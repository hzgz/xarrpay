<?php

declare(strict_types=1);

namespace XArrPay\Payment;

use PDO;

interface AccountPlugin
{
    /** @return list<string> */
    public function accountActions(): array;

    /**
     * @param PDO $db
     * @param array<string, mixed> $account
     * @param array<string, mixed> $user
     * @param array<string, mixed> $params
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function accountAction(PDO $db, array $account, array $user, string $func, array $params, array $request): array;

    /**
     * @param PDO $db
     * @param array<string, mixed> $account
     * @param array<string, mixed> $user
     * @param array<string, mixed> $params
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function loginQrcode(PDO $db, array $account, array $user, array $params, string $site): array;

    /**
     * @param PDO $db
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $account
     * @param array<string, mixed> $user
     * @param array<string, mixed> $params
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function loginQrcodeCheck(PDO $db, array $ticket, array $account, array $user): array;
}
