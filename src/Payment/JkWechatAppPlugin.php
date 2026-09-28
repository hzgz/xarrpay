<?php

declare(strict_types=1);

namespace XArrPay\Payment;

use PDO;

final class JkWechatAppPlugin implements PaymentPlugin, RuntimePlugin
{
    public function name(): string
    {
        return 'jk_wechat_app';
    }

    public function capabilities(): array
    {
        return ['create', 'qrcode', 'heartbeat', 'message_parse'];
    }

    public function create(array $order, array $channel, array $account): array
    {
        $options = PluginOptions::fromAccount($account);
        $imageMode = (string) ($options['type'] ?? '') === 'image'
            || (string) ($options['qrcode_type'] ?? '') === 'zanshang'
            || trim((string) ($options['qrcode_file'] ?? '')) !== '';

        if ($imageMode) {
            $file = PluginOptions::requireString($options, 'qrcode_file', '收款码图片不能为空');
            return [
                'status' => 'pending',
                'type' => 'qrcode',
                'qrcode_data' => PluginOptions::imageData($file),
                'qrcode' => '',
                'content' => '',
                'content_copy' => false,
                'actual_account' => '',
                'actual_account_type' => '',
                'channel_code' => (string) ($channel['code'] ?? 'wxpay_app_monitor'),
                'plugin_name' => $this->name(),
            ];
        }

        $qrcode = PluginOptions::requireString($options, 'qrcode', '收款码地址不能为空');
        return [
            'status' => 'pending',
            'type' => 'qrcode',
            'qrcode_data' => '',
            'qrcode' => $qrcode,
            'content' => '',
            'content_copy' => false,
            'actual_account' => '',
            'actual_account_type' => '',
            'channel_code' => (string) ($channel['code'] ?? 'wxpay_app_monitor'),
            'plugin_name' => $this->name(),
        ];
    }

    public function query(array $order, array $channel, array $account): array
    {
        throw new \RuntimeException('jk_wechat_app 不提供主动订单查询，等待客户端流水上报');
    }

    public function heartbeat(PDO $db, array $account, int $now): array
    {
        $options = PluginOptions::fromAccount($account);
        if ((string) ($options['heartbeat_enable'] ?? '0') !== '1') {
            return [
                'online' => false,
                'message' => '插件未开启客户端心跳',
                'last_seen_at' => 0,
            ];
        }

        $query = $db->prepare('SELECT last_seen_at,last_error FROM pay_account_ext WHERE account_id = :account_id LIMIT 1');
        $query->execute([':account_id' => (int) ($account['id'] ?? 0)]);
        $state = $query->fetch();
        if (!is_array($state)) {
            return [
                'online' => false,
                'message' => '客户端尚未上报心跳',
                'last_seen_at' => 0,
            ];
        }

        $lastSeen = (int) ($state['last_seen_at'] ?? 0);
        $timeout = max((int) ($options['heartbeat_timeout'] ?? 90), 15);
        $online = $lastSeen > 0 && $lastSeen >= $now - $timeout;
        return [
            'online' => $online,
            'message' => $online ? '客户端心跳正常' : '客户端心跳已超时',
            'last_seen_at' => $lastSeen,
            'last_error' => (string) ($state['last_error'] ?? ''),
        ];
    }

    public function cron(PDO $db, array $account, int $now): array
    {
        throw new \RuntimeException('微信 APP 插件等待客户端消息上报，不执行服务端流水定时任务');
    }

    public function parseMessage(array $message, array $account): array
    {
        $text = trim((string) ($message['title'] ?? '') . ' ' . (string) ($message['content'] ?? ''));
        $amount = $this->messageAmount($message, $text);
        if ($amount <= 0) {
            throw new \RuntimeException('微信客户端消息未解析出有效收款金额');
        }
        if (!preg_match('/微信|收款|到账|支付/u', $text)) {
            throw new \RuntimeException('微信客户端消息不是收款通知');
        }

        $thirdOrderId = trim((string) (
            $message['third_order_id']
            ?? $message['transaction_id']
            ?? $message['message_id']
            ?? $message['id']
            ?? ''
        ));
        if ($thirdOrderId === '') {
            throw new \RuntimeException('微信客户端消息缺少第三方流水号');
        }

        $transTime = (int) ($message['trans_time'] ?? $message['timestamp'] ?? time());
        if ($transTime <= 0) {
            throw new \RuntimeException('微信客户端消息时间无效');
        }
        return [
            'third_order_id' => $thirdOrderId,
            'amount' => $amount,
            'trans_time' => $transTime,
            'remark' => trim((string) ($message['remark'] ?? $text)),
            'buyer_name' => trim((string) ($message['buyer_name'] ?? $message['payer'] ?? '')),
            'raw' => $message,
        ];
    }

    private function messageAmount(array $message, string $text): int
    {
        $value = $message['amount'] ?? null;
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            return (int) $value;
        }
        if (is_float($value) || is_string($value)) {
            $value = trim((string) $value);
            if (preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1) {
                return (int) round((float) $value * 100);
            }
        }
        if (preg_match('/(?:收款|到账|支付|金额)[^\d]{0,12}(\d+(?:\.\d{1,2})?)\s*元/u', $text, $matches) !== 1) {
            return 0;
        }
        return (int) round((float) $matches[1] * 100);
    }
}
