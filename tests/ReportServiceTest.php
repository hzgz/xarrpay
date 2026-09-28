<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;
use XArrPay\Support\PluginRuntimeService;

function reportTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'xarr-report-');
if ($path === false) {
    throw new RuntimeException('无法创建报告测试数据库');
}
putenv('XARR_DB_DSN=sqlite:' . $path);

try {
    $db = Database::connection();
    $db->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
    $now = time();
    $db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at) VALUES (10000,'report-merchant','', '报告商户','report-secret',1,0,{$now})");
    $db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at) VALUES (10001,'other-merchant','', '其它商户','other-secret',1,0,{$now})");
    $db->exec("INSERT INTO pay_type (id,value,code,name,label,status) VALUES (1,'wxpay','wxpay','微信支付','微信支付',1)");
    $db->exec("INSERT INTO pay_channel (id,code,name,type,status,plugin_name,options) VALUES (1,'report-wx','报告微信通道','wxpay',1,'jk_wechat_app','{}')");
    $db->exec("INSERT INTO pay_account (id,uid,pay_type,channel_code,status,name,options,created_at,updated_at) VALUES (1,10000,'wxpay','report-wx',1,'报告账号','{\"package_name\":\"com.example.pay\"}',{$now},{$now})");
    $db->exec("INSERT INTO pay_account_ext (account_id,plugin_name,created_at,updated_at) VALUES (1,'jk_wechat_app',{$now},{$now})");
    $db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,pay_type,channel_code,account_id,expire_time,created_at,updated_at) VALUES (1,'REPORT-ORDER','REPORT-OUT',10000,123,123,1,'wxpay','report-wx',1," . ($now + 600) . "," . ($now - 10) . ",{$now})");

    $service = new PluginRuntimeService();
    $result = $service->reportFlow($db, [
        'id' => 1,
        'uid' => 10000,
        'options' => '{"package_name":"com.example.pay"}',
    ], 'jk_wechat_app', [
        'third_order_id' => 'CLIENT-FLOW-1',
        'amount' => 123,
        'trans_time' => $now,
        'remark' => '微信收款 1.23 元',
        'buyer_name' => '买家',
        'raw' => ['package_name' => 'com.example.pay'],
    ], $now);
    reportTestAssert($result['state'] === 'settled', '上报流水未完成结算');
    reportTestAssert((int) $db->query('SELECT balance FROM user WHERE id=10000')->fetchColumn() === 123, '上报结算未增加商户余额');

    $duplicate = $service->reportFlow($db, [
        'id' => 1,
        'uid' => 10000,
        'options' => '{"package_name":"com.example.pay"}',
    ], 'jk_wechat_app', [
        'third_order_id' => 'CLIENT-FLOW-1',
        'amount' => 123,
        'trans_time' => $now,
        'remark' => '微信收款 1.23 元',
        'buyer_name' => '买家',
        'raw' => ['package_name' => 'com.example.pay'],
    ], $now + 1);
    reportTestAssert($duplicate['duplicate'] === true, '重复上报没有去重');
    reportTestAssert((int) $db->query('SELECT balance FROM user WHERE id=10000')->fetchColumn() === 123, '重复上报重复入账');

    $crossMerchant = $service->reportFlow($db, [
        'id' => 1,
        'uid' => 10001,
        'options' => '{"package_name":"com.example.pay"}',
    ], 'jk_wechat_app', [
        'third_order_id' => 'CLIENT-FLOW-2',
        'amount' => 123,
        'trans_time' => $now,
        'remark' => '微信收款 1.23 元',
        'buyer_name' => '买家',
        'raw' => ['package_name' => 'com.example.pay'],
    ], $now + 2);
    reportTestAssert($crossMerchant['state'] !== 'settled', '跨商户上报错误结算');
    reportTestAssert((int) $db->query('SELECT status FROM "order" WHERE id=1')->fetchColumn() === 2, '原商户订单状态异常');

    echo "ReportServiceTest: OK\n";
} finally {
    putenv('XARR_DB_DSN');
    @unlink($path);
}
