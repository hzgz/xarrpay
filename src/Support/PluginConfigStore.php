<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class PluginConfigStore
{
    /** @return list<array<string, mixed>> */
    public static function definitions(string $pluginName): array
    {
        $manifest = PluginRuntime::manifest(trim($pluginName));
        if ($manifest === null) {
            throw new \RuntimeException('支付插件未安装: ' . trim($pluginName));
        }

        $config = $manifest['config'] ?? [];
        if (!is_array($config)) {
            throw new \RuntimeException('支付插件配置定义无效: ' . trim($pluginName));
        }

        $items = [];
        $keys = [];
        foreach ($config as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('支付插件配置项无效: ' . trim($pluginName));
            }
            $key = trim((string) ($item['key'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            if ($key === '' || $title === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $key) !== 1) {
                throw new \RuntimeException('支付插件配置项缺少有效 key 或 title: ' . trim($pluginName));
            }
            if (isset($keys[$key])) {
                throw new \RuntimeException('支付插件配置项 key 重复: ' . $key);
            }
            $keys[$key] = true;
            $items[] = [
                'title' => $title,
                'key' => $key,
                'default' => (string) ($item['default'] ?? ''),
            ];
        }
        return $items;
    }

    /** @return list<array<string, mixed>> */
    public static function read(PDO $db, string $pluginName): array
    {
        $pluginName = trim($pluginName);
        $items = self::definitions($pluginName);
        $stored = self::stored($db, $pluginName);
        foreach ($items as &$item) {
            $key = (string) $item['key'];
            $item['value'] = array_key_exists($key, $stored)
                ? (string) $stored[$key]
                : (string) $item['default'];
        }
        unset($item);
        return $items;
    }

    /**
     * @param mixed $input
     * @return list<array<string, mixed>>
     */
    public static function save(PDO $db, string $pluginName, mixed $input): array
    {
        $pluginName = trim($pluginName);
        $definitions = self::definitions($pluginName);
        if (!is_array($input)) {
            throw new \InvalidArgumentException('支付插件配置必须是数组');
        }

        $allowed = [];
        foreach ($definitions as $item) {
            $allowed[(string) $item['key']] = $item;
        }
        $values = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('支付插件配置项格式无效');
            }
            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '' || !isset($allowed[$key])) {
                throw new \InvalidArgumentException('支付插件配置项不受支持: ' . ($key !== '' ? $key : '未命名'));
            }
            $value = $item['value'] ?? '';
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('支付插件配置值必须是文本: ' . $key);
            }
            $values[$key] = (string) ($value ?? '');
        }
        foreach ($definitions as $item) {
            $key = (string) $item['key'];
            if (!array_key_exists($key, $values)) {
                $values[$key] = (string) $item['default'];
            }
        }

        $encoded = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::write($db, 'plugin.' . $pluginName . '.config', $encoded);
        return self::read($db, $pluginName);
    }

    /** @return array<string, mixed> */
    private static function stored(PDO $db, string $pluginName): array
    {
        $query = $db->prepare('SELECT value FROM `options` WHERE `key` = :key LIMIT 1');
        $query->execute([':key' => 'plugin.' . $pluginName . '.config']);
        $raw = $query->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('支付插件配置存储格式无效: ' . $pluginName);
        }
        return $decoded;
    }

    private static function write(PDO $db, string $key, string $value): void
    {
        $exists = $db->prepare('SELECT COUNT(*) FROM `options` WHERE `key` = :key');
        $exists->execute([':key' => $key]);
        if ((int) $exists->fetchColumn() > 0) {
            $query = $db->prepare('UPDATE `options` SET value = :value WHERE `key` = :key');
            $query->execute([':key' => $key, ':value' => $value]);
            return;
        }
        $query = $db->prepare('INSERT INTO `options` (`key`, value) VALUES (:key, :value)');
        $query->execute([':key' => $key, ':value' => $value]);
    }
}
