<?php

declare(strict_types=1);

namespace XArrPay\Support;

/**
 * Go xarr-pay/utility.GenerateSignField/GenerateSignMap 的 PHP 等价实现。
 *
 * Go 逻辑使用 json 字段名，过滤 sign/sign_type 和零值，按字段名排序，
 * 拼接 key=value&key=value，再追加通信密钥并计算 MD5。
 */
final class ReportSigner
{
    /** @param list<string> $fields */
    public static function sign(array $params, string $key, array $fields): string
    {
        $values = [];
        foreach ($fields as $field) {
            if ($field === 'sign' || $field === 'sign_type' || !array_key_exists($field, $params)) {
                continue;
            }
            $value = $params[$field];
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                throw new \InvalidArgumentException('报告签名字段不能是数组或对象: ' . $field);
            }
            if ($value === 0 || $value === 0.0) {
                continue;
            }
            $values[$field] = (string) $value;
        }

        ksort($values, SORT_STRING);
        $content = [];
        foreach ($values as $field => $value) {
            $content[] = $field . '=' . $value;
        }
        return md5(implode('&', $content) . $key);
    }

    /** @param list<string> $fields */
    public static function valid(array $params, string $key, array $fields): bool
    {
        $provided = strtolower(trim((string) ($params['sign'] ?? '')));
        return $provided !== '' && hash_equals(self::sign($params, $key, $fields), $provided);
    }
}
