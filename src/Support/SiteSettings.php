<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class SiteSettings
{
    public static function title(PDO $db): string
    {
        return trim(OptionStore::raw($db, 'web_title'));
    }

    public static function indexTitle(PDO $db): string
    {
        return trim(OptionStore::raw($db, 'web_index_title'));
    }
}
