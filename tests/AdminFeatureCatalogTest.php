<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function adminCatalogAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function adminCatalogRequest(string $base, string $path, string $method = 'GET', array $data = []): array
{
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('curl 初始化失败');
    }
    $headers = ['Accept: application/json', 'Authorization: admin-feature-catalog-token'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 3,
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
        throw new RuntimeException("{$method} {$path} 返回非 JSON {$status}: " . substr($body, 0, 200));
    }
    $decoded['_http_status'] = $status;
    return $decoded;
}

$dbPath = tempnam(sys_get_temp_dir(), 'xarr-admin-catalog-');
if ($dbPath === false) {
    throw new RuntimeException('无法创建测试数据库');
}
$lockPath = $root . '/var/.admin-feature-catalog-' . bin2hex(random_bytes(4)) . '.lock';

$process = null;
$pipes = [];

try {
    putenv('XARR_DB_DSN=sqlite:' . $dbPath);
    putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);
    file_put_contents($lockPath, json_encode([
        'version' => 1,
        'installed_at' => gmdate('c'),
        'admin' => 'admin',
        'username' => 'admin-feature-catalog',
    ], JSON_UNESCAPED_SLASHES));
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec((string) file_get_contents($root . '/database/schema.sqlite.sql'));
    $db->prepare('INSERT INTO staff (id,username,password,name,status,token) VALUES (1,:username,:password,:name,1,:token)')
        ->execute([
            ':username' => 'admin-feature-catalog',
            ':password' => md5('123456'),
            ':name' => '后台目录接口测试',
            ':token' => 'admin-feature-catalog-token',
        ]);
    $db->prepare(
        'INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,settings) '
        . 'VALUES (1,:username,:password,:merchant_name,:app_secret,1,0,:settings)'
    )->execute([
        ':username' => 'catalog-merchant',
        ':password' => md5('123456'),
        ':merchant_name' => '目录测试商户',
        ':app_secret' => 'catalog-merchant-secret',
        ':settings' => json_encode(['recommend_code' => 'CATALOG'], JSON_UNESCAPED_UNICODE),
    ]);

    $port = random_int(20080, 20999);
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
            $health = adminCatalogRequest($base, '/api/health');
            if ((int) ($health['code'] ?? 0) === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    adminCatalogAssert($ready, 'PHP 测试服务未启动');

    $optionTemplates = adminCatalogRequest($base, '/api/admin/option/templates');
    adminCatalogAssert((int) ($optionTemplates['code'] ?? 0) === 200, '配置模板接口失败');
    adminCatalogAssert(is_array($optionTemplates['data']['frontend'] ?? null), '前台模板列表格式错误');
    adminCatalogAssert(is_array($optionTemplates['data']['backend'] ?? null), '后台模板列表格式错误');

    $localPlugins = adminCatalogRequest($base, '/api/admin/app-items/scan-local-plugins');
    adminCatalogAssert((int) ($localPlugins['code'] ?? 0) === 200, '本地插件扫描接口失败');
    $pluginGroups = $localPlugins['data']['plugins'] ?? [];
    $pluginNames = [];
    foreach (is_array($pluginGroups) ? $pluginGroups : [] as $group) {
        foreach (is_array($group['plugins'] ?? null) ? $group['plugins'] : [] as $plugin) {
            $pluginNames[] = (string) ($plugin['name'] ?? '');
        }
    }
    adminCatalogAssert(count($pluginNames) === 5, '本地插件扫描数量错误');
    adminCatalogAssert(in_array('static_code', $pluginNames, true), '本地插件扫描缺少 static_code');

    $loadPlugin = adminCatalogRequest($base, '/api/admin/app-items/load-local-plugin', 'POST', [
        'plugin_type' => 'pay',
        'plugin_name' => 'static_code',
    ]);
    adminCatalogAssert((int) ($loadPlugin['code'] ?? 0) === 200, '本地插件加载接口失败');

    $baseOptions = adminCatalogRequest($base, '/api/admin/option/base');
    adminCatalogAssert((int) ($baseOptions['code'] ?? 0) === 200, '基础配置读取接口失败');
    adminCatalogAssert(array_key_exists('web_title', $baseOptions['data'] ?? []), '基础配置缺少网站标题字段');
    adminCatalogAssert(array_key_exists('admin_path', $baseOptions['data'] ?? []), '基础配置缺少后台路径字段');

    $savedBase = adminCatalogRequest($base, '/api/admin/option/base', 'POST', [
        'web_index_title' => '目录测试首页',
        'web_title' => '目录测试站点',
        'admin_path' => 'admin',
        'third_account_report_secret' => 'catalog-report-secret',
        'system_plugin_maxtotal' => '50',
    ]);
    adminCatalogAssert((int) ($savedBase['code'] ?? 0) === 200, '基础配置保存接口失败');
    $baseOptionsAfterSave = adminCatalogRequest($base, '/api/admin/option/base');
    adminCatalogAssert((string) ($baseOptionsAfterSave['data']['web_title'] ?? '') === '目录测试站点', '基础配置保存后读取值错误');

    $logs = adminCatalogRequest($base, '/api/admin/log/files');
    adminCatalogAssert((int) ($logs['code'] ?? 0) === 200, '日志文件列表接口失败');
    $logItems = is_array($logs['data'] ?? null) ? $logs['data'] : [];
    adminCatalogAssert(
        count(array_filter($logItems, static fn (array $item): bool => str_starts_with((string) ($item['path'] ?? ''), 'var/')))
        >= 1,
        '日志列表没有应用运行日志',
    );
    adminCatalogAssert(
        count(array_filter($logItems, static fn (array $item): bool => str_contains((string) ($item['path'] ?? ''), 'browser-')))
        === 0,
        '日志列表错误包含浏览器测试目录日志',
    );
    adminCatalogAssert(
        count(array_filter($logItems, static fn (array $item): bool => array_key_exists('_path', $item))) === 0,
        '日志列表不应暴露服务器绝对路径',
    );
    foreach ($logItems as $logItem) {
        $logPath = (string) ($logItem['path'] ?? '');
        adminCatalogAssert(
            !str_contains($logPath, 'legacy/')
            && !str_contains($logPath, 'wwwlogs/')
            && !str_contains($logPath, 'php-fpm/')
            && !str_contains($logPath, 'nginx/'),
            '日志列表包含其它站点或服务日志：' . $logPath,
        );
    }
    $firstLogPath = (string) ($logItems[0]['path'] ?? '');
    adminCatalogAssert($firstLogPath !== '', '日志列表没有可查看文件');
    $logDetail = adminCatalogRequest($base, '/api/admin/log/detail?file=' . rawurlencode($firstLogPath));
    adminCatalogAssert((int) ($logDetail['code'] ?? 0) === 200, '日志详情接口失败');

    $connectItems = adminCatalogRequest($base, '/api/admin/connect/items');
    adminCatalogAssert((int) ($connectItems['code'] ?? 0) === 200, '通知连接项接口失败');
    adminCatalogAssert(count($connectItems['data']['connect'] ?? []) >= 3, '通知连接项数量不足');
    adminCatalogAssert(count($connectItems['data']['events'] ?? []) >= 3, '通知事件数量不足');

    $templates = adminCatalogRequest($base, '/api/admin/connect/templates');
    adminCatalogAssert((int) ($templates['code'] ?? 0) === 200, '通知模板矩阵接口失败');
    $first = $templates['data']['data'][0] ?? [];
    adminCatalogAssert(isset($first['global'], $first['system']), '通知模板矩阵缺少通道单元');
    adminCatalogAssert((string) ($first['system']['source_type'] ?? '') === 'system', '通知模板通道字段错误');

    $create = adminCatalogRequest($base, '/api/admin/notification/template/create', 'POST', [
        'name' => '订单支付成功站内通知',
        'code' => 'order_paid:system',
        'source_type' => 'system',
        'event_id' => 'order_paid',
        'template_content' => '订单{{order_id}}支付成功',
        'status' => 1,
    ]);
    adminCatalogAssert((int) ($create['code'] ?? 0) === 200, '通知模板创建失败');

    $templatesAfterCreate = adminCatalogRequest($base, '/api/admin/connect/templates');
    $orderPaid = $templatesAfterCreate['data']['data'][0] ?? [];
    adminCatalogAssert((int) ($orderPaid['system']['id'] ?? 0) > 0, '通知模板矩阵未读取已创建模板');
    adminCatalogAssert((string) ($orderPaid['system']['template_content'] ?? '') === '订单{{order_id}}支付成功', '通知模板内容读取错误');

    $wizard = adminCatalogRequest($base, '/api/admin/tools/config-wizard/check');
    adminCatalogAssert((int) ($wizard['code'] ?? 0) === 200, '配置向导检查接口失败');
    adminCatalogAssert(is_array($wizard['data']['permission_checks'] ?? null), '权限检查格式错误');
    adminCatalogAssert(is_array($wizard['data']['theme_checks'] ?? null), '模板检查格式错误');
    adminCatalogAssert(is_array($wizard['data']['redis_check'] ?? null), 'Redis 检查格式错误');

    $runtime = adminCatalogRequest($base, '/api/admin/system/runtime');
    adminCatalogAssert((int) ($runtime['code'] ?? 0) === 200, '系统监控运行态接口失败');
    foreach (['sysOsName', 'sysOsArch', 'sysComputerName', 'pid', 'goRunTime', 'goStartTime', 'goUsed', 'runUser', 'cpuNum', 'cpuUsed', 'cpuAvg5', 'cpuAvg15', 'memTotal', 'memUsed', 'memFree', 'memUsage'] as $field) {
        adminCatalogAssert(array_key_exists($field, $runtime['data'] ?? []), '系统监控运行态缺少字段：' . $field);
    }
    adminCatalogAssert((int) ($runtime['data']['pid'] ?? 0) > 0, '系统监控进程 ID 无效');
    adminCatalogAssert((int) ($runtime['data']['cpuNum'] ?? 0) > 0, '系统监控 CPU 核心数无效');
    adminCatalogAssert((int) ($runtime['data']['memTotal'] ?? 0) > 0, '系统监控内存总量无效');

    $smsPlugins = adminCatalogRequest($base, '/api/admin/sms/plugin/list');
    adminCatalogAssert((int) ($smsPlugins['code'] ?? 0) === 200, '短信插件列表接口失败');
    adminCatalogAssert(is_array($smsPlugins['data']['list'] ?? null), '短信插件列表格式错误');

    $smsForm = adminCatalogRequest($base, '/api/admin/sms/plugin/form-items?plugin_name=');
    adminCatalogAssert((int) ($smsForm['code'] ?? 0) === 200, '空短信插件表单接口失败');
    adminCatalogAssert(($smsForm['data']['items'] ?? null) === [], '空短信插件表单应返回空配置项');

    $users = adminCatalogRequest($base, '/api/admin/user/list');
    adminCatalogAssert((int) ($users['code'] ?? 0) === 200, '商户列表接口失败');
    adminCatalogAssert((string) ($users['data']['list'][0]['recommend_code'] ?? '') === 'CATALOG', '商户列表扩展字段缺失');

    $loginTicket = adminCatalogRequest($base, '/api/admin/user/login-ticket', 'POST', ['id' => 1]);
    adminCatalogAssert((int) ($loginTicket['code'] ?? 0) === 200, '后台免密票据创建失败');
    $ticket = (string) ($loginTicket['data']['ticket'] ?? '');
    adminCatalogAssert($ticket !== '' && str_contains((string) ($loginTicket['data']['login_url'] ?? ''), $ticket), '后台免密链接格式错误');

    $ticketData = adminCatalogRequest($base, '/api/tickets/data?ticket=' . rawurlencode($ticket));
    adminCatalogAssert((int) ($ticketData['code'] ?? 0) === 200, '后台免密票据兑换失败');
    adminCatalogAssert((string) ($ticketData['data']['token'] ?? '') !== '', '后台免密票据未签发商户 token');
    $ticketReplay = adminCatalogRequest($base, '/api/tickets/data?ticket=' . rawurlencode($ticket));
    adminCatalogAssert((int) ($ticketReplay['code'] ?? 0) === 200 && !isset($ticketReplay['data']['token']), '后台免密票据可重复兑换');

    $uidLoginTicket = adminCatalogRequest($base, '/api/admin/user/login-ticket', 'POST', ['uid' => 1]);
    adminCatalogAssert((int) ($uidLoginTicket['code'] ?? 0) === 200, '后台免密票据 uid 字段创建失败');
    adminCatalogAssert((string) ($uidLoginTicket['data']['ticket'] ?? '') !== '', '后台免密票据 uid 字段未返回票据');

    echo "AdminFeatureCatalogTest: OK\n";
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
