<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use PDOException;
use XArrPay\Http\Request;
use XArrPay\Support\Captcha;
use XArrPay\Support\Database;
use XArrPay\Support\MerchantIdentity;
use XArrPay\Support\RechargeCardService;
use XArrPay\Support\RechargeService;
use XArrPay\Support\OrderNotifier;
use XArrPay\Support\PaymentCodeStore;
use XArrPay\Support\PaymentPluginCatalog;
use XArrPay\Support\PaymentPluginRegistry;
use XArrPay\Support\PluginAccountService;
use XArrPay\Support\Response;
use XArrPay\Support\SecurityTicket;
use XArrPay\Support\Totp;
use XArrPay\Support\WebAuthn;
use XArrPay\Support\QrCode;
use XArrPay\Support\QrCodeDecoder;

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
            $this->recordLogin(is_array($merchant) ? (int) $merchant['id'] : 0, $username, 0, '用户名或密码错误');
            Response::json(['code' => 401, 'message' => '用户名或密码错误', 'data' => [], 'redirect' => '']);
        }

        if ((int) ($merchant['mfa_enabled'] ?? 0) === 1 && trim((string) ($merchant['mfa_secret'] ?? '')) !== '') {
            $ticket = SecurityTicket::create(Database::connection(), SecurityTicket::KIND_LOGIN_MFA, (int) $merchant['id'], 'merchant.login', 300);
            Response::success([
                'status' => 2,
                'data' => [
                    'ticket' => $ticket['ticket'],
                    'captcha_types' => ['totp'],
                    'expire_time' => $ticket['expire_time'],
                ],
            ], '请输入动态口令完成登录');
        }

        $token = $this->issueToken((int) $merchant['id']);
        $this->recordLogin((int) $merchant['id'], $username, 1, '登录成功', 1);

        Response::success([
            'status' => 1,
            'token' => $token,
            'data' => ['token' => $token],
        ], '登录成功');
    }

    public function loginMfa(Request $request): never
    {
        $params = $request->all();
        $ticket = trim((string) ($params['ticket'] ?? ''));
        $code = trim((string) ($params['totp_code'] ?? $params['captcha_code'] ?? $params['code'] ?? ''));
        if ($ticket === '' || $code === '') {
            Response::error('请输入动态口令', 422, 422);
        }

        $db = Database::connection();
        try {
            $row = SecurityTicket::requirePending($db, $ticket, SecurityTicket::KIND_LOGIN_MFA);
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 401, 401);
        }

        $query = $db->prepare('SELECT * FROM `user` WHERE id=:id AND status=1 LIMIT 1');
        $query->execute([':id' => (int) $row['uid']]);
        $merchant = $query->fetch();
        if (!is_array($merchant)) {
            SecurityTicket::mark($db, (int) $row['id'], SecurityTicket::STATUS_BLOCKED);
            Response::error('商户不存在或已停用', 401, 401);
        }
        if ((int) ($merchant['mfa_enabled'] ?? 0) !== 1 || trim((string) ($merchant['mfa_secret'] ?? '')) === '') {
            SecurityTicket::mark($db, (int) $row['id'], SecurityTicket::STATUS_BLOCKED);
            Response::error('当前账号未开启动态口令', 401, 401);
        }
        if (!Totp::verify((string) $merchant['mfa_secret'], $code)) {
            SecurityTicket::incrementFailure($db, $row);
            $this->recordLogin((int) $merchant['id'], (string) $merchant['username'], 0, '动态口令错误', 3);
            Response::error('动态口令错误', 401, 401);
        }

        if (!SecurityTicket::consumePending(
            $db,
            $ticket,
            SecurityTicket::KIND_LOGIN_MFA,
            (int) $row['uid']
        )) {
            Response::error('安全票据已使用，请重新登录', 401, 401);
        }
        $token = $this->issueToken((int) $merchant['id']);
        $this->recordLogin((int) $merchant['id'], (string) $merchant['username'], 1, '登录成功', 3);
        Response::success([
            'status' => 1,
            'token' => $token,
            'data' => ['token' => $token],
        ], '登录成功');
    }

    public function loginQrcode(Request $request): never
    {
        if (trim((string) $request->input('channel', '')) !== 'app') {
            Response::error('当前仅支持 APP 扫码登录', 501, 501);
        }
        $db = Database::connection();
        $now = time();
        $ticket = bin2hex(random_bytes(32));
        $expire = $now + 180;
        $insert = $db->prepare(
            'INSERT INTO app_login_ticket (ticket,kind,uid,status,options,expires_at,created_at,updated_at) '
            . 'VALUES (:ticket,"app_login",0,1,"{}",:expires_at,:created_at,:updated_at)'
        );
        $insert->execute([
            ':ticket' => $ticket,
            ':expires_at' => $expire,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $payload = base64_encode((string) json_encode([
            'ticket' => $ticket,
            'expire_time' => $expire,
            'host' => $this->requestSite($request),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Response::success([
            'ticket' => $ticket,
            'expire_time' => $expire,
            'qrcode' => $payload,
        ]);
    }

    public function qrcode(Request $request): never
    {
        $text = (string) $request->input('text', '');
        if ($text === '') {
            Response::error('二维码内容不能为空', 422, 422);
        }
        try {
            $uri = QrCode::svgDataUri($text);
        } catch (\InvalidArgumentException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        }
        $raw = base64_decode((string) substr($uri, strlen('data:image/svg+xml;base64,')), true);
        if ($raw === false) {
            Response::error('二维码生成失败', 500, 500);
        }
        header('Content-Type: image/svg+xml; charset=utf-8');
        echo $raw;
        exit;
    }

    public function decodeQrcode(Request $request): never
    {
        $file = $request->file('file') ?? $request->file('image');
        if (!is_array($file)) {
            Response::error('请上传二维码图片', 422, 422);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => '二维码图片超过服务器允许的大小',
                UPLOAD_ERR_FORM_SIZE => '二维码图片超过表单允许的大小',
                UPLOAD_ERR_PARTIAL => '二维码图片上传不完整',
                UPLOAD_ERR_NO_FILE => '请上传二维码图片',
                UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不可用',
                UPLOAD_ERR_CANT_WRITE => '服务器无法保存二维码图片',
                UPLOAD_ERR_EXTENSION => '服务器扩展中止了二维码图片上传',
            ];
            Response::error($messages[$error] ?? '二维码图片上传失败', 422, 422);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::error('上传文件无效', 422, 422);
        }

        try {
            $text = QrCodeDecoder::decodeFile($tmp);
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        }

        Response::success($text, '解析成功');
    }

    public function passkeyLoginBegin(Request $request): never
    {
        $db = Database::connection();
        $now = time();
        $ticket = bin2hex(random_bytes(32));
        $challenge = WebAuthn::encode(random_bytes(32));
        $rpId = $this->webAuthnRpId($request);
        $origin = $this->webAuthnOrigin($request);
        $credentials = [];
        foreach ($db->query('SELECT credential_id FROM passkey_credential WHERE status=1 ORDER BY id ASC')->fetchAll() as $row) {
            if (is_array($row) && trim((string) ($row['credential_id'] ?? '')) !== '') {
                $credentials[] = ['type' => 'public-key', 'id' => (string) $row['credential_id']];
            }
        }
        $insert = $db->prepare(
            'INSERT INTO webauthn_challenge (ticket,uid,purpose,challenge,rp_id,origin,status,expires_at,created_at,updated_at) '
            . 'VALUES (:ticket,0,"login",:challenge,:rp_id,:origin,1,:expires_at,:created_at,:updated_at)'
        );
        $insert->execute([
            ':ticket' => $ticket,
            ':challenge' => $challenge,
            ':rp_id' => $rpId,
            ':origin' => $origin,
            ':expires_at' => $now + 300,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        Response::success([
            'ticket' => $ticket,
            'options' => [
                'publicKey' => [
                    'challenge' => $challenge,
                    'rpId' => $rpId,
                    'timeout' => 300000,
                    'userVerification' => 'required',
                    'allowCredentials' => $credentials,
                ],
            ],
        ]);
    }

    public function passkeyLoginFinish(Request $request): never
    {
        $ticket = trim((string) $request->input('ticket', ''));
        $credential = $this->credentialPayload($request->input('credential'));
        if ($ticket === '') {
            Response::error('通行密钥认证票据不能为空', 422, 422);
        }
        $db = Database::connection();
        $query = $db->prepare('SELECT * FROM webauthn_challenge WHERE ticket=:ticket AND purpose="login" AND status=1 LIMIT 1');
        $query->execute([':ticket' => $ticket]);
        $challenge = $query->fetch();
        if (!is_array($challenge) || (int) $challenge['expires_at'] < time()) {
            Response::error('通行密钥认证票据不存在或已过期', 401, 401);
        }
        $credentialId = trim((string) ($credential['rawId'] ?? ''));
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        $find = $db->prepare('SELECT * FROM passkey_credential WHERE credential_id=:credential_id AND status=1 LIMIT 1');
        $find->execute([':credential_id' => $credentialId]);
        $stored = $find->fetch();
        if (!is_array($stored)) {
            Response::error('通行密钥不存在或已停用', 401, 401);
        }
        try {
            $result = WebAuthn::assertion(
                $credentialId,
                WebAuthn::decode((string) ($response['authenticatorData'] ?? '')),
                WebAuthn::decode((string) ($response['clientDataJSON'] ?? '')),
                WebAuthn::decode((string) ($response['signature'] ?? '')),
                (string) $challenge['challenge'],
                (string) $challenge['origin'],
                (string) $stored['public_key'],
                (int) $stored['sign_count'],
                (string) $challenge['rp_id'],
            );
        } catch (\Throwable $exception) {
            Response::error($exception->getMessage(), 401, 401);
        }
        if (!$this->consumeWebAuthnChallenge($db, (int) $challenge['id'])) {
            Response::error('通行密钥认证票据已使用，请重新登录', 401, 401);
        }
        $now = time();
        $update = $db->prepare('UPDATE passkey_credential SET sign_count=:sign_count,last_used_at=:last_used_at,updated_at=:updated_at WHERE id=:id');
        $update->execute([
            ':sign_count' => $result['sign_count'],
            ':last_used_at' => $now,
            ':updated_at' => $now,
            ':id' => (int) $stored['id'],
        ]);
        $merchantQuery = $db->prepare('SELECT * FROM user WHERE id=:id AND status=1 LIMIT 1');
        $merchantQuery->execute([':id' => (int) $stored['uid']]);
        $merchant = $merchantQuery->fetch();
        if (!is_array($merchant)) {
            Response::error('商户不存在或已停用', 401, 401);
        }
        if ((int) ($merchant['mfa_enabled'] ?? 0) === 1 && trim((string) ($merchant['mfa_secret'] ?? '')) !== '') {
            $mfaTicket = SecurityTicket::create($db, SecurityTicket::KIND_LOGIN_MFA, (int) $merchant['id'], 'merchant.passkey.login', 300);
            Response::success([
                'status' => 2,
                'data' => [
                    'ticket' => $mfaTicket['ticket'],
                    'captcha_types' => ['totp'],
                    'expire_time' => $mfaTicket['expire_time'],
                ],
            ], '请输入动态口令完成登录');
        }
        $token = $this->issueToken((int) $merchant['id']);
        $this->recordLogin((int) $merchant['id'], (string) $merchant['username'], 1, '通行密钥登录成功', 11);
        Response::success(['status' => 1, 'token' => $token, 'data' => ['token' => $token]], '登录成功');
    }

    public function profile(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $settings = $this->decodeSettings((string) ($merchant['settings'] ?? '{}'));
        $meal = is_array($settings['meal'] ?? null) ? $settings['meal'] : [];
        Response::success([
            'id' => (int) ($merchant['id'] ?? 0),
            'pid' => (int) ($merchant['id'] ?? 0),
            'username' => (string) ($merchant['username'] ?? ''),
            'name' => (string) ($merchant['merchant_name'] ?? $merchant['username'] ?? ''),
            'merchant_name' => (string) ($merchant['merchant_name'] ?? ''),
            'status' => (int) ($merchant['status'] ?? 0),
            'balance' => (int) ($merchant['balance'] ?? 0),
            'email' => (string) ($merchant['email'] ?? ''),
            'phone' => (string) ($merchant['phone'] ?? ''),
            'avatar' => (string) ($merchant['avatar'] ?? ''),
            'last_login_ip' => (string) ($merchant['last_login_ip'] ?? ''),
            'last_login_time' => (int) ($merchant['last_login_time'] ?? 0),
            'mfa_enabled' => (int) ($merchant['mfa_enabled'] ?? 0),
            'mfa_enable' => (int) ($merchant['mfa_enabled'] ?? 0),
            'meal_id' => (int) ($meal['id'] ?? 0),
            'meal_name' => (string) ($meal['name'] ?? ''),
            'expire_time' => (int) ($meal['expires_at'] ?? 0),
            'meal_auto_renewal' => (int) ($meal['auto_renewal'] ?? 0),
            'settings' => $settings,
        ]);
    }

    public function resetPassword(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $old = (string) ($params['old_password'] ?? $params['password'] ?? '');
        $new = (string) ($params['new_password'] ?? $params['new_pwd'] ?? '');
        if (!$this->passwordMatches($old, (string) ($merchant['password'] ?? ''))) {
            Response::error('当前密码错误', 401, 401);
        }
        if (strlen($new) < 6) {
            Response::error('新密码至少需要 6 位', 422, 422);
        }
        $update = Database::connection()->prepare('UPDATE `user` SET password = :password, token = "" WHERE id = :id');
        $update->execute([':password' => md5($new), ':id' => (int) $merchant['id']]);
        Response::success(null, '密码已修改，请重新登录');
    }

    public function resetSecret(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $this->consumeStepUpTicket($request, (int) $merchant['id'], 'secret.reset');
        $secret = MerchantIdentity::appSecret();
        $update = Database::connection()->prepare('UPDATE `user` SET app_secret = :secret WHERE id = :id');
        $update->execute([':secret' => $secret, ':id' => (int) $merchant['id']]);
        Response::success($secret, '商户密钥已重置');
    }

    public function secret(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $this->consumeStepUpTicket($request, (int) $merchant['id'], 'secret.view');
        Response::success((string) ($merchant['app_secret'] ?? ''));
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
        PaymentPluginCatalog::syncAllChannels(Database::connection());
        $params = $request->all();
        $rows = $this->filterRows($this->tableRows('pay_channel'), $params, ['status', 'type'], ['query' => ['code', 'name', 'type']]);
        $items = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? $row['channel_code'] ?? $row['name'] ?? $row['id'] ?? '');
            $name = (string) ($row['name'] ?? $row['title'] ?? $code);
            $type = (string) ($row['type'] ?? $row['pay_type'] ?? '');
            $pluginName = (string) ($row['plugin_name'] ?? '');
            if ($code === '' || $type === '' || $pluginName === ''
                || !PaymentPluginRegistry::usableForPayType($pluginName, $type)) {
                continue;
            }
            $items[] = $row + ['code' => $code, 'name' => $name, 'type' => $type]
                + PaymentPluginCatalog::channelMeta($pluginName, $code, $type);
        }
        Response::success($this->paginate($items, $params));
    }

    public function channelFormItems(Request $request): never
    {
        $this->authenticatedMerchant($request);
        $channelCode = trim((string) ($request->input('pay_channel') ?? $request->input('channel_code') ?? ''));
        $payType = trim((string) ($request->input('pay_type') ?? ''));
        if ($channelCode === '') {
            Response::error('通道代码不能为空', 422, 422);
        }
        if ($payType === '') {
            Response::error('支付方式不能为空', 422, 422);
        }
        PaymentPluginCatalog::syncAllChannels(Database::connection());
        $query = Database::connection()->prepare('SELECT code, plugin_name FROM pay_channel WHERE code = :code AND status = 1 LIMIT 1');
        $query->execute([':code' => $channelCode]);
        $channel = $query->fetch();
        if (!is_array($channel)) {
            Response::error('支付通道不存在或已停用', 404, 404);
        }
        $this->validateUsableChannel($channelCode, $payType);
        try {
            Response::success(PaymentPluginCatalog::formItems((string) ($channel['plugin_name'] ?? ''), $channelCode, $payType));
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 501, 501);
        }
    }

    public function channelGateways(Request $request): never
    {
        $this->authenticatedMerchant($request);
        $channelCode = trim((string) ($request->input('channel_code') ?? $request->input('pay_channel') ?? ''));
        $payType = trim((string) ($request->input('pay_type') ?? ''));
        if ($channelCode === '') {
            Response::error('通道代码不能为空', 422, 422);
        }
        if ($payType === '') {
            Response::error('支付方式不能为空', 422, 422);
        }
        PaymentPluginCatalog::syncAllChannels(Database::connection());
        $this->validateUsableChannel($channelCode, $payType);
        $query = Database::connection()->prepare(
            'SELECT id, name, addr, channel_code, pay_type, options, active_time '
            . 'FROM channel_gateway WHERE status = 1 AND channel_code = :channel_code '
            . 'AND (:pay_type = \'\' OR pay_type = :pay_type) ORDER BY id ASC'
        );
        $query->execute([':channel_code' => $channelCode, ':pay_type' => $payType]);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['options'] = $this->decodeJsonField($row['options'] ?? '{}', []);
            $rows[] = $row;
        }
        Response::success($rows);
    }

    public function channelAccounts(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $params['uid'] = (int) $merchant['id'];
        $rows = $this->filterRows($this->tableRows('pay_account'), $params, ['uid', 'pay_type', 'channel_code', 'status'], ['query' => ['name', 'account', 'sub_account', 'remark']]);
        $rows = $this->hydrateChannelAccountRows($rows);
        usort($rows, static fn (array $left, array $right): int => ((int) ($left['sort'] ?? 0) <=> (int) ($right['sort'] ?? 0)) ?: ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0)));
        Response::success($this->paginate($rows, $params));
    }

    public function channelAccountPluginAction(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $accountId = (int) ($request->input('account_id', $request->input('id', 0)));
        if ($accountId <= 0) {
            Response::error('收款账号 ID 无效', 422, 422);
        }
        $account = $this->merchantPluginAccount($accountId, (int) $merchant['id']);
        $params = $request->input('params', []);
        if (!is_array($params)) {
            Response::error('插件动作参数格式错误', 422, 422);
        }
        $result = PluginAccountService::action(
            $account,
            (string) $request->input('func', ''),
            $params,
        );
        Response::json([
            'code' => (int) ($result['code'] ?? 500),
            'message' => (string) ($result['message'] ?? '插件动作失败'),
            'data' => $result['data'] ?? [],
            'redirect' => '',
        ]);
    }

    public function channelAccountQrcodeLogin(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $accountId = (int) ($request->input('id', $request->input('account_id', 0)));
        if ($accountId <= 0) {
            Response::error('收款账号 ID 无效', 422, 422);
        }
        $account = $this->merchantPluginAccount($accountId, (int) $merchant['id']);
        $params = $request->all();
        try {
            $result = PluginAccountService::loginQrcode(
                Database::connection(),
                $account,
                $merchant,
                $params,
                $this->requestSite($request),
            );
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        } catch (\Throwable $exception) {
            Response::error('二维码登录初始化失败', 500, 500);
        }
        Response::json([
            'code' => (int) ($result['code'] ?? 500),
            'message' => (string) ($result['message'] ?? '二维码登录初始化失败'),
            'data' => $result['data'] ?? [],
            'redirect' => '',
        ]);
    }

    public function channelAccountQrcodeLoginCheck(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $ticketId = trim((string) $request->input('ticket_id', ''));
        if ($ticketId === '') {
            Response::error('二维码登录票据不能为空', 422, 422);
        }
        $query = Database::connection()->prepare(
            'SELECT t.id AS ticket_row_id, t.ticket_id, t.account_id AS ticket_account_id, '
            . 't.uid AS ticket_uid, t.plugin_name AS ticket_plugin_name, t.params AS ticket_params, '
            . 't.status AS ticket_status, t.expires_at AS ticket_expires_at, '
            . 't.created_at AS ticket_created_at, t.updated_at AS ticket_updated_at, '
            . 'a.*, c.plugin_name AS channel_plugin_name '
            . 'FROM qrcode_login_ticket t '
            . 'INNER JOIN pay_account a ON a.id = t.account_id AND a.uid = :uid '
            . 'INNER JOIN pay_channel c ON c.code = a.channel_code '
            . 'WHERE t.ticket_id = :ticket_id AND t.uid = :ticket_uid LIMIT 1'
        );
        $query->execute([
            ':uid' => (int) $merchant['id'],
            ':ticket_id' => $ticketId,
            ':ticket_uid' => (int) $merchant['id'],
        ]);
        $ticket = $query->fetch();
        if (!is_array($ticket)) {
            Response::error('二维码登录票据不存在', 404, 404);
        }
        $ticketData = $ticket + [
            'id' => (int) ($ticket['ticket_row_id'] ?? 0),
            'status' => (int) ($ticket['ticket_status'] ?? 0),
            'expires_at' => (int) ($ticket['ticket_expires_at'] ?? 0),
            'params' => (string) ($ticket['ticket_params'] ?? '{}'),
            'plugin_name' => (string) ($ticket['ticket_plugin_name'] ?? ''),
        ];
        $ticket['plugin_name'] = (string) ($ticket['channel_plugin_name'] ?? '');
        try {
            $result = PluginAccountService::checkQrcode(
                Database::connection(),
                $ticketData,
                $ticket,
                $merchant,
            );
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        } catch (\Throwable $exception) {
            Response::error('二维码登录状态检查失败', 500, 500);
        }
        Response::json([
            'code' => (int) ($result['code'] ?? 500),
            'message' => (string) ($result['message'] ?? '二维码登录状态检查失败'),
            'data' => $result['data'] ?? [],
            'redirect' => '',
        ]);
    }

    public function channelAccountDetail(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $id = (int) $request->input('id', 0);
        $query = Database::connection()->prepare('SELECT * FROM pay_account WHERE id = :id AND uid = :uid LIMIT 1');
        $query->execute([':id' => $id, ':uid' => (int) $merchant['id']]);
        $row = $query->fetch();
        if (!is_array($row)) {
            Response::error('收款账号不存在', 404, 404);
        }
        $row['options'] = $this->decodeJsonField($row['options'] ?? '{}', []);
        $row['bind_pay_type'] = $this->decodeJsonField($row['bind_pay_type'] ?? '[]', []);
        $row['limit_rule'] = [
            'max_order_time' => 0,
            'max_order_count' => 0,
            'max_order_amount' => 0,
            'max_order_filter' => 1,
            'day_order_amount' => (int) ($row['day_amount_limit'] ?? 0),
            'day_order_count' => 0,
            'day_order_filter' => 1,
        ];
        Response::success($row);
    }

    public function createChannelAccount(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $this->writeChannelAccount($request->all(), (int) $merchant['id'], null);
    }

    public function editChannelAccount(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $this->writeChannelAccount($request->all(), (int) $merchant['id'], (int) $request->input('id', 0));
    }

    public function removeChannelAccount(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $delete = Database::connection()->prepare('DELETE FROM pay_account WHERE id = :id AND uid = :uid');
        $delete->execute([':id' => (int) $request->input('id', 0), ':uid' => (int) $merchant['id']]);
        $this->ensureAffected($delete->rowCount(), '收款账号不存在');
        Response::success(null, '收款账号删除成功');
    }

    public function switchChannelAccountStatus(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $status = filter_var($request->input('status'), FILTER_VALIDATE_INT);
        if ($status === false || !in_array((int) $status, [0, 1], true)) {
            Response::error('状态值只能为 0 或 1', 422, 422);
        }
        $update = Database::connection()->prepare('UPDATE pay_account SET status = :status WHERE id = :id AND uid = :uid');
        $update->execute([':status' => (int) $status, ':id' => (int) $request->input('id', 0), ':uid' => (int) $merchant['id']]);
        $this->ensureAffected($update->rowCount(), '收款账号不存在');
        Response::success(null, '收款账号状态更新成功');
    }

    public function copyChannelAccount(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $query = Database::connection()->prepare('SELECT * FROM pay_account WHERE id = :id AND uid = :uid LIMIT 1');
        $query->execute([':id' => (int) $request->input('id', 0), ':uid' => (int) $merchant['id']]);
        $row = $query->fetch();
        if (!is_array($row)) {
            Response::error('收款账号不存在', 404, 404);
        }
        unset($row['id']);
        $row['name'] = trim((string) ($row['name'] ?? '') . ' (副本)');
        $this->writeChannelAccount($row, (int) $merchant['id'], null);
    }

    public function batchImportInfo(Request $request): never
    {
        $this->authenticatedMerchant($request);
        Response::success(['fields' => ['pay_type', 'channel_code', 'name', 'account', 'account_type', 'remark'], 'format' => 'JSON array']);
    }

    public function batchImportAccounts(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $rows = $request->input('list', $request->input('accounts', []));
        if (!is_array($rows) || $rows === [] || count($rows) > 100) {
            Response::error('导入数据必须为 1 至 100 条记录', 422, 422);
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('导入记录格式错误');
                }
                $this->insertMerchantAccount($db, $row, (int) $merchant['id']);
            }
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error($exception instanceof \InvalidArgumentException ? $exception->getMessage() : '批量导入失败', 422, 422);
        }
        Response::success(['imported' => count($rows)], '收款账号导入完成');
    }

    public function removeOrder(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $id = (int) ($params['id'] ?? 0);
        $orderId = trim((string) ($params['order_id'] ?? ''));
        if ($id <= 0 && $orderId === '') {
            Response::error('订单号不能为空', 422, 422);
        }
        $delete = Database::connection()->prepare('DELETE FROM `order` WHERE uid = :uid AND ((id = :id AND :id_check = 1) OR (order_id = :order_id AND :order_id_check = 1))');
        $delete->execute([
            ':uid' => (int) $merchant['id'],
            ':id' => $id,
            ':id_check' => $id > 0 ? 1 : 0,
            ':order_id' => $orderId,
            ':order_id_check' => $orderId !== '' ? 1 : 0,
        ]);
        $this->ensureAffected($delete->rowCount(), '订单不存在');
        Response::success(null, '订单删除成功');
    }

    public function batchRemoveOrders(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $ids = $request->input('order_ids', []);
        if (!is_array($ids) || $ids === [] || count($ids) > 100) {
            Response::error('请选择 1 至 100 个订单', 422, 422);
        }
        $db = Database::connection();
        $delete = $db->prepare('DELETE FROM `order` WHERE uid = :uid AND order_id = :order_id');
        $removed = 0;
        foreach ($ids as $orderId) {
            $delete->execute([':uid' => (int) $merchant['id'], ':order_id' => trim((string) $orderId)]);
            $removed += $delete->rowCount();
        }
        Response::success(['removed' => $removed], '批量删除完成');
    }

    public function closeOrder(Request $request): never
    {
        $this->setMerchantOrderStatus($request, 3, '订单已关闭');
    }

    public function waitOrder(Request $request): never
    {
        $this->setMerchantOrderStatus($request, 1, '订单已设为待支付');
    }

    public function successOrder(Request $request): never
    {
        $this->setMerchantOrderStatus($request, 2, '订单已补单成功');
    }

    public function callbackOrder(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $order = $this->findMerchantOrder($request->all(), (int) $merchant['id']);
        if ((int) ($order['status'] ?? 0) !== 2) {
            Response::error('只有已支付订单可以发送异步通知', 409, 409);
        }
        $result = (new OrderNotifier())->enqueue(Database::connection(), $order, $merchant);
        Response::success($result, '异步通知已加入队列');
    }

    public function createTestOrder(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $amount = (int) ($params['amount'] ?? $params['money'] ?? 100);
        if ($amount <= 0 || $amount > 100000000) {
            Response::error('测试订单金额必须在 1 至 100000000 分之间', 422, 422);
        }
        $now = time();
        $orderId = '';
        $candidate = Database::connection()->prepare('SELECT 1 FROM `order` WHERE order_id = :order_id LIMIT 1');
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $value = date('YmdHis') . random_int(1000, 9999);
            $candidate->execute([':order_id' => $value]);
            if ($candidate->fetchColumn() === false) {
                $orderId = $value;
                break;
            }
        }
        if ($orderId === '') {
            Response::error('测试订单号生成失败，请重试', 503, 503);
        }
        $insert = Database::connection()->prepare('INSERT INTO `order` (order_id, out_order_id, uid, price, amount, trade_amount, rate_amount, status, subject, expire_time, pay_type, created_at, updated_at) VALUES (:order_id, :out_order_id, :uid, :price, :amount, :trade_amount, 0, 1, :subject, :expire_time, :pay_type, :created_at, :updated_at)');
        $insert->execute([
            ':order_id' => $orderId,
            ':out_order_id' => trim((string) ($params['out_order_id'] ?? $orderId)),
            ':uid' => (int) $merchant['id'],
            ':price' => $amount,
            ':amount' => $amount,
            ':trade_amount' => $amount,
            ':subject' => trim((string) ($params['subject'] ?? '商户测试订单')),
            ':expire_time' => $now + 900,
            ':pay_type' => trim((string) ($params['pay_type'] ?? '')),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        Response::success(['order_id' => $orderId, 'trade_no' => $orderId], '测试订单创建成功');
    }

    public function noticeDetail(Request $request): never
    {
        $this->authenticatedMerchant($request);
        $id = (int) $request->input('id', 0);
        $position = (int) $request->input('position', 0);
        $query = Database::connection()->prepare('SELECT id, title, content, position, created_at, updated_at FROM notice WHERE status = 1 AND position IN (:position, 0) AND (:id_disabled = 1 OR id = :notice_id) ORDER BY CASE WHEN position = :exact_position THEN 0 ELSE 1 END, sort ASC, id DESC LIMIT 1');
        $query->execute([':position' => $position, ':id_disabled' => $id === 0 ? 1 : 0, ':notice_id' => $id, ':exact_position' => $position]);
        $notice = $query->fetch();
        if (!is_array($notice)) {
            Response::error('公告不存在', 404, 404);
        }
        Response::success($notice);
    }

    public function loginLogs(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $query = Database::connection()->prepare(
            'SELECT l.*, l.status AS type, COALESCE(l.login_type, 1) AS login_type '
            . 'FROM login_log l WHERE l.uid = :uid ORDER BY l.id DESC'
        );
        $query->execute([':uid' => (int) $merchant['id']]);
        Response::success($this->paginate($this->filterLogRows($query->fetchAll(), $params), $params));
    }

    public function balanceLogs(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $query = Database::connection()->prepare('SELECT * FROM balance_log WHERE uid = :uid ORDER BY id DESC');
        $query->execute([':uid' => (int) $merchant['id']]);
        $rows = array_map(static fn (array $row): array => $row + [
            'balance' => (int) ($row['amount'] ?? 0),
            'before' => (int) ($row['before_balance'] ?? 0),
            'after' => (int) ($row['after_balance'] ?? 0),
            'org_name' => '',
        ], $this->filterLogRows($query->fetchAll(), $params));
        Response::success($this->paginate($rows, $params));
    }

    public function notifyLogs(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $query = Database::connection()->prepare('SELECT * FROM notify_log WHERE uid = :uid ORDER BY id DESC');
        $query->execute([':uid' => (int) $merchant['id']]);
        Response::success($this->paginate($this->filterLogRows($query->fetchAll(), $params), $params));
    }

    public function batchRemoveNotifyLogs(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $ids = $request->input('ids', $request->input('log_ids', []));
        if (!is_array($ids) || $ids === []) {
            Response::error('请选择通知日志', 422, 422);
        }
        $delete = Database::connection()->prepare('DELETE FROM notify_log WHERE id = :id AND uid = :uid');
        $removed = 0;
        foreach ($ids as $id) {
            $delete->execute([':id' => (int) $id, ':uid' => (int) $merchant['id']]);
            $removed += $delete->rowCount();
        }
        Response::success(['removed' => $removed], '通知日志已删除');
    }

    public function batchCleanNotifyLogs(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $delete = Database::connection()->prepare('DELETE FROM notify_log WHERE uid = :uid');
        $delete->execute([':uid' => (int) $merchant['id']]);
        Response::success(['removed' => $delete->rowCount()], '通知日志已清空');
    }

    public function withdrawAccounts(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $query = Database::connection()->prepare('SELECT * FROM withdraw_account WHERE uid = :uid ORDER BY id DESC');
        $query->execute([':uid' => (int) $merchant['id']]);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row + [
                'account_no' => (string) ($row['account'] ?? ''),
                'account_name' => (string) ($row['name'] ?? ''),
                'qr_code' => (string) ($row['qr_code'] ?? ''),
            ];
        }
        Response::success($this->paginate($rows, $params));
    }

    public function saveWithdrawAccount(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $name = trim((string) ($params['account_name'] ?? $params['name'] ?? ''));
        $account = trim((string) ($params['account_no'] ?? $params['account'] ?? ''));
        if ($name === '' || $account === '') {
            Response::error('收款人姓名和账号不能为空', 422, 422);
        }
        $db = Database::connection();
        $id = (int) ($params['id'] ?? 0);
        $values = [
            ':uid' => (int) $merchant['id'],
            ':type' => trim((string) ($params['type'] ?? '')),
            ':name' => $name,
            ':account' => $account,
            ':bank_name' => trim((string) ($params['bank_name'] ?? '')),
            ':qr_code' => trim((string) ($params['qr_code'] ?? '')),
            ':remark' => trim((string) ($params['remark'] ?? '')),
            ':updated_at' => time(),
        ];
        if ($id > 0) {
            $update = $db->prepare('UPDATE withdraw_account SET type = :type, name = :name, account = :account, bank_name = :bank_name, qr_code = :qr_code, remark = :remark, updated_at = :updated_at WHERE id = :id AND uid = :uid');
            $update->execute($values + [':id' => $id]);
            if ($update->rowCount() === 0) {
                $check = $db->prepare('SELECT COUNT(*) FROM withdraw_account WHERE id = :id AND uid = :uid');
                $check->execute([':id' => $id, ':uid' => (int) $merchant['id']]);
                $this->ensureAffected((int) $check->fetchColumn(), '提现账号不存在');
            }
            Response::success(['id' => $id], '提现账号保存成功');
        }
        $insert = $db->prepare('INSERT INTO withdraw_account (uid, type, name, account, bank_name, qr_code, remark, status, created_at, updated_at) VALUES (:uid, :type, :name, :account, :bank_name, :qr_code, :remark, 1, :updated_at, :updated_at)');
        $insert->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '提现账号添加成功');
    }

    public function removeWithdrawAccount(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $delete = Database::connection()->prepare('DELETE FROM withdraw_account WHERE id = :id AND uid = :uid');
        $delete->execute([':id' => (int) $request->input('id', 0), ':uid' => (int) $merchant['id']]);
        $this->ensureAffected($delete->rowCount(), '提现账号不存在');
        Response::success(null, '提现账号删除成功');
    }

    public function withdrawIncomeList(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $query = Database::connection()->prepare('SELECT i.*, a.type AS account_type, a.name AS account_name, a.account AS account_no, a.bank_name, a.qr_code FROM withdraw_income i LEFT JOIN withdraw_account a ON a.id = i.account_id AND a.uid = i.uid WHERE i.uid = :uid ORDER BY i.id DESC');
        $query->execute([':uid' => (int) $merchant['id']]);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        Response::success($this->paginate($rows, $params));
    }

    public function withdrawIncomeConfig(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $config = $this->withdrawConfig();
        Response::success($config + [
            'enabled' => $config['withdraw_income_enable'] === '1',
            'minimum' => (int) $config['withdraw_income_min'],
            'fee_rate' => $config['withdraw_income_fee_type'] === '1' ? (int) $config['withdraw_income_fee_value'] : 0,
            'available_balance' => (int) ($merchant['balance'] ?? 0),
        ]);
    }

    public function createWithdrawIncome(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $params = $request->all();
        $amount = (int) ($params['amount'] ?? 0);
        $accountId = (int) ($params['account_id'] ?? 0);
        if ($amount <= 0 || $accountId <= 0) {
            Response::error('提现金额和收款账号不能为空', 422, 422);
        }
        $config = $this->withdrawConfig();
        if ($config['withdraw_income_enable'] !== '1') {
            Response::error('提现功能已关闭', 403, 403);
        }
        $minimum = max((int) $config['withdraw_income_min'], 0);
        if ($amount < $minimum) {
            Response::error('提现金额低于最低提现额', 422, 422);
        }
        $account = Database::connection()->prepare('SELECT * FROM withdraw_account WHERE id = :id AND uid = :uid AND status = 1 LIMIT 1');
        $account->execute([':id' => $accountId, ':uid' => (int) $merchant['id']]);
        $accountRow = $account->fetch();
        if (!is_array($accountRow)) {
            Response::error('收款账号不存在', 404, 404);
        }
        $feeType = (string) $config['withdraw_income_fee_type'];
        $feeValue = max((int) $config['withdraw_income_fee_value'], 0);
        $fee = $feeType === '1' ? (int) round($amount * $feeValue / 10000) : $feeValue;
        $realAmount = $amount - $fee;
        if ($realAmount <= 0) {
            Response::error('手续费不能大于提现金额', 422, 422);
        }

        $db = Database::connection();
        $now = time();
        $orderId = 'WD' . date('YmdHis') . random_int(100000, 999999);
        $db->beginTransaction();
        try {
            $balanceQuery = $db->prepare('SELECT balance FROM `user` WHERE id = :id LIMIT 1' . (Database::driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $balanceQuery->execute([':id' => (int) $merchant['id']]);
            $balance = $balanceQuery->fetchColumn();
            if ($balance === false) {
                throw new \RuntimeException('商户不存在');
            }
            $balance = (int) $balance;
            if ($balance < $amount) {
                $db->rollBack();
                Response::error('商户余额不足', 422, 422);
            }
            $updateBalance = $db->prepare('UPDATE `user` SET balance = :balance WHERE id = :id');
            $updateBalance->execute([':balance' => $balance - $amount, ':id' => (int) $merchant['id']]);
            $insert = $db->prepare('INSERT INTO withdraw_income (uid, account_id, order_id, amount, fee, real_amount, status, remark, created_at, updated_at) VALUES (:uid, :account_id, :order_id, :amount, :fee, :real_amount, 1, :remark, :created_at, :updated_at)');
            $insert->execute([
                ':uid' => (int) $merchant['id'],
                ':account_id' => $accountId,
                ':order_id' => $orderId,
                ':amount' => $amount,
                ':fee' => $fee,
                ':real_amount' => $realAmount,
                ':remark' => trim((string) ($params['remark'] ?? '')),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $log = $db->prepare('INSERT INTO balance_log (uid, type, amount, before_balance, after_balance, remark, created_at) VALUES (:uid, :type, :amount, :before_balance, :after_balance, :remark, :created_at)');
            $log->execute([':uid' => (int) $merchant['id'], ':type' => 'withdraw', ':amount' => -$amount, ':before_balance' => $balance, ':after_balance' => $balance - $amount, ':remark' => '提现申请 ' . $orderId, ':created_at' => $now]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        Response::success(['id' => (int) $db->lastInsertId(), 'order_id' => $orderId, 'amount' => $amount, 'fee' => $fee, 'real_amount' => $realAmount, 'status' => 1], '提现申请已提交');
    }

    public function createRecharge(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $amount = filter_var($request->input('amount'), FILTER_VALIDATE_INT);
        if ($amount === false || $amount <= 0) {
            Response::error('充值金额必须为大于 0 的整数分', 422, 422);
        }
        $payType = trim((string) $request->input('pay_type', ''));
        if ($payType === '') {
            Response::error('请选择充值支付方式', 422, 422);
        }

        $db = Database::connection();
        try {
            $db->beginTransaction();
            $result = (new RechargeService())->create(
                $db,
                $merchant,
                (int) $amount,
                $payType,
                $this->requestSite($request),
                time(),
            );
            $db->commit();
        } catch (\InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error($exception->getMessage(), 422, 422);
        } catch (\RuntimeException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error($exception->getMessage(), 501, 501);
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('充值订单创建失败', 500, 500);
        }
        Response::success($result, '充值订单已创建');
    }

    public function rechargeStatus(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $orderId = trim((string) $request->input('order_id', $request->input('id', '')));
        if ($orderId === '') {
            Response::error('充值订单号不能为空', 422, 422);
        }
        $query = Database::connection()->prepare('SELECT id, order_id, amount, status, remark, pay_type, payment_order_id, paid_at, created_at, updated_at FROM recharge WHERE uid = :uid AND order_id = :order_id LIMIT 1');
        $query->execute([':uid' => (int) $merchant['id'], ':order_id' => $orderId]);
        $row = $query->fetch();
        if (!is_array($row)) {
            Response::error('充值订单不存在', 404, 404);
        }
        Response::success($row);
    }

    public function useRechargeCard(Request $request): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $secret = trim((string) $request->input('secret', $request->input('card', '')));
        try {
            $result = (new RechargeCardService())->redeem(
                Database::connection(),
                (int) $merchant['id'],
                $secret,
                time(),
            );
        } catch (\InvalidArgumentException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        } catch (\Throwable $exception) {
            Response::error('充值卡密兑换失败，未入账', 500, 500);
        }
        Response::success($result, '充值卡密兑换成功');
    }

    public function rechargeNotify(Request $request): never
    {
        $params = $request->all();
        $db = Database::connection();
        try {
            $callback = (new RechargeService())->verifyEpay($db, $params);
            (new RechargeService())->settle($db, (string) $callback['order_id'], (int) $callback['amount'], time());
        } catch (\Throwable $exception) {
            Response::json(['code' => 0, 'message' => $exception->getMessage()], 400);
        }
        Response::json(['code' => 1, 'message' => 'success']);
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
        $position = (int) $request->input('position', 0);
        $query = Database::connection()->prepare('SELECT id, title, content, position, created_at, updated_at FROM notice WHERE status = 1 AND position IN (:position, 0) ORDER BY CASE WHEN position = :exact_position THEN 0 ELSE 1 END, sort ASC, id DESC LIMIT 1');
        $query->execute([':position' => $position, ':exact_position' => $position]);
        $notice = $query->fetch();
        if (!is_array($notice)) {
            Response::json(['code' => 204, 'message' => '暂无公告', 'data' => [], 'redirect' => '']);
        }
        Response::success(['show_diff' => true, 'notice' => $notice, 'id' => (int) $notice['id'], 'title' => (string) $notice['title'], 'content' => (string) $notice['content']]);
    }

    public function noticeShows(Request $request): never
    {
        $this->authenticatedMerchant($request);
        $params = $request->all();
        $position = (int) ($params['position'] ?? 0);
        $query = Database::connection()->prepare('SELECT id, title, content, position, status, sort, created_at, updated_at FROM notice WHERE status = 1 AND position IN (:position, 0) ORDER BY CASE WHEN position = :exact_position THEN 0 ELSE 1 END, sort ASC, id DESC');
        $query->execute([':position' => $position, ':exact_position' => $position]);
        $rows = $query->fetchAll();
        Response::success($this->paginate(is_array($rows) ? $rows : [], $params));
    }

    public function plugins(Request $request): never
    {
        $this->authenticatedMerchant($request);
        $items = PaymentPluginRegistry::catalog();
        Response::success(['list' => $items, 'count' => count($items), 'total' => count($items)]);
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

    /** @return array<string, mixed> */
    private function merchantPluginAccount(int $accountId, int $uid): array
    {
        $query = Database::connection()->prepare(
            'SELECT a.*, c.plugin_name FROM pay_account a '
            . 'INNER JOIN pay_channel c ON c.code = a.channel_code '
            . 'WHERE a.id = :id AND a.uid = :uid LIMIT 1'
        );
        $query->execute([':id' => $accountId, ':uid' => $uid]);
        $account = $query->fetch();
        if (!is_array($account)) {
            Response::error('收款账号不存在', 404, 404);
        }
        if (trim((string) ($account['plugin_name'] ?? '')) === '') {
            Response::error('收款账号未配置支付插件', 501, 501);
        }
        return $account;
    }

    private function requestSite(Request $request): string
    {
        $configured = trim((string) getenv('XARR_SITE_URL'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    private function webAuthnRpId(Request $request): string
    {
        $configured = trim((string) getenv('XARR_WEBAUTHN_RP_ID'));
        if ($configured !== '') {
            return strtolower($configured);
        }
        $host = strtolower((string) parse_url($this->requestSite($request), PHP_URL_HOST));
        if ($host === '') {
            Response::error('无法确定 WebAuthn 域名', 503, 503);
        }
        return $host;
    }

    private function webAuthnOrigin(Request $request): string
    {
        $origin = trim((string) getenv('XARR_WEBAUTHN_ORIGIN'));
        return $origin !== '' ? rtrim($origin, '/') : $this->requestSite($request);
    }

    /** @return array<string,mixed> */
    private function credentialPayload(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value) || !is_array($value['response'] ?? null)) {
            Response::error('通行密钥凭据格式无效', 422, 422);
        }
        return $value;
    }

    private function consumeWebAuthnChallenge(\PDO $db, int $id): bool
    {
        $query = $db->prepare(
            'UPDATE webauthn_challenge SET status=2,updated_at=:updated_at '
            . 'WHERE id=:id AND status=1 AND expires_at>=:expires_at'
        );
        $query->execute([':updated_at' => time(), ':id' => $id, ':expires_at' => time()]);
        return $query->rowCount() === 1;
    }

    private function passwordMatches(string $password, string $stored): bool
    {
        return preg_match('/^[a-f0-9]{32}$/i', $stored) === 1 && hash_equals(strtolower($stored), md5($password));
    }

    private function consumeStepUpTicket(Request $request, int $uid, string $operation): void
    {
        $factor = Database::connection()->prepare(
            'SELECT mfa_enabled, mfa_secret FROM `user` WHERE id = :id AND status = 1 LIMIT 1'
        );
        $factor->execute([':id' => $uid]);
        $merchant = $factor->fetch();
        $hasTotp = is_array($merchant)
            && (int) ($merchant['mfa_enabled'] ?? 0) === 1
            && trim((string) ($merchant['mfa_secret'] ?? '')) !== '';
        $passkey = Database::connection()->prepare(
            'SELECT COUNT(*) FROM passkey_credential WHERE uid = :uid AND status = 1'
        );
        $passkey->execute([':uid' => $uid]);
        if (!$hasTotp && (int) $passkey->fetchColumn() === 0) {
            return;
        }

        $ticket = trim((string) ($request->header('X-Step-Up-Ticket') ?? $request->input('ticket', '')));
        if ($ticket === '') {
            Response::error('请先完成二次安全验证', 401, 401);
        }
        $db = Database::connection();
        $row = SecurityTicket::find($db, $ticket, SecurityTicket::KIND_STEP_UP, $uid);
        if (!is_array($row)) {
            Response::error('二次安全验证票据不存在', 401, 401);
        }
        if ((string) ($row['operation'] ?? '') !== $operation) {
            Response::error('二次安全验证票据用途不匹配', 401, 401);
        }
        if ((int) ($row['expires_at'] ?? 0) < time()) {
            SecurityTicket::mark($db, (int) $row['id'], SecurityTicket::STATUS_EXPIRED);
            Response::error('二次安全验证已过期，请重新操作', 401, 401);
        }
        if ((int) ($row['status'] ?? 0) !== SecurityTicket::STATUS_VERIFIED) {
            Response::error('请先完成二次安全验证', 401, 401);
        }
        if (!SecurityTicket::consumeVerified($db, $ticket, SecurityTicket::KIND_STEP_UP, $uid, $operation)) {
            Response::error('二次安全验证票据已使用，请重新操作', 401, 401);
        }
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

    /** @return array{withdraw_income_enable:string, withdraw_income_fee_type:string, withdraw_income_fee_value:string, withdraw_income_min:string} */
    private function withdrawConfig(): array
    {
        $defaults = [
            'withdraw_income_enable' => '1',
            'withdraw_income_fee_type' => '1',
            'withdraw_income_fee_value' => '0',
            'withdraw_income_min' => '0',
        ];
        $query = Database::connection()->prepare('SELECT `key`, value FROM options WHERE `key` IN (?, ?, ?, ?)');
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

    private function issueToken(int $merchantId): string
    {
        $token = $this->token();
        $now = time();
        $update = Database::connection()->prepare(
            'UPDATE `user` SET token = :token, last_login_ip = :last_login_ip, last_login_time = :last_login_time WHERE id = :id'
        );
        $update->execute([
            ':token' => $token,
            ':last_login_ip' => $this->clientIp(),
            ':last_login_time' => $now,
            ':id' => $merchantId,
        ]);
        return $token;
    }

    /** @return array<string, mixed> */
    private function decodeSettings(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function decodeJsonField(mixed $value, array $default = []): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /** @param array<string, mixed> $params */
    private function writeChannelAccount(array $params, int $uid, ?int $id): never
    {
        $params['uid'] = $uid;
        try {
            $this->validateMerchantAccount($params);
            $values = $this->merchantAccountValues($params);
        } catch (\InvalidArgumentException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        }
        $db = Database::connection();
        if ($id !== null) {
            if ($id <= 0) {
                Response::error('收款账号 ID 无效', 422, 422);
            }
            $values[':id'] = $id;
            $values[':uid'] = $uid;
            $values[':updated_at'] = time();
            $update = $db->prepare('UPDATE pay_account SET pay_type = :pay_type, channel_code = :channel_code, account = :account, account_type = :account_type, qrcode_data = :qrcode_data, qrcode = :qrcode, uri = :uri, scheme = :scheme, status = :status, name = :name, sub_account = :sub_account, bind_client_name = :bind_client_name, sort = :sort, remark = :remark, min_amount = :min_amount, max_amount = :max_amount, day_amount_limit = :day_amount_limit, code = :code, options = :options, bind_pay_type = :bind_pay_type, updated_at = :updated_at WHERE id = :id AND uid = :uid');
            $update->execute($values);
            if ($update->rowCount() === 0) {
                $check = $db->prepare('SELECT COUNT(*) FROM pay_account WHERE id = :id AND uid = :uid');
                $check->execute([':id' => $id, ':uid' => $uid]);
                $this->ensureAffected((int) $check->fetchColumn(), '收款账号不存在');
            }
            PaymentCodeStore::syncAccount($db, $id, $params);
            Response::success(['id' => $id], '收款账号保存成功');
        }

        $now = time();
        $values[':created_at'] = $now;
        $values[':updated_at'] = $now;
        $columns = 'uid, pay_type, channel_code, account, account_type, qrcode_data, qrcode, uri, scheme, status, name, sub_account, bind_client_name, sort, remark, min_amount, max_amount, day_amount_limit, code, options, bind_pay_type, created_at, updated_at';
        $insert = $db->prepare('INSERT INTO pay_account (' . $columns . ') VALUES (:uid, :pay_type, :channel_code, :account, :account_type, :qrcode_data, :qrcode, :uri, :scheme, :status, :name, :sub_account, :bind_client_name, :sort, :remark, :min_amount, :max_amount, :day_amount_limit, :code, :options, :bind_pay_type, :created_at, :updated_at)');
        $insert->execute($values);
        $newId = (int) $db->lastInsertId();
        PaymentCodeStore::syncAccount($db, $newId, $params);
        Response::success(['id' => $newId], '收款账号创建成功');
    }

    /** @param array<string, mixed> $params */
    private function validateMerchantAccount(array $params): void
    {
        $payType = trim((string) ($params['pay_type'] ?? ''));
        $channelCode = trim((string) ($params['channel_code'] ?? $params['code'] ?? ''));
        if ($payType === '' || $channelCode === '') {
            throw new \InvalidArgumentException('支付方式和通道不能为空');
        }
        $db = Database::connection();
        $type = $db->prepare('SELECT COUNT(*) FROM pay_type WHERE value = :value AND status = 1');
        $type->execute([':value' => $payType]);
        if ((int) $type->fetchColumn() === 0) {
            throw new \InvalidArgumentException('支付方式不存在');
        }
        $channel = $db->prepare('SELECT status, type, plugin_name FROM pay_channel WHERE code = :code LIMIT 1');
        $channel->execute([':code' => $channelCode]);
        $channelRow = $channel->fetch();
        if (!is_array($channelRow)) {
            throw new \InvalidArgumentException('支付通道不存在');
        }
        if ((int) ($channelRow['status'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('支付通道已停用');
        }
        if ((string) ($channelRow['type'] ?? '') !== $payType) {
            throw new \InvalidArgumentException('支付方式与通道不匹配');
        }
        $pluginName = trim((string) ($channelRow['plugin_name'] ?? ''));
        if ($pluginName === '') {
            throw new \InvalidArgumentException('支付通道未配置支付插件');
        }
        try {
            PaymentPluginRegistry::resolve($pluginName);
            PaymentPluginRegistry::resolveAccount($pluginName);
            if (!PaymentPluginRegistry::supportsPayType($pluginName, $payType)) {
                throw new \RuntimeException('支付插件不支持当前支付方式');
            }
            if (!PaymentPluginCatalog::supportsChannel($pluginName, $channelCode, $payType)) {
                throw new \RuntimeException('支付插件不提供当前通道');
            }
        } catch (\RuntimeException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
    }

    private function validateUsableChannel(string $channelCode, string $payType): void
    {
        $db = Database::connection();
        $type = $db->prepare('SELECT status FROM pay_type WHERE value = :value LIMIT 1');
        $type->execute([':value' => $payType]);
        if ((int) $type->fetchColumn() !== 1) {
            Response::error('支付方式不存在或已停用', 422, 422);
        }

        $channel = $db->prepare('SELECT status, type, plugin_name FROM pay_channel WHERE code = :code LIMIT 1');
        $channel->execute([':code' => $channelCode]);
        $row = $channel->fetch();
        if (!is_array($row)) {
            Response::error('支付通道不存在', 404, 404);
        }
        if ((int) ($row['status'] ?? 0) !== 1) {
            Response::error('支付通道已停用', 422, 422);
        }
        if ((string) ($row['type'] ?? '') !== $payType) {
            Response::error('支付方式与通道不匹配', 422, 422);
        }
        $pluginName = trim((string) ($row['plugin_name'] ?? ''));
        if ($pluginName === '' || !PaymentPluginRegistry::usableForPayType($pluginName, $payType)
            || !PaymentPluginCatalog::supportsChannel($pluginName, $channelCode, $payType)) {
            Response::error('支付插件未启用或不支持当前支付方式', 422, 422);
        }
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function merchantAccountValues(array $params): array
    {
        $options = $params['options'] ?? [];
        $bindPayType = $params['bind_pay_type'] ?? [];
        $limitRule = is_array($params['limit_rule'] ?? null) ? $params['limit_rule'] : [];
        $dayAmountLimit = $params['day_amount_limit'] ?? ($limitRule['day_order_amount'] ?? 0);
        return [
            ':uid' => (int) $params['uid'],
            ':pay_type' => trim((string) $params['pay_type']),
            ':channel_code' => trim((string) ($params['channel_code'] ?? $params['code'] ?? '')),
            ':account' => trim((string) ($params['account'] ?? '')),
            ':account_type' => trim((string) ($params['account_type'] ?? '')),
            ':qrcode_data' => (string) ($params['qrcode_data'] ?? ''),
            ':qrcode' => trim((string) ($params['qrcode'] ?? '')),
            ':uri' => trim((string) ($params['uri'] ?? '')),
            ':scheme' => trim((string) ($params['scheme'] ?? '')),
            ':status' => $this->validatedStatus($params['status'] ?? 1),
            ':name' => trim((string) ($params['name'] ?? '')),
            ':sub_account' => trim((string) ($params['sub_account'] ?? '')),
            ':bind_client_name' => trim((string) ($params['bind_client_name'] ?? '')),
            ':sort' => (int) ($params['sort'] ?? 50),
            ':remark' => trim((string) ($params['remark'] ?? '')),
            ':min_amount' => max((int) ($params['min_amount'] ?? 0), 0),
            ':max_amount' => max((int) ($params['max_amount'] ?? 0), 0),
            ':day_amount_limit' => max((int) $dayAmountLimit, 0),
            ':code' => trim((string) ($params['code'] ?? $params['channel_code'] ?? '')),
            ':options' => is_string($options) ? $options : json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':bind_pay_type' => is_string($bindPayType) ? $bindPayType : json_encode($bindPayType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function validatedStatus(mixed $value): int
    {
        $status = filter_var($value, FILTER_VALIDATE_INT);
        if ($status === false || !in_array((int) $status, [0, 1], true)) {
            throw new \InvalidArgumentException('状态值只能为 0 或 1');
        }
        return (int) $status;
    }

    private function ensureAffected(int $count, string $message): void
    {
        if ($count === 0) {
            Response::error($message, 404, 404);
        }
    }

    private function setMerchantOrderStatus(Request $request, int $status, string $message): never
    {
        $merchant = $this->authenticatedMerchant($request);
        $order = $this->findMerchantOrder($request->all(), (int) $merchant['id']);
        if ($status === 2 && (int) ($order['status'] ?? 0) !== 2) {
            Response::error('本地版未接入订单结算与异步通知，不能直接将订单标记为成功', 501, 501);
        }
        $now = time();
        $payTime = $status === 2 ? ($order['pay_time'] ?? $now) : ($order['pay_time'] ?? null);
        $update = Database::connection()->prepare('UPDATE `order` SET status = :status, pay_time = :pay_time, updated_at = :updated_at WHERE id = :id AND uid = :uid');
        $update->execute([':status' => $status, ':pay_time' => $payTime, ':updated_at' => $now, ':id' => (int) $order['id'], ':uid' => (int) $merchant['id']]);
        Response::success(['order_id' => (string) $order['order_id'], 'status' => $status], $message);
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function findMerchantOrder(array $params, int $uid): array
    {
        $id = (int) ($params['id'] ?? 0);
        $orderId = trim((string) ($params['order_id'] ?? ''));
        if ($id <= 0 && $orderId === '') {
            Response::error('订单号不能为空', 422, 422);
        }
        $query = Database::connection()->prepare('SELECT * FROM `order` WHERE uid = :uid AND ((:id_enabled = 1 AND id = :row_id) OR (:order_enabled = 1 AND order_id = :order_id)) LIMIT 1');
        $query->execute([':uid' => $uid, ':id_enabled' => $id > 0 ? 1 : 0, ':row_id' => $id, ':order_enabled' => $orderId !== '' ? 1 : 0, ':order_id' => $orderId]);
        $order = $query->fetch();
        if (!is_array($order)) {
            Response::error('订单不存在', 404, 404);
        }
        return $order;
    }

    /** @param list<array<string, mixed>> $rows @param array<string, mixed> $params @return list<array<string, mixed>> */
    private function filterLogRows(array $rows, array $params): array
    {
        $needle = trim((string) ($params['query'] ?? ''));
        if ($needle === '') {
            return $rows;
        }
        return array_values(array_filter($rows, static function (array $row) use ($needle): bool {
            return stripos(implode(' ', array_map(static fn (mixed $value): string => (string) $value, $row)), $needle) !== false;
        }));
    }

    private function recordLogin(int $uid, string $username, int $status, string $message, int $loginType = 1): void
    {
        $insert = Database::connection()->prepare(
            'INSERT INTO login_log (uid, username, ip, user_agent, status, message, login_type, created_at) '
            . 'VALUES (:uid, :username, :ip, :user_agent, :status, :message, :login_type, :created_at)'
        );
        $insert->execute([
            ':uid' => $uid,
            ':username' => $username,
            ':ip' => $this->clientIp(),
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            ':status' => $status,
            ':message' => $message,
            ':login_type' => $loginType,
            ':created_at' => time(),
        ]);
    }

    private function clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $ip = trim((string) $candidate);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return substr($ip, 0, 64);
            }
        }
        return '';
    }
}
