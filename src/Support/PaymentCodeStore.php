<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class PaymentCodeStore
{
    /** @param array<string, mixed> $account */
    public static function syncAccount(PDO $db, int $accountId, array $account): void
    {
        $now = time();
        $channelCode = trim((string) ($account['channel_code'] ?? $account['code'] ?? ''));
        $channel = $db->prepare('SELECT plugin_name FROM pay_channel WHERE code = :code LIMIT 1');
        $channel->execute([':code' => $channelCode]);
        $pluginName = (string) ($channel->fetchColumn() ?: '');
        $values = [
            'uid' => (int) ($account['uid'] ?? 0),
            'pay_type' => trim((string) ($account['pay_type'] ?? '')),
            'channel_code' => $channelCode,
            'code_type' => trim((string) ($account['code_type'] ?? 'qrcode')) ?: 'qrcode',
            'content' => trim((string) ($account['qrcode'] ?? $account['account'] ?? '')),
            'qrcode_data' => (string) ($account['qrcode_data'] ?? ''),
            'amount' => max((int) ($account['amount'] ?? 0), 0),
            'status' => (int) ($account['status'] ?? 1),
            'sort' => (int) ($account['sort'] ?? 50),
            'options' => is_string($account['options'] ?? null)
                ? (string) $account['options']
                : json_encode($account['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $existing = $db->prepare('SELECT id FROM pay_codes WHERE account_id = :account_id ORDER BY id ASC LIMIT 1');
        $existing->execute([':account_id' => $accountId]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            $update = $db->prepare('UPDATE pay_codes SET uid=:uid,pay_type=:pay_type,channel_code=:channel_code,code_type=:code_type,content=:content,qrcode_data=:qrcode_data,amount=:amount,status=:status,sort=:sort,options=:options,updated_at=:updated_at WHERE id=:id');
            $update->execute([
                ':uid' => $values['uid'],
                ':pay_type' => $values['pay_type'],
                ':channel_code' => $values['channel_code'],
                ':code_type' => $values['code_type'],
                ':content' => $values['content'],
                ':qrcode_data' => $values['qrcode_data'],
                ':amount' => $values['amount'],
                ':status' => $values['status'],
                ':sort' => $values['sort'],
                ':options' => $values['options'],
                ':updated_at' => $now,
                ':id' => (int) $id,
            ]);
        } else {
            $insert = $db->prepare('INSERT INTO pay_codes (account_id,uid,pay_type,channel_code,code_type,content,qrcode_data,amount,status,sort,options,created_at,updated_at) VALUES (:account_id,:uid,:pay_type,:channel_code,:code_type,:content,:qrcode_data,:amount,:status,:sort,:options,:created_at,:updated_at)');
            $insert->execute([
                ':account_id' => $accountId,
                ':uid' => $values['uid'],
                ':pay_type' => $values['pay_type'],
                ':channel_code' => $values['channel_code'],
                ':code_type' => $values['code_type'],
                ':content' => $values['content'],
                ':qrcode_data' => $values['qrcode_data'],
                ':amount' => $values['amount'],
                ':status' => $values['status'],
                ':sort' => $values['sort'],
                ':options' => $values['options'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
        $extension = $db->prepare('SELECT id FROM pay_account_ext WHERE account_id = :account_id LIMIT 1');
        $extension->execute([':account_id' => $accountId]);
        $extensionId = $extension->fetchColumn();
        if ($extensionId !== false) {
            $update = $db->prepare('UPDATE pay_account_ext SET plugin_name=:plugin_name,config=:config,last_seen_at=:last_seen_at,last_error=\'\',updated_at=:updated_at WHERE id=:id');
            $update->execute([
                ':plugin_name' => $pluginName,
                ':config' => $values['options'],
                ':last_seen_at' => $now,
                ':updated_at' => $now,
                ':id' => (int) $extensionId,
            ]);
            return;
        }
        $insert = $db->prepare('INSERT INTO pay_account_ext (account_id,plugin_name,external_id,config,last_seen_at,last_error,created_at,updated_at) VALUES (:account_id,:plugin_name,\'\',:config,:last_seen_at,\'\',:created_at,:updated_at)');
        $insert->execute([
            ':account_id' => $accountId,
            ':plugin_name' => $pluginName,
            ':config' => $values['options'],
            ':last_seen_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}
