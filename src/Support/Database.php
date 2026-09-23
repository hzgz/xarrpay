<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = Config::get('XARR_DB_DSN');
        if (is_string($dsn) && $dsn !== '') {
            self::$connection = new PDO($dsn, (string) Config::get('XARR_DB_USER', ''), (string) Config::get('XARR_DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return self::$connection;
        }

        $host = (string) Config::get('XARR_DB_HOST', '127.0.0.1');
        $port = (string) Config::get('XARR_DB_PORT', '3306');
        $name = (string) Config::get('XARR_DB_NAME', 'xarrpay');
        $user = (string) Config::get('XARR_DB_USER', 'xarrpay');
        $password = (string) Config::get('XARR_DB_PASSWORD', '');
        $charset = (string) Config::get('XARR_DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        try {
            self::$connection = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new PDOException('Database connection failed: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return self::$connection;
    }

    public static function driver(): string
    {
        $dsn = (string) Config::get('XARR_DB_DSN', '');
        if ($dsn !== '') {
            return strtolower((string) strtok($dsn, ':'));
        }
        return 'mysql';
    }
}
