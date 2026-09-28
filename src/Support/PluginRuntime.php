<?php

declare(strict_types=1);

namespace XArrPay\Support;

final class PluginRuntime
{
    public static function directory(): string
    {
        return rtrim((string) Config::get(
            'XARR_PLUGIN_DIR',
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'plugins'
        ), "\\/");
    }

    /** @return list<array<string, mixed>> */
    public static function manifests(): array
    {
        $root = self::paymentDirectory();
        if (!is_dir($root)) {
            return [];
        }

        $result = [];
        foreach (self::scan() as $item) {
            if (($item['valid'] ?? false) !== true) {
                continue;
            }
            $result[] = $item['manifest'];
        }

        usort($result, static fn (array $left, array $right): int =>
            ((string) ($left['name'] ?? '') <=> (string) ($right['name'] ?? '')));
        return $result;
    }

    public static function paymentDirectory(): string
    {
        return self::directory() . DIRECTORY_SEPARATOR . 'pay';
    }

    /** @return array<string, mixed>|null */
    public static function manifest(string $name): ?array
    {
        $name = trim($name);
        foreach (self::manifests() as $manifest) {
            if ((string) ($manifest['name'] ?? '') === $name) {
                return $manifest;
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function diagnostics(): array
    {
        $result = [];
        foreach (self::scan() as $item) {
            $manifest = $item['manifest'];
            unset($manifest['_path']);
            $result[] = [
                'name' => (string) ($manifest['name'] ?? $item['directory_name'] ?? ''),
                'directory' => (string) ($item['directory_name'] ?? ''),
                'valid' => (bool) ($item['valid'] ?? false),
                'errors' => array_values($item['errors'] ?? []),
                'manifest' => $manifest,
            ];
        }
        return $result;
    }

    public static function entryPath(array $manifest, string $key, string $default): string
    {
        $root = realpath((string) ($manifest['_path'] ?? ''));
        if ($root === false) {
            throw new \RuntimeException('插件目录不存在');
        }
        $entry = trim((string) ($manifest[$key] ?? $default));
        if ($entry === '' || str_contains($entry, "\0") || preg_match('#^(?:[a-zA-Z]:)?[\\\\/]#', $entry) === 1) {
            throw new \RuntimeException('插件入口配置无效');
        }
        $path = realpath($root . DIRECTORY_SEPARATOR . $entry);
        if ($path === false || dirname($path) !== $root || !is_file($path)) {
            throw new \RuntimeException('插件入口文件不存在');
        }
        return $path;
    }

    public static function setState(string $name, string $action): void
    {
        $path = self::pluginPath($name);
        if (!in_array($action, ['enable', 'disable', 'start', 'stop'], true)) {
            throw new \InvalidArgumentException('插件操作无效');
        }
        $state = self::state($path);
        if (in_array($action, ['enable', 'disable'], true)) {
            $state['enabled'] = $action === 'enable';
        }
        if (in_array($action, ['start', 'stop'], true)) {
            $state['running'] = $action === 'start';
        }
        self::writeState($path, $state);
        PaymentPluginRegistry::clearCache();
    }

    public static function remove(string $name): void
    {
        $path = self::pluginPath($name);
        if (!is_dir($path)) {
            throw new \RuntimeException('插件不存在');
        }
        self::removeDirectory($path);
        PaymentPluginRegistry::clearCache();
    }

    /** @return array<string, mixed> */
    private static function state(string $path): array
    {
        $file = $path . DIRECTORY_SEPARATOR . '.runtime.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('插件运行态文件格式无效');
        }
        foreach (['enabled', 'running'] as $key) {
            if (array_key_exists($key, $data) && !is_bool($data[$key])) {
                throw new \RuntimeException('插件运行态字段无效: ' . $key);
            }
        }
        return $data;
    }

    /** @param array<string, mixed> $state */
    private static function writeState(string $path, array $state): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('插件运行态目录创建失败');
        }
        $written = file_put_contents(
            $path . DIRECTORY_SEPARATOR . '.runtime.json',
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
        if ($written === false) {
            throw new \RuntimeException('插件运行态写入失败');
        }
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
        if ($path === false || dirname($path) !== $root || !is_dir($path)) {
            throw new \RuntimeException('插件不存在');
        }
        return $path;
    }

    /** @return list<array{valid:bool,manifest:array<string,mixed>,directory_name:string,errors:list<string>}> */
    private static function scan(): array
    {
        $root = self::paymentDirectory();
        if (!is_dir($root)) {
            return [];
        }

        $result = [];
        foreach (glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $path) {
            $directoryName = basename($path);
            $manifestPath = $path . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifest = [];
            $errors = [];
            if (!is_file($manifestPath)) {
                $errors[] = '缺少 manifest.json';
            } else {
                $decoded = json_decode((string) file_get_contents($manifestPath), true);
                if (!is_array($decoded)) {
                    $errors[] = 'manifest.json 格式无效';
                } else {
                    $manifest = $decoded;
                }
            }

            $name = trim((string) ($manifest['name'] ?? ''));
            if ($name === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $name) !== 1) {
                $errors[] = '插件名称无效';
            } elseif ($name !== $directoryName) {
                $errors[] = '插件名称与目录名不一致';
            }
            if (!array_key_exists('type', $manifest) || !is_string($manifest['type']) || trim($manifest['type']) === '') {
                $errors[] = '插件类型未配置';
            }
            foreach (['enabled', 'running'] as $key) {
                if (!array_key_exists($key, $manifest) || !is_bool($manifest[$key])) {
                    $errors[] = '清单字段必须为布尔值: ' . $key;
                }
            }
            if (($manifest['type'] ?? '') === 'pay') {
                if (!isset($manifest['pay_types']) || !is_array($manifest['pay_types']) || $manifest['pay_types'] === []) {
                    $errors[] = '支付插件支持的支付方式未配置';
                } else {
                    foreach ($manifest['pay_types'] as $payType) {
                        if (!is_string($payType) || trim($payType) === '') {
                            $errors[] = '支付插件支持的支付方式无效';
                            break;
                        }
                    }
                }
                foreach (['adapter_entry', 'account_entry', 'form_entry'] as $key) {
                    if (!isset($manifest[$key]) || !is_string($manifest[$key]) || trim($manifest[$key]) === '') {
                        $errors[] = '支付插件入口未配置: ' . $key;
                        continue;
                    }
                    try {
                        self::entryPath(['_path' => $path] + $manifest, $key, '');
                    } catch (\Throwable $exception) {
                        $errors[] = $key . '：' . $exception->getMessage();
                    }
                }
            }

            $manifest['_path'] = $path;
            try {
                $state = self::state($path);
                $manifest['enabled'] = array_key_exists('enabled', $state)
                    ? $state['enabled']
                    : ($manifest['enabled'] ?? false);
                $manifest['running'] = array_key_exists('running', $state)
                    ? $state['running']
                    : ($manifest['running'] ?? false);
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
                $manifest['enabled'] = false;
                $manifest['running'] = false;
            }

            $result[] = [
                'valid' => $errors === [],
                'manifest' => $manifest,
                'directory_name' => $directoryName,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        usort($result, static fn (array $left, array $right): int =>
            ((string) ($left['directory_name'] ?? '') <=> (string) ($right['directory_name'] ?? '')));
        return $result;
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
