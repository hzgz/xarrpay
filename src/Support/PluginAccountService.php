<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;
use XArrPay\Payment\PluginOptions;

final class PluginAccountService
{
    /** @return array<string, mixed> */
    public static function loginQrcode(PDO $db, array $account, array $user, array $params, string $site): array
    {
        $plugin = (string) ($account['plugin_name'] ?? '');
        $adapter = PaymentPluginRegistry::resolveAccount($plugin);
        $result = $adapter->loginQrcode($db, $account, $user, $params, $site);
        if ((int) ($result['code'] ?? 500) !== 200) {
            return $result;
        }
        $options = self::options($account);
        $accountId = (int) ($account['id'] ?? 0);

        $options = array_replace($options, self::decodeArray($result['account_options'] ?? []));
        unset($result['account_options']);
        PluginAccountStore::saveOptions($db, $accountId, $options);

        $ticket = bin2hex(random_bytes(24));
        $now = time();
        $insert = $db->prepare(
            'INSERT INTO qrcode_login_ticket (ticket_id,account_id,uid,plugin_name,params,status,expires_at,created_at,updated_at) '
            . 'VALUES (:ticket_id,:account_id,:uid,:plugin_name,:params,1,:expires_at,:created_at,:updated_at)'
        );
        $insert->execute([
            ':ticket_id' => $ticket,
            ':account_id' => $accountId,
            ':uid' => (int) ($user['id'] ?? 0),
            ':plugin_name' => $plugin,
            ':params' => json_encode($result['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':expires_at' => $now + 300,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return [
            'code' => 200,
            'message' => (string) ($result['message'] ?? 'success'),
            'data' => [
                'ticket_id' => $ticket,
                'qrcode' => (string) ($result['qrcode'] ?? ''),
                'options' => $result['options'] ?? [],
                'expires_at' => $now + 300,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function checkQrcode(PDO $db, array $ticket, array $account, array $user): array
    {
        if ((int) ($ticket['status'] ?? 0) !== 1) {
            return self::error('二维码登录票据已处理，请重新发起登录', 409);
        }
        if ((int) ($ticket['expires_at'] ?? 0) < time()) {
            self::expireTicket($db, (int) $ticket['id']);
            return self::error('二维码登录已超时，请重新扫码', 410);
        }

        $ticketParams = self::decodeArray($ticket['params'] ?? []);
        $plugin = (string) ($ticket['plugin_name'] ?? $account['plugin_name'] ?? '');
        $result = PaymentPluginRegistry::resolveAccount($plugin)->loginQrcodeCheck($db, $ticket + ['params' => $ticketParams], $account, $user);

        if ((int) ($result['code'] ?? 500) !== 200) {
            return $result;
        }
        $options = self::options($account);
        $options = array_replace($options, self::decodeArray($result['account_options'] ?? []));
        unset($result['account_options']);
        PluginAccountStore::saveOptions($db, (int) $account['id'], $options);
        PluginAccountStore::markOnline($db, (int) $account['id']);
        $update = $db->prepare('UPDATE qrcode_login_ticket SET status = 2, updated_at = :updated_at WHERE id = :id');
        $update->execute([':updated_at' => time(), ':id' => (int) $ticket['id']]);
        return $result;
    }

    /** @return array<string, mixed> */
    public static function action(array $account, string $func, array $params): array
    {
        $func = trim($func);
        if ($func === '') {
            return self::error('插件动作不能为空', 422);
        }
        return PaymentPluginRegistry::resolveAccount((string) ($account['plugin_name'] ?? ''))
            ->accountAction(Database::connection(), $account, [], $func, $params, []);
    }

    public static function unsupportedAction(string $func): array
    {
        return self::error('该插件未声明可执行的账号动作: ' . $func, 501);
    }

    public static function qnmLoginAdapter(PDO $db, array $account, array $user, array $params, string $site): array
    {
        $options = self::options($account);
        return self::qnmLogin(self::gateway($db, $options), $options, $user, $params, $site);
    }

    public static function qnmCheckAdapter(PDO $db, array $ticket, array $account, array $user): array
    {
        $options = self::options($account);
        return self::qnmCheck(self::gateway($db, $options), $options, self::decodeArray($ticket['params'] ?? []));
    }

    public static function guanjiaLoginAdapter(PDO $db, array $account, array $user, array $params, string $site): array
    {
        $options = self::options($account);
        return self::guanjiaLogin(self::gateway($db, $options), $options);
    }

    public static function guanjiaCheckAdapter(PDO $db, array $ticket, array $account, array $user): array
    {
        $options = self::options($account);
        return self::guanjiaCheck(self::gateway($db, $options), $options, self::decodeArray($ticket['params'] ?? []));
    }

    public static function yybLoginAdapter(PDO $db, array $account, array $user, array $params, string $site): array
    {
        $options = self::options($account);
        return self::yybLogin(self::gateway($db, $options), $options);
    }

    public static function yybCheckAdapter(PDO $db, array $ticket, array $account, array $user): array
    {
        $options = self::options($account);
        return self::yybCheck(self::gateway($db, $options), $options, self::decodeArray($ticket['params'] ?? []));
    }

    /** @return array<string, mixed> */
    public static function qnmBillAdapter(array $options, string $session, string $openid, int $now): array
    {
        $response = self::jsonRequest('POST', 'https://payapp.wechatpay.cn/tallybook/index', [
            'op_from' => 0,
            'classification' => 0,
            'year' => (int) date('Y', $now),
            'month' => (int) date('n', $now),
            'limit' => 100,
            'sort_type' => 1,
            'is_get_more' => 0,
            'skip_grant' => 0,
            'use_new_sys' => 1,
            'custom_session_key' => $session,
            'openid' => $openid,
        ], '全能码账本');
        if (!$response['ok']) {
            throw new \RuntimeException($response['message']);
        }
        return self::decodeArray($response['data']);
    }

    public static function amountInCents(string|int|float $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return 0;
        }
        if (str_contains($value, '.')) {
            [$yuan, $fen] = explode('.', $value, 2);
            return ((int) $yuan * 100) + (int) str_pad(substr($fen, 0, 2), 2, '0');
        }
        return (int) $value;
    }

    public static function timestamp(string|int|float $value, int $default = 0): int
    {
        if (is_numeric($value)) {
            $number = (int) $value;
            if ($number > 100000000000) {
                $number = (int) floor($number / 1000);
            }
            return $number;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }
        $parsed = strtotime($value);
        return $parsed === false ? $default : $parsed;
    }

    /** @return array<string, mixed> */
    private static function qnmLogin(string $gateway, array $options, array $user, array $params, string $site): array
    {
        $protocol = self::protocol($options);
        $prefix = match ($protocol) {
            'app' => '/WeChat_APP',
            'pc_tool' => '/WeChat_Pc_Tool',
            default => '/WeChat_YYB',
        };
        $payload = base64_encode(json_encode([
            'site' => rtrim($site, '/'),
            'pid' => (int) ($user['id'] ?? 0),
            'key' => (string) ($user['app_secret'] ?? ''),
            'token' => (string) ($options['yun_token'] ?? 'jiaowoliangzai'),
            'proxy' => self::proxy($options),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $created = self::gatewayRequest('POST', $gateway . $prefix . '/CreateID', 'data=' . rawurlencode($payload));
        if (!$created['ok']) {
            return self::error($created['message'], 500);
        }
        $uid = self::text($created['data'], ['uid']);
        if ((string) ($created['data']['code'] ?? '') !== '1' || $uid === '') {
            return self::error(self::textValue($created['data'], ['msg', 'message'], '微信网关创建登录会话失败'), 500);
        }
        $qr = self::gatewayRequest('POST', $gateway . $prefix . '/QRCode', 'uid=' . rawurlencode($uid));
        if (!$qr['ok']) {
            return self::error($qr['message'], 500);
        }
        $qrcode = self::text($qr['data'], ['url']);
        if ((string) ($qr['data']['code'] ?? '') !== '1' || $qrcode === '') {
            return self::error(self::textValue($qr['data'], ['msg', 'message'], '微信网关未返回登录二维码'), 500);
        }
        $bind = json_encode(['uid' => $uid, 'protocol' => $protocol], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return [
            'code' => 200,
            'message' => 'success',
            'qrcode' => $qrcode,
            'options' => ['uid' => $uid, 'protocol' => $protocol],
            'account_options' => ['uid' => $uid, 'bind_token' => $bind, 'bound_login_protocol' => $protocol],
        ];
    }

    /** @return array<string, mixed> */
    private static function qnmCheck(string $gateway, array $options, array $ticketParams): array
    {
        $uid = trim((string) ($ticketParams['uid'] ?? $options['uid'] ?? ''));
        $protocol = self::protocol($options);
        $bind = self::decodeArray($options['bind_token'] ?? []);
        if ($uid === '') {
            $uid = trim((string) ($bind['uid'] ?? ''));
        }
        if ($uid === '' || ($ticketParams['protocol'] ?? $protocol) !== $protocol) {
            return self::error('登录会话无效，请重新扫码', 500);
        }
        $prefix = match ($protocol) {
            'app' => '/WeChat_APP',
            'pc_tool' => '/WeChat_Pc_Tool',
            default => '/WeChat_YYB',
        };
        $status = self::gatewayRequest('POST', $gateway . $prefix . '/IsLoginStatus', 'uid=' . rawurlencode($uid));
        if (!$status['ok']) {
            return self::error($status['message'], 500);
        }
        $code = (string) ($status['data']['code'] ?? '');
        if ($code === '0') {
            return self::error(self::textValue($status['data'], ['msg'], '请扫码并在微信确认'), 201);
        }
        if ($code !== '1') {
            return self::error(self::textValue($status['data'], ['msg'], '微信网关登录状态异常'), 500);
        }
        $codeResponse = self::gatewayRequest('POST', $gateway . $prefix . '/GetCode', 'uid=' . rawurlencode($uid) . '&app_id=' . rawurlencode('wx7c86e0c731b9b8ef'));
        if (!$codeResponse['ok']) {
            return self::error($codeResponse['message'], 500);
        }
        $miniCode = self::text($codeResponse['data'], ['jscode', 'js_code', 'auth_code', 'code_value', 'code', 'Code']);
        if ($miniCode === '') {
            return self::error('微信网关未返回小程序登录 code', 500);
        }
        $session = self::qnmBootstrap($miniCode);
        if (!$session['ok']) {
            return self::error($session['message'], 500);
        }
        return [
            'code' => 200,
            'message' => '登录成功',
            'data' => [],
            'account_options' => [
                'uid' => $uid,
                'bind_token' => json_encode(['uid' => $uid, 'protocol' => $protocol], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'bound_login_protocol' => $protocol,
                'custom_session_key' => $session['session'],
                'tally_openid' => $session['openid'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function guanjiaLogin(string $gateway, array $options): array
    {
        $appId = (string) ($options['pay_mode'] ?? 'receipt') === 'smallbook'
            ? 'wx28be8489b7a36aaa'
            : 'wx28be8489b7a36aaa';
        $response = self::jsonRequest('POST', $gateway . '/guanjia/api/qrcode', ['appid' => $appId], '电脑管家');
        if (!$response['ok']) {
            return self::error($response['message'], 500);
        }
        $data = self::decodeArray($response['data']);
        $uuid = trim((string) ($data['uuid'] ?? ''));
        $qrcode = trim((string) ($data['qrcode'] ?? ''));
        if ($uuid === '' || $qrcode === '') {
            return self::error('电脑管家未返回完整登录二维码', 500);
        }
        $bind = json_encode(['session_id' => $uuid, 'appid' => $appId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return [
            'code' => 200,
            'message' => 'success',
            'qrcode' => $qrcode,
            'options' => ['session_id' => $uuid, 'appid' => $appId],
            'account_options' => ['bind_token' => $bind],
        ];
    }

    /** @return array<string, mixed> */
    private static function guanjiaCheck(string $gateway, array $options, array $ticketParams): array
    {
        $bind = self::decodeArray($options['bind_token'] ?? []);
        $sessionId = trim((string) ($ticketParams['session_id'] ?? $options['session_id'] ?? $bind['session_id'] ?? ''));
        if ($sessionId === '') {
            return self::error('缺少电脑管家登录会话', 500);
        }
        $response = self::jsonRequest('GET', $gateway . '/guanjia/api/status?uuid=' . rawurlencode($sessionId), null, '电脑管家');
        if (!$response['ok']) {
            return self::error($response['message'], 500);
        }
        $data = self::decodeArray($response['data']);
        if (in_array((string) ($data['status'] ?? ''), ['pending', 'ready'], true)) {
            return self::error('请扫描电脑管家登录二维码并在微信确认', 201);
        }
        if (($data['status'] ?? '') !== 'authorized') {
            return self::error((string) ($data['error'] ?? '电脑管家登录状态异常'), 500);
        }
        $result = self::decodeArray($data['result'] ?? []);
        $ref = trim((string) ($result['ref'] ?? $result['bind_account'] ?? ''));
        $code = trim((string) ($result['code'] ?? $result['Code'] ?? ''));
        if ($ref === '' || $code === '') {
            return self::error('电脑管家未返回完整账号标识', 500);
        }
        $sid = self::smallBookSid($code);
        if (!$sid['ok']) {
            return self::error($sid['message'], 500);
        }
        return [
            'code' => 200,
            'message' => '登录成功',
            'data' => [],
            'account_options' => [
                'sid' => $sid['sid'],
                'bind_token' => json_encode(['session_id' => $sessionId, 'ref' => $ref, 'appid' => $ticketParams['appid'] ?? $bind['appid'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'guanjia_ref' => $ref,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function yybLogin(string $gateway, array $options): array
    {
        $response = self::jsonRequest('POST', $gateway . '/yyb/qr', [], '应用宝');
        if (!$response['ok']) {
            return self::error($response['message'], 500);
        }
        $data = self::decodeArray($response['data']);
        $sessionId = trim((string) ($data['session_id'] ?? ''));
        $qrcode = trim((string) ($data['qrcode'] ?? ''));
        if ($sessionId === '' || $qrcode === '') {
            return self::error('应用宝未返回完整登录二维码', 500);
        }
        return [
            'code' => 200,
            'message' => 'success',
            'qrcode' => $qrcode,
            'options' => ['session_id' => $sessionId],
            'account_options' => [
                'bind_token' => json_encode(['session_id' => $sessionId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function yybCheck(string $gateway, array $options, array $ticketParams): array
    {
        $bind = self::decodeArray($options['bind_token'] ?? []);
        $sessionId = trim((string) ($ticketParams['session_id'] ?? $options['session_id'] ?? $bind['session_id'] ?? ''));
        if ($sessionId === '') {
            return self::error('缺少应用宝登录会话', 500);
        }
        $poll = self::jsonRequest('GET', $gateway . '/yyb/qr/' . rawurlencode($sessionId) . '/poll', null, '应用宝');
        if (!$poll['ok']) {
            return self::error($poll['message'], 500);
        }
        $data = self::decodeArray($poll['data']);
        if (in_array((string) ($data['status'] ?? ''), ['pending', 'scanned'], true)) {
            return self::error(($data['status'] ?? '') === 'scanned' ? '已扫码，请在微信确认' : '请扫描应用宝登录二维码', 201);
        }
        if (($data['status'] ?? '') !== 'authorized') {
            return self::error('应用宝登录状态异常: ' . (string) ($data['status'] ?? 'unknown'), 500);
        }
        $confirmed = self::jsonRequest('POST', $gateway . '/yyb/qr/' . rawurlencode($sessionId) . '/confirm', [], '应用宝');
        if (!$confirmed['ok']) {
            return self::error($confirmed['message'], 500);
        }
        $account = self::decodeArray($confirmed['data']);
        $ref = trim((string) ($account['openid'] ?? $account['uin'] ?? $account['id'] ?? ''));
        if ($ref === '') {
            return self::error('应用宝未返回账号标识', 500);
        }
        $codeResponse = self::jsonRequest('POST', $gateway . '/yyb/wxapp/getCode', [
            'ref' => $ref,
            'app_id' => 'wx28be8489b7a36aaa',
        ], '应用宝');
        if (!$codeResponse['ok']) {
            return self::error($codeResponse['message'], 500);
        }
        $result = self::decodeArray($codeResponse['data']['result'] ?? $codeResponse['data']);
        $code = trim((string) ($result['code'] ?? $result['Code'] ?? ''));
        if ($code === '') {
            return self::error('应用宝未返回小程序登录 code', 500);
        }
        $sid = self::smallBookSid($code);
        if (!$sid['ok']) {
            return self::error($sid['message'], 500);
        }
        return [
            'code' => 200,
            'message' => '登录成功',
            'data' => [],
            'account_options' => [
                'sid' => $sid['sid'],
                'bind_token' => json_encode(['ref' => $ref], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'yyb_ref' => $ref,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function qnmBootstrap(string $code): array
    {
        $index = self::jsonRequest('POST', 'https://payapp.wechatpay.cn/tallybook/index', [
            'op_from' => 0, 'classification' => 0, 'year' => (int) date('Y'),
            'month' => (int) date('n'), 'limit' => 20, 'sort_type' => 1,
            'is_get_more' => 0, 'skip_grant' => 0, 'use_new_sys' => 1, 'wxcode' => $code,
        ], '全能码小程序');
        if (!$index['ok']) {
            return $index;
        }
        $data = self::decodeArray($index['data']);
        $session = trim((string) ($data['custom_session_key'] ?? ''));
        if ($session === '') {
            return self::error('全能码接口未返回 custom_session_key', 500);
        }
        $entrance = self::jsonRequest('POST', 'https://payapp.wechatpay.cn/tallybook/entrance', [
            'op_from' => 0, 'custom_session_key' => $session,
        ], '全能码小程序');
        if (!$entrance['ok']) {
            return $entrance;
        }
        $entry = self::decodeArray($entrance['data']);
        return [
            'ok' => true,
            'session' => (string) ($entry['custom_session_key'] ?? $session),
            'openid' => (string) ($entry['openid'] ?? $data['openid'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private static function smallBookSid(string $code): array
    {
        $response = self::jsonRequest('GET', 'https://smallbook.wxpapp.weixin.qq.com/qrapp/user/login?js_code=' . rawurlencode($code), null, '微信小程序');
        if (!$response['ok']) {
            return self::error('获取微信 SID 失败: ' . $response['message'], 500);
        }
        $sid = self::text($response['data'], ['sid', 'Sid', 'receipt_sid', 'receiptSid']);
        if ($sid === '') {
            return self::error('小程序接口未返回 SID', 500);
        }
        return ['ok' => true, 'sid' => $sid];
    }

    private static function gateway(PDO $db, array $options): string
    {
        $id = (int) ($options['gateway'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('请先选择插件云端网关');
        }
        $query = $db->prepare('SELECT addr FROM channel_gateway WHERE id = :id AND status = 1 LIMIT 1');
        $query->execute([':id' => $id]);
        $addr = trim((string) ($query->fetchColumn() ?: ''));
        if ($addr === '') {
            throw new \RuntimeException('插件云端网关不存在或已停用');
        }
        return rtrim($addr, '/');
    }

    /** @return array<string, mixed> */
    private static function gatewayRequest(string $method, string $url, string $form): array
    {
        return self::http($method, $url, $form, ['Content-Type: application/x-www-form-urlencoded'], '网关');
    }

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    private static function jsonRequest(string $method, string $url, ?array $body, string $label): array
    {
        return self::http(
            $method,
            $url,
            $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Content-Type: application/json'],
            $label,
        );
    }

    /** @param list<string> $headers @return array<string, mixed> */
    private static function http(string $method, string $url, ?string $body, array $headers, string $label): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'message' => $label . ' HTTP 客户端初始化失败'];
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false || $error !== '') {
            return ['ok' => false, 'message' => $label . '请求失败: ' . ($error !== '' ? $error : '响应为空')];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => $label . '响应不是 JSON'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'message' => self::textValue($decoded, ['msg', 'message', 'error'], $label . '接口 HTTP ' . $status)];
        }
        return [
            'ok' => true,
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded,
            'envelope' => $decoded,
        ];
    }

    /** @return array<string, mixed> */
    private static function options(array $account): array
    {
        $options = PluginOptions::decode($account['options'] ?? []);
        foreach (['gateway', 'pay_mode', 'login_protocol'] as $key) {
            if (!array_key_exists($key, $options) && isset($account[$key]) && $account[$key] !== '') {
                $options[$key] = $account[$key];
            }
        }
        return $options;
    }

    private static function protocol(array $options): string
    {
        return in_array((string) ($options['login_protocol'] ?? 'yyb'), ['yyb', 'app', 'pc_tool'], true)
            ? (string) $options['login_protocol']
            : 'yyb';
    }

    private static function proxy(array $options): string
    {
        return (string) (($options['proxy_mode'] ?? '') === 'custom_proxy' ? ($options['proxy'] ?? '') : '');
    }

    /** @return array<string, mixed> */
    private static function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function text(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }
        foreach (['data', 'result', 'payload'] as $container) {
            if (is_array($data[$container] ?? null)) {
                $value = self::text($data[$container], $keys);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function textValue(array $data, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }
        return $default;
    }

    /** @return array<string, mixed> */
    private static function unsupported(string $message): array
    {
        return self::error($message, 501);
    }

    /** @return array<string, mixed> */
    private static function error(string $message, int $code): array
    {
        return ['code' => $code, 'message' => $message, 'data' => []];
    }

    private static function expireTicket(PDO $db, int $id): void
    {
        $query = $db->prepare('UPDATE qrcode_login_ticket SET status = 3, updated_at = :updated_at WHERE id = :id');
        $query->execute([':updated_at' => time(), ':id' => $id]);
    }
}
