<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;
use XArrPay\Support\PluginRuntimeService;
use XArrPay\Payment\PaymentPlugin;
use XArrPay\Payment\RuntimePlugin;

function pluginRuntimeServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class TestRuntimePlugin implements PaymentPlugin, RuntimePlugin
{
    public function name(): string
    {
        return 'test_runtime';
    }

    public function capabilities(): array
    {
        return ['create', 'cron', 'heartbeat'];
    }

    public function create(array $order, array $channel, array $account): array
    {
        return ['status' => 'pending', 'type' => 'qrcode', 'qrcode' => 'test://qrcode'];
    }

    public function query(array $order, array $channel, array $account): array
    {
        throw new RuntimeException('测试插件不提供主动查询');
    }

    public function heartbeat(PDO $db, array $account, int $now): array
    {
        return ['online' => true, 'message' => '测试心跳正常', 'last_seen_at' => $now];
    }

    public function cron(PDO $db, array $account, int $now): array
    {
        $options = json_decode((string) ($account['options'] ?? '{}'), true);
        $options = is_array($options) ? $options : [];
        return [
            'flows' => [[
                'third_order_id' => (string) ($options['flow_id'] ?? 'FLOW-1'),
                'amount' => (int) ($options['flow_amount'] ?? 123),
                'trans_time' => $now,
                'remark' => '测试流水',
                'buyer_name' => '测试买家',
                'raw' => ['source' => 'test'],
            ]],
            'message' => '测试流水同步完成',
        ];
    }

    public function parseMessage(array $message, array $account): array
    {
        throw new RuntimeException('测试插件不接受消息');
    }
}

$path = tempnam(sys_get_temp_dir(), 'xarr-plugin-runtime-db-');
$pluginRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xarr-plugin-runtime-' . bin2hex(random_bytes(5));
if ($path === false || !mkdir($pluginRoot . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . 'test_runtime', 0775, true)) {
    throw new RuntimeException('无法创建运行态测试目录');
}

try {
    putenv('XARR_DB_DSN=sqlite:' . $path);
    putenv('XARR_PLUGIN_DIR=' . $pluginRoot);
    $pluginPath = $pluginRoot . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . 'test_runtime';
    file_put_contents($pluginPath . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
        'id' => 'test_runtime',
        'name' => 'test_runtime',
        'type' => 'pay',
        'pay_types' => ['wxpay'],
        'enabled' => true,
        'running' => true,
        'capabilities' => ['create', 'cron', 'heartbeat'],
        'adapter_entry' => 'adapter.php',
        'account_entry' => 'account.php',
        'form_entry' => 'form.php',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($pluginPath . DIRECTORY_SEPARATOR . 'adapter.php', '<?php return new \\TestRuntimePlugin();');
    file_put_contents($pluginPath . DIRECTORY_SEPARATOR . 'account.php', '<?php return new \\XArrPay\\Payment\\UnsupportedAccountPlugin();');
    file_put_contents($pluginPath . DIRECTORY_SEPARATOR . 'form.php', '<?php return [];');

    $db = Database::connection();
    $db->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
    $now = time();
    $db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at) VALUES (1,'m1','', '商户一','key1',1,0,{$now})");
    $db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at) VALUES (2,'m2','', '商户二','key2',1,0,{$now})");
    $db->exec("INSERT INTO pay_type (value,code,name,label,status) VALUES ('wxpay','wxpay','微信支付','微信支付',1)");
    $db->exec("INSERT INTO pay_channel (code,name,type,status,plugin_name,options) VALUES ('test-runtime','运行态测试通道','wxpay',1,'test_runtime','{}')");
    $db->exec("INSERT INTO pay_account (id,uid,pay_type,channel_code,status,name,options,created_at,updated_at) VALUES (1,1,'wxpay','test-runtime',1,'账号一','{\"flow_id\":\"FLOW-1\",\"flow_amount\":123}',{$now},{$now})");
    $db->exec("INSERT INTO pay_account_ext (account_id,plugin_name,created_at,updated_at) VALUES (1,'test_runtime',{$now},{$now})");
    $db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,channel_code,account_id,expire_time,created_at,updated_at) VALUES (1,'ORDER-1','OUT-1',1,123,123,1,'test-runtime',1," . ($now + 900) . "," . ($now - 10) . ",{$now})");
    $db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,channel_code,account_id,expire_time,created_at,updated_at) VALUES (2,'ORDER-2','OUT-2',2,123,123,1,'test-runtime',1," . ($now + 900) . "," . ($now - 10) . ",{$now})");

    \XArrPay\Support\PaymentPluginRegistry::clearCache();
    $service = new PluginRuntimeService();
    $first = $service->runOnce($db, 'test_runtime', 1, 10, 'test-worker', $now);
    pluginRuntimeServiceAssert($first['processed'] === 1, '首次运行未写入流水');
    pluginRuntimeServiceAssert($first['settled'] === 1, '首次运行未完成订单结算');
    pluginRuntimeServiceAssert((int) $db->query('SELECT balance FROM user WHERE id=1')->fetchColumn() === 123, '商户余额未按流水入账');
    pluginRuntimeServiceAssert((int) $db->query('SELECT status FROM "order" WHERE id=1')->fetchColumn() === 2, '匹配订单未标记已支付');
    pluginRuntimeServiceAssert((int) $db->query('SELECT status FROM "order" WHERE id=2')->fetchColumn() === 1, '账号归属校验未阻止跨商户结算');

    $second = $service->runOnce($db, 'test_runtime', 1, 10, 'test-worker', $now + 1);
    pluginRuntimeServiceAssert($second['processed'] === 0, '重复流水被重复写入');
    pluginRuntimeServiceAssert((int) $db->query('SELECT COUNT(*) FROM third_order')->fetchColumn() === 1, '第三方流水去重失败');
    pluginRuntimeServiceAssert((int) $db->query('SELECT COUNT(*) FROM balance_log WHERE uid=1 AND type=\'order_income\'')->fetchColumn() === 1, '重复运行导致重复入账');
    pluginRuntimeServiceAssert(count($service->status($db, 'test_runtime', 1)) === 1, '运行态状态查询失败');

    $db->exec("UPDATE pay_account SET options='{\"flow_id\":\"FLOW-BAD\",\"flow_amount\":200}' WHERE id=1");
    $third = $service->runOnce($db, 'test_runtime', 1, 10, 'test-worker', $now + 2);
    pluginRuntimeServiceAssert($third['settled'] === 0, '金额不一致流水被错误结算');
    pluginRuntimeServiceAssert((int) $db->query('SELECT balance FROM user WHERE id=1')->fetchColumn() === 123, '金额不一致导致余额变化');

    echo "PluginRuntimeServiceTest: OK\n";
} finally {
    \XArrPay\Support\PaymentPluginRegistry::clearCache();
    putenv('XARR_DB_DSN');
    putenv('XARR_PLUGIN_DIR');
    if (is_dir($pluginRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($pluginRoot);
    }
    @unlink((string) $path);
}
