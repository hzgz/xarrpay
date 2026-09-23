<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use XArrPay\Support\Database;
use XArrPay\Support\PluginRuntime;
use XArrPay\Support\Response;

final class HealthController
{
    public function timestamp(): never
    {
        Response::success(time());
    }

    public function health(): never
    {
        $database = 'ok';
        try {
            Database::connection()->query('SELECT 1');
        } catch (\Throwable) {
            $database = 'error';
        }

        Response::json([
            'code' => $database === 'ok' ? 200 : 503,
            'message' => $database === 'ok' ? 'ok' : 'database unavailable',
            'data' => [
                'database' => $database,
                'plugin_dir' => PluginRuntime::directory(),
                'plugin_count' => count(PluginRuntime::manifests()),
            ],
            'redirect' => '',
        ], $database === 'ok' ? 200 : 503);
    }
}
