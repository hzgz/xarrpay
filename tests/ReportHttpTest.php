<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function reportHttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $fields */
function reportHttpSign(array $params, string $key, array $fields): string
{
    $values = [];
    foreach ($fields as $field) {
        if ($field === 'sign' || $field === 'sign_type' || !array_key_exists($field, $params)) {
            continue;
        }
        $value = $params[$field];
        if ($value === null || $value === '' || $value === false || $value === 0 || $value === 0.0) {
            continue;
        }
        $values[$field] = (string) $value;
    }
    ksort($values, SORT_STRING);
    $pairs = [];
    foreach ($values as $field => $value) {
        $pairs[] = $field . '=' . $value;
    }
    return md5(implode('&', $pairs) . $key);
}

/** @return array<string,mixed> */
function reportHttpRequest(string $base, string $path, string $method, array $data = []): array
{
    $handle = curl_init($base . $path);
    reportHttpAssert($handle !== false, 'curl 初始化失败');
    $headers = ['Accept: application/json'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
    ];
    if ($data !== []) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    reportHttpAssert($body !== false, 'HTTP 请求失败：' . $error);
    $decoded = json_decode($body, true);
    reportHttpAssert(is_array($decoded), $method . ' ' . $path . ' 返回非 JSON：' . $status . ' ' . $body);
    $decoded['_http_status'] = $status;
    return $decoded;
}

$root = dirname(__DIR__);
$dbPath = tempnam(sys_get_temp_dir(), 'xarr-report-http-');
$lockPath = tempnam(sys_get_temp_dir(), 'xarr-report-http-lock-');
$serverErrorPath = tempnam(sys_get_temp_dir(), 'xarr-report-http-error-');
$process = null;
$pipes = [];
$testPassed = false;

try {
    reportHttpAssert($dbPath !== false && $lockPath !== false && $serverErrorPath !== false, '测试文件创建失败');
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec((string) file_get_contents($root . '/database/schema.sqlite.sql'));
    $now = time();
    $db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at) VALUES (10000,'http-report','', 'HTTP 报告商户','http-secret',1,0,{$now})");
    $db->exec("INSERT INTO pay_type (id,value,code,name,label,status) VALUES (1,'wxpay','wxpay','微信支付','微信支付',1)");
    $db->exec("INSERT INTO pay_channel (id,code,name,type,status,plugin_name,options) VALUES (1,'http-report-wx','HTTP 报告通道','wxpay',1,'jk_wechat_app','{}')");
    $db->exec("INSERT INTO pay_account (id,uid,pay_type,channel_code,account,sub_account,bind_client_name,status,name,options,created_at,updated_at) VALUES (1,10000,'wxpay','http-report-wx','third@login','sub-1','com.example.pay',1,'HTTP 报告账号','{\"package_name\":\"com.example.pay\"}',{$now},{$now})");
    $db->exec("INSERT INTO pay_account_ext (account_id,plugin_name,created_at,updated_at) VALUES (1,'jk_wechat_app',{$now},{$now})");
    $db->exec("INSERT INTO third_account (id,name,provider,account,options,status,created_at,updated_at) VALUES (1,'第三方账号','1','third@login','{\"type\":1}',1,{$now},{$now})");
    $db->exec("INSERT INTO options (key,value) VALUES ('third_account_report_secret','third-secret')");
    $db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,pay_type,channel_code,account_id,expire_time,created_at,updated_at) VALUES (1,'REPORT-ORDER','REPORT-OUT',10000,123,123,1,'wxpay','http-report-wx',1," . ($now + 600) . "," . ($now - 10) . ",{$now})");
    $db = null;

    file_put_contents($lockPath, json_encode([
        'version' => 1,
        'installed_at' => gmdate('c'),
        'admin' => 'admin',
        'username' => 'report-http',
    ], JSON_UNESCAPED_SLASHES));

    putenv('XARR_DB_DSN=sqlite:' . $dbPath);
    putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);

    $port = random_int(23100, 23999);
    $process = proc_open(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t public public/router.php',
        [
            0 => ['pipe', 'r'],
            1 => ['file', $serverErrorPath, 'a'],
            2 => ['file', $serverErrorPath, 'a'],
        ],
        $pipes,
        $root,
    );
    reportHttpAssert(is_resource($process), '无法启动 HTTP 测试服务');
    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($index = 0; $index < 10; $index++) {
        try {
            $health = reportHttpRequest($base, '/api/health', 'GET');
            if ((int) ($health['code'] ?? 0) === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    reportHttpAssert($ready, 'HTTP 测试服务未启动');

    $mobile = [
        'from' => 'com.example.pay',
        'content' => '微信收款到账 1.23 元',
        'timestamp' => (string) $now,
    ];
    $mobile['sign'] = reportHttpSign($mobile, 'http-secret', ['from', 'content', 'timestamp', 'sign']);
    $success = reportHttpRequest($base, '/api/report/10000', 'POST', $mobile);
    reportHttpAssert((int) ($success['code'] ?? 0) === 200, '移动端 Go 四字段报告失败');
    reportHttpAssert(($success['data']['matched'] ?? false) === true, '移动端报告没有匹配订单');

    $duplicate = reportHttpRequest($base, '/api/report/10000', 'POST', $mobile);
    reportHttpAssert(($duplicate['data']['duplicate'] ?? false) === true, '移动端重复报告没有返回幂等标记');

    $heart = [
        'client_name' => 'com.example.pay',
        'channel_id' => 1,
        'timestamp' => $now,
    ];
    $heart['sign'] = reportHttpSign($heart, 'http-secret', ['client_name', 'channel_code', 'channel_id', 'ext_data', 'timestamp', 'sign']);
    $heartResult = reportHttpRequest($base, '/api/report/10000/heart', 'POST', $heart);
    reportHttpAssert((int) ($heartResult['code'] ?? 0) === 200, 'ReportHeartReq 心跳失败');

    $pc = [
        'amount' => 123,
        'pay_type' => 'wxpay',
        'channel_code' => 'http-report-wx',
        'remark' => 'PC 订单',
        'pay_time' => (string) $now,
        'pay_user' => '买家',
        'order_id' => 'REPORT-ORDER-PC',
        'channel_account_id' => 1,
        'timestamp' => (string) $now,
    ];
    $pc['sign'] = reportHttpSign($pc, 'http-secret', [
        'amount', 'pay_type', 'channel_code', 'remark', 'pay_time', 'coll_user', 'pay_user',
        'uid', 'order_id', 'out_order_id', 'channel_account_id', 'timestamp', 'sign',
    ]);
    $pcResult = reportHttpRequest($base, '/api/report/10000/pc', 'POST', $pc);
    reportHttpAssert((int) ($pcResult['code'] ?? 0) === 200, 'ReportXArrPcReq 报告失败');

    $head = [
        'account' => 'third@login',
        'type' => 1,
        'timestamp' => $now,
    ];
    $head['sign'] = reportHttpSign($head, 'third-secret', ['account', 'type', 'timestamp', 'sign']);
    $headResult = reportHttpRequest($base, '/api/report/third-account/head', 'POST', $head);
    reportHttpAssert((int) ($headResult['code'] ?? 0) === 200, 'ThirdAccountHeadReq 查询失败');

    $third = [
        'account' => 'third@login',
        'type' => 1,
        'sub_account' => 'sub-1',
        'amount' => 123,
        'pay_type' => 'wxpay',
        'channel_code' => 'http-report-wx',
        'remark' => '第三方流水',
        'out_order_id' => 'THIRD-OUT-1',
        'timestamp' => $now,
    ];
    $third['sign'] = reportHttpSign($third, 'third-secret', [
        'account', 'type', 'sub_account', 'amount', 'pay_type', 'channel_code', 'remark',
        'out_order_id', 'order_id', 'timestamp', 'sign',
    ]);
    $thirdResult = reportHttpRequest($base, '/api/report/third-account', 'POST', $third);
    reportHttpAssert((int) ($thirdResult['code'] ?? 0) === 200, 'ThirdAccountReportReq 报告失败');

    $xiaodai = [
        'lx' => 'heart',
        'pid' => 10000,
        'key' => 'http-secret',
        'id' => 1,
    ];
    $xiaodaiResult = reportHttpRequest($base, '/api/report/xiaodai/api', 'POST', $xiaodai);
    reportHttpAssert((int) ($xiaodaiResult['code'] ?? 0) === 200, '小贷心跳报告失败');

    $badSignature = $mobile;
    $badSignature['sign'] = str_repeat('0', 32);
    $bad = reportHttpRequest($base, '/api/report/10000', 'POST', $badSignature);
    reportHttpAssert((int) ($bad['code'] ?? 0) === 401, '错误签名没有被拒绝');

    $testPassed = true;
    echo "ReportHttpTest: OK\n";
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
    putenv('XARR_DB_DSN');
    putenv('XARR_INSTALL_LOCK_FILE');
    @unlink($dbPath);
    @unlink($lockPath);
    if (!$testPassed && is_file($serverErrorPath)) {
        $serverError = trim((string) file_get_contents($serverErrorPath));
        if ($serverError !== '') {
            fwrite(STDERR, $serverError . PHP_EOL);
        }
    }
    @unlink($serverErrorPath);
}
