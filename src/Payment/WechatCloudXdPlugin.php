<?php

declare(strict_types=1);

namespace XArrPay\Payment;

use PDO;
use XArrPay\Support\PluginAccountService;

abstract class WechatCloudXdPlugin implements PaymentPlugin, RuntimePlugin
{
    abstract public function name(): string;

    public function capabilities(): array
    {
        return ['create', 'qrcode', 'login_qrcode', 'login_qrcode_check', 'cron', 'heartbeat'];
    }

    public function create(array $order, array $channel, array $account): array
    {
        $options = PluginOptions::fromAccount($account);
        $mode = (string) ($options['pay_mode'] ?? 'receipt');
        $sid = PluginOptions::requireString($options, 'sid', '请先配置微信云端 SID');
        $amount = (int) ($order['trade_amount'] ?? $order['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \RuntimeException('订单金额无效');
        }

        if ($mode === 'smallbook') {
            $response = $this->request(
                'GET',
                'https://smallbook.wxpapp.weixin.qq.com/qrappsl/profile/getpayqrcode?' . http_build_query([
                    'sid' => $sid,
                    'v' => '6.23.1',
                ], '', '&', PHP_QUERY_RFC3986),
                []
            );
            $qrcode = $this->nestedString($response, ['qrcode_content', 'qrcode']);
            if ($qrcode === '') {
                throw new \RuntimeException($this->responseMessage($response, '小账本未返回二维码'));
            }
            return $this->result($channel, $qrcode, '', (string) ($order['order_id'] ?? ''));
        }

        $aid = PluginOptions::requireString($options, 'aid', '请先配置微信云端账号 ID');
        $accountType = PluginOptions::requireString($options, 'account_type', '请先配置微信云端账号类型');
        $gateway = $this->gateway($accountType);
        $query = [
            'miniprogram_version' => '3.15.9',
            'account_id' => $aid,
            'account_type' => $accountType,
            'sid' => $sid,
        ];
        $shopId = trim((string) ($options['shop_id'] ?? ''));
        if ($shopId !== '') {
            $query['shop_id'] = $shopId;
        }
        $query['remark'] = (string) ($order['order_id'] ?? '');
        $query['fee'] = (string) $amount;

        $createUrl = $gateway . '/receipt/create';
        $createBody = null;
        if (in_array($accountType, ['1', '2'], true)) {
            $createUrl .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } else {
            $createUrl .= '?' . http_build_query([
                'miniprogram_version' => '3.15.9',
                'account_id' => $aid,
                'account_type' => $accountType,
                'sid' => $sid,
            ], '', '&', PHP_QUERY_RFC3986);
            $createBody = [
                'fee' => $amount,
                'remark' => (string) ($order['order_id'] ?? ''),
                'remark_pic_urls' => '',
                'option_list' => [],
                'receipt_item_list' => [],
                'sid' => $sid,
            ];
            if ($shopId !== '') {
                $createBody['shop_id'] = (int) $shopId;
            }
        }
        $response = $this->request('POST', $createUrl, [], $createBody);
        $receiptId = $this->nestedString($response, ['receipt_id']);
        if ($receiptId === '') {
            throw new \RuntimeException($this->responseMessage($response, '云端收款单创建失败'));
        }

        $qrResponse = $this->request('GET', $gateway . '/receipt/getwxacode?' . http_build_query([
            'miniprogram_version' => '3.15.10',
            'wxacode_path_type' => '1',
            'receipt_id' => $receiptId,
            'account_id' => $aid,
            'account_type' => $accountType,
            'sid' => $sid,
        ], '', '&', PHP_QUERY_RFC3986), []);
        $qrcode = $this->nestedString($qrResponse, ['qrcode']);
        if ($qrcode === '') {
            throw new \RuntimeException($this->responseMessage($qrResponse, '云端未返回收款二维码'));
        }

        return $this->result($channel, '', $this->imageData($qrcode), $receiptId);
    }

    public function query(array $order, array $channel, array $account): array
    {
        throw new \RuntimeException($this->name() . ' 通过云端流水定时上报结算，不提供主动订单查询');
    }

    public function heartbeat(PDO $db, array $account, int $now): array
    {
        $options = PluginOptions::fromAccount($account);
        $sid = trim((string) ($options['sid'] ?? ''));
        if ($sid === '') {
            return [
                'online' => false,
                'message' => '微信云端账号尚未完成登录',
                'last_seen_at' => 0,
            ];
        }
        $query = $db->prepare('SELECT last_seen_at,last_error FROM pay_account_ext WHERE account_id = :account_id LIMIT 1');
        $query->execute([':account_id' => (int) ($account['id'] ?? 0)]);
        $state = $query->fetch();
        $lastSeen = is_array($state) ? (int) ($state['last_seen_at'] ?? 0) : 0;
        $online = $lastSeen > 0 && $lastSeen >= $now - 900;
        return [
            'online' => $online,
            'message' => $online ? '微信云端流水任务正常' : '微信云端尚未完成最近一次流水同步',
            'last_seen_at' => $lastSeen,
            'last_error' => is_array($state) ? (string) ($state['last_error'] ?? '') : '',
        ];
    }

    public function cron(PDO $db, array $account, int $now): array
    {
        $options = PluginOptions::fromAccount($account);
        $sid = PluginOptions::requireString($options, 'sid', '请先完成微信云端登录');
        $endpoint = PluginOptions::requireString($options, 'flow_endpoint', '微信云端插件未配置流水接口地址');
        $method = strtoupper(trim((string) ($options['flow_http_method'] ?? 'GET')));
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new \RuntimeException('微信云端流水接口请求方法无效');
        }
        $payload = [
            'sid' => $sid,
            'account_id' => trim((string) ($options['aid'] ?? '')),
            'account_type' => trim((string) ($options['account_type'] ?? '')),
            'shop_id' => trim((string) ($options['shop_id'] ?? '')),
            'limit' => 100,
            'start_time' => $now - $this->window($options),
            'end_time' => $now,
        ];
        $response = $method === 'GET'
            ? $this->request('GET', $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($payload, '', '&', PHP_QUERY_RFC3986), [], null)
            : $this->request('POST', $endpoint, [], $payload);
        return [
            'flows' => $this->normalizeFlows($response, $now, $options),
            'message' => $this->name() . ' 流水同步完成',
        ];
    }

    public function parseMessage(array $message, array $account): array
    {
        throw new \RuntimeException($this->name() . ' 不接受客户端消息上报');
    }

    /** @return array<string, mixed> */
    private function result(array $channel, string $qrcode, string $qrcodeData, string $externalId): array
    {
        return [
            'status' => 'pending',
            'type' => 'qrcode',
            'qrcode_data' => $qrcodeData,
            'qrcode' => $qrcode,
            'content' => '',
            'content_copy' => false,
            'out_pay_order_id' => $externalId,
            'actual_account' => '',
            'actual_account_type' => '',
            'channel_code' => (string) ($channel['code'] ?? 'wxpay'),
            'plugin_name' => $this->name(),
        ];
    }

    private function gateway(string $accountType): string
    {
        return match ((string) $accountType) {
            '1', '2' => 'https://payapp.wechatpay.cn/receiptmdmgr',
            '3' => 'https://payapp.wechatpay.cn/receiptwxmgr',
            default => throw new \RuntimeException('不支持的微信云端账号类型'),
        };
    }

    /** @return array<string, mixed> */
    /** @param array<string, string> $headers @param array<string, mixed>|null $json */
    private function request(string $method, string $url, array $headers, ?array $json = null): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('无法初始化云端 HTTP 客户端');
        }
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $this->headerLines($headers)),
        ]);
        if ($json !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($body === false || $error !== '') {
            throw new \RuntimeException('云端请求失败: ' . ($error !== '' ? $error : 'empty response'));
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('云端响应不是 JSON');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException($this->responseMessage($decoded, '云端 HTTP ' . $status));
        }
        if (isset($decoded['errcode']) && (int) $decoded['errcode'] !== 0) {
            throw new \RuntimeException($this->responseMessage($decoded, '云端接口返回失败'));
        }
        return $decoded;
    }

    /** @param array<string, string> $headers @return list<string> */
    private function headerLines(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $result[] = $name . ': ' . $value;
        }
        return $result;
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private function nestedString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return trim((string) $data[$key]);
            }
        }
        foreach (['data', 'result', 'payload'] as $container) {
            if (is_array($data[$container] ?? null)) {
                $found = $this->nestedString($data[$container], $keys);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }

    /** @param array<string, mixed> $data */
    private function responseMessage(array $data, string $default): string
    {
        foreach (['msg', 'message', 'errmsg', 'error'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }
        return $default;
    }

    private function imageData(string $value): string
    {
        if (str_starts_with($value, 'data:image/')) {
            return $value;
        }
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        return 'data:image/png;base64,' . $value;
    }

    /** @param array<string,mixed> $response @param array<string,mixed> $options @return list<array<string,mixed>> */
    private function normalizeFlows(array $response, int $now, array $options): array
    {
        $items = [];
        $this->collectFlowItems($response, $items);
        $result = [];
        $window = $this->window($options);
        foreach ($items as $item) {
            $remark = $this->first($item, ['remark', 'memo', 'body', 'description']);
            if ($this->isExcluded($item, $remark)) {
                continue;
            }
            $time = PluginAccountService::timestamp(
                $this->first($item, ['trans_time', 'transaction_time', 'paid_at', 'time', 'create_time']),
                0,
            );
            if ($time <= 0 || $time < $now - $window || $time > $now + 60) {
                continue;
            }
            $id = $this->first($item, ['third_order_id', 'transaction_id', 'bill_id', 'id']);
            $amount = PluginAccountService::amountInCents($this->first($item, ['amount', 'receipt_amount', 'fee', 'money']));
            if ($id === '' || $amount <= 0) {
                continue;
            }
            $result[] = [
                'third_order_id' => $id,
                'amount' => $amount,
                'trans_time' => $time,
                'remark' => $remark,
                'buyer_name' => $this->first($item, ['buyer_name', 'payer', 'nickname', 'name']),
                'raw' => $item,
            ];
        }
        return $result;
    }

    /** @param array<string,mixed> $value @param list<array<string,mixed>> $items */
    private function collectFlowItems(array $value, array &$items): void
    {
        foreach ($value as $item) {
            if (is_array($item) && $this->looksLikeFlow($item)) {
                $items[] = $item;
                continue;
            }
            if (is_array($item)) {
                $this->collectFlowItems($item, $items);
            }
        }
    }

    /** @param array<string,mixed> $item */
    private function looksLikeFlow(array $item): bool
    {
        return $this->first($item, ['third_order_id', 'transaction_id', 'bill_id', 'id']) !== ''
            && $this->first($item, ['amount', 'receipt_amount', 'fee', 'money']) !== '';
    }

    /** @param array<string,mixed> $item */
    private function isExcluded(array $item, string $remark): bool
    {
        if (isset($item['bill_type']) && (int) $item['bill_type'] !== 2) {
            return true;
        }
        if (isset($item['trans_source']) && (int) $item['trans_source'] !== 1) {
            return true;
        }
        return preg_match('/退款|红包|转账|消费|充值/u', $remark) === 1;
    }

    /** @param array<string,mixed> $options */
    private function window(array $options): int
    {
        return max((int) ($options['poll_window_seconds'] ?? 600), 60);
    }

    /** @param array<string,mixed> $item @param list<string> $keys */
    private function first(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && trim((string) $item[$key]) !== '') {
                return trim((string) $item[$key]);
            }
        }
        return '';
    }
}
