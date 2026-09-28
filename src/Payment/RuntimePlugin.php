<?php

declare(strict_types=1);

namespace XArrPay\Payment;

use PDO;

interface RuntimePlugin
{
    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function heartbeat(PDO $db, array $account, int $now): array;

    /**
     * @param array<string, mixed> $account
     * @return array{flows:list<array<string,mixed>>,message:string}
     */
    public function cron(PDO $db, array $account, int $now): array;

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function parseMessage(array $message, array $account): array;
}
