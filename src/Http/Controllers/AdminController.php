<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use PDOException;
use XArrPay\Http\Request;
use XArrPay\Support\Captcha;
use XArrPay\Support\Database;
use XArrPay\Support\PluginRuntime;
use XArrPay\Support\Response;

final class AdminController
{
    public function loginConnect(): never
    {
        Response::success([
            [
                'code' => 'account',
                'name' => '账号密码',
                'type' => 'form',
                'icon' => '.el-icon-user',
            ],
        ]);
    }

    public function loginChannels(): never
    {
        Response::success([
            [
                'code' => 'account',
                'name' => '账号密码',
                'type' => 'form',
                'icon' => '.el-icon-user',
            ],
        ]);
    }

    public function config(): never
    {
        try {
            $query = Database::connection()->query('SELECT `key`, value FROM `options` ORDER BY `key`');
            $config = [];
            foreach ($query->fetchAll() as $row) {
                $config[(string) $row['key']] = $this->decodeOption($row['value'] ?? '');
            }
        } catch (PDOException $exception) {
            Response::json(['code' => 503, 'message' => '数据库不可用', 'data' => [], 'redirect' => ''], 503);
        }

        $config['admin_path'] = 'admin';
        $config['version'] ??= '1.5.1.12';
        $config['web_title'] ??= 'XArrPay 支付系统';
        $config['captcha_type'] ??= 'captcha';
        Response::success($config);
    }

    public function captcha(): never
    {
        if (!Captcha::available()) {
            Response::json(['code' => 503, 'message' => 'PHP GD 扩展未启用', 'data' => [], 'redirect' => ''], 503);
        }

        $captcha = Captcha::issue();
        Response::success([
            'captcha_type' => 'captcha',
            'captcha_id' => $captcha['captcha_id'],
            'captcha_base64' => $captcha['captcha_base64'],
        ]);
    }

    public function login(Request $request): never
    {
        $params = $request->all();
        $captchaId = trim((string) ($params['captcha_id'] ?? ''));
        $captchaCode = trim((string) ($params['captcha_code'] ?? ''));
        if (!Captcha::verify($captchaId, $captchaCode)) {
            Response::json(['code' => 502, 'message' => '请输入正确的验证码', 'data' => [], 'redirect' => '']);
        }

        $username = trim((string) ($params['username'] ?? ''));
        $password = (string) ($params['password'] ?? '');
        if ($username === '' || $password === '') {
            Response::json(['code' => 400, 'message' => '请输入用户名和密码', 'data' => [], 'redirect' => '']);
        }

        try {
            $query = Database::connection()->prepare('SELECT * FROM `staff` WHERE username = :username LIMIT 1');
            $query->execute([':username' => $username]);
            $staff = $query->fetch();
        } catch (PDOException $exception) {
            Response::json(['code' => 503, 'message' => '数据库不可用', 'data' => [], 'redirect' => ''], 503);
        }

        if (!is_array($staff) || (int) ($staff['status'] ?? 0) !== 1 || !$this->passwordMatches($password, (string) ($staff['password'] ?? ''))) {
            Response::json(['code' => 401, 'message' => '用户名或密码错误', 'data' => [], 'redirect' => '']);
        }

        $token = $this->token();
        $update = Database::connection()->prepare('UPDATE `staff` SET token = :token WHERE id = :id');
        $update->execute([':token' => $token, ':id' => (int) $staff['id']]);

        Response::success(['token' => $token], '登录成功');
    }

    public function profile(Request $request): never
    {
        $staff = $this->authenticatedStaff($request);
        $profile = [
            'id' => (int) ($staff['id'] ?? 0),
            'username' => (string) ($staff['username'] ?? ''),
            'name' => (string) ($staff['name'] ?? $staff['username'] ?? ''),
            'super' => (int) ($staff['super'] ?? 0),
            'status' => (int) ($staff['status'] ?? 0),
            'email' => (string) ($staff['email'] ?? ''),
            'phone' => (string) ($staff['phone'] ?? ''),
            'avatar' => (string) ($staff['avatar'] ?? ''),
            'mfa' => !empty($staff['mfa_secret']),
        ];
        Response::success($profile);
    }

    public function payTypes(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $rows = $this->filterRows($this->tableRows('pay_type'), $params, ['status'], ['query' => ['value', 'label', 'name']]);
        $items = array_map(function (array $row): array {
            $value = (string) ($row['value'] ?? $row['code'] ?? $row['name'] ?? $row['id'] ?? '');
            $label = (string) ($row['label'] ?? $row['title'] ?? $row['name'] ?? $value);
            return $row + ['value' => $value, 'label' => $label];
        }, $rows);
        Response::success($this->paginate($items, $params));
    }

    public function channels(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $rows = $this->filterRows($this->tableRows('pay_channel'), $params, ['status', 'type'], ['query' => ['code', 'name', 'type']]);
        $items = array_map(function (array $row): array {
            $code = (string) ($row['code'] ?? $row['channel_code'] ?? $row['name'] ?? $row['id'] ?? '');
            $name = (string) ($row['name'] ?? $row['title'] ?? $code);
            return $row + ['code' => $code, 'name' => $name, 'type' => (string) ($row['type'] ?? $row['pay_type'] ?? '')];
        }, $rows);
        Response::success($this->paginate($items, $params));
    }

    public function createPayType(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $value = trim((string) ($params['value'] ?? $params['code'] ?? ''));
        $label = trim((string) ($params['label'] ?? $params['name'] ?? ''));
        if ($value === '' || $label === '') {
            Response::error('支付方式代码和名称不能为空', 422, 422);
        }
        $db = Database::connection();
        $check = $db->prepare('SELECT COUNT(*) FROM `pay_type` WHERE value = :value');
        $check->execute([':value' => $value]);
        if ((int) $check->fetchColumn() > 0) {
            Response::error('支付方式代码已存在', 409, 409);
        }
        $insert = $db->prepare('INSERT INTO `pay_type` (value, code, name, label, logo, status) VALUES (:value, :code, :name, :label, :logo, :status)');
        $insert->execute([
            ':value' => $value,
            ':code' => trim((string) ($params['code'] ?? $value)),
            ':name' => trim((string) ($params['name'] ?? $label)),
            ':label' => $label,
            ':logo' => trim((string) ($params['logo'] ?? '')),
            ':status' => $this->statusValue($params['status'] ?? 1),
        ]);
        Response::success(['id' => (int) $db->lastInsertId()], '支付方式创建成功');
    }

    public function editPayType(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $id = $this->requiredId($params);
        $value = trim((string) ($params['value'] ?? ''));
        $label = trim((string) ($params['label'] ?? $params['name'] ?? ''));
        if ($value === '' || $label === '') {
            Response::error('支付方式代码和名称不能为空', 422, 422);
        }
        $db = Database::connection();
        $check = $db->prepare('SELECT COUNT(*) FROM `pay_type` WHERE value = :value AND id <> :id');
        $check->execute([':value' => $value, ':id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            Response::error('支付方式代码已存在', 409, 409);
        }
        $update = $db->prepare('UPDATE `pay_type` SET value = :value, code = :code, name = :name, label = :label, logo = :logo, status = :status WHERE id = :id');
        $update->execute([
            ':id' => $id,
            ':value' => $value,
            ':code' => trim((string) ($params['code'] ?? $value)),
            ':name' => trim((string) ($params['name'] ?? $label)),
            ':label' => $label,
            ':logo' => trim((string) ($params['logo'] ?? '')),
            ':status' => $this->statusValue($params['status'] ?? 1),
        ]);
        $this->ensureAffected($update->rowCount(), '支付方式不存在');
        Response::success(null, '支付方式保存成功');
    }

    public function removePayType(Request $request): never
    {
        $this->authenticatedStaff($request);
        $id = $this->requiredId($request->all());
        $db = Database::connection();
        $row = $this->findById('pay_type', $id);
        $value = (string) ($row['value'] ?? '');
        $check = $db->prepare('SELECT COUNT(*) FROM `pay_account` WHERE pay_type = :pay_type');
        $check->execute([':pay_type' => $value]);
        if ((int) $check->fetchColumn() > 0) {
            Response::error('该支付方式仍有收款账号，不能删除', 409, 409);
        }
        $delete = $db->prepare('DELETE FROM `pay_type` WHERE id = :id');
        $delete->execute([':id' => $id]);
        $this->ensureAffected($delete->rowCount(), '支付方式不存在');
        Response::success(null, '支付方式删除成功');
    }

    public function switchPayTypeStatus(Request $request): never
    {
        $this->authenticatedStaff($request);
        $this->switchStatus('pay_type', $request->all(), '支付方式状态更新成功');
    }

    public function createChannel(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $code = trim((string) ($params['code'] ?? ''));
        $name = trim((string) ($params['name'] ?? $params['title'] ?? ''));
        if ($code === '' || $name === '') {
            Response::error('通道代码和名称不能为空', 422, 422);
        }
        $db = Database::connection();
        $check = $db->prepare('SELECT COUNT(*) FROM `pay_channel` WHERE code = :code');
        $check->execute([':code' => $code]);
        if ((int) $check->fetchColumn() > 0) {
            Response::error('通道代码已存在', 409, 409);
        }
        $insert = $db->prepare('INSERT INTO `pay_channel` (code, name, type, status, plugin_name, remark, options) VALUES (:code, :name, :type, :status, :plugin_name, :remark, :options)');
        $insert->execute($this->channelParams($params, $code, $name));
        Response::success(['id' => (int) $db->lastInsertId()], '通道创建成功');
    }

    public function editChannel(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $id = $this->requiredId($params);
        $code = trim((string) ($params['code'] ?? ''));
        $name = trim((string) ($params['name'] ?? $params['title'] ?? ''));
        if ($code === '' || $name === '') {
            Response::error('通道代码和名称不能为空', 422, 422);
        }
        $db = Database::connection();
        $check = $db->prepare('SELECT COUNT(*) FROM `pay_channel` WHERE code = :code AND id <> :id');
        $check->execute([':code' => $code, ':id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            Response::error('通道代码已存在', 409, 409);
        }
        $values = $this->channelParams($params, $code, $name);
        $values[':id'] = $id;
        $update = $db->prepare('UPDATE `pay_channel` SET code = :code, name = :name, type = :type, status = :status, plugin_name = :plugin_name, remark = :remark, options = :options WHERE id = :id');
        $update->execute($values);
        $this->ensureAffected($update->rowCount(), '通道不存在');
        Response::success(null, '通道保存成功');
    }

    public function removeChannel(Request $request): never
    {
        $this->authenticatedStaff($request);
        $id = $this->requiredId($request->all());
        $row = $this->findById('pay_channel', $id);
        $code = (string) ($row['code'] ?? '');
        $db = Database::connection();
        $check = $db->prepare('SELECT COUNT(*) FROM `pay_account` WHERE channel_code = :code');
        $check->execute([':code' => $code]);
        if ((int) $check->fetchColumn() > 0) {
            Response::error('该通道仍有收款账号，不能删除', 409, 409);
        }
        $delete = $db->prepare('DELETE FROM `pay_channel` WHERE id = :id');
        $delete->execute([':id' => $id]);
        $this->ensureAffected($delete->rowCount(), '通道不存在');
        Response::success(null, '通道删除成功');
    }

    public function switchChannelStatus(Request $request): never
    {
        $this->authenticatedStaff($request);
        $this->switchStatus('pay_channel', $request->all(), '通道状态更新成功');
    }

    public function channelDetail(Request $request): never
    {
        $this->authenticatedStaff($request);
        $row = $this->findById('pay_channel', $this->requiredId($request->all()));
        $row['options'] = $this->decodeJsonField($row['options'] ?? '{}', []);
        Response::success($row);
    }

    public function createChannelAccount(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $this->validateAccount($params);
        $db = Database::connection();
        $insert = $db->prepare('INSERT INTO `pay_account` (uid, pay_type, channel_code, account, account_type, qrcode_data, qrcode, uri, scheme, status, name, sub_account, bind_client_name, sort, remark, min_amount, max_amount, day_amount_limit, code, options, bind_pay_type) VALUES (:uid, :pay_type, :channel_code, :account, :account_type, :qrcode_data, :qrcode, :uri, :scheme, :status, :name, :sub_account, :bind_client_name, :sort, :remark, :min_amount, :max_amount, :day_amount_limit, :code, :options, :bind_pay_type)');
        $insert->execute($this->accountParams($params));
        Response::success(['id' => (int) $db->lastInsertId()], '收款账号创建成功');
    }

    public function editChannelAccount(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $id = $this->requiredId($params);
        $this->validateAccount($params);
        $values = $this->accountParams($params);
        $values[':id'] = $id;
        $update = Database::connection()->prepare('UPDATE `pay_account` SET uid = :uid, pay_type = :pay_type, channel_code = :channel_code, account = :account, account_type = :account_type, qrcode_data = :qrcode_data, qrcode = :qrcode, uri = :uri, scheme = :scheme, status = :status, name = :name, sub_account = :sub_account, bind_client_name = :bind_client_name, sort = :sort, remark = :remark, min_amount = :min_amount, max_amount = :max_amount, day_amount_limit = :day_amount_limit, code = :code, options = :options, bind_pay_type = :bind_pay_type WHERE id = :id');
        $update->execute($values);
        $this->ensureAffected($update->rowCount(), '收款账号不存在');
        Response::success(null, '收款账号保存成功');
    }

    public function removeChannelAccount(Request $request): never
    {
        $this->authenticatedStaff($request);
        $delete = Database::connection()->prepare('DELETE FROM `pay_account` WHERE id = :id');
        $delete->execute([':id' => $this->requiredId($request->all())]);
        $this->ensureAffected($delete->rowCount(), '收款账号不存在');
        Response::success(null, '收款账号删除成功');
    }

    public function switchChannelAccountStatus(Request $request): never
    {
        $this->authenticatedStaff($request);
        $this->switchStatus('pay_account', $request->all(), '收款账号状态更新成功');
    }

    public function channelAccounts(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $rows = $this->tableRows('pay_account');
        $rows = $this->filterRows($rows, $params, ['uid', 'pay_type', 'channel_code', 'status'], ['query' => ['name', 'account', 'sub_account', 'remark']]);
        usort($rows, static fn (array $left, array $right): int => ((int) ($left['sort'] ?? 0) <=> (int) ($right['sort'] ?? 0)) ?: ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0)));
        Response::success($this->paginate($rows, $params));
    }

    public function orders(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $rows = $this->tableRows('order');
        $rows = array_values(array_filter($rows, function (array $row) use ($params): bool {
            foreach (['status', 'pay_type', 'channel_code'] as $field) {
                if (isset($params[$field]) && $params[$field] !== '' && ($field !== 'status' || (int) $params[$field] !== 0) && (string) ($row[$field] ?? '') !== (string) $params[$field]) {
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

    public function removeOrder(Request $request): never
    {
        $this->authenticatedStaff($request);
        $delete = Database::connection()->prepare('DELETE FROM `order` WHERE id = :id OR order_id = :order_id');
        $id = $request->input('id');
        $orderId = trim((string) $request->input('order_id', ''));
        if ($id === null && $orderId === '') {
            Response::error('订单号不能为空', 422, 422);
        }
        $delete->execute([':id' => (int) ($id ?? 0), ':order_id' => $orderId]);
        $this->ensureAffected($delete->rowCount(), '订单不存在');
        Response::success(null, '订单删除成功');
    }

    public function batchRemoveOrders(Request $request): never
    {
        $this->authenticatedStaff($request);
        $ids = $request->input('order_ids', []);
        if (!is_array($ids) || $ids === []) {
            Response::error('请选择要删除的订单', 422, 422);
        }
        $db = Database::connection();
        $removed = 0;
        $delete = $db->prepare('DELETE FROM `order` WHERE order_id = :order_id');
        foreach ($ids as $orderId) {
            $delete->execute([':order_id' => trim((string) $orderId)]);
            $removed += $delete->rowCount();
        }
        Response::success(['removed' => $removed], '批量删除完成');
    }

    public function closeOrder(Request $request): never
    {
        $this->authenticatedStaff($request);
        $this->updateOrderStatus($request, 3, '订单已关闭');
    }

    public function waitOrder(Request $request): never
    {
        $this->authenticatedStaff($request);
        $this->updateOrderStatus($request, 1, '订单已设为待支付');
    }

    public function successOrder(Request $request): never
    {
        $this->authenticatedStaff($request);
        $this->updateOrderStatus($request, 2, '订单已补单成功');
    }

    public function callbackOrder(Request $request): never
    {
        $this->authenticatedStaff($request);
        $order = $this->findOrder($request->all());
        $now = time();
        $update = Database::connection()->prepare('UPDATE `order` SET notify_status = 1, notify_count = notify_count + 1, notify_time = :notify_time, updated_at = :updated_at WHERE id = :id');
        $update->execute([':notify_time' => $now, ':updated_at' => $now, ':id' => (int) $order['id']]);
        Response::success(['order_id' => (string) $order['order_id'], 'notify_time' => $now], '订单回调已记录');
    }

    public function orderAction(Request $request): never
    {
        $this->authenticatedStaff($request);
        $action = trim((string) $request->input('action', ''));
        if ($action === 'clear_timeout') {
            $update = Database::connection()->prepare('UPDATE `order` SET status = 4, updated_at = :updated_at WHERE status = 1 AND expire_time > 0 AND expire_time < :now');
            $update->execute([':updated_at' => time(), ':now' => time()]);
            Response::success(['affected' => $update->rowCount()], '超时订单清理完成');
        }
        if ($action === 'clear_all') {
            $delete = Database::connection()->prepare('DELETE FROM `order`');
            $delete->execute();
            Response::success(['affected' => $delete->rowCount()], '订单已清空');
        }
        Response::error('不支持的订单操作', 422, 422);
    }

    public function createTestOrder(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $amount = (int) ($params['amount'] ?? $params['money'] ?? 100);
        if ($amount <= 0) {
            Response::error('测试订单金额必须大于 0', 422, 422);
        }
        $now = time();
        $orderId = 'TEST-' . date('YmdHis') . random_int(1000, 9999);
        $insert = Database::connection()->prepare('INSERT INTO `order` (order_id, out_order_id, uid, price, amount, trade_amount, rate_amount, status, subject, expire_time, pay_type, created_at, updated_at) VALUES (:order_id, :out_order_id, :uid, :price, :amount, :trade_amount, 0, 1, :subject, :expire_time, :pay_type, :created_at, :updated_at)');
        $insert->execute([
            ':order_id' => $orderId,
            ':out_order_id' => (string) ($params['out_order_id'] ?? $orderId),
            ':uid' => (int) ($params['uid'] ?? 1),
            ':price' => $amount,
            ':amount' => $amount,
            ':trade_amount' => $amount,
            ':subject' => trim((string) ($params['subject'] ?? '后台测试订单')),
            ':expire_time' => $now + 900,
            ':pay_type' => trim((string) ($params['pay_type'] ?? '')),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        Response::success(['order_id' => $orderId, 'trade_no' => $orderId], '测试订单创建成功');
    }

    public function getOptionGroup(Request $request, string $group): never
    {
        $this->authenticatedStaff($request);
        if ($group === 'compliance') {
            $this->compliance($request);
        }
        $this->validateOptionGroup($group);
        Response::success($this->readOptionGroup($group));
    }

    public function setOptionGroup(Request $request, string $group): never
    {
        $this->authenticatedStaff($request);
        if ($group === 'compliance') {
            $this->confirmCompliance($request);
        }
        $this->validateOptionGroup($group);
        $data = $request->all();
        unset($data['group']);
        $this->saveOption('option.' . $group, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Response::success($data, '配置保存成功');
    }

    public function pluginAction(Request $request, string $action): never
    {
        $this->authenticatedStaff($request);
        $name = trim((string) ($request->input('name') ?? $request->input('plugin_name') ?? ''));
        if ($action !== 'refresh' && $name === '') {
            Response::error('插件名称不能为空', 422, 422);
        }
        if ($action === 'refresh') {
            Response::success(['list' => PluginRuntime::manifests()], '插件清单已刷新');
        }
        if ($action === 'remove') {
            PluginRuntime::remove($name);
        } else {
            PluginRuntime::setState($name, $action === 'start' || $action === 'enable' ? $action : $action);
        }
        Response::success(null, '插件操作成功');
    }

    public function statistics(Request $request): never
    {
        $this->authenticatedStaff($request);
        $db = Database::connection();
        $total = (int) $db->query('SELECT COUNT(*) FROM `order`')->fetchColumn();
        $paid = (int) $db->query('SELECT COUNT(*) FROM `order` WHERE status = 2')->fetchColumn();
        $amount = (int) ($db->query('SELECT COALESCE(SUM(trade_amount), 0) FROM `order` WHERE status = 2')->fetchColumn() ?: 0);
        $todayStart = strtotime('today');
        $todayQuery = $db->prepare('SELECT COUNT(*) FROM `order` WHERE created_at >= :today_start');
        $todayQuery->execute([':today_start' => $todayStart]);
        $today = (int) $todayQuery->fetchColumn();
        Response::success([
            'order_count' => $total,
            'order_success_count' => $paid,
            'order_success_amount' => $amount,
            'order_today_count' => $today,
            'total' => $total,
            'success' => $paid,
            'amount' => $amount,
        ]);
    }

    public function plugins(Request $request): never
    {
        $this->authenticatedStaff($request);
        $items = PluginRuntime::manifests();
        foreach ($items as &$item) {
            unset($item['_path']);
        }
        unset($item);
        Response::success(['list' => $items, 'count' => count($items)]);
    }

    public function payConf(Request $request): never
    {
        $this->authenticatedStaff($request);
        $payTypes = array_map(static function (array $row): array {
            $value = (string) ($row['value'] ?? $row['code'] ?? $row['id'] ?? '');
            return [
                'id' => (int) ($row['id'] ?? 0),
                'value' => $value,
                'code' => (string) ($row['code'] ?? $value),
                'name' => (string) ($row['name'] ?? $row['label'] ?? $value),
                'label' => (string) ($row['label'] ?? $row['name'] ?? $value),
                'logo' => (string) ($row['logo'] ?? ''),
                'status' => (int) ($row['status'] ?? 0),
            ];
        }, $this->tableRows('pay_type'));
        $channels = array_map(static function (array $row): array {
            $code = (string) ($row['code'] ?? '');
            return [
                'id' => (int) ($row['id'] ?? 0),
                'code' => $code,
                'name' => (string) ($row['name'] ?? $code),
                'type' => (string) ($row['type'] ?? ''),
                'status' => (int) ($row['status'] ?? 0),
                'plugin_name' => (string) ($row['plugin_name'] ?? ''),
                'remark' => (string) ($row['remark'] ?? ''),
            ];
        }, $this->tableRows('pay_channel'));

        Response::success(['pay_type' => $payTypes, 'channels' => $channels]);
    }

    public function homeInfo(Request $request): never
    {
        $this->authenticatedStaff($request);
        $db = Database::connection();
        $todayStart = (int) strtotime('today');
        $orderCount = (int) $db->query('SELECT COUNT(*) FROM `order`')->fetchColumn();
        $successCount = (int) $db->query('SELECT COUNT(*) FROM `order` WHERE status = 2')->fetchColumn();
        $successAmount = (int) ($db->query('SELECT COALESCE(SUM(trade_amount), 0) FROM `order` WHERE status = 2')->fetchColumn() ?: 0);
        $merchantCount = (int) $db->query('SELECT COUNT(*) FROM `user`')->fetchColumn();
        $todayOrderQuery = $db->prepare('SELECT COUNT(*) FROM `order` WHERE created_at >= :start');
        $todayOrderQuery->execute([':start' => $todayStart]);
        $todaySuccessQuery = $db->prepare('SELECT COUNT(*) FROM `order` WHERE status = 2 AND created_at >= :start');
        $todaySuccessQuery->execute([':start' => $todayStart]);
        $todayAmountQuery = $db->prepare('SELECT COALESCE(SUM(trade_amount), 0) FROM `order` WHERE status = 2 AND created_at >= :start');
        $todayAmountQuery->execute([':start' => $todayStart]);
        $todayOrderCount = (int) $todayOrderQuery->fetchColumn();
        $todaySuccessCount = (int) $todaySuccessQuery->fetchColumn();
        $todaySuccessAmount = (int) ($todayAmountQuery->fetchColumn() ?: 0);

        Response::success([
            'overview' => [
                ['title' => '订单总数', 'value' => $orderCount, 'icon' => 'el-icon-s-order'],
                ['title' => '成功订单', 'value' => $successCount, 'icon' => 'el-icon-circle-check'],
                ['title' => '成交金额', 'value' => $this->moneyText($successAmount), 'icon' => 'el-icon-money'],
                ['title' => '商户数量', 'value' => $merchantCount, 'icon' => 'el-icon-user'],
                ['title' => '今日订单', 'value' => $todayOrderCount, 'icon' => 'el-icon-date'],
                ['title' => '今日成功', 'value' => $todaySuccessCount, 'icon' => 'el-icon-success'],
                ['title' => '今日成交', 'value' => $this->moneyText($todaySuccessAmount), 'icon' => 'el-icon-data-line'],
                ['title' => '支付方式', 'value' => count($this->tableRows('pay_type')), 'icon' => 'el-icon-bank-card'],
            ],
            'statis' => [
                'order_count' => $orderCount,
                'order_success_count' => $successCount,
                'order_success_amount' => $successAmount,
                'merchant_count' => $merchantCount,
                'today_order_count' => $todayOrderCount,
                'today_success_count' => $todaySuccessCount,
                'today_success_amount' => $todaySuccessAmount,
            ],
        ]);
    }

    public function homePayDistribution(Request $request): never
    {
        $this->authenticatedStaff($request);
        $labels = [];
        foreach ($this->tableRows('pay_type') as $row) {
            $value = (string) ($row['value'] ?? $row['code'] ?? '');
            if ($value !== '') {
                $labels[$value] = (string) ($row['label'] ?? $row['name'] ?? $value);
            }
        }
        $items = [];
        foreach ($this->tableRows('order') as $order) {
            $payType = (string) ($order['pay_type'] ?? '');
            $key = $payType !== '' ? $payType : 'unknown';
            $items[$key] ??= ['title' => $labels[$payType] ?? ($payType !== '' ? $payType : '未选择'), 'value' => 0, 'amount' => 0];
            $items[$key]['value']++;
            if ((int) ($order['status'] ?? 0) === 2) {
                $items[$key]['amount'] += (int) ($order['trade_amount'] ?? 0);
            }
        }
        if ($items === []) {
            foreach ($labels as $value => $label) {
                $items[$value] = ['title' => $label, 'value' => 0, 'amount' => 0];
            }
        }
        Response::success(['items' => array_values($items)]);
    }

    public function homeMerchantRegister(Request $request): never
    {
        $this->authenticatedStaff($request);
        $category = $this->lastDays(7);
        $merchantCount = (int) Database::connection()->query('SELECT COUNT(*) FROM `user`')->fetchColumn();
        Response::success([
            'category' => $category,
            'items' => [[
                'name' => '商户总数',
                'type' => 'line',
                'smooth' => true,
                'data' => array_fill(0, count($category), $merchantCount),
            ]],
        ]);
    }

    public function homeOrderAmount(Request $request): never
    {
        $this->authenticatedStaff($request);
        [$category, $rows] = $this->dailyOrderBuckets(7);
        Response::success([
            'category' => $category,
            'items' => [
                ['name' => '订单数量', 'type' => 'line', 'smooth' => true, 'data' => array_column($rows, 'count')],
                ['name' => '成交金额', 'type' => 'line', 'smooth' => true, 'data' => array_map(static fn (array $row): float => round($row['amount'] / 100, 2), $rows)],
            ],
        ]);
    }

    public function homeDailyRecharge(Request $request): never
    {
        $this->authenticatedStaff($request);
        $category = $this->lastDays(7);
        Response::success([
            'category' => $category,
            'items' => [[
                'name' => '充值金额',
                'type' => 'line',
                'smooth' => true,
                'data' => array_fill(0, count($category), 0),
            ]],
        ]);
    }

    public function homeMerchantRanking(Request $request): never
    {
        $this->authenticatedStaff($request);
        $query = Database::connection()->query('SELECT u.id, u.merchant_name, u.username, COUNT(o.id) AS order_count, COALESCE(SUM(o.trade_amount), 0) AS trade_amount FROM `user` u LEFT JOIN `order` o ON o.uid = u.id AND o.status = 2 GROUP BY u.id, u.merchant_name, u.username ORDER BY trade_amount DESC, order_count DESC, u.id ASC LIMIT 10');
        $items = [];
        foreach ($query->fetchAll() as $index => $row) {
            $items[] = [
                'rank' => $index + 1,
                'uid' => (int) ($row['id'] ?? 0),
                'merchant_name' => (string) ($row['merchant_name'] ?? $row['username'] ?? ''),
                'order_count' => (int) ($row['order_count'] ?? 0),
                'trade_amount' => $this->moneyText((int) ($row['trade_amount'] ?? 0)),
            ];
        }
        Response::success($items);
    }

    public function homeAuthorize(Request $request): never
    {
        $this->authenticatedStaff($request);
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        Response::success([
            'domain' => preg_replace('/:\d+$/', '', $host),
            'type' => '本地重构版',
            'reg_time' => strtotime('2026-09-22 00:00:00'),
            'expire_time' => 4102444800,
            'open_id' => substr(hash('sha256', $host . '|xarrpay-local'), 0, 24),
        ]);
    }

    public function homeSafeRate(Request $request): never
    {
        $this->authenticatedStaff($request);
        Response::success(['score' => 100, 'reasons' => []]);
    }

    public function systemInfo(Request $request): never
    {
        $this->authenticatedStaff($request);
        Response::success([
            'version' => '1.5.1.12-php-local',
            'version_code' => 'php-refactor-20260922',
            'version_btime' => '2026-09-22 00:00:00',
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'PHP built-in server',
        ]);
    }

    public function redisStatus(Request $request): never
    {
        $this->authenticatedStaff($request);
        Response::success([
            'configured' => false,
            'connected' => false,
            'message' => 'PHP 重构本地版当前未配置 Redis',
        ]);
    }

    public function compliance(Request $request): never
    {
        $this->authenticatedStaff($request);
        $requiredText = $this->optionString('compliance_required_text', '我已经了解以上规则,并同意继续使用');
        $ruleLines = $this->optionJsonList('compliance_rule_lines', [
            '禁止本系统用于任何非法用途，包括但不限于违法违规乱纪、诈骗洗钱、侵犯他人权益、传播违规内容、绕过监管或其他违反国家法律法规及平台规则的行为。',
            '严禁本系统被转借、出租、售卖、共享等非本人使用；严禁冒用他人身份、代实名、代操作等违规行为。',
            '一经发现上述或类似违规情况，平台有权立即取消授权资格、封禁账号并保留追责权利，已支付费用不予退款。',
        ]);
        $confirmed = $this->optionString('compliance_confirmed_content', '');

        Response::success([
            'required_text' => $requiredText,
            'rule_lines' => $ruleLines,
            'need_confirm' => $confirmed !== $requiredText,
        ]);
    }

    public function confirmCompliance(Request $request): never
    {
        $this->authenticatedStaff($request);
        $requiredText = $this->optionString('compliance_required_text', '我已经了解以上规则,并同意继续使用');
        $content = (string) $request->input('content', '');
        if ($content !== $requiredText) {
            Response::error('请输入完整指定内容后才能继续使用。', 422, 422);
        }

        $this->saveOption('compliance_confirmed_content', $content);
        Response::success(null, '规则确认成功。');
    }

    private function moneyText(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** @return list<string> */
    private function lastDays(int $days): array
    {
        $days = max($days, 1);
        $start = strtotime('today') - (($days - 1) * 86400);
        $items = [];
        for ($index = 0; $index < $days; $index++) {
            $items[] = date('m-d', $start + ($index * 86400));
        }
        return $items;
    }

    /** @return array{0: list<string>, 1: list<array{date: string, count: int, amount: int}>} */
    private function dailyOrderBuckets(int $days): array
    {
        $days = max($days, 1);
        $category = $this->lastDays($days);
        $start = strtotime('today') - (($days - 1) * 86400);
        $buckets = [];
        foreach ($category as $date) {
            $buckets[$date] = ['date' => $date, 'count' => 0, 'amount' => 0];
        }

        $query = Database::connection()->prepare('SELECT created_at, trade_amount, status FROM `order` WHERE created_at >= :start ORDER BY created_at ASC');
        $query->execute([':start' => $start]);
        foreach ($query->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = date('m-d', (int) ($row['created_at'] ?? 0));
            if (!isset($buckets[$date])) {
                continue;
            }
            $buckets[$date]['count']++;
            if ((int) ($row['status'] ?? 0) === 2) {
                $buckets[$date]['amount'] += (int) ($row['trade_amount'] ?? 0);
            }
        }

        return [$category, array_values($buckets)];
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

    /** @param array<string, mixed> $params */
    private function requiredId(array $params): int
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('记录 ID 无效', 422, 422);
        }
        return $id;
    }

    private function statusValue(mixed $value): int
    {
        $status = filter_var($value, FILTER_VALIDATE_INT);
        if ($status === false || !in_array((int) $status, [0, 1], true)) {
            Response::error('状态值只能为 0 或 1', 422, 422);
        }
        return (int) $status;
    }

    private function switchStatus(string $table, array $params, string $message): never
    {
        if (!in_array($table, ['pay_type', 'pay_channel', 'pay_account'], true)) {
            throw new \InvalidArgumentException('Unsupported status table');
        }
        $id = $this->requiredId($params);
        $update = Database::connection()->prepare('UPDATE `' . $table . '` SET status = :status WHERE id = :id');
        $update->execute([':status' => $this->statusValue($params['status'] ?? null), ':id' => $id]);
        $this->ensureAffected($update->rowCount(), '记录不存在');
        Response::success(null, $message);
    }

    /** @return array<string, mixed> */
    private function findById(string $table, int $id): array
    {
        if (!in_array($table, ['pay_type', 'pay_channel', 'pay_account'], true)) {
            throw new \InvalidArgumentException('Unsupported lookup table');
        }
        $query = Database::connection()->prepare('SELECT * FROM `' . $table . '` WHERE id = :id LIMIT 1');
        $query->execute([':id' => $id]);
        $row = $query->fetch();
        if (!is_array($row)) {
            Response::error('記錄不存在', 404, 404);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function findOrder(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $orderId = trim((string) ($params['order_id'] ?? ''));
        if ($id <= 0 && $orderId === '') {
            Response::error('订单号不能为空', 422, 422);
        }
        $query = Database::connection()->prepare('SELECT * FROM `order` WHERE id = :id OR order_id = :order_id LIMIT 1');
        $query->execute([':id' => $id, ':order_id' => $orderId]);
        $order = $query->fetch();
        if (!is_array($order)) {
            Response::error('订单不存在', 404, 404);
        }
        return $order;
    }

    private function updateOrderStatus(Request $request, int $status, string $message): never
    {
        $order = $this->findOrder($request->all());
        $now = time();
        $payTime = $status === 2 ? $now : ($order['pay_time'] ?? null);
        $update = Database::connection()->prepare('UPDATE `order` SET status = :status, pay_time = :pay_time, updated_at = :updated_at WHERE id = :id');
        $update->execute([':status' => $status, ':pay_time' => $payTime, ':updated_at' => $now, ':id' => (int) $order['id']]);
        Response::success(['order_id' => (string) $order['order_id'], 'status' => $status], $message);
    }

    private function ensureAffected(int $count, string $message): void
    {
        if ($count === 0) {
            Response::error($message, 404, 404);
        }
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function channelParams(array $params, string $code, string $name): array
    {
        $options = $params['options'] ?? [];
        return [
            ':code' => $code,
            ':name' => $name,
            ':type' => trim((string) ($params['type'] ?? '')),
            ':status' => $this->statusValue($params['status'] ?? 1),
            ':plugin_name' => trim((string) ($params['plugin_name'] ?? '')),
            ':remark' => trim((string) ($params['remark'] ?? '')),
            ':options' => is_string($options) ? $options : json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @param array<string, mixed> $params */
    private function validateAccount(array $params): void
    {
        if (trim((string) ($params['pay_type'] ?? '')) === '' || trim((string) ($params['channel_code'] ?? $params['code'] ?? '')) === '') {
            Response::error('支付方式和通道不能为空', 422, 422);
        }
        $type = trim((string) $params['pay_type']);
        $typeQuery = Database::connection()->prepare('SELECT COUNT(*) FROM `pay_type` WHERE value = :value');
        $typeQuery->execute([':value' => $type]);
        if ((int) $typeQuery->fetchColumn() === 0) {
            Response::error('支付方式不存在', 422, 422);
        }
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function accountParams(array $params): array
    {
        $options = $params['options'] ?? [];
        $bindPayType = $params['bind_pay_type'] ?? [];
        return [
            ':uid' => max((int) ($params['uid'] ?? 0), 0),
            ':pay_type' => trim((string) ($params['pay_type'] ?? '')),
            ':channel_code' => trim((string) ($params['channel_code'] ?? $params['code'] ?? '')),
            ':account' => trim((string) ($params['account'] ?? '')),
            ':account_type' => trim((string) ($params['account_type'] ?? '')),
            ':qrcode_data' => (string) ($params['qrcode_data'] ?? ''),
            ':qrcode' => trim((string) ($params['qrcode'] ?? '')),
            ':uri' => trim((string) ($params['uri'] ?? '')),
            ':scheme' => trim((string) ($params['scheme'] ?? '')),
            ':status' => $this->statusValue($params['status'] ?? 1),
            ':name' => trim((string) ($params['name'] ?? '')),
            ':sub_account' => trim((string) ($params['sub_account'] ?? '')),
            ':bind_client_name' => trim((string) ($params['bind_client_name'] ?? '')),
            ':sort' => (int) ($params['sort'] ?? 50),
            ':remark' => trim((string) ($params['remark'] ?? '')),
            ':min_amount' => max((int) ($params['min_amount'] ?? 0), 0),
            ':max_amount' => max((int) ($params['max_amount'] ?? 0), 0),
            ':day_amount_limit' => max((int) ($params['day_amount_limit'] ?? 0), 0),
            ':code' => trim((string) ($params['code'] ?? $params['channel_code'] ?? '')),
            ':options' => is_string($options) ? $options : json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':bind_pay_type' => is_string($bindPayType) ? $bindPayType : json_encode($bindPayType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function validateOptionGroup(string $group): void
    {
        if (!in_array($group, ['base', 'login', 'security', 'marketing', 'msg', 'notice', 'pay', 'captcha', 'proxy', 'policy', 'page-diy'], true)) {
            Response::error('不支持的配置分组', 404, 404);
        }
    }

    /** @return array<string, mixed> */
    private function readOptionGroup(string $group): array
    {
        $raw = $this->optionString('option.' . $group, '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $prefix = $group . '_';
        $query = Database::connection()->query('SELECT `key`, value FROM `options` ORDER BY `key`');
        $result = [];
        foreach ($query->fetchAll() as $row) {
            $key = (string) ($row['key'] ?? '');
            if (str_starts_with($key, $prefix)) {
                $result[substr($key, strlen($prefix))] = $this->decodeOption($row['value'] ?? '');
            }
        }
        return $result;
    }

    private function decodeJsonField(mixed $value, array $default): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /** @param list<array<string, mixed>> $rows @return array{list: list<array<string, mixed>>, count: int, total: int, page: int, limit: int} */
    private function paginate(array $rows, array $params): array
    {
        $page = max((int) ($params['page'] ?? 1), 1);
        $limit = min(max((int) ($params['limit'] ?? 20), 1), 100);
        return [
            'list' => array_slice($rows, ($page - 1) * $limit, $limit),
            'count' => count($rows),
            'total' => count($rows),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array<string, mixed> */
    private function authenticatedStaff(Request $request): array
    {
        $token = trim((string) ($request->header('Authorization') ?? ''));
        if ($token === '') {
            Response::json(['code' => 404, 'message' => '未登录', 'data' => [], 'redirect' => '']);
        }

        try {
            $query = Database::connection()->prepare('SELECT * FROM `staff` WHERE token = :token AND status = 1 LIMIT 1');
            $query->execute([':token' => $token]);
            $staff = $query->fetch();
        } catch (PDOException $exception) {
            Response::json(['code' => 503, 'message' => '数据库不可用', 'data' => [], 'redirect' => ''], 503);
        }

        if (!is_array($staff)) {
            Response::json(['code' => 401, 'message' => '登录已失效', 'data' => [], 'redirect' => '']);
        }
        return $staff;
    }

    private function passwordMatches(string $password, string $stored): bool
    {
        // The live staff.password column is a 32-character digest.
        return preg_match('/^[a-f0-9]{32}$/i', $stored) === 1 && hash_equals(strtolower($stored), md5($password));
    }

    private function token(): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $token = '';
        for ($index = 0; $index < 21; $index++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $token;
    }

    private function decodeOption(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function optionString(string $key, string $default): string
    {
        try {
            $query = Database::connection()->prepare('SELECT value FROM `options` WHERE `key` = :key LIMIT 1');
            $query->execute([':key' => $key]);
            $value = $query->fetchColumn();
        } catch (PDOException $exception) {
            Response::json(['code' => 503, 'message' => '数据库不可用', 'data' => [], 'redirect' => ''], 503);
        }

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @param list<string> $default @return list<string> */
    private function optionJsonList(string $key, array $default): array
    {
        $value = $this->optionString($key, '');
        if ($value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return $default;
        }
        $items = array_values(array_filter($decoded, 'is_string'));
        return $items !== [] ? $items : $default;
    }

    private function saveOption(string $key, string $value): void
    {
        try {
            $db = Database::connection();
            $query = $db->prepare('SELECT COUNT(*) FROM `options` WHERE `key` = :key');
            $query->execute([':key' => $key]);
            if ((int) $query->fetchColumn() > 0) {
                $update = $db->prepare('UPDATE `options` SET value = :value WHERE `key` = :key');
                $update->execute([':key' => $key, ':value' => $value]);
                return;
            }

            $insert = $db->prepare('INSERT INTO `options` (`key`, value) VALUES (:key, :value)');
            $insert->execute([':key' => $key, ':value' => $value]);
        } catch (PDOException $exception) {
            Response::json(['code' => 503, 'message' => '数据库不可用', 'data' => [], 'redirect' => ''], 503);
        }
    }
}
