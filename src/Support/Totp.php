<?php

declare(strict_types=1);

namespace XArrPay\Support;

use InvalidArgumentException;

final class Totp
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10) {
            throw new InvalidArgumentException('动态口令密钥长度不足');
        }
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $key = self::base32Decode($secret);
        $counter = intdiv($timestamp ?? time(), 30);
        $binaryCounter = pack('N2', intdiv($counter, 0x100000000), $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $normalized = strtoupper(trim($secret));
        if (!self::isValidSecret($normalized) || preg_match('/^\d{6}$/', trim($code)) !== 1) {
            return false;
        }
        $now = $timestamp ?? time();
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($normalized, $now + ($offset * 30)), trim($code))) {
                return true;
            }
        }
        return false;
    }

    public static function isValidSecret(string $secret): bool
    {
        return preg_match('/^[A-Z2-7]+={0,6}$/', strtoupper(trim($secret))) === 1
            && strlen(rtrim(strtoupper(trim($secret)), '=')) >= 16;
    }

    public static function uri(string $secret, string $account, string $issuer = ''): string
    {
        $issuer = trim($issuer);
        if ($issuer === '') {
            throw new InvalidArgumentException('动态口令发行者未配置');
        }
        $account = trim($account) !== '' ? trim($account) : 'merchant';
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode(strtoupper($secret));
    }

    private static function base32Encode(string $value): string
    {
        $buffer = 0;
        $bits = 0;
        $result = '';
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $buffer = ($buffer << 8) | ord($value[$index]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= self::BASE32[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $result .= self::BASE32[($buffer << (5 - $bits)) & 31];
        }
        return $result;
    }

    private static function base32Decode(string $value): string
    {
        $value = strtoupper(str_replace('=', '', trim($value)));
        if ($value === '' || preg_match('/^[A-Z2-7]+$/', $value) !== 1) {
            throw new InvalidArgumentException('动态口令密钥格式不正确');
        }
        $buffer = 0;
        $bits = 0;
        $result = '';
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $position = strpos(self::BASE32, $value[$index]);
            if ($position === false) {
                throw new InvalidArgumentException('动态口令密钥格式不正确');
            }
            $buffer = ($buffer << 5) | $position;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $result .= chr(($buffer >> $bits) & 0xff);
            }
        }
        return $result;
    }
}
