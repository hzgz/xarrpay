<?php

declare(strict_types=1);

namespace XArrPay\Support;

final class Config
{
    private static bool $loadedEnv = false;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::loadEnv();
        $value = getenv($key);
        return $value === false || $value === '' ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function loadEnv(): void
    {
        if (self::$loadedEnv) {
            return;
        }
        self::$loadedEnv = true;

        $path = dirname(__DIR__, 2) . '/.env';
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            if ($key === '' || preg_match('/^[A-Z0-9_]+$/', $key) !== 1) {
                continue;
            }
            if (getenv($key) !== false && getenv($key) !== '') {
                continue;
            }

            $value = trim(substr($line, $pos + 1));
            if (strlen($value) >= 2) {
                $quote = $value[0];
                if (($quote === '"' || $quote === "'") && $value[strlen($value) - 1] === $quote) {
                    $value = substr($value, 1, -1);
                    if ($quote === '"') {
                        $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
                    }
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
