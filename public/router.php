<?php

declare(strict_types=1);

// PHP's built-in server uses this file only when a requested static path does
// not exist. Existing UI assets remain ordinary files and are served directly.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mappedStaticRoots = [
    '/static/' => __DIR__ . '/user/static/',
    '/assets/' => __DIR__ . '/cashier/assets/',
];
foreach ($mappedStaticRoots as $prefix => $root) {
    if (str_starts_with($path, $prefix)) {
        $relative = substr($path, strlen($prefix));
        $mappedFile = $root . $relative;
        if (is_file($mappedFile)) {
            $extension = strtolower(pathinfo($mappedFile, PATHINFO_EXTENSION));
            $mimeTypes = [
                'css' => 'text/css; charset=utf-8',
                'js' => 'application/javascript; charset=utf-8',
                'mjs' => 'application/javascript; charset=utf-8',
                'json' => 'application/json; charset=utf-8',
                'html' => 'text/html; charset=utf-8',
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'ico' => 'image/x-icon',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf',
            ];
            $mime = $mimeTypes[$extension] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            readfile($mappedFile);
            exit;
        }
    }
}
if ($path === '/favicon.ico') {
    $favicon = __DIR__ . '/admin/static/ico/favicon-6d3c559c.ico';
    if (is_file($favicon)) {
        header('Content-Type: image/x-icon');
        readfile($favicon);
        exit;
    }
}
$candidate = __DIR__ . $path;
if ($path !== '/' && is_file($candidate)) {
    return false;
}

$pages = [
    '/' => __DIR__ . '/index/index.html',
    '/login' => __DIR__ . '/user/index.html',
    '/admin' => __DIR__ . '/admin/index.html',
    '/admin/login' => __DIR__ . '/admin/index.html',
    '/user' => __DIR__ . '/user/index.html',
    '/pay' => __DIR__ . '/pay/index.html',
    '/pay/status' => __DIR__ . '/pay/status.html',
    '/pay/separate' => __DIR__ . '/pay/separate.html',
    '/cashier' => __DIR__ . '/cashier/index.html',
    '/install' => __DIR__ . '/install/index.html',
];
$page = $pages[rtrim($path, '/') ?: '/'] ?? null;
if ($page === null && preg_match('#^/pay/separate/[^/]+$#', $path) === 1) {
    $page = __DIR__ . '/pay/separate.html';
}
if ($page === null && preg_match('#^/pay/status/[^/]+$#', $path) === 1) {
    $page = __DIR__ . '/pay/status.html';
}
if ($page === null && preg_match('#^/pay/[^/]+$#', $path) === 1) {
    $page = __DIR__ . '/pay/index.html';
}
if ($page === null && preg_match('#^/cashier/[^/]+$#', $path) === 1) {
    $page = __DIR__ . '/cashier/index.html';
}
if ($page !== null && is_file($page)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($page);
    exit;
}

require __DIR__ . '/index.php';
