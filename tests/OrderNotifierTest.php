<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;
use XArrPay\Support\OrderNotifier;

$path = tempnam(sys_get_temp_dir(), 'xarr-notify-');
if ($path === false) {
    throw new RuntimeException('无法创建临时 SQLite 数据库');
}
putenv('XARR_DB_DSN=sqlite:' . $path);
putenv('XARR_NOTIFY_MAX_ATTEMPTS=2');
try {
    $db = Database::connection();
    $db->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
    $db->exec('INSERT INTO "order" (id, order_id, out_order_id, uid, created_at, updated_at) VALUES (1, "NT-1", "OUT-1", 7, ' . time() . ', ' . time() . ')');

    $notifier = new OrderNotifier();
    foreach ([
        'http://127.0.0.1/callback',
        'http://10.0.0.1/callback',
        'http://169.254.169.254/latest/meta-data/',
        'http://[::1]/callback',
        'http://[::ffff:127.0.0.1]/callback',
        'http://2130706433/callback',
        'http://0x7f000001/callback',
        'http://user:pass@example.com/callback',
    ] as $blockedUrl) {
        $delivery = $notifier->deliver(['url' => $blockedUrl, 'payload' => '']);
        assertTrue(($delivery['skipped'] ?? false) === true, 'blocks unsafe callback URL: ' . $blockedUrl);
    }

    $order = [
        'id' => 1,
        'order_id' => 'NT-1',
        'out_order_id' => 'OUT-1',
        'uid' => 7,
        'trade_amount' => 125,
        'status' => 2,
        'notify_uri' => 'http://127.0.0.1/callback',
        'pay_type' => 'alipay',
        'subject' => 'test',
    ];
    $merchant = ['id' => 7, 'app_secret' => 'test-secret'];
    $first = $notifier->enqueue($db, $order, $merchant);
    $second = $notifier->enqueue($db, $order, $merchant);
    assertTrue($first['created'] === true, 'first enqueue creates row');
    assertTrue($second['created'] === false && $first['id'] === $second['id'], 'duplicate enqueue reuses row');
    assertTrue((int) $db->query('SELECT COUNT(*) FROM notify_queue')->fetchColumn() === 1, 'unique order task');

    $task = $notifier->claim($db, 'test-worker', 30);
    assertTrue(is_array($task) && $task['status'] === 'processing', 'claim gets task');
    assertTrue($notifier->claim($db, 'other-worker', 30) === null, 'lease blocks second claim');
    $notifier->finish($db, $task, ['success' => true, 'skipped' => false, 'http_status' => 200, 'response' => 'success', 'message' => 'ok']);
    assertTrue((string) $db->query('SELECT status FROM notify_queue WHERE id = ' . (int) $first['id'])->fetchColumn() === 'success', 'success is terminal');
    assertTrue((int) $db->query('SELECT COUNT(*) FROM notify_log')->fetchColumn() === 1, 'success writes log');
    assertTrue((int) $db->query('SELECT notify_status FROM "order" WHERE id = 1')->fetchColumn() === 1, 'success updates order');

    $failedOrder = $order;
    $failedOrder['order_id'] = 'NT-2';
    $failedOrder['id'] = 2;
    $db->exec('INSERT INTO "order" (id, order_id, out_order_id, uid, created_at, updated_at) VALUES (2, "NT-2", "OUT-2", 7, ' . time() . ', ' . time() . ')');
    $failed = $notifier->enqueue($db, $failedOrder, $merchant);
    $failedTask = $notifier->claim($db, 'test-worker', 30);
    assertTrue(is_array($failedTask), 'second task claim');
    $notifier->finish($db, $failedTask, ['success' => false, 'skipped' => false, 'http_status' => 503, 'response' => 'unavailable', 'message' => 'temporary']);
    $failedRow = $db->query('SELECT status, attempts, available_at FROM notify_queue WHERE id = ' . (int) $failed['id'])->fetch();
    assertTrue($failedRow['status'] === 'queued' && (int) $failedRow['attempts'] === 1, 'transient failure is requeued');
    assertTrue((int) $failedRow['available_at'] >= time() + 29, 'failure has backoff');

    $db->exec('UPDATE notify_queue SET available_at = 0 WHERE id = ' . (int) $failed['id']);
    $failedTask = $notifier->claim($db, 'test-worker', 30);
    assertTrue(is_array($failedTask), 'retry claim');
    $notifier->finish($db, $failedTask, ['success' => false, 'skipped' => false, 'http_status' => 503, 'response' => '', 'message' => 'temporary']);
    assertTrue((string) $db->query('SELECT status FROM notify_queue WHERE id = ' . (int) $failed['id'])->fetchColumn() === 'failed', 'max attempts reaches failed');
    assertTrue(($notifier->retry($db, (int) $failed['id'])['queued'] ?? false) === true, 'admin retry requeues terminal task');
    $db->exec('UPDATE notify_queue SET available_at = ' . (time() + 3600) . ' WHERE id = ' . (int) $failed['id']);

    $crashedOrder = $order;
    $crashedOrder['id'] = 3;
    $crashedOrder['order_id'] = 'NT-3';
    $db->exec('INSERT INTO "order" (id, order_id, out_order_id, uid, created_at, updated_at) VALUES (3, "NT-3", "OUT-3", 7, ' . time() . ', ' . time() . ')');
    $crashed = $notifier->enqueue($db, $crashedOrder, $merchant);
    $crashedTask = $notifier->claim($db, 'crashing-worker', 1);
    assertTrue(is_array($crashedTask), 'crash task claim');
    $db->exec('UPDATE notify_queue SET attempts = max_attempts, available_at = 0, leased_until = ' . (time() - 1) . ' WHERE id = ' . (int) $crashed['id']);
    assertTrue($notifier->claim($db, 'recovery-worker', 30) === null, 'expired final lease is not delivered again');
    assertTrue((string) $db->query('SELECT status FROM notify_queue WHERE id = ' . (int) $crashed['id'])->fetchColumn() === 'failed', 'expired final lease is terminally recovered');
    assertTrue((int) $db->query('SELECT notify_status FROM "order" WHERE id = 3')->fetchColumn() === 0, 'expired final lease updates order status');
    assertTrue((int) $db->query('SELECT COUNT(*) FROM notify_log WHERE order_id = "NT-3"')->fetchColumn() === 1, 'expired final lease writes notification log');

    echo "OrderNotifierTest: OK\n";
} finally {
    Database::connection();
    unset($db);
    @unlink($path);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}
