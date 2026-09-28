<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\PaymentPluginRegistry;
use XArrPay\Support\PluginRuntime;

function pluginRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pluginRuntimeRemove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) && !is_link($child) ? pluginRuntimeRemove($child) : @unlink($child);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xarr-plugin-runtime-' . bin2hex(random_bytes(6));
$pluginPath = $root . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . 'static_code';
$invalidPath = $root . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . 'wrong-folder';
$incompletePath = $root . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . 'incomplete';
mkdir($pluginPath, 0775, true);
mkdir($invalidPath, 0775, true);
mkdir($incompletePath, 0775, true);

try {
    $source = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . 'static_code';
    foreach (['manifest.json', 'adapter.php', 'account.php', 'form.php'] as $file) {
        pluginRuntimeAssert(
            copy($source . DIRECTORY_SEPARATOR . $file, $pluginPath . DIRECTORY_SEPARATOR . $file),
            '无法复制测试插件文件: ' . $file,
        );
    }
    file_put_contents(
        $invalidPath . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode([
            'name' => 'different-name',
            'type' => 'pay',
            'enabled' => true,
            'running' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
    $incompleteManifest = json_decode(
        (string) file_get_contents($source . DIRECTORY_SEPARATOR . 'manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $incompleteManifest['name'] = 'incomplete';
    file_put_contents(
        $incompletePath . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode($incompleteManifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );

    putenv('XARR_PLUGIN_DIR=' . $root);
    PaymentPluginRegistry::clearCache();

    pluginRuntimeAssert(count(PluginRuntime::manifests()) === 1, '有效插件清单数量错误');
    $diagnostics = PluginRuntime::diagnostics();
    $invalid = array_values(array_filter(
        $diagnostics,
        static fn (array $item): bool => ($item['directory'] ?? '') === 'wrong-folder',
    ));
    pluginRuntimeAssert(count($invalid) === 1, '无效插件目录未进入自检结果');
    pluginRuntimeAssert(($invalid[0]['valid'] ?? true) === false, '目录名不一致未被判定为无效');
    $incomplete = array_values(array_filter(
        $diagnostics,
        static fn (array $item): bool => ($item['directory'] ?? '') === 'incomplete',
    ));
    pluginRuntimeAssert(count($incomplete) === 1, '缺少入口文件的目录未进入自检结果');
    pluginRuntimeAssert(($incomplete[0]['valid'] ?? true) === false, '缺少支付入口文件未被判定为无效');

    pluginRuntimeAssert(
        PaymentPluginRegistry::resolve('static_code')->name() === 'static_code',
        '有效目录插件首次解析失败',
    );
    PluginRuntime::setState('static_code', 'disable');
    $disabled = false;
    try {
        PaymentPluginRegistry::resolve('static_code');
    } catch (RuntimeException $exception) {
        $disabled = str_contains($exception->getMessage(), '未启用');
    }
    pluginRuntimeAssert($disabled, '插件停用后仍使用进程缓存');

    PluginRuntime::setState('static_code', 'enable');
    PluginRuntime::setState('static_code', 'stop');
    $stopped = false;
    try {
        PaymentPluginRegistry::resolve('static_code');
    } catch (RuntimeException $exception) {
        $stopped = str_contains($exception->getMessage(), '未运行');
    }
    pluginRuntimeAssert($stopped, '插件停止后仍可解析');

    PluginRuntime::setState('static_code', 'start');
    pluginRuntimeAssert(
        PaymentPluginRegistry::resolve('static_code')->name() === 'static_code',
        '插件重新启用后解析失败',
    );

    $invalidAction = false;
    try {
        PluginRuntime::setState('static_code', 'reload');
    } catch (InvalidArgumentException $exception) {
        $invalidAction = str_contains($exception->getMessage(), '操作无效');
    }
    pluginRuntimeAssert($invalidAction, '未知插件操作未被拒绝');

    echo "PluginRuntimeTest: OK\n";
} finally {
    pluginRuntimeRemove($root);
    putenv('XARR_PLUGIN_DIR');
    PaymentPluginRegistry::clearCache();
}
