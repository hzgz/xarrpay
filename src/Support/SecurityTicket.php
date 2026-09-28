<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;
use RuntimeException;

final class SecurityTicket
{
    public const KIND_LOGIN_MFA = 'login_mfa';
    public const KIND_STEP_UP = 'step_up';
    public const KIND_PASSKEY_REGISTER = 'passkey_register';
    public const KIND_PASSKEY_AUTH = 'passkey_auth';
    public const KIND_APP_LOGIN = 'app_login';
    public const STATUS_PENDING = 1;
    public const STATUS_VERIFIED = 2;
    public const STATUS_CONSUMED = 3;
    public const STATUS_EXPIRED = 4;
    public const STATUS_BLOCKED = 5;

    public static function create(PDO $db, string $kind, int $uid, string $operation, int $ttl = 300): array
    {
        $now = time();
        $ticket = bin2hex(random_bytes(32));
        $insert = $db->prepare(
            'INSERT INTO security_ticket (ticket,kind,uid,operation,status,attempts,expires_at,created_at,updated_at) '
            . 'VALUES (:ticket,:kind,:uid,:operation,:status,0,:expires_at,:created_at,:updated_at)'
        );
        $insert->execute([
            ':ticket' => $ticket,
            ':kind' => $kind,
            ':uid' => $uid,
            ':operation' => $operation,
            ':status' => self::STATUS_PENDING,
            ':expires_at' => $now + $ttl,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        return ['ticket' => $ticket, 'expire_time' => $now + $ttl];
    }

    public static function find(PDO $db, string $ticket, string $kind, ?int $uid = null): ?array
    {
        $query = $db->prepare(
            'SELECT * FROM security_ticket WHERE ticket=:ticket AND kind=:kind'
            . ($uid === null ? '' : ' AND uid=:uid') . ' LIMIT 1'
        );
        $params = [':ticket' => $ticket, ':kind' => $kind];
        if ($uid !== null) {
            $params[':uid'] = $uid;
        }
        $query->execute($params);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public static function requirePending(PDO $db, string $ticket, string $kind, ?int $uid = null): array
    {
        $row = self::find($db, $ticket, $kind, $uid);
        if ($row === null) {
            throw new RuntimeException('安全票据不存在');
        }
        $now = time();
        if ((int) ($row['expires_at'] ?? 0) < $now) {
            self::mark($db, (int) $row['id'], self::STATUS_EXPIRED);
            throw new RuntimeException('安全票据已过期，请重新操作');
        }
        if ((int) ($row['status'] ?? 0) !== self::STATUS_PENDING) {
            throw new RuntimeException('安全票据已使用，请重新操作');
        }
        return $row;
    }

    public static function incrementFailure(PDO $db, array $row): void
    {
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $status = $attempts >= 5 ? self::STATUS_BLOCKED : self::STATUS_PENDING;
        self::mark($db, (int) $row['id'], $status, $attempts);
    }

    public static function mark(PDO $db, int $id, int $status, ?int $attempts = null): void
    {
        $sql = 'UPDATE security_ticket SET status=:status,updated_at=:updated_at';
        $params = [':status' => $status, ':updated_at' => time(), ':id' => $id];
        if ($attempts !== null) {
            $sql .= ',attempts=:attempts';
            $params[':attempts'] = $attempts;
        }
        $sql .= ' WHERE id=:id';
        $db->prepare($sql)->execute($params);
    }

    public static function consumePending(
        PDO $db,
        string $ticket,
        string $kind,
        ?int $uid = null,
        ?string $operation = null
    ): bool {
        return self::consumeStatus($db, $ticket, $kind, self::STATUS_PENDING, $uid, $operation);
    }

    public static function consumeVerified(
        PDO $db,
        string $ticket,
        string $kind,
        ?int $uid = null,
        ?string $operation = null
    ): bool {
        return self::consumeStatus($db, $ticket, $kind, self::STATUS_VERIFIED, $uid, $operation);
    }

    private static function consumeStatus(
        PDO $db,
        string $ticket,
        string $kind,
        int $status,
        ?int $uid,
        ?string $operation
    ): bool {
        $conditions = [
            'ticket=:ticket',
            'kind=:kind',
            'status=:status',
            'expires_at>=:now',
        ];
        $params = [
            ':ticket' => $ticket,
            ':kind' => $kind,
            ':status' => $status,
            ':now' => time(),
        ];
        if ($uid !== null) {
            $conditions[] = 'uid=:uid';
            $params[':uid'] = $uid;
        }
        if ($operation !== null) {
            $conditions[] = 'operation=:operation';
            $params[':operation'] = $operation;
        }
        $query = $db->prepare(
            'UPDATE security_ticket SET status=:consumed_status,updated_at=:updated_at WHERE '
            . implode(' AND ', $conditions)
        );
        $query->execute($params + [
            ':consumed_status' => self::STATUS_CONSUMED,
            ':updated_at' => time(),
        ]);
        return $query->rowCount() === 1;
    }
}
