<?php

declare(strict_types=1);

namespace XArrPay\Payment;

use PDO;
use XArrPay\Support\PluginAccountService;

final class YunQnmLzAccountPlugin implements AccountPlugin
{
    public function accountActions(): array
    {
        return [];
    }

    public function accountAction(PDO $db, array $account, array $user, string $func, array $params, array $request): array
    {
        return PluginAccountService::unsupportedAction($func);
    }

    public function loginQrcode(PDO $db, array $account, array $user, array $params, string $site): array
    {
        return PluginAccountService::qnmLoginAdapter($db, $account, $user, $params, $site);
    }

    public function loginQrcodeCheck(PDO $db, array $ticket, array $account, array $user): array
    {
        return PluginAccountService::qnmCheckAdapter($db, $ticket, $account, $user);
    }
}
