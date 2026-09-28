<?php

declare(strict_types=1);

$zxingFunctions = dirname(__DIR__) . '/vendor/qrcode-detector-decoder/lib/Common/customFunctions.php';
require_once $zxingFunctions;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Zxing\\')) {
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen('Zxing\\')));
        $file = dirname(__DIR__) . '/vendor/qrcode-detector-decoder/lib/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }

    $prefix = 'XArrPay\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});
