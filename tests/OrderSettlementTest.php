<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;
use XArrPay\Support\OrderSettlementService;

$path = tempnam(sys_get_temp_dir(), 'xarr-settlement-');
if ($path === false) {
    throw new RuntimeException('cannot create test database');
}
putenv('XARR_DB_DSN=sqlite:' . $path);
$db = Database::connection();
$db->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
$now = time();
$db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at) VALUES (1,'merchant','', '测试商户','test-key',1,0,{$now})");
$db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,notify_uri,created_at,updated_at) VALUES (1,'T-SETTLE','O-SETTLE',1,200,200,1,'',{$now},{$now})");
$merchant = $db->query('SELECT * FROM user WHERE id = 1')->fetch();

$settlement = new OrderSettlementService();
$first = $settlement->settle($db, $merchant, 'O-SETTLE', 200, '2.00', 'WX-1', 'buyer-a');
$second = $settlement->settle($db, $merchant, 'O-SETTLE', 200, '2.00', 'WX-1', 'buyer-a');

if (($first['already_paid'] ?? true) !== false || ($second['already_paid'] ?? false) !== true) {
    throw new RuntimeException('settlement idempotency state mismatch');
}
if ((int) $db->query('SELECT balance FROM user WHERE id = 1')->fetchColumn() !== 200) {
    throw new RuntimeException('balance was credited more than once');
}
if ((int) $db->query("SELECT COUNT(*) FROM balance_log WHERE uid = 1 AND type = 'order_income'")->fetchColumn() !== 1) {
    throw new RuntimeException('balance log was duplicated');
}
if ((int) $db->query('SELECT COUNT(*) FROM notify_queue WHERE order_id = "T-SETTLE"')->fetchColumn() !== 1) {
    throw new RuntimeException('notification queue was duplicated');
}

$db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,created_at,updated_at) VALUES (2,'T-BAD','O-BAD',1,300,300,1,{$now},{$now})");
$failed = false;
try {
    $settlement->settle($db, $merchant, 'O-BAD', 200, '2.00');
} catch (RuntimeException $exception) {
    $failed = str_contains($exception->getMessage(), '金额');
}
if (!$failed || (int) $db->query('SELECT status FROM "order" WHERE id = 2')->fetchColumn() !== 1) {
    throw new RuntimeException('amount mismatch was not rejected atomically');
}

echo "OrderSettlementTest: OK\n";
@unlink($path);
