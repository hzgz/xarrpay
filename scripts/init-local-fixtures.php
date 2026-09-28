<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Config;

if (Config::get('XARR_ENV') !== 'test' || Config::get('XARR_FIXTURES') !== '1') {
    fwrite(STDERR, "测试夹具仅在 XARR_ENV=test 且 XARR_FIXTURES=1 时可写入。\n");
    exit(1);
}

$dsn = (string) Config::get('XARR_DB_DSN', '');
$root = realpath(dirname(__DIR__));
$var = realpath(dirname(__DIR__) . '/var');
if (!str_starts_with($dsn, 'sqlite:') || $root === false || $var === false) {
    fwrite(STDERR, "测试夹具需要项目 var 目录中的 SQLite 数据库。\n");
    exit(1);
}
$path = substr($dsn, 7);
$absolutePath = realpath($path);
if ($absolutePath === false
    || !str_starts_with(strtolower($absolutePath), strtolower($var . DIRECTORY_SEPARATOR))
    || preg_match('/^test-[a-f0-9]{32}\.sqlite$/i', basename($absolutePath)) !== 1
) {
    fwrite(STDERR, "测试夹具仅允许写入 var/test-<guid>.sqlite。\n");
    exit(1);
}

$db = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys = ON');

foreach (['staff', 'user', 'pay_type', 'pay_channel', 'pay_account', 'pay_codes', 'order'] as $table) {
    if ((int) $db->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn() !== 0) {
        fwrite(STDERR, "拒绝向非空测试库写入夹具: {$table}\n");
        exit(1);
    }
}

$now = time();
$db->beginTransaction();
try {
    $staff = $db->prepare('INSERT INTO staff (id,username,password,name,status,super) VALUES (1,:username,:password,:name,1,1)');
    $staff->execute([':username' => 'admin', ':password' => md5('123456'), ':name' => 'Test Administrator']);
    $merchant = $db->prepare('INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,payed,audit,created_at) VALUES (10000,:username,:password,:merchant_name,:app_secret,1,0,1,1,:created_at)');
    $merchant->execute([
        ':username' => 'coco',
        ':password' => md5('123456'),
        ':merchant_name' => 'Test Merchant',
        ':app_secret' => '0123456789abcdef0123456789abcdef',
        ':created_at' => $now,
    ]);
    $db->exec("INSERT INTO pay_type (id,value,code,name,label,status) VALUES
        (1,'alipay','alipay','alipay','支付宝',1),
        (2,'wxpay','wxpay','wxpay','微信支付',1)");
    $db->exec("INSERT INTO pay_channel (id,code,name,type,status,plugin_name) VALUES
        (1,'demo','Test Alipay Static-Code Channel','alipay',1,'static_code'),
        (2,'demo-wxpay','Test WeChat Static-Code Channel','wxpay',1,'static_code')");

    $account = $db->prepare('INSERT INTO pay_account (id,uid,pay_type,channel_code,account,account_type,status,name,created_at,updated_at) VALUES (:id,10000,:pay_type,:channel_code,:account,:account_type,1,:name,:created_at,:updated_at)');
    $code = $db->prepare('INSERT INTO pay_codes (account_id,uid,pay_type,channel_code,code_type,content,qrcode_data,amount,status,sort,options,created_at,updated_at) VALUES (:account_id,10000,:pay_type,:channel_code,\'text\',:content,:content,0,1,50,\'{}\',:created_at,:updated_at)');
    foreach ([[1, 'alipay'], [2, 'wxpay']] as [$id, $payType]) {
        $label = 'test-' . $payType;
        $channelCode = $payType === 'alipay' ? 'demo' : 'demo-wxpay';
        $account->execute([
            ':id' => $id,
            ':pay_type' => $payType,
            ':channel_code' => $channelCode,
            ':account' => $label,
            ':account_type' => 'test',
            ':name' => $label,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $code->execute([
            ':account_id' => $id,
            ':pay_type' => $payType,
            ':channel_code' => $channelCode,
            ':content' => $label,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $order = $db->prepare('INSERT INTO "order" (order_id,out_order_id,uid,price,amount,trade_amount,rate_amount,status,subject,expire_time,pay_type,created_at,updated_at) VALUES (\'LOCAL-DEMO-0001\',\'LOCAL-OUT-0001\',10000,100,100,100,0,1,\'Isolated test order\',:expire_time,\'\',:created_at,:updated_at)');
    $order->execute([':expire_time' => $now + 900, ':created_at' => $now, ':updated_at' => $now]);

    $option = $db->prepare('INSERT INTO options (key,value) VALUES (:key,:value)');
    foreach ([
        'web_title' => 'Local Test',
        'web_index_title' => 'Local Test',
        'web_logo' => '/admin/static/images/logo.png',
        'web_service_qq' => '',
        'withdraw_withdraw_income_enable' => '1',
        'withdraw_withdraw_income_fee_type' => '1',
        'withdraw_withdraw_income_fee_value' => '0',
        'withdraw_withdraw_income_min' => '0',
    ] as $key => $value) {
        $option->execute([':key' => $key, ':value' => $value]);
    }
    $notice = $db->prepare('INSERT INTO notice (title,content,position,status,sort,created_at,updated_at) VALUES (:title,:content,:position,1,50,:created_at,:updated_at)');
    foreach ([4, 8] as $position) {
        $notice->execute([
            ':title' => 'Test notice ' . $position,
            ':content' => 'Isolated smoke-test notice.',
            ':position' => $position,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

fwrite(STDOUT, "仅测试库夹具已创建: {$absolutePath}\n");
