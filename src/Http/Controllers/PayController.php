<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use XArrPay\Http\Request;
use XArrPay\Support\Database;
use XArrPay\Support\PaymentAccountAllocator;
use XArrPay\Support\PaymentPluginCatalog;
use XArrPay\Support\PaymentPluginRegistry;
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

        $db = Database::connection();
        try {
            $db->beginTransaction();
            $allocation = (new PaymentAccountAllocator())->allocate($db, $order, $payType);
            $update = $db->prepare('UPDATE `order` SET pay_type = :pay_type, channel_code = :channel_code, account_id = :account_id, updated_at = :updated_at WHERE order_id = :order_id AND status = 1');
            $update->execute([
                ':pay_type' => $payType,
                ':channel_code' => (string) ($allocation['channel']['code'] ?? ''),
                ':account_id' => (int) ($allocation['account']['id'] ?? 0),
                ':updated_at' => time(),
                ':order_id' => (string) $order['order_id'],
            ]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error($exception->getMessage(), 409, 409);
        }
        $order['pay_type'] = $payType;
        $order['channel_code'] = (string) ($allocation['channel']['code'] ?? '');
        $order['account_id'] = (int) ($allocation['account']['id'] ?? 0);
        Response::success($this->orderData($order), '支付方式已选择');
    }

    public function audio(Request $request): never
    {
        $this->order($request->input('order_id'));
        Response::success(['audio_enable' => 0, 'audio_url' => '']);
    }

    public function config(): never
    {
        $db = Database::connection();
        PaymentPluginCatalog::syncAllChannels($db);
        $rows = $db->query('SELECT `key`, value FROM `options` ORDER BY `key`')->fetchAll();
        $config = [];
        foreach ($rows as $row) {
            $value = $row['value'] ?? '';
            $decoded = is_string($value) ? json_decode($value, true) : null;
            $config[(string) $row['key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }
        $config['web_title'] ??= '';
        $config['web_index_title'] ??= '';
        $config['web_logo'] ??= '';
        $config['web_service_qq'] ??= '';
        $config['pay_type'] = [];
        foreach ($db->query('SELECT * FROM pay_type WHERE status = 1 ORDER BY id')->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = trim((string) ($row['value'] ?? $row['code'] ?? ''));
            if ($value === '') {
                continue;
            }
            $config['pay_type'][] = [
                'value' => $value,
                'label' => (string) ($row['label'] ?? $row['name'] ?? $value),
                'name' => (string) ($row['name'] ?? $value),
                'logo' => (string) ($row['logo'] ?? ''),
            ];
        }
        $config['channels'] = [];
        $channels = $db->query(
            'SELECT c.* FROM pay_channel c '
            . 'INNER JOIN pay_type t ON t.value = c.type AND t.status = 1 '
            . 'WHERE c.status = 1 ORDER BY c.id'
        )->fetchAll();
        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $pluginName = trim((string) ($channel['plugin_name'] ?? ''));
            $payType = trim((string) ($channel['type'] ?? ''));
            $code = trim((string) ($channel['code'] ?? ''));
            if ($pluginName === '' || $payType === '' || $code === ''
                || !PaymentPluginRegistry::usableForPayType($pluginName, $payType)) {
                continue;
            }
            $config['channels'][] = [
                'code' => $code,
                'name' => (string) ($channel['name'] ?? $code),
                'type' => $payType,
                'plugin_name' => $pluginName,
            ] + PaymentPluginCatalog::channelMeta($pluginName, $code, $payType);
        }
        Response::success($config);
    }

    public function types(Request $request): never
    {
        $orderId = trim((string) $request->input('order_id', ''));
        $uid = 0;
        if ($orderId !== '') {
            $order = $this->order($orderId);
            $uid = (int) ($order['uid'] ?? 0);
        } else {
            $uid = $this->merchantUid($request);
        }
        $query = Database::connection()->prepare('SELECT DISTINCT t.* FROM `pay_type` t INNER JOIN `pay_account` a ON a.pay_type = t.value AND a.status = 1 INNER JOIN `pay_channel` c ON c.code = a.channel_code AND c.status = 1 WHERE t.status = 1 AND (a.uid = :uid OR a.uid = 0) ORDER BY t.id');
        $query->execute([':uid' => $uid]);
        $rows = $query->fetchAll();
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
        Response::success($items);
    }

    public function qrcode(Request $request): never
    {
        $order = $this->order($request->input('order_id'));
        if ((int) $order['status'] !== 1) {
            Response::error('订单已不可支付');
        }

        $db = Database::connection();
        try {
            $db->beginTransaction();
            $allocation = (new PaymentAccountAllocator())->allocate($db, $order, (string) ($order['pay_type'] ?? ''));
            $update = $db->prepare('UPDATE `order` SET pay_type = :pay_type, channel_code = :channel_code, account_id = :account_id, updated_at = :updated_at WHERE id = :id AND status = 1');
            $update->execute([
                ':pay_type' => (string) ($order['pay_type'] ?? $allocation['account']['pay_type'] ?? ''),
                ':channel_code' => (string) ($allocation['channel']['code'] ?? ''),
                ':account_id' => (int) ($allocation['account']['id'] ?? 0),
                ':updated_at' => time(),
                ':id' => (int) $order['id'],
            ]);
            $order['pay_type'] = trim((string) ($order['pay_type'] ?? '')) !== ''
                ? (string) $order['pay_type']
                : (string) ($allocation['account']['pay_type'] ?? '');
            $order['channel_code'] = (string) ($allocation['channel']['code'] ?? '');
            $order['account_id'] = (int) ($allocation['account']['id'] ?? 0);
            $payment = $allocation['plugin']->create($order, $allocation['channel'], $allocation['account']);
            $persist = $db->prepare('UPDATE `order` SET out_pay_order_id = :out_pay_order_id, actual_account = :actual_account, updated_at = :updated_at WHERE id = :id AND status = 1');
            $persist->execute([
                ':out_pay_order_id' => (string) ($payment['out_pay_order_id'] ?? ''),
                ':actual_account' => (string) ($payment['actual_account'] ?? ''),
                ':updated_at' => time(),
                ':id' => (int) $order['id'],
            ]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error($exception->getMessage(), 409, 409);
        }
        Response::success([
            'type' => (string) ($payment['type'] ?? 'qrcode'),
            'qrcode_data' => (string) ($payment['qrcode_data'] ?? ''),
            'qrcode' => (string) ($payment['qrcode'] ?? ''),
            'uri' => (string) ($allocation['account']['uri'] ?? ''),
            'scheme' => (string) ($allocation['account']['scheme'] ?? ''),
            'content' => (string) ($payment['content'] ?? ''),
            'content_copy' => (bool) ($payment['content_copy'] ?? false),
            'actual_amount' => number_format(((int) $order['trade_amount']) / 100, 2, '.', ''),
            'actual_account' => (string) ($payment['actual_account'] ?? ''),
            'actual_account_type' => (string) ($payment['actual_account_type'] ?? ''),
        ]);
    }

    public function cashierParse(Request $request): never
    {
        $merchant = $this->merchantByKey($request->input('key'));
        $merchantName = trim((string) ($merchant['merchant_name'] ?? $merchant['name'] ?? ''));
        if ($merchantName === '') {
            Response::error('收银台商户名称未配置', 422, 422);
        }
        Response::success(['merchant_name' => $merchantName]);
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

    private function merchantUid(Request $request): int
    {
        $token = trim((string) $request->header('Authorization'));
        if ($token === '') {
            Response::error('未登录', 401, 401);
        }
        $query = Database::connection()->prepare('SELECT id FROM `user` WHERE token=:token AND status=1 LIMIT 1');
        $query->execute([':token' => $token]);
        $uid = $query->fetchColumn();
        if ($uid === false) {
            Response::error('登录已失效', 401, 401);
        }
        return (int) $uid;
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
