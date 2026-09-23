<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Config;

$dsn = (string) Config::get('XARR_DB_DSN', 'sqlite:' . dirname(__DIR__) . '/var/local.sqlite');
if (!str_starts_with($dsn, 'sqlite:')) {
    fwrite(STDERR, "本地初始化只接受 SQLite DSN。\n");
    exit(1);
}

$path = substr($dsn, 7);
if ($path !== ':memory:') {
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

$db = new \PDO($dsn, null, null, [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
]);
$db->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));

// Upgrade databases created by the earlier demo schema in place.
$columns = [
    'pay_channel' => [
        'plugin_name' => "TEXT NOT NULL DEFAULT ''",
        'remark' => "TEXT NOT NULL DEFAULT ''",
        'options' => "TEXT NOT NULL DEFAULT '{}'",
    ],
    'pay_account' => [
        'name' => "TEXT NOT NULL DEFAULT ''",
        'sub_account' => "TEXT NOT NULL DEFAULT ''",
        'bind_client_name' => "TEXT NOT NULL DEFAULT ''",
        'sort' => 'INTEGER NOT NULL DEFAULT 50',
        'remark' => "TEXT NOT NULL DEFAULT ''",
        'min_amount' => 'INTEGER NOT NULL DEFAULT 0',
        'max_amount' => 'INTEGER NOT NULL DEFAULT 0',
        'day_amount_limit' => 'INTEGER NOT NULL DEFAULT 0',
        'code' => "TEXT NOT NULL DEFAULT ''",
        'options' => "TEXT NOT NULL DEFAULT '{}'",
        'bind_pay_type' => "TEXT NOT NULL DEFAULT '[]'",
    ],
    'order' => [
        'channel_code' => "TEXT NOT NULL DEFAULT ''",
        'account_id' => 'INTEGER NOT NULL DEFAULT 0',
        'notify_status' => 'INTEGER NOT NULL DEFAULT 0',
        'notify_count' => 'INTEGER NOT NULL DEFAULT 0',
        'notify_time' => 'INTEGER',
        'actual_account' => "TEXT NOT NULL DEFAULT ''",
        'pay_ip' => "TEXT NOT NULL DEFAULT ''",
        'remark' => "TEXT NOT NULL DEFAULT ''",
    ],
    'user' => [
        'username' => "TEXT NOT NULL DEFAULT ''",
        'password' => "TEXT NOT NULL DEFAULT ''",
        'token' => "TEXT NOT NULL DEFAULT ''",
    ],
];
$db->beginTransaction();
foreach ($columns as $table => $definitions) {
    $present = [];
    foreach ($db->query('PRAGMA table_info("' . $table . '")')->fetchAll() as $column) {
        $present[(string) ($column['name'] ?? '')] = true;
    }
    foreach ($definitions as $name => $definition) {
        if (!isset($present[$name])) {
            $db->exec('ALTER TABLE "' . $table . '" ADD COLUMN "' . $name . '" ' . $definition);
        }
    }
}
$existingMerchants = $db->query('SELECT id, merchant_name, app_secret FROM "user" ORDER BY id')->fetchAll();
$defaultMerchantId = 0;
foreach ($existingMerchants as $merchantRow) {
    $id = (int) $merchantRow['id'];
    $username = $id === (int) ($existingMerchants[0]['id'] ?? 0) ? 'coco' : 'merchant_' . $id;
    $password = $id === (int) ($existingMerchants[0]['id'] ?? 0) ? md5('123456') : '';
    $merchantName = (string) ($merchantRow['merchant_name'] ?? '');
    $appSecret = (string) ($merchantRow['app_secret'] ?? '');
    if ($appSecret === '') {
        $appSecret = 'local-merchant-' . $id;
    }
    $update = $db->prepare('UPDATE "user" SET username = :username, password = :password, merchant_name = :merchant_name, app_secret = :app_secret WHERE id = :id');
    $update->execute([
        ':username' => $username,
        ':password' => $password,
        ':merchant_name' => $merchantName !== '' ? $merchantName : $username,
        ':app_secret' => $appSecret,
        ':id' => $id,
    ]);
    if ($id === (int) ($existingMerchants[0]['id'] ?? 0)) {
        $defaultMerchantId = $id;
    }
}
if ($defaultMerchantId === 0) {
    $insertMerchant = $db->prepare('INSERT INTO "user" (username, password, merchant_name, app_secret, status, balance, token) VALUES (:username, :password, :merchant_name, :app_secret, 1, 0, "")');
    $insertMerchant->execute([
        ':username' => 'coco',
        ':password' => md5('123456'),
        ':merchant_name' => 'coco',
        ':app_secret' => 'local-demo-key',
    ]);
    $defaultMerchantId = (int) $db->lastInsertId();
}
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS "user_username_unique" ON "user" (username)');
$db->commit();
$db->exec("INSERT OR IGNORE INTO staff (id, username, password, name, status, super) VALUES (1, 'admin', 'e10adc3949ba59abbe56e057f20f883e', '本地管理员', 1, 1)");
$db->exec("INSERT OR IGNORE INTO pay_type (id, value, code, name, label, logo, status) VALUES (1, 'alipay', 'alipay', 'alipay', '支付宝', '/admin/static/images/pay/alipay.png', 1)");
$db->exec("INSERT OR IGNORE INTO pay_type (id, value, code, name, label, logo, status) VALUES (2, 'wechat', 'wechat', 'wechat', '微信支付', '/admin/static/images/pay/wechat.png', 1)");
$db->exec("INSERT OR IGNORE INTO pay_channel (id, code, name, type, status) VALUES (1, 'demo', '本地演示通道', 'demo', 1)");
$db->exec("INSERT OR IGNORE INTO pay_account (id, uid, pay_type, channel_code, account, account_type, status) VALUES (1, 1, 'alipay', 'demo', '本地演示收款账号', '演示账号', 1)");
$db->exec("INSERT OR IGNORE INTO pay_account (id, uid, pay_type, channel_code, account, account_type, status) VALUES (2, 1, 'wechat', 'demo', '本地演示收款账号', '演示账号', 1)");
$db->exec("INSERT OR IGNORE INTO options (key, value) VALUES ('web_title', 'XArrPay 本地演示')");
$db->exec("INSERT OR IGNORE INTO options (key, value) VALUES ('web_index_title', 'XArrPay 本地演示')");
$db->exec("INSERT OR IGNORE INTO options (key, value) VALUES ('web_logo', '/admin/static/images/logo.png')");
$db->exec("INSERT OR IGNORE INTO options (key, value) VALUES ('web_service_qq', '')");

$exists = (int) $db->query("SELECT COUNT(*) FROM \"order\" WHERE order_id = 'LOCAL-DEMO-0001'")->fetchColumn();
if ($exists === 0) {
    $now = time();
    $insert = $db->prepare('INSERT INTO "order" (order_id, out_order_id, uid, price, amount, trade_amount, rate_amount, status, subject, expire_time, pay_type, created_at, updated_at) VALUES (:order_id, :out_order_id, 1, 100, 100, 100, 0, 1, :subject, :expire_time, "", :created_at, :updated_at)');
    $insert->execute([':order_id' => 'LOCAL-DEMO-0001', ':out_order_id' => 'LOCAL-OUT-0001', ':subject' => '本地演示订单', ':expire_time' => $now + 900, ':created_at' => $now, ':updated_at' => $now]);
}

fwrite(STDOUT, "SQLite 本地数据库已初始化: {$path}\n");
fwrite(STDOUT, "后台账号: admin / 123456\n");
fwrite(STDOUT, "默认商户账号: coco / 123456\n");
fwrite(STDOUT, "演示订单: LOCAL-DEMO-0001\n");
fwrite(STDOUT, "收银台密钥: local-demo-key\n");
