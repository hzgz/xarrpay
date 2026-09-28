<?php

declare(strict_types=1);

namespace XArrPay\Payment;

final class PluginOptions
{
    /** @param array<string, mixed> $account */
    public static function fromAccount(array $account): array
    {
        $options = self::decode($account['options'] ?? []);
        foreach (['qrcode', 'qrcode_file', 'type', 'qrcode_type', 'pay_mode', 'sid', 'aid', 'account_type', 'shop_id'] as $key) {
            if (!array_key_exists($key, $options) && isset($account[$key]) && $account[$key] !== '') {
                $options[$key] = $account[$key];
            }
        }
        return $options;
    }

    /** @return array<string, mixed> */
    public static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $options */
    public static function requireString(array $options, string $key, string $message): string
    {
        $value = trim((string) ($options[$key] ?? ''));
        if ($value === '') {
            throw new \RuntimeException($message);
        }
        return $value;
    }

    public static function imageData(string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, 'data:image/')) {
            return $value;
        }
        if (is_file($value)) {
            $mime = (string) (mime_content_type($value) ?: 'image/png');
            $encoded = base64_encode((string) file_get_contents($value));
            return $encoded === '' ? '' : 'data:' . $mime . ';base64,' . $encoded;
        }
        return $value;
    }
}
