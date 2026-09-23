<?php

declare(strict_types=1);

namespace XArrPay\Support;

final class Config
{
    public static function get(string $key, mixed $default = null): mixed
    {
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
}
