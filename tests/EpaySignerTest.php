<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\EpaySigner;

$params = [
    'pid' => '1000',
    'type' => 'alipay',
    'out_trade_no' => '20240101123456',
    'money' => '100.00',
    'sign_type' => 'MD5',
];

$expected = '45abb1f6213ebbbf3da5a5b15f8e899e';
$actual = EpaySigner::sign($params, 'your_key_here');
if ($actual !== $expected) {
    fwrite(STDERR, "Epay signer mismatch: {$actual}\n");
    exit(1);
}

$params['sign'] = $actual;
if (!EpaySigner::valid($params, 'your_key_here')) {
    fwrite(STDERR, "Epay signer validation failed\n");
    exit(1);
}

echo "EpaySignerTest: OK\n";
