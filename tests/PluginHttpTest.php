<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function pluginHttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function pluginHttpRequest(string $base, string $path, string $method, string $token, array $data = []): array
{
    $handle = curl_init($base . $path);
    pluginHttpAssert($handle !== false, 'curl 初始化失败');
    $headers = ['Accept: application/json', 'Authorization: ' . $token];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
    ];
    if ($data !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    pluginHttpAssert($body !== false, 'HTTP 请求失败: ' . $error);
    $decoded = json_decode($body, true);
    pluginHttpAssert(is_array($decoded), "{$method} {$path} 返回非 JSON {$status}");
    $decoded['_http_status'] = $status;
    return $decoded;
}

function pluginHttpCopyDirectory(string $source, string $target): void
{
    mkdir($target, 0775, true);
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.runtime.json') {
            continue;
        }
        $from = $source . DIRECTORY_SEPARATOR . $entry;
        $to = $target . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($from)) {
            pluginHttpCopyDirectory($from, $to);
        } else {
            copy($from, $to);
        }
    }
}

function pluginHttpRemoveDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) && !is_link($child)
            ? pluginHttpRemoveDirectory($child)
            : @unlink($child);
    }
    @rmdir($path);
}

$root = dirname(__DIR__);
$dbPath = tempnam(sys_get_temp_dir(), 'xarr-plugin-http-');
$pluginRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xarr-plugin-http-' . bin2hex(random_bytes(6));
$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xarr-plugin-http-' . bin2hex(random_bytes(6)) . '.lock';
$process = null;
$pipes = [];

try {
    pluginHttpAssert($dbPath !== false, '测试数据库创建失败');
    mkdir($pluginRoot . DIRECTORY_SEPARATOR . 'pay', 0775, true);
    foreach (glob($root . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $source) {
        pluginHttpCopyDirectory($source, $pluginRoot . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . basename($source));
    }

    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec((string) file_get_contents($root . '/database/schema.sqlite.sql'));
    $db->exec("INSERT INTO staff (id,username,password,name,status,token,super) VALUES (1,'plugin-http','" . md5('123456') . "','插件 HTTP 测试',1,'plugin-http-token',1)");
    $db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,token) VALUES (10000,'merchant-http','" . md5('123456') . "','插件 HTTP 商户','plugin-http-secret',1,'merchant-http-token')");
    $db->exec("INSERT INTO pay_type (id,value,code,name,label,status) VALUES (1,'alipay','alipay','alipay','支付宝',1),(2,'wxpay','wxpay','wxpay','微信支付',1)");
    $db->exec("INSERT INTO pay_channel (id,code,name,type,status,plugin_name,options) VALUES (1,'http-static','HTTP 测试支付宝通道','alipay',1,'static_code','{}')");
    $db->exec("INSERT INTO options (`key`,value) VALUES ('web_title','配置站点'),('web_index_title','配置站点首页')");
    file_put_contents($lockPath, json_encode([
        'version' => 1,
        'installed_at' => gmdate('c'),
        'admin' => 'admin',
        'username' => 'plugin-http',
    ], JSON_UNESCAPED_SLASHES));

    putenv('XARR_DB_DSN=sqlite:' . $dbPath);
    putenv('XARR_PLUGIN_DIR=' . $pluginRoot);
    putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);

    $port = random_int(22080, 22999);
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
    pluginHttpAssert(is_resource($process), '无法启动 HTTP 测试服务');

    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($index = 0; $index < 30; $index++) {
        try {
            $health = pluginHttpRequest($base, '/api/health', 'GET', 'plugin-http-token');
            if ((int) ($health['code'] ?? 0) === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    pluginHttpAssert($ready, 'HTTP 测试服务未启动');

    $config = pluginHttpRequest($base, '/api/config', 'GET', 'plugin-http-token');
    pluginHttpAssert((int) ($config['code'] ?? 0) === 200, '系统配置接口失败');
    pluginHttpAssert(($config['data']['web_title'] ?? '') === '配置站点', '网站名称没有读取系统设置');
    pluginHttpAssert(($config['data']['web_index_title'] ?? '') === '配置站点首页', '首页标题没有读取系统设置');

    $list = pluginHttpRequest($base, '/api/admin/plugins/list', 'GET', 'plugin-http-token');
    pluginHttpAssert((int) ($list['code'] ?? 0) === 200, '后台插件列表接口失败');
    pluginHttpAssert(count($list['data']['list'] ?? []) === 5, '后台插件列表数量错误');
    pluginHttpAssert(
        count(array_filter($list['data']['diagnostics'] ?? [], static fn (array $item): bool => ($item['valid'] ?? false) !== true)) === 0,
        '后台插件目录自检存在无效项',
    );

    $payConf = pluginHttpRequest($base, '/api/admin/pay/conf', 'GET', 'plugin-http-token');
    pluginHttpAssert((int) ($payConf['code'] ?? 0) === 200, '支付配置接口失败');
    $payTypes = array_column($payConf['data']['pay_type'] ?? [], 'value');
    pluginHttpAssert(in_array('wxpay', $payTypes, true), '支付配置没有真实 wxpay 支付方式');
    $fixedChannels = array_column($payConf['data']['channels'] ?? [], 'code');
    foreach (['wxpay_app_monitor', 'yun_qnm_lz', 'yun_wechat_gj_xd', 'yun_wechat_yyb_xd'] as $channelCode) {
        pluginHttpAssert(in_array($channelCode, $fixedChannels, true), '固定插件通道未自动登记: ' . $channelCode);
    }

    $publicPayConf = pluginHttpRequest($base, '/api/pay/conf', 'GET', '');
    pluginHttpAssert((int) ($publicPayConf['code'] ?? 0) === 200, '前台支付配置接口失败');
    $publicPayTypes = array_column($publicPayConf['data']['pay_type'] ?? [], 'value');
    pluginHttpAssert($publicPayTypes === ['alipay', 'wxpay'], '前台支付方式未按统一契约返回');
    $publicChannels = array_column($publicPayConf['data']['channels'] ?? [], 'code');
    foreach (['http-static', 'wxpay_app_monitor', 'yun_qnm_lz', 'yun_wechat_gj_xd', 'yun_wechat_yyb_xd'] as $channelCode) {
        pluginHttpAssert(in_array($channelCode, $publicChannels, true), '前台固定通道未返回: ' . $channelCode);
    }

    $formCases = [
        ['wxpay', 'wxpay_app_monitor', 5],
        ['wxpay', 'yun_qnm_lz', 15],
        ['wxpay', 'yun_wechat_gj_xd', 15],
        ['wxpay', 'yun_wechat_yyb_xd', 15],
        ['alipay', 'http-static', 3],
    ];
    foreach ($formCases as [$payType, $channelCode, $minimum]) {
        $form = pluginHttpRequest($base, '/api/channel/form-items?pay_type=' . rawurlencode($payType) . '&pay_channel=' . rawurlencode($channelCode), 'GET', 'merchant-http-token');
        pluginHttpAssert((int) ($form['code'] ?? 0) === 200, '通道表单接口失败: ' . $channelCode);
        pluginHttpAssert(is_array($form['data'] ?? null), '通道表单返回类型错误: ' . $channelCode);
        pluginHttpAssert(count($form['data']) >= $minimum, '通道表单为空或字段不完整: ' . $channelCode);
    }

    $legacyWechat = pluginHttpRequest($base, '/api/channel/form-items?pay_type=wechat&pay_channel=demo-wechat', 'GET', 'merchant-http-token');
    pluginHttpAssert(in_array((int) ($legacyWechat['code'] ?? 0), [404, 422], true), '旧 wechat 契约没有被明确拒绝');
    pluginHttpAssert((string) ($legacyWechat['message'] ?? '') !== '', '旧 wechat 契约拒绝没有返回原因');
    $unknownChannel = pluginHttpRequest($base, '/api/channel/form-items?pay_type=wxpay&pay_channel=not-installed', 'GET', 'merchant-http-token');
    pluginHttpAssert((int) ($unknownChannel['code'] ?? 0) === 404, '不存在的通道没有被明确拒绝');

    $pluginOptions = pluginHttpRequest($base, '/api/admin/plugins/set-options', 'POST', 'plugin-http-token', [
        'plugin_name' => 'yun_qnm_lz',
        'options' => [
            ['key' => 'yun_token', 'value' => 'token-from-http-test'],
        ],
    ]);
    pluginHttpAssert((int) ($pluginOptions['code'] ?? 0) === 200, '插件级配置保存接口失败');
    $qnmConfig = array_values(array_filter(
        $pluginOptions['data']['config'] ?? [],
        static fn (array $item): bool => ($item['key'] ?? '') === 'yun_token',
    ));
    pluginHttpAssert(($qnmConfig[0]['value'] ?? '') === 'token-from-http-test', '插件级配置保存值错误');

    $refresh = pluginHttpRequest($base, '/api/admin/plugins/refresh', 'POST', 'plugin-http-token');
    pluginHttpAssert((int) ($refresh['code'] ?? 0) === 200, '插件刷新接口失败');
    pluginHttpAssert(count($refresh['data']['list'] ?? []) === 5, '插件刷新列表数量错误');

    $disable = pluginHttpRequest($base, '/api/admin/plugins/disable', 'POST', 'plugin-http-token', [
        'plugin_name' => 'static_code',
    ]);
    pluginHttpAssert((int) ($disable['code'] ?? 0) === 200, '插件停用接口失败');
    $disabled = array_values(array_filter(
        $disable['data']['list'] ?? [],
        static fn (array $item): bool => ($item['name'] ?? '') === 'static_code',
    ));
    pluginHttpAssert(($disabled[0]['enabled'] ?? true) === false, '插件停用状态未返回');

    $enable = pluginHttpRequest($base, '/api/admin/plugins/enable', 'POST', 'plugin-http-token', [
        'plugin_name' => 'static_code',
    ]);
    pluginHttpAssert((int) ($enable['code'] ?? 0) === 200, '插件启用接口失败');
    $enabled = array_values(array_filter(
        $enable['data']['list'] ?? [],
        static fn (array $item): bool => ($item['name'] ?? '') === 'static_code',
    ));
    pluginHttpAssert(($enabled[0]['enabled'] ?? false) === true, '插件启用状态未返回');

    $appItems = pluginHttpRequest($base, '/api/admin/app-items/list', 'GET', 'plugin-http-token');
    pluginHttpAssert((int) ($appItems['code'] ?? 0) === 200, '应用商店插件列表接口失败');
    $staticItem = array_values(array_filter(
        $appItems['data']['list'] ?? [],
        static fn (array $item): bool => ($item['name'] ?? '') === 'static_code',
    ));
    pluginHttpAssert((int) ($staticItem[0]['type'] ?? 0) === 1, '支付插件类型没有转换为前端数字类型');
    pluginHttpAssert((int) ($staticItem[0]['status'] ?? 0) === 1, '应用商店插件状态字段缺失');
    pluginHttpAssert(($staticItem[0]['running'] ?? false) === true, '应用商店插件运行态字段缺失');
    pluginHttpAssert(($staticItem[0]['plugin_name'] ?? '') === 'static_code', '应用商店插件缺少真实目录名');
    pluginHttpAssert(($staticItem[0]['can_enable'] ?? false) === true, '应用商店插件缺少启停能力字段');

    $appDisable = pluginHttpRequest($base, '/api/admin/app-items/disable', 'POST', 'plugin-http-token', [
        'uuid' => 'static_code',
    ]);
    pluginHttpAssert((int) ($appDisable['code'] ?? 0) === 200, '应用商店插件禁用接口失败');
    $appDisabled = array_values(array_filter(
        $appDisable['data']['list'] ?? [],
        static fn (array $item): bool => ($item['name'] ?? '') === 'static_code',
    ));
    pluginHttpAssert((int) ($appDisabled[0]['status'] ?? 1) === 2, '应用商店插件禁用状态错误');
    pluginHttpAssert(($appDisabled[0]['running'] ?? true) === false, '应用商店插件停止状态错误');

    $appEnable = pluginHttpRequest($base, '/api/admin/app-items/enable', 'POST', 'plugin-http-token', [
        'uuid' => 'static_code',
    ]);
    pluginHttpAssert((int) ($appEnable['code'] ?? 0) === 200, '应用商店插件启用接口失败');
    $appEnabled = array_values(array_filter(
        $appEnable['data']['list'] ?? [],
        static fn (array $item): bool => ($item['name'] ?? '') === 'static_code',
    ));
    pluginHttpAssert((int) ($appEnabled[0]['status'] ?? 0) === 1, '应用商店插件启用状态错误');
    pluginHttpAssert(($appEnabled[0]['running'] ?? false) === true, '应用商店插件启动状态错误');

    $reload = pluginHttpRequest($base, '/api/admin/app-items/reload', 'POST', 'plugin-http-token', [
        'uuid' => 'static_code',
    ]);
    pluginHttpAssert((int) ($reload['code'] ?? 0) === 200, '应用商店插件重载接口失败');

    $gateway = pluginHttpRequest($base, '/api/admin/channel/gateway/create', 'POST', 'plugin-http-token', [
        'name' => 'HTTP 测试网关',
        'addr' => 'https://gateway.example.test/api',
        'channel_code' => 'http-static',
        'pay_type' => 'alipay',
        'status' => 1,
        'options' => '{}',
    ]);
    pluginHttpAssert((int) ($gateway['code'] ?? 0) === 200, '支付网关创建接口失败');
    $gatewayId = (int) ($gateway['data']['id'] ?? 0);
    pluginHttpAssert($gatewayId > 0, '支付网关创建未返回 ID');

    $gatewayList = pluginHttpRequest($base, '/api/admin/channel/gateway/list', 'GET', 'plugin-http-token');
    pluginHttpAssert((int) ($gatewayList['code'] ?? 0) === 200, '支付网关列表接口失败');
    pluginHttpAssert(is_array($gatewayList['data']['list'][0]['options'] ?? null), '支付网关配置没有解码为数组');

    $invalidGateway = pluginHttpRequest($base, '/api/admin/channel/gateway/create', 'POST', 'plugin-http-token', [
        'name' => '错配网关',
        'addr' => 'https://gateway.example.test/api',
        'channel_code' => 'http-static',
        'pay_type' => 'wxpay',
        'status' => 1,
    ]);
    pluginHttpAssert((int) ($invalidGateway['code'] ?? 0) === 422, '支付方式与通道错配没有被拒绝');
    pluginHttpAssert(str_contains((string) ($invalidGateway['message'] ?? ''), '不匹配'), '错配网关没有返回中文原因');

    $removeGateway = pluginHttpRequest($base, '/api/admin/channel/gateway/remove', 'POST', 'plugin-http-token', [
        'id' => $gatewayId,
    ]);
    pluginHttpAssert((int) ($removeGateway['code'] ?? 0) === 200, '支付网关删除接口失败');

    $batchDisable = pluginHttpRequest($base, '/api/admin/app-items/batch-disable', 'POST', 'plugin-http-token', [
        'uuids' => json_encode(['static_code', 'yun_qnm_lz'], JSON_UNESCAPED_SLASHES),
    ]);
    pluginHttpAssert((int) ($batchDisable['code'] ?? 0) === 200, '应用商店插件批量停止接口失败');
    pluginHttpAssert((int) ($batchDisable['data']['success_count'] ?? 0) === 2, '应用商店插件批量停止成功数量错误');

    $batchEnable = pluginHttpRequest($base, '/api/admin/app-items/batch-enable', 'POST', 'plugin-http-token', [
        'uuids' => json_encode(['static_code', 'yun_qnm_lz'], JSON_UNESCAPED_SLASHES),
    ]);
    pluginHttpAssert((int) ($batchEnable['code'] ?? 0) === 200, '应用商店插件批量启动接口失败');
    pluginHttpAssert((int) ($batchEnable['data']['success_count'] ?? 0) === 2, '应用商店插件批量启动成功数量错误');

    echo "PluginHttpTest: OK\n";
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
    pluginHttpRemoveDirectory($pluginRoot);
    putenv('XARR_DB_DSN');
    putenv('XARR_PLUGIN_DIR');
    putenv('XARR_INSTALL_LOCK_FILE');
}
