<?php

declare(strict_types=1);

// Local-only HTTP smoke test. It uses the demo SQLite database and removes
// the orders created by this run before exiting.
$base = rtrim((string) ($argv[1] ?? 'http://127.0.0.1:8088'), '/');
$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$cookieFile = tempnam(sys_get_temp_dir(), 'xarr-cookie-');
$createdOutOrders = [];
$createdPayTypeId = 0;
$createdChannelId = 0;
$createdAccountId = 0;
$createdAdminOrders = [];
$auth = [];
$originalBaseOptions = null;

$db = new PDO('sqlite:' . $root . '/var/local.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$reset = $db->prepare('UPDATE "order" SET status = 1, pay_type = "", trade_amount = amount, actual_amount = "", pay_time = NULL, expire_time = :expire_time, updated_at = :updated_at WHERE order_id = "LOCAL-DEMO-0001"');
$reset->execute([':expire_time' => time() + 900, ':updated_at' => time()]);

if ($cookieFile === false) {
    fwrite(STDERR, "无法创建临时 Cookie 文件。\n");
    exit(1);
}

function requestJson(string $base, string $path, string $method = 'GET', array $data = [], ?string $cookieFile = null): array
{
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('curl 初始化失败');
    }

    $headers = ['Accept: application/json'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($cookieFile !== null) {
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
    }
    if ($data !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    if ($raw === false) {
        throw new RuntimeException('HTTP 请求失败: ' . $error);
    }
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("{$method} {$path} 返回非 JSON (HTTP {$status}): " . substr($body, 0, 300));
    }
    $decoded['_http_status'] = $status;
    return $decoded;
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectCode(array $response, int $code, string $label): void
{
    expect((int) ($response['code'] ?? -1) === $code, $label . ': code=' . (string) ($response['code'] ?? 'missing'));
}

function sessionCode(string $cookieFile): string
{
    $cookie = file_get_contents($cookieFile) ?: '';
    if (preg_match('/xarr_php\s+([^\s;]+)/', $cookie, $matches) !== 1) {
        throw new RuntimeException('验证码响应未写入 xarr_php Cookie。');
    }
    $savePath = (string) ini_get('session.save_path');
    $candidates = [$savePath, sys_get_temp_dir(), 'C:\\Windows\\Temp'];
    foreach ($candidates as $directory) {
        $file = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $matches[1];
        if (!is_file($file)) {
            continue;
        }
        $contents = file_get_contents($file) ?: '';
        if (preg_match('/s:4:"code";s:\d+:"([^"]+)"/', $contents, $code) === 1) {
            return $code[1];
        }
    }
    throw new RuntimeException('无法从本地 session 文件读取验证码。');
}

try {
    $home = requestRaw($base, '/', 'GET', [], $cookieFile);
    expect($home['status'] === 200, '首页未返回 HTTP 200');
    expect(str_contains($home['body'], '/index/assets/js/index-c0004b59.js'), '首页未加载原程序首页模板 bundle');
    expect(!str_contains($home['body'], '/original-home/'), '首页仍引用错误的模板资源目录');
    foreach ([
        '/index/favicon.ico',
        '/index/assets/js/index-c0004b59.js',
        '/index/assets/js/FeatureCards-933a4003.js',
        '/index/assets/css/index-25e6f205.css',
    ] as $assetPath) {
        $asset = requestRaw($base, $assetPath, 'GET', [], $cookieFile);
        expect($asset['status'] === 200 && $asset['body'] !== '', '首页资源加载失败: ' . $assetPath);
    }

    $health = requestJson($base, '/api/health', 'GET', [], $cookieFile);
    expectCode($health, 200, 'health');

    $config = requestJson($base, '/api/config', 'GET', [], $cookieFile);
    expectCode($config, 200, 'config');

    $captcha = requestJson($base, '/api/login/captcha', 'GET', [], $cookieFile);
    expectCode($captcha, 200, 'captcha');
    expect((string) ($captcha['data']['captcha_id'] ?? '') !== '', 'captcha_id 缺失');
    expect(str_starts_with((string) ($captcha['data']['captcha_base64'] ?? ''), 'data:image/png;base64,'), '验证码图片缺失');
    $captchaCode = sessionCode($cookieFile);
    expect(preg_match('/^\d{4}$/', $captchaCode) === 1, '验证码不是四位纯数字');
    $captchaPng = base64_decode(substr((string) $captcha['data']['captcha_base64'], strlen('data:image/png;base64,')), true);
    expect(is_string($captchaPng), '验证码 PNG base64 无法解码');
    $captchaImage = @imagecreatefromstring($captchaPng);
    expect($captchaImage !== false, '验证码 PNG 无法解析');
    expect(imagesx($captchaImage) === 360 && imagesy($captchaImage) === 144, '验证码图片尺寸不为 360x144');
    imagedestroy($captchaImage);

    $badLogin = requestJson($base, '/api/login', 'POST', [
        'username' => 'coco',
        'password' => '123456',
        'captcha_id' => $captcha['data']['captcha_id'],
        'captcha_code' => 'BAD',
    ], $cookieFile);
    expectCode($badLogin, 502, '错误验证码商户登录');

    $captcha = requestJson($base, '/api/login/captcha', 'GET', [], $cookieFile);
    $captchaCode = sessionCode($cookieFile);
    expect(preg_match('/^\d{4}$/', $captchaCode) === 1, '第二次验证码不是四位纯数字');
    $wrongPassword = requestJson($base, '/api/login', 'POST', [
        'username' => 'coco',
        'password' => 'wrong-password',
        'captcha_id' => $captcha['data']['captcha_id'],
        'captcha_code' => $captchaCode,
    ], $cookieFile);
    expectCode($wrongPassword, 401, '商户错误密码登录');

    $captcha = requestJson($base, '/api/login/captcha', 'GET', [], $cookieFile);
    $captchaCode = sessionCode($cookieFile);
    $merchantLogin = requestJson($base, '/api/login', 'POST', [
        'username' => 'coco',
        'password' => '123456',
        'captcha_id' => $captcha['data']['captcha_id'],
        'captcha_code' => $captchaCode,
    ], $cookieFile);
    expectCode($merchantLogin, 200, 'coco 商户正确登录');
    expect((int) ($merchantLogin['data']['status'] ?? 0) === 1, '商户登录 data.status 不为 1');
    $merchantToken = (string) ($merchantLogin['data']['data']['token'] ?? '');
    expect($merchantToken !== '', '商户登录 token 缺失');
    $merchantAuth = ['Authorization: ' . $merchantToken];
    $merchantProfileMissing = requestJson($base, '/api/user/profile', 'GET', [], $cookieFile);
    expectCode($merchantProfileMissing, 404, '未登录 user/profile');
    $merchantProfile = requestJsonWithHeaders($base, '/api/user/profile', 'GET', [], $cookieFile, $merchantAuth);
    expectCode($merchantProfile, 200, 'user/profile(token)');
    expect((string) ($merchantProfile['data']['username'] ?? '') === 'coco', '商户资料账号不匹配');
    expect(!array_key_exists('app_secret', $merchantProfile['data'] ?? []), '商户资料泄露 app_secret');
    foreach ([
        '/api/pay-type/list',
        '/api/channel/list',
        '/api/channel/account/list?page=1&limit=200',
        '/api/order/list?page=1&limit=20',
        '/api/statistics/info',
        '/api/static/order/base',
        '/api/static/pay-type',
        '/api/static/pay-hour-stats',
        '/api/static/pay-daily-stats',
        '/api/notice/shows?position=8&page=1&limit=10&detail=1',
        '/api/plugins/list',
    ] as $path) {
        $merchantResponse = requestJsonWithHeaders($base, $path, 'GET', [], $cookieFile, $merchantAuth);
        expectCode($merchantResponse, 200, 'merchant ' . $path);
    }
    $merchantNotice = requestJsonWithHeaders($base, '/api/notice/show?position=4', 'GET', [], $cookieFile, $merchantAuth);
    expectCode($merchantNotice, 204, 'merchant /api/notice/show?position=4');

    $captcha = requestJson($base, '/api/admin/login/captcha', 'GET', [], $cookieFile);
    expectCode($captcha, 200, 'admin captcha');
    $adminCaptchaCode = sessionCode($cookieFile);
    expect(preg_match('/^\d{4}$/', $adminCaptchaCode) === 1, '管理员验证码不是四位纯数字');
    $adminLogin = requestJson($base, '/api/admin/login', 'POST', [
        'username' => 'admin',
        'password' => '123456',
        'captcha_id' => $captcha['data']['captcha_id'],
        'captcha_code' => $adminCaptchaCode,
    ], $cookieFile);
    expectCode($adminLogin, 200, '管理员登录');
    $adminToken = (string) ($adminLogin['data']['token'] ?? '');
    expect($adminToken !== '', '管理员 token 缺失');
    $auth = ['Authorization: ' . $adminToken];
    $profile = requestJson($base, '/api/admin/staff/profile', 'GET', [], $cookieFile);
    expectCode($profile, 404, '未登录 admin/staff/profile');
    $profile = requestJsonWithHeaders($base, '/api/admin/staff/profile', 'GET', [], $cookieFile, $auth);
    expectCode($profile, 200, 'admin/staff/profile(token)');
    foreach (['/api/admin/pay-type/list', '/api/admin/channel/list', '/api/admin/channel/account/list', '/api/admin/order/list', '/api/admin/statistics/info', '/api/admin/plugins/list'] as $path) {
        $response = requestJsonWithHeaders($base, $path, 'GET', [], $cookieFile, $auth);
        expectCode($response, 200, $path);
    }

    $suffix = date('YmdHis') . random_int(100, 999);
    $payType = requestJsonWithHeaders($base, '/api/admin/pay-type/create', 'POST', [
        'value' => 'smoke_' . $suffix,
        'code' => 'smoke_' . $suffix,
        'name' => 'Smoke Pay Type',
        'label' => 'Smoke 支付方式',
        'status' => 1,
    ], $cookieFile, $auth);
    expectCode($payType, 200, 'pay-type/create');
    $createdPayTypeId = (int) ($payType['data']['id'] ?? 0);
    expect($createdPayTypeId > 0, '支付方式创建 ID 缺失');
    $payTypeEdit = requestJsonWithHeaders($base, '/api/admin/pay-type/edit', 'POST', [
        'id' => $createdPayTypeId,
        'value' => 'smoke_' . $suffix,
        'code' => 'smoke_' . $suffix,
        'name' => 'Smoke Pay Type Edited',
        'label' => 'Smoke 支付方式已编辑',
        'status' => 1,
    ], $cookieFile, $auth);
    expectCode($payTypeEdit, 200, 'pay-type/edit');
    expectCode(requestJsonWithHeaders($base, '/api/admin/pay-type/switch-status', 'POST', [
        'id' => $createdPayTypeId,
        'status' => 0,
    ], $cookieFile, $auth), 200, 'pay-type/switch-status off');
    expectCode(requestJsonWithHeaders($base, '/api/admin/pay-type/switch-status', 'POST', [
        'id' => $createdPayTypeId,
        'status' => 1,
    ], $cookieFile, $auth), 200, 'pay-type/switch-status on');

    $channel = requestJsonWithHeaders($base, '/api/admin/channel/create', 'POST', [
        'code' => 'smoke_channel_' . $suffix,
        'name' => 'Smoke Channel',
        'type' => 'smoke',
        'status' => 1,
        'remark' => 'smoke test',
        'options' => ['mode' => 'test'],
    ], $cookieFile, $auth);
    expectCode($channel, 200, 'channel/create');
    $createdChannelId = (int) ($channel['data']['id'] ?? 0);
    expect($createdChannelId > 0, '通道创建 ID 缺失');
    $channelRow = requestJsonWithHeaders($base, '/api/admin/channel/detail?id=' . $createdChannelId, 'GET', [], $cookieFile, $auth);
    expectCode($channelRow, 200, 'channel/detail');
    expect((string) ($channelRow['data']['code'] ?? '') === 'smoke_channel_' . $suffix, '通道详情代码不匹配');
    expectCode(requestJsonWithHeaders($base, '/api/admin/channel/edit', 'POST', [
        'id' => $createdChannelId,
        'code' => 'smoke_channel_' . $suffix,
        'name' => 'Smoke Channel Edited',
        'type' => 'smoke',
        'status' => 1,
        'remark' => 'smoke edited',
    ], $cookieFile, $auth), 200, 'channel/edit');
    expectCode(requestJsonWithHeaders($base, '/api/admin/channel/switch-status', 'POST', [
        'id' => $createdChannelId,
        'status' => 0,
    ], $cookieFile, $auth), 200, 'channel/switch-status off');
    expectCode(requestJsonWithHeaders($base, '/api/admin/channel/switch-status', 'POST', [
        'id' => $createdChannelId,
        'status' => 1,
    ], $cookieFile, $auth), 200, 'channel/switch-status on');

    $account = requestJsonWithHeaders($base, '/api/admin/channel/account/create', 'POST', [
        'uid' => 1,
        'pay_type' => 'smoke_' . $suffix,
        'channel_code' => 'smoke_channel_' . $suffix,
        'account' => 'smoke-account-' . $suffix,
        'account_type' => 'smoke',
        'name' => 'Smoke Account',
        'status' => 1,
        'sort' => 10,
        'remark' => 'smoke account',
        'options' => ['mode' => 'test'],
        'bind_pay_type' => ['smoke_' . $suffix],
    ], $cookieFile, $auth);
    expectCode($account, 200, 'channel/account/create');
    $createdAccountId = (int) ($account['data']['id'] ?? 0);
    expect($createdAccountId > 0, '收款账号创建 ID 缺失');
    expectCode(requestJsonWithHeaders($base, '/api/admin/channel/account/edit', 'POST', [
        'id' => $createdAccountId,
        'uid' => 1,
        'pay_type' => 'smoke_' . $suffix,
        'channel_code' => 'smoke_channel_' . $suffix,
        'account' => 'smoke-account-edited-' . $suffix,
        'account_type' => 'smoke',
        'name' => 'Smoke Account Edited',
        'status' => 1,
        'sort' => 20,
        'remark' => 'smoke account edited',
    ], $cookieFile, $auth), 200, 'channel/account/edit');
    expectCode(requestJsonWithHeaders($base, '/api/admin/channel/account/switch-status', 'POST', [
        'id' => $createdAccountId,
        'status' => 0,
    ], $cookieFile, $auth), 200, 'channel/account/switch-status off');
    expectCode(requestJsonWithHeaders($base, '/api/admin/channel/account/switch-status', 'POST', [
        'id' => $createdAccountId,
        'status' => 1,
    ], $cookieFile, $auth), 200, 'channel/account/switch-status on');
    $blockedChannelDelete = requestJsonWithHeaders($base, '/api/admin/channel/remove', 'POST', ['id' => $createdChannelId], $cookieFile, $auth);
    expectCode($blockedChannelDelete, 409, 'channel/remove with account protection');
    $blockedPayTypeDelete = requestJsonWithHeaders($base, '/api/admin/pay-type/remove', 'POST', ['id' => $createdPayTypeId], $cookieFile, $auth);
    expectCode($blockedPayTypeDelete, 409, 'pay-type/remove with account protection');

    $originalBase = requestJsonWithHeaders($base, '/api/admin/option/base', 'GET', [], $cookieFile, $auth);
    expectCode($originalBase, 200, 'admin/option/base GET');
    $originalBaseOptions = is_array($originalBase['data'] ?? null) ? $originalBase['data'] : [];
    $baseMarker = 'smoke-' . $suffix;
    expectCode(requestJsonWithHeaders($base, '/api/admin/option/base', 'POST', [
        'smoke_marker' => $baseMarker,
    ], $cookieFile, $auth), 200, 'admin/option/base POST');
    $baseAfter = requestJsonWithHeaders($base, '/api/admin/option/base', 'GET', [], $cookieFile, $auth);
    expectCode($baseAfter, 200, 'admin/option/base GET after POST');
    expect((string) ($baseAfter['data']['smoke_marker'] ?? '') === $baseMarker, '配置保存未生效');

    $testOrder = requestJsonWithHeaders($base, '/api/admin/order/create-test', 'POST', [
        'amount' => 234,
        'subject' => 'Smoke Admin Order 1',
    ], $cookieFile, $auth);
    expectCode($testOrder, 200, 'order/create-test 1');
    $createdAdminOrders[] = (string) ($testOrder['data']['order_id'] ?? '');
    $testOrderTwo = requestJsonWithHeaders($base, '/api/admin/order/create-test', 'POST', [
        'amount' => 345,
        'subject' => 'Smoke Admin Order 2',
    ], $cookieFile, $auth);
    expectCode($testOrderTwo, 200, 'order/create-test 2');
    $createdAdminOrders[] = (string) ($testOrderTwo['data']['order_id'] ?? '');
    expect($createdAdminOrders[0] !== '' && $createdAdminOrders[1] !== '', '后台测试订单号缺失');
    expectCode(requestJsonWithHeaders($base, '/api/admin/order/success', 'POST', ['order_id' => $createdAdminOrders[0]], $cookieFile, $auth), 200, 'order/success');
    expectCode(requestJsonWithHeaders($base, '/api/admin/order/callback', 'POST', ['order_id' => $createdAdminOrders[0]], $cookieFile, $auth), 200, 'order/callback');
    expectCode(requestJsonWithHeaders($base, '/api/admin/order/wait', 'POST', ['order_id' => $createdAdminOrders[0]], $cookieFile, $auth), 200, 'order/wait');
    expectCode(requestJsonWithHeaders($base, '/api/admin/order/close', 'POST', ['order_id' => $createdAdminOrders[0]], $cookieFile, $auth), 200, 'order/close');
    expectCode(requestJsonWithHeaders($base, '/api/admin/order/batch-remove', 'POST', [
        'order_ids' => $createdAdminOrders,
    ], $cookieFile, $auth), 200, 'order/batch-remove');
    $createdAdminOrders = [];
    $compliance = requestJsonWithHeaders($base, '/api/admin/option/compliance', 'GET', [], $cookieFile, $auth);
    expectCode($compliance, 200, 'admin/option/compliance GET');
    expect((string) ($compliance['data']['required_text'] ?? '') !== '', '合规确认文本缺失');
    $confirm = requestJsonWithHeaders($base, '/api/admin/option/compliance', 'POST', [
        'content' => (string) $compliance['data']['required_text'],
    ], $cookieFile, $auth);
    expectCode($confirm, 200, 'admin/option/compliance POST');
    $complianceAfter = requestJsonWithHeaders($base, '/api/admin/option/compliance', 'GET', [], $cookieFile, $auth);
    expect(($complianceAfter['data']['need_confirm'] ?? true) === false, '合规确认状态未保存');

    $order = requestJson($base, '/api/order/info', 'POST', ['order_id' => 'LOCAL-DEMO-0001'], $cookieFile);
    expectCode($order, 200, 'order/info');
    $types = requestJson($base, '/api/pay/types', 'POST', ['order_id' => 'LOCAL-DEMO-0001'], $cookieFile);
    expectCode($types, 200, 'pay/types');
    $choose = requestJson($base, '/api/order/chose-type', 'POST', ['order_id' => 'LOCAL-DEMO-0001', 'pay_type' => 'alipay'], $cookieFile);
    expectCode($choose, 200, 'order/chose-type');
    $qr = requestJson($base, '/api/order/qrcode', 'POST', ['order_id' => 'LOCAL-DEMO-0001'], $cookieFile);
    expectCode($qr, 200, 'order/qrcode');
    $status = requestJson($base, '/api/order/status', 'POST', ['order_id' => 'LOCAL-DEMO-0001'], $cookieFile);
    expectCode($status, 200, 'order/status');

    $parse = requestJson($base, '/api/cashier/parse', 'POST', ['key' => 'local-demo-key'], $cookieFile);
    expectCode($parse, 200, 'cashier/parse');
    $cashier = requestJson($base, '/api/order/create-cashier', 'POST', ['key' => 'local-demo-key', 'amount' => '123'], $cookieFile);
    expectCode($cashier, 200, 'order/create-cashier');
    $createdOutOrders[] = 'cashier_' . (string) ($cashier['data']['trade_no'] ?? '');

    $epayParams = [
        'pid' => '1',
        'type' => 'alipay',
        'out_trade_no' => 'SMOKE-' . date('YmdHis'),
        'name' => 'PHP 验收订单',
        'money' => '1.00',
        'notify_url' => 'http://127.0.0.1/notify',
        'return_url' => 'http://127.0.0.1/return',
        'sign_type' => 'MD5',
    ];
    $epayParams['sign'] = XArrPay\Support\EpaySigner::sign($epayParams, 'local-demo-key');
    $mapi = requestJson($base, '/api/epay/mapi.php', 'POST', $epayParams, $cookieFile);
    expect((int) ($mapi['code'] ?? 0) === 1, 'epay/mapi');
    $createdOutOrders[] = $epayParams['out_trade_no'];
    $tradeNo = (string) ($mapi['trade_no'] ?? '');
    expect($tradeNo !== '', 'epay trade_no 缺失');

    $queryParams = ['pid' => '1', 'key' => 'local-demo-key', 'act' => 'order', 'trade_no' => $tradeNo];
    $query = requestJson($base, '/api/epay/api.php', 'POST', $queryParams, $cookieFile);
    expect((int) ($query['code'] ?? 0) === 1, 'epay/api order');

    $notify = [
        'pid' => '1',
        'trade_status' => 'TRADE_SUCCESS',
        'out_trade_no' => $epayParams['out_trade_no'],
        'money' => '1.00',
        'sign_type' => 'MD5',
    ];
    $notify['sign'] = XArrPay\Support\EpaySigner::sign($notify, 'local-demo-key');
    $notifyResponse = requestRaw($base, '/api/epay/notify.php', 'POST', $notify, $cookieFile);
    expect($notifyResponse['status'] === 200 && trim($notifyResponse['body']) === 'success', 'epay/notify');

    $queryAfterNotify = requestJson($base, '/api/epay/api.php', 'POST', $queryParams, $cookieFile);
    expect((int) ($queryAfterNotify['status'] ?? 0) === 1, 'epay/api paid status');

    echo "SMOKE TEST: OK\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "SMOKE TEST: FAIL - " . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    foreach ($createdOutOrders as $outOrder) {
        if ($outOrder !== '') {
            $delete = $db->prepare('DELETE FROM "order" WHERE out_order_id = :out_order_id');
            $delete->execute([':out_order_id' => $outOrder]);
        }
    }
    foreach ($createdAdminOrders as $orderId) {
        if ($orderId !== '') {
            $delete = $db->prepare('DELETE FROM "order" WHERE order_id = :order_id');
            $delete->execute([':order_id' => $orderId]);
        }
    }
    if ($originalBaseOptions !== null && $auth !== []) {
        try {
            requestJsonWithHeaders($base, '/api/admin/option/base', 'POST', $originalBaseOptions, $cookieFile, $auth);
        } catch (Throwable) {
            // The direct database fallback below still restores the exact JSON value.
        }
    }
    if ($createdAccountId > 0) {
        $db->prepare('DELETE FROM pay_account WHERE id = :id')->execute([':id' => $createdAccountId]);
    }
    if ($createdChannelId > 0) {
        $db->prepare('DELETE FROM pay_channel WHERE id = :id')->execute([':id' => $createdChannelId]);
    }
    if ($createdPayTypeId > 0) {
        $db->prepare('DELETE FROM pay_type WHERE id = :id')->execute([':id' => $createdPayTypeId]);
    }
    @unlink($cookieFile);
}

exit($exitCode ?? 0);

function requestRaw(string $base, string $path, string $method, array $data, string $cookieFile): array
{
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('curl 初始化失败');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($body === false) {
        throw new RuntimeException('HTTP 请求失败: ' . $error);
    }
    return ['status' => $status, 'body' => $body];
}

function requestJsonWithHeaders(string $base, string $path, string $method, array $data, string $cookieFile, array $extraHeaders): array
{
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('curl 初始化失败');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $extraHeaders),
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_TIMEOUT => 10,
    ];
    if ($data !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    if ($raw === false) {
        throw new RuntimeException('HTTP 请求失败: ' . $error);
    }
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("{$method} {$path} 返回非 JSON (HTTP {$status})");
    }
    $decoded['_http_status'] = $status;
    return $decoded;
}
