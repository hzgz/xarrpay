<?php

declare(strict_types=1);

namespace XArrPay\Support;

use XArrPay\Payment\PaymentPlugin;
use XArrPay\Payment\AccountPlugin;

final class PaymentPluginRegistry
{
    /** @var array<string, PaymentPlugin> */
    private static array $plugins = [];

    /** @return list<array<string, mixed>> */
    public static function catalog(): array
    {
        $items = [];
        foreach (PluginRuntime::manifests() as $manifest) {
            if ((string) ($manifest['type'] ?? 'pay') !== 'pay') {
                continue;
            }
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $item = $manifest;
            unset($item['_path']);
            $item['id'] = (string) ($item['id'] ?? $name);
            $item['plugin_name'] = $name;
            $item['builtin'] = false;
            $item['adapter'] = self::hasAdapter($name);
            $items[$name] = $item;
        }
        return array_values($items);
    }

    public static function hasAdapter(string $name): bool
    {
        try {
            self::resolve($name);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function available(string $name): bool
    {
        return self::hasAdapter($name);
    }

    public static function supportsPayType(string $name, string $payType): bool
    {
        $manifest = PluginRuntime::manifest($name);
        if ($manifest === null || trim($payType) === '') {
            return false;
        }

        $payTypes = $manifest['pay_types'] ?? null;
        if (!is_array($payTypes)) {
            return false;
        }

        foreach ($payTypes as $candidate) {
            if (trim((string) $candidate) === trim($payType)) {
                return true;
            }
        }
        return false;
    }

    public static function usableForPayType(string $name, string $payType): bool
    {
        if (!self::supportsPayType($name, $payType)) {
            return false;
        }

        try {
            self::resolve($name);
            self::resolveAccount($name);
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /** @return array<string, mixed> */
    public static function catalogItem(string $name): array
    {
        $name = trim($name);
        foreach (self::catalog() as $item) {
            if ((string) ($item['name'] ?? '') === $name) {
                return $item;
            }
        }
        return [];
    }

    public static function resolve(string $name): PaymentPlugin
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('支付通道未配置插件');
        }
        $manifest = PluginRuntime::manifest($name);
        if ($manifest === null || (string) ($manifest['type'] ?? 'pay') !== 'pay') {
            throw new \RuntimeException('支付插件未安装: ' . $name);
        }
        if (($manifest['enabled'] ?? false) !== true) {
            throw new \RuntimeException('支付插件未启用: ' . $name);
        }
        if (($manifest['running'] ?? false) !== true) {
            throw new \RuntimeException('支付插件未运行: ' . $name);
        }
        if (isset(self::$plugins[$name])) {
            return self::$plugins[$name];
        }

        $entry = PluginRuntime::entryPath($manifest, 'adapter_entry', '');
        $plugin = require $entry;
        if (!$plugin instanceof PaymentPlugin) {
            throw new \RuntimeException('支付插件适配器返回类型无效: ' . $name);
        }
        if ($plugin->name() !== $name) {
            throw new \RuntimeException('支付插件名称与适配器不一致: ' . $name);
        }
        self::$plugins[$name] = $plugin;
        return $plugin;
    }

    public static function resolveAccount(string $name): AccountPlugin
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('支付通道未配置插件');
        }
        $manifest = PluginRuntime::manifest($name);
        if ($manifest === null || (string) ($manifest['type'] ?? 'pay') !== 'pay') {
            throw new \RuntimeException('支付插件未安装: ' . $name);
        }
        if (($manifest['enabled'] ?? false) !== true) {
            throw new \RuntimeException('支付插件未启用: ' . $name);
        }
        if (($manifest['running'] ?? false) !== true) {
            throw new \RuntimeException('支付插件未运行: ' . $name);
        }
        $entry = PluginRuntime::entryPath($manifest, 'account_entry', '');
        $plugin = require $entry;
        if (!$plugin instanceof AccountPlugin) {
            throw new \RuntimeException('支付插件账号适配器返回类型无效: ' . $name);
        }
        return $plugin;
    }

    public static function clearCache(): void
    {
        self::$plugins = [];
    }
}
