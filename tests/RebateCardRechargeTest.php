<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;
use XArrPay\Support\RebateService;
use XArrPay\Support\RechargeCardService;
use XArrPay\Support\RechargeService;
use XArrPay\Support\OrderSettlementService;

function assertCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'xarr-rebate-card-');
if ($path === false) {
    throw new RuntimeException('cannot create test database');
}
putenv('XARR_DB_DSN=sqlite:' . $path);
$db = Database::connection();
$db->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
$now = time();

$db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,recommend_uid,created_at) VALUES
    (1,'referrer','', '推荐商户','referrer-key',1,0,0,{$now}),
    (2,'invited','', '被推荐商户','invited-key',1,0,1,{$now})");

$option = $db->prepare('INSERT INTO options (`key`, value) VALUES (:key, :value)');
$options = [
    'recommend_enable' => '1',
    'recommend_fee_enable' => '1',
    'recommend_fee_min_amount' => '0',
    'recommend_fee_type' => '1',
    'recommend_fee_amount' => '10',
    'recommend_top_up_enable' => '1',
    'recommend_top_up_min_spend' => '0',
    'recommend_top_up_type' => '1',
    'recommend_top_up_value' => '5',
    'pay_recharge_enable' => '1',
    'pay_recharge_type' => 'epay',
    'pay_recharge_pay_type' => 'alipay',
    'pay_recharge_min' => '1',
    'pay_recharge_epay_host' => 'https://pay.example.test',
    'pay_recharge_epay_pid' => '10001',
    'pay_recharge_epay_key' => 'test-key',
];
foreach ($options as $key => $value) {
    $option->execute([':key' => $key, ':value' => $value]);
}

$db->exec("INSERT INTO card_group (id,name,value_type,value,meal_id,total_limit,time_limit,day_limit,status,created_at,updated_at)
    VALUES (1,'100分余额卡',1,100,0,1,0,0,1,{$now},{$now})");
$db->exec("INSERT INTO card (id,group_id,secret,value_type,value,meal_id,use_uid,use_time,status,created_at,updated_at)
    VALUES (1,1,'CARD-ONE',1,0,0,0,0,1,{$now},{$now})");

$cardResult = (new RechargeCardService())->redeem($db, 2, 'CARD-ONE', $now);
assertCondition((int) $cardResult['amount'] === 100, 'card amount mismatch');
assertCondition((int) $db->query('SELECT balance FROM user WHERE id=2')->fetchColumn() === 100, 'card balance was not credited');
assertCondition((int) $db->query("SELECT COUNT(*) FROM recharge WHERE uid=2 AND status=2 AND pay_type='card'")->fetchColumn() === 1, 'card recharge record missing');
assertCondition((int) $db->query("SELECT COUNT(*) FROM balance_log WHERE uid=2 AND type='recharge_card'")->fetchColumn() === 1, 'card balance log missing');
assertCondition((int) $db->query("SELECT rebate_balance FROM user WHERE id=1")->fetchColumn() === 5, 'top-up rebate was not credited');

$duplicate = false;
try {
    (new RechargeCardService())->redeem($db, 2, 'CARD-ONE', $now + 1);
} catch (RuntimeException $exception) {
    $duplicate = str_contains($exception->getMessage(), '已使用');
}
assertCondition($duplicate, 'duplicate card redemption was accepted');
assertCondition((int) $db->query('SELECT COUNT(*) FROM recharge WHERE uid=2')->fetchColumn() === 1, 'duplicate card created a recharge');

$db->exec("INSERT INTO card_group (id,name,value_type,value,meal_id,total_limit,time_limit,day_limit,status,created_at,updated_at)
    VALUES (2,'disabled',1,100,0,0,0,0,0,{$now},{$now})");
$db->exec("INSERT INTO card (id,group_id,secret,value_type,value,meal_id,use_uid,use_time,status,created_at,updated_at)
    VALUES (2,2,'CARD-DISABLED',1,0,0,0,0,1,{$now},{$now})");
$disabled = false;
try {
    (new RechargeCardService())->redeem($db, 2, 'CARD-DISABLED', $now + 2);
} catch (RuntimeException $exception) {
    $disabled = str_contains($exception->getMessage(), '停用');
}
assertCondition($disabled, 'disabled card was accepted');

$db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,created_at,updated_at)
    VALUES (1,'ORDER-REBATE','OUT-REBATE',2,200,200,1,{$now},{$now})");
$merchant = $db->query('SELECT * FROM user WHERE id=2')->fetch();
$settlement = new OrderSettlementService();
$settlement->settle($db, $merchant, 'OUT-REBATE', 200, '2.00', 'PAY-1', 'buyer');
$settlement->settle($db, $merchant, 'OUT-REBATE', 200, '2.00', 'PAY-1', 'buyer');
assertCondition((int) $db->query('SELECT balance FROM user WHERE id=2')->fetchColumn() === 300, 'order balance mismatch');
assertCondition((int) $db->query("SELECT COUNT(*) FROM user_rebate_log WHERE origin_type='order_fee'")->fetchColumn() === 1, 'order rebate was duplicated');
assertCondition((int) $db->query('SELECT rebate_balance FROM user WHERE id=1')->fetchColumn() === 25, 'order rebate amount mismatch');

$missingDefault = false;
$db->exec("UPDATE options SET value='default' WHERE `key`='pay_recharge_type'");
try {
    (new RechargeService())->create($db, $merchant, 300, 'alipay', 'https://local.test', $now + 3);
} catch (RuntimeException $exception) {
    $missingDefault = str_contains($exception->getMessage(), '默认支付用户');
}
assertCondition($missingDefault, 'missing default payment configuration was not rejected');
$db->exec("UPDATE options SET value='epay' WHERE `key`='pay_recharge_type'");

$online = (new RechargeService())->create($db, $merchant, 300, 'alipay', 'https://local.test', $now + 4);
assertCondition((int) ($online['code'] ?? 0) === 1, 'epay recharge order was not created');
assertCondition(str_contains((string) $online['payurl'], 'submit.php?'), 'epay payment URL missing');
$recharge = (new RechargeService())->settle($db, (string) $online['system_order_id'], 300, $now + 5);
$rechargeAgain = (new RechargeService())->settle($db, (string) $online['system_order_id'], 300, $now + 6);
assertCondition(($recharge['already_paid'] ?? true) === false, 'first recharge settlement state mismatch');
assertCondition(($rechargeAgain['already_paid'] ?? false) === true, 'recharge callback was not idempotent');
assertCondition((int) $db->query('SELECT balance FROM user WHERE id=2')->fetchColumn() === 600, 'online recharge balance mismatch');
assertCondition((int) $db->query("SELECT COUNT(*) FROM user_rebate_log WHERE origin_type='top_up'")->fetchColumn() === 2, 'top-up rebate count mismatch');

$transfer = (new RebateService())->transfer($db, 1, 10, $now + 7);
assertCondition((int) ($transfer['rebate_balance'] ?? 0) === 30, 'rebate transfer balance mismatch');
assertCondition((int) $db->query('SELECT balance FROM user WHERE id=1')->fetchColumn() === 10, 'rebate transfer did not credit balance');
assertCondition((int) $db->query("SELECT COUNT(*) FROM user_rebate_log WHERE origin_type='transfer' AND balance < 0")->fetchColumn() === 1, 'rebate transfer ledger missing');

$db->exec("UPDATE options SET value='default' WHERE `key`='pay_recharge_type'");
$db->exec("INSERT INTO pay_type (id,value,code,name,label,status) VALUES (1,'alipay','alipay','alipay','支付宝',1)");
$db->exec("INSERT INTO pay_channel (id,code,name,type,status,plugin_name) VALUES (1,'default-demo','支付宝默认支付','alipay',1,'static_code')");
$db->exec("INSERT INTO pay_account (id,uid,pay_type,channel_code,account,account_type,status,sort) VALUES (1,1,'alipay','default-demo','DEFAULT-ACCOUNT','测试',1,1)");
$db->exec("INSERT INTO pay_codes (id,account_id,uid,pay_type,channel_code,code_type,content,qrcode_data,amount,status,sort,options,created_at,updated_at) VALUES (1,1,1,'alipay','default-demo','text','TDEFAULT','TDEFAULT',0,1,1,'{}',{$now},{$now})");
$db->exec("INSERT INTO options (`key`,value) VALUES ('pay_default_uid','1')");
$default = (new RechargeService())->create($db, $merchant, 400, 'alipay', 'https://local.test', $now + 8);
$paymentOrderId = (string) $db->query("SELECT payment_order_id FROM recharge WHERE order_id=" . $db->quote((string) $default['system_order_id']))->fetchColumn();
$official = $db->query('SELECT * FROM user WHERE id=1')->fetch();
(new OrderSettlementService())->settle($db, $official, $paymentOrderId, 400, '4.00', 'PAY-DEFAULT', 'buyer-default');
assertCondition((int) $db->query('SELECT balance FROM user WHERE id=2')->fetchColumn() === 1000, 'default recharge did not credit target merchant');
assertCondition((int) $db->query('SELECT balance FROM user WHERE id=1')->fetchColumn() === 10, 'default recharge incorrectly credited official merchant');

echo "RebateCardRechargeTest: OK\n";
@unlink($path);
