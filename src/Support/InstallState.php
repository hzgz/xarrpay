<?php

declare(strict_types=1);

namespace XArrPay\Support;

use RuntimeException;

final class InstallState
{
    private const LOCK_VERSION = 1;

    public static function isLocked(): bool
    {
        $path = self::lockPath();
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data)
            && (int) ($data['version'] ?? 0) === self::LOCK_VERSION
            && is_string($data['installed_at'] ?? null)
            && $data['installed_at'] !== ''
            && is_string($data['admin'] ?? null)
            && $data['admin'] !== '';
    }

    /** @return array<string, mixed> */
    public static function data(): array
    {
        if (!self::isLocked()) {
            return [];
        }
        $data = json_decode((string) file_get_contents(self::lockPath()), true);
        return is_array($data) ? $data : [];
    }

    public static function lockPath(): string
    {
        return (string) Config::get(
            'XARR_INSTALL_LOCK_FILE',
            dirname(__DIR__, 2) . '/var/install.lock',
        );
    }

    public static function isInstallPagePath(string $path): bool
    {
        return $path === '/install'
            || $path === '/install/'
            || str_starts_with($path, '/install/');
    }

    public static function isInstallApiPath(string $path): bool
    {
        return $path === '/api/install'
            || str_starts_with($path, '/api/install/');
    }

    public static function isInstallPath(string $path): bool
    {
        return self::isInstallPagePath($path) || self::isInstallApiPath($path);
    }

    public static function redirectToInstall(): never
    {
        header('Location: /install', true, 302);
        exit;
    }

    public static function enforce(string $path): void
    {
        if (!self::isLocked() && !self::isInstallPath($path)) {
            self::redirectToInstall();
        }
        if (self::isLocked() && self::isInstallPagePath($path)) {
            header('Location: /', true, 302);
            exit;
        }
    }

    /** @param array<string, mixed> $data */
    public static function createLock(array $data): void
    {
        if (self::isLocked()) {
            throw new RuntimeException('安装锁已存在，禁止重复写入');
        }

        $payload = [
            'version' => self::LOCK_VERSION,
            'installed_at' => gmdate('c'),
            'admin' => (string) ($data['admin'] ?? 'admin'),
            'username' => (string) ($data['username'] ?? ''),
        ];
        if ($payload['admin'] === '' || $payload['username'] === '') {
            throw new RuntimeException('安装锁信息不完整');
        }

        $path = self::lockPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('安装锁目录创建失败');
        }
        if (is_file($path)) {
            throw new RuntimeException('安装锁文件已存在，禁止覆盖');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new RuntimeException('安装锁内容生成失败');
        }

        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('安装锁写入失败');
        }
        @chmod($temporary, 0444);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('安装锁落盘失败');
        }
        @chmod($path, 0444);
    }
}
