<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\QrCode;

function qrDecodeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{status:int,body:string} */
function qrDecodeRequest(string $base, array $fields = []): array
{
    $handle = curl_init($base . '/api/qrcode/decode');
    qrDecodeAssert($handle !== false, '二维码接口 curl 初始化失败');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    qrDecodeAssert($body !== false, '二维码接口请求失败: ' . $error);
    return ['status' => $status, 'body' => (string) $body];
}

function qrDecodeJson(array $response): array
{
    $decoded = json_decode($response['body'], true);
    qrDecodeAssert(is_array($decoded), '二维码接口返回非 JSON: ' . substr($response['body'], 0, 300));
    return $decoded;
}

function qrDecodePng(string $path, string $content): void
{
    $qr = QrCode::encode($content);
    $scale = 8;
    $border = 4;
    $size = ($qr['size'] + $border * 2) * $scale;
    $image = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $white);
    foreach ($qr['modules'] as $row => $modules) {
        foreach ($modules as $column => $dark) {
            if (!$dark) {
                continue;
            }
            $left = ($column + $border) * $scale;
            $top = ($row + $border) * $scale;
            imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
        }
    }
    imagepng($image, $path);
    imagedestroy($image);
}

$root = dirname(__DIR__);
$lockPath = tempnam(sys_get_temp_dir(), 'xarr-qrcode-lock-');
$dbPath = tempnam(sys_get_temp_dir(), 'xarr-qrcode-db-');
$pngPath = tempnam(sys_get_temp_dir(), 'xarr-qrcode-image-') . '.png';
$blankPath = tempnam(sys_get_temp_dir(), 'xarr-qrcode-blank-') . '.png';
$textPath = tempnam(sys_get_temp_dir(), 'xarr-qrcode-text-') . '.txt';
$process = null;
$pipes = [];

try {
    qrDecodeAssert($lockPath !== false && $dbPath !== false, '二维码测试临时文件创建失败');
    file_put_contents($lockPath, json_encode([
        'version' => 1,
        'installed_at' => gmdate('c'),
        'admin' => 'admin',
        'username' => 'qrcode-test',
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($dbPath, '');
    qrDecodePng($pngPath, 'https://example.com/收款?order=online-r29-final');

    $blank = imagecreatetruecolor(360, 360);
    $white = imagecolorallocate($blank, 255, 255, 255);
    imagefilledrectangle($blank, 0, 0, 359, 359, $white);
    imagepng($blank, $blankPath);
    imagedestroy($blank);
    file_put_contents($textPath, '这不是图片');

    putenv('XARR_DB_DSN=sqlite:' . $dbPath);
    putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);

    $port = random_int(23080, 23999);
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
    qrDecodeAssert(is_resource($process), '二维码测试服务启动失败');
    $base = 'http://127.0.0.1:' . $port;

    $ready = false;
    for ($index = 0; $index < 30; $index++) {
        $probe = curl_init($base . '/api/qrcode/decode');
        if ($probe !== false) {
            curl_setopt_array($probe, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [],
                CURLOPT_TIMEOUT_MS => 300,
            ]);
            curl_exec($probe);
            $probeStatus = (int) curl_getinfo($probe, CURLINFO_RESPONSE_CODE);
            curl_close($probe);
            if ($probeStatus > 0) {
                $ready = true;
                break;
            }
        }
        usleep(100000);
    }
    qrDecodeAssert($ready, '二维码测试服务未启动');

    $missing = qrDecodeJson(qrDecodeRequest($base));
    qrDecodeAssert(($missing['code'] ?? 0) === 422 && str_contains((string) ($missing['message'] ?? ''), '上传'), '缺少二维码图片未返回中文错误');

    $notImage = qrDecodeJson(qrDecodeRequest($base, [
        'file' => new CURLFile($textPath, 'text/plain', 'not-image.txt'),
    ]));
    qrDecodeAssert(($notImage['code'] ?? 0) === 422 && str_contains((string) ($notImage['message'] ?? ''), '图片'), '非图片文件未被拒绝');

    $noCode = qrDecodeJson(qrDecodeRequest($base, [
        'file' => new CURLFile($blankPath, 'image/png', 'blank.png'),
    ]));
    qrDecodeAssert(($noCode['code'] ?? 0) === 422 && str_contains((string) ($noCode['message'] ?? ''), '二维码'), '无二维码图片未返回明确错误');

    $decoded = qrDecodeJson(qrDecodeRequest($base, [
        'file' => new CURLFile($pngPath, 'image/png', 'qrcode.png'),
    ]));
    qrDecodeAssert(($decoded['code'] ?? 0) === 200, '正常二维码解析失败');
    qrDecodeAssert(($decoded['message'] ?? '') === '解析成功', '正常二维码解析成功消息错误');
    qrDecodeAssert(($decoded['data'] ?? '') === 'https://example.com/收款?order=online-r29-final', '二维码内容回填值错误');

    echo "QrCodeDecodeTest: OK\n";
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
    if (is_string($lockPath)) {
        @unlink($lockPath);
    }
    if (is_string($dbPath)) {
        @unlink($dbPath);
    }
    @unlink($pngPath);
    @unlink($blankPath);
    @unlink($textPath);
    putenv('XARR_DB_DSN');
    putenv('XARR_INSTALL_LOCK_FILE');
}
