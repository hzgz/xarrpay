<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\ReportSigner;

$params = [
    'from' => 'com.example.pay',
    'content' => '微信收款到账 1.23 元',
    'timestamp' => '1727000000',
    'sign' => 'ignored',
    'sign_type' => 'md5',
];
$expected = md5('content=微信收款到账 1.23 元&from=com.example.pay&timestamp=1727000000report-secret');
$actual = ReportSigner::sign($params, 'report-secret', ['from', 'content', 'timestamp', 'sign']);
if ($actual !== $expected) {
    throw new RuntimeException('报告签名字段排序或密钥拼接错误');
}
if (!ReportSigner::valid(array_replace($params, ['sign' => $actual]), 'report-secret', ['from', 'content', 'timestamp', 'sign'])) {
    throw new RuntimeException('正确报告签名没有通过校验');
}
if (ReportSigner::valid(array_replace($params, ['sign' => str_repeat('0', 32)]), 'report-secret', ['from', 'content', 'timestamp', 'sign'])) {
    throw new RuntimeException('错误报告签名错误地通过校验');
}

echo "ReportSignerTest: OK\n";
