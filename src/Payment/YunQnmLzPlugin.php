<?php

declare(strict_types=1);

namespace XArrPay\Payment;

use PDO;
use XArrPay\Support\PluginAccountService;

final class YunQnmLzPlugin implements PaymentPlugin, RuntimePlugin
{
    public function name(): string
    {
        return 'yun_qnm_lz';
    }

    public function capabilities(): array
    {
        return ['create', 'qrcode', 'login_qrcode', 'login_qrcode_check', 'cron', 'heartbeat'];
    }

    public function create(array $order, array $channel, array $account): array
    {
        $options = PluginOptions::fromAccount($account);
        $type = (string) ($options['type'] ?? 'qrcode');
        if ($type === 'image') {
            $file = PluginOptions::requireString($options, 'qrcode_file', '请上传收款码图片');
            return $this->result($channel, '', PluginOptions::imageData($file));
        }

        $qrcode = PluginOptions::requireString($options, 'qrcode', '请输入收款码地址');
        return $this->result($channel, $qrcode, '');
    }

    public function query(array $order, array $channel, array $account): array
    {
        throw new \RuntimeException('yun_qnm_lz 通过定时流水上报结算，不提供主动订单查询');
    }

    public function heartbeat(PDO $db, array $account, int $now): array
    {
        $options = PluginOptions::fromAccount($account);
        $session = trim((string) ($options['custom_session_key'] ?? ''));
        $openid = trim((string) ($options['tally_openid'] ?? ''));
        if ($session === '' || $openid === '') {
            return [
                'online' => false,
                'message' => '全能码账号尚未完成登录',
                'last_seen_at' => 0,
            ];
        }
        $query = $db->prepare('SELECT last_seen_at,last_error FROM pay_account_ext WHERE account_id = :account_id LIMIT 1');
        $query->execute([':account_id' => (int) ($account['id'] ?? 0)]);
        $state = $query->fetch();
        $lastSeen = is_array($state) ? (int) ($state['last_seen_at'] ?? 0) : 0;
        $online = $lastSeen > 0 && $lastSeen >= $now - 900;
        return [
            'online' => $online,
            'message' => $online ? '全能码流水任务正常' : '全能码尚未完成最近一次流水同步',
            'last_seen_at' => $lastSeen,
            'last_error' => is_array($state) ? (string) ($state['last_error'] ?? '') : '',
        ];
    }

    public function cron(PDO $db, array $account, int $now): array
    {
        $options = PluginOptions::fromAccount($account);
        $session = PluginOptions::requireString($options, 'custom_session_key', '请先完成全能码登录');
        $openid = PluginOptions::requireString($options, 'tally_openid', '全能码账号缺少账本用户标识');
        $response = PluginAccountService::qnmBillAdapter($options, $session, $openid, $now);
        return [
            'flows' => $this->normalizeFlows($response, $now, $options),
            'message' => '全能码流水同步完成',
        ];
    }

    public function parseMessage(array $message, array $account): array
    {
        throw new \RuntimeException('全能码插件不接受客户端消息上报');
    }

    /** @return list<array<string,mixed>> */
    private function normalizeFlows(array $response, int $now, array $options): array
    {
        $items = [];
        $this->collectFlowItems($response, $items);
        $result = [];
        foreach ($items as $item) {
            if (isset($item['bill_type']) && (int) $item['bill_type'] !== 2) {
                continue;
            }
            if (isset($item['trans_source']) && (int) $item['trans_source'] !== 1) {
                continue;
            }
            $remark = $this->first($item, ['remark', 'memo', 'body', 'description']);
            if (preg_match('/退款|红包|转账|消费|充值/u', $remark) === 1) {
                continue;
            }
            $flow = $this->flow($item, $now);
            if ($flow !== null) {
                if ($flow['trans_time'] < $now - max((int) ($options['poll_window_seconds'] ?? 600), 60)) {
                    continue;
                }
                $result[] = $flow;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $value @param list<array<string,mixed>> $items */
    private function collectFlowItems(array $value, array &$items): void
    {
        foreach ($value as $item) {
            if (is_array($item) && $this->looksLikeFlow($item)) {
                $items[] = $item;
                continue;
            }
            if (is_array($item)) {
                $this->collectFlowItems($item, $items);
            }
        }
    }

    /** @param array<string,mixed> $item */
    private function looksLikeFlow(array $item): bool
    {
        return $this->first($item, ['third_order_id', 'transaction_id', 'bill_id', 'id']) !== ''
            && $this->first($item, ['amount', 'receipt_amount', 'fee', 'money']) !== '';
    }

    /** @param array<string,mixed> $item @return array<string,mixed>|null */
    private function flow(array $item, int $now): ?array
    {
        $id = $this->first($item, ['third_order_id', 'transaction_id', 'bill_id', 'id']);
        $amount = PluginAccountService::amountInCents($this->first($item, ['amount', 'receipt_amount', 'fee', 'money']));
        $time = PluginAccountService::timestamp($this->first($item, ['trans_time', 'transaction_time', 'paid_at', 'time', 'create_time']), $now);
        $remark = $this->first($item, ['remark', 'memo', 'body', 'description']);
        if ($id === '' || $amount <= 0 || $time <= 0) {
            return null;
        }
        return [
            'third_order_id' => $id,
            'amount' => $amount,
            'trans_time' => $time,
            'remark' => $remark,
            'buyer_name' => $this->first($item, ['buyer_name', 'payer', 'nickname', 'name']),
            'raw' => $item,
        ];
    }

    /** @param array<string,mixed> $item @param list<string> $keys */
    private function first(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && trim((string) $item[$key]) !== '') {
                return trim((string) $item[$key]);
            }
        }
        return '';
    }

    /** @return array<string, mixed> */
    private function result(array $channel, string $qrcode, string $qrcodeData): array
    {
        return [
            'status' => 'pending',
            'type' => 'qrcode',
            'qrcode_data' => $qrcodeData,
            'qrcode' => $qrcode,
            'content' => '',
            'content_copy' => false,
            'actual_account' => '',
            'actual_account_type' => '',
            'channel_code' => (string) ($channel['code'] ?? 'wxpay'),
            'plugin_name' => $this->name(),
        ];
    }
}
