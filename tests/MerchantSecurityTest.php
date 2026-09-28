<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;
use XArrPay\Support\SecurityTicket;
use XArrPay\Support\WebAuthn;

function merchantSecurityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function merchantSecurityRequest(
    string $base,
    string $path,
    string $method = 'GET',
    array $data = [],
    array $headers = [],
    ?string $cookieFile = null
): array {
    if ($data !== [] && in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
        $path .= (str_contains($path, '?') ? '&' : '?')
            . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $data = [];
    }
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('curl 初始化失败');
    }
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 5,
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
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
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
        throw new RuntimeException("{$method} {$path} 返回非 JSON {$status}：" . substr($body, 0, 300));
    }
    $decoded['_http_status'] = $status;
    return $decoded;
}

function merchantSecurityStepUp(string $base, string $token, string $operation, string $password): string
{
    $auth = ['Authorization: ' . $token];
    $begin = merchantSecurityRequest($base, '/api/security/step-up/begin', 'POST', [
        'operation' => $operation,
    ], $auth);
    merchantSecurityAssert((int) ($begin['code'] ?? 0) === 200, "{$operation} step-up 发起失败");
    merchantSecurityAssert(
        in_array('password', $begin['data']['allow_types'] ?? [], true),
        "{$operation} 未提供当前密码验证因子"
    );
    $ticket = (string) ($begin['data']['ticket'] ?? '');
    merchantSecurityAssert($ticket !== '', "{$operation} step-up 票据为空");

    $finish = merchantSecurityRequest($base, '/api/security/step-up/finish', 'POST', [
        'ticket' => $ticket,
        'type' => 'password',
        'password' => $password,
    ], $auth);
    merchantSecurityAssert((int) ($finish['code'] ?? 0) === 200, "{$operation} step-up 完成失败");
    return (string) ($finish['data']['ticket'] ?? $ticket);
}

function merchantSecurityStopProcess(mixed $process, array $pipes): void
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

function merchantSecurityStartServer(string $root, string $sessionPath, int $port): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $command = escapeshellarg(PHP_BINARY)
        . ' -d ' . escapeshellarg('session.save_path=' . $sessionPath)
        . ' -d session.name=xarr_php'
        . ' -S 127.0.0.1:' . $port . ' -t public public/router.php';
    $process = proc_open($command, $descriptor, $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动 PHP 测试服务');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $base = 'http://127.0.0.1:' . $port;
    for ($index = 0; $index < 40; $index++) {
        try {
            $health = merchantSecurityRequest($base, '/api/health');
            if ((int) ($health['code'] ?? 0) === 200) {
                return [$process, $pipes, $base];
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    $stderr = stream_get_contents($pipes[2]) ?: '';
    merchantSecurityStopProcess($process, $pipes);
    throw new RuntimeException('PHP 测试服务未启动：' . trim($stderr));
}

function merchantSecurityMakeAssertion(
    string $challenge,
    string $origin,
    string $rpId,
    int $signCount,
    mixed $privateKey
): array {
    $clientData = json_encode([
        'type' => 'webauthn.get',
        'challenge' => $challenge,
        'origin' => $origin,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($clientData)) {
        throw new RuntimeException('clientDataJSON 生成失败');
    }
    $authenticatorData = hash('sha256', $rpId, true) . "\x01"
        . pack('N', $signCount);
    $signature = '';
    if (!openssl_sign($authenticatorData . hash('sha256', $clientData, true), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('WebAuthn 测试签名生成失败');
    }
    return [
        'authenticator_data' => $authenticatorData,
        'client_data' => $clientData,
        'signature' => $signature,
    ];
}

$root = dirname(__DIR__);
$dbPath = tempnam(sys_get_temp_dir(), 'xarr-merchant-security-');
$cookieFile = tempnam(sys_get_temp_dir(), 'xarr-merchant-security-cookie-');
$sessionPath = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '.merchant-security-session-' . bin2hex(random_bytes(4));
$lockPath = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '.merchant-security-' . bin2hex(random_bytes(4)) . '.lock';
if ($dbPath === false || $cookieFile === false || !mkdir($sessionPath, 0700, true)) {
    throw new RuntimeException('无法创建商户安全测试文件');
}

putenv('XARR_DB_DSN=sqlite:' . $dbPath);
putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);
file_put_contents($lockPath, json_encode([
    'version' => 1,
    'installed_at' => gmdate('c'),
    'admin' => 'admin',
    'username' => 'merchant-security',
], JSON_UNESCAPED_SLASHES));
putenv('XARR_SITE_URL=http://127.0.0.1');
putenv('XARR_WEBAUTHN_RP_ID=127.0.0.1');
putenv('XARR_WEBAUTHN_ORIGIN=http://127.0.0.1');
putenv('XARR_RSA_ENCRYPTION_KEY=test-only-rsa-encryption-key');
putenv('XARR_PLATFORM_RSA_PUBLIC_KEY=-----BEGIN PUBLIC KEY-----' . "\n" . 'test-platform-key' . "\n" . '-----END PUBLIC KEY-----');
$opensslConfigCandidates = [
    getenv('OPENSSL_CONF') ?: '',
    'C:\\php-8.1.34-nts-Win32-vs16-x64\\extras\\ssl\\openssl.cnf',
    '/etc/ssl/openssl.cnf',
];
foreach ($opensslConfigCandidates as $opensslConfig) {
    if ($opensslConfig !== '' && is_file($opensslConfig)) {
        putenv('OPENSSL_CONF=' . $opensslConfig);
        break;
    }
}

$db = Database::connection();
$db->exec((string) file_get_contents($root . '/database/schema.sqlite.sql'));
$now = time();
$merchant = $db->prepare(
    'INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,token,created_at,email,phone) '
    . 'VALUES (:id,:username,:password,:merchant_name,:app_secret,1,0,:token,:created_at,"","")'
);
$merchant->execute([
    ':id' => 1,
    ':username' => 'security-one',
    ':password' => md5('123456'),
    ':merchant_name' => 'Security One',
    ':app_secret' => 'merchant-secret-one',
    ':token' => 'merchant-token-one',
    ':created_at' => $now,
]);
$merchant->execute([
    ':id' => 2,
    ':username' => 'security-two',
    ':password' => md5('654321'),
    ':merchant_name' => 'Security Two',
    ':app_secret' => 'merchant-secret-two',
    ':token' => 'merchant-token-two',
    ':created_at' => $now,
]);
$db->exec("INSERT INTO pay_type (id,value,name,label,status) VALUES (1,'alipay','支付宝','支付宝',1)");
$db->exec("INSERT INTO pay_channel (id,code,name,type,status,plugin_name) VALUES (1,'static','静态通道','alipay',1,'static_code')");
$db->exec(
    "INSERT INTO pay_account (id,uid,pay_type,channel_code,account,account_type,status,day_amount_limit,options,bind_pay_type) "
    . "VALUES (1,1,'alipay','static','SECURITY-ACCOUNT','码',1,8800,'{\"app_id\":\"demo\"}','[\"alipay\"]')"
);

try {
    fwrite(STDERR, "[MERCHANT SECURITY] 票据过期、失败次数、用途和重放\n");
    $expired = SecurityTicket::create($db, SecurityTicket::KIND_STEP_UP, 1, 'secret.view', -1);
    $expiredRejected = false;
    try {
        SecurityTicket::requirePending($db, $expired['ticket'], SecurityTicket::KIND_STEP_UP, 1);
    } catch (RuntimeException $exception) {
        $expiredRejected = str_contains($exception->getMessage(), '已过期');
    }
    merchantSecurityAssert($expiredRejected, '过期 step-up 票据未拒绝');

    $blocked = SecurityTicket::create($db, SecurityTicket::KIND_STEP_UP, 1, 'secret.view', 300);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        SecurityTicket::incrementFailure($db, SecurityTicket::find($db, $blocked['ticket'], SecurityTicket::KIND_STEP_UP, 1));
    }
    merchantSecurityAssert(
        (int) $db->query("SELECT status FROM security_ticket WHERE ticket='{$blocked['ticket']}'")->fetchColumn() === SecurityTicket::STATUS_BLOCKED,
        'step-up 失败五次未锁定'
    );

    $verified = SecurityTicket::create($db, SecurityTicket::KIND_STEP_UP, 1, 'secret.view', 300);
    $verifiedRow = SecurityTicket::find($db, $verified['ticket'], SecurityTicket::KIND_STEP_UP, 1);
    merchantSecurityAssert(is_array($verifiedRow), 'step-up 票据未创建');
    SecurityTicket::mark($db, (int) $verifiedRow['id'], SecurityTicket::STATUS_VERIFIED);
    merchantSecurityAssert(SecurityTicket::consumeVerified($db, $verified['ticket'], SecurityTicket::KIND_STEP_UP, 1, 'secret.view'), 'step-up 首次消费失败');
    merchantSecurityAssert(!SecurityTicket::consumeVerified($db, $verified['ticket'], SecurityTicket::KIND_STEP_UP, 1, 'secret.view'), 'step-up 票据可重放');
    merchantSecurityAssert(!SecurityTicket::consumeVerified($db, $verified['ticket'], SecurityTicket::KIND_STEP_UP, 2, 'secret.view'), 'step-up 票据可跨用户消费');

    fwrite(STDERR, "[MERCHANT SECURITY] 验证码过期、错误次数、一次性消费和跨用户隔离\n");
    $codeInsert = $db->prepare(
        'INSERT INTO verification_code (uid,purpose,target,code_hash,attempts,status,expires_at,created_at) '
        . 'VALUES (:uid,:purpose,:target,:code_hash,0,1,:expires_at,:created_at)'
    );
    $codeInsert->execute([
        ':uid' => 1,
        ':purpose' => 'bind_email',
        ':target' => 'security@example.com',
        ':code_hash' => password_hash('123456', PASSWORD_DEFAULT),
        ':expires_at' => $now + 300,
        ':created_at' => $now,
    ]);
    $codeInsert->execute([
        ':uid' => 2,
        ':purpose' => 'bind_email',
        ':target' => 'security@example.com',
        ':code_hash' => password_hash('222222', PASSWORD_DEFAULT),
        ':expires_at' => $now + 300,
        ':created_at' => $now,
    ]);
    $codeInsert->execute([
        ':uid' => 1,
        ':purpose' => 'bind_email',
        ':target' => 'expired@example.com',
        ':code_hash' => password_hash('123456', PASSWORD_DEFAULT),
        ':expires_at' => $now - 1,
        ':created_at' => $now - 301,
    ]);
    $rows = $db->query('SELECT * FROM verification_code ORDER BY id')->fetchAll();
    merchantSecurityAssert(count($rows) === 3, '验证码测试数据未写入');
    merchantSecurityAssert(password_verify('123456', (string) $rows[0]['code_hash']), '验证码未哈希保存');
    $db->prepare('UPDATE verification_code SET attempts=5,status=5 WHERE uid=1 AND target="security@example.com"')
        ->execute();
    merchantSecurityAssert((int) $db->query('SELECT status FROM verification_code WHERE uid=1 AND target="security@example.com"')->fetchColumn() === 5, '验证码五次错误未锁定');
    merchantSecurityAssert(
        (int) $db->query('SELECT status FROM verification_code WHERE uid=2 AND target="security@example.com"')->fetchColumn() === 1,
        '验证码状态错误地跨用户变化'
    );

    fwrite(STDERR, "[MERCHANT SECURITY] WebAuthn 签名计数器和挑战一次性消费\n");
    $privatePem = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgTIIo3/UVG6HeD9Tl
fyuquWynZyEKvsbdubPx8RFbx3OhRANCAAT7V7hmMj0Zt354nDj6lnYj1C6HuC2S
tGusXxtjx0Uva52apc9yGNWrtVtgtc62g/k1wV/TlW5tGCP9jv7da9kT
-----END PRIVATE KEY-----
PEM;
    $key = openssl_pkey_get_private($privatePem);
    merchantSecurityAssert($key !== false, '无法加载 WebAuthn 测试椭圆曲线密钥');
    $details = openssl_pkey_get_details($key);
    merchantSecurityAssert(is_array($details) && is_string($details['key'] ?? null), 'WebAuthn 测试公钥生成失败');
    $assertion = merchantSecurityMakeAssertion('security-challenge', 'http://127.0.0.1', '127.0.0.1', 6, $key);
    $verifiedAssertion = WebAuthn::assertion(
        'credential-one',
        $assertion['authenticator_data'],
        $assertion['client_data'],
        $assertion['signature'],
        'security-challenge',
        'http://127.0.0.1',
        $details['key'],
        5,
        '127.0.0.1',
    );
    merchantSecurityAssert((int) $verifiedAssertion['sign_count'] === 6, 'WebAuthn 签名计数器未递增');
    $rollback = merchantSecurityMakeAssertion('security-challenge', 'http://127.0.0.1', '127.0.0.1', 4, $key);
    $rollbackRejected = false;
    try {
        WebAuthn::assertion(
            'credential-one',
            $rollback['authenticator_data'],
            $rollback['client_data'],
            $rollback['signature'],
            'security-challenge',
            'http://127.0.0.1',
            $details['key'],
            5,
            '127.0.0.1',
        );
    } catch (RuntimeException $exception) {
        $rollbackRejected = str_contains($exception->getMessage(), '计数器');
    }
    merchantSecurityAssert($rollbackRejected, 'WebAuthn 签名计数器回退未拒绝');

    $challengeTicket = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO webauthn_challenge (ticket,uid,purpose,challenge,rp_id,origin,status,expires_at,created_at,updated_at) '
        . 'VALUES (:ticket,1,"auth","challenge","127.0.0.1","http://127.0.0.1",1,:expires_at,:created_at,:updated_at)'
    )->execute([
        ':ticket' => $challengeTicket,
        ':expires_at' => $now + 300,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $consume = $db->prepare('UPDATE webauthn_challenge SET status=2,updated_at=:updated_at WHERE ticket=:ticket AND status=1 AND expires_at>=:now');
    $consume->execute([':updated_at' => time(), ':ticket' => $challengeTicket, ':now' => time()]);
    merchantSecurityAssert($consume->rowCount() === 1, 'WebAuthn challenge 首次消费失败');
    $consume->execute([':updated_at' => time(), ':ticket' => $challengeTicket, ':now' => time()]);
    merchantSecurityAssert($consume->rowCount() === 0, 'WebAuthn challenge 可重复消费');

    $db->prepare(
        'INSERT INTO passkey_credential (uid,credential_id,public_key,sign_count,device_name,aaguid,status,last_used_at,created_at,updated_at) '
        . 'VALUES (1,"credential-one",:public_key,1,"设备一","",1,0,:created_at,:updated_at)'
    )->execute([':public_key' => $details['key'], ':created_at' => $now, ':updated_at' => $now]);
    merchantSecurityAssert(
        (int) $db->query("SELECT COUNT(*) FROM passkey_credential WHERE uid=2 AND credential_id='credential-one'")->fetchColumn() === 0,
        'Passkey 凭据错误地归属于第二个商户'
    );

    fwrite(STDERR, "[MERCHANT SECURITY] HTTP：商户密钥 step-up、APP 票据隔离和 RSA 加密保存\n");
    [$process, $pipes, $base] = merchantSecurityStartServer($root, $sessionPath, random_int(19080, 19999));
    try {
        $authOne = ['Authorization: merchant-token-one'];
        $authTwo = ['Authorization: merchant-token-two'];
        $defaultSecret = merchantSecurityRequest($base, '/api/user/secret', 'GET', [], $authTwo);
        merchantSecurityAssert(
            (int) ($defaultSecret['code'] ?? 0) === 200
            && ($defaultSecret['data'] ?? '') === 'merchant-secret-two',
            '未配置安全因子的商户默认仍要求二次认证',
        );
        $defaultRsa = merchantSecurityRequest($base, '/api/user/rsa-key/generate', 'POST', [], $authTwo);
        merchantSecurityAssert((int) ($defaultRsa['code'] ?? 0) === 200, '默认关闭时 RSA 操作仍被二次认证阻塞');

        $unauthorizedSecret = merchantSecurityRequest($base, '/api/user/secret', 'GET', [], $authOne);
        merchantSecurityAssert((int) ($unauthorizedSecret['code'] ?? 0) === 401, '商户密钥查看未强制 step-up');

        $viewTicket = merchantSecurityStepUp($base, 'merchant-token-one', 'secret.view', '123456');
        $secret = merchantSecurityRequest($base, '/api/user/secret', 'GET', [], $authOne, null);
        merchantSecurityAssert((int) ($secret['code'] ?? 0) === 401, '未提交 step-up 票据却读取到商户密钥');
        $secret = merchantSecurityRequest($base, '/api/user/secret', 'GET', [], array_merge($authOne, ['X-Step-Up-Ticket: ' . $viewTicket]));
        merchantSecurityAssert((int) ($secret['code'] ?? 0) === 200 && ($secret['data'] ?? '') === 'merchant-secret-one', '商户密钥查看失败');
        $replay = merchantSecurityRequest($base, '/api/user/secret', 'GET', [], array_merge($authOne, ['X-Step-Up-Ticket: ' . $viewTicket]));
        merchantSecurityAssert((int) ($replay['code'] ?? 0) === 401, '商户密钥查看票据可重放');

        $missing = merchantSecurityRequest($base, '/api/user/rsa-key/generate', 'POST', [], $authOne);
        merchantSecurityAssert((int) ($missing['code'] ?? 0) === 401, 'RSA 生成未先要求 step-up');
        $rsaTicket = merchantSecurityStepUp($base, 'merchant-token-one', 'rsa.reset', '123456');
        $rsa = merchantSecurityRequest($base, '/api/user/rsa-key/generate', 'POST', [], array_merge($authOne, ['X-Step-Up-Ticket: ' . $rsaTicket]));
        merchantSecurityAssert((int) ($rsa['code'] ?? 0) === 200, 'RSA 密钥生成失败');
        $private = (string) ($rsa['data']['merchant_private_key'] ?? '');
        merchantSecurityAssert(str_contains($private, 'BEGIN PRIVATE KEY'), 'RSA 私钥未在首次生成响应返回');
        $stored = $db->query('SELECT private_ciphertext,private_nonce,private_tag FROM merchant_rsa_key WHERE uid=1')->fetch();
        merchantSecurityAssert(is_array($stored), 'RSA 加密密钥记录未保存');
        merchantSecurityAssert($stored['private_ciphertext'] !== '' && $stored['private_ciphertext'] !== $private, 'RSA 私钥以明文保存');
        $plain = openssl_decrypt(
            base64_decode((string) $stored['private_ciphertext'], true),
            'aes-256-gcm',
            hash('sha256', 'test-only-rsa-encryption-key', true),
            OPENSSL_RAW_DATA,
            base64_decode((string) $stored['private_nonce'], true),
            base64_decode((string) $stored['private_tag'], true),
        );
        merchantSecurityAssert($plain === $private, 'RSA 私钥解密后不一致');

        $bindTicket = merchantSecurityRequest($base, '/api/user/app-login-ticket', 'POST', [], $authOne);
        merchantSecurityAssert((int) ($bindTicket['code'] ?? 0) === 200, 'APP 绑定票据创建失败');
        $bindValue = (string) ($bindTicket['data']['ticket'] ?? '');
        $bindData = merchantSecurityRequest($base, '/api/tickets/data', 'GET', ['ticket' => $bindValue]);
        merchantSecurityAssert((int) ($bindData['code'] ?? 0) === 200 && ($bindData['data']['kind'] ?? '') === 'app_bind', 'APP 绑定票据类型错误');
        merchantSecurityAssert(!isset($bindData['data']['token']), 'APP 绑定票据错误地返回登录 token');

        $loginQr = merchantSecurityRequest($base, '/api/login/qrcode?channel=app', 'GET');
        merchantSecurityAssert((int) ($loginQr['code'] ?? 0) === 200, 'APP 登录票据创建失败');
        $loginValue = (string) ($loginQr['data']['ticket'] ?? '');
        $confirm = merchantSecurityRequest($base, '/api/tickets/status', 'POST', [
            'ticket' => $loginValue,
            'token' => 'merchant-token-one',
        ]);
        merchantSecurityAssert((int) ($confirm['code'] ?? 0) === 200 && (int) ($confirm['data']['status'] ?? 0) === 2, 'APP 登录票据确认失败');
        $loginData = merchantSecurityRequest($base, '/api/tickets/data', 'GET', ['ticket' => $loginValue]);
        merchantSecurityAssert((int) ($loginData['code'] ?? 0) === 200 && ($loginData['data']['token'] ?? '') !== '', 'APP 登录票据未签发登录 token');
        $loginToken = (string) ($loginData['data']['token'] ?? '');
        $loginReplay = merchantSecurityRequest($base, '/api/tickets/data', 'GET', ['ticket' => $loginValue]);
        merchantSecurityAssert(
            (int) ($loginReplay['code'] ?? 0) === 200
            && (int) ($loginReplay['data']['status'] ?? 0) === 3
            && !isset($loginReplay['data']['token']),
            'APP 登录票据重复查询再次返回登录 token'
        );

        $owner = merchantSecurityRequest($base, '/api/user/passkey/list', 'GET', [], ['Authorization: ' . $loginToken]);
        merchantSecurityAssert((int) ($owner['code'] ?? 0) === 200 && count($owner['data']['list'] ?? []) === 1, 'Passkey 所属商户无法读取自己的凭据');
        $other = merchantSecurityRequest($base, '/api/user/passkey/list', 'GET', [], ['Authorization: merchant-token-two']);
        merchantSecurityAssert((int) ($other['code'] ?? 0) === 200 && count($other['data']['list'] ?? []) === 0, 'Passkey 凭据跨商户泄露');

        $accountDetail = merchantSecurityRequest(
            $base,
            '/api/channel/account/detail?id=1',
            'GET',
            [],
            ['Authorization: ' . $loginToken],
        );
        merchantSecurityAssert(
            (int) ($accountDetail['code'] ?? 0) === 200,
            '商户通道详情读取失败：' . json_encode($accountDetail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        merchantSecurityAssert(
            (int) ($accountDetail['data']['limit_rule']['day_order_amount'] ?? -1) === 8800,
            '商户通道详情缺少嵌套日限额字段',
        );
        merchantSecurityAssert(
            ($accountDetail['data']['options']['app_id'] ?? '') === 'demo'
            && ($accountDetail['data']['bind_pay_type'][0] ?? '') === 'alipay',
            '商户通道详情 JSON 字段解析错误',
        );
    } finally {
        merchantSecurityStopProcess($process, $pipes);
    }

    echo "MerchantSecurityTest: OK\n";
} finally {
    @unlink($dbPath);
    @unlink($cookieFile);
    @unlink($lockPath);
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $sessionFile) {
        @unlink($sessionFile);
    }
    @rmdir($sessionPath);
}
