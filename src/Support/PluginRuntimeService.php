<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;
use PDOException;
use XArrPay\Payment\PaymentPlugin;
use XArrPay\Payment\PluginOptions;
use XArrPay\Payment\RuntimePlugin;

final class PluginRuntimeService
{
    /**
     * @return array{accounts:int,processed:int,settled:int,pending:int,rejected:int,failed:int,skipped:int,errors:list<string>}
     */
    public function runOnce(
        PDO $db,
        ?string $pluginName = null,
        ?int $accountId = null,
        int $limit = 100,
        string $workerId = '',
        ?int $now = null,
    ): array {
        $now ??= time();
        $limit = max(1, min($limit, 1000));
        $workerId = trim($workerId) !== '' ? trim($workerId) : 'plugin-worker-' . getmypid();
        $where = ['a.status = 1', 'c.status = 1'];
        $params = [];
        if ($pluginName !== null && trim($pluginName) !== '') {
            $where[] = 'c.plugin_name = :plugin_name';
            $params[':plugin_name'] = trim($pluginName);
        }
        if ($accountId !== null && $accountId > 0) {
            $where[] = 'a.id = :account_id';
            $params[':account_id'] = $accountId;
        }
        $query = $db->prepare(
            'SELECT a.*, c.plugin_name, c.code AS channel_code, c.type AS channel_type '
            . 'FROM pay_account a INNER JOIN pay_channel c ON c.code = a.channel_code '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id ASC'
        );
        $query->execute($params);
        $accounts = $query->fetchAll();

        $summary = [
            'accounts' => 0,
            'processed' => 0,
            'settled' => 0,
            'pending' => 0,
            'rejected' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            $summary['accounts']++;
            try {
                $result = $this->runAccount($db, $account, $limit, $workerId, $now);
                foreach (['processed', 'settled', 'pending', 'rejected', 'failed', 'skipped'] as $key) {
                    $summary[$key] += (int) ($result[$key] ?? 0);
                }
                foreach (($result['errors'] ?? []) as $error) {
                    $summary['errors'][] = (string) $error;
                }
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $summary['errors'][] = (string) ($account['id'] ?? 0) . ': ' . $exception->getMessage();
                $this->recordFailure($db, $account, 'runtime', $exception->getMessage(), $workerId, $now);
            }
        }
        return $summary;
    }

    /** @return list<array<string,mixed>> */
    public function status(PDO $db, ?string $pluginName = null, ?int $accountId = null): array
    {
        $where = [];
        $params = [];
        if ($pluginName !== null && trim($pluginName) !== '') {
            $where[] = 'c.plugin_name = :plugin_name';
            $params[':plugin_name'] = trim($pluginName);
        }
        if ($accountId !== null && $accountId > 0) {
            $where[] = 'a.id = :account_id';
            $params[':account_id'] = $accountId;
        }
        $query = $db->prepare(
            'SELECT a.id AS account_id, a.uid, a.name AS account_name, a.status AS account_status, '
            . 'a.online, a.online_start, a.online_end, c.plugin_name, c.code AS channel_code, '
            . 's.online AS runtime_online, s.last_heartbeat_at, s.last_cron_at, s.last_flow_at, '
            . 's.last_flow_count, s.last_error, s.worker_id, s.updated_at '
            . 'FROM pay_account a INNER JOIN pay_channel c ON c.code = a.channel_code '
            . 'LEFT JOIN plugin_runtime_state s ON s.account_id = a.id '
            . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ')
            . 'ORDER BY a.id ASC'
        );
        $query->execute($params);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['capabilities'] = $this->capabilities((string) ($row['plugin_name'] ?? ''));
            $row['online'] = (int) ($row['runtime_online'] ?? $row['online'] ?? 0);
        }
        unset($row);
        return array_values(array_filter($rows, 'is_array'));
    }

    /** @return list<array<string,mixed>> */
    public function flows(PDO $db, int $limit = 100, ?int $accountId = null): array
    {
        $limit = max(1, min($limit, 500));
        $where = [];
        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $where[] = 'account_id = :account_id';
            $params[':account_id'] = $accountId;
        }
        $sql = 'SELECT * FROM third_order '
            . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ')
            . 'ORDER BY id DESC LIMIT ' . $limit;
        $query = $db->prepare($sql);
        $query->execute($params);
        return array_values(array_filter($query->fetchAll(), 'is_array'));
    }

    /**
     * 接收客户端上报的单条流水，并复用运行态的去重、匹配和结算逻辑。
     *
     * @param array<string,mixed> $account
     * @param array<string,mixed> $flow
     * @return array{duplicate:bool,flow_id:int,state:string,order_id:int,message:string}
     */
    public function reportFlow(
        PDO $db,
        array $account,
        string $pluginName,
        array $flow,
        ?int $now = null,
    ): array {
        $now ??= time();
        $pluginName = trim($pluginName);
        if ($pluginName === '') {
            throw new \RuntimeException('支付插件未绑定');
        }
        if ((int) ($account['id'] ?? 0) <= 0 || (int) ($account['uid'] ?? 0) <= 0) {
            throw new \RuntimeException('渠道账号归属无效');
        }

        $stored = $this->storeFlow($db, $account, $pluginName, $flow, $now);
        $flowId = (int) ($stored['id'] ?? 0);
        $query = $db->prepare('SELECT * FROM third_order WHERE id=:id LIMIT 1');
        $query->execute([':id' => $flowId]);
        $storedFlow = $query->fetch();
        if (!is_array($storedFlow)) {
            throw new \RuntimeException('第三方流水入库失败');
        }

        $status = (int) ($storedFlow['status'] ?? 0);
        if ($status === 2) {
            return [
                'duplicate' => (bool) ($stored['duplicate'] ?? false),
                'flow_id' => $flowId,
                'state' => 'settled',
                'order_id' => (int) ($storedFlow['matched_order_id'] ?? 0),
                'message' => '流水已完成结算',
            ];
        }
        if ($status === 3) {
            return [
                'duplicate' => (bool) ($stored['duplicate'] ?? false),
                'flow_id' => $flowId,
                'state' => 'rejected',
                'order_id' => 0,
                'message' => (string) ($storedFlow['error_message'] ?? '流水已拒绝'),
            ];
        }
        if ($status === 4) {
            return [
                'duplicate' => (bool) ($stored['duplicate'] ?? false),
                'flow_id' => $flowId,
                'state' => 'failed',
                'order_id' => (int) ($storedFlow['matched_order_id'] ?? 0),
                'message' => (string) ($storedFlow['error_message'] ?? '流水处理失败'),
            ];
        }

        $state = $this->settleFlow($db, $account, $pluginName, $storedFlow, $now);
        $refresh = $db->prepare('SELECT status,matched_order_id,error_message FROM third_order WHERE id=:id LIMIT 1');
        $refresh->execute([':id' => $flowId]);
        $result = $refresh->fetch();
        $result = is_array($result) ? $result : [];
        $status = (int) ($result['status'] ?? 1);
        return [
            'duplicate' => (bool) ($stored['duplicate'] ?? false),
            'flow_id' => $flowId,
            'state' => match ($state) {
                'settled' => 'settled',
                'rejected' => 'rejected',
                'failed' => 'failed',
                default => 'pending',
            },
            'order_id' => (int) ($result['matched_order_id'] ?? 0),
            'message' => (string) ($result['error_message'] ?? match ($status) {
                2 => '匹配成功',
                3 => '匹配失败',
                4 => '流水处理失败',
                default => '等待订单匹配',
            }),
        ];
    }

    /**
     * @param array<string,mixed> $account
     * @return array{processed:int,settled:int,pending:int,rejected:int,failed:int,skipped:int,errors:list<string>}
     */
    private function runAccount(PDO $db, array $account, int $limit, string $workerId, int $now): array
    {
        $result = [
            'processed' => 0,
            'settled' => 0,
            'pending' => 0,
            'rejected' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
        $pluginName = trim((string) ($account['plugin_name'] ?? ''));
        if ($pluginName === '') {
            throw new \RuntimeException('收款账号未绑定插件');
        }
        $manifest = PluginRuntime::manifest($pluginName);
        if ($manifest === null) {
            throw new \RuntimeException('支付插件未安装: ' . $pluginName);
        }
        $capabilities = array_values(array_map('strval', is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : []));
        if (!in_array('cron', $capabilities, true) && !in_array('heartbeat', $capabilities, true)) {
            $result['skipped']++;
            return $result;
        }
        $plugin = PaymentPluginRegistry::resolve($pluginName);
        if (!$plugin instanceof RuntimePlugin) {
            throw new \RuntimeException('插件已声明运行态能力，但 PHP 适配器未实现运行态契约');
        }
        $this->ensureState($db, $account, $workerId, $now);
        $logId = 0;
        $lastError = '';
        $flowCount = 0;
        try {
            $logId = $this->logStart($db, $account, 'runtime', $workerId, $now);
            if (in_array('heartbeat', $capabilities, true)) {
                $heartbeat = $plugin->heartbeat($db, $account, $now);
                $online = ($heartbeat['online'] ?? false) === true;
                $lastSeen = (int) ($heartbeat['last_seen_at'] ?? 0);
                $message = trim((string) ($heartbeat['message'] ?? ($online ? '心跳正常' : '心跳异常')));
                $this->updateHeartbeat($db, $account, $online, $lastSeen, $message, $workerId, $now);
                if ($online) {
                    PluginAccountStore::markOnline($db, (int) $account['id']);
                } else {
                    PluginAccountStore::markOffline($db, (int) $account['id']);
                }
            }

            if (in_array('cron', $capabilities, true)) {
                $cron = $plugin->cron($db, $account, $now);
                $flows = $cron['flows'] ?? null;
                if (!is_array($flows)) {
                    throw new \RuntimeException('插件流水定时任务返回格式无效');
                }
                foreach (array_slice($flows, 0, $limit) as $flow) {
                    if (!is_array($flow)) {
                        throw new \RuntimeException('插件返回了无效流水项');
                    }
                    $stored = $this->storeFlow($db, $account, $pluginName, $flow, $now);
                    if (($stored['duplicate'] ?? false) === true) {
                        continue;
                    }
                    $flowCount++;
                    $result['processed']++;
                }
                $this->updateCron($db, $account, $flowCount, $workerId, $now);
                PluginAccountStore::markOnline($db, (int) $account['id']);
                $this->touchSeen($db, (int) $account['id'], $now, '');
            }

            foreach ($this->pendingFlows($db, (int) $account['id'], $limit) as $flow) {
                $state = $this->settleFlow($db, $account, $pluginName, $flow, $now);
                $result[$state]++;
            }
            $this->logFinish($db, $logId, 1, $flowCount, $lastError, $now);
        } catch (\Throwable $exception) {
            $lastError = $exception->getMessage();
            $result['failed']++;
            $result['errors'][] = $pluginName . '#' . (string) ($account['id'] ?? 0) . ': ' . $lastError;
            $this->updateError($db, $account, $lastError, $workerId, $now);
            $this->logFinish($db, $logId, 0, $flowCount, $lastError, $now);
        }
        return $result;
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $flow @return array{duplicate:bool,id:int} */
    private function storeFlow(PDO $db, array $account, string $pluginName, array $flow, int $now): array
    {
        $normalized = $this->normalizeFlow($flow);
        $params = [
            ':plugin_name' => $pluginName,
            ':account_id' => (int) $account['id'],
            ':uid' => (int) ($account['uid'] ?? 0),
            ':third_order_id' => $normalized['third_order_id'],
            ':amount' => $normalized['amount'],
            ':trans_time' => $normalized['trans_time'],
            ':remark' => $normalized['remark'],
            ':buyer_name' => $normalized['buyer_name'],
            ':raw_data' => json_encode($normalized['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        try {
            $insert = $db->prepare(
                'INSERT INTO third_order (plugin_name,account_id,uid,third_order_id,amount,trans_time,remark,buyer_name,raw_data,status,matched_order_id,error_message,created_at,updated_at) '
                . 'VALUES (:plugin_name,:account_id,:uid,:third_order_id,:amount,:trans_time,:remark,:buyer_name,:raw_data,0,0,\'\',:created_at,:updated_at)'
            );
            $insert->execute($params);
            return ['duplicate' => false, 'id' => (int) $db->lastInsertId()];
        } catch (PDOException $exception) {
            $query = $db->prepare('SELECT id FROM third_order WHERE plugin_name = :plugin_name AND account_id = :account_id AND third_order_id = :third_order_id LIMIT 1');
            $query->execute([
                ':plugin_name' => $pluginName,
                ':account_id' => (int) $account['id'],
                ':third_order_id' => $normalized['third_order_id'],
            ]);
            $existing = $query->fetchColumn();
            if ($existing !== false) {
                return ['duplicate' => true, 'id' => (int) $existing];
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    private function pendingFlows(PDO $db, int $accountId, int $limit): array
    {
        $query = $db->prepare('SELECT * FROM third_order WHERE account_id = :account_id AND status IN (0,1) ORDER BY id ASC LIMIT ' . max(1, min($limit, 1000)));
        $query->execute([':account_id' => $accountId]);
        return array_values(array_filter($query->fetchAll(), 'is_array'));
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $flow */
    private function settleFlow(PDO $db, array $account, string $pluginName, array $flow, int $now): string
    {
        $window = $this->window($account);
        $order = $this->findOrder($db, $account, $pluginName, $flow, $window, $now);
        if ($order === null) {
            $status = $now > ((int) $flow['trans_time'] + $window) ? 3 : 1;
            $message = $status === 3
                ? '流水已超出订单匹配时间窗口'
                : '尚未找到同账号同金额且处于时间窗口内的待支付订单';
            $this->updateFlow($db, (int) $flow['id'], $status, 0, $message, $now);
            return $status === 3 ? 'rejected' : 'pending';
        }

        $uid = (int) ($order['uid'] ?? 0);
        $merchantQuery = $db->prepare('SELECT * FROM `user` WHERE id = :id AND status = 1 LIMIT 1');
        $merchantQuery->execute([':id' => $uid]);
        $merchant = $merchantQuery->fetch();
        if (!is_array($merchant)) {
            $this->updateFlow($db, (int) $flow['id'], 4, 0, '订单所属商户不存在或已停用', $now);
            return 'failed';
        }

        try {
            $settlement = (new OrderSettlementService())->settle(
                $db,
                $merchant,
                (string) $order['order_id'],
                (int) $flow['amount'],
                number_format((int) $flow['amount'] / 100, 2, '.', ''),
                (string) $flow['third_order_id'],
                (string) ($flow['buyer_name'] ?? ''),
            );
            $this->updateFlow($db, (int) $flow['id'], 2, (int) $order['id'], '', $now);
            return 'settled';
        } catch (\Throwable $exception) {
            $this->updateFlow($db, (int) $flow['id'], 4, (int) $order['id'], $exception->getMessage(), $now);
            return 'failed';
        }
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $flow @return array<string,mixed>|null */
    private function findOrder(PDO $db, array $account, string $pluginName, array $flow, int $window, int $now): ?array
    {
        $query = $db->prepare(
            'SELECT o.*, u.id AS merchant_id, c.plugin_name AS order_plugin '
            . 'FROM `order` o INNER JOIN `user` u ON u.id = o.uid '
            . 'INNER JOIN `pay_channel` c ON c.code = o.channel_code '
            . 'WHERE o.account_id = :account_id AND o.status = 1 AND u.status = 1 '
            . 'AND c.status = 1 AND c.plugin_name = :plugin_name ORDER BY o.created_at ASC, o.id ASC'
        );
        $query->execute([
            ':account_id' => (int) $account['id'],
            ':plugin_name' => $pluginName,
        ]);
        $hint = trim((string) ($flow['remark'] ?? ''));
        $candidates = [];
        foreach ($query->fetchAll() as $order) {
            if (!is_array($order)) {
                continue;
            }
            if ((int) ($account['uid'] ?? 0) > 0 && (int) $account['uid'] !== (int) $order['uid']) {
                continue;
            }
            if ((int) ($order['amount'] ?? 0) !== (int) $flow['amount']) {
                continue;
            }
            $created = (int) ($order['created_at'] ?? 0);
            $expires = (int) ($order['expire_time'] ?? 0);
            if ($created <= 0 || (int) $flow['trans_time'] < $created - $window) {
                continue;
            }
            if ($expires > 0 && (int) $flow['trans_time'] > $expires + $window) {
                continue;
            }
            if ($expires > 0 && $expires < $now) {
                continue;
            }
            $score = 0;
            if ($hint !== '' && (
                str_contains($hint, (string) ($order['order_id'] ?? ''))
                || str_contains($hint, (string) ($order['out_order_id'] ?? ''))
            )) {
                $score = 10;
            }
            $candidates[] = ['score' => $score, 'order' => $order];
        }
        usort($candidates, static fn (array $left, array $right): int =>
            ((int) $right['score'] <=> (int) $left['score'])
            ?: ((int) ($left['order']['created_at'] ?? 0) <=> (int) ($right['order']['created_at'] ?? 0))
        );
        return isset($candidates[0]['order']) && is_array($candidates[0]['order'])
            ? $candidates[0]['order']
            : null;
    }

    /** @param array<string,mixed> $flow @return array{third_order_id:string,amount:int,trans_time:int,remark:string,buyer_name:string,raw:array<string,mixed>} */
    private function normalizeFlow(array $flow): array
    {
        $id = trim((string) ($flow['third_order_id'] ?? ''));
        $amount = (int) ($flow['amount'] ?? 0);
        $time = (int) ($flow['trans_time'] ?? 0);
        if ($id === '' || $amount <= 0 || $time <= 0) {
            throw new \RuntimeException('第三方流水缺少流水号、金额或交易时间');
        }
        $raw = $flow['raw'] ?? $flow;
        if (!is_array($raw)) {
            throw new \RuntimeException('第三方流水原始数据格式无效');
        }
        return [
            'third_order_id' => $id,
            'amount' => $amount,
            'trans_time' => $time,
            'remark' => trim((string) ($flow['remark'] ?? '')),
            'buyer_name' => trim((string) ($flow['buyer_name'] ?? '')),
            'raw' => $raw,
        ];
    }

    /** @param array<string,mixed> $account */
    private function ensureState(PDO $db, array $account, string $workerId, int $now): void
    {
        $query = $db->prepare('SELECT id FROM plugin_runtime_state WHERE account_id = :account_id LIMIT 1');
        $query->execute([':account_id' => (int) $account['id']]);
        if ($query->fetchColumn() !== false) {
            return;
        }
        $insert = $db->prepare(
            'INSERT INTO plugin_runtime_state (account_id,plugin_name,worker_id,created_at,updated_at) VALUES (:account_id,:plugin_name,:worker_id,:created_at,:updated_at)'
        );
        $insert->execute([
            ':account_id' => (int) $account['id'],
            ':plugin_name' => (string) ($account['plugin_name'] ?? ''),
            ':worker_id' => $workerId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $account */
    private function updateHeartbeat(PDO $db, array $account, bool $online, int $lastSeen, string $message, string $workerId, int $now): void
    {
        $query = $db->prepare(
            'UPDATE plugin_runtime_state SET online=:online,last_heartbeat_at=:last_heartbeat_at,last_error=:last_error,worker_id=:worker_id,updated_at=:updated_at WHERE account_id=:account_id'
        );
        $query->execute([
            ':online' => $online ? 1 : 0,
            ':last_heartbeat_at' => $now,
            ':last_error' => $online ? '' : $message,
            ':worker_id' => $workerId,
            ':updated_at' => $now,
            ':account_id' => (int) $account['id'],
        ]);
    }

    /** @param array<string,mixed> $account */
    private function updateCron(PDO $db, array $account, int $flowCount, string $workerId, int $now): void
    {
        $query = $db->prepare(
            'UPDATE plugin_runtime_state SET online=1,last_cron_at=:last_cron_at,last_flow_at=:last_flow_at,last_flow_count=:last_flow_count,last_error=\'\',worker_id=:worker_id,updated_at=:updated_at WHERE account_id=:account_id'
        );
        $query->execute([
            ':last_cron_at' => $now,
            ':last_flow_at' => $flowCount > 0 ? $now : 0,
            ':last_flow_count' => $flowCount,
            ':worker_id' => $workerId,
            ':updated_at' => $now,
            ':account_id' => (int) $account['id'],
        ]);
    }

    /** @param array<string,mixed> $account */
    private function updateError(PDO $db, array $account, string $message, string $workerId, int $now): void
    {
        $query = $db->prepare(
            'UPDATE plugin_runtime_state SET last_error=:last_error,worker_id=:worker_id,updated_at=:updated_at WHERE account_id=:account_id'
        );
        $query->execute([
            ':last_error' => $message,
            ':worker_id' => $workerId,
            ':updated_at' => $now,
            ':account_id' => (int) $account['id'],
        ]);
    }

    private function touchSeen(PDO $db, int $accountId, int $now, string $error): void
    {
        $query = $db->prepare('UPDATE pay_account_ext SET last_seen_at=:last_seen_at,last_error=:last_error,updated_at=:updated_at WHERE account_id=:account_id');
        $query->execute([
            ':last_seen_at' => $now,
            ':last_error' => $error,
            ':updated_at' => $now,
            ':account_id' => $accountId,
        ]);
    }

    /** @param array<string,mixed> $account */
    private function recordFailure(PDO $db, array $account, string $event, string $message, string $workerId, int $now): void
    {
        try {
            $this->ensureState($db, $account, $workerId, $now);
            $this->updateError($db, $account, $message, $workerId, $now);
            $this->logFinish($db, 0, 0, 0, $message, $now, $account, $event, $workerId);
        } catch (\Throwable) {
            // 原始运行异常已经返回给 worker；这里不覆盖根因。
        }
    }

    /** @param array<string,mixed> $account */
    private function logStart(PDO $db, array $account, string $event, string $workerId, int $now): int
    {
        $insert = $db->prepare(
            'INSERT INTO plugin_runtime_log (account_id,plugin_name,event,status,message,flow_count,worker_id,started_at,finished_at,created_at) '
            . 'VALUES (:account_id,:plugin_name,:event,0,\'\',0,:worker_id,:started_at,0,:created_at)'
        );
        $insert->execute([
            ':account_id' => (int) $account['id'],
            ':plugin_name' => (string) ($account['plugin_name'] ?? ''),
            ':event' => $event,
            ':worker_id' => $workerId,
            ':started_at' => $now,
            ':created_at' => $now,
        ]);
        return (int) $db->lastInsertId();
    }

    /** @param array<string,mixed> $account */
    private function logFinish(
        PDO $db,
        int $logId,
        int $status,
        int $flowCount,
        string $message,
        int $now,
        ?array $account = null,
        string $event = '',
        string $workerId = '',
    ): void
    {
        $params = [
            ':status' => $status,
            ':message' => $message,
            ':flow_count' => $flowCount,
            ':finished_at' => $now,
        ];
        if ($logId > 0) {
            $sql = 'UPDATE plugin_runtime_log SET status=:status,message=:message,flow_count=:flow_count,finished_at=:finished_at WHERE id=:id';
            $params[':id'] = $logId;
        } elseif ($account !== null) {
            $sql = 'UPDATE plugin_runtime_log SET status=:status,message=:message,flow_count=:flow_count,finished_at=:finished_at '
                . 'WHERE account_id=:account_id AND event=:event AND worker_id=:worker_id AND finished_at=0';
            $params[':account_id'] = (int) $account['id'];
            $params[':event'] = $event;
            $params[':worker_id'] = $workerId;
        } else {
            return;
        }
        $query = $db->prepare($sql);
        $query->execute($params);
    }

    private function updateFlow(PDO $db, int $id, int $status, int $orderId, string $message, int $now): void
    {
        $query = $db->prepare(
            'UPDATE third_order SET status=:status,matched_order_id=:matched_order_id,error_message=:error_message,updated_at=:updated_at WHERE id=:id'
        );
        $query->execute([
            ':status' => $status,
            ':matched_order_id' => $orderId,
            ':error_message' => $message,
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }

    /** @param array<string,mixed> $account */
    private function window(array $account): int
    {
        $options = PluginOptions::fromAccount($account);
        return max((int) ($options['poll_window_seconds'] ?? 600), 60);
    }

    /** @return list<string> */
    private function capabilities(string $pluginName): array
    {
        $manifest = PluginRuntime::manifest($pluginName);
        return $manifest !== null && is_array($manifest['capabilities'] ?? null)
            ? array_values(array_map('strval', $manifest['capabilities']))
            : [];
    }
}
