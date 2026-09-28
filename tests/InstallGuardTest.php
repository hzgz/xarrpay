<?php

declare(strict_types=1);

function installGuardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{status:int,location:string,body:string} */
function installGuardRequest(string $base, string $path): array
{
    $handle = curl_init($base . $path);
    installGuardAssert($handle !== false, 'curl 初始化失败');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
    ]);
    $raw = curl_exec($handle);
    installGuardAssert($raw !== false, '请求失败：' . curl_error($handle));
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $headers = substr($raw, 0, $headerSize);
    preg_match('/^Location:\s*([^\r\n]+)/mi', $headers, $matches);
    return [
        'status' => $status,
        'location' => trim((string) ($matches[1] ?? '')),
        'body' => substr($raw, $headerSize),
    ];
}

$root = dirname(__DIR__);
$lockPath = $root . '/var/install-guard-' . bin2hex(random_bytes(8)) . '.lock';
$port = random_int(21080, 21999);
$process = null;
$pipes = [];
@unlink($lockPath);

putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);

try {
    $process = proc_open(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t public public/router.php',
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
    );
    installGuardAssert(is_resource($process), '无法启动 PHP 测试服务');
    $base = 'http://127.0.0.1:' . $port;

    $ready = false;
    for ($index = 0; $index < 30; $index++) {
        try {
            $response = installGuardRequest($base, '/api/health');
            if (($response['status'] ?? 0) === 302) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
        }
        usleep(100000);
    }
    installGuardAssert($ready, '未安装状态未拦截动态请求');
    installGuardAssert($response['location'] === '/install', '未安装状态跳转地址错误');
    foreach (['/', '/admin', '/api/health', '/anything'] as $path) {
        $blocked = installGuardRequest($base, $path);
        installGuardAssert(
            $blocked['status'] === 302 && $blocked['location'] === '/install',
            '未安装状态入口未统一跳转安装：' . $path,
        );
    }

    $installPage = installGuardRequest($base, '/install/');
    installGuardAssert($installPage['status'] === 200, '未安装状态安装页不可访问');
    $installAsset = installGuardRequest($base, '/install/static/js/index-8f34504c.js');
    installGuardAssert($installAsset['status'] === 200, '未安装状态安装资源不可访问');

    file_put_contents($lockPath, json_encode([
        'version' => 1,
        'installed_at' => gmdate('c'),
        'admin' => 'admin',
        'username' => 'install-guard',
    ], JSON_UNESCAPED_SLASHES));
    $home = installGuardRequest($base, '/');
    installGuardAssert($home['status'] === 200, '安装锁生效后首页不可访问');
    $lockedInstall = installGuardRequest($base, '/install');
    installGuardAssert(
        $lockedInstall['status'] === 302 && $lockedInstall['location'] === '/',
        '安装锁生效后安装页未回首页',
    );

    @unlink($lockPath);
    foreach (['/', '/admin', '/api/health', '/anything'] as $path) {
        $unlocked = installGuardRequest($base, $path);
        installGuardAssert(
            $unlocked['status'] === 302 && $unlocked['location'] === '/install',
            '删除安装锁后入口未重新跳转安装：' . $path,
        );
    }
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
    @unlink($lockPath);
}

echo "InstallGuardTest: OK\n";
