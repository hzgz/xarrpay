<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\PaymentPluginRegistry;
use XArrPay\Support\PaymentPluginCatalog;

$expected = [
    'static_code',
    'jk_wechat_app',
    'yun_qnm_lz',
    'yun_wechat_gj_xd',
    'yun_wechat_yyb_xd',
];

$catalog = PaymentPluginRegistry::catalog();
$names = array_values(array_map(
    static fn (array $item): string => (string) ($item['name'] ?? ''),
    $catalog,
));
sort($names);
$expectedSorted = $expected;
sort($expectedSorted);
if ($names !== $expectedSorted) {
    throw new RuntimeException('builtin payment plugin catalog mismatch');
}

foreach ($catalog as $item) {
    $name = (string) ($item['name'] ?? '');
    if (($item['builtin'] ?? true) !== false || ($item['adapter'] ?? false) !== true) {
        throw new RuntimeException('directory plugin flags mismatch: ' . $name);
    }
    $plugin = PaymentPluginRegistry::resolve($name);
    if ($plugin->name() !== $name || !in_array('create', $plugin->capabilities(), true)) {
        throw new RuntimeException('builtin plugin cannot be resolved: ' . $name);
    }
    if (!PaymentPluginRegistry::resolveAccount($name) instanceof \XArrPay\Payment\AccountPlugin) {
        throw new RuntimeException('plugin account entry cannot be resolved: ' . $name);
    }
    $channelCode = match ($name) {
        'jk_wechat_app' => 'wxpay_app_monitor',
        'yun_qnm_lz' => 'yun_qnm_lz',
        'yun_wechat_gj_xd' => 'yun_wechat_gj_xd',
        'yun_wechat_yyb_xd' => 'yun_wechat_yyb_xd',
        default => 'test',
    };
    $forms = PaymentPluginCatalog::formItems($name, $channelCode, $name === 'static_code' ? 'alipay' : 'wxpay');
    if (!is_array($forms)) {
        throw new RuntimeException('plugin form entry did not return an array: ' . $name);
    }
}

$expectedFormFields = [
    'jk_wechat_app' => ['heartbeat_enable', 'qrcode_type', 'type', 'qrcode', 'qrcode_file'],
    'yun_qnm_lz' => ['gateway', 'login_protocol', 'type', 'qrcode', 'qrcode_file', 'poll_window_seconds', 'proxy_mode', 'proxy', 'proxy_pool_id', 'proxy_pool_item_id', 'bind_token', 'uid', 'custom_session_key', 'tally_openid', 'bound_login_protocol'],
    'yun_wechat_gj_xd' => ['gateway', 'pay_mode', 'sid', 'account_list', 'aid', 'account_type', 'shop_id', 'proxy_mode', 'proxy', 'proxy_pool_id', 'proxy_pool_item_id', 'bind_token', 'guanjia_ref', 'flow_endpoint', 'flow_http_method'],
    'yun_wechat_yyb_xd' => ['gateway', 'pay_mode', 'sid', 'account_list', 'aid', 'account_type', 'shop_id', 'proxy_mode', 'proxy', 'proxy_pool_id', 'proxy_pool_item_id', 'bind_token', 'yyb_ref', 'flow_endpoint', 'flow_http_method'],
];
foreach ($expectedFormFields as $plugin => $expectedNames) {
    $channel = $plugin === 'jk_wechat_app' ? 'wxpay_app_monitor' : $plugin;
    $names = array_map(
        static fn (array $item): string => (string) ($item['name'] ?? ''),
        PaymentPluginCatalog::formItems($plugin, $channel, 'wxpay'),
    );
    if ($names !== $expectedNames) {
        throw new RuntimeException('plugin form fields mismatch: ' . $plugin);
    }
    if (count($names) !== count(array_unique($names))) {
        throw new RuntimeException('plugin form fields contain duplicate names: ' . $plugin);
    }
}

foreach ([
    ['jk_wechat_app', 'wxpay_app_monitor'],
    ['yun_qnm_lz', 'yun_qnm_lz'],
    ['yun_wechat_gj_xd', 'yun_wechat_gj_xd'],
    ['yun_wechat_yyb_xd', 'yun_wechat_yyb_xd'],
] as [$plugin, $channel]) {
    if (!PaymentPluginCatalog::supportsChannel($plugin, $channel, 'wxpay')) {
        throw new RuntimeException('fixed plugin channel definition missing: ' . $plugin);
    }
}
if (!PaymentPluginCatalog::supportsChannel('static_code', 'merchant-defined', 'alipay')) {
    throw new RuntimeException('static_code generic channel contract missing');
}
if (PaymentPluginCatalog::supportsChannel('jk_wechat_app', 'merchant-defined', 'wxpay')) {
    throw new RuntimeException('fixed plugin accepted an undefined channel');
}

if (PaymentPluginRegistry::catalogItem('not-installed') !== []) {
    throw new RuntimeException('unknown plugin unexpectedly exists in catalog');
}
if (PaymentPluginRegistry::hasAdapter('not-installed')) {
    throw new RuntimeException('unknown plugin unexpectedly has adapter');
}

echo "PaymentPluginCatalogTest: OK\n";
