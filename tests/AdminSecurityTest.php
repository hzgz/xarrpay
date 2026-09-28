<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function adminSecurityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,mixed> $data @param list<string> $headers @return array<string,mixed> */
function adminSecurityRequest(string $base, string $path, string $method = 'GET', array $data = [], array $headers = []): array
{
    if ($data !== [] && in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
        $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $data = [];
    }
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('curl 初始化失败');
    }
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
    ];
    if ($data !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($body === false) {
        throw new RuntimeException('HTTP 请求失败：' . $error);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("{$method} {$path} 返回非 JSON {$status}: " . substr($body, 0, 300));
    }
    $decoded['_http_status'] = $status;
    return $decoded;
}

/** @return array{ticket:string,token:string} */
function adminSecurityTicket(string $base, string $token, string $operation, string $path = '/api/security/step-up/begin'): array
{
    $auth = ['Authorization: ' . $token];
    $begin = adminSecurityRequest($base, $path, 'POST', ['operation' => $operation], $auth);
    adminSecurityAssert((int) ($begin['code'] ?? 0) === 200, "{$operation} 发起失败");
    adminSecurityAssert(($begin['data']['allow_types'] ?? []) === ['password'], "{$operation} 因子不是当前密码");
    $ticket = (string) ($begin['data']['ticket'] ?? '');
    adminSecurityAssert($ticket !== '', "{$operation} 票据为空");

    $finish = adminSecurityRequest($base, '/api/security/step-up/finish', 'POST', [
        'ticket' => $ticket,
        'type' => 'password',
        'password' => '123456',
    ], $auth);
    adminSecurityAssert((int) ($finish['code'] ?? 0) === 200, "{$operation} 完成失败");
    return ['ticket' => (string) ($finish['data']['ticket'] ?? $ticket), 'token' => $token];
}

$dbPath = tempnam(sys_get_temp_dir(), 'xarr-admin-security-');
if ($dbPath === false) {
    throw new RuntimeException('无法创建测试数据库');
}
$lockPath = $root . '/var/.admin-security-' . bin2hex(random_bytes(4)) . '.lock';
$process = null;
$pipes = [];

try {
    putenv('XARR_DB_DSN=sqlite:' . $dbPath);
    putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);
    file_put_contents($lockPath, json_encode([
        'version' => 1,
        'installed_at' => gmdate('c'),
        'admin' => 'admin',
        'username' => 'admin-security',
    ], JSON_UNESCAPED_SLASHES));

    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec((string) file_get_contents($root . '/database/schema.sqlite.sql'));
    $db->prepare(
        'INSERT INTO staff (id,username,password,name,status,token) VALUES (1,:username,:password,:name,1,:token)'
    )->execute([
        ':username' => 'admin-security',
        ':password' => md5('123456'),
        ':name' => '管理员安全测试',
        ':token' => 'admin-security-token',
    ]);
    $db->prepare(
        'INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,token,settings) '
        . 'VALUES (1,:username,:password,:merchant_name,:app_secret,1,1000,:token,:settings)'
    )->execute([
        ':username' => 'merchant-security',
        ':password' => md5('123456'),
        ':merchant_name' => '安全测试商户',
        ':app_secret' => 'merchant-security-secret',
        ':token' => 'merchant-security-token',
        ':settings' => '{}',
    ]);

    $port = random_int(21080, 21999);
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t public public/router.php',
        $descriptor,
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动 PHP 测试服务');
    }
    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($index = 0; $index < 30; $index++) {
        try {
            if ((int) (adminSecurityRequest($base, '/api/health')['code'] ?? 0) === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    adminSecurityAssert($ready, 'PHP 测试服务未启动');

    $adminAuth = ['Authorization: admin-security-token'];
    $directAdmin = adminSecurityRequest($base, '/api/admin/security/step-up/begin', 'POST', [
        'operation' => 'user.create',
    ], $adminAuth);
    adminSecurityAssert((int) ($directAdmin['code'] ?? 0) === 200, '管理员专用二次认证路由失败');
    adminSecurityAssert(($directAdmin['data']['need_step_up'] ?? true) === false, '未配置因子的管理员默认仍要求二次认证');

    $merchantBegin = adminSecurityRequest($base, '/api/security/step-up/begin', 'POST', [
        'operation' => 'secret.view',
    ], ['Authorization: merchant-security-token']);
    adminSecurityAssert((int) ($merchantBegin['code'] ?? 0) === 200, '商户共享二次认证路由被破坏');
    adminSecurityAssert(($merchantBegin['data']['need_step_up'] ?? true) === false, '未配置因子的商户默认仍要求二次认证');

    $createResponse = adminSecurityRequest($base, '/api/admin/user/create', 'POST', [
        'username' => 'created-by-admin-stepup',
        'password' => '123456',
        'merchant_name' => '管理员创建商户',
        'status' => 1,
    ], $adminAuth);
    adminSecurityAssert((int) ($createResponse['code'] ?? 0) === 200, '管理员创建商户失败');
    $createdId = (int) ($createResponse['data']['id'] ?? 0);
    adminSecurityAssert($createdId >= 10000, '创建商户 ID 没有从 10000 起步');
    adminSecurityAssert(
        preg_match('/^[a-f0-9]{32}$/', (string) ($createResponse['data']['app_secret'] ?? '')) === 1,
        '创建商户密钥不是 32 位十六进制',
    );

    $missing = adminSecurityRequest($base, '/api/admin/user/create', 'POST', [
        'username' => 'created-without-ticket',
        'password' => '123456',
        'merchant_name' => '缺票据商户',
        'status' => 1,
    ], $adminAuth);
    adminSecurityAssert((int) ($missing['code'] ?? 0) === 200, '创建商户仍错误要求管理员二次认证');

    foreach ([
        ['user.edit', '/api/admin/user/edit', ['id' => $createdId, 'username' => 'created-by-admin-stepup', 'merchant_name' => '管理员编辑商户', 'status' => 1]],
        ['user.balance.set', '/api/admin/user/balance/set', ['uid' => $createdId, 'balance' => 2500]],
        ['user.rebate_balance.set', '/api/admin/user/rebate-balance/set', ['uid' => $createdId, 'rebate_balance' => 300]],
        ['user.remove', '/api/admin/user/remove', ['id' => $createdId]],
    ] as [$operation, $endpoint, $payload]) {
        $response = adminSecurityRequest($base, $endpoint, 'POST', $payload, $adminAuth);
        adminSecurityAssert((int) ($response['code'] ?? 0) === 200, "{$operation} 默认关闭时不应阻塞业务");
    }

    echo "AdminSecurityTest: OK\n";
} finally {
    if (is_resource($process)) {
        $status = proc_get_status($process);
        if (PHP_OS_FAMILY === 'Windows' && (int) ($status['pid'] ?? 0) > 0) {
            exec('taskkill /PID ' . (int) $status['pid'] . ' /T /F 2>NUL');
        } else {
            proc_terminate($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    @unlink($dbPath);
    @unlink($lockPath);
}
