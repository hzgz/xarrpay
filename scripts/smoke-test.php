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
$createdMerchantAccountId = 0;
$createdReferralUserId = 0;
$createdBalanceLogId = 0;
$createdWorkOrderCategoryId = 0;
$createdWorkOrderId = 0;
$createdWorkOrderReplyId = 0;
$createdAdminWorkOrderId = 0;
$createdPollingRuleId = 0;
$createdAdminOrders = [];
$auth = [];
$merchantUid = 0;
$originalBaseOptions = null;
$currentRequest = '';

$smokeDsn = (string) (getenv('XARR_DB_DSN') ?: 'sqlite:' . $root . '/var/local.sqlite');
if (!str_starts_with($smokeDsn, 'sqlite:')) {
    throw new RuntimeException('Smoke test requires an isolated SQLite DSN.');
}
$db = new PDO($smokeDsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$reset = $db->prepare('UPDATE "order" SET status = 1, pay_type = "", trade_amount = amount, actual_amount = "", pay_time = NULL, expire_time = :expire_time, updated_at = :updated_at WHERE order_id = "LOCAL-DEMO-0001"');
$reset->execute([':expire_time' => time() + 900, ':updated_at' => time()]);

if ($cookieFile === false) {
    fwrite(STDERR, "无法创建临时 Cookie 文件。\n");
    exit(1);
}

function requestJson(string $base, string $path, string $method = 'GET', array $data = [], ?string $cookieFile = null): array
{
    global $currentRequest;
    $currentRequest = $method . ' ' . $path;
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

function expectChineseMessage(array $response, string $label): void
{
    $message = (string) ($response['message'] ?? '');
    expect(
        preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1,
        $label . ': expected a Chinese message, got ' . $message
    );
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
    expect(imagesx($captchaImage) === 220 && imagesy($captchaImage) === 88, '验证码图片尺寸不为 220x88');
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
    $merchantProfile = requestJsonWithHeaders($base, '/api/user/profile', 'GET', [], $cookieFile, ['Authorization: ' . $merchantToken]);
    expectCode($merchantProfile, 200, '商户登录后读取资料');
    $merchantUid = (int) ($merchantProfile['data']['id'] ?? $merchantProfile['data']['pid'] ?? 0);
    expect($merchantUid > 0, '商户登录 ID 缺失');
    $merchantAuth = ['Authorization: ' . $merchantToken];
    $inviteInfo = requestJsonWithHeaders($base, '/api/invite/info', 'GET', [], $cookieFile, $merchantAuth);
    expectCode($inviteInfo, 200, 'invite/info');
    $recommendCode = 'u' . $merchantUid;
    expect((string) ($inviteInfo['data']['recommend_code'] ?? '') === $recommendCode, '邀请推荐码不匹配');
    expect((int) ($inviteInfo['data']['reg_user_count'] ?? -1) >= 0, '邀请注册人数不是数字');
    expect((string) ($inviteInfo['data']['invite_url'] ?? '') === '/r/' . $recommendCode, '邀请地址不匹配');
    $inviteVisit = requestRaw($base, '/r/' . $recommendCode, 'GET', [], $cookieFile);
    expect($inviteVisit['status'] === 302, '邀请链接没有重定向到注册页');
    $registerCaptcha = requestJson($base, '/api/register/captcha', 'GET', [], $cookieFile);
    expectCode($registerCaptcha, 200, 'register/captcha');
    $registerCode = sessionCode($cookieFile);
    $registered = requestJson($base, '/api/register', 'POST', [
        'username' => 'smoke_ref_' . date('His'),
        'password' => 'smoke-password',
        'invite_code' => $recommendCode,
        'captcha_id' => $registerCaptcha['data']['captcha_id'],
        'captcha_code' => $registerCode,
    ], $cookieFile);
    expectCode($registered, 200, 'register with invite code');
    $createdReferralUserId = (int) ($registered['data']['id'] ?? 0);
    expect($createdReferralUserId > 0, '邀请注册用户 ID 缺失');
    $referralRow = $db->prepare('SELECT recommend_uid FROM user WHERE id=:id');
    $referralRow->execute([':id' => $createdReferralUserId]);
    $registeredReferrerId = (int) $referralRow->fetchColumn();
    $referralRow->closeCursor();
    expect($registeredReferrerId === $merchantUid, '注册用户推荐人关联未保存');
    $inviteInfoAfter = requestJsonWithHeaders($base, '/api/invite/info', 'GET', [], $cookieFile, $merchantAuth);
    expect((int) ($inviteInfoAfter['data']['reg_user_count'] ?? 0) >= 1, '邀请注册人数未更新');

    $db->prepare('INSERT INTO balance_log (uid,type,amount,before_balance,after_balance,remark,created_at) VALUES (:uid,\'adjust\',125,1000,1125,\'smoke balance contract\',:created_at)')
        ->execute([':uid' => $merchantUid, ':created_at' => time()]);
    $createdBalanceLogId = (int) $db->lastInsertId();
    $balanceLogs = requestJsonWithHeaders($base, '/api/log/balance?page=1&limit=20', 'GET', [], $cookieFile, $merchantAuth);
    expectCode($balanceLogs, 200, 'log/balance');
    $balanceRows = $balanceLogs['data']['list'] ?? [];
    $balanceRow = current(array_filter($balanceRows, static fn (array $row): bool => (int) ($row['id'] ?? 0) === $createdBalanceLogId));
    expect(is_array($balanceRow), '资金流水临时记录未返回');
    expect((int) ($balanceRow['balance'] ?? -1) === 125, '资金流水操作金额字段未映射');
    expect((int) ($balanceRow['before'] ?? -1) === 1000, '资金流水变更前字段未映射');
    expect((int) ($balanceRow['after'] ?? -1) === 1125, '资金流水变更后字段未映射');
    expect(array_key_exists('org_name', $balanceRow), '资金流水操作对象字段缺失');

    $merchantAccount = requestJsonWithHeaders($base, '/api/channel/account/create', 'POST', [
        'pay_type' => 'alipay',
        'channel_code' => 'demo',
        'account' => 'smoke merchant account',
        'account_type' => 'smoke',
        'name' => 'Smoke Merchant Account',
        'status' => 1,
    ], $cookieFile, $merchantAuth);
    expectCode($merchantAccount, 200, 'merchant channel/account/create');
    $createdMerchantAccountId = (int) ($merchantAccount['data']['id'] ?? 0);
    expect($createdMerchantAccountId > 0, '商户收款账号 ID 缺失');
    $merchantAccountRow = requestJsonWithHeaders($base, '/api/channel/account/detail?id=' . $createdMerchantAccountId, 'GET', [], $cookieFile, $merchantAuth);
    expectCode($merchantAccountRow, 200, 'merchant channel/account/detail');
    expect((int) ($merchantAccountRow['data']['created_at'] ?? 0) > 0, '收款账号创建时间缺失');
    expect((int) ($merchantAccountRow['data']['updated_at'] ?? 0) > 0, '收款账号更新时间缺失');
    expectCode(requestJsonWithHeaders($base, '/api/channel/account/edit', 'POST', [
        'id' => $createdMerchantAccountId,
        'pay_type' => 'alipay',
        'channel_code' => 'demo',
        'account' => 'smoke merchant account edited',
        'account_type' => 'smoke',
        'name' => 'Smoke Merchant Account Edited',
        'status' => 1,
    ], $cookieFile, $merchantAuth), 200, 'merchant channel/account/edit');
    $merchantAccountAfterEdit = requestJsonWithHeaders($base, '/api/channel/account/detail?id=' . $createdMerchantAccountId, 'GET', [], $cookieFile, $merchantAuth);
    expectCode($merchantAccountAfterEdit, 200, 'merchant channel/account/detail after edit');
    expect((string) ($merchantAccountAfterEdit['data']['account'] ?? '') === 'smoke merchant account edited', '商户收款账号编辑未保存');
    expect((int) ($merchantAccountAfterEdit['data']['updated_at'] ?? 0) >= (int) ($merchantAccountAfterEdit['data']['created_at'] ?? 0), '收款账号更新时间早于创建时间');
    $unsupportedPluginAction = requestJsonWithHeaders($base, '/api/channel/account/plugin-action', 'POST', [
        'account_id' => $createdMerchantAccountId,
        'id' => $createdMerchantAccountId,
        'func' => 'test',
    ], $cookieFile, $merchantAuth);
    expectCode($unsupportedPluginAction, 501, 'merchant channel/account/plugin-action unsupported');
    expectChineseMessage($unsupportedPluginAction, 'merchant channel/account/plugin-action unsupported');
    $unsupportedQrcodeLogin = requestJsonWithHeaders($base, '/api/channel/account/qrcode-login/qrcode', 'POST', [
        'id' => $createdMerchantAccountId,
    ], $cookieFile, $merchantAuth);
    expectCode($unsupportedQrcodeLogin, 501, 'merchant channel/account/qrcode-login unsupported');
    expectChineseMessage($unsupportedQrcodeLogin, 'merchant channel/account/qrcode-login unsupported');

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
    expectCode($merchantNotice, 200, 'merchant /api/notice/show?position=4');
    foreach ([
        '/api/user/rsa-key/info',
        '/api/work-order/cate/list',
        '/api/work-order/list?page=1&limit=20',
    ] as $path) {
        $response = requestJsonWithHeaders($base, $path, 'GET', [], $cookieFile, $merchantAuth);
        expectCode($response, 200, 'merchant feature ' . $path);
    }
    $channelFormItems = requestJsonWithHeaders($base, '/api/channel/form-items?pay_type=alipay&pay_channel=demo', 'GET', [], $cookieFile, $merchantAuth);
    expectCode($channelFormItems, 200, 'merchant channel/form-items');
    expect(is_array($channelFormItems['data'] ?? null), 'merchant channel/form-items data is not an array');
    $channelGateways = requestJsonWithHeaders($base, '/api/channel/gateway?pay_type=alipay&channel_code=demo', 'GET', [], $cookieFile, $merchantAuth);
    expectCode($channelGateways, 200, 'merchant channel/gateway');
    expect(is_array($channelGateways['data'] ?? null), 'merchant channel/gateway data is not an array');
    foreach ([
        '/api/user/verification/ticket/status',
        '/api/user/sms-credit/info',
    ] as $path) {
        $response = requestJsonWithHeaders($base, $path, 'GET', [], $cookieFile, $merchantAuth);
        expectCode($response, 501, 'unsupported merchant feature ' . $path);
        expectChineseMessage($response, 'unsupported merchant feature ' . $path);
    }
    foreach ([
        '/api/user/connect/channels',
        '/api/user/connect/list',
        '/api/user/passkey/list',
        '/api/user/rsa-key/info',
    ] as $path) {
        $response = requestJsonWithHeaders($base, $path, 'GET', [], $cookieFile, $merchantAuth);
        expectCode($response, 200, 'merchant security feature ' . $path);
    }
    $rebateRecords = requestJsonWithHeaders($base, '/api/invite/rebate_record?page=1&limit=20', 'GET', [], $cookieFile, $merchantAuth);
    expectCode($rebateRecords, 200, 'invite/rebate_record');
    expect(is_array($rebateRecords['data']['list'] ?? null), '返佣明细 list 字段缺失');
    expect(array_key_exists('total', $rebateRecords['data'] ?? []), '返佣明细 total 字段缺失');
    foreach ([
        '/api/user/connect/channels',
        '/api/user/rsa-key/info',
        '/api/work-order/cate/list',
    ] as $path) {
        $response = requestJson($base, $path, 'GET', [], $cookieFile);
        expectCode($response, 404, 'unauthenticated merchant feature ' . $path);
    }

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

    $suffix = date('YmdHis') . random_int(100, 999);
    $category = requestJsonWithHeaders($base, '/api/admin/work-order/category/create', 'POST', [
        'name' => 'Smoke Work Order ' . $suffix,
        'status' => 1,
        'sort' => 50,
    ], $cookieFile, $auth);
    expectCode($category, 200, 'work-order/category/create');
    $createdWorkOrderCategoryId = (int) ($category['data']['id'] ?? 0);
    expect($createdWorkOrderCategoryId > 0, '工单分类 ID 缺失');

    $invalidCategoryWorkOrder = requestJsonWithHeaders($base, '/api/work-order/create', 'POST', [
        'category_id' => 999999,
        'title' => 'Invalid category',
        'content' => 'Must be rejected',
    ], $cookieFile, $merchantAuth);
    expectCode($invalidCategoryWorkOrder, 422, 'work-order rejects invalid category');

    $workOrder = requestJsonWithHeaders($base, '/api/work-order/create', 'POST', [
        'category_id' => $createdWorkOrderCategoryId,
        'title' => 'Smoke Work Order',
        'content' => 'Initial merchant message',
    ], $cookieFile, $merchantAuth);
    expectCode($workOrder, 200, 'work-order/create');
    $createdWorkOrderId = (int) ($workOrder['data']['id'] ?? 0);
    expect($createdWorkOrderId > 0, '工单 ID 缺失');

    $badAttachmentReply = requestJsonWithHeaders($base, '/api/work-order/reply', 'POST', [
        'pid' => $createdWorkOrderId,
        'content' => 'Reject foreign attachment',
        'attachments' => ['/foreign/file.png'],
    ], $cookieFile, $merchantAuth);
    expectCode($badAttachmentReply, 422, 'work-order rejects unowned attachment');

    $staffReply = requestJsonWithHeaders($base, '/api/admin/work-order/reply/create', 'POST', [
        'pid' => $createdWorkOrderId,
        'content' => 'Staff response',
    ], $cookieFile, $auth);
    expectCode($staffReply, 200, 'work-order staff reply');
    $createdWorkOrderReplyId = (int) ($staffReply['data']['id'] ?? 0);
    $workOrderDetail = requestJsonWithHeaders($base, '/api/admin/work-order/detail?id=' . $createdWorkOrderId, 'GET', [], $cookieFile, $auth);
    expectCode($workOrderDetail, 200, 'work-order/detail after staff reply');
    expect((int) ($workOrderDetail['data']['status'] ?? 0) === 2, '客服回复后工单状态应为 2');
    expectCode(requestJsonWithHeaders($base, '/api/admin/work-order/switch-status', 'POST', [
        'id' => $createdWorkOrderId,
        'status' => 3,
    ], $cookieFile, $auth), 200, 'work-order close');
    $closedWorkOrder = requestJsonWithHeaders($base, '/api/admin/work-order/detail?id=' . $createdWorkOrderId, 'GET', [], $cookieFile, $auth);
    expect((int) ($closedWorkOrder['data']['status'] ?? 0) === 3, '工单结单状态未保存');
    expect((int) ($closedWorkOrder['data']['closed_at'] ?? 0) > 0, '工单结单时间未保存');
    $merchantReplies = requestJsonWithHeaders($base, '/api/work-order/reply/list?pid=' . $createdWorkOrderId, 'GET', [], $cookieFile, $merchantAuth);
    expectCode($merchantReplies, 200, 'merchant work-order/reply/list');
    expect((string) ($merchantReplies['data']['info']['cate_name'] ?? '') === 'Smoke Work Order ' . $suffix, '工单详情分类名未返回');
    expect((int) ($merchantReplies['data']['info']['status'] ?? 0) === 3, '工单详情状态未返回');
    expect(count($merchantReplies['data']['list'] ?? []) === 1, '工单详情回复列表未按工单过滤');
    expectCode(requestJsonWithHeaders($base, '/api/work-order/reply', 'POST', [
        'pid' => $createdWorkOrderId,
        'content' => 'Merchant follow-up',
        'attachments' => [],
    ], $cookieFile, $merchantAuth), 200, 'merchant work-order/reply');
    $reopenedWorkOrder = requestJsonWithHeaders($base, '/api/admin/work-order/detail?id=' . $createdWorkOrderId, 'GET', [], $cookieFile, $auth);
    expect((int) ($reopenedWorkOrder['data']['status'] ?? 0) === 1, '商户回复后工单应重新打开');
    expect(($reopenedWorkOrder['data']['closed_at'] ?? null) === null, '重新打开工单仍有结单时间');
    $adminWorkOrder = requestJsonWithHeaders($base, '/api/admin/work-order/list/create', 'POST', [
        'uid' => $merchantUid,
        'category_id' => $createdWorkOrderCategoryId,
        'title' => 'Admin Smoke Work Order',
        'content' => 'Created from admin action',
        'status' => 1,
        'priority' => 0,
    ], $cookieFile, $auth);
    expectCode($adminWorkOrder, 200, 'admin work-order/create');
    $createdAdminWorkOrderId = (int) ($adminWorkOrder['data']['id'] ?? 0);
    expect($createdAdminWorkOrderId > 0, '后台工单创建 ID 缺失');

    $pollingRule = requestJsonWithHeaders($base, '/api/polling/create', 'POST', [
        'name' => 'Smoke Polling ' . $suffix,
        'status' => 2,
        'account_ids' => [],
    ], $cookieFile, $merchantAuth);
    expectCode($pollingRule, 200, 'merchant polling/create');
    $createdPollingRuleId = (int) ($pollingRule['data']['id'] ?? 0);
    expect($createdPollingRuleId > 0, '轮询池 ID 缺失');
    $pollingChannelsBefore = requestJsonWithHeaders(
        $base,
        '/api/polling/channel?polling_id=' . $createdPollingRuleId,
        'GET',
        [],
        $cookieFile,
        $merchantAuth
    );
    expectCode($pollingChannelsBefore, 200, 'merchant polling/channel empty');
    expect(is_array($pollingChannelsBefore['data'] ?? null), '轮询通道接口必须返回数组');
    $pollingChannelsSave = requestJsonWithHeaders($base, '/api/polling/save-channel', 'POST', [
        'polling_id' => $createdPollingRuleId,
        'channels' => [[
            'account_id' => $createdMerchantAccountId,
            'weight' => 80,
            'pay_count' => 3,
        ]],
    ], $cookieFile, $merchantAuth);
    expectCode($pollingChannelsSave, 200, 'merchant polling/save-channel weighted');
    $pollingChannelsAfter = requestJsonWithHeaders(
        $base,
        '/api/polling/channel?polling_id=' . $createdPollingRuleId,
        'GET',
        [],
        $cookieFile,
        $merchantAuth
    );
    expectCode($pollingChannelsAfter, 200, 'merchant polling/channel configured');
    $configuredChannel = $pollingChannelsAfter['data'][0] ?? null;
    expect(
        is_array($configuredChannel)
        && (int) ($configuredChannel['account_id'] ?? 0) === $createdMerchantAccountId
        && (int) ($configuredChannel['weight'] ?? 0) === 80
        && (int) ($configuredChannel['pay_count'] ?? 0) === 3,
        '轮询通道权重配置未回读'
    );
    $invalidPollingAccount = requestJsonWithHeaders($base, '/api/polling/save-channel', 'POST', [
        'id' => $createdPollingRuleId,
        'account_ids' => [999999],
    ], $cookieFile, $merchantAuth);
    expectCode($invalidPollingAccount, 422, 'polling rejects unowned account');
    $pollingList = requestJsonWithHeaders($base, '/api/polling/list', 'GET', [], $cookieFile, $merchantAuth);
    expectCode($pollingList, 200, 'merchant polling/list');
    $pollingRows = $pollingList['data']['list'] ?? [];
    $pollingRow = current(array_filter($pollingRows, static fn (array $row): bool => (int) ($row['id'] ?? 0) === $createdPollingRuleId));
    expect(is_array($pollingRow) && (int) $pollingRow['status'] === 2, '停用的自有轮询池应可查看');
    expectCode(requestJsonWithHeaders($base, '/api/polling/edit', 'POST', [
        'id' => $createdPollingRuleId,
        'name' => 'Smoke Polling Edited ' . $suffix,
        'status' => 2,
        'account_ids' => [],
    ], $cookieFile, $merchantAuth), 200, 'merchant polling/edit disabled rule');

    foreach (['/api/admin/pay-type/list', '/api/admin/channel/list', '/api/admin/channel/account/list', '/api/admin/order/list', '/api/admin/statistics/info', '/api/admin/plugins/list'] as $path) {
        $response = requestJsonWithHeaders($base, $path, 'GET', [], $cookieFile, $auth);
        expectCode($response, 200, $path);
    }
    foreach ([
        '/api/admin/pay/conf',
        '/api/admin/home/info',
        '/api/admin/home/pay-distribution',
        '/api/admin/home/merchant-register',
        '/api/admin/home/order-amount',
        '/api/admin/home/daily-recharge',
        '/api/admin/home/merchant-ranking',
        '/api/admin/home/authorize',
        '/api/admin/home/safe-rate',
        '/api/admin/system/info',
        '/api/admin/system/redis/status',
    ] as $path) {
        $response = requestJsonWithHeaders($base, $path, 'GET', [], $cookieFile, $auth);
        expectCode($response, 200, $path);
    }
    foreach ([
        '/api/admin/log/files',
        '/api/admin/staff-action-log/list?page=1&limit=20',
        '/api/admin/log/staff-action-log?page=1&limit=20',
        '/api/admin/notification/list?page=1&limit=20',
        '/api/admin/notification/template/list?page=1&limit=20',
        '/api/admin/page/list?page=1&limit=20',
        '/api/admin/polling/list?page=1&limit=20',
        '/api/admin/storage-channel/list?page=1&limit=20',
        '/api/admin/storage-channel/plugin/list',
        '/api/admin/storage-file/list?page=1&limit=20',
        '/api/admin/storage-file/stats',
        '/api/admin/storage-file/orphan/scan',
        '/api/admin/third-account/list?page=1&limit=20',
        '/api/admin/third-account/log/list?page=1&limit=20',
        '/api/admin/third-order-log/list?page=1&limit=20',
        '/api/admin/sms-channel/list?page=1&limit=20',
        '/api/admin/system/runtime',
        '/api/admin/shared-channel/capability',
        '/api/admin/work-order/category/list?page=1&limit=20',
        '/api/admin/work-order/list?page=1&limit=20',
        '/api/admin/work-order/reply/list?page=1&limit=20',
        '/api/admin/work-order/cate/list?page=1&limit=20',
        '/api/admin/work-order/list/list?page=1&limit=20',
        '/api/admin/channel/gateway/list?page=1&limit=20',
        '/api/admin/third-accounts/list?page=1&limit=20',
        '/api/admin/third-accounts/log/list?page=1&limit=20',
        '/api/admin/domain-white/list?page=1&limit=20',
        '/api/admin/black-data/list?page=1&limit=20',
        '/api/admin/meal/list?page=1&limit=20',
        '/api/admin/system-order/list?page=1&limit=20',
        '/api/admin/cards/groups/list?page=1&limit=20',
        '/api/admin/cards/list?page=1&limit=20',
        '/api/admin/area/list',
        '/api/admin/proxy-pool/list?page=1&pageSize=20',
        '/api/admin/option/proxy-pool',
        '/api/admin/option/captcha/verify',
        '/api/admin/option/pay',
        '/api/admin/sms/channel/list?page=1&limit=20',
        '/api/admin/storage/channel/list?page=1&limit=20',
        '/api/admin/storage/file/stats',
        '/api/admin/storage/file/list?page=1&limit=20',
        '/api/admin/service-account-pool/list?page=1&limit=20',
        '/api/admin/qrcode-templates/list?page=1&limit=20',
        '/api/admin/templates/index/list',
        '/api/admin/templates/pay/list',
        '/api/admin/templates/cashier/list',
        '/api/admin/templates/user/list',
        '/api/admin/third-connect-chat/list?page=1&limit=20',
        '/api/admin/app-items/list?limit=0',
        '/api/admin/option/templates',
        '/api/admin/connect/items',
        '/api/admin/connect/templates',
        '/api/admin/tools/config-wizard/check',
        '/api/admin/sms-channel/plugin/list',
        '/api/admin/sms-channel/plugin/form-items?plugin_name=',
        '/api/admin/sms/plugin/list',
        '/api/admin/sms/plugin/form-items?plugin_name=',
    ] as $path) {
        $response = requestJsonWithHeaders($base, $path, 'GET', [], $cookieFile, $auth);
        expectCode($response, 200, 'admin feature ' . $path);
    }
    foreach ([
        '/api/admin/system/check-version',
        '/api/admin/system/version-logs',
        '/api/admin/shared-channel/rule/list?page=1&limit=20',
        '/api/admin/staff/connect-channels',
        '/api/admin/staff/passkey/list',
    ] as $path) {
        $response = requestJsonWithHeaders($base, $path, 'GET', [], $cookieFile, $auth);
        expectCode($response, 501, 'unsupported admin feature ' . $path);
        expectChineseMessage($response, 'unsupported admin feature ' . $path);
    }
    foreach ([
        '/api/admin/log/files',
        '/api/admin/page/list',
        '/api/admin/storage-channel/list',
        '/api/admin/system/runtime',
        '/api/admin/work-order/list',
    ] as $path) {
        $response = requestJson($base, $path, 'GET', [], $cookieFile);
        expectCode($response, 404, 'unauthenticated admin feature ' . $path);
    }

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

    $unsupportedChannel = requestJsonWithHeaders($base, '/api/admin/channel/create', 'POST', [
        'code' => 'smoke_unsupported_channel_' . $suffix,
        'name' => 'Smoke Unsupported Channel',
        'type' => 'smoke',
        'plugin_name' => 'static_code',
        'status' => 1,
    ], $cookieFile, $auth);
    expectCode($unsupportedChannel, 422, 'channel/create rejects unsupported plugin pay type');

    $channel = requestJsonWithHeaders($base, '/api/admin/channel/create', 'POST', [
        'code' => 'smoke_channel_' . $suffix,
        'name' => 'Smoke Channel',
        'type' => 'alipay',
        'plugin_name' => 'static_code',
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
        'type' => 'alipay',
        'plugin_name' => 'static_code',
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
        'uid' => $merchantUid,
        'pay_type' => 'alipay',
        'channel_code' => 'smoke_channel_' . $suffix,
        'account' => 'smoke-account-' . $suffix,
        'account_type' => 'smoke',
        'name' => 'Smoke Account',
        'status' => 1,
        'sort' => 10,
        'remark' => 'smoke account',
        'options' => ['mode' => 'test'],
        'bind_pay_type' => ['alipay'],
    ], $cookieFile, $auth);
    expectCode($account, 200, 'channel/account/create');
    $createdAccountId = (int) ($account['data']['id'] ?? 0);
    expect($createdAccountId > 0, '收款账号创建 ID 缺失');
    expectCode(requestJsonWithHeaders($base, '/api/admin/channel/account/edit', 'POST', [
        'id' => $createdAccountId,
        'uid' => $merchantUid,
        'pay_type' => 'alipay',
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
    $payTypeDelete = requestJsonWithHeaders($base, '/api/admin/pay-type/remove', 'POST', ['id' => $createdPayTypeId], $cookieFile, $auth);
    expectCode($payTypeDelete, 200, 'pay-type/remove without linked account');

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

    $merchantSecretQuery = $db->prepare('SELECT app_secret FROM user WHERE id=:uid LIMIT 1');
    $merchantSecretQuery->execute([':uid' => $merchantUid]);
    $merchantSecret = (string) $merchantSecretQuery->fetchColumn();
    $merchantSecretQuery->closeCursor();
    unset($merchantSecretQuery);
    expect($merchantSecret !== '', '商户密钥缺失');

    $testOrder = requestJsonWithHeaders($base, '/api/admin/order/create-test', 'POST', [
        'uid' => $merchantUid,
        'amount' => 234,
        'subject' => 'Smoke Admin Order 1',
    ], $cookieFile, $auth);
    expectCode($testOrder, 200, 'order/create-test 1');
    $createdAdminOrders[] = (string) ($testOrder['data']['order_id'] ?? '');
    $testOrderTwo = requestJsonWithHeaders($base, '/api/admin/order/create-test', 'POST', [
        'uid' => $merchantUid,
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

    $parse = requestJson($base, '/api/cashier/parse', 'POST', ['key' => $merchantSecret], $cookieFile);
    expectCode($parse, 200, 'cashier/parse');
    $cashier = requestJson($base, '/api/order/create-cashier', 'POST', ['key' => $merchantSecret, 'amount' => '123'], $cookieFile);
    expectCode($cashier, 200, 'order/create-cashier');
    $createdOutOrders[] = 'cashier_' . (string) ($cashier['data']['trade_no'] ?? '');

    $epayParams = [
        'pid' => (string) $merchantUid,
        'type' => 'alipay',
        'out_trade_no' => 'SMOKE-' . date('YmdHis'),
        'name' => 'PHP 验收订单',
        'money' => '1.00',
        'notify_url' => 'http://127.0.0.1/notify',
        'return_url' => 'http://127.0.0.1/return',
        'sign_type' => 'MD5',
    ];
    $epayParams['sign'] = XArrPay\Support\EpaySigner::sign($epayParams, $merchantSecret);
    $mapi = requestJson($base, '/api/epay/mapi.php', 'POST', $epayParams, $cookieFile);
    expect((int) ($mapi['code'] ?? 0) === 1, 'epay/mapi');
    $createdOutOrders[] = $epayParams['out_trade_no'];
    $tradeNo = (string) ($mapi['trade_no'] ?? '');
    expect($tradeNo !== '', 'epay trade_no 缺失');

    $queryParams = ['pid' => (string) $merchantUid, 'key' => $merchantSecret, 'act' => 'order', 'trade_no' => $tradeNo];
    $query = requestJson($base, '/api/epay/api.php', 'POST', $queryParams, $cookieFile);
    expect((int) ($query['code'] ?? 0) === 1, 'epay/api order');

    $notify = [
        'pid' => (string) $merchantUid,
        'trade_status' => 'TRADE_SUCCESS',
        'out_trade_no' => $epayParams['out_trade_no'],
        'money' => '1.00',
        'sign_type' => 'MD5',
    ];
    $notify['sign'] = XArrPay\Support\EpaySigner::sign($notify, $merchantSecret);
    $notifyResponse = requestRaw($base, '/api/epay/notify.php', 'POST', $notify, $cookieFile);
    expect($notifyResponse['status'] === 200 && trim($notifyResponse['body']) === 'success', 'epay/notify');

    $queryAfterNotify = requestJson($base, '/api/epay/api.php', 'POST', $queryParams, $cookieFile);
    expect((int) ($queryAfterNotify['status'] ?? 0) === 1, 'epay/api paid status');

    echo "SMOKE TEST: OK\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "SMOKE TEST: FAIL at {$currentRequest} - " . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($createdWorkOrderId > 0) {
        $db->prepare('DELETE FROM work_order_reply WHERE pid=:id')->execute([':id' => $createdWorkOrderId]);
        $db->prepare('DELETE FROM work_order WHERE id=:id')->execute([':id' => $createdWorkOrderId]);
    }
    if ($createdAdminWorkOrderId > 0) {
        $db->prepare('DELETE FROM work_order_reply WHERE pid=:id')->execute([':id' => $createdAdminWorkOrderId]);
        $db->prepare('DELETE FROM work_order WHERE id=:id')->execute([':id' => $createdAdminWorkOrderId]);
    }
    if ($createdWorkOrderCategoryId > 0) {
        $db->prepare('DELETE FROM work_order_category WHERE id=:id')->execute([':id' => $createdWorkOrderCategoryId]);
    }
    if ($createdPollingRuleId > 0) {
        $db->prepare('DELETE FROM polling_rule WHERE id=:id AND uid=:uid')
            ->execute([':id' => $createdPollingRuleId, ':uid' => $merchantUid]);
    }
    foreach ($createdOutOrders as $outOrder) {
        if ($outOrder !== '') {
            $db->prepare('DELETE FROM notify_queue WHERE order_id IN (SELECT order_id FROM "order" WHERE out_order_id = :out_order_id)')->execute([':out_order_id' => $outOrder]);
            $delete = $db->prepare('DELETE FROM "order" WHERE out_order_id = :out_order_id');
            $delete->execute([':out_order_id' => $outOrder]);
        }
    }
    foreach ($createdAdminOrders as $orderId) {
        if ($orderId !== '') {
            $db->prepare('DELETE FROM notify_queue WHERE order_id = :order_id')->execute([':order_id' => $orderId]);
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
    if ($createdMerchantAccountId > 0) {
        $db->prepare('DELETE FROM pay_codes WHERE account_id=:id')->execute([':id' => $createdMerchantAccountId]);
        $db->prepare('DELETE FROM pay_account_ext WHERE account_id=:id')->execute([':id' => $createdMerchantAccountId]);
        $db->prepare('DELETE FROM pay_account WHERE id=:id')->execute([':id' => $createdMerchantAccountId]);
    }
    if ($createdReferralUserId > 0) {
        $db->prepare('DELETE FROM user WHERE id=:id')->execute([':id' => $createdReferralUserId]);
    }
    $inviteVisitorHash = hash('sha256', '127.0.0.1' . "\0" . 'xarr-php-smoke-test' . "\0" . date('Y-m-d'));
    $db->prepare('DELETE FROM invite_visit WHERE uid=:uid AND visitor_hash=:visitor_hash AND visit_day=:visit_day')
        ->execute([
            ':uid' => $merchantUid,
            ':visitor_hash' => $inviteVisitorHash,
            ':visit_day' => date('Y-m-d'),
        ]);
    if ($createdBalanceLogId > 0) {
        $db->prepare('DELETE FROM balance_log WHERE id=:id')->execute([':id' => $createdBalanceLogId]);
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
    global $currentRequest;
    $currentRequest = $method . ' ' . $path;
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
        CURLOPT_USERAGENT => 'xarr-php-smoke-test',
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
    global $currentRequest;
    $currentRequest = $method . ' ' . $path;
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
