<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class OrderNotifier
{
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const LEASE_SECONDS = 60;

    /** @param array<string, mixed> $order @param array<string, mixed> $merchant */
    public function enqueue(PDO $db, array $order, array $merchant, bool $force = false): array
    {
        $orderId = (string) ($order['order_id'] ?? '');
        $uid = (int) ($order['uid'] ?? $merchant['id'] ?? 0);
        if ($orderId === '' || $uid <= 0) {
            throw new \InvalidArgumentException('通知任务缺少订单号或商户 UID');
        }

        $existingQuery = $db->prepare('SELECT * FROM notify_queue WHERE order_id = :order_id LIMIT 1');
        $existingQuery->execute([':order_id' => $orderId]);
        $existing = $existingQuery->fetch();
        $now = time();

        if (is_array($existing)) {
            if (!$force) {
                return ['queued' => true, 'created' => false, 'id' => (int) $existing['id'], 'status' => (string) $existing['status']];
            }
            $update = $db->prepare('UPDATE notify_queue SET uid = :uid, url = :url, payload = :payload, status = :status, attempts = 0, available_at = :available_at, leased_until = 0, lease_token = \'\', last_http_status = 0, last_response = \'\', last_error = \'\', updated_at = :updated_at WHERE id = :id');
            $update->execute($this->queueValues($order, $merchant) + [
                ':id' => (int) $existing['id'],
                ':status' => 'queued',
                ':available_at' => $now,
                ':updated_at' => $now,
            ]);
            return ['queued' => true, 'created' => false, 'id' => (int) $existing['id'], 'status' => 'queued', 'retried' => true];
        }

        $values = $this->queueValues($order, $merchant);
        $values[':status'] = 'queued';
        $values[':attempts'] = 0;
        $values[':max_attempts'] = $this->maxAttempts();
        $values[':available_at'] = $now;
        $values[':leased_until'] = 0;
        $values[':lease_token'] = '';
        $values[':last_http_status'] = 0;
        $values[':last_response'] = '';
        $values[':last_error'] = '';
        $values[':created_at'] = $now;
        $values[':updated_at'] = $now;

        $insert = $db->prepare('INSERT INTO notify_queue (uid, order_id, url, payload, status, attempts, max_attempts, available_at, leased_until, lease_token, last_http_status, last_response, last_error, created_at, updated_at) VALUES (:uid, :order_id, :url, :payload, :status, :attempts, :max_attempts, :available_at, :leased_until, :lease_token, :last_http_status, :last_response, :last_error, :created_at, :updated_at)');
        try {
            $insert->execute($values);
        } catch (\PDOException $exception) {
            $existingQuery->execute([':order_id' => $orderId]);
            $existing = $existingQuery->fetch();
            if (!is_array($existing)) {
                throw $exception;
            }
            return ['queued' => true, 'created' => false, 'id' => (int) $existing['id'], 'status' => (string) $existing['status']];
        }

        return ['queued' => true, 'created' => true, 'id' => (int) $db->lastInsertId(), 'status' => 'queued'];
    }

    /** @return array<string, mixed>|null */
    public function claim(PDO $db, string $workerId, int $leaseSeconds = self::LEASE_SECONDS): ?array
    {
        $now = time();
        $query = 'SELECT * FROM notify_queue WHERE available_at <= :now AND ((status = :queued) OR (status = :processing AND leased_until <= :now_expired)) ORDER BY id ASC LIMIT 1';
        if (Database::driver() === 'mysql') {
            $query .= ' FOR UPDATE';
        }
        $db->beginTransaction();
        try {
            $this->recoverExpiredFinalAttempts($db, $now);
            $select = $db->prepare($query);
            $select->execute([':now' => $now, ':queued' => 'queued', ':processing' => 'processing', ':now_expired' => $now]);
            $task = $select->fetch();
            if (!is_array($task)) {
                $db->commit();
                return null;
            }
            if ((int) $task['attempts'] >= (int) $task['max_attempts']) {
                $this->markExpiredFinalAttempt($db, $task, $now);
                $db->commit();
                return null;
            }

            $token = bin2hex(random_bytes(16));
            $update = $db->prepare('UPDATE notify_queue SET status = :status, attempts = attempts + 1, leased_until = :leased_until, lease_token = :lease_token, updated_at = :updated_at WHERE id = :id AND ((status = :queued AND available_at <= :available_at) OR (status = :processing AND leased_until <= :expired_at))');
            $update->execute([
                ':status' => 'processing',
                ':leased_until' => $now + max($leaseSeconds, 1),
                ':lease_token' => $token,
                ':updated_at' => $now,
                ':id' => (int) $task['id'],
                ':queued' => 'queued',
                ':available_at' => $now,
                ':expired_at' => $now,
            ]);
            if ($update->rowCount() !== 1) {
                $db->commit();
                return null;
            }
            $task['status'] = 'processing';
            $task['attempts'] = (int) $task['attempts'] + 1;
            $task['lease_token'] = $token;
            $task['leased_until'] = $now + max($leaseSeconds, 1);
            $db->commit();
            return $task;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $task @return array<string, mixed> */
    public function deliver(array $task): array
    {
        $url = trim((string) ($task['url'] ?? ''));
        if ($url === '') {
            return ['success' => false, 'skipped' => true, 'http_status' => 0, 'response' => '', 'message' => '未配置异步通知地址'];
        }
        $parsed = parse_url($url);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = trim((string) ($parsed['host'] ?? ''), '[]');
        if (
            !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parsed['user'])
            || isset($parsed['pass'])
        ) {
            return ['success' => false, 'skipped' => true, 'http_status' => 0, 'response' => '', 'message' => '异步通知地址必须是 http 或 https URL'];
        }

        $port = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));
        $addresses = $this->publicAddresses($host);
        if ($addresses === []) {
            return ['success' => false, 'skipped' => true, 'http_status' => 0, 'response' => '', 'message' => '异步通知地址解析失败或指向非公网地址'];
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return ['success' => false, 'skipped' => false, 'http_status' => 0, 'response' => '', 'message' => '无法初始化 HTTP 客户端'];
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) ($task['payload'] ?? ''),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => array_map(
                static fn (string $address): string => $host . ':' . $port . ':' . (str_contains($address, ':') ? '[' . $address . ']' : $address),
                $addresses
            ),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $response = is_string($body) ? substr($body, 0, 4096) : '';
        $success = $body !== false && $httpStatus >= 200 && $httpStatus < 300 && strtolower(trim($response)) === 'success';
        return [
            'success' => $success,
            'skipped' => false,
            'http_status' => $httpStatus,
            'response' => $response,
            'message' => $success ? '异步通知成功' : ($error !== '' ? $error : '商户未返回 success'),
        ];
    }

    /** @return list<string> */
    private function publicAddresses(string $host): array
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicAddress($literal) ? [$literal] : [];
        }
        if (!$this->isDnsHost($literal)) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($address === '') {
                continue;
            }
            if (!$this->isPublicAddress($address)) {
                return [];
            }
            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if (
            is_string($packed)
            && strlen($packed) === 16
            && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
        ) {
            $address = (string) inet_ntop(substr($packed, 12));
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function isDnsHost(string $host): bool
    {
        $host = rtrim($host, '.');
        if ($host === '' || strlen($host) > 253 || preg_match('/^[0-9.]+$/', $host) === 1) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (
                strlen($label) > 63
                || preg_match('/^(?!-)[A-Za-z0-9-]+(?<!-)$/', $label) !== 1
            ) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $task @param array<string, mixed> $result */
    public function finish(PDO $db, array $task, array $result): void
    {
        $now = time();
        $success = ($result['success'] ?? false) === true;
        $skipped = ($result['skipped'] ?? false) === true;
        $attempts = (int) ($task['attempts'] ?? 1);
        $maxAttempts = max((int) ($task['max_attempts'] ?? $this->maxAttempts()), 1);
        $terminal = $success ? 'success' : ($skipped || $attempts >= $maxAttempts ? ($skipped ? 'skipped' : 'failed') : 'queued');
        $availableAt = $terminal === 'queued' ? $now + $this->backoff($attempts) : $now;
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $update = $db->prepare('UPDATE notify_queue SET status = :status, available_at = :available_at, leased_until = 0, lease_token = \'\', last_http_status = :http_status, last_response = :response, last_error = :error, updated_at = :updated_at WHERE id = :id AND status = :processing AND lease_token = :lease_token');
            $update->execute([
                ':status' => $terminal,
                ':available_at' => $availableAt,
                ':http_status' => (int) ($result['http_status'] ?? 0),
                ':response' => (string) ($result['response'] ?? ''),
                ':error' => (string) ($result['message'] ?? ''),
                ':updated_at' => $now,
                ':id' => (int) ($task['id'] ?? 0),
                ':processing' => 'processing',
                ':lease_token' => (string) ($task['lease_token'] ?? ''),
            ]);
            if ($update->rowCount() !== 1) {
                if ($ownsTransaction) {
                    $db->commit();
                }
                return;
            }

            $orderUpdate = $db->prepare('UPDATE `order` SET notify_status = :status, notify_count = notify_count + 1, notify_time = :notify_time, updated_at = :updated_at WHERE order_id = :order_id AND uid = :uid');
            $orderUpdate->execute([
                ':status' => $success ? 1 : 0,
                ':notify_time' => $now,
                ':updated_at' => $now,
                ':order_id' => (string) ($task['order_id'] ?? ''),
                ':uid' => (int) ($task['uid'] ?? 0),
            ]);
            $log = $db->prepare('INSERT INTO notify_log (uid, order_id, url, status, response, message, created_at) VALUES (:uid, :order_id, :url, :status, :response, :message, :created_at)');
            $log->execute([
                ':uid' => (int) ($task['uid'] ?? 0),
                ':order_id' => (string) ($task['order_id'] ?? ''),
                ':url' => (string) ($task['url'] ?? ''),
                ':status' => $success ? 1 : 0,
                ':response' => (string) ($result['response'] ?? ''),
                ':message' => (string) ($result['message'] ?? ''),
                ':created_at' => $now,
            ]);
            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function retry(PDO $db, int $id): array
    {
        $now = time();
        $update = $db->prepare('UPDATE notify_queue SET status = :status, attempts = 0, available_at = :available_at, leased_until = 0, lease_token = \'\', last_error = \'\', updated_at = :updated_at WHERE id = :id AND status IN (\'failed\', \'skipped\', \'success\')');
        $update->execute([':status' => 'queued', ':available_at' => $now, ':updated_at' => $now, ':id' => $id]);
        if ($update->rowCount() !== 1) {
            return ['queued' => false, 'id' => $id];
        }
        return ['queued' => true, 'id' => $id, 'status' => 'queued'];
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $merchant @return array<string, mixed> */
    private function queueValues(array $order, array $merchant): array
    {
        $params = $this->parameters($order, $merchant);
        $params['sign'] = EpaySigner::sign($params, (string) ($merchant['app_secret'] ?? ''));
        return [
            ':uid' => (int) ($order['uid'] ?? $merchant['id'] ?? 0),
            ':order_id' => (string) ($order['order_id'] ?? ''),
            ':url' => trim((string) ($order['notify_uri'] ?? '')),
            ':payload' => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        ];
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $merchant @return array<string, string> */
    private function parameters(array $order, array $merchant): array
    {
        $amount = (int) ($order['trade_amount'] ?? 0);
        if ($amount <= 0) {
            $amount = (int) ($order['amount'] ?? 0);
        }
        return [
            'pid' => (string) ($merchant['id'] ?? $order['uid'] ?? ''),
            'trade_no' => (string) ($order['order_id'] ?? ''),
            'out_trade_no' => (string) ($order['out_order_id'] ?? ''),
            'type' => (string) ($order['pay_type'] ?? ''),
            'name' => (string) ($order['subject'] ?? ''),
            'money' => number_format($amount / 100, 2, '.', ''),
            'trade_status' => (int) ($order['status'] ?? 0) === 2 ? 'TRADE_SUCCESS' : 'TRADE_FAILED',
            'param' => (string) ($order['param'] ?? ''),
            'sign_type' => 'MD5',
        ];
    }

    private function maxAttempts(): int
    {
        return max((int) Config::get('XARR_NOTIFY_MAX_ATTEMPTS', self::DEFAULT_MAX_ATTEMPTS), 1);
    }

    private function backoff(int $attempts): int
    {
        return min(3600, 30 * (2 ** max($attempts - 1, 0)));
    }

    private function recoverExpiredFinalAttempts(PDO $db, int $now): void
    {
        $select = $db->prepare('SELECT * FROM notify_queue WHERE status = :status AND leased_until <= :now AND attempts >= max_attempts ORDER BY id ASC LIMIT 100');
        $select->execute([':status' => 'processing', ':now' => $now]);
        foreach ($select->fetchAll() as $task) {
            $this->markExpiredFinalAttempt($db, $task, $now);
        }
    }

    /** @param array<string, mixed> $task */
    private function markExpiredFinalAttempt(PDO $db, array $task, int $now): void
    {
        $update = $db->prepare('UPDATE notify_queue SET status = :failed, leased_until = 0, lease_token = \'\', last_error = :error, updated_at = :updated_at WHERE id = :id AND status = :processing AND leased_until <= :expired_at');
        $orderUpdate = $db->prepare('UPDATE `order` SET notify_status = 0, notify_count = notify_count + 1, notify_time = :notify_time, updated_at = :updated_at WHERE order_id = :order_id AND uid = :uid');
        $message = 'worker lease expired at retry limit';
        $update->execute([
            ':failed' => 'failed',
            ':error' => $message,
            ':updated_at' => $now,
            ':id' => (int) $task['id'],
            ':processing' => 'processing',
            ':expired_at' => $now,
        ]);
        if ($update->rowCount() !== 1) {
            return;
        }
        $orderUpdate->execute([
            ':notify_time' => $now,
            ':updated_at' => $now,
            ':order_id' => (string) $task['order_id'],
            ':uid' => (int) $task['uid'],
        ]);
        $log = $db->prepare('INSERT INTO notify_log (uid, order_id, url, status, response, message, created_at) VALUES (:uid, :order_id, :url, 0, \'\', :message, :created_at)');
        $log->execute([
            ':uid' => (int) $task['uid'],
            ':order_id' => (string) $task['order_id'],
            ':url' => (string) $task['url'],
            ':message' => $message,
            ':created_at' => $now,
        ]);
    }
}
