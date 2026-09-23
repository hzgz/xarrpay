<?php

declare(strict_types=1);

namespace XArrPay\Http;

final class Request
{
    /** @return array<string, mixed> */
    public function all(): array
    {
        $body = file_get_contents('php://input') ?: '';
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : [];

        return array_replace($_GET, $_POST, $json);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        if ($value === null && strcasecmp($name, 'Authorization') === 0) {
            $value = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }
        return is_string($value) ? $value : null;
    }
}
