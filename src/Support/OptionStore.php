<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class OptionStore
{
    public static function raw(PDO $db, string $key, string $default = ''): string
    {
        $query = $db->prepare('SELECT value FROM `options` WHERE `key` = :key LIMIT 1');
        $query->execute([':key' => $key]);
        $value = $query->fetchColumn();
        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function bool(PDO $db, string $key, bool $default = false): bool
    {
        $value = strtolower(trim(self::raw($db, $key, $default ? '1' : '0')));
        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    public static function int(PDO $db, string $key, int $default = 0): int
    {
        return (int) self::raw($db, $key, (string) $default);
    }

    /** @return list<string> */
    public static function csv(PDO $db, string $key): array
    {
        $value = self::raw($db, $key);
        if ($value === '') {
            return [];
        }

        $items = array_map(static fn (string $item): string => trim($item), explode(',', $value));
        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /** @return array<string, mixed> */
    public static function json(PDO $db, string $key, array $default = []): array
    {
        $decoded = json_decode(self::raw($db, $key), true);
        return is_array($decoded) ? $decoded : $default;
    }
}
