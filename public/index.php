<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

(new XArrPay\Application())->run();
