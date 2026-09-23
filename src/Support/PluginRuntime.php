<?php

declare(strict_types=1);

namespace XArrPay\Support;

final class PluginRuntime
{
    public static function directory(): string
    {
        return rtrim((string) Config::get('XARR_PLUGIN_DIR', '/www/wwwroot/xarr/plugins'), "\\/");
    }

    /** @return list<array<string, mixed>> */
    public static function manifests(): array
    {
        $root = self::directory() . DIRECTORY_SEPARATOR . 'pay';
        if (!is_dir($root)) {
            return [];
        }

        $result = [];
        foreach (glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $data['_path'] = dirname($file);
                $state = self::state(dirname($file));
                $data['enabled'] = $state['enabled'] ?? ($data['enabled'] ?? true);
                $data['running'] = $state['running'] ?? ($data['running'] ?? false);
                $result[] = $data;
            }
        }

        return $result;
    }

    public static function setState(string $name, string $action): void
    {
        $path = self::pluginPath($name);
        $state = self::state($path);
        if (in_array($action, ['enable', 'disable'], true)) {
            $state['enabled'] = $action === 'enable';
        }
        if (in_array($action, ['start', 'stop'], true)) {
            $state['running'] = $action === 'start';
        }
        self::writeState($path, $state);
    }

    public static function remove(string $name): void
    {
        $path = self::pluginPath($name);
        if (!is_dir($path)) {
            throw new \RuntimeException('插件不存在');
        }
        self::removeDirectory($path);
    }

    /** @return array<string, mixed> */
    private static function state(string $path): array
    {
        $file = $path . DIRECTORY_SEPARATOR . '.runtime.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $state */
    private static function writeState(string $path, array $state): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('插件运行态目录创建失败');
        }
        file_put_contents($path . DIRECTORY_SEPARATOR . '.runtime.json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private static function pluginPath(string $name): string
    {
        if ($name === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('插件名称无效');
        }
        $root = realpath(self::directory() . DIRECTORY_SEPARATOR . 'pay');
        if ($root === false) {
            throw new \RuntimeException('插件目录不存在');
        }
        $path = realpath($root . DIRECTORY_SEPARATOR . $name);
        if ($path === false || dirname($path) !== $root) {
            throw new \RuntimeException('插件不存在');
        }
        return $path;
    }

    private static function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) && !is_link($child) ? self::removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
