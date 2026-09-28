<?php

declare(strict_types=1);

namespace XArrPay\Support;

use XArrPay\Http\Request;

final class RuntimeLogger
{
    public static function recordAdminRequest(Request $request): void
    {
        if (!str_starts_with($request->path(), '/api/admin/')) {
            return;
        }

        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var';
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            return;
        }

        $line = json_encode([
            'time' => date('c'),
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }

        $path = $root . DIRECTORY_SEPARATOR . 'php-' . date('Ymd') . '.log';
        if (!is_writable($root) || (is_file($path) && !is_writable($path))) {
            return;
        }

        // 日志不能影响 API 响应；权限在部署阶段修正，写入失败时静默跳过。
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
