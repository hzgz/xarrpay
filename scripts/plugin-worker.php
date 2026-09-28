<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;
use XArrPay\Support\PluginRuntimeService;

$options = getopt('', [
    'once',
    'limit::',
    'plugin::',
    'account-id::',
    'worker-id::',
    'sleep::',
]);
$limit = max(1, (int) ($options['limit'] ?? 100));
$plugin = isset($options['plugin']) ? trim((string) $options['plugin']) : null;
$accountId = isset($options['account-id']) ? max((int) $options['account-id'], 0) : null;
$workerId = trim((string) ($options['worker-id'] ?? ''));
$sleep = max(1, (int) ($options['sleep'] ?? 6));
$once = array_key_exists('once', $options);
$service = new PluginRuntimeService();

do {
    try {
        $summary = $service->runOnce(Database::connection(), $plugin, $accountId, $limit, $workerId);
        fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        if (($summary['failed'] ?? 0) > 0 && $once) {
            exit(1);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, '插件运行任务失败: ' . $exception->getMessage() . PHP_EOL);
        if ($once) {
            exit(1);
        }
    }
    if (!$once) {
        sleep($sleep);
    }
} while (!$once);
