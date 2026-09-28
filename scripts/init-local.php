<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Config;

$dsn = (string) Config::get('XARR_DB_DSN', 'sqlite:' . dirname(__DIR__) . '/var/local.sqlite');
if (!str_starts_with($dsn, 'sqlite:')) {
    fwrite(STDERR, "本地建库只接受 SQLite DSN。\n");
    exit(1);
}

$path = substr($dsn, 7);
if ($path !== ':memory:') {
    $absolutePath = realpath(dirname($path));
    $workspace = realpath(dirname(__DIR__));
    if ($absolutePath === false || $workspace === false || !str_starts_with(
        strtolower($absolutePath . DIRECTORY_SEPARATOR),
        strtolower($workspace . DIRECTORY_SEPARATOR)
    )) {
        fwrite(STDERR, "SQLite 数据库路径必须位于项目目录内。\n");
        exit(1);
    }
    if (file_exists($path)) {
        fwrite(STDERR, "数据库文件已存在；初始化器只创建全新空库，不执行迁移或覆盖。\n");
        exit(1);
    }
    if (!is_dir($absolutePath) && !mkdir($absolutePath, 0775, true) && !is_dir($absolutePath)) {
        fwrite(STDERR, "SQLite 数据库目录创建失败。\n");
        exit(1);
    }
}

try {
    $db = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $schemaPath = dirname(__DIR__) . '/database/schema.sqlite.sql';
    $schema = file_get_contents($schemaPath);
    if (!is_string($schema) || $schema === '') {
        throw new RuntimeException('SQLite schema file is missing or empty.');
    }
    $db->exec($schema);
} catch (Throwable $exception) {
    fwrite(STDERR, 'SQLite schema initialization failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "空 SQLite schema 已创建: {$path}\n");
