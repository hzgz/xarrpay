<?php

declare(strict_types=1);

namespace XArrPay\Http;

use Closure;
use XArrPay\Support\Response;

final class Router
{
    /** @var list<array{methods: list<string>, pattern: string, handler: Closure}> */
    private array $routes = [];

    public function get(string $pattern, Closure $handler): self
    {
        return $this->match(['GET'], $pattern, $handler);
    }

    public function post(string $pattern, Closure $handler): self
    {
        return $this->match(['POST'], $pattern, $handler);
    }

    public function any(string $pattern, Closure $handler): self
    {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $pattern, $handler);
    }

    public function match(array $methods, string $pattern, Closure $handler): self
    {
        $this->routes[] = ['methods' => $methods, 'pattern' => $pattern, 'handler' => $handler];
        return $this;
    }

    public function dispatch(Request $request): never
    {
        foreach ($this->routes as $route) {
            if (!in_array($request->method(), $route['methods'], true)) {
                continue;
            }

            $regex = '#^' . preg_replace('#\{[^/]+\}#', '([^/]+)', $route['pattern']) . '/?$#';
            if (preg_match($regex, $request->path(), $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            ($route['handler'])($request, ...$matches);
            exit;
        }

        Response::error('Not Found', 404, 404);
    }
}
