<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;

function orderFormatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,mixed> */
function orderFormatRequest(string $base, string $path, string $token, array $data): array
{
    $handle = curl_init($base . $path);
    orderFormatAssert($handle !== false, 'curl 初始化失败');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: ' . $token,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
    ]);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    curl_close($handle);
    orderFormatAssert($body !== false, 'HTTP 请求失败：' . $error);
    $decoded = json_decode($body, true);
    orderFormatAssert(is_array($decoded), '订单接口返回非 JSON');
    return $decoded;
}

$root = dirname(__DIR__);
$dbPath = tempnam(sys_get_temp_dir(), 'xarr-order-format-');
$lockPath = $root . '/var/.order-format-' . bin2hex(random_bytes(4)) . '.lock';
$process = null;
$pipes = [];
if ($dbPath === false) {
    throw new RuntimeException('无法创建测试数据库');
}

try {
    putenv('XARR_DB_DSN=sqlite:' . $dbPath);
    putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);
    file_put_contents($lockPath, json_encode([
        'version' => 1,
        'installed_at' => gmdate('c'),
        'admin' => 'admin',
        'username' => 'order-format',
    ], JSON_UNESCAPED_SLASHES));

    $db = Database::connection();
    $db->exec((string) file_get_contents($root . '/database/schema.sqlite.sql'));
    $now = time();
    $db->exec(
        "INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,token,created_at) "
        . "VALUES (10000,'order-format','" . md5('123456') . "','订单号测试商户','order-format-secret',1,0,'order-format-token',{$now})"
    );
    $db->exec(
        "INSERT INTO staff (id,username,password,name,status,token) "
        . "VALUES (1,'order-format-admin','" . md5('123456') . "','订单号测试管理员',1,'order-format-admin-token')"
    );

    $port = random_int(23080, 23999);
    $process = proc_open(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t public public/router.php',
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
    );
    orderFormatAssert(is_resource($process), '无法启动 PHP 测试服务');
    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $health = @file_get_contents($base . '/api/health');
        if (is_string($health) && (int) ((json_decode($health, true)['code'] ?? 0)) === 200) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    orderFormatAssert($ready, 'PHP 测试服务未启动');

    $merchantOrder = orderFormatRequest($base, '/api/order/create-test', 'order-format-token', [
        'amount' => 100,
        'subject' => '商户订单号格式测试',
    ]);
    orderFormatAssert((int) ($merchantOrder['code'] ?? 0) === 200, '商户测试订单创建失败');
    $merchantOrderId = (string) ($merchantOrder['data']['order_id'] ?? '');
    orderFormatAssert($merchantOrderId !== '', '商户测试订单号为空');
    orderFormatAssert(str_contains($merchantOrderId, 'MTEST-') === false, '商户测试订单仍包含 MTEST-');
    orderFormatAssert(preg_match('/^\d{18}$/', $merchantOrderId) === 1, '商户测试订单号不是纯数字 18 位');

    $adminOrder = orderFormatRequest($base, '/api/admin/order/create-test', 'order-format-admin-token', [
        'uid' => 10000,
        'amount' => 100,
        'subject' => '后台订单号格式测试',
    ]);
    orderFormatAssert((int) ($adminOrder['code'] ?? 0) === 200, '后台测试订单创建失败');
    orderFormatAssert(str_contains((string) ($adminOrder['data']['order_id'] ?? ''), 'MTEST-') === false, '后台测试订单出现 MTEST-');

    echo "OrderFormatTest: OK\n";
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
