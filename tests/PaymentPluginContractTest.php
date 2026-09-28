<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\PaymentPluginRegistry;

foreach (['jk_wechat_app', 'yun_qnm_lz', 'yun_wechat_gj_xd', 'yun_wechat_yyb_xd'] as $name) {
    $plugin = PaymentPluginRegistry::resolve($name);
    if ($plugin->name() !== $name || !in_array('create', $plugin->capabilities(), true)) {
        throw new RuntimeException('plugin contract mismatch: ' . $name);
    }
}

$jk = PaymentPluginRegistry::resolve('jk_wechat_app')->create(
    ['order_id' => 'T-JK', 'amount' => 100],
    ['code' => 'wxpay_app_monitor'],
    ['options' => json_encode(['type' => 'url', 'qrcode' => 'weixin://test'], JSON_THROW_ON_ERROR)]
);
if (($jk['qrcode'] ?? '') !== 'weixin://test' || ($jk['type'] ?? '') !== 'qrcode') {
    throw new RuntimeException('jk_wechat_app create contract mismatch');
}

$qnm = PaymentPluginRegistry::resolve('yun_qnm_lz')->create(
    ['order_id' => 'T-QNM', 'amount' => 100],
    ['code' => 'yun_qnm_lz'],
    ['options' => json_encode(['type' => 'qrcode', 'qrcode' => 'https://pay.test/qrcode'], JSON_THROW_ON_ERROR)]
);
if (($qnm['qrcode'] ?? '') !== 'https://pay.test/qrcode') {
    throw new RuntimeException('yun_qnm_lz create contract mismatch');
}

$failed = false;
try {
    PaymentPluginRegistry::resolve('yun_wechat_gj_xd')->create(
        ['order_id' => 'T-GJ', 'amount' => 100],
        ['code' => 'yun_wechat_gj_xd'],
        ['options' => '{}']
    );
} catch (RuntimeException $exception) {
    $failed = str_contains($exception->getMessage(), 'SID');
}
if (!$failed) {
    throw new RuntimeException('cloud plugin missing SID was not rejected');
}

echo "PaymentPluginContractTest: OK\n";
