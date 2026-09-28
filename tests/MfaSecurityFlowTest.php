<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use XArrPay\Support\Totp;

function mfaFlowEnsureCaptchaFont(): void
{
    if ((string) getenv('XARR_CAPTCHA_FONT') !== '') {
        return;
    }
    foreach ([
        'C:\\Windows\\Fonts\\arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    ] as $font) {
        if (is_file($font)) {
            putenv('XARR_CAPTCHA_FONT=' . $font);
            return;
        }
    }
}

function mfaFlowAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mfaFlowStep(string $message): void
{
    fwrite(STDERR, '[MFA FLOW] ' . $message . PHP_EOL);
    fflush(STDERR);
}

/** @param array<int,resource> $pipes */
function mfaFlowStopProcess(mixed $process, array $pipes): void
{
    if (is_resource($process)) {
        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
            exec('taskkill /PID ' . $pid . ' /T /F 2>NUL');
        } else {
            proc_terminate($process);
        }
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        proc_close($process);
    }
}

/**
 * @param array<string,string> $headers
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function mfaFlowRequest(
    string $base,
    string $path,
    string $method = 'GET',
    array $data = [],
    ?string $cookieFile = null,
    array $headers = []
): array {
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('curl 初始化失败');
    }
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $requestHeaders,
    ];
    if ($cookieFile !== null) {
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
    }
    if ($data !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        $options[CURLOPT_HTTPHEADER] = $requestHeaders;
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    if ($raw === false) {
        throw new RuntimeException('HTTP 请求失败：' . $error);
    }
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("{$method} {$path} 返回非 JSON {$status}：" . substr($body, 0, 200));
    }
    $decoded['_http_status'] = $status;
    return $decoded;
}

function mfaFlowCaptchaCode(string $cookieFile, string $sessionPath): string
{
    $cookie = file_get_contents($cookieFile) ?: '';
    if (preg_match('/xarr_php\s+([^\s;]+)/', $cookie, $matches) !== 1) {
        throw new RuntimeException('图形验证码会话 Cookie 不存在');
    }
    $directories = [
        $sessionPath,
        (string) ini_get('session.save_path'),
        sys_get_temp_dir(),
        'C:\\Windows\\Temp',
    ];
    foreach ($directories as $directory) {
        if ($directory === '') {
            continue;
        }
        $file = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $matches[1];
        if (!is_file($file)) {
            continue;
        }
        $contents = file_get_contents($file) ?: '';
        if (preg_match('/s:4:"code";s:\d+:"([^"]+)"/', $contents, $code) === 1) {
            return $code[1];
        }
    }
    throw new RuntimeException('图形验证码内容不存在');
}

/**
 * @return array{token:string,profile:array<string,mixed>}
 */
function mfaFlowLogin(
    string $base,
    string $cookieFile,
    string $sessionPath,
    string $username,
    string $password,
    string $secret,
    bool $expectMfa
): array {
    $captcha = mfaFlowRequest($base, '/api/login/captcha', 'GET', [], $cookieFile);
    $login = mfaFlowRequest($base, '/api/login', 'POST', [
        'username' => $username,
        'password' => $password,
        'captcha_id' => $captcha['data']['captcha_id'] ?? '',
        'captcha_code' => mfaFlowCaptchaCode($cookieFile, $sessionPath),
    ], $cookieFile);
    mfaFlowAssert((int) ($login['code'] ?? 0) === 200, '登录首段失败');

    if (!$expectMfa) {
        $token = (string) ($login['data']['data']['token'] ?? '');
        mfaFlowAssert($token !== '', '普通登录没有返回 token');
        $profile = mfaFlowRequest($base, '/api/user/profile', 'GET', [], $cookieFile, [
            'Authorization: ' . $token,
        ]);
        return ['token' => $token, 'profile' => $profile['data'] ?? []];
    }

    mfaFlowAssert((int) ($login['data']['status'] ?? 0) === 2, 'MFA 登录没有返回 status=2');
    $ticket = (string) ($login['data']['data']['ticket'] ?? '');
    mfaFlowAssert($ticket !== '', 'MFA 登录 ticket 为空');
    $generated = mfaFlowRequest($base, '/api/captcha/gen', 'POST', [
        'ticket' => $ticket,
        'captcha_type' => 'totp',
    ], $cookieFile);
    mfaFlowAssert(
        (string) ($generated['data']['list'][0]['captcha_type'] ?? '') === 'mfa',
        'MFA 登录没有返回 mfa 输入组件'
    );

    $wrong = mfaFlowRequest($base, '/api/login/safe', 'POST', [
        'ticket' => $ticket,
        'captcha_code' => '000000',
    ], $cookieFile);
    mfaFlowAssert((int) ($wrong['code'] ?? 0) === 401, '错误动态码没有被拒绝');

    $finish = mfaFlowRequest($base, '/api/login/safe', 'POST', [
        'ticket' => $ticket,
        'captcha_code' => Totp::code($secret),
    ], $cookieFile);
    mfaFlowAssert((int) ($finish['code'] ?? 0) === 200, '正确动态码没有完成登录');
    $token = (string) ($finish['data']['data']['token'] ?? '');
    mfaFlowAssert($token !== '', 'MFA 登录没有返回 token');
    $profile = mfaFlowRequest($base, '/api/user/profile', 'GET', [], $cookieFile, [
        'Authorization: ' . $token,
    ]);
    return ['token' => $token, 'profile' => $profile['data'] ?? []];
}

$dbPath = tempnam(sys_get_temp_dir(), 'xarr-mfa-flow-');
$cookieFile = tempnam(sys_get_temp_dir(), 'xarr-mfa-flow-cookie-');
$sessionPath = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '.mfa-flow-session-' . bin2hex(random_bytes(4));
$lockPath = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '.mfa-flow-' . bin2hex(random_bytes(4)) . '.lock';
if ($dbPath === false || $cookieFile === false || !mkdir($sessionPath, 0700, true)) {
    throw new RuntimeException('无法创建测试文件');
}

$dsn = 'sqlite:' . $dbPath;
putenv('XARR_DB_DSN=' . $dsn);
putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);
file_put_contents($lockPath, json_encode([
    'version' => 1,
    'installed_at' => gmdate('c'),
    'admin' => 'admin',
    'username' => 'mfa-flow',
], JSON_UNESCAPED_SLASHES));
mfaFlowEnsureCaptchaFont();
$secret = 'JBSWY3DPEHPK3PXP';
$db = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec((string) file_get_contents($root . '/database/schema.sqlite.sql'));
$db->exec("INSERT INTO options (`key`,value) VALUES ('web_title','MFA 测试站'),('web_index_title','MFA 测试站首页')");
$db->prepare(
    'INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at,mfa_enabled,mfa_secret) '
    . 'VALUES (1,:username,:password,:merchant_name,:app_secret,1,0,:created_at,0,"")'
)->execute([
    ':username' => 'mfa-flow',
    ':password' => md5('123456'),
    ':merchant_name' => 'MFA Flow',
    ':app_secret' => 'mfa-flow-key',
    ':created_at' => time(),
]);

$port = random_int(18080, 18999);
$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open(
    escapeshellarg(PHP_BINARY)
    . ' -d ' . escapeshellarg('session.save_path=' . $sessionPath)
    . ' -d session.name=xarr_php'
    . ' -S 127.0.0.1:' . $port . ' -t public public/router.php',
    $descriptor,
    $pipes,
    $root
);
if (!is_resource($process)) {
    throw new RuntimeException('无法启动 PHP 测试服务');
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

try {
    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($index = 0; $index < 30; $index++) {
        try {
            $health = mfaFlowRequest($base, '/api/health', 'GET', [], $cookieFile);
            if ((int) ($health['code'] ?? 0) === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    if (!$ready) {
        throw new RuntimeException('PHP 测试服务未启动');
    }

    mfaFlowStep('普通登录');
    $firstLogin = mfaFlowLogin($base, $cookieFile, $sessionPath, 'mfa-flow', '123456', $secret, false);
    mfaFlowAssert((int) ($firstLogin['profile']['mfa_enable'] ?? 1) === 0, '初始 MFA 状态错误');
    $token = $firstLogin['token'];
    $auth = ['Authorization: ' . $token];

    mfaFlowStep('首次绑定：默认关闭二次认证');
    $beginBind = mfaFlowRequest($base, '/api/security/step-up/begin', 'POST', [
        'operation' => 'mfa.bind',
    ], $cookieFile, $auth);
    mfaFlowAssert(($beginBind['data']['need_step_up'] ?? true) === false, '首次绑定默认不应要求二次认证');

    mfaFlowStep('首次绑定：生成密钥并提交动态码');
    $setup = mfaFlowRequest($base, '/api/user/safe/mfa', 'POST', [], $cookieFile, $auth);
    mfaFlowAssert((int) ($setup['code'] ?? 0) === 200, 'MFA step 1 失败');
    $generatedSecret = (string) ($setup['data']['secret'] ?? '');
    mfaFlowAssert($generatedSecret !== '', 'MFA step 1 没有生成密钥');

    $bind = mfaFlowRequest($base, '/api/user/safe/mfa', 'POST', [
        'step' => '2',
        'secret' => $generatedSecret,
        'code' => Totp::code($generatedSecret),
    ], $cookieFile, $auth);
    mfaFlowAssert(
        (int) ($bind['code'] ?? 0) === 200,
        'MFA 绑定失败：' . json_encode($bind, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    mfaFlowAssert((int) ($bind['data']['mfa_enable'] ?? 0) === 1, 'MFA 绑定状态没有返回 1');

    $replayBind = mfaFlowRequest($base, '/api/user/safe/mfa', 'POST', [
        'step' => '2',
        'secret' => $generatedSecret,
        'code' => Totp::code($generatedSecret),
    ], $cookieFile, $auth);
    mfaFlowAssert((int) ($replayBind['code'] ?? 0) === 401, '绑定票据可以重复使用');
    mfaFlowAssert(
        str_contains((string) ($replayBind['message'] ?? ''), '安全验证'),
        '绑定票据重放没有返回安全验证错误'
    );

    mfaFlowStep('MFA 登录');
    $mfaLogin = mfaFlowLogin($base, $cookieFile, $sessionPath, 'mfa-flow', '123456', $generatedSecret, true);
    mfaFlowAssert((int) ($mfaLogin['profile']['mfa_enable'] ?? 0) === 1, 'MFA 登录后状态错误');
    $mfaToken = $mfaLogin['token'];
    $mfaAuth = ['Authorization: ' . $mfaToken];

    mfaFlowStep('解绑：错误动态码');
    $beginUnbind = mfaFlowRequest($base, '/api/security/step-up/begin', 'POST', [
        'operation' => 'mfa.unbind',
    ], $cookieFile, $mfaAuth);
    mfaFlowAssert(($beginUnbind['data']['allow_types'] ?? []) === ['totp'], '解绑应要求动态口令');
    $unbindTicket = (string) ($beginUnbind['data']['ticket'] ?? '');
    $wrongTotp = mfaFlowRequest($base, '/api/security/step-up/finish', 'POST', [
        'ticket' => $unbindTicket,
        'type' => 'totp',
        'totp_code' => '000000',
    ], $cookieFile, $mfaAuth);
    mfaFlowAssert((int) ($wrongTotp['code'] ?? 0) === 401, '解绑错误动态码没有被拒绝');

    $finishUnbind = mfaFlowRequest($base, '/api/security/step-up/finish', 'POST', [
        'ticket' => $unbindTicket,
        'type' => 'totp',
        'totp_code' => Totp::code($generatedSecret),
    ], $cookieFile, $mfaAuth);
    mfaFlowAssert((int) ($finishUnbind['code'] ?? 0) === 200, '解绑动态码验证失败');
    $verifiedUnbindTicket = (string) ($finishUnbind['data']['ticket'] ?? '');
    mfaFlowStep('解绑：执行关闭');
    $unbind = mfaFlowRequest($base, '/api/user/safe/unmfa', 'POST', [], $cookieFile, array_merge($mfaAuth, [
        'X-Step-Up-Ticket: ' . $verifiedUnbindTicket,
    ]));
    mfaFlowAssert((int) ($unbind['code'] ?? 0) === 200, 'MFA 解绑失败');
    mfaFlowAssert((int) ($unbind['data']['mfa_enable'] ?? 1) === 0, 'MFA 解绑状态没有返回 0');

    $afterUnbind = mfaFlowRequest($base, '/api/user/profile', 'GET', [], $cookieFile, $mfaAuth);
    mfaFlowAssert((int) ($afterUnbind['data']['mfa_enable'] ?? 1) === 0, '解绑后 profile 状态错误');
} finally {
    mfaFlowStopProcess($process, $pipes);
    @unlink($dbPath);
    @unlink($cookieFile);
    @unlink($lockPath);
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $sessionFile) {
        @unlink($sessionFile);
    }
    @rmdir($sessionPath);
}

echo "MfaSecurityFlowTest: OK\n";
