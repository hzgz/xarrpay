<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Payment\StaticCodePaymentPlugin;
use XArrPay\Support\PaymentAccountAllocator;
use XArrPay\Support\PaymentPluginRegistry;

$path = tempnam(sys_get_temp_dir(), 'xarr-payment-');
if ($path === false) {
    throw new RuntimeException('cannot create test database');
}
putenv('XARR_DB_DSN=sqlite:' . $path);
$db = XArrPay\Support\Database::connection();
$db->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
$now = time();
$db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at) VALUES (1,'merchant','', '测试商户','test-key',1,0,{$now})");
$db->exec("INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at) VALUES (2,'merchant2','', '测试商户2','test-key-2',1,0,{$now})");
$db->exec("INSERT INTO pay_type (id,value,code,name,label,status) VALUES (1,'alipay','alipay','alipay','支付宝',1)");
$db->exec("INSERT INTO pay_channel (id,code,name,type,status,plugin_name) VALUES (1,'static','支付宝静态通道','alipay',1,'static_code')");
$db->exec("INSERT INTO pay_channel (id,code,name,type,status,plugin_name) VALUES (2,'missing','缺失插件通道','missing',1,'not-installed')");
$db->exec("INSERT INTO pay_account (id,uid,pay_type,channel_code,account,account_type,status,sort,min_amount,max_amount,day_amount_limit) VALUES (1,1,'alipay','static','A-1','码',1,50,100,500,600)");
$db->exec("INSERT INTO pay_account (id,uid,pay_type,channel_code,account,account_type,status,sort,min_amount,max_amount,day_amount_limit) VALUES (2,1,'alipay','static','A-2','码',1,10,0,0,0)");
$db->exec("INSERT INTO pay_account (id,uid,pay_type,channel_code,account,account_type,status,sort) VALUES (3,1,'alipay','missing','A-3','码',1,1)");
$db->exec("INSERT INTO pay_account (id,uid,pay_type,channel_code,account,account_type,status,sort) VALUES (4,2,'alipay','static','B-1','码',1,1)");
$db->exec("INSERT INTO pay_codes (account_id,uid,pay_type,channel_code,content,status,sort,created_at,updated_at) VALUES (1,1,'alipay','static','CODE-1',1,50,{$now},{$now})");
$db->exec("INSERT INTO pay_codes (account_id,uid,pay_type,channel_code,content,status,sort,created_at,updated_at) VALUES (2,1,'alipay','static','CODE-2',1,10,{$now},{$now})");
$db->exec("INSERT INTO pay_codes (account_id,uid,pay_type,channel_code,content,status,sort,created_at,updated_at) VALUES (4,2,'alipay','static','CODE-4',1,1,{$now},{$now})");
$db->exec("INSERT INTO polling_rule (id,uid,name,pay_type,channel_code,account_ids,status,sort,created_at,updated_at) VALUES (1,0,'全局规则','alipay','static','[1,2]',1,1,{$now},{$now})");
$db->exec("INSERT INTO polling_rule (id,uid,name,pay_type,channel_code,account_ids,status,sort,created_at,updated_at) VALUES (2,1,'商户 1 规则','alipay','static','[2,1]',1,1,{$now},{$now})");
$db->exec("INSERT INTO polling_rule (id,uid,name,pay_type,channel_code,account_ids,status,sort,created_at,updated_at) VALUES (3,2,'商户 2 规则','alipay','static','[4]',1,1,{$now},{$now})");
$db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,expire_time,pay_type,created_at,updated_at) VALUES (1,'T-1','O-1',1,200,200,1," . ($now + 900) . ",'alipay',{$now},{$now})");
$db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,expire_time,pay_type,created_at,updated_at) VALUES (3,'T-2','O-2',2,200,200,1," . ($now + 900) . ",'alipay',{$now},{$now})");

function checkTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$order = $db->query('SELECT * FROM "order" WHERE id = 1')->fetch();
$allocation = (new PaymentAccountAllocator())->allocate($db, $order, 'alipay');
checkTrue((int) $allocation['account']['id'] === 2, 'merchant polling rule must override global account order');
checkTrue($allocation['plugin'] instanceof StaticCodePaymentPlugin, 'static plugin must resolve');
$payment = $allocation['plugin']->create($order, $allocation['channel'], $allocation['account']);
checkTrue((string) $payment['content'] === 'CODE-2', 'payment code content mismatch');

$otherMerchantOrder = $db->query('SELECT * FROM "order" WHERE id = 3')->fetch();
$otherMerchantAllocation = (new PaymentAccountAllocator())->allocate($db, $otherMerchantOrder, 'alipay');
checkTrue((int) $otherMerchantAllocation['account']['id'] === 4, 'one merchant polling rule must not affect another merchant');

$largeOrder = $order;
$largeOrder['amount'] = 700;
$db->exec('UPDATE polling_rule SET status=0 WHERE id=2');
$largeAllocation = (new PaymentAccountAllocator())->allocate($db, $largeOrder, 'alipay');
checkTrue((int) $largeAllocation['account']['id'] === 2, 'amount limit must skip account 1');

$db->exec("UPDATE pay_account SET day_amount_limit = 100 WHERE id = 1");
$db->exec("INSERT INTO \"order\" (id,order_id,out_order_id,uid,amount,trade_amount,status,pay_time,expire_time,pay_type,account_id,created_at,updated_at) VALUES (2,'T-PAID','O-PAID',1,100,100,2,{$now}," . ($now + 900) . ",'alipay',1,{$now},{$now})");
$limited = (new PaymentAccountAllocator())->allocate($db, $order, 'alipay');
checkTrue((int) $limited['account']['id'] === 2, 'daily limit must skip exhausted account');

checkTrue(PaymentPluginRegistry::available('static_code'), 'static plugin must be available');
checkTrue(!PaymentPluginRegistry::available('not-installed'), 'missing plugin must be unavailable');

echo "PaymentFlowTest: OK\n";
@unlink($path);
