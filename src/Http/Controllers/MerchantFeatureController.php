<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use XArrPay\Http\Request;
use XArrPay\Support\Captcha;
use XArrPay\Support\Database;
use XArrPay\Support\MerchantIdentity;
use XArrPay\Support\QrCode;
use XArrPay\Support\RebateService;
use XArrPay\Support\Response;
use XArrPay\Support\SecurityTicket;
use XArrPay\Support\SiteSettings;
use XArrPay\Support\Totp;
use XArrPay\Support\WebAuthn;
use XArrPay\Support\OptionStore;
use XArrPay\Support\PaymentPluginRegistry;
use XArrPay\Support\PluginRuntimeService;
use XArrPay\Support\ReportSigner;

final class MerchantFeatureController
{
    public function setProfile(Request $request): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $name = trim((string) ($params['merchant_name'] ?? $params['name'] ?? $merchant['merchant_name']));
        $email = trim((string) ($params['email'] ?? $merchant['email'] ?? ''));
        $phone = trim((string) ($params['phone'] ?? $merchant['phone'] ?? ''));
        if ($name === '') {
            Response::error('商户名称不能为空', 422, 422);
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Response::error('邮箱格式不正确', 422, 422);
        }
        $update = Database::connection()->prepare('UPDATE user SET merchant_name=:merchant_name,email=:email,phone=:phone WHERE id=:id');
        $update->execute([':merchant_name' => $name, ':email' => $email, ':phone' => $phone, ':id' => (int) $merchant['id']]);
        Response::success(['merchant_name' => $name, 'email' => $email, 'phone' => $phone], '资料保存成功');
    }

    public function sendBindEmailCode(Request $request): never
    {
        $merchant = $this->merchant($request);
        $email = trim((string) $request->input('email', ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Response::error('邮箱格式不正确', 422, 422);
        }
        $mailFrom = trim((string) getenv('XARR_MAIL_FROM'));
        if (!function_exists('mail') || $mailFrom === '') {
            Response::error('邮件服务未配置，不能发送绑定验证码', 501, 501);
        }
        $code = (string) random_int(100000, 999999);
        $db = Database::connection();
        $now = time();
        $recent = $db->prepare(
            'SELECT id FROM verification_code WHERE uid=:uid AND purpose="bind_email" AND target=:target AND status=1 AND created_at>:created_at LIMIT 1'
        );
        $recent->execute([':uid' => (int) $merchant['id'], ':target' => $email, ':created_at' => $now - 60]);
        if ($recent->fetchColumn() !== false) {
            Response::error('验证码发送过于频繁，请 60 秒后重试', 429, 429);
        }
        $db->prepare('UPDATE verification_code SET status=2,used_at=:used_at WHERE uid=:uid AND purpose="bind_email" AND status=1')
            ->execute([':used_at' => $now, ':uid' => (int) $merchant['id']]);
        $insert = $db->prepare(
            'INSERT INTO verification_code (uid,purpose,target,code_hash,expires_at,created_at) '
            . 'VALUES (:uid,"bind_email",:target,:code_hash,:expires_at,:created_at)'
        );
        $insert->execute([
            ':uid' => (int) $merchant['id'],
            ':target' => $email,
            ':code_hash' => password_hash($code, PASSWORD_DEFAULT),
            ':expires_at' => $now + 300,
            ':created_at' => $now,
        ]);
        $subject = '商户邮箱绑定验证码';
        $body = "您的绑定验证码是 {$code}，5分钟内有效。";
        $headers = 'From: ' . $mailFrom . "\r\nContent-Type: text/plain; charset=UTF-8";
        if (!mail($email, $subject, $body, $headers)) {
            $db->prepare(
                'UPDATE verification_code SET status=5,used_at=:used_at WHERE uid=:uid AND purpose="bind_email" AND target=:target AND status=1'
            )->execute([':used_at' => time(), ':uid' => (int) $merchant['id'], ':target' => $email]);
            Response::error('邮件发送失败，请检查邮件服务配置', 502, 502);
        }
        Response::success(['expire_seconds' => 300], '绑定验证码已发送');
    }

    public function bindEmail(Request $request): never
    {
        $merchant = $this->merchant($request);
        $email = trim((string) $request->input('email', ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Response::error('邮箱格式不正确', 422, 422);
        }
        $code = trim((string) ($request->input('email_code') ?? $request->input('code', '')));
        if (!preg_match('/^\d{6}$/', $code)) {
            Response::error('邮箱验证码格式不正确', 422, 422);
        }
        $this->consumeVerificationCode((int) $merchant['id'], 'bind_email', $email, $code);
        $this->consumeStepUpTicket($request, (int) $merchant['id'], 'email.bind');
        $update = Database::connection()->prepare('UPDATE user SET email=:email WHERE id=:id');
        $update->execute([':email' => $email, ':id' => (int) $merchant['id']]);
        Response::success(['email' => $email], '邮箱绑定成功');
    }

    public function sendBindPhoneCode(Request $request): never
    {
        $merchant = $this->merchant($request);
        $phone = trim((string) $request->input('phone', ''));
        if (preg_match('/^\+?[0-9]{6,20}$/', $phone) !== 1) {
            Response::error('手机号码格式不正确', 422, 422);
        }
        $webhook = trim((string) getenv('XARR_SMS_WEBHOOK_URL'));
        if ($webhook === '') {
            Response::error('短信服务未配置，不能发送绑定验证码', 501, 501);
        }
        $code = (string) random_int(100000, 999999);
        $db = Database::connection();
        $now = time();
        $recent = $db->prepare(
            'SELECT id FROM verification_code WHERE uid=:uid AND purpose="bind_phone" AND target=:target AND status=1 AND created_at>:created_at LIMIT 1'
        );
        $recent->execute([':uid' => (int) $merchant['id'], ':target' => $phone, ':created_at' => $now - 60]);
        if ($recent->fetchColumn() !== false) {
            Response::error('验证码发送过于频繁，请 60 秒后重试', 429, 429);
        }
        $db->prepare('UPDATE verification_code SET status=2,used_at=:used_at WHERE uid=:uid AND purpose="bind_phone" AND status=1')
            ->execute([':used_at' => $now, ':uid' => (int) $merchant['id']]);
        $insert = $db->prepare(
            'INSERT INTO verification_code (uid,purpose,target,code_hash,expires_at,created_at) '
            . 'VALUES (:uid,"bind_phone",:target,:code_hash,:expires_at,:created_at)'
        );
        $insert->execute([
            ':uid' => (int) $merchant['id'],
            ':target' => $phone,
            ':code_hash' => password_hash($code, PASSWORD_DEFAULT),
            ':expires_at' => $now + 300,
            ':created_at' => $now,
        ]);
        $payload = json_encode(['phone' => $phone, 'code' => $code, 'purpose' => 'bind_phone'], JSON_UNESCAPED_UNICODE);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($webhook, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int) $match[1];
                break;
            }
        }
        if ($result === false || $status < 200 || $status >= 300) {
            $db->prepare(
                'UPDATE verification_code SET status=5,used_at=:used_at WHERE uid=:uid AND purpose="bind_phone" AND target=:target AND status=1'
            )->execute([':used_at' => time(), ':uid' => (int) $merchant['id'], ':target' => $phone]);
            Response::error('短信发送失败，请检查短信服务配置', 502, 502);
        }
        Response::success(['expire_seconds' => 300], '手机验证码已发送');
    }

    public function bindPhone(Request $request): never
    {
        $merchant = $this->merchant($request);
        $phone = trim((string) $request->input('phone', ''));
        $code = trim((string) ($request->input('phone_code') ?? $request->input('code', '')));
        if (preg_match('/^\+?[0-9]{6,20}$/', $phone) !== 1 || !preg_match('/^\d{6}$/', $code)) {
            Response::error('手机号码或验证码格式不正确', 422, 422);
        }
        $this->consumeVerificationCode((int) $merchant['id'], 'bind_phone', $phone, $code);
        $this->consumeStepUpTicket($request, (int) $merchant['id'], 'phone.bind');
        $update = Database::connection()->prepare('UPDATE user SET phone=:phone WHERE id=:id');
        $update->execute([':phone' => $phone, ':id' => (int) $merchant['id']]);
        Response::success(['phone' => $phone], '手机绑定成功');
    }

    public function smsCreditInfo(Request $request): never
    {
        $this->merchant($request);
        Response::error('短信余额需要短信服务商和计费账本；当前版本未实现', 501, 501);
    }

    public function smsCreditBuy(Request $request): never
    {
        $this->merchant($request);
        Response::error('本地版未配置短信充值服务', 501, 501);
    }

    public function mfa(Request $request): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $step = (string) ($params['step'] ?? '1');
        if ($step !== '2') {
            if ((int) ($merchant['mfa_enabled'] ?? 0) === 1 && trim((string) ($merchant['mfa_secret'] ?? '')) !== '') {
                Response::error('动态口令已经开启，请先关闭后再重新绑定', 409, 409);
            }
            $secret = Totp::generateSecret();
            $siteTitle = SiteSettings::title(Database::connection());
            if ($siteTitle === '') {
                Response::error('系统网站名称未配置，请先在系统设置中填写', 409, 409);
            }
            $uri = Totp::uri($secret, 'u' . (int) ($merchant['id'] ?? 0), $siteTitle);
            Response::success([
                'secret' => $secret,
                'qrcode' => QrCode::svgDataUri($uri),
                'uri' => $uri,
            ]);
        }

        $this->consumeStepUpTicket($request, (int) $merchant['id'], 'mfa.bind');
        if ((int) ($merchant['mfa_enabled'] ?? 0) === 1 && trim((string) ($merchant['mfa_secret'] ?? '')) !== '') {
            Response::error('动态口令已经开启，请先关闭后再重新绑定', 409, 409);
        }
        $secret = strtoupper(trim((string) ($params['secret'] ?? '')));
        $code = trim((string) ($params['code'] ?? $params['totp_code'] ?? $params['captcha_code'] ?? ''));
        if (!Totp::isValidSecret($secret)) {
            Response::error('动态口令密钥格式不正确', 422, 422);
        }
        if (!Totp::verify($secret, $code)) {
            Response::error('动态口令错误，请重新输入', 422, 422);
        }
        $update = Database::connection()->prepare('UPDATE user SET mfa_enabled=1,mfa_secret=:secret WHERE id=:id AND mfa_enabled=0');
        $update->execute([':secret' => $secret, ':id' => (int) $merchant['id']]);
        if ($update->rowCount() === 0) {
            Response::error('动态口令状态已变化，请刷新后重试', 409, 409);
        }
        Response::success(['mfa_enabled' => 1, 'mfa_enable' => 1], '动态口令认证已开启');
    }

    public function unmfa(Request $request): never
    {
        $merchant = $this->merchant($request);
        $this->consumeStepUpTicket($request, (int) $merchant['id'], 'mfa.unbind');
        if ((int) ($merchant['mfa_enabled'] ?? 0) !== 1 || trim((string) ($merchant['mfa_secret'] ?? '')) === '') {
            Response::error('当前账号未开启动态口令', 409, 409);
        }
        $update = Database::connection()->prepare('UPDATE user SET mfa_enabled=0,mfa_secret="" WHERE id=:id AND mfa_enabled=1');
        $update->execute([':id' => (int) $merchant['id']]);
        if ($update->rowCount() === 0) {
            Response::error('动态口令状态已变化，请刷新后重试', 409, 409);
        }
        Response::success(['mfa_enabled' => 0, 'mfa_enable' => 0], '动态口令认证已关闭');
    }

    public function captchaGen(Request $request): never
    {
        $params = $request->all();
        $type = trim((string) ($params['captcha_type'] ?? ''));
        $ticket = trim((string) ($params['ticket'] ?? ''));
        if ($ticket !== '') {
            $row = SecurityTicket::find(Database::connection(), $ticket, SecurityTicket::KIND_LOGIN_MFA);
            if (is_array($row) && (int) ($row['status'] ?? 0) === SecurityTicket::STATUS_PENDING && (int) ($row['expires_at'] ?? 0) >= time()) {
                Response::success([
                    'allow_types' => ['mfa'],
                    'list' => [['captcha_id' => $ticket, 'captcha_type' => 'mfa']],
                ]);
            }
        }
        if ($type === 'mfa') {
            $merchant = $this->merchant($request);
            if ((int) ($merchant['mfa_enabled'] ?? 0) !== 1 || trim((string) ($merchant['mfa_secret'] ?? '')) === '') {
                Response::error('当前账号未开启动态口令', 409, 409);
            }
            $issued = SecurityTicket::create(Database::connection(), SecurityTicket::KIND_STEP_UP, (int) $merchant['id'], 'captcha.mfa', 300);
            Response::success([
                'allow_types' => ['mfa'],
                'list' => [['captcha_id' => $issued['ticket'], 'captcha_type' => 'mfa']],
            ]);
        }
        Response::success(['continue' => true]);
    }

    public function captchaValid(Request $request): never
    {
        $params = $request->all();
        $ticket = trim((string) ($params['captcha_id'] ?? $params['ticket'] ?? ''));
        $code = trim((string) ($params['captcha_code'] ?? $params['code'] ?? ''));
        if ($ticket === '') {
            Response::success(['continue' => true]);
        }
        $db = Database::connection();
        $loginRow = SecurityTicket::find($db, $ticket, SecurityTicket::KIND_LOGIN_MFA);
        if (is_array($loginRow)) {
            try {
                SecurityTicket::requirePending($db, $ticket, SecurityTicket::KIND_LOGIN_MFA);
            } catch (\RuntimeException $exception) {
                Response::error($exception->getMessage(), 401, 401);
            }
            $query = $db->prepare('SELECT * FROM user WHERE id=:id AND status=1 LIMIT 1');
            $query->execute([':id' => (int) $loginRow['uid']]);
            $merchant = $query->fetch();
            if (!is_array($merchant) || (int) ($merchant['mfa_enabled'] ?? 0) !== 1 || trim((string) ($merchant['mfa_secret'] ?? '')) === '') {
                SecurityTicket::mark($db, (int) $loginRow['id'], SecurityTicket::STATUS_BLOCKED);
                Response::error('当前账号未开启动态口令', 401, 401);
            }
            if (!Totp::verify((string) $merchant['mfa_secret'], $code)) {
                SecurityTicket::incrementFailure($db, $loginRow);
                Response::error('动态口令错误', 401, 401);
            }
            Response::success(['ticket' => $ticket, 'captcha_id' => $ticket, 'captcha_code' => $code]);
        }
        $row = SecurityTicket::find($db, $ticket, SecurityTicket::KIND_STEP_UP);
        if (!is_array($row)) {
            Response::error('验证码票据不存在', 401, 401);
        }
        $merchant = $this->merchant($request);
        if ((int) $row['uid'] !== (int) $merchant['id']) {
            Response::error('验证码票据不属于当前账号', 401, 401);
        }
        try {
            SecurityTicket::requirePending($db, $ticket, SecurityTicket::KIND_STEP_UP, (int) $merchant['id']);
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 401, 401);
        }
        if (!Totp::verify((string) $merchant['mfa_secret'], $code)) {
            SecurityTicket::incrementFailure($db, $row);
            Response::error('动态口令错误', 401, 401);
        }
        if (!SecurityTicket::consumePending(
            $db,
            $ticket,
            SecurityTicket::KIND_STEP_UP,
            (int) $merchant['id']
        )) {
            Response::error('安全票据已使用，请重新操作', 401, 401);
        }
        Response::success(['ticket' => $ticket]);
    }

    public function stepUpBegin(Request $request): never
    {
        $merchant = $this->merchant($request);
        $operation = trim((string) ($request->input('operation', '')));
        if ($operation === '') {
            Response::error('安全操作名称不能为空', 422, 422);
        }
        $db = Database::connection();
        $hasTotp = (int) ($merchant['mfa_enabled'] ?? 0) === 1 && trim((string) ($merchant['mfa_secret'] ?? '')) !== '';
        $credentials = $this->passkeyAllowCredentials($db, (int) $merchant['id']);
        if (!$hasTotp && $credentials === []) {
            Response::success([
                'need_step_up' => false,
                'ticket' => '',
                'allow_types' => [],
                'captcha_types' => [],
                'factor_targets' => [
                    'totp' => '动态口令',
                    'passkey' => '通行密钥',
                    'password' => '当前登录密码',
                ],
                'expire_time' => 0,
                'options' => ['operation' => $operation],
            ]);
        }

        $ticket = SecurityTicket::create($db, SecurityTicket::KIND_STEP_UP, (int) $merchant['id'], $operation, 300);
        $allow = [];
        $options = ['operation' => $operation];
        if ($hasTotp) {
            $allow[] = 'totp';
        }
        if ($credentials !== []) {
            $allow[] = 'passkey';
            $challenge = WebAuthn::encode(random_bytes(32));
            $rpId = $this->webAuthnRpId($request);
            $origin = $this->webAuthnOrigin($request);
            $now = time();
            $insert = $db->prepare(
                'INSERT INTO webauthn_challenge (ticket,uid,purpose,challenge,rp_id,origin,status,expires_at,created_at,updated_at) '
                . 'VALUES (:ticket,:uid,"step_up",:challenge,:rp_id,:origin,1,:expires_at,:created_at,:updated_at)'
            );
            $insert->execute([
                ':ticket' => $ticket['ticket'],
                ':uid' => (int) $merchant['id'],
                ':challenge' => $challenge,
                ':rp_id' => $rpId,
                ':origin' => $origin,
                ':expires_at' => $ticket['expire_time'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $options['publicKey'] = [
                'challenge' => $challenge,
                'rpId' => $rpId,
                'timeout' => 300000,
                'userVerification' => 'required',
                'allowCredentials' => $credentials,
            ];
        }
        if (!$hasTotp) {
            $allow[] = 'password';
        }
        Response::success([
            'need_step_up' => true,
            'ticket' => $ticket['ticket'],
            'allow_types' => $allow,
            'captcha_types' => $allow,
            'factor_targets' => ['totp' => '动态口令', 'passkey' => '通行密钥', 'password' => '当前登录密码'],
            'expire_time' => $ticket['expire_time'],
            'options' => $options,
        ]);
    }

    public function stepUpFinish(Request $request): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $ticket = trim((string) ($params['ticket'] ?? ''));
        $type = trim((string) ($params['type'] ?? ''));
        if ($ticket === '' || $type === '') {
            Response::error('安全票据和验证类型不能为空', 422, 422);
        }
        $db = Database::connection();
        try {
            $row = SecurityTicket::requirePending($db, $ticket, SecurityTicket::KIND_STEP_UP, (int) $merchant['id']);
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 401, 401);
        }

        $passed = false;
        if ($type === 'totp') {
            $code = trim((string) ($params['totp_code'] ?? $params['captcha_code'] ?? ''));
            $passed = (int) ($merchant['mfa_enabled'] ?? 0) === 1
                && trim((string) ($merchant['mfa_secret'] ?? '')) !== ''
                && Totp::verify((string) $merchant['mfa_secret'], $code);
        } elseif ($type === 'passkey') {
            $passed = $this->verifyPasskeyAssertion($db, $request, (int) $merchant['id'], 'step_up', $ticket, true);
        } elseif ($type === 'password') {
            $passed = $this->passwordMatches((string) ($params['password'] ?? ''), (string) ($merchant['password'] ?? ''));
        } else {
            Response::error('当前仅支持动态口令、通行密钥或当前密码验证', 422, 422);
        }
        if (!$passed) {
            SecurityTicket::incrementFailure($db, $row);
            $message = $type === 'password' ? '当前密码错误' : ($type === 'passkey' ? '通行密钥验证失败' : '动态口令错误');
            Response::error($message, 401, 401);
        }
        SecurityTicket::mark($db, (int) $row['id'], SecurityTicket::STATUS_VERIFIED);
        Response::success(['ticket' => $ticket], '二次认证成功');
    }

    public function stepUpSendCode(Request $request): never
    {
        $this->merchant($request);
        Response::error('本地版未配置邮件或短信验证码服务', 501, 501);
    }

    public function webSocketTicket(Request $request): never
    {
        $this->merchant($request);
        Response::success(['enabled' => false, 'ticket' => '', 'expire_time' => 0], '本地版未启用实时推送');
    }

    public function bindWechat(Request $request): never
    {
        $this->merchant($request);
        Response::error('本地版未配置微信开放平台', 501, 501);
    }

    public function unbindWechat(Request $request): never
    {
        $this->merchant($request);
        Response::error('本地版未配置微信开放平台', 501, 501);
    }

    public function bindTelegram(Request $request): never
    {
        $this->merchant($request);
        Response::error('本地版未配置 Telegram 登录服务', 501, 501);
    }

    public function unbindTelegram(Request $request): never
    {
        $this->merchant($request);
        Response::error('本地版未配置 Telegram 登录服务', 501, 501);
    }

    public function connectChannels(Request $request): never
    {
        $this->merchant($request);
        $rows = Database::connection()->query('SELECT id,name,provider,account,options,status FROM third_account WHERE status=1 ORDER BY id ASC')->fetchAll();
        $channels = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $options = $this->jsonArray($row['options'] ?? '{}');
            $channels[] = [
                'code' => (string) $row['provider'],
                'name' => (string) $row['name'],
                'type' => 'link',
                'enabled' => trim((string) ($options['client_id'] ?? '')) !== ''
                    && trim((string) ($options['authorize_url'] ?? '')) !== '',
            ];
        }
        Response::success(['list' => $channels, 'count' => count($channels), 'total' => count($channels)]);
    }

    public function connectList(Request $request): never
    {
        $merchant = $this->merchant($request);
        $query = Database::connection()->prepare(
            'SELECT id,provider,provider_uid,nickname,avatar,expires_at,status,created_at,updated_at '
            . 'FROM merchant_connect WHERE uid=:uid AND status=1 ORDER BY id DESC'
        );
        $query->execute([':uid' => (int) $merchant['id']]);
        Response::success(['list' => $query->fetchAll()]);
    }

    public function connectUrl(Request $request): never
    {
        $this->merchant($request);
        Response::error('第三方登录授权地址未配置，不能发起绑定', 501, 501);
    }

    public function connectQrcode(Request $request): never
    {
        $this->merchant($request);
        Response::error('第三方扫码绑定服务未配置，不能发起绑定', 501, 501);
    }

    public function connectAction(Request $request): never
    {
        $merchant = $this->merchant($request);
        $provider = trim((string) ($request->input('provider') ?? $request->input('channel', '')));
        if ($provider === '') {
            Response::error('第三方服务商不能为空', 422, 422);
        }
        if (str_ends_with($request->path(), '/unbind')) {
            $this->consumeStepUpTicket($request, (int) $merchant['id'], 'connect.unbind');
            $delete = Database::connection()->prepare('UPDATE merchant_connect SET status=0,updated_at=:updated_at WHERE uid=:uid AND provider=:provider AND status=1');
            $delete->execute([':uid' => (int) $merchant['id'], ':provider' => $provider, ':updated_at' => time()]);
            if ($delete->rowCount() === 0) {
                Response::error('第三方账号未绑定', 404, 404);
            }
            Response::success(null, '第三方账号已解绑');
        }
        Response::error('第三方账号绑定必须通过服务端 OAuth 回调验证，当前未配置该服务，不能绑定', 501, 501);
    }

    public function appLoginTicket(Request $request, bool $check = false): never
    {
        if ($check) {
            $merchant = $this->merchant($request);
            $ticket = trim((string) $request->input('ticket', ''));
            if ($ticket === '') {
                Response::error('扫码票据不能为空', 422, 422);
            }
            $query = Database::connection()->prepare('SELECT * FROM app_login_ticket WHERE ticket=:ticket AND kind="app_bind" AND uid=:uid AND status=1 LIMIT 1');
            $query->execute([':ticket' => $ticket, ':uid' => (int) $merchant['id']]);
            $row = $query->fetch();
            if (!is_array($row) || (int) $row['expires_at'] < time()) {
                Response::error('扫码票据不存在或已过期', 401, 401);
            }
            $update = Database::connection()->prepare('UPDATE app_login_ticket SET status=2,confirmed_at=:confirmed_at,updated_at=:updated_at WHERE id=:id AND status=1 AND expires_at>=:expires_at');
            $update->execute([':confirmed_at' => time(), ':updated_at' => time(), ':id' => (int) $row['id'], ':expires_at' => time()]);
            if ($update->rowCount() !== 1) {
                Response::error('扫码票据已被处理，请重新生成', 409, 409);
            }
            Response::success(null, '扫码登录已确认');
        }
        $merchant = $this->merchant($request);
        $now = time();
        $ticket = bin2hex(random_bytes(32));
        $expire = $now + 180;
        $insert = Database::connection()->prepare(
            'INSERT INTO app_login_ticket (ticket,kind,uid,status,options,expires_at,created_at,updated_at) '
            . 'VALUES (:ticket,"app_bind",:uid,1,"{}",:expires_at,:created_at,:updated_at)'
        );
        $insert->execute([
            ':ticket' => $ticket,
            ':uid' => (int) $merchant['id'],
            ':expires_at' => $expire,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        Response::success(['ticket' => $ticket, 'expire_time' => $expire]);
    }

    public function passkey(Request $request, string $action): never
    {
        $merchant = $this->merchant($request);
        $db = Database::connection();
        if ($action === 'list') {
            $query = $db->prepare(
                'SELECT id,credential_id,device_name,sign_count,aaguid,last_used_at,created_at,updated_at '
                . 'FROM passkey_credential WHERE uid=:uid AND status=1 ORDER BY id DESC'
            );
            $query->execute([':uid' => (int) $merchant['id']]);
            $rows = $query->fetchAll();
            foreach ($rows as &$row) {
                $row['platform'] = '';
                $row['browser'] = '';
                $row['device_type'] = '';
            }
            unset($row);
            Response::success(['list' => $rows, 'count' => count($rows), 'total' => count($rows)]);
        }
        if ($action === 'begin') {
            $this->passkeyBegin($request, $merchant);
        }
        if ($action === 'finish') {
            $this->passkeyFinish($request, $merchant);
        }
        if ($action === 'rename') {
            $id = (int) $request->input('id', 0);
            $name = trim((string) ($request->input('name') ?? $request->input('device_name', '')));
            if ($id <= 0 || $name === '' || mb_strlen($name) > 50) {
                Response::error('通行密钥 ID 或名称无效', 422, 422);
            }
            $update = $db->prepare('UPDATE passkey_credential SET device_name=:device_name,updated_at=:updated_at WHERE id=:id AND uid=:uid AND status=1');
            $update->execute([':device_name' => $name, ':updated_at' => time(), ':id' => $id, ':uid' => (int) $merchant['id']]);
            if ($update->rowCount() === 0) {
                Response::error('通行密钥不存在', 404, 404);
            }
            Response::success(null, '通行密钥名称已修改');
        }
        if ($action === 'remove') {
            $this->consumeStepUpTicket($request, (int) $merchant['id'], 'passkey.remove');
            $id = (int) $request->input('id', 0);
            $update = $db->prepare('UPDATE passkey_credential SET status=0,updated_at=:updated_at WHERE id=:id AND uid=:uid AND status=1');
            $update->execute([':updated_at' => time(), ':id' => $id, ':uid' => (int) $merchant['id']]);
            if ($update->rowCount() === 0) {
                Response::error('通行密钥不存在', 404, 404);
            }
            Response::success(null, '通行密钥已删除');
        }
        if ($action === 'auth-begin') {
            $this->passkeyAuthBegin($request, $merchant);
        }
        if ($action === 'auth-finish') {
            $this->passkeyAuthFinish($request, $merchant);
        }
        Response::error('不支持的通行密钥操作', 400, 400);
    }

    public function rsaKey(Request $request, string $action): never
    {
        $merchant = $this->merchant($request);
        $db = Database::connection();
        $query = $db->prepare('SELECT * FROM merchant_rsa_key WHERE uid=:uid AND status=1 LIMIT 1');
        $query->execute([':uid' => (int) $merchant['id']]);
        $stored = $query->fetch();
        if ($action === 'info') {
            if (is_array($stored)) {
                $this->consumeStepUpTicket($request, (int) $merchant['id'], 'rsa.view');
            }
            $platformPublic = trim((string) getenv('XARR_PLATFORM_RSA_PUBLIC_KEY'));
            Response::success([
                'uid' => (int) $merchant['id'],
                'enabled' => is_array($stored),
                'has_key' => is_array($stored),
                'status' => is_array($stored) ? (int) $stored['status'] : 0,
                'algorithm' => 'RSA2',
                'merchant_public_key' => is_array($stored) ? (string) $stored['public_key'] : '',
                'platform_public_key' => $platformPublic,
            ]);
        }
        $this->consumeStepUpTicket($request, (int) $merchant['id'], 'rsa.reset');
        if (!function_exists('openssl_pkey_new')) {
            Response::error('PHP OpenSSL 扩展未启用', 501, 501);
        }
        $encryptionKey = trim((string) getenv('XARR_RSA_ENCRYPTION_KEY'));
        $platformPublic = trim((string) getenv('XARR_PLATFORM_RSA_PUBLIC_KEY'));
        if ($encryptionKey === '' || $platformPublic === '') {
            Response::error('RSA 加密密钥或平台公钥未配置，不能生成商户 RSA 密钥', 501, 501);
        }
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($resource === false || !openssl_pkey_export($resource, $privateKey)) {
            Response::error('RSA 密钥生成失败', 500, 500);
        }
        $details = openssl_pkey_get_details($resource);
        $publicKey = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($publicKey === '') {
            Response::error('RSA 公钥生成失败', 500, 500);
        }
        $key = hash('sha256', $encryptionKey, true);
        $nonce = random_bytes(12);
        $ciphertext = openssl_encrypt($privateKey, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false || !is_string($tag)) {
            Response::error('RSA 私钥加密保存失败', 500, 500);
        }
        $now = time();
        $values = [
            ':uid' => (int) $merchant['id'],
            ':public_key' => $publicKey,
            ':private_ciphertext' => base64_encode($ciphertext),
            ':private_nonce' => base64_encode($nonce),
            ':private_tag' => base64_encode($tag),
            ':updated_at' => $now,
            ':created_at' => $now,
        ];
        if (is_array($stored)) {
            $save = $db->prepare(
                'UPDATE merchant_rsa_key SET public_key=:public_key,private_ciphertext=:private_ciphertext,private_nonce=:private_nonce,private_tag=:private_tag,status=1,updated_at=:updated_at WHERE uid=:uid'
            );
            $save->execute($values);
        } else {
            $save = $db->prepare(
                'INSERT INTO merchant_rsa_key (uid,public_key,private_ciphertext,private_nonce,private_tag,status,created_at,updated_at) VALUES (:uid,:public_key,:private_ciphertext,:private_nonce,:private_tag,1,:created_at,:updated_at)'
            );
            $save->execute($values);
        }
        Response::success([
            'algorithm' => 'RSA2',
            'merchant_private_key' => $privateKey,
            'merchant_public_key' => $publicKey,
            'platform_public_key' => $platformPublic,
        ], 'RSA 密钥已生成，商户私钥仅本次返回');
    }

    public function ticketsStatus(Request $request): never
    {
        $ticket = trim((string) $request->input('ticket', ''));
        if ($ticket === '') {
            Response::error('票据不能为空', 422, 422);
        }
        $db = Database::connection();
        $query = $db->prepare('SELECT * FROM app_login_ticket WHERE ticket=:ticket LIMIT 1');
        $query->execute([':ticket' => $ticket]);
        $row = $query->fetch();
        if (!is_array($row)) {
            Response::error('票据不存在', 404, 404);
        }
        if ((int) $row['expires_at'] < time() && (int) $row['status'] === 1) {
            $db->prepare('UPDATE app_login_ticket SET status=5,updated_at=:updated_at WHERE id=:id AND status=1')
                ->execute([':updated_at' => time(), ':id' => (int) $row['id']]);
            $row['status'] = 5;
        }
        $token = trim((string) ($request->input('token') ?? ''));
        if ($token !== '' && (int) $row['status'] === 1 && (string) ($row['kind'] ?? '') === 'app_login') {
            $merchant = $db->prepare('SELECT id FROM user WHERE token=:token AND status=1 LIMIT 1');
            $merchant->execute([':token' => $token]);
            $uid = (int) ($merchant->fetchColumn() ?: 0);
            if ($uid > 0) {
                $confirm = $db->prepare(
                    'UPDATE app_login_ticket SET uid=:uid,status=2,confirmed_at=:confirmed_at,updated_at=:updated_at '
                    . 'WHERE id=:id AND status=1 AND expires_at>=:expires_at'
                );
                $confirm->execute([
                    ':uid' => $uid,
                    ':confirmed_at' => time(),
                    ':updated_at' => time(),
                    ':id' => (int) $row['id'],
                    ':expires_at' => time(),
                ]);
                if ($confirm->rowCount() === 1) {
                    $row['uid'] = $uid;
                    $row['status'] = 2;
                }
            }
        }
        Response::success([
            'ticket' => $ticket,
            'status' => (int) $row['status'],
            'options' => $this->jsonArray($row['options'] ?? '{}'),
            'expire_time' => (int) $row['expires_at'],
        ]);
    }

    public function ticketsData(Request $request): never
    {
        $ticket = trim((string) $request->input('ticket', ''));
        if ($ticket === '') {
            Response::error('票据不能为空', 422, 422);
        }
        $db = Database::connection();
        $query = $db->prepare('SELECT * FROM app_login_ticket WHERE ticket=:ticket LIMIT 1');
        $query->execute([':ticket' => $ticket]);
        $row = $query->fetch();
        if (!is_array($row)) {
            Response::error('票据不存在', 404, 404);
        }
        if ((int) $row['expires_at'] < time() && in_array((int) $row['status'], [1, 2], true)) {
            $db->prepare('UPDATE app_login_ticket SET status=5,updated_at=:updated_at WHERE id=:id AND status IN (1,2)')
                ->execute([':updated_at' => time(), ':id' => (int) $row['id']]);
            $row['status'] = 5;
        }
        $status = (int) $row['status'];
        $kind = (string) ($row['kind'] ?? '');
        if (!in_array($kind, ['app_login', 'admin_login'], true)) {
            Response::success([
                'ticket' => $ticket,
                'kind' => $kind,
                'status' => $status,
                'options' => $this->jsonArray($row['options'] ?? '{}'),
                'expire_time' => (int) $row['expires_at'],
            ]);
        }
        if ($status !== 2) {
            Response::success([
                'ticket' => $ticket,
                'kind' => $kind,
                'status' => $status,
                'options' => $this->jsonArray($row['options'] ?? '{}'),
                'expire_time' => (int) $row['expires_at'],
            ]);
        }
        $consume = $db->prepare('UPDATE app_login_ticket SET status=3,updated_at=:updated_at WHERE id=:id AND status=2');
        $consume->execute([':updated_at' => time(), ':id' => (int) $row['id']]);
        if ($consume->rowCount() !== 1) {
            Response::error('票据已使用，请重新操作', 409, 409);
        }
        $uid = (int) $row['uid'];
        $user = $db->prepare('SELECT id,username FROM user WHERE id=:id AND status=1 LIMIT 1');
        $user->execute([':id' => $uid]);
        $merchant = $user->fetch();
        if (!is_array($merchant)) {
            Response::error('票据对应商户不存在或已停用', 401, 401);
        }
        $token = bin2hex(random_bytes(32));
        $db->prepare('UPDATE user SET token=:token WHERE id=:id')->execute([':token' => $token, ':id' => $uid]);
        Response::success(['ticket' => $ticket, 'kind' => $kind, 'status' => 3, 'token' => $token], '登录成功');
    }

    public function workOrderCategories(Request $request): never
    {
        $this->merchant($request);
        $rows = Database::connection()->query('SELECT id,name,status,sort FROM work_order_category WHERE status=1 ORDER BY sort ASC,id ASC')->fetchAll();
        Response::success(['list' => $rows, 'count' => count($rows), 'total' => count($rows)]);
    }

    public function workOrderList(Request $request): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $query = Database::connection()->prepare('SELECT w.*, c.name AS cate_name FROM work_order w LEFT JOIN work_order_category c ON c.id=w.category_id WHERE w.uid=:uid ORDER BY w.id DESC');
        $query->execute([':uid' => (int) $merchant['id']]);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $row['attachments'] = $this->jsonArray($row['attachments'] ?? '[]');
        }
        unset($row);
        Response::success($this->paginate($rows, $params));
    }

    public function workOrderCreate(Request $request): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $title = trim((string) ($params['title'] ?? ''));
        $content = trim((string) ($params['content'] ?? ''));
        if ($title === '' || $content === '') {
            Response::error('工单标题和内容不能为空', 422, 422);
        }
        $categoryId = (int) ($params['category_id'] ?? $params['cate_id'] ?? 0);
        $category = Database::connection()->prepare('SELECT id FROM work_order_category WHERE id=:id AND status=1 LIMIT 1');
        $category->execute([':id' => $categoryId]);
        if (!$category->fetchColumn()) {
            Response::error('请选择有效的工单分类', 422, 422);
        }
        $attachments = $this->workOrderAttachments($params['attachments'] ?? [], (int) $merchant['id']);
        $now = time();
        $insert = Database::connection()->prepare('INSERT INTO work_order (uid,category_id,title,content,attachments,status,priority,created_at,updated_at) VALUES (:uid,:category_id,:title,:content,:attachments,1,:priority,:created_at,:updated_at)');
        $insert->execute([
            ':uid' => (int) $merchant['id'],
            ':category_id' => $categoryId,
            ':title' => $title,
            ':content' => $content,
            ':attachments' => json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':priority' => 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        Response::success(['id' => (int) Database::connection()->lastInsertId()], '工单已提交');
    }

    public function workOrderReplies(Request $request): never
    {
        $merchant = $this->merchant($request);
        $pid = (int) ($request->input('pid') ?? $request->input('id') ?? 0);
        $this->ownedWorkOrder($pid, (int) $merchant['id']);
        $db = Database::connection();
        $infoQuery = $db->prepare('SELECT w.id,w.title,w.status,w.created_at,c.name AS cate_name FROM work_order w LEFT JOIN work_order_category c ON c.id=w.category_id WHERE w.id=:id AND w.uid=:uid LIMIT 1');
        $infoQuery->execute([':id' => $pid, ':uid' => (int) $merchant['id']]);
        $info = $infoQuery->fetch();
        $replyTime = $db->prepare('SELECT MAX(created_at) FROM work_order_reply WHERE pid=:pid AND status=1');
        $replyTime->execute([':pid' => $pid]);
        $info['reply_time'] = (int) ($replyTime->fetchColumn() ?: 0);
        $query = Database::connection()->prepare('SELECT * FROM work_order_reply WHERE pid=:pid AND status=1 ORDER BY id ASC');
        $query->execute([':pid' => $pid]);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $row['attachments'] = $this->jsonArray($row['attachments'] ?? '[]');
        }
        unset($row);
        Response::success(['list' => $rows, 'count' => count($rows), 'total' => count($rows), 'info' => $info]);
    }

    public function workOrderReply(Request $request): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $pid = (int) ($params['pid'] ?? $params['id'] ?? 0);
        $this->ownedWorkOrder($pid, (int) $merchant['id']);
        $content = trim((string) ($params['content'] ?? ''));
        if ($content === '') {
            Response::error('回复内容不能为空', 422, 422);
        }
        $attachments = $this->workOrderAttachments($params['attachments'] ?? [], (int) $merchant['id']);
        $now = time();
        $insert = Database::connection()->prepare('INSERT INTO work_order_reply (pid,uid,content,attachments,status,created_at,updated_at) VALUES (:pid,:uid,:content,:attachments,1,:created_at,:updated_at)');
        $insert->execute([
            ':pid' => $pid,
            ':uid' => (int) $merchant['id'],
            ':content' => $content,
            ':attachments' => json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $update = Database::connection()->prepare('UPDATE work_order SET status=1,updated_at=:updated_at,closed_at=NULL WHERE id=:id AND uid=:uid');
        $update->execute([':updated_at' => $now, ':id' => $pid, ':uid' => (int) $merchant['id']]);
        Response::success(['id' => (int) Database::connection()->lastInsertId()], '回复已提交');
    }

    public function pollingList(Request $request): never
    {
        $merchant = $this->merchant($request);
        $query = Database::connection()->prepare('SELECT id,name,pay_type,channel_code,account_ids,status,sort,uid FROM polling_rule WHERE (uid=:uid OR (uid=0 AND status=1)) ORDER BY sort ASC,id ASC');
        $query->execute([':uid' => (int) $merchant['id']]);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $row['account_ids'] = $this->jsonArray($row['account_ids'] ?? '[]');
            $row['editable'] = (int) $row['uid'] === (int) $merchant['id'];
            unset($row['uid']);
        }
        unset($row);
        Response::success(['list' => $rows, 'count' => count($rows), 'total' => count($rows)]);
    }

    public function pollingWrite(Request $request, ?int $id = null): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            Response::error('轮询池名称不能为空', 422, 422);
        }
        $db = Database::connection();
        $accountIds = $this->validateMerchantAccountIds(
            $db,
            $params['account_ids'] ?? [],
            (int) $merchant['id']
        );
        $values = [
            ':uid' => (int) $merchant['id'],
            ':name' => $name,
            ':pay_type' => trim((string) ($params['pay_type'] ?? '')),
            ':channel_code' => trim((string) ($params['channel_code'] ?? '')),
            ':account_ids' => json_encode($accountIds, JSON_UNESCAPED_UNICODE),
            ':status' => $this->status($params['status'] ?? 1),
            ':sort' => (int) ($params['sort'] ?? 50),
            ':updated_at' => time(),
        ];
        if ($id !== null) {
            $query = $db->prepare('UPDATE polling_rule SET name=:name,pay_type=:pay_type,channel_code=:channel_code,account_ids=:account_ids,status=:status,sort=:sort,updated_at=:updated_at WHERE id=:id AND uid=:uid');
            $query->execute($values + [':id' => $id]);
            if ($query->rowCount() === 0) {
                Response::error('轮询池不存在或不是商户自有配置', 404, 404);
            }
            Response::success(['id' => $id], '轮询池已保存');
        }
        $now = time();
        $query = $db->prepare('INSERT INTO polling_rule (uid,name,pay_type,channel_code,account_ids,status,sort,created_at,updated_at) VALUES (:uid,:name,:pay_type,:channel_code,:account_ids,:status,:sort,:created_at,:updated_at)');
        $query->execute($values + [':created_at' => $now]);
        Response::success(['id' => (int) $db->lastInsertId()], '轮询池已创建');
    }

    public function pollingRemove(Request $request): never
    {
        $merchant = $this->merchant($request);
        $query = Database::connection()->prepare('DELETE FROM polling_rule WHERE id=:id AND uid=:uid');
        $query->execute([':id' => (int) $request->input('id', 0), ':uid' => (int) $merchant['id']]);
        if ($query->rowCount() === 0) {
            Response::error('轮询池不存在或不是商户自有配置', 404, 404);
        }
        Response::success(null, '轮询池已删除');
    }

    public function pollingSwitch(Request $request): never
    {
        $merchant = $this->merchant($request);
        $query = Database::connection()->prepare('UPDATE polling_rule SET status=:status,updated_at=:updated_at WHERE id=:id AND uid=:uid');
        $query->execute([
            ':status' => $this->status($request->input('status')),
            ':updated_at' => time(),
            ':id' => (int) $request->input('id', 0),
            ':uid' => (int) $merchant['id'],
        ]);
        if ($query->rowCount() === 0) {
            Response::error('轮询池不存在或不是商户自有配置', 404, 404);
        }
        Response::success(null, '轮询池状态已更新');
    }

    public function pollingChannel(Request $request): never
    {
        $merchant = $this->merchant($request);
        $ruleId = (int) $request->input('polling_id', $request->input('id', 0));
        if ($ruleId <= 0) {
            Response::error('轮询池 ID 无效', 422, 422);
        }
        $db = Database::connection();
        $ruleQuery = $db->prepare('SELECT pay_type,account_ids FROM polling_rule WHERE id=:id AND (uid=:uid OR (uid=0 AND status=1)) LIMIT 1');
        $ruleQuery->execute([':id' => $ruleId, ':uid' => (int) $merchant['id']]);
        $rule = $ruleQuery->fetch();
        if (!is_array($rule)) {
            Response::error('轮询池不存在或无权查看', 404, 404);
        }

        $configured = $this->jsonArray($rule['account_ids'] ?? '[]');
        $normalized = [];
        foreach ($configured as $item) {
            if (is_array($item)) {
                $accountId = (int) ($item['account_id'] ?? $item['id'] ?? 0);
                if ($accountId <= 0) {
                    continue;
                }
                $normalized[$accountId] = [
                    'account_id' => $accountId,
                    'weight' => max((int) ($item['weight'] ?? 50), 1),
                    'pay_count' => max((int) ($item['pay_count'] ?? 0), 0),
                ];
                continue;
            }
            $accountId = (int) $item;
            if ($accountId > 0) {
                $normalized[$accountId] = [
                    'account_id' => $accountId,
                    'weight' => 50,
                    'pay_count' => 0,
                ];
            }
        }
        if ($normalized === []) {
            Response::success([]);
        }

        $ids = array_keys($normalized);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $accounts = $db->prepare(
            'SELECT id,pay_type,channel_code,name,account,account_type,status,sort '
            . 'FROM pay_account WHERE uid=? AND id IN (' . $placeholders . ')'
        );
        $accounts->execute(array_merge([(int) $merchant['id']], $ids));
        $available = [];
        foreach ($accounts->fetchAll() as $account) {
            if (!is_array($account)) {
                continue;
            }
            $accountId = (int) ($account['id'] ?? 0);
            if ($accountId <= 0 || !isset($normalized[$accountId])) {
                continue;
            }
            $available[] = $normalized[$accountId] + [
                'pay_type' => (string) ($rule['pay_type'] ?? $account['pay_type'] ?? ''),
            ];
        }
        usort($available, static function (array $left, array $right) use ($ids): int {
            $leftIndex = array_search((int) $left['account_id'], $ids, true);
            $rightIndex = array_search((int) $right['account_id'], $ids, true);
            return ((int) $leftIndex) <=> ((int) $rightIndex);
        });
        Response::success($available);
    }

    public function pollingSaveChannel(Request $request): never
    {
        $merchant = $this->merchant($request);
        $ruleId = (int) $request->input('id', $request->input('polling_id', 0));
        $db = Database::connection();
        $owner = $db->prepare('SELECT id FROM polling_rule WHERE id=:id AND uid=:uid LIMIT 1');
        $owner->execute([':id' => $ruleId, ':uid' => (int) $merchant['id']]);
        if (!is_array($owner->fetch())) {
            Response::error('轮询池不存在或不是商户自有配置', 404, 404);
        }
        $rawChannels = $request->input('channels', null);
        if (is_array($rawChannels)) {
            $channelConfig = [];
            foreach ($rawChannels as $channel) {
                if (!is_array($channel)) {
                    continue;
                }
                $accountId = (int) ($channel['account_id'] ?? $channel['id'] ?? 0);
                if ($accountId <= 0) {
                    continue;
                }
                $channelConfig[] = [
                    'account_id' => $accountId,
                    'weight' => max((int) ($channel['weight'] ?? 50), 1),
                    'pay_count' => max((int) ($channel['pay_count'] ?? 0), 0),
                ];
            }
            $validatedIds = $this->validateMerchantAccountIds(
                $db,
                array_map(static fn (array $channel): int => $channel['account_id'], $channelConfig),
                (int) $merchant['id']
            );
            $byId = [];
            foreach ($channelConfig as $channel) {
                $byId[(int) $channel['account_id']] = $channel;
            }
            $requestedIds = array_values(array_unique(array_map(
                static fn (array $channel): int => $channel['account_id'],
                $channelConfig
            )));
            $accountIds = array_values(array_map(
                static fn (int $accountId): array => $byId[$accountId],
                $requestedIds
            ));
        } else {
            $accountIds = $this->validateMerchantAccountIds(
                $db,
                $request->input('account_ids', []),
                (int) $merchant['id']
            );
        }
        $query = $db->prepare('UPDATE polling_rule SET account_ids=:account_ids,updated_at=:updated_at WHERE id=:id AND uid=:uid');
        $query->execute([
            ':account_ids' => json_encode($accountIds, JSON_UNESCAPED_UNICODE),
            ':updated_at' => time(),
            ':id' => $ruleId,
            ':uid' => (int) $merchant['id'],
        ]);
        Response::success(['id' => $ruleId], '轮询通道已保存');
    }

    public function paySettings(Request $request, bool $write = false): never
    {
        $merchant = $this->merchant($request);
        $settings = $this->settings($merchant);
        $pay = is_array($settings['pay'] ?? null) ? $settings['pay'] : [];
        $defaults = [
            'user_pay_types' => '',
            'pay_audio_enable' => '2',
            'pay_audio_content' => '',
            'pay_notify_method' => '',
            'pay_payed_wait_time' => 3,
            'pay_notify_epay_method' => '',
            'pay_notify_filter_subject' => '',
            'user_tip_min_balance' => 0,
            'pay_tip' => '',
            'pay_random_amount_min' => 0,
            'pay_random_amount_max' => 0,
            'pay_epay_always_payurl' => '2',
            'pay_epay_api_returl' => '1',
            'pay_cashier_subject' => '',
            'pay_notify_log_retention_days' => 30,
        ];
        if (!$write) {
            Response::success($pay + $defaults);
        }
        $params = $request->all();
        $settings['pay'] = array_merge($defaults, $pay, $params);
        $this->saveSettings((int) $merchant['id'], $settings);
        Response::success($settings['pay'], '支付设置已保存');
    }

    public function payTemplates(Request $request): never
    {
        $this->merchant($request);
        $query = Database::connection()->query('SELECT id,name,uri,config,status,sort FROM qrcode_template WHERE status=1 ORDER BY sort ASC,id DESC');
        $rows = $query->fetchAll();
        Response::success(['list' => array_merge([[
            'id' => 'default',
            'name' => '默认模板',
            'uri' => '/admin/static/images/template-pay-default.png',
            'img_width' => 900,
            'img_height' => 1200,
            'qr_left' => 140,
            'qr_top' => 280,
            'qr_width' => 620,
            'qr_height' => 620,
            'rec_name_left' => 450,
            'rec_name_top' => 220,
            'config' => [],
            'status' => 1,
        ]], is_array($rows) ? $rows : [])]);
    }

    public function setPayTemplate(Request $request): never
    {
        $merchant = $this->merchant($request);
        $settings = $this->settings($merchant);
        $settings['pay'] = is_array($settings['pay'] ?? null) ? $settings['pay'] : [];
        $settings['pay']['pay_template'] = trim((string) ($request->input('template_id') ?? $request->input('id') ?? 'default'));
        $this->saveSettings((int) $merchant['id'], $settings);
        Response::success(['id' => $settings['pay']['pay_template']], '支付模板已保存');
    }

    public function cashierUri(Request $request): never
    {
        $merchant = $this->merchant($request);
        $key = (string) ($merchant['app_secret'] ?? '');
        Response::success([
            'uri' => '/cashier/' . rawurlencode($key),
            'url' => '/cashier/' . rawurlencode($key),
            'key' => $key,
        ]);
    }

    public function mealList(Request $request): never
    {
        $this->merchant($request);
        $query = Database::connection()->query('SELECT id,name,price,days,description,status FROM meal WHERE status=1 ORDER BY id ASC');
        $rows = $query->fetchAll();
        Response::success(['list' => is_array($rows) ? $rows : [], 'count' => count($rows), 'total' => count($rows)]);
    }

    public function mealBuy(Request $request): never
    {
        $merchant = $this->merchant($request);
        $id = (int) $request->input('id', $request->input('meal_id', 0));
        $query = Database::connection()->prepare('SELECT * FROM meal WHERE id=:id AND status=1 LIMIT 1');
        $query->execute([':id' => $id]);
        $meal = $query->fetch();
        if (!is_array($meal)) {
            Response::error('套餐不存在或已停用', 404, 404);
        }
        $price = (int) ($meal['price'] ?? 0);
        $db = Database::connection();
        $balanceQuery = $db->prepare('SELECT balance,settings FROM user WHERE id=:id LIMIT 1');
        $balanceQuery->execute([':id' => (int) $merchant['id']]);
        $current = $balanceQuery->fetch();
        if (!is_array($current) || (int) $current['balance'] < $price) {
            Response::error('商户余额不足', 422, 422);
        }
        $settings = $this->settings($current);
        $settings['meal'] = [
            'id' => $id,
            'name' => (string) $meal['name'],
            'expires_at' => time() + max((int) ($meal['days'] ?? 0), 0) * 86400,
        ];
        $db->beginTransaction();
        try {
            $update = $db->prepare('UPDATE user SET balance=:balance,settings=:settings WHERE id=:id');
            $update->execute([
                ':balance' => (int) $current['balance'] - $price,
                ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => (int) $merchant['id'],
            ]);
            $log = $db->prepare('INSERT INTO balance_log (uid,type,amount,before_balance,after_balance,remark,created_at) VALUES (:uid,:type,:amount,:before_balance,:after_balance,:remark,:created_at)');
            $log->execute([
                ':uid' => (int) $merchant['id'],
                ':type' => 'meal',
                ':amount' => -$price,
                ':before_balance' => (int) $current['balance'],
                ':after_balance' => (int) $current['balance'] - $price,
                ':remark' => '购买套餐 ' . (string) $meal['name'],
                ':created_at' => time(),
            ]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        Response::success(['meal' => $settings['meal'], 'balance' => (int) $current['balance'] - $price], '套餐购买成功');
    }

    public function mealAutoRenewalSwitch(Request $request): never
    {
        $merchant = $this->merchant($request);
        $settings = $this->settings($merchant);
        $meal = is_array($settings['meal'] ?? null) ? $settings['meal'] : [];
        $mealId = (int) ($meal['id'] ?? 0);
        if ($mealId <= 0) {
            Response::error('当前商户没有可续费套餐', 422, 422);
        }
        $value = filter_var($request->input('meal_auto_renewal') ?? $request->input('auto_renewal'), FILTER_VALIDATE_INT);
        if ($value === false || !in_array((int) $value, [0, 1, 2], true)) {
            Response::error('自动续费状态无效', 422, 422);
        }
        $enabled = (int) $value === 1 ? 1 : 0;
        $meal['auto_renewal'] = $enabled;
        $settings['meal'] = $meal;
        $this->saveSettings((int) $merchant['id'], $settings);
        Response::success([
            'meal_id' => $mealId,
            'meal_auto_renewal' => $enabled,
        ], $enabled === 1 ? '自动续费已开启' : '自动续费已关闭');
    }

    public function pushEvents(Request $request): never
    {
        $merchant = $this->merchant($request);
        $query = Database::connection()->prepare('SELECT id,type,message,created_at FROM security_event WHERE uid=:uid ORDER BY id DESC LIMIT 100');
        $query->execute([':uid' => (int) $merchant['id']]);
        $rows = $query->fetchAll();
        Response::success(['list' => is_array($rows) ? $rows : [], 'count' => count($rows), 'total' => count($rows)]);
    }

    public function eventNotice(Request $request, bool $write = false): never
    {
        $merchant = $this->merchant($request);
        $settings = $this->settings($merchant);
        if (!$write) {
            Response::success(is_array($settings['event_notice'] ?? null) ? $settings['event_notice'] : []);
        }
        $settings['event_notice'] = $request->all();
        $this->saveSettings((int) $merchant['id'], $settings);
        Response::success($settings['event_notice'], '事件订阅设置已保存');
    }

    public function safeSettings(Request $request, string $kind, bool $write = false): never
    {
        $merchant = $this->merchant($request);
        $settings = $this->settings($merchant);
        $key = $kind === 'scenes' ? 'safe_scenes' : 'safe_settings';
        if (!$write) {
            $value = $settings[$key] ?? [];
            Response::success(is_array($value) ? $value : []);
        }
        $settings[$key] = $request->all();
        $this->saveSettings((int) $merchant['id'], $settings);
        Response::success($settings[$key], '安全设置已保存');
    }

    public function inviteInfo(Request $request): never
    {
        $merchant = $this->merchant($request);
        $db = Database::connection();
        $visits = $db->prepare('SELECT COUNT(*) FROM invite_visit WHERE uid=:uid');
        $visits->execute([':uid' => (int) $merchant['id']]);
        $referrals = $db->prepare('SELECT COUNT(*) FROM user WHERE recommend_uid=:uid');
        $referrals->execute([':uid' => (int) $merchant['id']]);
        $totalRebate = $db->prepare('SELECT COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) FROM user_rebate_log WHERE uid=:uid AND status=1');
        $totalRebate->execute([':uid' => (int) $merchant['id']]);
        Response::success([
            'recommend_code' => 'u' . (int) $merchant['id'],
            'invite_url' => '/r/u' . (int) $merchant['id'],
            'visit_count' => (int) $visits->fetchColumn(),
            'reg_user_count' => (int) $referrals->fetchColumn(),
            'rebate_balance' => (int) ($merchant['rebate_balance'] ?? 0),
            'total_rebate' => (int) ($totalRebate->fetchColumn() ?: 0),
        ]);
    }

    public function inviteRebateRecord(Request $request): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $page = max((int) ($params['page'] ?? 1), 1);
        $limit = min(max((int) ($params['limit'] ?? 20), 1), 100);
        $uid = (int) $merchant['id'];

        $count = Database::connection()->prepare('SELECT COUNT(*) FROM user_rebate_log WHERE uid=:uid');
        $count->execute([':uid' => $uid]);
        $total = (int) $count->fetchColumn();

        $query = Database::connection()->prepare(
            'SELECT id, uid, balance, arrival_time, status, origin_type, origin_id, from_uid, remark, created_at, updated_at '
            . 'FROM user_rebate_log WHERE uid=:uid ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $query->bindValue(':uid', $uid, \PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $limit, \PDO::PARAM_INT);
        $query->execute();
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['balance'] = (int) ($row['balance'] ?? 0);
            $row['arrival_time'] = (int) ($row['arrival_time'] ?? 0);
            $row['created_at'] = (int) ($row['created_at'] ?? 0);
            $row['remark'] = (string) ($row['remark'] ?? '');
            $rows[] = $row;
        }
        Response::success([
            'list' => $rows,
            'count' => $total,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    public function transferRebate(Request $request): never
    {
        $merchant = $this->merchant($request);
        $amount = (int) round((float) $request->input('amount', 0) * 100);
        if ($amount <= 0) {
            Response::error('划转金额必须大于 0', 422, 422);
        }
        try {
            $result = (new RebateService())->transfer(Database::connection(), (int) $merchant['id'], $amount, time());
        } catch (\InvalidArgumentException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        } catch (\Throwable $exception) {
            Response::error('佣金划转失败', 500, 500);
        }
        Response::success($result, '佣金已划转');
    }

    public function payCodeList(Request $request): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $query = Database::connection()->prepare('SELECT * FROM pay_codes WHERE uid=:uid ORDER BY id DESC');
        $query->execute([':uid' => (int) $merchant['id']]);
        $rows = $query->fetchAll();
        $rows = array_values(array_filter($rows, static function (array $row) use ($params): bool {
            foreach (['pay_type', 'status'] as $field) {
                if (($params[$field] ?? '') !== '' && (string) $row[$field] !== (string) $params[$field]) {
                    return false;
                }
            }
            $needle = trim((string) ($params['query'] ?? ''));
            return $needle === '' || stripos((string) ($row['content'] ?? '') . ' ' . (string) ($row['account_name'] ?? ''), $needle) !== false;
        }));
        Response::success($this->paginate($rows, $params));
    }

    public function payCodeWrite(Request $request, ?int $id = null): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $payType = trim((string) ($params['pay_type'] ?? ''));
        $content = trim((string) ($params['qrcode'] ?? $params['content'] ?? $params['qrcode_data'] ?? ''));
        if ($payType === '' || $content === '') {
            Response::error('支付方式和支付码不能为空', 422, 422);
        }
        $db = Database::connection();
        $values = [
            ':uid' => (int) $merchant['id'],
            ':account_id' => (int) ($params['account_id'] ?? 0),
            ':pay_type' => $payType,
            ':channel_code' => trim((string) ($params['channel_code'] ?? 'demo')),
            ':code_type' => trim((string) ($params['qrcode_type'] ?? $params['code_type'] ?? 'text')),
            ':content' => $content,
            ':qrcode_data' => trim((string) ($params['qrcode_data'] ?? $content)),
            ':amount' => max((int) ($params['amount'] ?? 0), 0),
            ':status' => $this->status($params['status'] ?? 1),
            ':sort' => (int) ($params['sort'] ?? 50),
            ':options' => is_string($params['options'] ?? null) ? (string) $params['options'] : json_encode($params['options'] ?? [], JSON_UNESCAPED_UNICODE),
            ':created_at' => time(),
            ':updated_at' => time(),
        ];
        if ($id !== null) {
            $query = $db->prepare('UPDATE pay_codes SET pay_type=:pay_type,channel_code=:channel_code,code_type=:code_type,content=:content,qrcode_data=:qrcode_data,amount=:amount,status=:status,sort=:sort,options=:options,updated_at=:updated_at WHERE id=:id AND uid=:uid');
            $query->execute($values + [':id' => $id]);
            if ($query->rowCount() === 0) {
                Response::error('支付码不存在', 404, 404);
            }
            Response::success(['id' => $id], '支付码已保存');
        }
        $query = $db->prepare('INSERT INTO pay_codes (account_id,uid,pay_type,channel_code,code_type,content,qrcode_data,amount,status,sort,options,created_at,updated_at) VALUES (:account_id,:uid,:pay_type,:channel_code,:code_type,:content,:qrcode_data,:amount,:status,:sort,:options,:created_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '支付码已创建');
    }

    public function payCodeRemove(Request $request): never
    {
        $merchant = $this->merchant($request);
        $query = Database::connection()->prepare('DELETE FROM pay_codes WHERE id=:id AND uid=:uid');
        $query->execute([':id' => (int) $request->input('id', 0), ':uid' => (int) $merchant['id']]);
        if ($query->rowCount() === 0) {
            Response::error('支付码不存在', 404, 404);
        }
        Response::success(null, '支付码已删除');
    }

    public function payCodeSwitch(Request $request): never
    {
        $merchant = $this->merchant($request);
        $query = Database::connection()->prepare('UPDATE pay_codes SET status=:status,updated_at=:updated_at WHERE id=:id AND uid=:uid');
        $query->execute([
            ':status' => $this->status($request->input('status')),
            ':updated_at' => time(),
            ':id' => (int) $request->input('id', 0),
            ':uid' => (int) $merchant['id'],
        ]);
        if ($query->rowCount() === 0) {
            Response::error('支付码不存在', 404, 404);
        }
        Response::success(null, '支付码状态已更新');
    }

    public function domainWhiteList(Request $request): never
    {
        $merchant = $this->merchant($request);
        $query = Database::connection()->prepare('SELECT * FROM domain_white WHERE username=:username ORDER BY id DESC');
        $query->execute([':username' => (string) $merchant['username']]);
        $this->respondPage($query->fetchAll(), $request->all());
    }

    public function domainWhiteApply(Request $request): never
    {
        $merchant = $this->merchant($request);
        $domain = trim((string) $request->input('domain', ''));
        $remark = trim((string) $request->input('remark', $request->input('reason', '')));
        if ($domain === '' || $remark === '') {
            Response::error('域名和用途说明不能为空', 422, 422);
        }
        $query = Database::connection()->prepare('INSERT INTO domain_white (domain,type,username,remark,reason,status,created_at,updated_at) VALUES (:domain,1,:username,:remark,:reason,0,:created_at,:updated_at)');
        $query->execute([
            ':domain' => $domain,
            ':username' => (string) $merchant['username'],
            ':remark' => $remark,
            ':reason' => '',
            ':created_at' => time(),
            ':updated_at' => time(),
        ]);
        Response::success(['id' => (int) Database::connection()->lastInsertId()], '域名白名单申请已提交');
    }

    public function mcpKeys(Request $request, string $action = 'list'): never
    {
        $merchant = $this->merchant($request);
        $db = Database::connection();
        if ($action === 'list') {
            $query = $db->prepare('SELECT id,name,api_key,status,allow_create,settings,created_at,updated_at FROM mcp_key WHERE uid=:uid ORDER BY id DESC');
            $query->execute([':uid' => (int) $merchant['id']]);
            $rows = $query->fetchAll();
            foreach ($rows as &$row) {
                $row['settings'] = $this->jsonArray($row['settings'] ?? '{}');
            }
            unset($row);
            Response::success(['list' => $rows, 'count' => count($rows), 'total' => count($rows)]);
        }
        if ($action === 'create') {
            $name = trim((string) $request->input('name', ''));
            if ($name === '') {
                Response::error('密钥名称不能为空', 422, 422);
            }
            $key = 'mcp_' . bin2hex(random_bytes(20));
            $insert = $db->prepare('INSERT INTO mcp_key (uid,name,api_key,status,allow_create,settings,created_at,updated_at) VALUES (:uid,:name,:api_key,1,0,"{}",:created_at,:updated_at)');
            $insert->execute([':uid' => (int) $merchant['id'], ':name' => $name, ':api_key' => $key, ':created_at' => time(), ':updated_at' => time()]);
            Response::success(['id' => (int) $db->lastInsertId(), 'api_key' => $key], 'MCP 密钥已创建');
        }
        $id = (int) ($request->input('id', 0));
        $owner = $db->prepare('SELECT * FROM mcp_key WHERE id=:id AND uid=:uid LIMIT 1');
        $owner->execute([':id' => $id, ':uid' => (int) $merchant['id']]);
        $row = $owner->fetch();
        if (!is_array($row)) {
            Response::error('MCP 密钥不存在', 404, 404);
        }
        if ($action === 'delete') {
            $delete = $db->prepare('DELETE FROM mcp_key WHERE id=:id AND uid=:uid');
            $delete->execute([':id' => $id, ':uid' => (int) $merchant['id']]);
            Response::success(null, 'MCP 密钥已删除');
        }
        if ($action === 'status') {
            $update = $db->prepare('UPDATE mcp_key SET status=:status,updated_at=:updated_at WHERE id=:id AND uid=:uid');
            $update->execute([':status' => $this->status($request->input('status')), ':updated_at' => time(), ':id' => $id, ':uid' => (int) $merchant['id']]);
            Response::success(null, 'MCP 密钥状态已更新');
        }
        if ($action === 'update') {
            $update = $db->prepare('UPDATE mcp_key SET name=:name,updated_at=:updated_at WHERE id=:id AND uid=:uid');
            $update->execute([':name' => trim((string) $request->input('name', $row['name'])), ':updated_at' => time(), ':id' => $id, ':uid' => (int) $merchant['id']]);
            Response::success(['id' => $id], 'MCP 密钥已保存');
        }
        if ($action === 'settings') {
            $update = $db->prepare('UPDATE mcp_key SET allow_create=:allow_create,settings=:settings,updated_at=:updated_at WHERE id=:id AND uid=:uid');
            $update->execute([
                ':allow_create' => $this->status($request->input('allow_create', 0)),
                ':settings' => json_encode($request->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':updated_at' => time(),
                ':id' => $id,
                ':uid' => (int) $merchant['id'],
            ]);
            Response::success(['id' => $id], 'MCP 密钥设置已保存');
        }
        Response::error('不支持的 MCP 操作', 400, 400);
    }

    public function verification(Request $request, string $action): never
    {
        $merchant = $this->merchant($request);
        $settings = $this->settings($merchant);
        $verification = is_array($settings['verification'] ?? null) ? $settings['verification'] : [];
        if ($action === 'config') {
            Response::success(['enabled' => true, 'types' => ['personal'], 'phone_required' => false]);
        }
        if ($action === 'status') {
            Response::success(['status' => (string) ($verification['status'] ?? 'unverified'), 'type' => (string) ($verification['type'] ?? '')]);
        }
        if ($action === 'info') {
            Response::success($verification);
        }
        if ($action === 'personal') {
            $name = trim((string) $request->input('name', ''));
            $idNumber = trim((string) ($request->input('id_number') ?? $request->input('id_card', '')));
            if ($name === '' || $idNumber === '') {
                Response::error('认证姓名和证件号码不能为空', 422, 422);
            }
            $verification = ['type' => 'personal', 'name' => $name, 'id_number' => $idNumber, 'status' => 'pending', 'updated_at' => time()];
            $settings['verification'] = $verification;
            $this->saveSettings((int) $merchant['id'], $settings);
            Response::success($verification, '认证资料已提交');
        }
        if (in_array($action, ['send-phone-code', 'verify-phone-code'], true)) {
            Response::error('本地版未配置短信验证服务', 501, 501);
        }
        if ($action === 'ticket-status') {
            Response::error('当前版本未配置外部认证凭据状态服务', 501, 501);
        }
        Response::error('不支持的认证操作', 400, 400);
    }

    public function verificationCreate(Request $request): never
    {
        $this->verification($request, 'personal');
    }

    public function verificationUpload(Request $request, string $kind): never
    {
        $merchant = $this->merchant($request);
        if (!in_array($kind, ['face', 'idcard'], true)) {
            Response::error('认证图片类型无效', 422, 422);
        }
        $settings = $this->settings($merchant);
        $verification = is_array($settings['verification'] ?? null) ? $settings['verification'] : [];
        if ((string) ($verification['name'] ?? '') === '' || (string) ($verification['id_number'] ?? '') === '') {
            Response::error('请先提交实名认证资料', 422, 422);
        }
        $file = $this->saveUploadedImage($request, (int) $merchant['id'], 'verification_' . $kind);
        $verification[$kind . '_file_id'] = (int) ($file['id'] ?? 0);
        $verification[$kind . '_url'] = (string) ($file['url'] ?? '');
        $verification['status'] = 'pending';
        $verification['updated_at'] = time();
        $settings['verification'] = $verification;
        $this->saveSettings((int) $merchant['id'], $settings);
        Response::success([
            'type' => $kind,
            'file' => $file,
            'verification' => $verification,
        ], '认证图片上传成功');
    }

    public function sharedChannelSettlementList(Request $request): never
    {
        $merchant = $this->merchant($request);
        $params = $request->all();
        $db = Database::connection();
        $sql = 'SELECT t.id, t.uid, t.third_order_id, t.amount, t.trans_time, t.status AS flow_status, '
            . 't.matched_order_id, t.created_at, t.updated_at, o.order_id, o.pay_type, o.channel_code, '
            . 'o.trade_amount, o.pay_time, a.id AS account_id, a.name AS account_name '
            . 'FROM third_order t LEFT JOIN `order` o ON o.id=t.matched_order_id '
            . 'LEFT JOIN pay_account a ON a.id=o.account_id '
            . 'WHERE t.uid=:uid';
        $bind = [':uid' => (int) $merchant['id']];
        $orderId = trim((string) ($params['order_id'] ?? ''));
        if ($orderId !== '') {
            $sql .= ' AND (o.order_id LIKE :order_id OR t.third_order_id LIKE :order_id)';
            $bind[':order_id'] = '%' . $orderId . '%';
        }
        $status = filter_var($params['status'] ?? null, FILTER_VALIDATE_INT);
        if ($status !== false && $status !== null) {
            $flowStatus = match ((int) $status) {
                1 => 0,
                2 => 2,
                3 => 4,
                default => -1,
            };
            if ($flowStatus < 0) {
                Response::error('结算状态无效', 422, 422);
            }
            $sql .= ' AND t.status=:flow_status';
            $bind[':flow_status'] = $flowStatus;
        }
        $sql .= ' ORDER BY t.id DESC';
        $query = $db->prepare($sql);
        $query->execute($bind);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $statusValue = (int) ($row['flow_status'] ?? 0);
            $settlementStatus = $statusValue === 2 ? 2 : ($statusValue === 4 ? 3 : 1);
            $tradeAmount = (int) (($row['trade_amount'] ?? 0) ?: ($row['amount'] ?? 0));
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'uid' => (int) ($row['uid'] ?? $merchant['id']),
                'order_id' => (string) ($row['order_id'] ?? $row['third_order_id'] ?? ''),
                'third_order_id' => (string) ($row['third_order_id'] ?? ''),
                'pay_type' => (string) ($row['pay_type'] ?? ''),
                'channel_code' => (string) ($row['channel_code'] ?? ''),
                'account_id' => (int) ($row['account_id'] ?? 0),
                'account_name' => (string) ($row['account_name'] ?? ''),
                'trade_amount' => $tradeAmount,
                'user_share_rate' => 0,
                'user_income_amount' => 0,
                'official_retained_amount' => 0,
                'status' => $settlementStatus,
                'settled_at' => (int) ($row['updated_at'] ?? 0),
                'settled_real_at' => $settlementStatus === 2 ? (int) ($row['updated_at'] ?? 0) : 0,
                'created_at' => (int) ($row['created_at'] ?? 0),
            ];
        }
        Response::success($this->paginate($rows, $params));
    }

    public function upload(Request $request, string $scope): never
    {
        $actor = $this->actor($request);
        Response::success($this->saveUploadedImage($request, (int) ($actor['uid'] ?? 0), $scope), '上传成功');
    }

    public function uploadSign(Request $request): never
    {
        $this->actor($request);
        $token = bin2hex(random_bytes(20));
        Response::success(['token' => $token, 'driver' => 'local', 'upload_url' => '/api/upload/images']);
    }

    public function uploadConfirm(Request $request): never
    {
        $actor = $this->actor($request);
        $id = (int) ($request->input('id') ?? $request->input('file_id') ?? 0);
        if ($id <= 0) {
            Response::error('文件 ID 无效', 422, 422);
        }
        $uid = (int) $actor['uid'];
        $query = Database::connection()->prepare('SELECT id,url,status FROM upload_file WHERE id=:id AND uid=:uid LIMIT 1');
        $query->execute([':id' => $id, ':uid' => $uid]);
        $file = $query->fetch();
        if (!is_array($file)) {
            Response::error('文件不存在', 404, 404);
        }
        Response::success($file, '文件已确认');
    }

    public function registerCaptcha(Request $request): never
    {
        if (!Captcha::available()) {
            Response::error('PHP GD 扩展未启用', 503, 503);
        }
        Response::success(Captcha::issue());
    }

    public function register(Request $request): never
    {
        $params = $request->all();
        if (!Captcha::verify(trim((string) ($params['captcha_id'] ?? '')), trim((string) ($params['captcha_code'] ?? '')))) {
            Response::error('请输入正确的验证码', 422, 422);
        }
        $username = trim((string) ($params['username'] ?? ''));
        $password = (string) ($params['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username) || strlen($password) < 6) {
            Response::error('用户名或密码格式不正确', 422, 422);
        }
        $db = Database::connection();
        $inviteCode = trim((string) ($params['invite_code'] ?? ''));
        $recommendUid = 0;
        if ($inviteCode !== '') {
            if (preg_match('/^u([1-9][0-9]*)$/', $inviteCode, $matches) !== 1) {
                Response::error('邀请码无效', 422, 422);
            }
            $referrer = $db->prepare('SELECT id FROM user WHERE id=:id AND status=1 LIMIT 1');
            $referrer->execute([':id' => (int) $matches[1]]);
            $recommendUid = (int) ($referrer->fetchColumn() ?: 0);
            if ($recommendUid === 0) {
                Response::error('邀请码对应的商户不存在或已停用', 422, 422);
            }
        }
        $check = $db->prepare('SELECT COUNT(*) FROM user WHERE username=:username');
        $check->execute([':username' => $username]);
        if ((int) $check->fetchColumn() > 0) {
            Response::error('用户名已存在', 409, 409);
        }
        try {
            $db->beginTransaction();
            $id = MerchantIdentity::nextId($db);
            $insert = $db->prepare('INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,token,created_at,recommend_uid) VALUES (:id,:username,:password,:merchant_name,:app_secret,1,0,"",:created_at,:recommend_uid)');
            $insert->execute([
                ':id' => $id,
                ':username' => $username,
                ':password' => md5($password),
                ':merchant_name' => trim((string) ($params['merchant_name'] ?? $username)),
                ':app_secret' => MerchantIdentity::appSecret(),
                ':created_at' => time(),
                ':recommend_uid' => $recommendUid,
            ]);
            $db->commit();
        } catch (\PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('注册失败：' . $exception->getMessage(), 500, 500);
        }
        Response::success(['id' => $id], '注册成功');
    }

    public function recordInviteVisit(Request $request, int $uid): never
    {
        $db = Database::connection();
        $merchant = $db->prepare('SELECT id FROM user WHERE id=:id AND status=1 LIMIT 1');
        $merchant->execute([':id' => $uid]);
        if ((int) $merchant->fetchColumn() === 0) {
            Response::error('邀请链接对应的商户不存在或已停用', 404, 404);
        }
        $day = date('Y-m-d');
        $visitorHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . "\0" . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '') . "\0" . $day);
        $exists = $db->prepare('SELECT id FROM invite_visit WHERE uid=:uid AND visitor_hash=:visitor_hash AND visit_day=:visit_day LIMIT 1');
        $exists->execute([':uid' => $uid, ':visitor_hash' => $visitorHash, ':visit_day' => $day]);
        if ($exists->fetchColumn() === false) {
            $insert = $db->prepare('INSERT INTO invite_visit (uid,visitor_hash,visit_day,created_at) VALUES (:uid,:visitor_hash,:visit_day,:created_at)');
            $insert->execute([':uid' => $uid, ':visitor_hash' => $visitorHash, ':visit_day' => $day, ':created_at' => time()]);
        }
        header('Set-Cookie: invite_code=u' . $uid . '; Path=/; Max-Age=2592000; SameSite=Lax' . (($_SERVER['HTTPS'] ?? '') === 'on' ? '; Secure' : ''));
        header('Location: /register', true, 302);
        exit;
    }

    public function externalAccountRecovery(Request $request, string $action): never
    {
        Response::error('本地版未配置邮件找回服务: ' . $action, 501, 501);
    }

    public function externalEmailFeature(Request $request, string $action): never
    {
        Response::error('本地版未配置邮件服务，无法执行操作: ' . $action, 501, 501);
    }

    public function rgorder(Request $request, string $action = 'create'): never
    {
        $this->merchant($request);
        Response::error('本地版未配置注册付费订单服务: ' . $action, 501, 501);
    }

    public function reportMerchant(Request $request, int $pid, string $mode = 'mobile'): never
    {
        $db = Database::connection();
        if ($pid <= 0) {
            Response::error('商户 ID 无效', 422, 422);
        }
        $merchant = $this->reportMerchantById($db, $pid);
        $params = $request->all();
        $secret = trim((string) ($merchant['app_secret'] ?? ''));
        if ($secret === '') {
            Response::error('商户通信密钥未配置', 501, 501);
        }

        if ($mode === 'heart') {
            $this->requireReportFields($params, ['client_name', 'timestamp', 'sign']);
            if (!is_numeric($params['timestamp']) || (int) $params['timestamp'] <= 0) {
                Response::error('请传入有效时间戳', 422, 422);
            }
            $fields = ['client_name', 'channel_code', 'channel_id', 'ext_data', 'timestamp', 'sign'];
            $this->requireReportSign($params, $secret, $fields);
            $account = $this->reportAccountByHeart($db, $merchant, $params);
            $this->touchReportHeartbeat($db, $account);
            Response::success([
                'pid' => $pid,
                'channel_id' => (int) $account['id'],
                'channel_code' => (string) $account['channel_code'],
                'online' => true,
                'last_seen_at' => time(),
            ], '心跳上报成功');
        }

        if ($mode === 'mobile') {
            $this->requireReportFields($params, ['from', 'content', 'timestamp', 'sign']);
            $fields = ['from', 'content', 'timestamp', 'sign'];
            $this->requireReportSign($params, $secret, $fields);
            $account = $this->reportAccountByFrom($db, $merchant, (string) $params['from']);
            $pluginName = $this->reportPluginName($account);
            $plugin = $this->reportPlugin($pluginName, '消息上报');
            if (!method_exists($plugin, 'parseMessage')) {
                Response::error('支付插件不支持消息上报', 422, 422);
            }
            $timestamp = $this->reportTime($params['timestamp']);
            $message = [
                'from' => (string) $params['from'],
                'content' => (string) $params['content'],
                'timestamp' => $timestamp,
                'trans_time' => $timestamp,
                'third_order_id' => 'app-' . hash('sha256', (string) $params['from'] . "\0" . (string) $params['content'] . "\0" . (string) $params['timestamp']),
                'remark' => (string) $params['content'],
                'raw' => $params,
            ];
            try {
                $flow = $plugin->parseMessage($message, $account);
                $result = (new PluginRuntimeService())->reportFlow($db, $account, $pluginName, $flow, $timestamp);
            } catch (\Throwable $exception) {
                Response::error('上报格式错误：' . $exception->getMessage(), 422, 422);
            }
            $this->touchReportHeartbeat($db, $account);
            $this->reportResult($pid, $account, $result);
        }

        if ($mode === 'pc') {
            $this->requireReportFields($params, ['amount', 'timestamp', 'sign']);
            if (!is_numeric($params['amount']) || (int) $params['amount'] <= 0) {
                Response::error('上报金额必须是大于 0 的整数', 422, 422);
            }
            $this->requireReportSign($params, $secret, [
                'amount', 'pay_type', 'channel_code', 'remark', 'pay_time', 'coll_user',
                'pay_user', 'uid', 'order_id', 'out_order_id', 'channel_account_id',
                'timestamp', 'sign',
            ]);
            $account = $this->reportAccountByPc($db, $merchant, $params);
            $pluginName = $this->reportPluginName($account);
            $timestamp = $this->reportTime($params['pay_time'] ?? $params['timestamp']);
            $thirdOrderId = trim((string) ($params['out_order_id'] ?? ''));
            if ($thirdOrderId === '') {
                $thirdOrderId = trim((string) ($params['order_id'] ?? ''));
            }
            if ($thirdOrderId === '') {
                Response::error('PC 上报缺少 order_id 或 out_order_id', 422, 422);
            }
            $flow = [
                'third_order_id' => $thirdOrderId,
                'amount' => (int) $params['amount'],
                'trans_time' => $timestamp,
                'remark' => trim((string) ($params['remark'] ?? '')),
                'buyer_name' => trim((string) ($params['pay_user'] ?? '')),
                'raw' => $params,
            ];
            try {
                $result = (new PluginRuntimeService())->reportFlow($db, $account, $pluginName, $flow, $timestamp);
            } catch (\Throwable $exception) {
                Response::error('PC 上报处理失败：' . $exception->getMessage(), 422, 422);
            }
            $this->touchReportHeartbeat($db, $account);
            $this->reportResult($pid, $account, $result);
        }

        Response::error('不支持的报告类型', 422, 422);
    }

    public function reportThirdAccount(Request $request, string $mode = 'report'): never
    {
        $db = Database::connection();
        $params = $request->all();
        $secret = trim(OptionStore::raw($db, 'third_account_report_secret'));
        if ($secret === '') {
            Response::error('系统未配置第三方账号上报密钥', 501, 501);
        }

        if ($mode === 'head') {
            $this->requireReportFields($params, ['account', 'type', 'timestamp', 'sign']);
            $this->requireReportSign($params, $secret, ['account', 'type', 'timestamp', 'sign']);
            $third = $this->thirdAccount($db, $params);
            Response::success([
                'id' => (int) $third['id'],
                'name' => (string) $third['name'],
                'provider' => (string) $third['provider'],
                'account' => (string) $third['account'],
                'status' => (int) $third['status'],
                'options' => $this->jsonObject($third['options'] ?? '{}'),
            ], '第三方账号查询成功');
        }

        if ($mode === 'heart') {
            $this->requireReportFields($params, ['client_name', 'timestamp', 'sign']);
            $this->requireReportSign($params, $secret, [
                'client_name', 'channel_code', 'channel_id', 'ext_data', 'timestamp', 'sign',
            ]);
            $account = $this->reportAccountByHeart($db, null, $params);
            $this->touchReportHeartbeat($db, $account);
            Response::success([
                'channel_id' => (int) $account['id'],
                'channel_code' => (string) $account['channel_code'],
                'online' => true,
                'last_seen_at' => time(),
            ], '第三方账号心跳成功');
        }

        if ($mode !== 'report') {
            Response::error('不支持的第三方账号报告类型', 422, 422);
        }
        $this->requireReportFields($params, [
            'account', 'type', 'sub_account', 'amount', 'pay_type', 'channel_code', 'timestamp', 'sign',
        ]);
        if (!is_numeric($params['amount']) || (int) $params['amount'] <= 0) {
            Response::error('第三方账号上报金额必须是大于 0 的整数', 422, 422);
        }
        $this->requireReportSign($params, $secret, [
            'account', 'type', 'sub_account', 'amount', 'pay_type', 'channel_code', 'remark',
            'out_order_id', 'order_id', 'timestamp', 'sign',
        ]);
        $account = $this->thirdPayAccount($db, $params);
        $pluginName = $this->reportPluginName($account);
        $thirdOrderId = trim((string) ($params['out_order_id'] ?? ''));
        if ($thirdOrderId === '') {
            $thirdOrderId = trim((string) ($params['order_id'] ?? ''));
        }
        if ($thirdOrderId === '') {
            $thirdOrderId = 'third-' . hash('sha256', json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        $timestamp = $this->reportTime($params['timestamp']);
        $flow = [
            'third_order_id' => $thirdOrderId,
            'amount' => (int) $params['amount'],
            'trans_time' => $timestamp,
            'remark' => trim((string) ($params['remark'] ?? '')),
            'buyer_name' => trim((string) ($params['account'] ?? '')),
            'raw' => $params,
        ];
        try {
            $result = (new PluginRuntimeService())->reportFlow($db, $account, $pluginName, $flow, $timestamp);
        } catch (\Throwable $exception) {
            Response::error('第三方账号上报处理失败：' . $exception->getMessage(), 422, 422);
        }
        $this->touchReportHeartbeat($db, $account);
        $this->reportResult(0, $account, $result);
    }

    public function reportXiaodai(Request $request): never
    {
        $db = Database::connection();
        $params = $request->all();
        $this->requireReportFields($params, ['lx', 'pid', 'key', 'id']);
        $pid = (int) $params['pid'];
        if ($pid <= 0 || !is_numeric($params['id']) || (int) $params['id'] <= 0) {
            Response::error('小贷上报商户 PID 或通道 ID 无效', 422, 422);
        }
        $merchant = $this->reportMerchantById($db, $pid);
        if (!hash_equals((string) ($merchant['app_secret'] ?? ''), trim((string) $params['key']))) {
            Response::error('商户通信密钥错误', 401, 401);
        }
        $account = $this->reportAccountById($db, $pid, (int) $params['id']);
        $lx = strtolower(trim((string) $params['lx']));
        if ($lx === 'heart') {
            $this->touchReportHeartbeat($db, $account);
            Response::success([
                'pid' => $pid,
                'id' => (int) $account['id'],
                'online' => true,
                'last_seen_at' => time(),
            ], '小贷心跳上报成功');
        }
        if ($lx !== 'order') {
            Response::error('小贷上报类型只能是 heart 或 order', 422, 422);
        }
        $money = trim((string) ($params['money'] ?? ''));
        $name = trim((string) ($params['name'] ?? ''));
        if ($money === '' || $name === '') {
            Response::error('小贷订单上报缺少 money 或 name', 422, 422);
        }
        $amount = $this->reportYuanToCents($money);
        $timestamp = $this->reportTime($params['time'] ?? time());
        $pluginName = $this->reportPluginName($account);
        $flow = [
            'third_order_id' => 'xiaodai-' . hash('sha256', $pid . '|' . $account['id'] . '|' . $name . '|' . $money . '|' . $timestamp),
            'amount' => $amount,
            'trans_time' => $timestamp,
            'remark' => $name,
            'buyer_name' => $name,
            'raw' => $params,
        ];
        try {
            $result = (new PluginRuntimeService())->reportFlow($db, $account, $pluginName, $flow, $timestamp);
        } catch (\Throwable $exception) {
            Response::error('小贷订单上报处理失败：' . $exception->getMessage(), 422, 422);
        }
        $this->touchReportHeartbeat($db, $account);
        $this->reportResult($pid, $account, $result);
    }

    public function externalReport(Request $request, string $action): never
    {
        $this->merchant($request);
        Response::error('本地版未配置外部报告服务: ' . $action, 501, 501);
    }

    /** @return array<string,mixed> */
    private function reportMerchantById(\PDO $db, int $pid): array
    {
        $query = $db->prepare('SELECT * FROM user WHERE id=:id AND status=1 LIMIT 1');
        $query->execute([':id' => $pid]);
        $merchant = $query->fetch();
        if (!is_array($merchant)) {
            Response::error('用户未找到', 404, 404);
        }
        return $merchant;
    }

    /** @param array<string,mixed> $params @param list<string> $fields */
    private function requireReportFields(array $params, array $fields): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $params) || trim((string) $params[$field]) === '') {
                Response::error('上报格式错误：缺少字段 ' . $field, 422, 422);
            }
        }
    }

    /** @param array<string,mixed> $params @param list<string> $fields */
    private function requireReportSign(array $params, string $key, array $fields): void
    {
        if (!ReportSigner::valid($params, $key, $fields)) {
            Response::error('签名信息异常', 401, 401);
        }
    }

    /** @return array<string,mixed> */
    private function reportAccountById(\PDO $db, int $uid, int $accountId): array
    {
        $query = $db->prepare(
            'SELECT a.*,c.plugin_name FROM pay_account a INNER JOIN pay_channel c ON c.code=a.channel_code '
            . 'WHERE a.id=:id AND a.uid=:uid AND a.status=1 AND c.status=1 LIMIT 1'
        );
        $query->execute([':id' => $accountId, ':uid' => $uid]);
        $account = $query->fetch();
        if (!is_array($account)) {
            Response::error('渠道账号未找到', 404, 404);
        }
        return $account;
    }

    /** @param array<string,mixed> $merchant @param array<string,mixed> $params */
    private function reportAccountByHeart(\PDO $db, ?array $merchant, array $params): array
    {
        $where = ['a.status=1', 'c.status=1'];
        $values = [];
        if ($merchant !== null) {
            $where[] = 'a.uid=:uid';
            $values[':uid'] = (int) $merchant['id'];
        }
        $channelId = (int) ($params['channel_id'] ?? 0);
        $channelCode = trim((string) ($params['channel_code'] ?? ''));
        if ($channelId > 0) {
            $where[] = 'a.id=:account_id';
            $values[':account_id'] = $channelId;
        } elseif ($channelCode !== '') {
            $where[] = 'a.channel_code=:channel_code';
            $values[':channel_code'] = $channelCode;
        } else {
            Response::error('心跳上报缺少 channel_id 或 channel_code', 422, 422);
        }
        $query = $db->prepare(
            'SELECT a.*,c.plugin_name FROM pay_account a INNER JOIN pay_channel c ON c.code=a.channel_code WHERE '
            . implode(' AND ', $where) . ' ORDER BY a.id ASC'
        );
        $query->execute($values);
        $rows = array_values(array_filter($query->fetchAll(), 'is_array'));
        $clientName = trim((string) ($params['client_name'] ?? ''));
        $rows = array_values(array_filter($rows, static function (array $row) use ($clientName): bool {
            return $clientName === '' || trim((string) ($row['bind_client_name'] ?? '')) === $clientName;
        }));
        if (count($rows) !== 1) {
            Response::error($rows === [] ? '渠道账号未找到' : '心跳上报匹配到多个渠道账号', 404, 404);
        }
        return $rows[0];
    }

    /** @return array<string,mixed> */
    private function reportAccountByFrom(\PDO $db, array $merchant, string $from): array
    {
        $query = $db->prepare(
            'SELECT a.*,c.plugin_name FROM pay_account a INNER JOIN pay_channel c ON c.code=a.channel_code '
            . 'WHERE a.uid=:uid AND a.status=1 AND c.status=1 ORDER BY a.id ASC'
        );
        $query->execute([':uid' => (int) $merchant['id']]);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $options = $this->jsonObject($row['options'] ?? '{}');
            if (trim((string) ($row['bind_client_name'] ?? '')) === $from
                || trim((string) ($options['package_name'] ?? '')) === $from) {
                $rows[] = $row;
            }
        }
        if (count($rows) !== 1) {
            Response::error($rows === [] ? '渠道账号未找到' : '消息上报匹配到多个渠道账号', 404, 404);
        }
        return $rows[0];
    }

    /** @param array<string,mixed> $merchant @param array<string,mixed> $params */
    private function reportAccountByPc(\PDO $db, array $merchant, array $params): array
    {
        $accountId = (int) ($params['channel_account_id'] ?? 0);
        if ($accountId > 0) {
            return $this->reportAccountById($db, (int) $merchant['id'], $accountId);
        }
        $channelCode = trim((string) ($params['channel_code'] ?? ''));
        $payType = trim((string) ($params['pay_type'] ?? ''));
        if ($channelCode === '' || $payType === '') {
            Response::error('PC 上报缺少 pay_type、channel_code 或 channel_account_id', 422, 422);
        }
        $query = $db->prepare(
            'SELECT a.*,c.plugin_name FROM pay_account a INNER JOIN pay_channel c ON c.code=a.channel_code '
            . 'WHERE a.uid=:uid AND a.pay_type=:pay_type AND a.channel_code=:channel_code AND a.status=1 AND c.status=1 '
            . 'ORDER BY a.id ASC'
        );
        $query->execute([
            ':uid' => (int) $merchant['id'],
            ':pay_type' => $payType,
            ':channel_code' => $channelCode,
        ]);
        $rows = array_values(array_filter($query->fetchAll(), 'is_array'));
        if (count($rows) !== 1) {
            Response::error($rows === [] ? '渠道账号未找到' : 'PC 上报匹配到多个渠道账号', 404, 404);
        }
        return $rows[0];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function thirdAccount(\PDO $db, array $params): array
    {
        $type = (string) (int) $params['type'];
        $query = $db->prepare('SELECT * FROM third_account WHERE account=:account AND status=1 ORDER BY id ASC');
        $query->execute([':account' => trim((string) $params['account'])]);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $options = $this->jsonObject($row['options'] ?? '{}');
            if ((string) ($row['provider'] ?? '') === $type || (string) ($options['type'] ?? '') === $type) {
                $rows[] = $row;
            }
        }
        $rows = array_slice($rows, 0, 2);
        if (count($rows) !== 1) {
            Response::error($rows === [] ? '第三方账号不存在' : '第三方账号匹配不唯一', 404, 404);
        }
        return $rows[0];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function thirdPayAccount(\PDO $db, array $params): array
    {
        $query = $db->prepare(
            'SELECT a.*,c.plugin_name FROM pay_account a INNER JOIN pay_channel c ON c.code=a.channel_code '
            . 'WHERE a.account=:account AND a.sub_account=:sub_account AND a.pay_type=:pay_type '
            . 'AND a.channel_code=:channel_code AND a.status=1 AND c.status=1 LIMIT 2'
        );
        $query->execute([
            ':account' => trim((string) $params['account']),
            ':sub_account' => trim((string) $params['sub_account']),
            ':pay_type' => trim((string) $params['pay_type']),
            ':channel_code' => trim((string) $params['channel_code']),
        ]);
        $rows = array_values(array_filter($query->fetchAll(), 'is_array'));
        if (count($rows) !== 1) {
            Response::error($rows === [] ? '渠道账号未找到' : '第三方账号匹配不唯一', 404, 404);
        }
        return $rows[0];
    }

    private function reportPluginName(array $account): string
    {
        $name = trim((string) ($account['plugin_name'] ?? ''));
        if ($name === '') {
            Response::error('渠道账号未绑定支付插件', 422, 422);
        }
        return $name;
    }

    private function reportPlugin(string $name, string $label): \XArrPay\Payment\PaymentPlugin
    {
        try {
            return PaymentPluginRegistry::resolve($name);
        } catch (\Throwable $exception) {
            Response::error($label . '插件不可用：' . $exception->getMessage(), 422, 422);
        }
    }

    /** @param array<string,mixed> $result */
    private function reportResult(int $pid, array $account, array $result): never
    {
        $state = (string) ($result['state'] ?? 'pending');
        Response::success([
            'pid' => $pid,
            'account_id' => (int) ($account['id'] ?? 0),
            'flow_id' => (int) ($result['flow_id'] ?? 0),
            'matched' => $state === 'settled',
            'order_id' => (int) ($result['order_id'] ?? 0),
            'state' => $state,
            'duplicate' => (bool) ($result['duplicate'] ?? false),
        ], $state === 'settled' ? '上报成功，匹配成功' : '上报成功，等待订单匹配');
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function reportYuanToCents(string $value): int
    {
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            Response::error('小贷金额格式不正确', 422, 422);
        }
        [$yuan, $fen] = array_pad(explode('.', $value, 2), 2, '');
        $fen = str_pad($fen, 2, '0');
        $amount = ((int) $yuan * 100) + (int) substr($fen, 0, 2);
        if ($amount <= 0) {
            Response::error('小贷金额必须大于 0', 422, 422);
        }
        return $amount;
    }

    private function reportTime(mixed $value): int
    {
        if (is_int($value) || (is_float($value) && $value > 0)) {
            $time = (int) $value;
            return $time > 20000000000 ? (int) floor($time / 1000) : $time;
        }
        $value = trim((string) $value);
        if ($value !== '' && ctype_digit($value)) {
            $time = (int) $value;
            return $time > 20000000000 ? (int) floor($time / 1000) : $time;
        }
        $parsed = $value === '' ? 0 : strtotime($value);
        return $parsed === false ? 0 : $parsed;
    }

    /** @param array<string,mixed> $account */
    private function touchReportHeartbeat(\PDO $db, array $account): void
    {
        $now = time();
        $accountId = (int) ($account['id'] ?? 0);
        $pluginName = trim((string) ($account['plugin_name'] ?? ''));
        if ($accountId <= 0) {
            return;
        }
        $update = $db->prepare('UPDATE pay_account_ext SET last_seen_at=:last_seen_at,last_error="",updated_at=:updated_at WHERE account_id=:account_id');
        $update->execute([':last_seen_at' => $now, ':updated_at' => $now, ':account_id' => $accountId]);
        $state = $db->prepare('SELECT id FROM plugin_runtime_state WHERE account_id=:account_id LIMIT 1');
        $state->execute([':account_id' => $accountId]);
        if ($state->fetchColumn() === false) {
            $insert = $db->prepare('INSERT INTO plugin_runtime_state (account_id,plugin_name,online,last_heartbeat_at,worker_id,created_at,updated_at) VALUES (:account_id,:plugin_name,1,:last_heartbeat_at,"report",:created_at,:updated_at)');
            $insert->execute([
                ':account_id' => $accountId,
                ':plugin_name' => $pluginName,
                ':last_heartbeat_at' => $now,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } else {
            $updateState = $db->prepare('UPDATE plugin_runtime_state SET online=1,last_heartbeat_at=:last_heartbeat_at,last_error="",worker_id="report",updated_at=:updated_at WHERE account_id=:account_id');
            $updateState->execute([':last_heartbeat_at' => $now, ':updated_at' => $now, ':account_id' => $accountId]);
        }
        $db->prepare('UPDATE pay_account SET online=1,online_start=CASE WHEN online=1 AND online_start>0 THEN online_start ELSE :now END,online_end=0,updated_at=:now WHERE id=:id')
            ->execute([':now' => $now, ':id' => $accountId]);
    }

    /** @return array<string,mixed> */
    private function merchant(Request $request): array
    {
        $token = trim((string) $request->header('Authorization'));
        if ($token === '') {
            Response::json(['code' => 404, 'message' => '未登录', 'data' => [], 'redirect' => '']);
        }
        $query = Database::connection()->prepare('SELECT * FROM user WHERE token=:token AND status=1 LIMIT 1');
        $query->execute([':token' => $token]);
        $merchant = $query->fetch();
        if (!is_array($merchant)) {
            Response::json(['code' => 401, 'message' => '登录已失效', 'data' => [], 'redirect' => '']);
        }
        return $merchant;
    }

    /** @return array{uid:int,type:string,record:array<string,mixed>} */
    private function actor(Request $request): array
    {
        $token = trim((string) $request->header('Authorization'));
        if ($token === '') {
            Response::json(['code' => 404, 'message' => '未登录', 'data' => [], 'redirect' => '']);
        }
        $db = Database::connection();
        $query = $db->prepare('SELECT * FROM user WHERE token=:token AND status=1 LIMIT 1');
        $query->execute([':token' => $token]);
        $merchant = $query->fetch();
        if (is_array($merchant)) {
            return ['uid' => (int) $merchant['id'], 'type' => 'merchant', 'record' => $merchant];
        }
        $staff = $db->prepare('SELECT * FROM staff WHERE token=:token AND status=1 LIMIT 1');
        $staff->execute([':token' => $token]);
        $staffRow = $staff->fetch();
        if (is_array($staffRow)) {
            return ['uid' => 0, 'type' => 'staff', 'record' => $staffRow];
        }
        Response::json(['code' => 401, 'message' => '登录已失效', 'data' => [], 'redirect' => '']);
    }

    /** @param array<string,mixed> $merchant @return array<string,mixed> */
    private function settings(array $merchant): array
    {
        $decoded = json_decode((string) ($merchant['settings'] ?? '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $settings */
    private function saveSettings(int $uid, array $settings): void
    {
        $query = Database::connection()->prepare('UPDATE user SET settings=:settings WHERE id=:id');
        $query->execute([':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id' => $uid]);
    }

    private function consumeStepUpTicket(Request $request, int $uid, string $operation): void
    {
        $db = Database::connection();
        $factor = $db->prepare(
            'SELECT mfa_enabled, mfa_secret FROM `user` WHERE id = :id AND status = 1 LIMIT 1'
        );
        $factor->execute([':id' => $uid]);
        $merchant = $factor->fetch();
        $hasTotp = is_array($merchant)
            && (int) ($merchant['mfa_enabled'] ?? 0) === 1
            && trim((string) ($merchant['mfa_secret'] ?? '')) !== '';
        $passkey = $db->prepare(
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
        if (!SecurityTicket::consumeVerified(
            $db,
            $ticket,
            SecurityTicket::KIND_STEP_UP,
            $uid,
            $operation
        )) {
            Response::error('二次安全验证票据已使用，请重新操作', 401, 401);
        }
    }

    private function consumeVerificationCode(int $uid, string $purpose, string $target, string $code): void
    {
        if ($code === '') {
            Response::error('验证码不能为空', 422, 422);
        }
        $db = Database::connection();
        $query = $db->prepare(
            'SELECT * FROM verification_code WHERE uid=:uid AND purpose=:purpose AND target=:target AND status=1 ORDER BY id DESC LIMIT 1'
        );
        $query->execute([':uid' => $uid, ':purpose' => $purpose, ':target' => $target]);
        $row = $query->fetch();
        if (!is_array($row)) {
            Response::error('验证码不存在或已失效', 422, 422);
        }
        if ((int) ($row['expires_at'] ?? 0) < time()) {
            $db->prepare('UPDATE verification_code SET status=4 WHERE id=:id AND status=1')->execute([':id' => (int) $row['id']]);
            Response::error('验证码已过期，请重新获取', 422, 422);
        }
        if ((int) ($row['attempts'] ?? 0) >= 5) {
            $db->prepare('UPDATE verification_code SET status=5 WHERE id=:id AND status=1')->execute([':id' => (int) $row['id']]);
            Response::error('验证码错误次数过多，请重新获取', 429, 429);
        }
        if (!password_verify($code, (string) ($row['code_hash'] ?? ''))) {
            $attempts = (int) ($row['attempts'] ?? 0) + 1;
            $status = $attempts >= 5 ? 5 : 1;
            $db->prepare('UPDATE verification_code SET attempts=:attempts,status=:status WHERE id=:id AND status=1')
                ->execute([':attempts' => $attempts, ':status' => $status, ':id' => (int) $row['id']]);
            Response::error($attempts >= 5 ? '验证码错误次数过多，请重新获取' : '验证码错误', 422, 422);
        }
        $consume = $db->prepare('UPDATE verification_code SET status=2,used_at=:used_at WHERE id=:id AND status=1');
        $consume->execute([':used_at' => time(), ':id' => (int) $row['id']]);
        if ($consume->rowCount() !== 1) {
            Response::error('验证码已使用，请重新获取', 422, 422);
        }
    }

    /** @param array<string,mixed> $merchant */
    private function passkeyBegin(Request $request, array $merchant): never
    {
        if (trim((string) ($merchant['email'] ?? '')) === ''
            && trim((string) ($merchant['phone'] ?? '')) === ''
            && ((int) ($merchant['mfa_enabled'] ?? 0) !== 1 || trim((string) ($merchant['mfa_secret'] ?? '')) === '')
        ) {
            Response::error('绑定 Passkey 前请先绑定邮箱、手机或动态口令作为备用安全校验', 409, 409);
        }
        $db = Database::connection();
        $uid = (int) $merchant['id'];
        $now = time();
        $ticket = bin2hex(random_bytes(32));
        $challenge = WebAuthn::encode(random_bytes(32));
        $rpId = $this->webAuthnRpId($request);
        $origin = $this->webAuthnOrigin($request);
        $insert = $db->prepare(
            'INSERT INTO webauthn_challenge (ticket,uid,purpose,challenge,rp_id,origin,status,expires_at,created_at,updated_at) '
            . 'VALUES (:ticket,:uid,"register",:challenge,:rp_id,:origin,1,:expires_at,:created_at,:updated_at)'
        );
        $insert->execute([
            ':ticket' => $ticket,
            ':uid' => $uid,
            ':challenge' => $challenge,
            ':rp_id' => $rpId,
            ':origin' => $origin,
            ':expires_at' => $now + 300,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        Response::success([
            'ticket' => $ticket,
            'expire_time' => $now + 300,
            'options' => [
                'publicKey' => [
                    'challenge' => $challenge,
                    'rp' => ['id' => $rpId, 'name' => 'XArr Pay'],
                    'user' => [
                        'id' => WebAuthn::encode('merchant:' . $uid),
                        'name' => (string) ($merchant['username'] ?? ('merchant-' . $uid)),
                        'displayName' => (string) ($merchant['merchant_name'] ?? $merchant['username'] ?? ('商户' . $uid)),
                    ],
                    'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
                    'excludeCredentials' => $this->passkeyAllowCredentials($db, $uid),
                    'authenticatorSelection' => [
                        'residentKey' => 'preferred',
                        'userVerification' => 'required',
                    ],
                    'attestation' => 'none',
                    'timeout' => 300000,
                ],
            ],
        ]);
    }

    /** @param array<string,mixed> $merchant */
    private function passkeyFinish(Request $request, array $merchant): never
    {
        $uid = (int) $merchant['id'];
        $this->consumeStepUpTicket($request, $uid, 'passkey.bind');
        $ticket = trim((string) $request->input('ticket', ''));
        $credential = $this->credentialPayload($request->input('credential'));
        if ($ticket === '') {
            Response::error('通行密钥注册票据不能为空', 422, 422);
        }
        $db = Database::connection();
        $query = $db->prepare('SELECT * FROM webauthn_challenge WHERE ticket=:ticket AND uid=:uid AND purpose="register" AND status=1 LIMIT 1');
        $query->execute([':ticket' => $ticket, ':uid' => $uid]);
        $challenge = $query->fetch();
        if (!is_array($challenge) || (int) $challenge['expires_at'] < time()) {
            Response::error('通行密钥注册票据不存在或已过期', 401, 401);
        }
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        try {
            $result = WebAuthn::registration(
                WebAuthn::decode((string) ($response['attestationObject'] ?? '')),
                WebAuthn::decode((string) ($response['clientDataJSON'] ?? '')),
                (string) $challenge['challenge'],
                (string) $challenge['origin'],
            );
        } catch (\Throwable $exception) {
            Response::error($exception->getMessage(), 401, 401);
        }
        if (!hash_equals((string) $result['credential_id'], trim((string) ($credential['rawId'] ?? '')))) {
            Response::error('通行密钥凭据 ID 不匹配', 401, 401);
        }
        if (!$this->consumeWebAuthnChallenge($db, (int) $challenge['id'])) {
            Response::error('通行密钥注册票据已使用，请重新操作', 401, 401);
        }
        $name = trim((string) ($request->input('device_name', '') ?: '我的通行密钥'));
        if (mb_strlen($name) > 50) {
            Response::error('通行密钥名称不能超过 50 个字符', 422, 422);
        }
        $now = time();
        try {
            $insert = $db->prepare(
                'INSERT INTO passkey_credential (uid,credential_id,public_key,sign_count,device_name,aaguid,status,last_used_at,created_at,updated_at) '
                . 'VALUES (:uid,:credential_id,:public_key,:sign_count,:device_name,:aaguid,1,0,:created_at,:updated_at)'
            );
            $insert->execute([
                ':uid' => $uid,
                ':credential_id' => (string) $result['credential_id'],
                ':public_key' => (string) $result['public_key'],
                ':sign_count' => (int) $result['sign_count'],
                ':device_name' => $name,
                ':aaguid' => (string) $result['aaguid'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (\PDOException) {
            Response::error('该通行密钥已绑定，请更换设备或先删除旧凭据', 409, 409);
        }
        Response::success(['id' => (int) $db->lastInsertId()], '通行密钥已绑定');
    }

    /** @param array<string,mixed> $merchant */
    private function passkeyAuthBegin(Request $request, array $merchant): never
    {
        $db = Database::connection();
        $uid = (int) $merchant['id'];
        $credentials = $this->passkeyAllowCredentials($db, $uid);
        if ($credentials === []) {
            Response::error('当前账号未绑定通行密钥', 409, 409);
        }
        $now = time();
        $ticket = bin2hex(random_bytes(32));
        $challenge = WebAuthn::encode(random_bytes(32));
        $rpId = $this->webAuthnRpId($request);
        $origin = $this->webAuthnOrigin($request);
        $insert = $db->prepare(
            'INSERT INTO webauthn_challenge (ticket,uid,purpose,challenge,rp_id,origin,status,expires_at,created_at,updated_at) '
            . 'VALUES (:ticket,:uid,"auth",:challenge,:rp_id,:origin,1,:expires_at,:created_at,:updated_at)'
        );
        $insert->execute([
            ':ticket' => $ticket,
            ':uid' => $uid,
            ':challenge' => $challenge,
            ':rp_id' => $rpId,
            ':origin' => $origin,
            ':expires_at' => $now + 300,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        Response::success([
            'ticket' => $ticket,
            'expire_time' => $now + 300,
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

    /** @param array<string,mixed> $merchant */
    private function passkeyAuthFinish(Request $request, array $merchant): never
    {
        $uid = (int) $merchant['id'];
        $ticket = trim((string) $request->input('ticket', ''));
        if ($ticket === '') {
            Response::error('通行密钥认证票据不能为空', 422, 422);
        }
        if (!$this->verifyPasskeyAssertion(Database::connection(), $request, $uid, 'auth', $ticket, true)) {
            Response::error('通行密钥认证失败', 401, 401);
        }
        $operation = trim((string) ($request->input('operation', '') ?: 'passkey.auth'));
        $issued = SecurityTicket::create(Database::connection(), SecurityTicket::KIND_STEP_UP, $uid, $operation, 300);
        SecurityTicket::mark(Database::connection(), (int) Database::connection()->lastInsertId(), SecurityTicket::STATUS_VERIFIED);
        Response::success(['ticket' => $issued['ticket'], 'expire_time' => $issued['expire_time']], '通行密钥认证成功');
    }

    /** @return list<array{type:string,id:string}> */
    private function passkeyAllowCredentials(\PDO $db, int $uid): array
    {
        $query = $db->prepare('SELECT credential_id FROM passkey_credential WHERE uid=:uid AND status=1 ORDER BY id ASC');
        $query->execute([':uid' => $uid]);
        $credentials = [];
        foreach ($query->fetchAll() as $row) {
            if (is_array($row) && trim((string) ($row['credential_id'] ?? '')) !== '') {
                $credentials[] = ['type' => 'public-key', 'id' => (string) $row['credential_id']];
            }
        }
        return $credentials;
    }

    private function verifyPasskeyAssertion(\PDO $db, Request $request, int $uid, string $purpose, string $ticket, bool $consume): bool
    {
        $credential = $this->credentialPayload($request->input('credential'));
        $query = $db->prepare('SELECT * FROM webauthn_challenge WHERE ticket=:ticket AND uid=:uid AND purpose=:purpose AND status=1 LIMIT 1');
        $query->execute([':ticket' => $ticket, ':uid' => $uid, ':purpose' => $purpose]);
        $challenge = $query->fetch();
        if (!is_array($challenge) || (int) $challenge['expires_at'] < time()) {
            Response::error('通行密钥认证票据不存在或已过期', 401, 401);
        }
        $credentialId = trim((string) ($credential['rawId'] ?? $credential['id'] ?? ''));
        $storedQuery = $db->prepare('SELECT * FROM passkey_credential WHERE uid=:uid AND credential_id=:credential_id AND status=1 LIMIT 1');
        $storedQuery->execute([':uid' => $uid, ':credential_id' => $credentialId]);
        $stored = $storedQuery->fetch();
        if (!is_array($stored)) {
            Response::error('通行密钥不存在或不属于当前账号', 401, 401);
        }
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
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
        if ($consume && !$this->consumeWebAuthnChallenge($db, (int) $challenge['id'])) {
            Response::error('通行密钥认证票据已使用，请重新操作', 401, 401);
        }
        $now = time();
        $update = $db->prepare('UPDATE passkey_credential SET sign_count=:sign_count,last_used_at=:last_used_at,updated_at=:updated_at WHERE id=:id AND uid=:uid AND status=1');
        $update->execute([
            ':sign_count' => (int) $result['sign_count'],
            ':last_used_at' => $now,
            ':updated_at' => $now,
            ':id' => (int) $stored['id'],
            ':uid' => $uid,
        ]);
        return true;
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

    private function status(mixed $value): int
    {
        $status = filter_var($value, FILTER_VALIDATE_INT);
        if ($status === false || !in_array((int) $status, [0, 1, 2], true)) {
            Response::error('状态值只能为 0、1 或 2', 422, 422);
        }
        return (int) $status;
    }

    private function respondPage(array $rows, array $params): never
    {
        Response::success($this->paginate($rows, $params));
    }

    private function ownedWorkOrder(int $id, int $uid): void
    {
        if ($id <= 0) {
            Response::error('工单 ID 无效', 422, 422);
        }
        $query = Database::connection()->prepare('SELECT COUNT(*) FROM work_order WHERE id=:id AND uid=:uid');
        $query->execute([':id' => $id, ':uid' => $uid]);
        if ((int) $query->fetchColumn() === 0) {
            Response::error('工单不存在', 404, 404);
        }
    }

    /** @return list<string> */
    private function workOrderAttachments(mixed $value, int $uid): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value) || count($value) > 5) {
            Response::error('工单附件格式无效或超过 5 个', 422, 422);
        }
        $urls = array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== ''
        )));
        if (count($urls) !== count($value)) {
            Response::error('工单附件地址无效', 422, 422);
        }
        if ($urls === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($urls), '?'));
        $query = Database::connection()->prepare(
            "SELECT url FROM upload_file WHERE uid=? AND scope='images' AND status=1 AND url IN ({$placeholders})"
        );
        $query->execute(array_merge([$uid], $urls));
        $owned = $query->fetchAll(\PDO::FETCH_COLUMN);
        if (count($owned) !== count($urls)) {
            Response::error('附件不存在或不属于当前账号', 422, 422);
        }
        return $urls;
    }

    /** @return list<int> */
    private function validateMerchantAccountIds(\PDO $db, mixed $value, int $uid): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($value)) {
            Response::error('轮询账号列表格式无效', 422, 422);
        }
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $value)));
        if (in_array(0, $ids, true) || count($ids) !== count($value)) {
            Response::error('轮询账号 ID 无效', 422, 422);
        }
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $db->prepare("SELECT id FROM pay_account WHERE uid=? AND id IN ({$placeholders})");
        $query->execute(array_merge([$uid], $ids));
        $ownedIds = array_map('intval', $query->fetchAll(\PDO::FETCH_COLUMN));
        sort($ids);
        sort($ownedIds);
        if ($ids !== $ownedIds) {
            Response::error('轮询账号不存在或不属于当前商户', 422, 422);
        }
        return $ids;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function paginate(array $rows, array $params): array
    {
        $page = max((int) ($params['page'] ?? 1), 1);
        $limit = min(max((int) ($params['limit'] ?? 20), 1), 100);
        return ['list' => array_slice($rows, ($page - 1) * $limit, $limit), 'count' => count($rows), 'total' => count($rows), 'page' => $page, 'limit' => $limit];
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private function saveUploadedImage(Request $request, int $uid, string $scope): array
    {
        $files = $request->files();
        $file = $files['file'] ?? $files['image'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('请选择要上传的文件', 422, 422);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            Response::error('图片大小不能超过 2MB', 422, 422);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($tmp) : (string) ($file['type'] ?? '');
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime]) || !is_uploaded_file($tmp)) {
            Response::error('只允许上传 jpg、png、gif、bmp、webp 图片', 422, 422);
        }
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . date('Ymd');
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            Response::error('上传目录创建失败', 500, 500);
        }
        $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($tmp, $path)) {
            Response::error('文件保存失败', 500, 500);
        }
        $url = '/uploads/' . date('Ymd') . '/' . $name;
        $insert = Database::connection()->prepare(
            'INSERT INTO upload_file (uid,scope,path,url,name,mime,size,status,created_at) '
            . 'VALUES (:uid,:scope,:path,:url,:name,:mime,:size,1,:created_at)'
        );
        $insert->execute([
            ':uid' => $uid,
            ':scope' => $scope,
            ':path' => $path,
            ':url' => $url,
            ':name' => (string) ($file['name'] ?? $name),
            ':mime' => $mime,
            ':size' => $size,
            ':created_at' => time(),
        ]);
        return [
            'id' => (int) Database::connection()->lastInsertId(),
            'filename' => $url,
            'url' => $url,
            'path' => $url,
            'name' => (string) ($file['name'] ?? $name),
            'size' => $size,
            'mime' => $mime,
        ];
    }
}
