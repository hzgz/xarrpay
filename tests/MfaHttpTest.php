<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use XArrPay\Support\Totp;

function httpMfaEnsureCaptchaFont(): void
{
    if ((string) getenv('XARR_CAPTCHA_FONT') !== '') {
        return;
    }
    foreach ([
        'C:\\Windows\\Fonts\\arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    ] as $font) {
        if (is_file($font)) {
            putenv('XARR_CAPTCHA_FONT=' . $font);
            return;
        }
    }
}

function httpMfaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function httpJson(string $base, string $path, string $method = 'GET', array $data = [], ?string $cookieFile = null, array $headers = []): array
{
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('curl init failed');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT_MS => 300,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ];
    if ($cookieFile !== null) {
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
    }
    if ($data !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    if ($raw === false) {
        throw new RuntimeException('HTTP failed: ' . $error);
    }
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("{$method} {$path} returned non-json {$status}: " . substr($body, 0, 200));
    }
    $decoded['_http_status'] = $status;
    return $decoded;
}

function httpCaptchaCode(string $cookieFile, string $sessionPath): string
{
    $cookie = file_get_contents($cookieFile) ?: '';
    if (preg_match('/xarr_php\s+([^\s;]+)/', $cookie, $matches) !== 1) {
        throw new RuntimeException('captcha cookie missing');
    }
    foreach ([$sessionPath, (string) ini_get('session.save_path'), sys_get_temp_dir(), 'C:\\Windows\\Temp'] as $directory) {
        $file = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $matches[1];
        if (!is_file($file)) {
            continue;
        }
        $contents = file_get_contents($file) ?: '';
        if (preg_match('/s:4:"code";s:\d+:"([^"]+)"/', $contents, $code) === 1) {
            return $code[1];
        }
    }
    throw new RuntimeException('captcha code not found in session');
}

$dbPath = tempnam(sys_get_temp_dir(), 'xarr-mfa-http-');
$cookieFile = tempnam(sys_get_temp_dir(), 'xarr-mfa-cookie-');
$sessionPath = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '.mfa-http-session-' . bin2hex(random_bytes(4));
$lockPath = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '.mfa-http-' . bin2hex(random_bytes(4)) . '.lock';
if ($dbPath === false || $cookieFile === false || !mkdir($sessionPath, 0700, true)) {
    throw new RuntimeException('cannot create temp files');
}
$dsn = 'sqlite:' . $dbPath;
putenv('XARR_DB_DSN=' . $dsn);
putenv('XARR_INSTALL_LOCK_FILE=' . $lockPath);
file_put_contents($lockPath, json_encode([
    'version' => 1,
    'installed_at' => gmdate('c'),
    'admin' => 'admin',
    'username' => 'mfa-http',
], JSON_UNESCAPED_SLASHES));
httpMfaEnsureCaptchaFont();
$secret = 'JBSWY3DPEHPK3PXP';
$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec((string) file_get_contents($root . '/database/schema.sqlite.sql'));
$now = time();
$db->prepare('INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at,mfa_enabled,mfa_secret) VALUES (1,:username,:password,:merchant_name,:app_secret,1,0,:created_at,1,:mfa_secret)')
    ->execute([':username' => 'mfa-http', ':password' => md5('123456'), ':merchant_name' => 'MFA HTTP', ':app_secret' => 'mfa-http-key', ':created_at' => $now, ':mfa_secret' => $secret]);

$port = random_int(18080, 18999);
$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$php = escapeshellarg(PHP_BINARY);
$sessionConfig = ' -d ' . escapeshellarg('session.save_path=' . $sessionPath) . ' -d session.name=xarr_php';
$process = proc_open($php . $sessionConfig . ' -S 127.0.0.1:' . $port . ' -t public public/router.php', $descriptor, $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('cannot start built-in server');
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

try {
    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($index = 0; $index < 30; $index++) {
        try {
            $health = httpJson($base, '/api/health', 'GET', [], $cookieFile);
            $ready = (int) ($health['code'] ?? 0) === 200;
            if ($ready) {
                break;
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    if (!$ready) {
        $stderr = stream_get_contents($pipes[2]) ?: '';
        $stdout = stream_get_contents($pipes[1]) ?: '';
        throw new RuntimeException('built-in server did not become ready: ' . trim($stderr . "\n" . $stdout));
    }

    $captcha = httpJson($base, '/api/login/captcha', 'GET', [], $cookieFile);
    $captchaCode = httpCaptchaCode($cookieFile, $sessionPath);
    $login = httpJson($base, '/api/login', 'POST', [
        'username' => 'mfa-http',
        'password' => '123456',
        'captcha_id' => $captcha['data']['captcha_id'],
        'captcha_code' => $captchaCode,
    ], $cookieFile);
    httpMfaAssert((int) ($login['code'] ?? 0) === 200, 'MFA login first step code mismatch');
    httpMfaAssert((int) ($login['data']['status'] ?? 0) === 2, 'MFA login must return status=2');
    $ticket = (string) ($login['data']['data']['ticket'] ?? '');
    httpMfaAssert($ticket !== '', 'MFA login ticket missing');
    httpMfaAssert(($login['data']['data']['captcha_types'] ?? []) === ['totp'], 'MFA captcha types mismatch');

    $generated = httpJson($base, '/api/captcha/gen', 'POST', ['ticket' => $ticket, 'captcha_type' => 'totp'], $cookieFile);
    httpMfaAssert((string) ($generated['data']['list'][0]['captcha_type'] ?? '') === 'mfa', 'login captcha must use MFA input component');

    $code = Totp::code($secret);
    $valid = httpJson($base, '/api/captcha/valid', 'POST', ['ticket' => $ticket, 'captcha_id' => $ticket, 'captcha_code' => $code], $cookieFile);
    httpMfaAssert((int) ($valid['code'] ?? 0) === 200, 'captcha valid failed');

    $finish = httpJson($base, '/api/login/safe', 'POST', ['ticket' => $ticket, 'captcha_id' => $ticket, 'captcha_code' => $code], $cookieFile);
    httpMfaAssert((int) ($finish['code'] ?? 0) === 200, 'MFA login finish failed');
    $token = (string) ($finish['data']['data']['token'] ?? '');
    httpMfaAssert($token !== '', 'MFA login finish token missing');

    $reuse = httpJson($base, '/api/login/safe', 'POST', ['ticket' => $ticket, 'captcha_code' => $code], $cookieFile);
    httpMfaAssert((int) ($reuse['code'] ?? 0) === 401, 'MFA login ticket reuse must fail');
    $profile = httpJson($base, '/api/user/profile', 'GET', [], $cookieFile, ['Authorization: ' . $token]);
    httpMfaAssert((int) ($profile['data']['mfa_enable'] ?? 0) === 1, 'profile mfa_enable mismatch');
} finally {
    $status = is_resource($process) ? proc_get_status($process) : [];
    if (PHP_OS_FAMILY === 'Windows' && (int) ($status['pid'] ?? 0) > 0) {
        exec('taskkill /PID ' . (int) $status['pid'] . ' /T /F 2>NUL');
    } elseif (is_resource($process)) {
        proc_terminate($process);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        proc_close($process);
    }
    @unlink($dbPath);
    @unlink($cookieFile);
    @unlink($lockPath);
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $sessionFile) {
        @unlink($sessionFile);
    }
    @rmdir($sessionPath);
}

echo "MfaHttpTest: OK\n";
