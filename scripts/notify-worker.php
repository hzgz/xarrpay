<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;
use XArrPay\Support\OrderNotifier;

$options = getopt('', ['once', 'limit:', 'sleep:', 'worker-id:']);
$once = array_key_exists('once', $options);
$limit = max(1, (int) ($options['limit'] ?? 100));
$sleep = max(1, (int) ($options['sleep'] ?? 2));
$workerId = (string) ($options['worker-id'] ?? gethostname() . '-' . getmypid());
$notifier = new OrderNotifier();
$handled = 0;
$idle = 0;

while ($handled < $limit) {
    try {
        $db = Database::connection();
        $task = $notifier->claim($db, $workerId);
        if ($task === null) {
            if ($once) {
                break;
            }
            sleep($sleep);
            continue;
        }
        $idle = 0;
        try {
            $result = $notifier->deliver($task);
        } catch (Throwable $exception) {
            $result = [
                'success' => false,
                'skipped' => false,
                'http_status' => 0,
                'response' => '',
                'message' => substr($exception->getMessage(), 0, 1000),
            ];
        }
        $notifier->finish($db, $task, $result);
        $handled++;
        fwrite(STDOUT, sprintf(
            "task=%d attempt=%d result=%s message=%s\n",
            (int) $task['id'],
            (int) $task['attempts'],
            ($result['success'] ?? false) ? 'success' : 'failure',
            str_replace(["\r", "\n"], ' ', (string) ($result['message'] ?? ''))
        ));
    } catch (Throwable $exception) {
        fwrite(STDERR, 'worker error: ' . $exception->getMessage() . PHP_EOL);
        if ($once) {
            exit(1);
        }
        sleep(min($sleep, 10));
        $idle++;
        if ($idle >= 10) {
            exit(1);
        }
    }
    if ($once) {
        break;
    }
}

fwrite(STDOUT, "handled={$handled} worker={$workerId}\n");
