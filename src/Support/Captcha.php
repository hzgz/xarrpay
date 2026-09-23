<?php

declare(strict_types=1);

namespace XArrPay\Support;

final class Captcha
{
    private const SESSION_KEY = 'xarr_numeric_captcha';
    private const WIDTH = 360;
    private const HEIGHT = 144;

    public static function fontPath(): string
    {
        return (string) (getenv('XARR_CAPTCHA_FONT') ?: 'C:\\Windows\\Fonts\\arial.ttf');
    }

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagettftext')
            && is_file(self::fontPath());
    }

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('xarr_php');
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }
    }

    /** @return array{captcha_id: string, captcha_base64: string} */
    public static function issue(): array
    {
        self::startSession();

        $code = '';
        $alphabet = '0123456789';
        for ($index = 0; $index < 4; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $id = rtrim(strtr(base64_encode(random_bytes(14)), '+/', '-_'), '=');
        $_SESSION[self::SESSION_KEY] = [
            'id' => $id,
            'code' => $code,
            'expires_at' => time() + 300,
        ];

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $background = imagecolorallocate($image, 247, 249, 252);
        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $background);

        for ($index = 0; $index < 12; $index++) {
            $line = imagecolorallocate($image, random_int(150, 220), random_int(150, 220), random_int(150, 220));
            imageline($image, random_int(0, self::WIDTH - 1), random_int(0, self::HEIGHT - 1), random_int(0, self::WIDTH - 1), random_int(0, self::HEIGHT - 1), $line);
        }

        for ($index = 0; $index < 70; $index++) {
            $dot = imagecolorallocate($image, random_int(170, 225), random_int(170, 225), random_int(170, 225));
            imagesetpixel($image, random_int(0, self::WIDTH - 1), random_int(0, self::HEIGHT - 1), $dot);
        }

        $text = imagecolorallocate($image, 25, 43, 67);
        $fontSize = 74;
        for ($index = 0; $index < strlen($code); $index++) {
            $x = 32 + $index * 82;
            $y = random_int(98, 112);
            imagettftext($image, $fontSize, random_int(-4, 4), $x, $y, $text, self::fontPath(), $code[$index]);
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return [
            'captcha_id' => $id,
            'captcha_base64' => 'data:image/png;base64,' . base64_encode((string) $png),
        ];
    }

    public static function verify(string $id, string $code): bool
    {
        self::startSession();
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        $_SESSION[self::SESSION_KEY] = null;

        if (!is_array($stored) || $id === '' || $code === '') {
            return false;
        }
        if (!hash_equals((string) ($stored['id'] ?? ''), $id)) {
            return false;
        }
        if ((int) ($stored['expires_at'] ?? 0) < time()) {
            return false;
        }

        if (preg_match('/^\d{4}$/', $code) !== 1) {
            return false;
        }

        return hash_equals((string) ($stored['code'] ?? ''), $code);
    }
}
