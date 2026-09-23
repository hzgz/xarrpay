<?php

declare(strict_types=1);

namespace XArrPay\Support;

final class EpaySigner
{
    /** Compatible with the existing Lua epay plugin. */
    public static function sign(array $params, string $key): string
    {
        $filtered = [];
        foreach ($params as $name => $value) {
            if ($name === 'sign' || $name === 'sign_type' || $value === '' || $value === null) {
                continue;
            }
            $filtered[(string) $name] = (string) $value;
        }

        ksort($filtered, SORT_STRING);
        $pairs = [];
        foreach ($filtered as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return md5(implode('&', $pairs) . $key);
    }

    public static function valid(array $params, string $key): bool
    {
        return hash_equals(self::sign($params, $key), strtolower((string) ($params['sign'] ?? '')));
    }
}
