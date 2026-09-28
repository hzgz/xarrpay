<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class PaymentPluginCatalog
{
    /** @return list<array<string, mixed>> */
    public static function channelDefinitions(string $pluginName): array
    {
        $item = PaymentPluginRegistry::catalogItem(trim($pluginName));
        if ($item === []) {
            throw new \RuntimeException('支付插件未安装: ' . trim($pluginName));
        }
        $channels = $item['channels'] ?? [];
        if (!is_array($channels)) {
            throw new \RuntimeException('支付插件通道定义无效: ' . trim($pluginName));
        }
        $result = [];
        foreach ($channels as $payType => $definitions) {
            if (!is_array($definitions)) {
                throw new \RuntimeException('支付插件通道定义无效: ' . trim($pluginName));
            }
            foreach ($definitions as $definition) {
                if (!is_array($definition)) {
                    throw new \RuntimeException('支付插件通道项无效: ' . trim($pluginName));
                }
                $code = trim((string) ($definition['value'] ?? ''));
                $label = trim((string) ($definition['label'] ?? ''));
                if ($code === '' || $label === '' || trim((string) $payType) === '') {
                    throw new \RuntimeException('支付插件通道项缺少代码、名称或支付方式: ' . trim($pluginName));
                }
                $result[] = [
                    'pay_type' => trim((string) $payType),
                    'code' => $code,
                    'name' => $label,
                    'label' => $label,
                    'report' => (int) ($definition['report'] ?? 0),
                    'parse_msg' => (int) ($definition['parse_msg'] ?? 0),
                    'options' => is_array($definition['options'] ?? null) ? $definition['options'] : [],
                ];
            }
        }
        return $result;
    }

    public static function supportsChannel(string $pluginName, string $channelCode, string $payType): bool
    {
        $definitions = self::channelDefinitions($pluginName);
        if ($definitions === []) {
            return true;
        }
        foreach ($definitions as $definition) {
            if ($definition['code'] === trim($channelCode) && $definition['pay_type'] === trim($payType)) {
                return true;
            }
        }
        return false;
    }

    public static function syncChannels(PDO $db, string $pluginName): int
    {
        $created = 0;
        foreach (self::channelDefinitions($pluginName) as $definition) {
            $exists = $db->prepare('SELECT id,type,plugin_name FROM pay_channel WHERE code = :code LIMIT 1');
            $exists->execute([':code' => $definition['code']]);
            $existing = $exists->fetch();
            if (is_array($existing)) {
                if ((string) ($existing['type'] ?? '') !== (string) $definition['pay_type']
                    || (string) ($existing['plugin_name'] ?? '') !== trim($pluginName)
                ) {
                    throw new \RuntimeException(
                        '固定通道代码已被其它支付方式或插件占用: ' . $definition['code']
                    );
                }
                continue;
            }
            $insert = $db->prepare(
                'INSERT INTO pay_channel (code,name,type,status,plugin_name,remark,options) '
                . 'VALUES (:code,:name,:type,1,:plugin_name,\'\',:options)'
            );
            $insert->execute([
                ':code' => $definition['code'],
                ':name' => $definition['name'],
                ':type' => $definition['pay_type'],
                ':plugin_name' => trim($pluginName),
                ':options' => json_encode($definition['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $created++;
        }
        return $created;
    }

    public static function syncAllChannels(PDO $db): int
    {
        $created = 0;
        foreach (PaymentPluginRegistry::catalog() as $plugin) {
            if ((string) ($plugin['type'] ?? 'pay') !== 'pay') {
                continue;
            }
            $name = trim((string) ($plugin['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $created += self::syncChannels($db, $name);
        }
        return $created;
    }

    /** @return array<string, mixed> */
    public static function channelMeta(string $pluginName, string $channelCode, string $payType): array
    {
        $pluginName = trim($pluginName);
        $item = PaymentPluginRegistry::catalogItem($pluginName);
        if ($item === []) {
            return [
                'plugin_name' => $pluginName,
                'channel_code' => trim($channelCode),
                'type' => trim($payType),
                'plugin_options' => [],
                'bind_pay_type' => [],
                'actions' => [],
            ];
        }

        $payType = trim($payType);
        $channelDefinition = null;
        foreach (self::channelDefinitions($pluginName) as $definition) {
            if ($definition['code'] === trim($channelCode) && $definition['pay_type'] === $payType) {
                $channelDefinition = $definition;
                break;
            }
        }
        return [
            'plugin_name' => $pluginName,
            'channel_code' => trim($channelCode),
            'type' => $payType,
            'pay_types' => is_array($item['pay_types'] ?? null) ? array_values($item['pay_types']) : [],
            'plugin_options' => is_array($item['plugin_options'] ?? null) ? $item['plugin_options'] : [],
            'channel' => $channelDefinition,
            'bind_pay_type' => $payType === '' ? [] : [[
                'label' => $payType,
                'value' => $payType,
            ]],
            'actions' => is_array($item['actions'] ?? null) ? $item['actions'] : [],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function formItems(string $pluginName, string $channelCode, ?string $payType = null): array
    {
        $pluginName = trim($pluginName);
        $item = PaymentPluginRegistry::catalogItem($pluginName);
        if ($item === []) {
            throw new \RuntimeException('支付插件未安装: ' . ($pluginName !== '' ? $pluginName : '未配置'));
        }
        PaymentPluginRegistry::resolve($pluginName);
        PaymentPluginRegistry::resolveAccount($pluginName);

        $manifest = PluginRuntime::manifest($pluginName);
        if ($manifest === null) {
            throw new \RuntimeException('支付插件清单不存在: ' . $pluginName);
        }
        $entry = PluginRuntime::entryPath($manifest, 'form_entry', '');
        $items = require $entry;
        if (!is_array($items)) {
            throw new \RuntimeException('支付插件表单入口返回类型无效: ' . $pluginName);
        }
        $resolvedPayType = trim((string) ($payType ?? self::channelPayType($pluginName, $channelCode)));
        if ($resolvedPayType !== '' && !self::supportsChannel($pluginName, $channelCode, $resolvedPayType)) {
            throw new \RuntimeException('支付插件不提供该通道的配置表单: ' . $channelCode);
        }
        foreach ($items as $formItem) {
            if (!is_array($formItem) || trim((string) ($formItem['name'] ?? '')) === '') {
                throw new \RuntimeException('支付插件表单项无效: ' . $pluginName);
            }
        }
        return array_values($items);
    }

    private static function channelPayType(string $pluginName, string $channelCode): string
    {
        foreach (self::channelDefinitions($pluginName) as $definition) {
            if ($definition['code'] === trim($channelCode)) {
                return (string) $definition['pay_type'];
            }
        }
        return '';
    }
}
