<?php

declare(strict_types=1);

namespace XArrPay\Support;

use PDO;

final class RechargeCardService
{
    /** @return array<string, mixed> */
    public function redeem(PDO $db, int $uid, string $secret, int $now): array
    {
        $secret = trim($secret);
        if ($uid <= 0 || $secret === '') {
            throw new \InvalidArgumentException('充值卡密不能为空');
        }

        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $lock = Database::driver() === 'mysql' ? ' FOR UPDATE' : '';
            $cardQuery = $db->prepare(
                'SELECT c.*, g.name AS group_name, g.value_type AS group_value_type, g.value AS group_value, '
                . 'g.meal_id AS group_meal_id, g.total_limit, g.time_limit, g.day_limit, g.status AS group_status '
                . 'FROM card c INNER JOIN card_group g ON g.id = c.group_id '
                . 'WHERE c.secret = :secret LIMIT 1' . $lock
            );
            $cardQuery->execute([':secret' => $secret]);
            $card = $cardQuery->fetch();
            if (!is_array($card)) {
                throw new \RuntimeException('充值卡密不存在');
            }
            if ((int) ($card['status'] ?? 0) !== 1 || (int) ($card['group_status'] ?? 0) !== 1) {
                throw new \RuntimeException('充值卡密已停用');
            }
            if ((int) ($card['use_uid'] ?? 0) > 0) {
                throw new \RuntimeException('充值卡密已使用');
            }

            $merchantQuery = $db->prepare('SELECT * FROM `user` WHERE id = :id AND status = 1 LIMIT 1' . $lock);
            $merchantQuery->execute([':id' => $uid]);
            $merchant = $merchantQuery->fetch();
            if (!is_array($merchant)) {
                throw new \RuntimeException('商户不存在或已停用');
            }

            $groupId = (int) ($card['group_id'] ?? 0);
            $totalLimit = (int) ($card['total_limit'] ?? 0);
            if ($totalLimit > 0) {
                $count = $db->prepare('SELECT COUNT(*) FROM card WHERE group_id = :group_id AND use_uid > 0');
                $count->execute([':group_id' => $groupId]);
                if ((int) $count->fetchColumn() >= $totalLimit) {
                    throw new \RuntimeException('充值卡密分组使用次数已达上限');
                }
            }

            $timeLimit = (int) ($card['time_limit'] ?? 0);
            $dayLimit = (int) ($card['day_limit'] ?? 0);
            if ($timeLimit > 0) {
                $last = $db->prepare('SELECT MAX(use_time) FROM card WHERE group_id = :group_id AND use_uid = :uid');
                $last->execute([':group_id' => $groupId, ':uid' => $uid]);
                $lastTime = (int) ($last->fetchColumn() ?: 0);
                if ($lastTime > 0 && $now - $lastTime < $timeLimit) {
                    throw new \RuntimeException('充值卡密使用过于频繁，请稍后再试');
                }
            }
            if ($dayLimit > 0) {
                $dayStart = strtotime('today', $now);
                $dayCount = $db->prepare(
                    'SELECT COUNT(*) FROM card WHERE group_id = :group_id AND use_uid = :uid AND use_time >= :day_start'
                );
                $dayCount->execute([':group_id' => $groupId, ':uid' => $uid, ':day_start' => $dayStart]);
                if ((int) $dayCount->fetchColumn() >= $dayLimit) {
                    throw new \RuntimeException('充值卡密今日使用次数已达上限');
                }
            }

            $hasCardValue = (int) ($card['value'] ?? 0) > 0 || (int) ($card['meal_id'] ?? 0) > 0;
            $valueType = $hasCardValue ? (int) ($card['value_type'] ?? 0) : (int) ($card['group_value_type'] ?? 0);
            $value = $hasCardValue ? (int) ($card['value'] ?? 0) : (int) ($card['group_value'] ?? 0);
            $mealId = $hasCardValue ? (int) ($card['meal_id'] ?? 0) : (int) ($card['group_meal_id'] ?? 0);
            $beforeBalance = (int) ($merchant['balance'] ?? 0);
            $afterBalance = $beforeBalance;
            $settings = $this->decodeSettings((string) ($merchant['settings'] ?? '{}'));
            $meal = null;

            if ($valueType === 1) {
                if ($value <= 0) {
                    throw new \RuntimeException('充值卡密金额配置无效');
                }
                $afterBalance += $value;
            } elseif ($valueType === 2) {
                if ($mealId <= 0) {
                    throw new \RuntimeException('充值卡密套餐配置无效');
                }
                $mealQuery = $db->prepare('SELECT id, name, days FROM meal WHERE id = :id AND status = 1 LIMIT 1');
                $mealQuery->execute([':id' => $mealId]);
                $meal = $mealQuery->fetch();
                if (!is_array($meal)) {
                    throw new \RuntimeException('充值卡密绑定的套餐不存在或已停用');
                }
                $currentExpires = (int) ($settings['meal']['expires_at'] ?? 0);
                $base = max($now, $currentExpires);
                $settings['meal'] = [
                    'id' => (int) $meal['id'],
                    'name' => (string) $meal['name'],
                    'expires_at' => $base + max((int) ($meal['days'] ?? 0), 0) * 86400,
                ];
            } else {
                throw new \RuntimeException('充值卡密类型无效');
            }

            $updateCard = $db->prepare(
                'UPDATE card SET use_uid = :use_uid, use_time = :use_time, updated_at = :updated_at '
                . 'WHERE id = :id AND status = 1 AND use_uid = 0'
            );
            $updateCard->execute([
                ':use_uid' => $uid,
                ':use_time' => $now,
                ':updated_at' => $now,
                ':id' => (int) $card['id'],
            ]);
            if ($updateCard->rowCount() !== 1) {
                throw new \RuntimeException('充值卡密已被其他请求使用');
            }

            $updateUser = $db->prepare('UPDATE `user` SET balance = :balance, settings = :settings WHERE id = :id');
            $updateUser->execute([
                ':balance' => $afterBalance,
                ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => $uid,
            ]);
            if ($updateUser->rowCount() !== 1) {
                throw new \RuntimeException('商户账户更新失败');
            }

            $orderId = 'CARD-' . (int) $card['id'] . '-' . $now . '-' . bin2hex(random_bytes(4));
            $remark = $valueType === 1
                ? '充值卡兑换余额 ' . $orderId
                : '充值卡兑换套餐 ' . (string) ($meal['name'] ?? '');
            $recharge = $db->prepare(
                'INSERT INTO recharge (uid, order_id, amount, status, remark, pay_type, payment_order_id, paid_at, created_at, updated_at) '
                . 'VALUES (:uid, :order_id, :amount, 2, :remark, \'card\', \'\', :paid_at, :created_at, :updated_at)'
            );
            $recharge->execute([
                ':uid' => $uid,
                ':order_id' => $orderId,
                ':amount' => $valueType === 1 ? $value : 0,
                ':remark' => $remark,
                ':paid_at' => $now,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $log = $db->prepare(
                'INSERT INTO balance_log (uid, type, amount, before_balance, after_balance, remark, created_at) '
                . 'VALUES (:uid, :type, :amount, :before_balance, :after_balance, :remark, :created_at)'
            );
            $log->execute([
                ':uid' => $uid,
                ':type' => 'recharge_card',
                ':amount' => $valueType === 1 ? $value : 0,
                ':before_balance' => $beforeBalance,
                ':after_balance' => $afterBalance,
                ':remark' => $remark,
                ':created_at' => $now,
            ]);

            $rebate = (new RebateService())->settleTopUp($db, $uid, $valueType === 1 ? $value : 0, $orderId, $now);
            if ($ownsTransaction) {
                $db->commit();
            }
            return [
                'card_id' => (int) $card['id'],
                'order_id' => $orderId,
                'value_type' => $valueType,
                'amount' => $valueType === 1 ? $value : 0,
                'balance' => $afterBalance,
                'meal' => $settings['meal'] ?? null,
                'rebate' => $rebate,
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function decodeSettings(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
