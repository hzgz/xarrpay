<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class RechargeService
{
    /** @return array<string, mixed> */
    public function create(
        PDO $db,
        array $merchant,
        int $amount,
        string $payType,
        string $publicBaseUrl,
        int $now,
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('充值金额必须大于 0');
        }
        if (!OptionStore::bool($db, 'pay_recharge_enable')) {
            throw new \RuntimeException('余额充值功能已关闭');
        }

        $minimumYuan = (float) OptionStore::raw($db, 'pay_recharge_min', '0');
        $minimum = (int) round($minimumYuan * 100);
        if ($minimum > 0 && $amount < $minimum) {
            throw new \RuntimeException('充值金额不能低于 ' . number_format($minimumYuan, 2, '.', '') . ' 元');
        }

        $payType = trim($payType);
        $allowedPayTypes = OptionStore::csv($db, 'pay_recharge_pay_type');
        if ($payType === '' || !in_array($payType, $allowedPayTypes, true)) {
            throw new \RuntimeException('请选择已配置的充值支付方式');
        }

        $mode = trim(OptionStore::raw($db, 'pay_recharge_type', ''));
        if ($mode === 'epay') {
            return $this->createEpay($db, $merchant, $amount, $payType, $publicBaseUrl, $now);
        }
        if ($mode === 'default') {
            return $this->createDefault($db, $merchant, $amount, $payType, $publicBaseUrl, $now);
        }
        throw new \RuntimeException('充值支付模式配置无效');
    }

    /** @return array<string, mixed> */
    public function settle(PDO $db, string $rechargeOrderId, int $amount, int $now, int $expectedUid = 0): array
    {
        $rechargeOrderId = trim($rechargeOrderId);
        if ($rechargeOrderId === '' || $amount <= 0) {
            throw new \InvalidArgumentException('充值订单参数无效');
        }

        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $lock = Database::driver() === 'mysql' ? ' FOR UPDATE' : '';
            $query = $db->prepare('SELECT * FROM recharge WHERE order_id = :order_id LIMIT 1' . $lock);
            $query->execute([':order_id' => $rechargeOrderId]);
            $recharge = $query->fetch();
            if (!is_array($recharge)) {
                throw new \RuntimeException('充值订单不存在');
            }
            $expected = (int) ($recharge['amount'] ?? 0);
            if ($expected !== $amount) {
                throw new \RuntimeException('充值金额与订单金额不一致');
            }
            $uid = (int) ($recharge['uid'] ?? 0);
            if ($expectedUid > 0 && $uid !== $expectedUid) {
                throw new \RuntimeException('充值目标商户不匹配');
            }
            if ((int) ($recharge['status'] ?? 0) === 2) {
                $balanceQuery = $db->prepare('SELECT balance FROM `user` WHERE id = :id LIMIT 1');
                $balanceQuery->execute([':id' => $uid]);
                $balance = (int) ($balanceQuery->fetchColumn() ?: 0);
                if ($ownsTransaction) {
                    $db->commit();
                }
                return ['already_paid' => true, 'balance_before' => $balance, 'balance_after' => $balance, 'rebate' => null];
            }

            $userQuery = $db->prepare('SELECT balance FROM `user` WHERE id = :id AND status = 1 LIMIT 1' . $lock);
            $userQuery->execute([':id' => $uid]);
            $before = $userQuery->fetchColumn();
            if ($before === false) {
                throw new \RuntimeException('充值商户不存在或已停用');
            }
            $before = (int) $before;
            $after = $before + $amount;

            $update = $db->prepare(
                'UPDATE recharge SET status = 2, paid_at = :paid_at, updated_at = :updated_at '
                . 'WHERE id = :id AND status <> 2'
            );
            $update->execute([':paid_at' => $now, ':updated_at' => $now, ':id' => (int) $recharge['id']]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('充值订单状态更新失败');
            }

            $balanceUpdate = $db->prepare('UPDATE `user` SET balance = :balance WHERE id = :id');
            $balanceUpdate->execute([':balance' => $after, ':id' => $uid]);
            if ($balanceUpdate->rowCount() !== 1) {
                throw new \RuntimeException('商户余额更新失败');
            }

            $log = $db->prepare(
                'INSERT INTO balance_log (uid, type, amount, before_balance, after_balance, remark, created_at) '
                . 'VALUES (:uid, :type, :amount, :before_balance, :after_balance, :remark, :created_at)'
            );
            $log->execute([
                ':uid' => $uid,
                ':type' => 'recharge',
                ':amount' => $amount,
                ':before_balance' => $before,
                ':after_balance' => $after,
                ':remark' => '在线充值 ' . $rechargeOrderId,
                ':created_at' => $now,
            ]);
            $rebate = (new RebateService())->settleTopUp($db, $uid, $amount, $rechargeOrderId, $now);

            if ($ownsTransaction) {
                $db->commit();
            }
            return ['already_paid' => false, 'balance_before' => $before, 'balance_after' => $after, 'rebate' => $rebate];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function verifyEpay(PDO $db, array $params): array
    {
        $pid = trim(OptionStore::raw($db, 'pay_recharge_epay_pid'));
        $key = OptionStore::raw($db, 'pay_recharge_epay_key');
        $host = rtrim(trim(OptionStore::raw($db, 'pay_recharge_epay_host')), '/');
        if ($host === '' || $pid === '' || $key === '') {
            throw new \RuntimeException('易支付充值配置不完整');
        }
        if ((string) ($params['pid'] ?? '') !== $pid) {
            throw new \RuntimeException('充值商户号校验失败');
        }
        if (!EpaySigner::valid($params, $key)) {
            throw new \RuntimeException('充值签名校验失败');
        }
        if (!in_array((string) ($params['trade_status'] ?? ''), ['TRADE_SUCCESS', 'SUCCESS'], true)) {
            throw new \RuntimeException('充值尚未支付完成');
        }
        $orderId = trim((string) ($params['out_trade_no'] ?? ''));
        $amount = (int) round(((float) ($params['money'] ?? 0)) * 100);
        if ($orderId === '' || $amount <= 0) {
            throw new \RuntimeException('充值回调参数无效');
        }
        return ['order_id' => $orderId, 'amount' => $amount, 'host' => $host];
    }

    /** @return array<string, mixed> */
    private function createEpay(PDO $db, array $merchant, int $amount, string $payType, string $publicBaseUrl, int $now): array
    {
        $host = rtrim(trim(OptionStore::raw($db, 'pay_recharge_epay_host')), '/');
        $pid = trim(OptionStore::raw($db, 'pay_recharge_epay_pid'));
        $key = OptionStore::raw($db, 'pay_recharge_epay_key');
        if ($host === '' || !preg_match('#^https?://#i', $host) || $pid === '' || $key === '') {
            throw new \RuntimeException('易支付充值配置不完整');
        }

        $orderId = $this->newOrderId('R');
        $this->insertRecharge($db, $merchant, $orderId, $amount, $payType, '', $now);
        $params = [
            'pid' => $pid,
            'type' => $payType,
            'out_trade_no' => $orderId,
            'notify_url' => rtrim($publicBaseUrl, '/') . '/api/recharge/notify',
            'return_url' => rtrim($publicBaseUrl, '/') . '/user/recharge',
            'name' => '账户余额充值',
            'money' => number_format($amount / 100, 2, '.', ''),
        ];
        $params['sign'] = EpaySigner::sign($params, $key);
        $params['sign_type'] = 'MD5';
        return [
            'code' => 1,
            'system_order_id' => $orderId,
            'payurl' => $host . '/submit.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        ];
    }

    /** @return array<string, mixed> */
    private function createDefault(PDO $db, array $merchant, int $amount, string $payType, string $publicBaseUrl, int $now): array
    {
        $officialUid = (int) OptionStore::raw($db, 'pay_default_uid', '0');
        if ($officialUid <= 0) {
            throw new \RuntimeException('系统默认支付用户未配置');
        }
        $official = $db->prepare('SELECT id FROM `user` WHERE id = :id AND status = 1 LIMIT 1');
        $official->execute([':id' => $officialUid]);
        if ($official->fetchColumn() === false) {
            throw new \RuntimeException('系统默认支付用户不存在或已停用');
        }
        $account = $db->prepare(
            'SELECT COUNT(*) FROM pay_account a INNER JOIN pay_channel c ON c.code = a.channel_code '
            . 'WHERE a.status = 1 AND c.status = 1 AND a.pay_type = :pay_type AND (a.uid = :uid OR a.uid = 0)'
        );
        $account->execute([':pay_type' => $payType, ':uid' => $officialUid]);
        if ((int) $account->fetchColumn() === 0) {
            throw new \RuntimeException('系统默认支付用户没有可用的收款账号');
        }

        $orderId = $this->newOrderId('R');
        $paymentOrderId = $this->newOrderId('RP');
        $param = json_encode(['recharge_order_id' => $orderId, 'target_uid' => (int) $merchant['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->insertRecharge($db, $merchant, $orderId, $amount, $payType, $paymentOrderId, $now);
        $insert = $db->prepare(
            'INSERT INTO `order` '
            . '(order_id, out_order_id, uid, price, amount, trade_amount, rate_amount, status, subject, expire_time, pay_type, param, device, created_at, updated_at) '
            . 'VALUES (:order_id, :out_order_id, :uid, :price, :amount, :trade_amount, 0, 1, :subject, :expire_time, :pay_type, :param, \'recharge\', :created_at, :updated_at)'
        );
        $insert->execute([
            ':order_id' => $paymentOrderId,
            ':out_order_id' => $orderId,
            ':uid' => $officialUid,
            ':price' => $amount,
            ':amount' => $amount,
            ':trade_amount' => $amount,
            ':subject' => '账户余额充值',
            ':expire_time' => $now + 900,
            ':pay_type' => $payType,
            ':param' => $param,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $update = $db->prepare('UPDATE recharge SET payment_order_id = :payment_order_id, updated_at = :updated_at WHERE order_id = :order_id');
        $update->execute([':payment_order_id' => $paymentOrderId, ':updated_at' => $now, ':order_id' => $orderId]);

        return [
            'code' => 1,
            'system_order_id' => $orderId,
            'payurl' => rtrim($publicBaseUrl, '/') . '/pay/' . rawurlencode($paymentOrderId),
        ];
    }

    private function insertRecharge(PDO $db, array $merchant, string $orderId, int $amount, string $payType, string $paymentOrderId, int $now): void
    {
        $insert = $db->prepare(
            'INSERT INTO recharge '
            . '(uid, order_id, amount, status, remark, pay_type, payment_order_id, paid_at, created_at, updated_at) '
            . 'VALUES (:uid, :order_id, :amount, 1, :remark, :pay_type, :payment_order_id, NULL, :created_at, :updated_at)'
        );
        $insert->execute([
            ':uid' => (int) $merchant['id'],
            ':order_id' => $orderId,
            ':amount' => $amount,
            ':remark' => '待支付充值订单',
            ':pay_type' => $payType,
            ':payment_order_id' => $paymentOrderId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function newOrderId(string $prefix): string
    {
        return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
    }
}
