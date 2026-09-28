<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class OrderSettlementService
{
    /**
     * @param array<string, mixed> $merchant
     * @return array{order: array<string, mixed>, already_paid: bool, balance_before: int, balance_after: int, notification: array<string, mixed>}
     */
    public function settle(
        PDO $db,
        array $merchant,
        string $externalOrderId,
        int $amount,
        string $actualAmount = '',
        string $externalPayOrderId = '',
        string $actualAccount = ''
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('交易金额无效');
        }
        $uid = (int) ($merchant['id'] ?? 0);
        if ($uid <= 0) {
            throw new \InvalidArgumentException('商户无效');
        }

        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $lock = Database::driver() === 'mysql' ? ' FOR UPDATE' : '';
            $query = $db->prepare('SELECT * FROM `order` WHERE uid = :uid AND (order_id = :order_id OR out_order_id = :out_order_id) LIMIT 1' . $lock);
            $query->execute([
                ':uid' => $uid,
                ':order_id' => $externalOrderId,
                ':out_order_id' => $externalOrderId,
            ]);
            $order = $query->fetch();
            if (!is_array($order)) {
                throw new \RuntimeException('订单不存在');
            }

            $expected = (int) ($order['amount'] ?? 0);
            if ($expected <= 0 || $amount !== $expected) {
                throw new \RuntimeException('交易金额与订单金额不一致');
            }
            $wasPaid = (int) ($order['status'] ?? 0) === 2;
            $now = time();
            $before = 0;
            $after = 0;
            $rebate = null;

            if (!$wasPaid) {
                $rechargeContext = $this->rechargeContext($order);
                if ($rechargeContext !== null) {
                    $recharge = (new RechargeService())->settle(
                        $db,
                        $rechargeContext['recharge_order_id'],
                        $amount,
                        $now,
                        $rechargeContext['target_uid'],
                    );
                    $before = (int) ($recharge['balance_before'] ?? 0);
                    $after = (int) ($recharge['balance_after'] ?? $before);
                    $rebate = $recharge['rebate'] ?? null;
                } else {
                    $before = $this->merchantBalance($db, $uid);
                    $after = $before + $amount;
                }
                $update = $db->prepare('UPDATE `order` SET status = 2, trade_amount = :trade_amount, actual_amount = :actual_amount, out_pay_order_id = :out_pay_order_id, actual_account = :actual_account, pay_time = :pay_time, updated_at = :updated_at WHERE id = :id AND status <> 2');
                $update->execute([
                    ':trade_amount' => $amount,
                    ':actual_amount' => $actualAmount,
                    ':out_pay_order_id' => $externalPayOrderId,
                    ':actual_account' => $actualAccount,
                    ':pay_time' => $now,
                    ':updated_at' => $now,
                    ':id' => (int) $order['id'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('订单状态更新失败');
                }
                if ($rechargeContext === null) {
                    $balanceUpdate = $db->prepare('UPDATE `user` SET balance = :balance WHERE id = :id');
                    $balanceUpdate->execute([':balance' => $after, ':id' => $uid]);
                    if ($balanceUpdate->rowCount() !== 1) {
                        throw new \RuntimeException('商户余额更新失败');
                    }
                    $log = $db->prepare('INSERT INTO balance_log (uid, type, amount, before_balance, after_balance, remark, created_at) VALUES (:uid, :type, :amount, :before_balance, :after_balance, :remark, :created_at)');
                    $log->execute([
                        ':uid' => $uid,
                        ':type' => 'order_income',
                        ':amount' => $amount,
                        ':before_balance' => $before,
                        ':after_balance' => $after,
                        ':remark' => '订单结算 ' . (string) $order['order_id'],
                        ':created_at' => $now,
                    ]);
                    $rebate = (new RebateService())->settleOrderFee($db, $order, $amount, $now);
                }
                $order['status'] = 2;
                $order['trade_amount'] = $amount;
                $order['actual_amount'] = $actualAmount;
                $order['out_pay_order_id'] = $externalPayOrderId;
                $order['actual_account'] = $actualAccount;
                $order['pay_time'] = $now;
                $order['updated_at'] = $now;
            } else {
                $order['status'] = 2;
            }

            $notifier = new OrderNotifier();
            $notification = $notifier->enqueue($db, $order, $merchant);
            if ($ownsTransaction) {
                $db->commit();
            }
            return [
                'order' => $order,
                'already_paid' => $wasPaid,
                'balance_before' => $before,
                'balance_after' => $after,
                'notification' => $notification,
                'rebate' => $rebate,
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    private function merchantBalance(PDO $db, int $uid): int
    {
        $suffix = Database::driver() === 'mysql' ? ' FOR UPDATE' : '';
        $query = $db->prepare('SELECT balance FROM `user` WHERE id = :id LIMIT 1' . $suffix);
        $query->execute([':id' => $uid]);
        $balance = $query->fetchColumn();
        if ($balance === false) {
            throw new \RuntimeException('商户不存在，无法结算订单');
        }
        return (int) $balance;
    }

    /** @return array{recharge_order_id: string, target_uid: int}|null */
    private function rechargeContext(array $order): ?array
    {
        if ((string) ($order['device'] ?? '') !== 'recharge') {
            return null;
        }
        $params = json_decode((string) ($order['param'] ?? ''), true);
        if (!is_array($params)) {
            throw new \RuntimeException('充值支付订单参数无效');
        }
        $rechargeOrderId = trim((string) ($params['recharge_order_id'] ?? ''));
        $targetUid = (int) ($params['target_uid'] ?? 0);
        if ($rechargeOrderId === '' || $targetUid <= 0) {
            throw new \RuntimeException('充值支付订单缺少目标账户');
        }
        return ['recharge_order_id' => $rechargeOrderId, 'target_uid' => $targetUid];
    }
}
