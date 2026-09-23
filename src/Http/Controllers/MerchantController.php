<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use PDOException;
use XArrPay\Http\Request;
use XArrPay\Support\Captcha;
use XArrPay\Support\Database;
use XArrPay\Support\Response;

final class MerchantController
{
    public function login(Request $request): never
    {
        $params = $request->all();
        if (!Captcha::verify(
            trim((string) ($params['captcha_id'] ?? '')),
            trim((string) ($params['captcha_code'] ?? ''))
        )) {
            Response::json(['code' => 502, 'message' => '请输入正确的验证码', 'data' => [], 'redirect' => '']);
        }

        $username = trim((string) ($params['username'] ?? ''));
        $password = (string) ($params['password'] ?? '');
        if ($username === '' || $password === '') {
            Response::json(['code' => 400, 'message' => '请输入用户名和密码', 'data' => [], 'redirect' => '']);
        }

        try {
            $query = Database::connection()->prepare('SELECT * FROM `user` WHERE username = :username LIMIT 1');
            $query->execute([':username' => $username]);
            $merchant = $query->fetch();
        } catch (PDOException $exception) {
            Response::json(['code' => 503, 'message' => '数据库不可用', 'data' => [], 'redirect' => ''], 503);
        }

        if (!is_array($merchant) || (int) ($merchant['status'] ?? 0) !== 1 || !$this->passwordMatches($password, (string) ($merchant['password'] ?? ''))) {
            Response::json(['code' => 401, 'message' => '用户名或密码错误', 'data' => [], 'redirect' => '']);
        }

        $token = $this->token();
        $update = Database::connection()->prepare('UPDATE `user` SET token = :token WHERE id = :id');
        $update->execute([':token' => $token, ':id' => (int) $merchant['id']]);

        Response::success([
            'status' => 1,
            'data' => ['token' => $token],
        ], '登录成功');
    }

    public function profile(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        Response::success([
            'id' => (int) ($merchant['id'] ?? 0),
            'username' => (string) ($merchant['username'] ?? ''),
            'name' => (string) ($merchant['merchant_name'] ?? $merchant['username'] ?? ''),
            'merchant_name' => (string) ($merchant['merchant_name'] ?? ''),
            'status' => (int) ($merchant['status'] ?? 0),
            'balance' => (int) ($merchant['balance'] ?? 0),
        ]);
    }

    public function payTypes(Request $request): never
    {
        $this->authenticatedMerchant($request);
        $params = $request->all();
        $rows = $this->filterRows($this->tableRows('pay_type'), $params, ['status'], ['query' => ['value', 'label', 'name']]);
        $items = array_map(static function (array $row): array {
            $value = (string) ($row['value'] ?? $row['code'] ?? $row['name'] ?? $row['id'] ?? '');
            $label = (string) ($row['label'] ?? $row['title'] ?? $row['name'] ?? $value);
            return $row + ['value' => $value, 'label' => $label];
        }, $rows);
        Response::success($this->paginate($items, $params));
    }

    public function channels(Request $request): never
    {
        $this->authenticatedMerchant($request);
        $params = $request->all();
        $rows = $this->filterRows($this->tableRows('pay_channel'), $params, ['status', 'type'], ['query' => ['code', 'name', 'type']]);
        $items = array_map(static function (array $row): array {
            $code = (string) ($row['code'] ?? $row['channel_code'] ?? $row['name'] ?? $row['id'] ?? '');
            $name = (string) ($row['name'] ?? $row['title'] ?? $code);
            return $row + ['code' => $code, 'name' => $name, 'type' => (string) ($row['type'] ?? $row['pay_type'] ?? '')];
        }, $rows);
        Response::success($this->paginate($items, $params));
    }

    public function channelAccounts(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $params['uid'] = (int) $merchant['id'];
        $rows = $this->filterRows($this->tableRows('pay_account'), $params, ['uid', 'pay_type', 'channel_code', 'status'], ['query' => ['name', 'account', 'sub_account', 'remark']]);
        usort($rows, static fn (array $left, array $right): int => ((int) ($left['sort'] ?? 0) <=> (int) ($right['sort'] ?? 0)) ?: ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0)));
        Response::success($this->paginate($rows, $params));
    }

    public function orders(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $uid = (int) $merchant['id'];
        $rows = array_values(array_filter($this->tableRows('order'), static function (array $row) use ($params, $uid): bool {
            if ((int) ($row['uid'] ?? 0) !== $uid) {
                return false;
            }
            foreach (['status', 'pay_type', 'channel_code'] as $field) {
                if (!isset($params[$field]) || $params[$field] === '' || ($field === 'status' && (int) $params[$field] === 0)) {
                    continue;
                }
                if ((string) ($row[$field] ?? '') !== (string) $params[$field]) {
                    return false;
                }
            }
            $query = trim((string) ($params['query'] ?? ''));
            if ($query !== '' && stripos(implode(' ', array_map(static fn (mixed $value): string => (string) $value, $row)), $query) === false) {
                return false;
            }
            return true;
        }));
        usort($rows, static fn (array $left, array $right): int => (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0));
        Response::success($this->paginate($rows, $params));
    }

    public function statistics(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        Response::success($this->orderStats((int) $merchant['id']));
    }

    public function orderBase(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        Response::success($this->orderStats((int) $merchant['id']));
    }

    public function staticPayTypes(Request $request): never
    {
        $this->authenticatedMerchant($request);
        $rows = array_values(array_filter($this->tableRows('pay_type'), static fn (array $row): bool => (int) ($row['status'] ?? 0) === 1));
        $items = array_map(static function (array $row): array {
            $value = (string) ($row['value'] ?? $row['code'] ?? $row['id'] ?? '');
            return [
                'value' => $value,
                'code' => (string) ($row['code'] ?? $value),
                'name' => (string) ($row['name'] ?? $row['label'] ?? $value),
                'label' => (string) ($row['label'] ?? $row['name'] ?? $value),
                'logo' => (string) ($row['logo'] ?? ''),
            ];
        }, $rows);
        Response::success(['list' => $items, 'count' => count($items), 'total' => count($items)]);
    }

    public function payHourStats(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $uid = (int) $merchant['id'];
        $start = strtotime('today');
        $rows = array_fill(0, 24, ['count' => 0, 'amount' => 0]);
        foreach ($this->ordersSince($uid, (int) $start) as $order) {
            $hour = (int) date('G', (int) ($order['created_at'] ?? 0));
            $rows[$hour]['count']++;
            if ((int) ($order['status'] ?? 0) === 2) {
                $rows[$hour]['amount'] += (int) ($order['trade_amount'] ?? 0);
            }
        }
        $list = [];
        foreach ($rows as $hour => $row) {
            $list[] = ['hour' => $hour, 'label' => sprintf('%02d:00', $hour), 'count' => $row['count'], 'amount' => $row['amount']];
        }
        Response::success(['list' => $list]);
    }

    public function payDailyStats(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $uid = (int) $merchant['id'];
        $days = 7;
        $start = strtotime('-' . ($days - 1) . ' days midnight');
        $rows = [];
        for ($index = 0; $index < $days; $index++) {
            $day = date('Y-m-d', (int) $start + $index * 86400);
            $rows[$day] = ['date' => $day, 'count' => 0, 'amount' => 0];
        }
        foreach ($this->ordersSince($uid, (int) $start) as $order) {
            $day = date('Y-m-d', (int) ($order['created_at'] ?? 0));
            if (!isset($rows[$day])) {
                continue;
            }
            $rows[$day]['count']++;
            if ((int) ($order['status'] ?? 0) === 2) {
                $rows[$day]['amount'] += (int) ($order['trade_amount'] ?? 0);
            }
        }
        Response::success(['list' => array_values($rows)]);
    }

    public function noticeShow(Request $request): never
    {
        $this->authenticatedMerchant($request);
        Response::json(['code' => 204, 'message' => '暂无公告', 'data' => [], 'redirect' => '']);
    }

    public function noticeShows(Request $request): never
    {
        $this->authenticatedMerchant($request);
        Response::success($this->paginate([], $request->all()));
    }

    public function plugins(Request $request): never
    {
        $this->authenticatedMerchant($request);
        Response::success(['list' => [], 'count' => 0, 'total' => 0]);
    }

    /** @return array<string, mixed> */
    private function authenticatedMerchant(Request $request): array
    {
        $token = trim((string) ($request->header('Authorization') ?? ''));
        if ($token === '') {
            Response::json(['code' => 404, 'message' => '未登录', 'data' => [], 'redirect' => '']);
        }

        try {
            $query = Database::connection()->prepare('SELECT * FROM `user` WHERE token = :token AND status = 1 LIMIT 1');
            $query->execute([':token' => $token]);
            $merchant = $query->fetch();
        } catch (PDOException $exception) {
            Response::json(['code' => 503, 'message' => '数据库不可用', 'data' => [], 'redirect' => ''], 503);
        }

        if (!is_array($merchant)) {
            Response::json(['code' => 401, 'message' => '登录已失效', 'data' => [], 'redirect' => '']);
        }
        return $merchant;
    }

    private function passwordMatches(string $password, string $stored): bool
    {
        return preg_match('/^[a-f0-9]{32}$/i', $stored) === 1 && hash_equals(strtolower($stored), md5($password));
    }

    /** @return list<array<string, mixed>> */
    private function tableRows(string $table): array
    {
        $allowed = ['pay_type', 'pay_channel', 'pay_account', 'order'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported table');
        }
        $quoted = $table === 'order' ? '`order`' : '`' . $table . '`';
        $query = Database::connection()->query('SELECT * FROM ' . $quoted);
        $rows = $query->fetchAll();
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string, mixed> $params @param list<string> $exactFields @param array<string, list<string>> $searchFields @return list<array<string, mixed>> */
    private function filterRows(array $rows, array $params, array $exactFields, array $searchFields): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($params, $exactFields, $searchFields): bool {
            foreach ($exactFields as $field) {
                if (!array_key_exists($field, $params) || $params[$field] === '' || ($field === 'status' && (int) $params[$field] === 0)) {
                    continue;
                }
                if ((string) ($row[$field] ?? '') !== (string) $params[$field]) {
                    return false;
                }
            }
            foreach ($searchFields as $parameter => $fields) {
                $needle = trim((string) ($params[$parameter] ?? ''));
                if ($needle === '') {
                    continue;
                }
                $haystack = implode(' ', array_map(static fn (string $field): string => (string) ($row[$field] ?? ''), $fields));
                if (stripos($haystack, $needle) === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** @param list<array<string, mixed>> $rows @return array{list: list<array<string, mixed>>, count: int, total: int, page: int, limit: int} */
    private function paginate(array $rows, array $params): array
    {
        $page = max((int) ($params['page'] ?? 1), 1);
        $limit = min(max((int) ($params['limit'] ?? 20), 1), 200);
        return [
            'list' => array_slice($rows, ($page - 1) * $limit, $limit),
            'count' => count($rows),
            'total' => count($rows),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array<string, int> */
    private function orderStats(int $uid): array
    {
        $db = Database::connection();
        $totalQuery = $db->prepare('SELECT COUNT(*) FROM `order` WHERE uid = :uid');
        $totalQuery->execute([':uid' => $uid]);
        $paidQuery = $db->prepare('SELECT COUNT(*) FROM `order` WHERE uid = :uid AND status = 2');
        $paidQuery->execute([':uid' => $uid]);
        $amountQuery = $db->prepare('SELECT COALESCE(SUM(trade_amount), 0) FROM `order` WHERE uid = :uid AND status = 2');
        $amountQuery->execute([':uid' => $uid]);
        $todayStart = strtotime('today');
        $todayQuery = $db->prepare('SELECT COUNT(*) FROM `order` WHERE uid = :uid AND created_at >= :today_start');
        $todayQuery->execute([':uid' => $uid, ':today_start' => $todayStart]);
        $total = (int) $totalQuery->fetchColumn();
        $paid = (int) $paidQuery->fetchColumn();
        $amount = (int) ($amountQuery->fetchColumn() ?: 0);
        $today = (int) $todayQuery->fetchColumn();
        return [
            'order_count' => $total,
            'order_success_count' => $paid,
            'order_success_amount' => $amount,
            'order_today_count' => $today,
            'total' => $total,
            'success' => $paid,
            'amount' => $amount,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ordersSince(int $uid, int $start): array
    {
        $query = Database::connection()->prepare('SELECT * FROM `order` WHERE uid = :uid AND created_at >= :created_at ORDER BY created_at ASC');
        $query->execute([':uid' => $uid, ':created_at' => $start]);
        $rows = $query->fetchAll();
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function token(): string
    {
        return bin2hex(random_bytes(24));
    }
}
