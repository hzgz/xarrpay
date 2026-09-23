<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use XArrPay\Http\Request;
use XArrPay\Support\Database;
use XArrPay\Support\Response;

final class PayController
{
    public function orderInfo(Request $request): never
    {
        $order = $this->order($request->input('order_id'));
        Response::success($this->orderData($order));
    }

    public function orderStatus(Request $request): never
    {
        $order = $this->order($request->input('order_id'));
        Response::success($this->orderData($order));
    }

    public function chooseType(Request $request): never
    {
        $order = $this->order($request->input('order_id'));
        $payType = trim((string) $request->input('pay_type', ''));
        if ($payType === '') {
            Response::error('请选择支付方式');
        }

        $type = $this->payType($payType);
        if ($type === null) {
            Response::error('支付方式不可用');
        }

        $update = Database::connection()->prepare('UPDATE `order` SET pay_type = :pay_type, updated_at = :updated_at WHERE order_id = :order_id AND status = 1');
        $update->execute([':pay_type' => $payType, ':updated_at' => time(), ':order_id' => (string) $order['order_id']]);
        $order['pay_type'] = $payType;
        Response::success($this->orderData($order), '支付方式已选择');
    }

    public function audio(Request $request): never
    {
        $this->order($request->input('order_id'));
        Response::success(['audio_enable' => 0, 'audio_url' => '']);
    }

    public function config(): never
    {
        $rows = Database::connection()->query('SELECT `key`, value FROM `options` ORDER BY `key`')->fetchAll();
        $config = [];
        foreach ($rows as $row) {
            $value = $row['value'] ?? '';
            $decoded = is_string($value) ? json_decode($value, true) : null;
            $config[(string) $row['key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }
        $config['web_title'] ??= 'XArrPay 支付系统';
        $config['web_index_title'] ??= $config['web_title'];
        $config['web_logo'] ??= '/admin/static/images/logo.png';
        $config['web_service_qq'] ??= '';
        Response::success($config);
    }

    public function types(Request $request): never
    {
        $order = $this->order($request->input('order_id'));
        $rows = Database::connection()->query('SELECT * FROM `pay_type` WHERE status = 1 ORDER BY id')->fetchAll();
        $items = [];
        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? $row['code'] ?? $row['name'] ?? '');
            if ($value === '') {
                continue;
            }
            $items[] = [
                'value' => $value,
                'label' => (string) ($row['label'] ?? $row['title'] ?? $row['name'] ?? $value),
                'logo' => (string) ($row['logo'] ?? $row['icon'] ?? '/admin/static/images/pay/' . $value . '.png'),
            ];
        }
        if ($items === []) {
            $items[] = ['value' => (string) ($order['pay_type'] ?: 'alipay'), 'label' => '支付宝', 'logo' => '/admin/static/images/pay/alipay.png'];
        }
        Response::success($items);
    }

    public function qrcode(Request $request): never
    {
        $order = $this->order($request->input('order_id'));
        if ((int) $order['status'] !== 1) {
            Response::error('订单已不可支付');
        }

        $account = $this->account((string) $order['pay_type']);
        if ($account === null) {
            Response::error('当前支付方式暂无收款账号');
        }

        $value = (string) ($account['account'] ?? $account['value'] ?? $account['url'] ?? '');
        $type = (string) ($account['account_type'] ?? $account['type'] ?? '');
        $isText = $value !== '' && preg_match('#^https?://#i', $value) !== 1 && str_starts_with($value, 'T');
        $qrcodeData = (string) ($account['qrcode_data'] ?? '');
        if ($qrcodeData === '' && $value !== '') {
            $qrcodeData = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><rect width="300" height="300" fill="#f5f7fa"/><text x="150" y="140" text-anchor="middle" fill="#606266" font-size="22">本地演示二维码</text><text x="150" y="175" text-anchor="middle" fill="#909399" font-size="14">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</text></svg>');
        }
        Response::success([
            'type' => $isText ? 'text' : 'qrcode',
            'qrcode_data' => $qrcodeData,
            'qrcode' => $value,
            'uri' => (string) ($account['uri'] ?? ''),
            'scheme' => (string) ($account['scheme'] ?? ''),
            'content' => $value,
            'content_copy' => $isText,
            'actual_amount' => number_format(((int) $order['trade_amount']) / 100, 2, '.', ''),
            'actual_account' => $value,
            'actual_account_type' => $type,
        ]);
    }

    public function cashierParse(Request $request): never
    {
        $merchant = $this->merchantByKey($request->input('key'));
        Response::success(['merchant_name' => (string) ($merchant['merchant_name'] ?? $merchant['name'] ?? 'XArrPay 商户')]);
    }

    public function createCashier(Request $request): never
    {
        $merchant = $this->merchantByKey($request->input('key'));
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0 || $amount > 99999999900) {
            Response::error('付款金额错误');
        }

        $orderId = date('YmdHis') . random_int(100000, 999999);
        $now = time();
        $payType = '';
        $insert = Database::connection()->prepare('INSERT INTO `order` (order_id, out_order_id, uid, price, amount, trade_amount, rate_amount, status, subject, expire_time, pay_type, notify_uri, redirect_uri, param, ip, device, created_at, updated_at) VALUES (:order_id, :out_order_id, :uid, :price, :amount, :trade_amount, 0, 1, :subject, :expire_time, :pay_type, :notify_uri, :redirect_uri, :param, :ip, :device, :created_at, :updated_at)');
        $insert->execute([
            ':order_id' => $orderId,
            ':out_order_id' => 'cashier_' . $orderId,
            ':uid' => (int) $merchant['id'],
            ':price' => $amount,
            ':amount' => $amount,
            ':trade_amount' => $amount,
            ':subject' => '收银台订单',
            ':expire_time' => $now + 900,
            ':pay_type' => $payType,
            ':notify_uri' => '',
            ':redirect_uri' => '',
            ':param' => '',
            ':ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ':device' => 'cashier',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        Response::success(['trade_no' => $orderId, 'pay_type' => $payType], '订单创建成功');
    }

    /** @return array<string, mixed> */
    private function order(mixed $id): array
    {
        $orderId = trim((string) $id);
        if ($orderId === '') {
            Response::error('订单号不能为空');
        }
        $query = Database::connection()->prepare('SELECT * FROM `order` WHERE order_id = :order_id LIMIT 1');
        $query->execute([':order_id' => $orderId]);
        $order = $query->fetch();
        if (!is_array($order)) {
            Response::error('未能找到订单信息或失效', 404, 404);
        }
        if ((int) ($order['status'] ?? 0) === 1 && (int) ($order['expire_time'] ?? 0) < time()) {
            $update = Database::connection()->prepare('UPDATE `order` SET status = 4, updated_at = :updated_at WHERE order_id = :order_id AND status = 1');
            $update->execute([':updated_at' => time(), ':order_id' => $orderId]);
            $order['status'] = 4;
        }
        return $order;
    }

    /** @return array<string, mixed>|null */
    private function payType(string $value): ?array
    {
        $rows = Database::connection()->query('SELECT * FROM `pay_type` WHERE status = 1 ORDER BY id')->fetchAll();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = (string) ($row['value'] ?? $row['code'] ?? $row['name'] ?? '');
            if ($candidate === $value) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function account(string $payType): ?array
    {
        $query = Database::connection()->prepare('SELECT * FROM `pay_account` WHERE status = 1 AND pay_type = :pay_type ORDER BY id LIMIT 1');
        $query->execute([':pay_type' => $payType]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function merchantByKey(mixed $key): array
    {
        $value = trim((string) $key);
        if ($value === '') {
            Response::error('收银台密钥不能为空');
        }
        $query = Database::connection()->prepare('SELECT * FROM `user` WHERE app_secret = :app_secret AND status = 1 LIMIT 1');
        $query->execute([':app_secret' => $value]);
        $merchant = $query->fetch();
        if (!is_array($merchant)) {
            Response::error('收银台不存在或已停用', 404, 404);
        }
        return $merchant;
    }

    /** @return array<string, mixed> */
    private function orderData(array $order): array
    {
        $payType = (string) ($order['pay_type'] ?? '');
        $type = $payType !== '' ? $this->payType($payType) : null;
        return [
            'order_id' => (string) ($order['order_id'] ?? ''),
            'out_order_id' => (string) ($order['out_order_id'] ?? ''),
            'amount' => (int) ($order['amount'] ?? 0),
            'trade_amount' => (int) ($order['trade_amount'] ?? $order['amount'] ?? 0),
            'subject' => (string) ($order['subject'] ?? ''),
            'status' => (int) ($order['status'] ?? 0),
            'expire_time' => (int) ($order['expire_time'] ?? 0),
            'pay_type' => $payType,
            'pay_type_logo' => (string) ($type['logo'] ?? '/admin/static/images/pay/' . ($payType ?: 'alipay') . '.png'),
            'pay_type_text' => (string) ($type['label'] ?? $type['name'] ?? $payType),
            'return_uri' => (string) ($order['redirect_uri'] ?? $order['return_uri'] ?? ''),
            'pay_payed_wait_time' => 3,
            'pay_account_tip' => ['tip' => '', 'tip_cover' => 0],
            'pay_tip' => '',
            'service_qq' => '',
            'create_time' => date('Y-m-d H:i:s', (int) ($order['created_at'] ?? 0)),
        ];
    }
}
