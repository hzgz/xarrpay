<?php

declare(strict_types=1);

namespace XArrPay\Support;

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, string $message = '获取成功'): never
    {
        self::json([
            'code' => 200,
            'message' => $message,
            'data' => $data,
            'redirect' => '',
        ]);
    }

    public static function error(string $message, int $status = 400, int $code = 400): never
    {
        self::json([
            'code' => $code,
            'message' => $message,
            'data' => null,
            'redirect' => '',
        ], $status);
    }
}
