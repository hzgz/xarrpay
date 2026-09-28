<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class PluginAccountStore
{
    /** @return array<string, mixed> */
    public static function options(array $account): array
    {
        $value = $account['options'] ?? [];
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $options */
    public static function saveOptions(PDO $db, int $accountId, array $options): void
    {
        $query = $db->prepare('UPDATE pay_account SET options = :options, updated_at = :updated_at WHERE id = :id');
        $query->execute([
            ':options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':updated_at' => time(),
            ':id' => $accountId,
        ]);
    }

    public static function markOnline(PDO $db, int $accountId): void
    {
        $now = time();
        $query = $db->prepare('UPDATE pay_account SET online = 1, online_start = CASE WHEN online = 1 AND online_start > 0 THEN online_start ELSE :now END, online_end = 0, updated_at = :now_update WHERE id = :id');
        $query->execute([':now' => $now, ':now_update' => $now, ':id' => $accountId]);
    }

    public static function markOffline(PDO $db, int $accountId): void
    {
        $now = time();
        $query = $db->prepare('UPDATE pay_account SET online = 0, online_end = :now, updated_at = :now_update WHERE id = :id');
        $query->execute([':now' => $now, ':now_update' => $now, ':id' => $accountId]);
    }
}
