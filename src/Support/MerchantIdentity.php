<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class MerchantIdentity
{
    public const FIRST_ID = 10000;

    public static function appSecret(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function nextId(PDO $db): int
    {
        $maxId = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM `user`')->fetchColumn();
        return max(self::FIRST_ID, $maxId + 1);
    }
}
