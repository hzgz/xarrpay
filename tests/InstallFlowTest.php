<?php

declare(strict_types=1);

function installAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function installCliRequest(array $env, string $method, string $uri, array $payload = []): array
{
    $runner = <<<'PHP'
$root = getenv('XARR_TEST_ROOT');
if (!is_string($root) || $root === '') {
    fwrite(STDERR, "missing XARR_TEST_ROOT\n");
    exit(1);
}
require $root . '/src/bootstrap.php';
$_SERVER['REQUEST_METHOD'] = getenv('XARR_TEST_METHOD') ?: 'GET';
$_SERVER['REQUEST_URI'] = getenv('XARR_TEST_URI') ?: '/';
$_GET = [];
$_POST = [];
$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
if (is_string($query) && $query !== '') {
    parse_str($query, $_GET);
}
$body = getenv('XARR_TEST_BODY') ?: '';
$decoded = json_decode($body, true);
if (is_array($decoded)) {
    $_POST = $decoded;
}
(new XArrPay\Application())->run();
PHP;

    $requestEnv = $env;
    $requestEnv['XARR_TEST_METHOD'] = $method;
    $requestEnv['XARR_TEST_URI'] = $uri;
    $requestEnv['XARR_TEST_BODY'] = $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $runnerPath = tempnam($env['XARR_TEST_ROOT'] . '/var', 'install-runner-');
    installAssert(is_string($runnerPath), 'cannot create request runner');
    file_put_contents($runnerPath, "<?php\n" . $runner);

    $process = proc_open([PHP_BINARY, $runnerPath], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $env['XARR_TEST_ROOT'], $requestEnv);
    installAssert(is_resource($process), 'cannot start request process');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($runnerPath);
    installAssert($exitCode === 0, 'request process failed: ' . $stderr);

    $json = json_decode(is_string($stdout) ? $stdout : '', true);
    return [
        'body' => is_string($stdout) ? $stdout : '',
        'json' => is_array($json) ? $json : null,
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

$root = dirname(__DIR__);
$id = bin2hex(random_bytes(8));
$dbPath = $root . '/var/install-test-' . $id . '.sqlite';
$envPath = $root . '/var/install-test-' . $id . '.env';
$statePath = $root . '/var/install-test-' . $id . '-state.json';
$lockPath = $root . '/var/install-test-' . $id . '.lock';

@unlink($dbPath);
@unlink($envPath);
@unlink($statePath);
@unlink($lockPath);

$env = getenv();
installAssert(is_array($env), 'cannot read environment');
$env['XARR_TEST_ROOT'] = $root;
$env['XARR_DB_DSN'] = 'sqlite:' . $dbPath;
$env['XARR_INSTALL_ENV_FILE'] = $envPath;
$env['XARR_INSTALL_STATE_FILE'] = $statePath;
$env['XARR_INSTALL_LOCK_FILE'] = $lockPath;
$env['XARR_ENV'] = 'test';

try {
    $progress = installCliRequest($env, 'GET', '/api/install/progress');
    installAssert(($progress['json']['code'] ?? 0) === 200, 'progress failed: ' . $progress['body'] . $progress['stderr']);
    installAssert(($progress['json']['data']['current_step'] ?? '') === 'hello', 'initial step mismatch');

    $invalidDatabase = installCliRequest($env, 'POST', '/api/install/database', []);
    installAssert(($invalidDatabase['json']['code'] ?? 0) === 400, 'invalid database config must return a Chinese JSON error');

    $authorize = installCliRequest($env, 'POST', '/api/install/authorize', ['license' => 'LOCAL-INSTALL-TEST']);
    installAssert(($authorize['json']['code'] ?? 0) === 200, 'authorize failed');

    $database = installCliRequest($env, 'POST', '/api/install/database', [
        'driver' => 'sqlite',
        'dsn' => 'sqlite:' . $dbPath,
    ]);
    installAssert(($database['json']['code'] ?? 0) === 200, 'database save failed: ' . $database['body']);
    installAssert(is_file($envPath) && str_contains((string) file_get_contents($envPath), 'XARR_DB_DSN='), 'env file was not written');

    $init = installCliRequest($env, 'GET', '/api/install/init');
    installAssert(str_contains($init['body'], 'event: done'), 'init did not emit done event: ' . $init['body']);

    $basic = installCliRequest($env, 'POST', '/api/install/basic', [
        'web_title' => '安装测试站',
        'admin_path' => 'admin',
        'username' => 'admin_test',
        'password' => 'install-pass-123',
        'email' => 'admin@example.com',
    ]);
    installAssert(($basic['json']['code'] ?? 0) === 200, 'basic save failed: ' . $basic['body']);

    $end = installCliRequest($env, 'POST', '/api/install/end');
    installAssert(($end['json']['code'] ?? 0) === 200, 'end failed');
    installAssert(($end['json']['data']['username'] ?? '') === 'admin_test', 'end result mismatch');

    $repeat = installCliRequest($env, 'POST', '/api/install/database', [
        'driver' => 'sqlite',
        'dsn' => 'sqlite:' . $dbPath,
    ]);
    installAssert(($repeat['json']['code'] ?? 0) === 409, 'repeat install must be rejected');
    installAssert(str_contains($repeat['body'], '系统已安装'), 'repeat install message mismatch');

    $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    installAssert((int) $db->query('SELECT COUNT(*) FROM staff')->fetchColumn() === 1, 'admin row missing');
    installAssert((string) $db->query("SELECT value FROM options WHERE `key`='web_title'")->fetchColumn() === '安装测试站', 'web_title option mismatch');
    installAssert((int) $db->query('SELECT COUNT(*) FROM pay_type')->fetchColumn() === 2, 'default pay types missing');
    installAssert((int) $db->query('SELECT COUNT(*) FROM user')->fetchColumn() === 0, 'installer must not create fake merchants');
    $db = null;
} finally {
    @unlink($dbPath);
    @unlink($envPath);
    @unlink($statePath);
    @unlink($lockPath);
}

echo "InstallFlowTest: OK\n";
