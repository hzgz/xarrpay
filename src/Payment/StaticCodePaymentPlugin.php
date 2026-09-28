<?php

declare(strict_types=1);

namespace XArrPay\Payment;

final class StaticCodePaymentPlugin implements PaymentPlugin
{
    public function name(): string
    {
        return 'static_code';
    }

    public function capabilities(): array
    {
        return ['create', 'query', 'qrcode'];
    }

    public function create(array $order, array $channel, array $account): array
    {
        $content = trim((string) ($account['qrcode'] ?? ''));
        $qrcodeData = trim((string) ($account['qrcode_data'] ?? ''));
        if ($content === '' && $qrcodeData === '') {
            $content = trim((string) ($account['account'] ?? ''));
        }
        if ($content === '' && $qrcodeData === '') {
            throw new \RuntimeException('收款账号未配置收款码内容');
        }
        if ($qrcodeData === '' && $content !== '') {
            $safe = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
            $qrcodeData = 'data:image/svg+xml,' . rawurlencode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300">'
                . '<rect width="300" height="300" fill="#f5f7fa"/>'
                . '<text x="150" y="140" text-anchor="middle" fill="#606266" font-size="22">静态收款码</text>'
                . '<text x="150" y="175" text-anchor="middle" fill="#909399" font-size="14">' . $safe . '</text>'
                . '</svg>'
            );
        }

        $isText = $content !== ''
            && preg_match('#^https?://#i', $content) !== 1
            && str_starts_with($content, 'T');

        return [
            'status' => 'pending',
            'type' => $isText ? 'text' : 'qrcode',
            'qrcode' => $content,
            'qrcode_data' => $qrcodeData,
            'content' => $content,
            'content_copy' => $isText,
            'actual_account' => $content,
            'actual_account_type' => (string) ($account['account_type'] ?? ''),
            'channel_code' => (string) ($channel['code'] ?? ''),
            'plugin_name' => $this->name(),
        ];
    }

    public function query(array $order, array $channel, array $account): array
    {
        return [
            'status' => ((int) ($order['status'] ?? 0)) === 2 ? 'paid' : 'pending',
            'out_pay_order_id' => (string) ($order['out_pay_order_id'] ?? ''),
            'plugin_name' => $this->name(),
        ];
    }
}
