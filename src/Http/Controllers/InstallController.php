<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use PDO;
use RuntimeException;
use Throwable;
use XArrPay\Http\Request;
use XArrPay\Support\Config;
use XArrPay\Support\InstallState;
use XArrPay\Support\PaymentPluginCatalog;
use XArrPay\Support\Response;

final class InstallController
{
    /** @var list<string> */
    private const STEPS = ['hello', 'authorize', 'database', 'init', 'staff', 'done'];

    public function progress(): never
    {
        $state = $this->state();
        if ($this->installed()) {
            Response::success([
                'current_step' => 'done',
                'completed_steps' => self::STEPS,
                'data' => $this->installedData(),
            ]);
        }

        Response::success([
            'current_step' => (string) ($state['current_step'] ?? 'hello'),
            'completed_steps' => $state['completed_steps'] ?? [],
            'data' => $state['data'] ?? [],
        ]);
    }

    public function authorize(Request $request): never
    {
        $this->rejectInstalled();
        $license = trim((string) $request->input('license', ''));
        if ($license === '') {
            Response::error('请输入授权码');
        }

        $state = $this->state();
        $state['license'] = $license;
        $this->completeStep($state, 'authorize', 'database');
        $this->writeState($state);

        Response::success(['current_step' => 'database'], '授权码已保存');
    }

    public function conf(): never
    {
        if ($this->installed()) {
            Response::error('系统已安装，禁止读取安装期数据库配置', 409, 409);
        }

        $state = $this->state();
        $database = is_array($state['database'] ?? null) ? $state['database'] : [];
        if ($database !== []) {
            Response::success($this->publicDatabaseConfig($database));
        }

        Response::success([
            'host' => (string) Config::get('XARR_DB_HOST', '127.0.0.1'),
            'port' => (string) Config::get('XARR_DB_PORT', '3306'),
            'username' => (string) Config::get('XARR_DB_USER', 'xarrpay'),
            'password' => '',
            'database' => (string) Config::get('XARR_DB_NAME', 'xarrpay'),
        ]);
    }

    public function database(Request $request): never
    {
        $this->rejectInstalled();
        $params = $request->all();

        try {
            $database = $this->normalizeDatabaseConfig($params);
            $db = $this->connect($database);
            $this->assertEmptyDatabase($db, $database['driver']);
            $this->writeEnv($this->envValues($database));

            $state = $this->state();
            $state['database'] = $database;
            $this->completeStep($state, 'database', 'init');
            $this->writeState($state);
        } catch (Throwable $exception) {
            Response::error('数据库配置失败：' . $exception->getMessage(), 400, 400);
        }

        Response::success(['current_step' => 'init'], '数据库配置已保存，目标库为空库');
    }

    public function init(Request $request): never
    {
        unset($request);
        $this->sseHeaders();
        try {
            if ($this->installed()) {
                throw new RuntimeException('系统已安装，禁止重复初始化');
            }
            $state = $this->state();
            if (!$this->stepCompleted($state, 'database')) {
                throw new RuntimeException('请先保存数据库配置');
            }
            $database = is_array($state['database'] ?? null) ? $state['database'] : $this->databaseFromEnv();
            if ($database === []) {
                throw new RuntimeException('缺少数据库配置');
            }

            $this->sse('log', '开始连接数据库');
            $db = $this->connect($database);
            $this->assertEmptyDatabase($db, $database['driver']);

            $this->sse('log', '开始导入数据库结构');
            $schema = $this->schema($database['driver']);
            $db->exec($schema);

            $this->sse('log', '写入系统默认选项和支付类型');
            $this->seedSystemDefaults($db);

            $this->completeStep($state, 'init', 'staff');
            $this->writeState($state);
            $this->sse('done', '数据库初始化完成');
        } catch (Throwable $exception) {
            $this->sse('error', $exception->getMessage());
        }
        exit;
    }

    public function basic(Request $request): never
    {
        $this->rejectInstalled();
        $state = $this->state();
        if (!$this->stepCompleted($state, 'init')) {
            Response::error('请先初始化数据库');
        }

        $webTitle = trim((string) $request->input('web_title', ''));
        $adminPath = trim((string) $request->input('admin_path', 'admin'));
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $email = trim((string) $request->input('email', ''));

        if ($webTitle === '') {
            Response::error('请输入站点标题');
        }
        if ($adminPath !== 'admin') {
            Response::error('当前 PHP 版本后台入口固定为 admin，请填写 admin');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{4,19}$/', $username) !== 1) {
            Response::error('管理员账号必须为 5-20 位字母、数字或下划线，且以字母开头');
        }
        $passwordLength = strlen($password);
        if ($passwordLength < 5 || $passwordLength > 32) {
            Response::error('管理员密码长度必须为 5-32 位');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Response::error('管理员邮箱格式不正确');
        }

        try {
            $database = is_array($state['database'] ?? null) ? $state['database'] : $this->databaseFromEnv();
            if ($database === []) {
                throw new RuntimeException('缺少数据库配置');
            }
            $db = $this->connect($database);
            $this->createAdmin($db, $username, $password, $email);
            $this->upsertOption($db, 'web_title', $webTitle);
            $this->upsertOption($db, 'web_index_title', $webTitle);
            $this->upsertOption($db, 'admin_path', $adminPath);
            $this->writeEnv(['XARR_ADMIN_PATH' => $adminPath]);

            $state['data'] = [
                'username' => $username,
                'admin' => $adminPath,
            ];
            $this->completeStep($state, 'staff', 'done');
            $this->completeStep($state, 'done', 'done');
            $this->writeState($state);
            InstallState::createLock([
                'username' => $username,
                'admin' => $adminPath,
            ]);
        } catch (Throwable $exception) {
            Response::error('系统信息保存失败：' . $exception->getMessage(), 400, 400);
        }

        Response::success(['username' => $username, 'admin' => $adminPath], '管理员创建成功');
    }

    public function end(): never
    {
        if (!$this->installed()) {
            Response::error('系统尚未完成安装');
        }
        Response::success($this->installedData(), '安装完成');
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return [
                'current_step' => 'hello',
                'completed_steps' => [],
                'data' => [],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [
            'current_step' => 'hello',
            'completed_steps' => [],
            'data' => [],
        ];
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $path = $this->statePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('安装状态目录创建失败');
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json) || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('安装状态写入失败');
        }
    }

    /** @param array<string, mixed> $state */
    private function completeStep(array &$state, string $step, string $next): void
    {
        $completed = is_array($state['completed_steps'] ?? null) ? $state['completed_steps'] : [];
        if (!in_array($step, $completed, true)) {
            $completed[] = $step;
        }
        $state['completed_steps'] = array_values($completed);
        $state['current_step'] = $next;
        $state['updated_at'] = time();
    }

    /** @param array<string, mixed> $state */
    private function stepCompleted(array $state, string $step): bool
    {
        $completed = is_array($state['completed_steps'] ?? null) ? $state['completed_steps'] : [];
        return in_array($step, $completed, true);
    }

    private function rejectInstalled(): void
    {
        if ($this->installed()) {
            Response::error('系统已安装，禁止重复安装', 409, 409);
        }
    }

    private function installed(): bool
    {
        return InstallState::isLocked();
    }

    /** @return array<string, string> */
    private function installedData(): array
    {
        $lock = InstallState::data();
        $admin = (string) ($lock['admin'] ?? Config::get('XARR_ADMIN_PATH', 'admin'));
        $username = (string) ($lock['username'] ?? '');
        try {
            $database = $this->databaseFromEnv();
            if ($database !== []) {
                $db = $this->connect($database);
                if ($this->tableExists($db, $database['driver'], 'options')) {
                    $value = $db->query("SELECT value FROM `options` WHERE `key`='admin_path' LIMIT 1")->fetchColumn();
                    if (is_string($value) && $value !== '') {
                        $admin = $value;
                    }
                }
                if ($this->tableExists($db, $database['driver'], 'staff')) {
                    $value = $db->query('SELECT username FROM `staff` ORDER BY id ASC LIMIT 1')->fetchColumn();
                    $username = is_string($value) ? $value : '';
                }
            }
        } catch (Throwable) {
        }
        return ['username' => $username, 'admin' => $admin !== '' ? $admin : 'admin'];
    }

    /** @param array<string, mixed> $params @return array<string, string> */
    private function normalizeDatabaseConfig(array $params): array
    {
        $driver = strtolower(trim((string) ($params['driver'] ?? 'mysql')));
        $dsn = trim((string) ($params['dsn'] ?? ''));
        if ($dsn !== '') {
            $driver = strtolower((string) strtok($dsn, ':'));
        }

        if ($driver === 'sqlite') {
            if ($dsn === '') {
                $path = trim((string) ($params['path'] ?? ''));
                if ($path === '') {
                    throw new RuntimeException('请提供 SQLite 数据库路径');
                }
                $dsn = 'sqlite:' . $this->normalizeSqlitePath($path);
            } else {
                $path = substr($dsn, 7);
                $dsn = 'sqlite:' . $this->normalizeSqlitePath($path);
            }
            return ['driver' => 'sqlite', 'dsn' => $dsn];
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException('仅支持 mysql 或 sqlite');
        }

        $host = trim((string) ($params['host'] ?? ''));
        $port = trim((string) ($params['port'] ?? '3306'));
        $name = trim((string) ($params['database'] ?? $params['name'] ?? ''));
        $user = trim((string) ($params['username'] ?? $params['user'] ?? ''));
        $password = (string) ($params['password'] ?? '');
        if ($host === '' || $port === '' || $name === '' || $user === '' || $password === '') {
            throw new RuntimeException('请完整填写数据库主机、端口、库名、用户名和密码');
        }
        if (preg_match('/^[1-9][0-9]{0,4}$/', $port) !== 1 || (int) $port > 65535) {
            throw new RuntimeException('数据库端口格式不正确');
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException('数据库名只能包含字母、数字和下划线');
        }

        return [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $name,
            'username' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
        ];
    }

    /** @param array<string, string> $database */
    private function connect(array $database): PDO
    {
        if (($database['driver'] ?? '') === 'sqlite') {
            $db = new PDO($database['dsn'], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $db->exec('PRAGMA foreign_keys = ON');
            return $db;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $database['host'],
            $database['port'],
            $database['database'],
            $database['charset'] ?? 'utf8mb4',
        );
        return new PDO($dsn, $database['username'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function assertEmptyDatabase(PDO $db, string $driver): void
    {
        if ($driver === 'sqlite') {
            $count = (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
        } else {
            $count = (int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        }
        if ($count > 0) {
            throw new RuntimeException('目标数据库不是空库，安装器不会清空、迁移或覆盖已有数据');
        }
    }

    private function tableExists(PDO $db, string $driver, string $table): bool
    {
        if ($driver === 'sqlite') {
            $query = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table");
            $query->execute([':table' => $table]);
            return (int) $query->fetchColumn() > 0;
        }
        $query = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name=:table');
        $query->execute([':table' => $table]);
        return (int) $query->fetchColumn() > 0;
    }

    private function schema(string $driver): string
    {
        $path = $this->root() . '/database/schema.' . $driver . '.sql';
        $schema = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($schema) || trim($schema) === '') {
            throw new RuntimeException('数据库结构文件不存在或为空');
        }
        return $schema;
    }

    private function seedSystemDefaults(PDO $db): void
    {
        $db->beginTransaction();
        try {
            $this->upsertOption($db, 'web_logo', '/admin/static/images/logo.png');
            $this->upsertOption($db, 'web_service_qq', '');
            $this->upsertOption($db, 'admin_path', 'admin');
            $this->upsertOption($db, 'withdraw_withdraw_income_enable', '1');
            $this->upsertOption($db, 'withdraw_withdraw_income_fee_type', '1');
            $this->upsertOption($db, 'withdraw_withdraw_income_fee_value', '0');
            $this->upsertOption($db, 'withdraw_withdraw_income_min', '0');

            $payType = $db->prepare('INSERT INTO `pay_type` (`value`,`code`,`name`,`label`,`status`) VALUES (:value,:code,:name,:label,1)');
            foreach ([['alipay', '支付宝'], ['wxpay', '微信支付']] as [$value, $label]) {
                $exists = $db->prepare('SELECT COUNT(*) FROM `pay_type` WHERE `value`=:value');
                $exists->execute([':value' => $value]);
                if ((int) $exists->fetchColumn() === 0) {
                    $payType->execute([':value' => $value, ':code' => $value, ':name' => $value, ':label' => $label]);
                }
            }
            PaymentPluginCatalog::syncAllChannels($db);

            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    private function createAdmin(PDO $db, string $username, string $password, string $email): void
    {
        $count = (int) $db->query('SELECT COUNT(*) FROM `staff`')->fetchColumn();
        if ($count > 0) {
            throw new RuntimeException('管理员表已有数据，安装器不会覆盖已有管理员');
        }
        $insert = $db->prepare('INSERT INTO `staff` (`username`,`password`,`name`,`status`,`super`,`email`) VALUES (:username,:password,:name,1,1,:email)');
        $insert->execute([
            ':username' => $username,
            ':password' => md5($password),
            ':name' => '系统管理员',
            ':email' => $email,
        ]);
    }

    private function upsertOption(PDO $db, string $key, string $value): void
    {
        $query = $db->prepare('SELECT COUNT(*) FROM `options` WHERE `key`=:key');
        $query->execute([':key' => $key]);
        if ((int) $query->fetchColumn() > 0) {
            $update = $db->prepare('UPDATE `options` SET `value`=:value WHERE `key`=:key');
            $update->execute([':key' => $key, ':value' => $value]);
            return;
        }
        $insert = $db->prepare('INSERT INTO `options` (`key`,`value`) VALUES (:key,:value)');
        $insert->execute([':key' => $key, ':value' => $value]);
    }

    /** @param array<string, string> $database @return array<string, string> */
    private function publicDatabaseConfig(array $database): array
    {
        if (($database['driver'] ?? '') === 'sqlite') {
            return ['driver' => 'sqlite', 'dsn' => $database['dsn'] ?? ''];
        }
        return [
            'host' => $database['host'] ?? '',
            'port' => $database['port'] ?? '3306',
            'username' => $database['username'] ?? '',
            'password' => $database['password'] ?? '',
            'database' => $database['database'] ?? '',
        ];
    }

    /** @return array<string, string> */
    private function databaseFromEnv(): array
    {
        $dsn = (string) Config::get('XARR_DB_DSN', '');
        if ($dsn !== '') {
            $driver = strtolower((string) strtok($dsn, ':'));
            if ($driver !== 'sqlite') {
                return [];
            }
            return ['driver' => 'sqlite', 'dsn' => $dsn];
        }

        $name = (string) Config::get('XARR_DB_NAME', '');
        $user = (string) Config::get('XARR_DB_USER', '');
        $password = (string) Config::get('XARR_DB_PASSWORD', '');
        if ($name === '' || $user === '') {
            return [];
        }
        return [
            'driver' => 'mysql',
            'host' => (string) Config::get('XARR_DB_HOST', '127.0.0.1'),
            'port' => (string) Config::get('XARR_DB_PORT', '3306'),
            'database' => $name,
            'username' => $user,
            'password' => $password,
            'charset' => (string) Config::get('XARR_DB_CHARSET', 'utf8mb4'),
        ];
    }

    /** @param array<string, string> $database @return array<string, string> */
    private function envValues(array $database): array
    {
        $values = [
            'XARR_RUNTIME_DIR' => $this->root(),
            'XARR_PLUGIN_DIR' => $this->root() . '/plugins',
            'XARR_ADMIN_PATH' => 'admin',
        ];
        $state = $this->state();
        if (is_string($state['license'] ?? null) && $state['license'] !== '') {
            $values['XARR_LICENSE_CODE'] = $state['license'];
        }

        if (($database['driver'] ?? '') === 'sqlite') {
            $values['XARR_DB_DSN'] = $database['dsn'];
            $values['XARR_DB_HOST'] = '';
            $values['XARR_DB_PORT'] = '';
            $values['XARR_DB_NAME'] = '';
            $values['XARR_DB_USER'] = '';
            $values['XARR_DB_PASSWORD'] = '';
            $values['XARR_DB_CHARSET'] = '';
            return $values;
        }

        return $values + [
            'XARR_DB_DSN' => '',
            'XARR_DB_HOST' => $database['host'],
            'XARR_DB_PORT' => $database['port'],
            'XARR_DB_NAME' => $database['database'],
            'XARR_DB_USER' => $database['username'],
            'XARR_DB_PASSWORD' => $database['password'],
            'XARR_DB_CHARSET' => $database['charset'] ?? 'utf8mb4',
        ];
    }

    /** @param array<string, string> $values */
    private function writeEnv(array $values): void
    {
        $path = $this->envPath();
        $existing = $this->readEnvFile($path);
        foreach ($values as $key => $value) {
            $existing[$key] = $value;
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        $lines = ['# PHP install configuration'];
        foreach ($existing as $key => $value) {
            $lines[] = $key . '=' . $this->quoteEnvValue($value);
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('配置文件目录创建失败');
        }
        if (file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('.env 配置写入失败');
        }
    }

    /** @return array<string, string> */
    private function readEnvFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '') {
                continue;
            }
            if (strlen($value) >= 2) {
                $quote = $value[0];
                if (($quote === '"' || $quote === "'") && $value[strlen($value) - 1] === $quote) {
                    $value = substr($value, 1, -1);
                    if ($quote === '"') {
                        $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
                    }
                }
            }
            $values[$key] = $value;
        }
        return $values;
    }

    private function quoteEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }

    private function normalizeSqlitePath(string $path): string
    {
        if ($path === ':memory:') {
            throw new RuntimeException('安装器不接受内存数据库');
        }
        $path = str_replace('\\', '/', $path);
        if (!preg_match('#^[A-Za-z]:/#', $path) && !str_starts_with($path, '/')) {
            $path = $this->root() . '/' . $path;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('SQLite 数据库目录创建失败');
        }
        $realDir = realpath($dir);
        $root = realpath($this->root());
        if ($realDir === false || $root === false || !str_starts_with(strtolower($realDir . DIRECTORY_SEPARATOR), strtolower($root . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('SQLite 数据库路径必须位于项目目录内');
        }
        if (is_file($path) && filesize($path) > 0) {
            throw new RuntimeException('SQLite 数据库文件已存在且非空');
        }
        return $path;
    }

    private function sseHeaders(): void
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
    }

    private function sse(string $event, string $message): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . str_replace(["\r", "\n"], ' ', $message) . "\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function statePath(): string
    {
        return (string) Config::get('XARR_INSTALL_STATE_FILE', $this->root() . '/var/install-state.json');
    }

    private function envPath(): string
    {
        return (string) Config::get('XARR_INSTALL_ENV_FILE', $this->root() . '/.env');
    }
}
