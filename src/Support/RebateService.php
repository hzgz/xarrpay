<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;
use PDOException;

final class RebateService
{
    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    public function settleOrderFee(PDO $db, array $order, int $amount, int $now): ?array
    {
        $uid = (int) ($order['uid'] ?? 0);
        $referrerUid = $this->referrerUid($db, $uid);
        if ($referrerUid <= 0 || $referrerUid === $uid) {
            return null;
        }
        if (!OptionStore::bool($db, 'recommend_enable') || !OptionStore::bool($db, 'recommend_fee_enable')) {
            return null;
        }

        $minimum = max(0, (int) OptionStore::raw($db, 'recommend_fee_min_amount', '0'));
        if ($amount < $minimum) {
            return null;
        }

        $reward = $this->calculate(
            $amount,
            OptionStore::raw($db, 'recommend_fee_type', '1'),
            OptionStore::raw($db, 'recommend_fee_amount', OptionStore::raw($db, 'recommend_fee_value', '0')),
        );
        if ($reward <= 0) {
            return null;
        }

        return $this->credit(
            $db,
            $referrerUid,
            $uid,
            $reward,
            'order_fee',
            (string) ($order['order_id'] ?? ''),
            '订单返佣 ' . (string) ($order['order_id'] ?? ''),
            $now,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function settleTopUp(PDO $db, int $uid, int $amount, string $originId, int $now): ?array
    {
        $referrerUid = $this->referrerUid($db, $uid);
        if ($referrerUid <= 0 || $referrerUid === $uid) {
            return null;
        }
        if (!OptionStore::bool($db, 'recommend_enable') || !OptionStore::bool($db, 'recommend_top_up_enable')) {
            return null;
        }

        $minimum = max(0, (int) OptionStore::raw($db, 'recommend_top_up_min_spend', '0'));
        if ($amount < $minimum) {
            return null;
        }

        $reward = $this->calculate(
            $amount,
            OptionStore::raw($db, 'recommend_top_up_type', '1'),
            OptionStore::raw($db, 'recommend_top_up_value', '0'),
        );
        if ($reward <= 0) {
            return null;
        }

        return $this->credit(
            $db,
            $referrerUid,
            $uid,
            $reward,
            'top_up',
            $originId,
            '充值返佣 ' . $originId,
            $now,
        );
    }

    /** @return array<string, mixed> */
    public function transfer(PDO $db, int $uid, int $amount, int $now): array
    {
        if ($uid <= 0 || $amount <= 0) {
            throw new \InvalidArgumentException('划转金额必须大于 0');
        }

        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $lock = Database::driver() === 'mysql' ? ' FOR UPDATE' : '';
            $query = $db->prepare('SELECT balance, rebate_balance FROM `user` WHERE id = :id LIMIT 1' . $lock);
            $query->execute([':id' => $uid]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new \RuntimeException('商户不存在');
            }
            $rebateBalance = (int) ($row['rebate_balance'] ?? 0);
            $balance = (int) ($row['balance'] ?? 0);
            if ($rebateBalance < $amount) {
                throw new \RuntimeException('佣金余额不足');
            }

            $afterRebate = $rebateBalance - $amount;
            $afterBalance = $balance + $amount;
            $update = $db->prepare(
                'UPDATE `user` SET rebate_balance = :rebate_balance, balance = :balance '
                . 'WHERE id = :id AND rebate_balance >= :amount'
            );
            $update->execute([
                ':rebate_balance' => $afterRebate,
                ':balance' => $afterBalance,
                ':amount' => $amount,
                ':id' => $uid,
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('佣金划转失败，请重试');
            }

            $originId = 'transfer-' . $uid . '-' . $now . '-' . bin2hex(random_bytes(6));
            $this->insertLedger(
                $db,
                $uid,
                -$amount,
                $now,
                'transfer',
                $originId,
                $uid,
                '佣金划转到余额',
            );

            $log = $db->prepare(
                'INSERT INTO balance_log (uid, type, amount, before_balance, after_balance, remark, created_at) '
                . 'VALUES (:uid, :type, :amount, :before_balance, :after_balance, :remark, :created_at)'
            );
            $log->execute([
                ':uid' => $uid,
                ':type' => 'rebate',
                ':amount' => $amount,
                ':before_balance' => $balance,
                ':after_balance' => $afterBalance,
                ':remark' => '佣金划转到余额',
                ':created_at' => $now,
            ]);

            if ($ownsTransaction) {
                $db->commit();
            }
            return [
                'amount' => $amount,
                'rebate_balance' => $afterRebate,
                'balance' => $afterBalance,
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function credit(
        PDO $db,
        int $uid,
        int $fromUid,
        int $amount,
        string $originType,
        string $originId,
        string $remark,
        int $now,
    ): array {
        if ($uid <= 0 || $fromUid <= 0 || $amount <= 0 || $originId === '') {
            throw new \InvalidArgumentException('返佣记录参数无效');
        }

        $lock = Database::driver() === 'mysql' ? ' FOR UPDATE' : '';
        $merchant = $db->prepare('SELECT rebate_balance FROM `user` WHERE id = :id LIMIT 1' . $lock);
        $merchant->execute([':id' => $uid]);
        if ($merchant->fetchColumn() === false) {
            throw new \RuntimeException('返佣商户不存在');
        }

        $sourceKey = $originType . ':' . $uid . ':' . $fromUid . ':' . $originId;
        try {
            $this->insertLedger($db, $uid, $amount, $now, $originType, $originId, $fromUid, $remark, $sourceKey);
        } catch (PDOException $exception) {
            $check = $db->prepare('SELECT id, balance FROM user_rebate_log WHERE source_key = :source_key LIMIT 1');
            $check->execute([':source_key' => $sourceKey]);
            $existing = $check->fetch();
            if (is_array($existing)) {
                return [
                    'id' => (int) $existing['id'],
                    'balance' => (int) $existing['balance'],
                    'duplicate' => true,
                ];
            }
            throw $exception;
        }

        $update = $db->prepare('UPDATE `user` SET rebate_balance = rebate_balance + :amount WHERE id = :id');
        $update->execute([':amount' => $amount, ':id' => $uid]);
        if ($update->rowCount() !== 1) {
            throw new \RuntimeException('返佣余额更新失败');
        }

        $id = (int) $db->lastInsertId();
        return ['id' => $id, 'balance' => $amount, 'duplicate' => false];
    }

    private function referrerUid(PDO $db, int $uid): int
    {
        $query = $db->prepare('SELECT recommend_uid FROM `user` WHERE id = :id AND status = 1 LIMIT 1');
        $query->execute([':id' => $uid]);
        return (int) ($query->fetchColumn() ?: 0);
    }

    private function calculate(int $amount, string $type, string $value): int
    {
        $number = (float) $value;
        if ($number <= 0) {
            return 0;
        }
        $reward = ((string) $type === '1')
            ? (int) floor($amount * $number / 100)
            : (int) round($number);
        return min(max($reward, 0), $amount);
    }

    private function insertLedger(
        PDO $db,
        int $uid,
        int $balance,
        int $now,
        string $originType,
        string $originId,
        int $fromUid,
        string $remark,
        ?string $sourceKey = null,
    ): void {
        $insert = $db->prepare(
            'INSERT INTO user_rebate_log '
            . '(uid, balance, arrival_time, status, origin_type, origin_id, from_uid, remark, source_key, created_at, updated_at) '
            . 'VALUES (:uid, :balance, :arrival_time, 1, :origin_type, :origin_id, :from_uid, :remark, :source_key, :created_at, :updated_at)'
        );
        $insert->execute([
            ':uid' => $uid,
            ':balance' => $balance,
            ':arrival_time' => $now,
            ':origin_type' => $originType,
            ':origin_id' => $originId,
            ':from_uid' => $fromUid,
            ':remark' => $remark,
            ':source_key' => $sourceKey ?? ($originType . ':' . $uid . ':' . $originId),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}
