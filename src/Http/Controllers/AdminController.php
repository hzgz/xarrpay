<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use PDOException;
use XArrPay\Http\Request;
use XArrPay\Support\Captcha;
use XArrPay\Support\Database;
use XArrPay\Support\MerchantIdentity;
use XArrPay\Support\OrderNotifier;
use XArrPay\Support\PaymentCodeStore;
use XArrPay\Support\PaymentPluginCatalog;
use XArrPay\Support\PaymentPluginRegistry;
use XArrPay\Support\PluginConfigStore;
use XArrPay\Support\PluginRuntime;
use XArrPay\Support\Response;
use XArrPay\Support\SecurityTicket;
use XArrPay\Support\Totp;

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
        $config['web_title'] ??= '';
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

    public function stepUpBegin(Request $request): never
    {
        $staff = $this->authenticatedStaff($request);
        $operation = trim((string) $request->input('operation', ''));
        if ($operation === '') {
            Response::error('安全操作名称不能为空', 422, 422);
        }

        if (trim((string) ($staff['mfa_secret'] ?? '')) === '') {
            Response::success([
                'need_step_up' => false,
                'ticket' => '',
                'allow_types' => [],
                'captcha_types' => [],
                'factor_targets' => [
                    'totp' => '管理员动态口令',
                    'password' => '当前管理员密码',
                ],
                'expire_time' => 0,
                'options' => ['operation' => $operation],
            ]);
        }

        $db = Database::connection();
        $ticket = SecurityTicket::create(
            $db,
            SecurityTicket::KIND_STEP_UP,
            (int) $staff['id'],
            $operation,
            300
        );
        $hasTotp = trim((string) ($staff['mfa_secret'] ?? '')) !== '';
        $allow = $hasTotp ? ['totp'] : ['password'];

        Response::success([
            'need_step_up' => true,
            'ticket' => $ticket['ticket'],
            'allow_types' => $allow,
            'captcha_types' => $allow,
            'factor_targets' => [
                'totp' => '管理员动态口令',
                'password' => '当前管理员密码',
            ],
            'expire_time' => $ticket['expire_time'],
            'options' => ['operation' => $operation],
        ]);
    }

    public function stepUpFinish(Request $request): never
    {
        $staff = $this->authenticatedStaff($request);
        $params = $request->all();
        $ticket = trim((string) ($params['ticket'] ?? ''));
        $type = trim((string) ($params['type'] ?? ''));
        if ($ticket === '' || $type === '') {
            Response::error('安全票据和验证类型不能为空', 422, 422);
        }

        $db = Database::connection();
        try {
            $row = SecurityTicket::requirePending(
                $db,
                $ticket,
                SecurityTicket::KIND_STEP_UP,
                (int) $staff['id']
            );
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 401, 401);
        }

        $passed = false;
        if ($type === 'totp') {
            $passed = trim((string) ($staff['mfa_secret'] ?? '')) !== ''
                && Totp::verify(
                    (string) $staff['mfa_secret'],
                    trim((string) ($params['totp_code'] ?? $params['captcha_code'] ?? ''))
                );
        } elseif ($type === 'password') {
            $passed = $this->passwordMatches(
                (string) ($params['password'] ?? ''),
                (string) ($staff['password'] ?? '')
            );
        } else {
            Response::error('管理员二次认证仅支持动态口令或当前密码验证', 422, 422);
        }

        if (!$passed) {
            SecurityTicket::incrementFailure($db, $row);
            Response::error($type === 'password' ? '当前管理员密码错误' : '管理员动态口令错误', 401, 401);
        }

        SecurityTicket::mark($db, (int) $row['id'], SecurityTicket::STATUS_VERIFIED);
        Response::success(['ticket' => $ticket], '管理员二次认证成功');
    }

    public function stepUpSendCode(Request $request): never
    {
        $this->authenticatedStaff($request);
        Response::error('本地版未配置管理员邮件或短信验证码服务', 501, 501);
    }

    public function isStaffToken(Request $request): bool
    {
        $token = trim((string) ($request->header('Authorization') ?? ''));
        if ($token === '') {
            return false;
        }

        try {
            $query = Database::connection()->prepare(
                'SELECT id FROM `staff` WHERE token = :token AND status = 1 LIMIT 1'
            );
            $query->execute([':token' => $token]);
            return $query->fetchColumn() !== false;
        } catch (PDOException $exception) {
            Response::json(['code' => 503, 'message' => '数据库不可用', 'data' => [], 'redirect' => ''], 503);
        }
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

    public function users(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $query = Database::connection()->query(
            'SELECT id, username, merchant_name, status, balance, email, phone, avatar, app_secret, '
            . 'rebate_balance, recommend_uid, mfa_enabled, created_at, last_login_ip, last_login_time, payed, audit, settings '
            . 'FROM `user` ORDER BY id DESC'
        );
        $rows = array_map(function (array $row): array {
            $settings = json_decode((string) ($row['settings'] ?? '{}'), true);
            $settings = is_array($settings) ? $settings : [];
            return $row + [
                'recommend_code' => (string) ($settings['recommend_code'] ?? ''),
                'meal_name' => (string) ($settings['meal_name'] ?? ''),
                'expire_time' => (int) ($settings['expire_time'] ?? -1),
                'income_balance' => (int) ($settings['income_balance'] ?? 0),
                'verification_status' => (int) ($settings['verification_status'] ?? 2),
                'verification_name' => (string) ($settings['verification_name'] ?? ''),
                'verification_code' => (string) ($settings['verification_code'] ?? ''),
                'verification_time' => (int) ($settings['verification_time'] ?? 0),
                'auth_2fa' => (int) ($row['mfa_enabled'] ?? 0),
                'reg_ip' => (string) ($settings['reg_ip'] ?? ''),
                'last_login_ip' => (string) ($row['last_login_ip'] ?? ''),
                'last_login_time' => (int) ($row['last_login_time'] ?? 0),
            ];
        }, $query->fetchAll());
        $rows = array_values(array_filter($rows, function (array $row) use ($params): bool {
            if (isset($params['status']) && $params['status'] !== '' && (int) $params['status'] !== 0 && (int) $row['status'] !== (int) $params['status']) {
                return false;
            }
            $needle = trim((string) ($params['query'] ?? ''));
            if (($params['id'] ?? '') !== '' && (int) ($row['id'] ?? 0) !== (int) $params['id']) {
                return false;
            }
            return $needle === '' || stripos(
                implode(' ', array_map(static fn (mixed $value): string => (string) $value, $row)),
                $needle
            ) !== false;
        }));
        Response::success($this->paginate($rows, $params));
    }

    public function userLoginTicket(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $id = $this->requiredId(['id' => $params['uid'] ?? $params['user_id'] ?? $params['id'] ?? 0]);
        $db = Database::connection();
        $user = $db->prepare('SELECT id, status FROM `user` WHERE id = :id LIMIT 1');
        $user->execute([':id' => $id]);
        $row = $user->fetch();
        if (!is_array($row)) {
            Response::error('商户不存在', 404, 404);
        }
        if ((int) ($row['status'] ?? 0) !== 1) {
            Response::error('商户已停用，不能发起免密登录', 409, 409);
        }

        $now = time();
        $db->prepare(
            'UPDATE app_login_ticket SET status=5,updated_at=:updated_at '
            . 'WHERE kind="admin_login" AND uid=:uid AND status IN (1,2) AND expires_at>=:now'
        )->execute([':updated_at' => $now, ':uid' => $id, ':now' => $now]);

        $ticket = bin2hex(random_bytes(32));
        $expiresAt = $now + 180;
        $insert = $db->prepare(
            'INSERT INTO app_login_ticket '
            . '(ticket,kind,uid,status,options,expires_at,created_at,updated_at) '
            . 'VALUES (:ticket,"admin_login",:uid,2,"{}",:expires_at,:created_at,:updated_at)'
        );
        $insert->execute([
            ':ticket' => $ticket,
            ':uid' => $id,
            ':expires_at' => $expiresAt,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $site = rtrim((string) (getenv('XARR_SITE_URL') ?: ''), '/');
        $loginUrl = ($site !== '' ? $site : '') . '/login?ticket=' . rawurlencode($ticket);
        Response::success([
            'ticket' => $ticket,
            'expire_time' => $expiresAt,
            'login_url' => $loginUrl,
        ], '免密登录链接已生成');
    }

    public function userLoginLogs(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $rows = Database::connection()->query(
            'SELECT l.*, l.status AS type, COALESCE(l.login_type, 1) AS login_type, u.merchant_name '
            . 'FROM login_log l LEFT JOIN `user` u ON u.id = l.uid ORDER BY l.id DESC'
        )->fetchAll();
        $needle = trim((string) ($params['query'] ?? $params['keyword'] ?? ''));
        if ($needle !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => stripos(implode(' ', array_map(static fn (mixed $value): string => (string) $value, $row)), $needle) !== false));
        }
        if (($params['uid'] ?? '') !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['uid'] ?? 0) === (int) $params['uid']));
        }
        Response::success($this->paginate($rows, $params));
    }

    public function switchUserPayed(Request $request): never
    {
        $this->switchUserFlag($request, 'payed', '商户收款状态已更新');
    }

    public function switchUserAudit(Request $request): never
    {
        $this->switchUserFlag($request, 'audit', '商户审核状态已更新');
    }

    public function setUserRebateBalance(Request $request): never
    {
        $staff = $this->authenticatedStaff($request);
        $params = $request->all();
        $id = $this->requiredId(['id' => $params['uid'] ?? $params['user_id'] ?? $params['id'] ?? 0]);
        $balance = (int) ($params['rebate_balance'] ?? $params['balance'] ?? 0);
        if ($balance < 0) {
            Response::error('返佣余额不能小于 0', 422, 422);
        }
        $this->consumeStepUpTicket($request, (int) $staff['id'], 'user.rebate_balance.set');
        $update = Database::connection()->prepare('UPDATE `user` SET rebate_balance = :rebate_balance WHERE id = :id');
        $update->execute([':rebate_balance' => $balance, ':id' => $id]);
        $this->ensureAffected($update->rowCount(), '商户不存在');
        Response::success(['uid' => $id, 'rebate_balance' => $balance], '返佣余额已更新');
    }

    private function switchUserFlag(Request $request, string $field, string $message): never
    {
        $this->authenticatedStaff($request);
        if (!in_array($field, ['payed', 'audit'], true)) {
            Response::error('不支持的商户状态字段', 500, 500);
        }
        $params = $request->all();
        $id = $this->requiredId(['id' => $params['uid'] ?? $params['user_id'] ?? $params['id'] ?? 0]);
        $value = $this->statusValue($params['status'] ?? $params['value'] ?? null);
        $update = Database::connection()->prepare('UPDATE `user` SET `' . $field . '` = :value WHERE id = :id');
        $update->execute([':value' => $value, ':id' => $id]);
        $this->ensureAffected($update->rowCount(), '商户不存在');
        Response::success(['id' => $id, $field => $value], $message);
    }

    public function createUser(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $username = trim((string) ($params['username'] ?? ''));
        $password = (string) ($params['password'] ?? '');
        $merchantName = trim((string) ($params['merchant_name'] ?? $params['name'] ?? $username));
        if ($username === '' || $password === '' || $merchantName === '') {
            Response::error('商户账号、密码和名称不能为空', 422, 422);
        }
        $secret = trim((string) ($params['app_secret'] ?? ''));
        if ($secret === '') {
            $secret = MerchantIdentity::appSecret();
        } elseif (preg_match('/^[a-f0-9]{32}$/i', $secret) !== 1) {
            Response::error('商户密钥必须为 32 位十六进制字符', 422, 422);
        }
        $db = Database::connection();
        try {
            $db->beginTransaction();
            $check = $db->prepare('SELECT COUNT(*) FROM `user` WHERE username = :username');
            $check->execute([':username' => $username]);
            if ((int) $check->fetchColumn() > 0) {
                $db->rollBack();
                Response::error('商户账号已存在', 409, 409);
            }
            $id = MerchantIdentity::nextId($db);
            $secret = strtolower($secret);
            $insert = $db->prepare('INSERT INTO `user` (id, username, password, merchant_name, app_secret, status, balance, token, created_at) VALUES (:id, :username, :password, :merchant_name, :app_secret, :status, :balance, "", :created_at)');
            $insert->execute([
                ':id' => $id,
                ':username' => $username,
                ':password' => md5($password),
                ':merchant_name' => $merchantName,
                ':app_secret' => $secret,
                ':status' => $this->statusValue($params['status'] ?? 1),
                ':balance' => max((int) ($params['balance'] ?? 0), 0),
                ':created_at' => time(),
            ]);
            $db->commit();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('商户创建失败：' . $exception->getMessage(), 500, 500);
        }
        Response::success(['id' => $id, 'app_secret' => $secret], '商户创建成功');
    }

    public function editUser(Request $request): never
    {
        $staff = $this->authenticatedStaff($request);
        $params = $request->all();
        $id = $this->requiredId($params);
        $merchantName = trim((string) ($params['merchant_name'] ?? $params['name'] ?? ''));
        $username = trim((string) ($params['username'] ?? ''));
        if ($username === '' || $merchantName === '') {
            Response::error('商户账号和名称不能为空', 422, 422);
        }
        $db = Database::connection();
        $exists = $db->prepare('SELECT id FROM `user` WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $id]);
        if ($exists->fetchColumn() === false) {
            Response::error('商户不存在', 404, 404);
        }
        $check = $db->prepare('SELECT COUNT(*) FROM `user` WHERE username = :username AND id <> :id');
        $check->execute([':username' => $username, ':id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            Response::error('商户账号已存在', 409, 409);
        }
        $this->consumeStepUpTicket($request, (int) $staff['id'], 'user.edit');
        $password = (string) ($params['password'] ?? '');
        if ($password !== '') {
            $update = $db->prepare('UPDATE `user` SET username = :username, merchant_name = :merchant_name, password = :password, status = :status WHERE id = :id');
            $update->execute([':username' => $username, ':merchant_name' => $merchantName, ':password' => md5($password), ':status' => $this->statusValue($params['status'] ?? 1), ':id' => $id]);
        } else {
            $update = $db->prepare('UPDATE `user` SET username = :username, merchant_name = :merchant_name, status = :status WHERE id = :id');
            $update->execute([':username' => $username, ':merchant_name' => $merchantName, ':status' => $this->statusValue($params['status'] ?? 1), ':id' => $id]);
        }
        $this->ensureAffected($update->rowCount(), '商户不存在');
        Response::success(null, '商户保存成功');
    }

    public function removeUser(Request $request): never
    {
        $staff = $this->authenticatedStaff($request);
        $id = $this->requiredId($request->all());
        $db = Database::connection();
        $orders = $db->prepare('SELECT COUNT(*) FROM `order` WHERE uid = :uid');
        $orders->execute([':uid' => $id]);
        if ((int) $orders->fetchColumn() > 0) {
            Response::error('该商户存在订单，不能删除', 409, 409);
        }
        $this->consumeStepUpTicket($request, (int) $staff['id'], 'user.remove');
        $delete = $db->prepare('DELETE FROM `user` WHERE id = :id');
        $delete->execute([':id' => $id]);
        $this->ensureAffected($delete->rowCount(), '商户不存在');
        Response::success(null, '商户删除成功');
    }

    public function switchUserStatus(Request $request): never
    {
        $this->authenticatedStaff($request);
        $id = $this->requiredId($request->all());
        $exists = Database::connection()->prepare('SELECT id FROM `user` WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $id]);
        if ($exists->fetchColumn() === false) {
            Response::error('商户不存在', 404, 404);
        }
        $update = Database::connection()->prepare('UPDATE `user` SET status = :status WHERE id = :id');
        $update->execute([':status' => $this->statusValue($request->input('status')), ':id' => $id]);
        $this->ensureAffected($update->rowCount(), '商户不存在');
        Response::success(null, '商户状态更新成功');
    }

    public function setUserBalance(Request $request): never
    {
        $staff = $this->authenticatedStaff($request);
        $params = $request->all();
        $uid = $this->requiredId(['id' => $params['uid'] ?? $params['user_id'] ?? $params['id'] ?? 0]);
        $this->consumeStepUpTicket($request, (int) $staff['id'], 'user.balance.set');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT balance FROM `user` WHERE id = :id LIMIT 1' . (Database::driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute([':id' => $uid]);
            $before = $query->fetchColumn();
            if ($before === false) {
                $db->rollBack();
                Response::error('商户不存在', 404, 404);
            }
            $balance = array_key_exists('balance', $params) ? (int) $params['balance'] : (int) $before + (int) ($params['amount'] ?? $params['change'] ?? 0);
            if ($balance < 0) {
                $db->rollBack();
                Response::error('余额不能小于 0', 422, 422);
            }
            $update = $db->prepare('UPDATE `user` SET balance = :balance WHERE id = :id');
            $update->execute([':balance' => $balance, ':id' => $uid]);
            $log = $db->prepare('INSERT INTO balance_log (uid, type, amount, before_balance, after_balance, remark, created_at) VALUES (:uid, "admin_adjust", :amount, :before_balance, :after_balance, :remark, :created_at)');
            $log->execute([':uid' => $uid, ':amount' => $balance - (int) $before, ':before_balance' => (int) $before, ':after_balance' => $balance, ':remark' => trim((string) ($params['remark'] ?? '后台调整')), ':created_at' => time()]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        Response::success(['uid' => $uid, 'balance' => $balance], '商户余额已更新');
    }

    public function notices(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM notice ORDER BY sort ASC, id DESC')->fetchAll();
        $rows = array_values(array_filter($rows, function (array $row) use ($params): bool {
            if (isset($params['status']) && $params['status'] !== '' && (int) $params['status'] !== 0 && (int) $row['status'] !== (int) $params['status']) {
                return false;
            }
            $needle = trim((string) ($params['query'] ?? ''));
            return $needle === '' || stripos((string) $row['title'] . ' ' . (string) $row['content'], $needle) !== false;
        }));
        Response::success($this->paginate($rows, $params));
    }

    public function createNotice(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $title = trim((string) ($params['title'] ?? ''));
        if ($title === '') {
            Response::error('公告标题不能为空', 422, 422);
        }
        $now = time();
        $insert = Database::connection()->prepare('INSERT INTO notice (title, content, position, status, sort, created_at, updated_at) VALUES (:title, :content, :position, :status, :sort, :created_at, :updated_at)');
        $insert->execute([':title' => $title, ':content' => (string) ($params['content'] ?? ''), ':position' => (int) ($params['position'] ?? 0), ':status' => $this->statusValue($params['status'] ?? 1), ':sort' => (int) ($params['sort'] ?? 50), ':created_at' => $now, ':updated_at' => $now]);
        Response::success(['id' => (int) Database::connection()->lastInsertId()], '公告创建成功');
    }

    public function editNotice(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $id = $this->requiredId($params);
        $title = trim((string) ($params['title'] ?? ''));
        if ($title === '') {
            Response::error('公告标题不能为空', 422, 422);
        }
        $exists = Database::connection()->prepare('SELECT id FROM notice WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $id]);
        if ($exists->fetchColumn() === false) {
            Response::error('公告不存在', 404, 404);
        }
        $update = Database::connection()->prepare('UPDATE notice SET title = :title, content = :content, position = :position, status = :status, sort = :sort, updated_at = :updated_at WHERE id = :id');
        $update->execute([':title' => $title, ':content' => (string) ($params['content'] ?? ''), ':position' => (int) ($params['position'] ?? 0), ':status' => $this->statusValue($params['status'] ?? 1), ':sort' => (int) ($params['sort'] ?? 50), ':updated_at' => time(), ':id' => $id]);
        $this->ensureAffected($update->rowCount(), '公告不存在');
        Response::success(null, '公告保存成功');
    }

    public function removeNotice(Request $request): never
    {
        $this->authenticatedStaff($request);
        $delete = Database::connection()->prepare('DELETE FROM notice WHERE id = :id');
        $delete->execute([':id' => $this->requiredId($request->all())]);
        $this->ensureAffected($delete->rowCount(), '公告不存在');
        Response::success(null, '公告删除成功');
    }

    public function switchNoticeStatus(Request $request): never
    {
        $this->authenticatedStaff($request);
        $id = $this->requiredId($request->all());
        $exists = Database::connection()->prepare('SELECT id FROM notice WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $id]);
        if ($exists->fetchColumn() === false) {
            Response::error('公告不存在', 404, 404);
        }
        $update = Database::connection()->prepare('UPDATE notice SET status = :status, updated_at = :updated_at WHERE id = :id');
        $update->execute([':status' => $this->statusValue($request->input('status')), ':updated_at' => time(), ':id' => $id]);
        $this->ensureAffected($update->rowCount(), '公告不存在');
        Response::success(null, '公告状态更新成功');
    }

    public function balanceLogs(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $query = Database::connection()->prepare('SELECT b.*, u.username, u.merchant_name FROM balance_log b LEFT JOIN `user` u ON u.id = b.uid ORDER BY b.id DESC');
        $query->execute();
        $types = [
            'admin_adjust' => '后台调整',
            'withdraw' => '提现申请',
            'withdraw_refund' => '提现退款',
            'recharge' => '充值',
            'order_income' => '订单收入',
        ];
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid = (int) ($row['uid'] ?? 0);
            $type = (string) ($row['type'] ?? 'adjust');
            $needle = trim((string) ($params['query'] ?? ''));
            if (($params['uid'] ?? '') !== '' && $uid !== (int) $params['uid']) {
                continue;
            }
            if (trim((string) ($params['type'] ?? '')) !== '' && $type !== trim((string) $params['type'])) {
                continue;
            }
            if (trim((string) ($params['balance_source'] ?? '')) !== '' && (string) $params['balance_source'] !== '1') {
                continue;
            }
            if ($needle !== '' && stripos((string) ($row['username'] ?? '') . ' ' . (string) ($row['merchant_name'] ?? '') . ' ' . (string) ($row['remark'] ?? ''), $needle) === false) {
                continue;
            }
            $amount = (int) ($row['amount'] ?? 0);
            $rows[] = $row + [
                'balance_source' => 1,
                'balance_type_name' => $types[$type] ?? $type,
                'before' => (int) ($row['before_balance'] ?? 0),
                'balance' => $amount,
                'after' => (int) ($row['after_balance'] ?? 0),
                'org_id' => $uid,
                'org_name' => (string) ($row['merchant_name'] ?? $row['username'] ?? ''),
            ];
        }
        Response::success($this->paginate($rows, $params) + ['types' => $types]);
    }

    public function withdrawIncomeList(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $query = Database::connection()->prepare('SELECT i.*, u.username, u.merchant_name, a.type AS account_type, a.name AS account_name, a.account AS account_no, a.bank_name, a.qr_code FROM withdraw_income i LEFT JOIN `user` u ON u.id = i.uid LEFT JOIN withdraw_account a ON a.id = i.account_id AND a.uid = i.uid ORDER BY i.id DESC');
        $query->execute();
        $rows = array_values(array_filter($query->fetchAll(), static function (array $row) use ($params): bool {
            if (($params['uid'] ?? '') !== '' && (int) ($row['uid'] ?? 0) !== (int) $params['uid']) {
                return false;
            }
            if (($params['status'] ?? '') !== '' && (int) ($params['status'] ?? 0) !== 0 && (int) ($row['status'] ?? 0) !== (int) $params['status']) {
                return false;
            }
            $orderId = trim((string) ($params['order_id'] ?? ''));
            return $orderId === '' || stripos((string) ($row['order_id'] ?? ''), $orderId) !== false;
        }));
        Response::success($this->paginate($rows, $params));
    }

    public function auditWithdrawIncome(Request $request): never
    {
        $staff = $this->authenticatedStaff($request);
        $params = $request->all();
        $id = $this->requiredId($params);
        $pass = filter_var($params['pass'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($pass === null) {
            Response::error('审核结果无效', 422, 422);
        }
        $remark = trim((string) ($params['remark'] ?? ''));
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM withdraw_income WHERE id = :id LIMIT 1' . (Database::driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute([':id' => $id]);
            $withdraw = $query->fetch();
            if (!is_array($withdraw)) {
                $db->rollBack();
                Response::error('提现申请不存在', 404, 404);
            }
            $status = (int) ($withdraw['status'] ?? 0);
            if ($status !== 1) {
                $db->rollBack();
                if ($status === ($pass ? 2 : 3)) {
                    Response::success(null, '提现申请状态未变化');
                }
                Response::error('当前提现状态不允许审核', 409, 409);
            }
            $now = time();
            $newStatus = $pass ? 2 : 3;
            $update = $db->prepare('UPDATE withdraw_income SET status = :status, audit_admin = :audit_admin, audit_at = :audit_at, audit_remark = :audit_remark, updated_at = :updated_at WHERE id = :id AND status = 1');
            $update->execute([':status' => $newStatus, ':audit_admin' => (string) ($staff['username'] ?? ''), ':audit_at' => $now, ':audit_remark' => $remark, ':updated_at' => $now, ':id' => $id]);
            if (!$pass) {
                $this->refundWithdraw($db, $withdraw, $now, '审核拒绝');
            }
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        Response::success(null, $pass ? '提现审核通过' : '提现申请已驳回');
    }

    public function markWithdrawPaid(Request $request): never
    {
        $this->authenticatedStaff($request);
        $id = $this->requiredId($request->all());
        $remark = trim((string) $request->input('pay_remark', ''));
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT status FROM withdraw_income WHERE id = :id LIMIT 1' . (Database::driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute([':id' => $id]);
            $status = $query->fetchColumn();
            if ($status === false) {
                $db->rollBack();
                Response::error('提现申请不存在', 404, 404);
            }
            if ((int) $status === 4) {
                $db->rollBack();
                Response::success(null, '提现已标记为完成');
            }
            if ((int) $status !== 2) {
                $db->rollBack();
                Response::error('只有待打款提现可以标记完成', 409, 409);
            }
            $update = $db->prepare('UPDATE withdraw_income SET status = 4, pay_remark = :pay_remark, paid_at = :paid_at, updated_at = :updated_at WHERE id = :id AND status = 2');
            $update->execute([':pay_remark' => $remark, ':paid_at' => time(), ':updated_at' => time(), ':id' => $id]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        Response::success(null, '提现已标记为完成');
    }

    public function markWithdrawFailed(Request $request): never
    {
        $this->authenticatedStaff($request);
        $id = $this->requiredId($request->all());
        $remark = trim((string) ($request->input('remark', '')));
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM withdraw_income WHERE id = :id LIMIT 1' . (Database::driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute([':id' => $id]);
            $withdraw = $query->fetch();
            if (!is_array($withdraw)) {
                $db->rollBack();
                Response::error('提现申请不存在', 404, 404);
            }
            $status = (int) ($withdraw['status'] ?? 0);
            if ($status === 3) {
                $db->rollBack();
                Response::success(null, '提现已标记为失败');
            }
            if ($status !== 2) {
                $db->rollBack();
                Response::error('只有待打款提现可以标记失败', 409, 409);
            }
            $now = time();
            $update = $db->prepare('UPDATE withdraw_income SET status = 3, audit_remark = :audit_remark, updated_at = :updated_at WHERE id = :id AND status = 2');
            $update->execute([':audit_remark' => $remark, ':updated_at' => $now, ':id' => $id]);
            $this->refundWithdraw($db, $withdraw, $now, '打款失败');
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        Response::success(null, '提现已标记失败，金额已退回商户余额');
    }

    public function withdrawOption(Request $request): never
    {
        $this->authenticatedStaff($request);
        Response::success($this->withdrawConfig());
    }

    public function saveWithdrawOption(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $enable = (string) ($params['withdraw_income_enable'] ?? '1');
        $feeType = (string) ($params['withdraw_income_fee_type'] ?? '1');
        $feeValue = (int) ($params['withdraw_income_fee_value'] ?? 0);
        $minimum = (int) ($params['withdraw_income_min'] ?? 0);
        if (!in_array($enable, ['1', '2'], true) || !in_array($feeType, ['1', '2'], true) || $feeValue < 0 || $minimum < 0) {
            Response::error('提现配置参数无效', 422, 422);
        }
        $this->saveOption('withdraw_withdraw_income_enable', $enable);
        $this->saveOption('withdraw_withdraw_income_fee_type', $feeType);
        $this->saveOption('withdraw_withdraw_income_fee_value', (string) $feeValue);
        $this->saveOption('withdraw_withdraw_income_min', (string) $minimum);
        Response::success($this->withdrawConfig(), '提现配置保存成功');
    }

    public function notifyLogs(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT n.*, u.username, u.merchant_name FROM notify_log n LEFT JOIN `user` u ON u.id = n.uid ORDER BY n.id DESC')->fetchAll();
        Response::success($this->paginate(array_values(array_filter($rows, function (array $row) use ($params): bool {
            $needle = trim((string) ($params['query'] ?? ''));
            return $needle === '' || stripos((string) $row['order_id'] . ' ' . (string) $row['url'] . ' ' . (string) $row['response'], $needle) !== false;
        })), $params));
    }

    public function notifyQueue(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT q.*, u.username, u.merchant_name FROM notify_queue q LEFT JOIN `user` u ON u.id = q.uid ORDER BY q.id DESC')->fetchAll();
        $status = trim((string) ($params['status'] ?? ''));
        $needle = trim((string) ($params['query'] ?? ''));
        $rows = array_values(array_filter($rows, static function (array $row) use ($status, $needle): bool {
            if ($status !== '' && (string) ($row['status'] ?? '') !== $status) {
                return false;
            }
            return $needle === '' || stripos((string) ($row['order_id'] ?? '') . ' ' . (string) ($row['url'] ?? '') . ' ' . (string) ($row['last_error'] ?? ''), $needle) !== false;
        }));
        Response::success($this->paginate($rows, $params));
    }

    public function retryNotifyQueue(Request $request): never
    {
        $this->authenticatedStaff($request);
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            Response::error('通知任务 ID 无效', 422, 422);
        }
        $result = (new OrderNotifier())->retry(Database::connection(), $id);
        if (($result['queued'] ?? false) !== true) {
            Response::error('任务不存在或当前状态不可重试', 409, 409);
        }
        Response::success($result, '通知任务已重新入队');
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
        PaymentPluginCatalog::syncAllChannels(Database::connection());
        $params = $request->all();
        $rows = $this->filterRows($this->tableRows('pay_channel'), $params, ['status', 'type'], ['query' => ['code', 'name', 'type']]);
        $items = array_map(function (array $row): array {
            $code = (string) ($row['code'] ?? $row['channel_code'] ?? $row['name'] ?? $row['id'] ?? '');
            $name = (string) ($row['name'] ?? $row['title'] ?? $code);
            $type = (string) ($row['type'] ?? $row['pay_type'] ?? '');
            $pluginName = (string) ($row['plugin_name'] ?? '');
            return $row + ['code' => $code, 'name' => $name, 'type' => $type, 'plugin_name' => $pluginName]
                + \XArrPay\Support\PaymentPluginCatalog::channelMeta($pluginName, $code, $type);
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
        $this->findById('pay_type', $id);
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
        $this->findById('pay_channel', $id);
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
        $id = (int) $db->lastInsertId();
        PaymentCodeStore::syncAccount($db, $id, $params);
        Response::success(['id' => $id], '收款账号创建成功');
    }

    public function editChannelAccount(Request $request): never
    {
        $this->authenticatedStaff($request);
        $params = $request->all();
        $id = $this->requiredId($params);
        $this->validateAccount($params);
        $this->findById('pay_account', $id);
        $values = $this->accountParams($params);
        $values[':id'] = $id;
        $db = Database::connection();
        $update = $db->prepare('UPDATE `pay_account` SET uid = :uid, pay_type = :pay_type, channel_code = :channel_code, account = :account, account_type = :account_type, qrcode_data = :qrcode_data, qrcode = :qrcode, uri = :uri, scheme = :scheme, status = :status, name = :name, sub_account = :sub_account, bind_client_name = :bind_client_name, sort = :sort, remark = :remark, min_amount = :min_amount, max_amount = :max_amount, day_amount_limit = :day_amount_limit, code = :code, options = :options, bind_pay_type = :bind_pay_type WHERE id = :id');
        $update->execute($values);
        $this->ensureAffected($update->rowCount(), '收款账号不存在');
        PaymentCodeStore::syncAccount($db, $id, $params);
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
        $rows = $this->hydrateChannelAccountRows($rows);
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

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function hydrateChannelAccountRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $db = Database::connection();
        $payTypes = [];
        foreach ($db->query('SELECT value, label, name FROM pay_type')->fetchAll() as $item) {
            if (is_array($item)) {
                $payTypes[(string) ($item['value'] ?? '')] = (string) ($item['label'] ?? $item['name'] ?? $item['value'] ?? '');
            }
        }
        $channels = [];
        foreach ($db->query('SELECT code, name FROM pay_channel')->fetchAll() as $item) {
            if (is_array($item)) {
                $channels[(string) ($item['code'] ?? '')] = (string) ($item['name'] ?? $item['code'] ?? '');
            }
        }

        $todayStart = strtotime('today');
        $tomorrowStart = $todayStart + 86400;
        $yesterdayStart = $todayStart - 86400;
        $amountQuery = $db->prepare(
            'SELECT '
            . 'COALESCE(SUM(CASE WHEN status = 2 AND created_at >= :today_start AND created_at < :tomorrow_start THEN trade_amount ELSE 0 END), 0) AS day_amount, '
            . 'COALESCE(SUM(CASE WHEN status = 2 AND created_at >= :yesterday_start AND created_at < :today_start THEN trade_amount ELSE 0 END), 0) AS yesterday_amount '
            . 'FROM `order` WHERE account_id = :account_id'
        );

        foreach ($rows as &$row) {
            $amountQuery->execute([
                ':today_start' => $todayStart,
                ':tomorrow_start' => $tomorrowStart,
                ':yesterday_start' => $yesterdayStart,
                ':account_id' => (int) ($row['id'] ?? 0),
            ]);
            $amounts = $amountQuery->fetch();
            $bindPayType = json_decode((string) ($row['bind_pay_type'] ?? '[]'), true);
            if (!is_array($bindPayType)) {
                $bindPayType = [];
            }
            $bindPayType = array_values(array_filter(array_map('strval', $bindPayType), static fn (string $value): bool => $value !== ''));
            if ($bindPayType === [] && (string) ($row['pay_type'] ?? '') !== '') {
                $bindPayType = [(string) $row['pay_type']];
            }
            $row['pay_type_name'] = $payTypes[(string) ($row['pay_type'] ?? '')] ?? (string) ($row['pay_type'] ?? '');
            $row['channel_name'] = $channels[(string) ($row['channel_code'] ?? '')] ?? (string) ($row['channel_code'] ?? '');
            $row['bind_pay_type'] = $bindPayType;
            $row['day_amount'] = (int) ($amounts['day_amount'] ?? 0);
            $row['yesterday_amount'] = (int) ($amounts['yesterday_amount'] ?? 0);
        }
        unset($row);

        return $rows;
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
        if ((int) ($order['status'] ?? 0) !== 2) {
            Response::error('只有已支付订单可以发送异步通知', 409, 409);
        }
        $merchantQuery = Database::connection()->prepare('SELECT * FROM `user` WHERE id = :id AND status = 1 LIMIT 1');
        $merchantQuery->execute([':id' => (int) ($order['uid'] ?? 0)]);
        $merchant = $merchantQuery->fetch();
        if (!is_array($merchant)) {
            Response::error('订单所属商户不存在或已停用', 409, 409);
        }
        $result = (new OrderNotifier())->enqueue(Database::connection(), $order, $merchant);
        Response::success($result, '异步通知已加入队列');
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
        $uid = (int) ($params['uid'] ?? $params['user_id'] ?? 0);
        if ($uid <= 0) {
            Response::error('必须指定有效商户 UID', 422, 422);
        }
        $merchant = Database::connection()->prepare('SELECT id FROM `user` WHERE id = :id AND status = 1 LIMIT 1');
        $merchant->execute([':id' => $uid]);
        if ($merchant->fetchColumn() === false) {
            Response::error('商户不存在或已停用', 422, 422);
        }
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
            ':uid' => $uid,
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
        if (!in_array($action, ['start', 'stop', 'enable', 'disable', 'remove', 'refresh'], true)) {
            Response::error('插件操作不支持', 422, 422);
        }
        $name = trim((string) ($request->input('name') ?? $request->input('plugin_name') ?? ''));
        if ($action !== 'refresh' && $name === '') {
            Response::error('插件名称不能为空', 422, 422);
        }
        if ($action === 'refresh') {
            PaymentPluginRegistry::clearCache();
            Response::success([
                'list' => PaymentPluginRegistry::catalog(),
                'diagnostics' => PluginRuntime::diagnostics(),
            ], '插件清单已刷新');
        }
        try {
            if ($action === 'remove') {
                PluginRuntime::remove($name);
            } else {
                PluginRuntime::setState($name, $action);
            }
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        }
        Response::success([
            'plugin_name' => $name,
            'list' => PaymentPluginRegistry::catalog(),
            'diagnostics' => PluginRuntime::diagnostics(),
        ], '插件操作成功');
    }

    public function pluginSetOptions(Request $request): never
    {
        $this->authenticatedStaff($request);
        $name = trim((string) ($request->input('uuid')
            ?? $request->input('plugin_name')
            ?? $request->input('name')
            ?? ''));
        if ($name === '') {
            Response::error('插件名称不能为空', 422, 422);
        }
        $options = $request->input('options', []);
        try {
            $config = PluginConfigStore::save(Database::connection(), $name, $options);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        }
        Response::success([
            'plugin_name' => $name,
            'config' => $config,
        ], '插件配置保存成功');
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
        $items = PaymentPluginRegistry::catalog();
        Response::success([
            'list' => $items,
            'count' => count($items),
            'diagnostics' => PluginRuntime::diagnostics(),
        ]);
    }

    public function payConf(Request $request): never
    {
        $this->authenticatedStaff($request);
        PaymentPluginCatalog::syncAllChannels(Database::connection());
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
            $item = [
                'id' => (int) ($row['id'] ?? 0),
                'code' => $code,
                'name' => (string) ($row['name'] ?? $code),
                'type' => (string) ($row['type'] ?? ''),
                'status' => (int) ($row['status'] ?? 0),
                'plugin_name' => (string) ($row['plugin_name'] ?? ''),
                'remark' => (string) ($row['remark'] ?? ''),
            ];
            return $item + PaymentPluginCatalog::channelMeta(
                (string) ($row['plugin_name'] ?? ''),
                $code,
                (string) ($row['type'] ?? ''),
            );
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
        $start = strtotime('today') - 6 * 86400;
        $buckets = array_fill_keys($category, 0);
        $query = Database::connection()->prepare('SELECT created_at FROM `user` WHERE created_at >= :start ORDER BY created_at ASC');
        $query->execute([':start' => $start]);
        foreach ($query->fetchAll() as $row) {
            $date = date('m-d', (int) ($row['created_at'] ?? 0));
            if (isset($buckets[$date])) {
                $buckets[$date]++;
            }
        }
        Response::success([
            'category' => $category,
            'items' => [[
                'name' => '新增商户',
                'type' => 'line',
                'smooth' => true,
                'data' => array_values($buckets),
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
        $start = strtotime('today') - 6 * 86400;
        $buckets = array_fill_keys($category, 0);
        $query = Database::connection()->prepare('SELECT created_at, amount FROM recharge WHERE status = 2 AND created_at >= :start ORDER BY created_at ASC');
        $query->execute([':start' => $start]);
        foreach ($query->fetchAll() as $row) {
            $date = date('m-d', (int) ($row['created_at'] ?? 0));
            if (isset($buckets[$date])) {
                $buckets[$date] += (int) ($row['amount'] ?? 0);
            }
        }
        Response::success([
            'category' => $category,
            'items' => [[
                'name' => '充值金额',
                'type' => 'line',
                'smooth' => true,
                'data' => array_map(static fn (int $amount): float => round($amount / 100, 2), array_values($buckets)),
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
        $this->findById($table, $id);
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

    /** @param array<string, mixed> $withdraw */
    private function refundWithdraw(\PDO $db, array $withdraw, int $now, string $reason): void
    {
        $uid = (int) ($withdraw['uid'] ?? 0);
        $amount = (int) ($withdraw['amount'] ?? 0);
        if ($uid <= 0 || $amount <= 0) {
            throw new \RuntimeException('提现记录金额或商户无效');
        }
        $balanceQuery = $db->prepare('SELECT balance FROM `user` WHERE id = :id LIMIT 1' . (Database::driver() === 'mysql' ? ' FOR UPDATE' : ''));
        $balanceQuery->execute([':id' => $uid]);
        $before = $balanceQuery->fetchColumn();
        if ($before === false) {
            throw new \RuntimeException('商户不存在，无法退款');
        }
        $before = (int) $before;
        $after = $before + $amount;
        $update = $db->prepare('UPDATE `user` SET balance = :balance WHERE id = :id');
        $update->execute([':balance' => $after, ':id' => $uid]);
        $log = $db->prepare('INSERT INTO balance_log (uid, type, amount, before_balance, after_balance, remark, created_at) VALUES (:uid, :type, :amount, :before_balance, :after_balance, :remark, :created_at)');
        $log->execute([
            ':uid' => $uid,
            ':type' => 'withdraw_refund',
            ':amount' => $amount,
            ':before_balance' => $before,
            ':after_balance' => $after,
            ':remark' => $reason . '退款 ' . (string) ($withdraw['order_id'] ?? ''),
            ':created_at' => $now,
        ]);
    }

    /** @return array{withdraw_income_enable:string, withdraw_income_fee_type:string, withdraw_income_fee_value:string, withdraw_income_min:string} */
    private function withdrawConfig(): array
    {
        $defaults = [
            'withdraw_income_enable' => '1',
            'withdraw_income_fee_type' => '1',
            'withdraw_income_fee_value' => '0',
            'withdraw_income_min' => '0',
        ];
        $db = Database::connection();
        $query = $db->prepare('SELECT `key`, value FROM options WHERE `key` IN (?, ?, ?, ?)');
        $query->execute([
            'withdraw_withdraw_income_enable',
            'withdraw_withdraw_income_fee_type',
            'withdraw_withdraw_income_fee_value',
            'withdraw_withdraw_income_min',
        ]);
        foreach ($query->fetchAll() as $row) {
            $key = (string) ($row['key'] ?? '');
            $short = substr($key, strlen('withdraw_'));
            if (array_key_exists($short, $defaults)) {
                $defaults[$short] = (string) ($row['value'] ?? $defaults[$short]);
            }
        }
        return $defaults;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function channelParams(array $params, string $code, string $name): array
    {
        $pluginName = trim((string) ($params['plugin_name'] ?? ''));
        if ($pluginName === '') {
            Response::error('支付通道必须选择支付插件', 422, 422);
        }
        $type = trim((string) ($params['type'] ?? ''));
        if ($type === '') {
            Response::error('支付通道必须选择支付方式', 422, 422);
        }
        $typeQuery = Database::connection()->prepare('SELECT status FROM pay_type WHERE value = :value LIMIT 1');
        $typeQuery->execute([':value' => $type]);
        if ((int) $typeQuery->fetchColumn() !== 1) {
            Response::error('支付方式不存在或已停用', 422, 422);
        }
        $plugin = PaymentPluginRegistry::catalogItem($pluginName);
        if ($plugin === []) {
            Response::error('支付插件不存在', 422, 422);
        }
        try {
            PaymentPluginRegistry::resolve($pluginName);
            PaymentPluginRegistry::resolveAccount($pluginName);
            if (!PaymentPluginRegistry::supportsPayType($pluginName, $type)) {
                Response::error('支付插件不支持当前支付方式', 422, 422);
            }
            if (!PaymentPluginCatalog::supportsChannel($pluginName, $code, $type)) {
                Response::error('支付插件不提供当前通道代码', 422, 422);
            }
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        }
        $options = $params['options'] ?? [];
        return [
            ':code' => $code,
            ':name' => $name,
            ':type' => $type,
            ':status' => $this->statusValue($params['status'] ?? 1),
            ':plugin_name' => $pluginName,
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
        $channel = trim((string) ($params['channel_code'] ?? $params['code'] ?? ''));
        $channelQuery = Database::connection()->prepare('SELECT status FROM `pay_channel` WHERE code = :code LIMIT 1');
        $channelQuery->execute([':code' => $channel]);
        $channelStatus = $channelQuery->fetchColumn();
        if ($channelStatus === false) {
            Response::error('支付通道不存在', 422, 422);
        }
        if ((int) $channelStatus !== 1) {
            Response::error('支付通道已停用', 422, 422);
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
        if (!in_array($group, ['base', 'login', 'security', 'marketing', 'msg', 'notice', 'pay', 'captcha', 'proxy', 'proxy-pool', 'policy', 'page-diy'], true)) {
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
                return $this->optionDefaults($group, $decoded);
            }
        }
        $prefix = $group . '_';
        $query = Database::connection()->query('SELECT `key`, value FROM `options` ORDER BY `key`');
        $result = [];
        foreach ($query->fetchAll() as $row) {
            $key = (string) ($row['key'] ?? '');
            if (str_starts_with($key, $prefix)) {
                $result[substr($key, strlen($prefix))] = $this->decodeOption($row['value'] ?? '');
                continue;
            }
            if ($group === 'base' && in_array($key, $this->baseOptionKeys(), true)) {
                $result[$key] = $this->decodeOption($row['value'] ?? '');
            }
        }
        return $this->optionDefaults($group, $result);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function optionDefaults(string $group, array $data): array
    {
        if ($group === 'base') {
            $data = array_replace([
                'web_index_title' => '',
                'web_title' => '',
                'web_favicon' => '',
                'web_logo' => '',
                'web_keywords' => '',
                'web_description' => '',
                'web_copyright' => '',
                'web_service_email' => '',
                'web_service_qq' => '',
                'admin_path' => 'admin',
                'system_plugin_maxtotal' => '50',
                'system_bt_goproject' => '',
                'app_store_github_proxy' => '',
                'system_upload_image_size' => '2048',
                'system_upload_image_suffix' => 'jpg,jpeg,png,gif,webp',
                'user_meal_expire_tip_times' => '',
                'mcp_enabled' => '2',
                'web_api_multi_domain' => '2',
                'web_api_default_domain' => '1',
                'web_api_domain' => '[]',
                'third_account_report_secret' => '',
                'system_api_secret' => '',
                'web_login_background' => '',
                'web_login_desc' => '',
                'user_verification_status' => '2',
                'user_verification_type' => '1',
                'user_verification_price' => '0',
                'user_verification_need_bind_phone' => '2',
                'user_verification_channel' => '',
                'user_verification_alipay_appid' => '',
                'user_verification_alipay_private_key' => '',
                'user_verification_alipay_public_key' => '',
                'user_verification_must' => '2',
            ], $data);
        }
        if ($group === 'pay') {
            $data = array_replace([
                'pay_recharge_pay_type' => '',
                'pay_audio_api_type' => '1',
                'pay_audio_api_url' => '',
                'pay_audio_url_rule' => '',
                'pay_work_mode' => '1',
                'pay_recharge_enable' => '2',
                'pay_recharge_type' => '1',
                'pay_min_pay_amount' => '0',
                'pay_max_pay_amount' => '0',
                'pay_timeout' => '900',
                'pay_payed_wait_time' => '0',
                'pay_official_uid' => '0',
                'shared_channel_feature_enabled' => false,
            ], $data);
        }
        if ($group === 'proxy-pool') {
            $data = array_replace(['enabled' => false, 'timeout' => 10, 'pool_id' => 0], $data);
        }
        return $data;
    }

    /** @return list<string> */
    private function baseOptionKeys(): array
    {
        return [
            'web_index_title',
            'web_title',
            'web_favicon',
            'web_logo',
            'web_keywords',
            'web_description',
            'web_copyright',
            'web_service_email',
            'web_service_qq',
            'admin_path',
            'system_plugin_maxtotal',
            'system_bt_goproject',
            'app_store_github_proxy',
            'system_upload_image_size',
            'system_upload_image_suffix',
            'user_meal_expire_tip_times',
            'mcp_enabled',
            'web_api_multi_domain',
            'web_api_default_domain',
            'web_api_domain',
            'third_account_report_secret',
            'system_api_secret',
            'web_login_background',
            'web_login_desc',
            'user_verification_status',
            'user_verification_type',
            'user_verification_price',
            'user_verification_need_bind_phone',
            'user_verification_channel',
            'user_verification_alipay_appid',
            'user_verification_alipay_private_key',
            'user_verification_alipay_public_key',
            'user_verification_must',
        ];
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

    private function consumeStepUpTicket(Request $request, int $staffId, string $operation): void
    {
        $factor = Database::connection()->prepare(
            'SELECT mfa_secret FROM `staff` WHERE id = :id AND status = 1 LIMIT 1'
        );
        $factor->execute([':id' => $staffId]);
        $staff = $factor->fetch();
        if (!is_array($staff) || trim((string) ($staff['mfa_secret'] ?? '')) === '') {
            return;
        }

        $ticket = trim((string) ($request->header('X-Step-Up-Ticket') ?? $request->input('ticket', '')));
        if ($ticket === '') {
            Response::error('请先完成管理员二次安全验证', 401, 401);
        }

        $db = Database::connection();
        $row = SecurityTicket::find($db, $ticket, SecurityTicket::KIND_STEP_UP, $staffId);
        if (!is_array($row)) {
            Response::error('管理员二次安全验证票据不存在', 401, 401);
        }
        if ((string) ($row['operation'] ?? '') !== $operation) {
            Response::error('管理员二次安全验证票据用途不匹配', 401, 401);
        }
        if ((int) ($row['expires_at'] ?? 0) < time()) {
            SecurityTicket::mark($db, (int) $row['id'], SecurityTicket::STATUS_EXPIRED);
            Response::error('管理员二次安全验证已过期，请重新操作', 401, 401);
        }
        if ((int) ($row['status'] ?? 0) !== SecurityTicket::STATUS_VERIFIED) {
            Response::error('请先完成管理员二次安全验证', 401, 401);
        }
        if (!SecurityTicket::consumeVerified(
            $db,
            $ticket,
            SecurityTicket::KIND_STEP_UP,
            $staffId,
            $operation
        )) {
            Response::error('管理员二次安全验证票据已使用，请重新操作', 401, 401);
        }
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
