<?php

declare(strict_types=1);

namespace XArrPay\Payment;

interface PaymentPlugin
{
    public function name(): string;

    /** @return list<string> */
    public function capabilities(): array;

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $channel
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function create(array $order, array $channel, array $account): array;

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $channel
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function query(array $order, array $channel, array $account): array;
}
