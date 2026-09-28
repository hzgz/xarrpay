<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class PaymentAccountAllocator
{
    /**
     * Selects one enabled account and its channel, applying polling order and
     * amount limits before the order is persisted.
     *
     * @param array<string, mixed> $order
     * @return array{account: array<string, mixed>, channel: array<string, mixed>, plugin: \XArrPay\Payment\PaymentPlugin}
     */
    public function allocate(PDO $db, array $order, string $requestedPayType = ''): array
    {
        $uid = (int) ($order['uid'] ?? 0);
        $amount = (int) ($order['amount'] ?? $order['trade_amount'] ?? 0);
        $payType = trim($requestedPayType !== '' ? $requestedPayType : (string) ($order['pay_type'] ?? ''));
        $sql = 'SELECT a.*, c.code AS channel_code_value, c.name AS channel_name, c.type AS channel_type, '
            . 'c.plugin_name, c.remark AS channel_remark, c.options AS channel_options '
            . 'FROM pay_account a INNER JOIN pay_channel c ON c.code = a.channel_code '
            . 'WHERE a.status = 1 AND c.status = 1 AND (a.uid = :uid OR a.uid = 0)';
        $params = [':uid' => $uid];
        if ($payType !== '') {
            $sql .= ' AND a.pay_type = :pay_type';
            $params[':pay_type'] = $payType;
        }
        $sql .= ' ORDER BY a.sort ASC, a.id ASC';
        $query = $db->prepare($sql);
        $query->execute($params);
        $candidates = array_values(array_filter($query->fetchAll(), 'is_array'));
        if ($candidates === []) {
            throw new \RuntimeException($payType === '' ? '当前没有可用收款账号' : '当前支付方式没有可用收款账号');
        }

        $rule = $this->pollingRule($db, $uid, $payType, $candidates);
        if ($rule !== null) {
            $candidates = $this->applyPollingOrder($candidates, $rule['account_ids']);
        }

        $lastError = '当前没有满足金额限制的收款账号';
        foreach ($candidates as $candidate) {
            if (!$this->amountAllowed($db, $candidate, $amount)) {
                continue;
            }
            $code = $this->paymentCode($db, (int) ($candidate['id'] ?? 0), $amount);
            if ($code === null) {
                $lastError = '收款账号未配置匹配的收款码';
                continue;
            }
            $candidate['qrcode'] = (string) ($code['content'] ?? '');
            $candidate['qrcode_data'] = (string) ($code['qrcode_data'] ?? '');
            $candidate['code_type'] = (string) ($code['code_type'] ?? 'qrcode');
            $pluginName = trim((string) ($candidate['plugin_name'] ?? ''));
            try {
                $plugin = PaymentPluginRegistry::resolve($pluginName);
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
                continue;
            }
            return [
                'account' => $candidate,
                'channel' => [
                    'code' => (string) ($candidate['channel_code_value'] ?? $candidate['channel_code'] ?? ''),
                    'name' => (string) ($candidate['channel_name'] ?? ''),
                    'type' => (string) ($candidate['channel_type'] ?? ''),
                    'plugin_name' => $pluginName,
                    'remark' => (string) ($candidate['channel_remark'] ?? ''),
                    'options' => $candidate['channel_options'] ?? '{}',
                ],
                'plugin' => $plugin,
            ];
        }

        throw new \RuntimeException($lastError);
    }

    /** @return array<string, mixed>|null */
    private function paymentCode(PDO $db, int $accountId, int $amount): ?array
    {
        $query = $db->prepare('SELECT * FROM pay_codes WHERE account_id = :account_id AND status = 1 AND (amount = 0 OR amount = :amount) ORDER BY CASE WHEN amount = :exact_amount THEN 0 ELSE 1 END, sort ASC, id ASC LIMIT 1');
        $query->execute([
            ':account_id' => $accountId,
            ':amount' => $amount,
            ':exact_amount' => $amount,
        ]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param list<array<string, mixed>> $candidates */
    private function pollingRule(PDO $db, int $uid, string $payType, array $candidates): ?array
    {
        $channelCodes = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row['channel_code_value'] ?? $row['channel_code'] ?? ''),
            $candidates
        ))));
        if ($channelCodes === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($channelCodes), '?'));
        $sql = 'SELECT * FROM polling_rule WHERE status = 1 AND uid IN (?, 0) AND (pay_type = ? OR pay_type = \'\') '
            . 'AND (channel_code = \'\' OR channel_code IN (' . $placeholders . ')) '
            . 'ORDER BY CASE WHEN uid = ? THEN 0 ELSE 1 END, sort ASC, id ASC LIMIT 1';
        $query = $db->prepare($sql);
        $query->execute(array_merge([$uid, $payType], $channelCodes, [$uid]));
        $rule = $query->fetch();
        return is_array($rule) ? $rule : null;
    }

    /** @param list<array<string, mixed>> $candidates @param mixed $rawIds @return list<array<string, mixed>> */
    private function applyPollingOrder(array $candidates, mixed $rawIds): array
    {
        $ids = is_string($rawIds) ? json_decode($rawIds, true) : $rawIds;
        if (!is_array($ids) || $ids === []) {
            return $candidates;
        }
        $priority = [];
        foreach (array_values($ids) as $index => $id) {
            $accountId = is_array($id)
                ? (int) ($id['account_id'] ?? $id['id'] ?? 0)
                : (int) $id;
            if ($accountId > 0) {
                $priority[$accountId] = $index;
            }
        }
        usort($candidates, static function (array $left, array $right) use ($priority): int {
            $leftRank = $priority[(int) ($left['id'] ?? 0)] ?? PHP_INT_MAX;
            $rightRank = $priority[(int) ($right['id'] ?? 0)] ?? PHP_INT_MAX;
            return ($leftRank <=> $rightRank)
                ?: (((int) ($left['sort'] ?? 0) <=> (int) ($right['sort'] ?? 0))
                    ?: ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0)));
        });
        return $candidates;
    }

    /** @param array<string, mixed> $account */
    private function amountAllowed(PDO $db, array $account, int $amount): bool
    {
        $min = (int) ($account['min_amount'] ?? 0);
        $max = (int) ($account['max_amount'] ?? 0);
        if (($min > 0 && $amount < $min) || ($max > 0 && $amount > $max)) {
            return false;
        }
        $limit = (int) ($account['day_amount_limit'] ?? 0);
        if ($limit <= 0) {
            return true;
        }
        $start = strtotime('today');
        $query = $db->prepare('SELECT COALESCE(SUM(trade_amount), 0) FROM `order` WHERE account_id = :account_id AND status = 2 AND pay_time >= :start');
        $query->execute([':account_id' => (int) ($account['id'] ?? 0), ':start' => $start]);
        return ((int) $query->fetchColumn() + $amount) <= $limit;
    }
}
