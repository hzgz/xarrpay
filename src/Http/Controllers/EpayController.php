<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use PDO;
use XArrPay\Http\Request;
use XArrPay\Support\Database;
use XArrPay\Support\EpaySigner;
use XArrPay\Support\OrderSettlementService;
use XArrPay\Support\Response;

final class EpayController
{
    public function unsupported(Request $request, string $protocol): never
    {
        Response::error('当前版本未配置该扩展支付协议: ' . $protocol, 501, 501);
    }

    public function submit(Request $request): never
    {
        $params = $request->all();
        $merchant = $this->merchant($params);
        $this->requireSignature($params, $merchant);

        $order = $this->createOrder($params, (int) $merchant['id']);
        $this->renderPayment($order, $params);
    }

    public function mapi(Request $request): never
    {
        $params = $request->all();
        $merchant = $this->merchant($params);
        $this->requireSignature($params, $merchant);

        $order = $this->createOrder($params, (int) $merchant['id']);
        Response::json([
            'code' => 1,
            'msg' => '提交成功',
            'trade_no' => $order['order_id'],
            'payurl' => $this->absolute('/pay/' . rawurlencode($order['order_id'])),
        ]);
    }

    public function notify(Request $request): never
    {
        $params = $request->all();
        $merchant = $this->merchant($params);
        if (!EpaySigner::valid($params, (string) $merchant['app_secret'])) {
            Response::json(['code' => 0, 'message' => '签名校验失败'], 400);
        }
        if ((string) ($params['pid'] ?? '') !== (string) $merchant['id']) {
            Response::json(['code' => 0, 'message' => '交易商户号异常'], 400);
        }
        if (!in_array((string) ($params['trade_status'] ?? ''), ['TRADE_SUCCESS', 'SUCCESS'], true)) {
            Response::json(['code' => 0, 'message' => '交易未完成'], 400);
        }

        $amount = (int) round(((float) ($params['money'] ?? 0)) * 100);
        $db = Database::connection();
        try {
            (new OrderSettlementService())->settle(
                $db,
                $merchant,
                (string) ($params['out_trade_no'] ?? ''),
                $amount,
                (string) ($params['money'] ?? ''),
                (string) ($params['trade_no'] ?? ''),
                (string) ($params['buyer'] ?? '')
            );
        } catch (\Throwable $exception) {
            Response::json(['code' => 0, 'message' => $exception->getMessage()], 400);
        }

        echo 'success';
        exit;
    }

    public function api(Request $request): never
    {
        $params = $request->all();
        $merchant = $this->merchant($params);
        $this->requireMerchantKey($params, $merchant);

        $action = (string) ($params['act'] ?? '');
        if ($action === 'query') {
            $this->merchantInfo($merchant);
        }
        if ($action === 'order') {
            $this->orderInfo($params, $merchant);
        }
        if ($action === 'orders') {
            $this->orders($params, $merchant);
        }

        Response::json(['code' => -1, 'msg' => '未知操作']);
    }

    /** @return array<string, mixed> */
    private function merchant(array $params): array
    {
        $id = (int) ($params['pid'] ?? 0);
        if ($id <= 0) {
            Response::json(['code' => 0, 'msg' => '商户ID不能为空']);
        }

        $query = Database::connection()->prepare('SELECT * FROM `user` WHERE id = :id LIMIT 1');
        $query->execute([':id' => $id]);
        $merchant = $query->fetch();
        if (!is_array($merchant) || (int) ($merchant['status'] ?? 0) !== 1) {
            Response::json(['code' => 0, 'msg' => '商户不存在或已停用']);
        }

        return $merchant;
    }

    private function requireSignature(array $params, array $merchant): void
    {
        if (!EpaySigner::valid($params, (string) $merchant['app_secret'])) {
            Response::json(['code' => 0, 'msg' => '签名校验失败']);
        }
    }

    private function requireMerchantKey(array $params, array $merchant): void
    {
        if (!hash_equals((string) $merchant['app_secret'], (string) ($params['key'] ?? ''))) {
            Response::json(['code' => 0, 'msg' => '商户密钥错误']);
        }
    }

    /** @return array<string, mixed> */
    private function createOrder(array $params, int $uid): array
    {
        $outOrderId = trim((string) ($params['out_trade_no'] ?? ''));
        $subject = mb_substr(trim((string) ($params['name'] ?? '')), 0, 127);
        $money = (float) ($params['money'] ?? 0);
        if ($outOrderId === '' || $subject === '' || $money <= 0) {
            Response::json(['code' => 0, 'msg' => '订单参数错误']);
        }

        $db = Database::connection();
        $existing = $db->prepare('SELECT * FROM `order` WHERE uid = :uid AND out_order_id = :out_order_id LIMIT 1');
        $existing->execute([':uid' => $uid, ':out_order_id' => $outOrderId]);
        $order = $existing->fetch();
        if (is_array($order)) {
            return $order;
        }

        $id = date('YmdHis') . random_int(100000, 999999);
        $now = time();
        $insert = $db->prepare('INSERT INTO `order` (order_id, out_order_id, uid, price, amount, trade_amount, rate_amount, status, subject, expire_time, pay_type, notify_uri, redirect_uri, param, ip, device, created_at, updated_at) VALUES (:order_id, :out_order_id, :uid, :price, :amount, :trade_amount, 0, 1, :subject, :expire_time, :pay_type, :notify_uri, :redirect_uri, :param, :ip, :device, :created_at, :updated_at)');
        $amount = (int) round($money * 100);
        $insert->execute([
            ':order_id' => $id,
            ':out_order_id' => $outOrderId,
            ':uid' => $uid,
            ':price' => $amount,
            ':amount' => $amount,
            ':trade_amount' => $amount,
            ':subject' => $subject,
            ':expire_time' => $now + 900,
            ':pay_type' => (string) ($params['type'] ?? 'alipay'),
            ':notify_uri' => (string) ($params['notify_url'] ?? ''),
            ':redirect_uri' => (string) ($params['return_url'] ?? ''),
            ':param' => (string) ($params['param'] ?? ''),
            ':ip' => (string) ($params['clientip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
            ':device' => (string) ($params['device'] ?? 'pc'),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return [
            'order_id' => $id,
            'out_order_id' => $outOrderId,
            'amount' => $amount,
        ];
    }

    private function renderPayment(array $order, array $params): never
    {
        $url = $this->absolute('/pay/' . rawurlencode((string) $order['order_id']));
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>正在跳转支付</title><p>正在跳转支付，请稍候...</p><script>location.replace(' . json_encode($url, JSON_UNESCAPED_SLASHES) . ')</script><noscript><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">继续支付</a></noscript>';
        exit;
    }

    private function merchantInfo(array $merchant): never
    {
        $db = Database::connection();
        $stats = $db->prepare('SELECT COUNT(*) FROM `order` WHERE uid = :uid');
        $stats->execute([':uid' => (int) $merchant['id']]);
        $orders = (int) $stats->fetchColumn();
        $todayStart = strtotime('today');
        $yesterdayStart = $todayStart - 86400;
        $todayQuery = $db->prepare('SELECT COUNT(*) FROM `order` WHERE uid = :uid AND created_at >= :today_start');
        $todayQuery->execute([':uid' => (int) $merchant['id'], ':today_start' => $todayStart]);
        $yesterdayQuery = $db->prepare('SELECT COUNT(*) FROM `order` WHERE uid = :uid AND created_at >= :yesterday_start AND created_at < :today_start');
        $yesterdayQuery->execute([':uid' => (int) $merchant['id'], ':yesterday_start' => $yesterdayStart, ':today_start' => $todayStart]);
        Response::json([
            'code' => 1,
            'pid' => (int) $merchant['id'],
            'key' => (string) $merchant['app_secret'],
            'active' => (int) $merchant['status'],
            'money' => number_format(((int) ($merchant['balance'] ?? 0)) / 100, 2, '.', ''),
            'type' => 1,
            'account' => '',
            'username' => (string) $merchant['merchant_name'],
            'orders' => $orders,
            'order_today' => (int) $todayQuery->fetchColumn(),
            'order_lastday' => (int) $yesterdayQuery->fetchColumn(),
        ]);
    }

    private function orderInfo(array $params, array $merchant): never
    {
        $where = isset($params['trade_no']) && $params['trade_no'] !== '' ? 'order_id = :order_id' : 'out_order_id = :out_order_id';
        $bind = isset($params['trade_no']) && $params['trade_no'] !== '' ? [':order_id' => (string) $params['trade_no']] : [':out_order_id' => (string) ($params['out_trade_no'] ?? '')];
        $bind[':uid'] = (int) $merchant['id'];
        $query = Database::connection()->prepare("SELECT * FROM `order` WHERE uid = :uid AND {$where} LIMIT 1");
        $query->execute($bind);
        $order = $query->fetch();
        if (!is_array($order)) {
            Response::json(['code' => 0, 'msg' => '订单不存在']);
        }
        Response::json(['code' => 1, 'msg' => '查询订单号成功！'] + $this->formatOrder($order));
    }

    private function orders(array $params, array $merchant): never
    {
        $limit = min(max((int) ($params['limit'] ?? 20), 1), 50);
        $page = max((int) ($params['page'] ?? 1), 1);
        $query = Database::connection()->prepare('SELECT * FROM `order` WHERE uid = :uid ORDER BY id DESC LIMIT :offset, :limit');
        $query->bindValue(':uid', (int) $merchant['id'], PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();
        $items = [];
        foreach ($query->fetchAll() as $order) {
            $items[] = $this->formatOrder($order);
        }
        Response::json(['code' => 1, 'msg' => '查询结算记录成功！', 'data' => $items]);
    }

    /** @return array<string, mixed> */
    private function formatOrder(array $order): array
    {
        return [
            'trade_no' => (string) $order['order_id'],
            'out_trade_no' => (string) $order['out_order_id'],
            'api_trade_no' => (string) ($order['out_pay_order_id'] ?? ''),
            'type' => (string) $order['pay_type'],
            'pid' => (int) $order['uid'],
            'addtime' => date('Y-m-d H:i:s', (int) $order['created_at']),
            'endtime' => $order['pay_time'] ? date('Y-m-d H:i:s', (int) $order['pay_time']) : '',
            'name' => (string) $order['subject'],
            'money' => number_format(((int) $order['amount']) / 100, 2, '.', ''),
            'status' => (int) $order['status'] === 2 ? 1 : 0,
            'param' => (string) ($order['param'] ?? ''),
            'buyer' => (string) ($order['actual_account'] ?? ''),
        ];
    }

    private function absolute(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
    }
}
