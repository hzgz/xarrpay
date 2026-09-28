<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function storageOrphanAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function storageOrphanRequest(string $base, string $path, string $method = 'GET', array $data = []): array
{
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('curl 初始化失败');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: storage-orphan-test-token'],
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
    ];
    if ($data !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($body === false) {
        throw new RuntimeException('HTTP 请求失败：' . $error);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("{$method} {$path} 返回非 JSON {$status}: " . substr($body, 0, 200));
    }
    $decoded['_http_status'] = $status;
    return $decoded;
}

$dbPath = tempnam(sys_get_temp_dir(), 'xarr-storage-orphan-');
if ($dbPath === false) {
    throw new RuntimeException('无法创建测试数据库');
}

$uploadRoot = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
$testDir = $uploadRoot . DIRECTORY_SEPARATOR . 'storage-orphan-test-' . bin2hex(random_bytes(4));
$knownPath = $testDir . DIRECTORY_SEPARATOR . 'known.txt';
$orphanPath = $testDir . DIRECTORY_SEPARATOR . 'orphan.txt';
$outsidePath = $root . DIRECTORY_SEPARATOR . 'storage-orphan-outside-' . bin2hex(random_bytes(4)) . '.txt';
$lockPath = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '.storage-orphan-' . bin2hex(random_bytes(4)) . '.lock';
$process = null;
$pipes = [];

try {
    putenv('XARR_DB_DSN=sqlite:' . $dbPath);
    putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);
    file_put_contents($lockPath, json_encode([
        'version' => 1,
        'installed_at' => gmdate('c'),
        'admin' => 'admin',
        'username' => 'storage-orphan-test',
    ], JSON_UNESCAPED_SLASHES));
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec((string) file_get_contents($root . '/database/schema.sqlite.sql'));
    $now = time();
    $db->prepare('INSERT INTO staff (id,username,password,name,status,token) VALUES (1,:username,:password,:name,1,:token)')
        ->execute([
            ':username' => 'storage-orphan-test',
            ':password' => md5('123456'),
            ':name' => '存储清理测试',
            ':token' => 'storage-orphan-test-token',
        ]);

    mkdir($testDir, 0775, true);
    file_put_contents($knownPath, 'known');
    file_put_contents($orphanPath, 'orphan');
    file_put_contents($outsidePath, 'outside');
    $db->prepare('INSERT INTO upload_file (uid,scope,path,url,name,mime,size,status,created_at) VALUES (0,:scope,:path,:url,:name,:mime,:size,1,:created_at)')
        ->execute([
            ':scope' => 'images',
            ':path' => realpath($knownPath),
            ':url' => '/uploads/' . basename($testDir) . '/known.txt',
            ':name' => 'known.txt',
            ':mime' => 'text/plain',
            ':size' => filesize($knownPath),
            ':created_at' => $now,
        ]);

    $port = random_int(19080, 19999);
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t public public/router.php',
        $descriptor,
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动 PHP 测试服务');
    }

    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($index = 0; $index < 30; $index++) {
        try {
            $health = storageOrphanRequest($base, '/api/health');
            if ((int) ($health['code'] ?? 0) === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    storageOrphanAssert($ready, 'PHP 测试服务未启动');

    $scan = storageOrphanRequest($base, '/api/admin/storage/file/orphan/scan');
    storageOrphanAssert((int) ($scan['code'] ?? 0) === 200, '孤立文件扫描失败');
    $orphans = $scan['data']['orphans'] ?? [];
    storageOrphanAssert(is_array($orphans), '孤立文件列表格式错误');
    $paths = array_map(static fn (mixed $row): string => is_array($row) ? (string) ($row['path'] ?? '') : '', $orphans);
    storageOrphanAssert(in_array('/uploads/' . basename($testDir) . '/orphan.txt', $paths, true), '真实孤立文件未被扫描');
    storageOrphanAssert(!in_array('/uploads/' . basename($testDir) . '/known.txt', $paths, true), '已登记文件被误报为孤立文件');

    $remove = storageOrphanRequest($base, '/api/admin/storage/file/orphan-delete', 'POST', [
        'orphans[]' => '/uploads/' . basename($testDir) . '/orphan.txt',
    ]);
    storageOrphanAssert((int) ($remove['code'] ?? 0) === 200, '孤立文件清理失败');
    storageOrphanAssert((int) ($remove['data']['removed'] ?? 0) === 1, '孤立文件清理数量错误');
    storageOrphanAssert(!is_file($orphanPath), '孤立文件清理后仍存在');
    storageOrphanAssert(is_file($knownPath), '已登记文件被错误删除');

    $invalid = storageOrphanRequest($base, '/api/admin/storage/file/orphan-delete', 'POST', [
        'orphans[]' => $outsidePath,
    ]);
    storageOrphanAssert((int) ($invalid['code'] ?? 0) === 200, '越界路径请求不应导致服务异常');
    storageOrphanAssert((int) ($invalid['data']['removed'] ?? 0) === 0, '越界路径不应被删除');
    storageOrphanAssert(is_file($outsidePath), '越界路径文件被错误删除');

    echo "StorageOrphanTest: OK\n";
} finally {
    if (is_resource($process)) {
        $status = proc_get_status($process);
        if (PHP_OS_FAMILY === 'Windows' && (int) ($status['pid'] ?? 0) > 0) {
            exec('taskkill /PID ' . (int) $status['pid'] . ' /T /F 2>NUL');
        } else {
            proc_terminate($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    if (is_file($knownPath)) {
        unlink($knownPath);
    }
    if (is_file($orphanPath)) {
        unlink($orphanPath);
    }
    if (is_file($outsidePath)) {
        unlink($outsidePath);
    }
    if (is_dir($testDir)) {
        @rmdir($testDir);
    }
    @rmdir($uploadRoot);
    @unlink($dbPath);
    @unlink($lockPath);
}
