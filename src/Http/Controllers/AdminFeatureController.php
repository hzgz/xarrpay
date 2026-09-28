<?php

declare(strict_types=1);

namespace XArrPay\Http\Controllers;

use PDO;
use XArrPay\Http\Request;
use XArrPay\Support\Database;
use XArrPay\Support\Config;
use XArrPay\Support\PaymentPluginRegistry;
use XArrPay\Support\PluginConfigStore;
use XArrPay\Support\PluginRuntime;
use XArrPay\Support\PluginRuntimeService;
use XArrPay\Support\Response;

/**
 * The management screens use a number of secondary APIs which are separate
 * from the payment CRUD in AdminController. Keeping them here makes each
 * route explicit while sharing the same SQLite/MySQL persistence rules.
 */
final class AdminFeatureController
{
    public function staffActionLogList(Request $request): never
    {
        $this->staff($request);
        $rows = Database::connection()->query('SELECT * FROM staff_action_log ORDER BY id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $request->all()), $request->all()));
    }

    public function logFiles(Request $request): never
    {
        $this->staff($request);
        $items = $this->logEntries();
        usort($items, static fn (array $a, array $b): int => $b['updated_at'] <=> $a['updated_at']);
        $items = array_map(static function (array $item): array {
            unset($item['_path']);
            return $item;
        }, $items);
        Response::success($items);
    }

    public function staffConnectChannels(Request $request): never
    {
        $this->staff($request);
        Response::error('当前版本未配置管理员第三方登录服务', 501, 501);
    }

    public function staffPasskeyList(Request $request): never
    {
        $this->staff($request);
        Response::error('管理员通行密钥（WebAuthn）凭据存储尚未实现', 501, 501);
    }

    public function logDetail(Request $request): never
    {
        $this->staff($request);
        $path = $this->logPath((string) ($request->input('file') ?? $request->input('name') ?? ''));
        if (!is_file($path)) {
            Response::error('日志文件不存在', 404, 404);
        }
        $content = file_get_contents($path);
        Response::success(['name' => basename($path), 'content' => is_string($content) ? $content : '']);
    }

    public function logStream(Request $request): never
    {
        $this->staffBearer($request);
        $path = $this->logPath((string) ($request->input('file') ?? $request->input('name') ?? ''));
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => 404, 'message' => '日志文件不存在', 'data' => null, 'redirect' => ''], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $content = file_get_contents($path);
        $content = is_string($content) ? $content : '';
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        echo 'data: ' . json_encode(['type' => 'size', 'size' => (int) filesize($path), 'redirect' => ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'content', 'content' => $content], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'end'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        exit;
    }

    public function logDownload(Request $request): never
    {
        $this->staff($request);
        $path = $this->logPath((string) ($request->input('file') ?? $request->input('name') ?? ''));
        if (!is_file($path)) {
            Response::error('日志文件不存在', 404, 404);
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }

    public function logRemove(Request $request): never
    {
        $this->staff($request);
        $path = $this->logPath((string) ($request->input('file') ?? $request->input('name') ?? ''));
        if (!is_file($path)) {
            Response::error('日志文件不存在', 404, 404);
        }
        file_put_contents($path, '');
        Response::success(null, '日志已清空');
    }

    public function logBatchRemove(Request $request): never
    {
        $this->staff($request);
        $type = (string) $request->input('type', '');
        if ($type !== '') {
            $days = match ($type) {
                '1day' => 1,
                '3days' => 3,
                '7days' => 7,
                '15days' => 15,
                'all' => 0,
                default => -1,
            };
            if ($days < 0) {
                Response::error('日志清理范围无效', 422, 422);
            }
            $threshold = $days > 0 ? time() - ($days * 86400) : PHP_INT_MAX;
            $removed = 0;
            foreach ($this->logEntries() as $entry) {
                $path = $this->logPath((string) $entry['path']);
                if (is_file($path) && (int) filemtime($path) < $threshold) {
                    file_put_contents($path, '');
                    $removed++;
                }
            }
            Response::success(['removed' => $removed], '日志已清理');
        }
        $files = $request->input('files', $request->input('names', []));
        if (!is_array($files) || $files === []) {
            Response::error('请选择日志文件', 422, 422);
        }
        $removed = 0;
        foreach ($files as $file) {
            $path = $this->logPath((string) $file);
            if (is_file($path)) {
                file_put_contents($path, '');
                $removed++;
            }
        }
        Response::success(['removed' => $removed], '日志已清空');
    }

    public function notificationList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM notification ORDER BY id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function notificationCreate(Request $request): never
    {
        $this->writeNotification($request, null);
    }

    public function notificationEdit(Request $request): never
    {
        $this->writeNotification($request, $this->id($request));
    }

    public function notificationRemove(Request $request): never
    {
        $this->staff($request);
        $delete = Database::connection()->prepare('DELETE FROM notification WHERE id = :id');
        $delete->execute([':id' => $this->id($request)]);
        $this->affected($delete->rowCount(), '通知不存在');
        Response::success(null, '通知已删除');
    }

    public function notificationSwitch(Request $request): never
    {
        $this->switchSimple('notification', $request, '通知状态已更新');
    }

    public function notificationTemplateList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM notification_template ORDER BY id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function notificationTemplateCreate(Request $request): never
    {
        $this->writeNotificationTemplate($request, null);
    }

    public function notificationTemplateEdit(Request $request): never
    {
        $this->writeNotificationTemplate($request, $this->id($request));
    }

    public function notificationTemplateRemove(Request $request): never
    {
        $this->staff($request);
        $delete = Database::connection()->prepare('DELETE FROM notification_template WHERE id = :id');
        $delete->execute([':id' => $this->id($request)]);
        $this->affected($delete->rowCount(), '通知模板不存在');
        Response::success(null, '通知模板已删除');
    }

    public function notificationTemplateSwitch(Request $request): never
    {
        $this->switchSimple('notification_template', $request, '通知模板状态已更新');
    }

    public function notificationTemplateTest(Request $request): never
    {
        $this->staff($request);
        Response::error('本地版未配置邮件、短信或第三方通知通道，不能发送测试通知', 501, 501);
    }

    public function pageList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM page ORDER BY sort ASC, id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function pageCreate(Request $request): never
    {
        $this->writePage($request, null);
    }

    public function pageEdit(Request $request): never
    {
        $this->writePage($request, $this->id($request));
    }

    public function pageRemove(Request $request): never
    {
        $this->deleteSimple('page', $request, '页面不存在', '页面已删除');
    }

    public function pageSwitch(Request $request): never
    {
        $this->switchSimple('page', $request, '页面状态已更新');
    }

    public function pollingList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM polling_rule ORDER BY sort ASC, id DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['account_ids'] = $this->jsonArray($row['account_ids'] ?? '[]');
        }
        unset($row);
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function pollingCreate(Request $request): never
    {
        $this->writePolling($request, null);
    }

    public function pollingEdit(Request $request): never
    {
        $this->writePolling($request, $this->id($request));
    }

    public function pollingRemove(Request $request): never
    {
        $this->deleteSimple('polling_rule', $request, '轮询规则不存在', '轮询规则已删除');
    }

    public function pollingSwitch(Request $request): never
    {
        $this->switchSimple('polling_rule', $request, '轮询规则状态已更新');
    }

    public function storageChannelList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM storage_channel ORDER BY is_default DESC, id DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['options'] = $this->jsonArray($row['options'] ?? '{}');
        }
        unset($row);
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function storageChannelCreate(Request $request): never
    {
        $this->writeStorageChannel($request, null);
    }

    public function storageChannelEdit(Request $request): never
    {
        $this->writeStorageChannel($request, $this->id($request));
    }

    public function storageChannelRemove(Request $request): never
    {
        $this->deleteSimple('storage_channel', $request, '存储通道不存在', '存储通道已删除');
    }

    public function storageChannelSwitch(Request $request): never
    {
        $this->switchSimple('storage_channel', $request, '存储通道状态已更新');
    }

    public function storageChannelDefault(Request $request): never
    {
        $this->staff($request);
        $id = $this->id($request);
        $db = Database::connection();
        $db->beginTransaction();
        $db->exec('UPDATE storage_channel SET is_default = 0');
        $update = $db->prepare('UPDATE storage_channel SET is_default = 1 WHERE id = :id');
        $update->execute([':id' => $id]);
        if ($update->rowCount() === 0) {
            $db->rollBack();
            Response::error('存储通道不存在', 404, 404);
        }
        $db->commit();
        Response::success(null, '默认存储通道已设置');
    }

    public function storagePluginList(Request $request): never
    {
        $this->staff($request);
        Response::success(['list' => [['name' => 'local', 'title' => '本地文件存储', 'driver' => 'local']]]);
    }

    public function storagePluginFormItems(Request $request): never
    {
        $this->staff($request);
        $plugin = trim((string) $request->input('plugin_name', 'local'));
        if ($plugin !== 'local') {
            Response::error('本地版只提供 local 存储插件', 404, 404);
        }
        Response::success(['plugin_name' => 'local', 'items' => []]);
    }

    public function storageChannelTest(Request $request): never
    {
        $this->staff($request);
        Response::success(['driver' => (string) $request->input('driver', 'local'), 'connected' => true], '本地存储通道可用');
    }

    public function storageFileList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM upload_file ORDER BY id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function storageFileStats(Request $request): never
    {
        $this->staff($request);
        $db = Database::connection();
        Response::success([
            'count' => (int) $db->query('SELECT COUNT(*) FROM upload_file')->fetchColumn(),
            'size' => (int) ($db->query('SELECT COALESCE(SUM(size), 0) FROM upload_file')->fetchColumn() ?: 0),
        ]);
    }

    public function storageFileDelete(Request $request): never
    {
        $this->staff($request);
        $id = $this->id($request);
        $query = Database::connection()->prepare('SELECT path FROM upload_file WHERE id = :id LIMIT 1');
        $query->execute([':id' => $id]);
        $path = $query->fetchColumn();
        $delete = Database::connection()->prepare('DELETE FROM upload_file WHERE id = :id');
        $delete->execute([':id' => $id]);
        $this->affected($delete->rowCount(), '文件不存在');
        if (is_string($path) && $path !== '' && is_file($path)) {
            unlink($path);
        }
        Response::success(null, '文件已删除');
    }

    public function storageOrphanScan(Request $request): never
    {
        $this->staff($request);
        $root = $this->uploadRoot();
        $db = Database::connection();
        $known = [];
        foreach ($db->query('SELECT path FROM upload_file WHERE path <> ""')->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $resolved = $this->resolveUploadPath((string) $path);
            if ($resolved !== null) {
                $known[$resolved] = true;
            }
        }

        $orphans = [];
        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getRealPath();
                if ($path === false || isset($known[$path])) {
                    continue;
                }
                $orphans[] = $this->uploadFileDescriptor($root, $path, $file);
            }
        }
        usort($orphans, static fn (array $left, array $right): int => ($right['modified_at'] <=> $left['modified_at']) ?: strcmp($left['path'], $right['path']));
        Response::success([
            'orphans' => $orphans,
            'list' => $orphans,
            'count' => count($orphans),
            'total' => count($orphans),
        ], $orphans === [] ? '未发现孤立文件' : '孤立文件扫描完成');
    }

    public function storageOrphanDelete(Request $request): never
    {
        $this->staff($request);
        $root = $this->uploadRoot();
        $items = $request->input('orphans', $request->input('files', []));
        if (!is_array($items) || $items === []) {
            Response::error('请选择要清理的孤立文件', 422, 422);
        }

        $removed = 0;
        $bytes = 0;
        $skipped = [];
        foreach ($items as $item) {
            $candidate = is_array($item)
                ? (string) ($item['path'] ?? $item['file'] ?? '')
                : (string) $item;
            $path = $this->resolveUploadPath($candidate);
            if ($path === null || !is_file($path)) {
                $skipped[] = $candidate;
                continue;
            }
            $known = Database::connection()->prepare('SELECT COUNT(*) FROM upload_file WHERE path = :path');
            $known->execute([':path' => $path]);
            if ((int) $known->fetchColumn() > 0) {
                $skipped[] = $candidate;
                continue;
            }
            $size = (int) filesize($path);
            if (!unlink($path)) {
                $skipped[] = $candidate;
                continue;
            }
            $removed++;
            $bytes += $size;
        }
        Response::success([
            'removed' => $removed,
            'bytes' => $bytes,
            'skipped' => $skipped,
        ], $removed > 0 ? '孤立文件已清理' : '未清理任何孤立文件');
    }

    public function channelGatewayList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM channel_gateway ORDER BY id DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['options'] = $this->jsonArray($row['options'] ?? '{}');
        }
        unset($row);
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function channelGatewayCreate(Request $request): never
    {
        $this->writeChannelGateway($request, null);
    }

    public function channelGatewayEdit(Request $request): never
    {
        $this->writeChannelGateway($request, $this->id($request));
    }

    public function channelGatewayRemove(Request $request): never
    {
        $this->deleteSimple('channel_gateway', $request, '支付网关不存在', '支付网关已删除');
    }

    public function channelGatewaySwitch(Request $request): never
    {
        $this->switchSimple('channel_gateway', $request, '支付网关状态已更新');
    }

    public function channelGatewayBatchRemove(Request $request): never
    {
        $this->deleteMany('channel_gateway', $request, '支付网关已删除');
    }

    public function channelGatewayBatchImport(Request $request): never
    {
        $this->staff($request);
        $items = $request->input('items', []);
        if (!is_array($items)) {
            Response::error('导入数据格式无效', 422, 422);
        }
        $created = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                Response::error('导入数据中包含无效记录', 422, 422);
            }
            $values = $this->channelGatewayValues($item);
            $query = Database::connection()->prepare('INSERT INTO channel_gateway (name,addr,channel_code,pay_type,status,options,active_time,created_at,updated_at) VALUES (:name,:addr,:channel_code,:pay_type,:status,:options,:active_time,:created_at,:updated_at)');
            $query->execute([
                ':name' => $values[':name'],
                ':addr' => $values[':addr'],
                ':channel_code' => $values[':channel_code'],
                ':pay_type' => $values[':pay_type'],
                ':status' => $values[':status'],
                ':options' => $values[':options'],
                ':active_time' => $values[':active_time'],
                ':created_at' => time(),
                ':updated_at' => time(),
            ]);
            $created++;
        }
        Response::success(['created' => $created], '支付网关导入完成');
    }

    public function thirdAccountList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM third_account ORDER BY id DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['options'] = $this->jsonArray($row['options'] ?? '{}');
        }
        unset($row);
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function thirdAccountCreate(Request $request): never
    {
        $this->writeThirdAccount($request, null);
    }

    public function thirdAccountEdit(Request $request): never
    {
        $this->writeThirdAccount($request, $this->id($request));
    }

    public function thirdAccountRemove(Request $request): never
    {
        $this->deleteSimple('third_account', $request, '第三方账号不存在', '第三方账号已删除');
    }

    public function thirdAccountSwitch(Request $request): never
    {
        $this->switchSimple('third_account', $request, '第三方账号状态已更新');
    }

    public function thirdAccountLogList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM third_order_log ORDER BY id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function thirdAccountLogRemove(Request $request): never
    {
        $this->deleteSimple('third_order_log', $request, '第三方订单日志不存在', '日志已删除');
    }

    public function thirdOrderLogList(Request $request): never
    {
        $this->thirdAccountLogList($request);
    }

    public function thirdOrderLogRemove(Request $request): never
    {
        $this->thirdAccountLogRemove($request);
    }

    public function thirdOrderLogReport(Request $request): never
    {
        $this->staff($request);
        Response::error('本地版未配置第三方订单上报服务', 501, 501);
    }

    public function pluginRuntimeStatus(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $service = new PluginRuntimeService();
        Response::success([
            'list' => $service->status(
                Database::connection(),
                trim((string) ($params['plugin_name'] ?? '')) ?: null,
                (int) ($params['account_id'] ?? 0) ?: null,
            ),
            'flows' => $service->flows(
                Database::connection(),
                max(1, (int) ($params['limit'] ?? 100)),
                (int) ($params['account_id'] ?? 0) ?: null,
            ),
        ]);
    }

    public function domainWhiteList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM domain_white ORDER BY id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function domainWhiteCreate(Request $request): never
    {
        $this->writeDomainWhite($request, null);
    }

    public function domainWhiteEdit(Request $request): never
    {
        $this->writeDomainWhite($request, $this->id($request));
    }

    public function domainWhiteRemove(Request $request): never
    {
        $this->deleteSimple('domain_white', $request, '域名白名单不存在', '域名白名单已删除');
    }

    public function domainWhiteSwitch(Request $request): never
    {
        $this->switchSimple('domain_white', $request, '域名白名单状态已更新');
    }

    public function blackDataList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM black_data ORDER BY id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function blackDataCreate(Request $request): never
    {
        $this->writeBlackData($request, null);
    }

    public function blackDataEdit(Request $request): never
    {
        $this->writeBlackData($request, $this->id($request));
    }

    public function blackDataRemove(Request $request): never
    {
        $this->deleteSimple('black_data', $request, '黑名单记录不存在', '黑名单记录已删除');
    }

    public function blackDataSwitch(Request $request): never
    {
        $this->switchSimple('black_data', $request, '黑名单状态已更新');
    }

    public function blackDataBatchGenerate(Request $request): never
    {
        $this->staff($request);
        $values = $request->input('values', []);
        if (!is_array($values)) {
            Response::error('批量数据格式无效', 422, 422);
        }
        $created = 0;
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $query = Database::connection()->prepare('INSERT OR IGNORE INTO black_data (type,black_value,reason,remark,expire_at,status,created_at,updated_at) VALUES (:type,:black_value,:reason,:remark,:expire_at,1,:created_at,:updated_at)');
            $query->execute([':type' => (int) ($request->input('type', 1)), ':black_value' => $value, ':reason' => '', ':remark' => '', ':expire_at' => 0, ':created_at' => time(), ':updated_at' => time()]);
            $created += $query->rowCount();
        }
        Response::success(['created' => $created], '黑名单批量生成完成');
    }

    public function blackDataRefreshCache(Request $request): never
    {
        $this->staff($request);
        Response::success(null, '黑名单缓存已刷新');
    }

    public function mealList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM meal ORDER BY id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function mealCreate(Request $request): never
    {
        $this->writeMeal($request, null);
    }

    public function mealEdit(Request $request): never
    {
        $this->writeMeal($request, $this->id($request));
    }

    public function mealRemove(Request $request): never
    {
        $this->deleteSimple('meal', $request, '商户套餐不存在', '商户套餐已删除');
    }

    public function mealSwitch(Request $request): never
    {
        $this->switchSimple('meal', $request, '商户套餐状态已更新');
    }

    public function systemOrderList(Request $request): never
    {
        $this->staff($request);
        Response::success($this->paginate([], $request->all()));
    }

    public function systemOrderRemove(Request $request): never
    {
        $this->staff($request);
        Response::error('本地版没有系统套餐订单可删除', 404, 404);
    }

    public function cardGroupList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM card_group ORDER BY id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function cardGroupCreate(Request $request): never
    {
        $this->writeCardGroup($request, null);
    }

    public function cardGroupEdit(Request $request): never
    {
        $this->writeCardGroup($request, $this->id($request));
    }

    public function cardGroupRemove(Request $request): never
    {
        $this->deleteSimple('card_group', $request, '卡密分组不存在', '卡密分组已删除');
    }

    public function cardGroupSwitch(Request $request): never
    {
        $this->switchSimple('card_group', $request, '卡密分组状态已更新');
    }

    public function cardList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $sql = 'SELECT c.*, g.name AS group_name, m.name AS meal_name, u.username FROM card c LEFT JOIN card_group g ON g.id=c.group_id LEFT JOIN meal m ON m.id=c.meal_id LEFT JOIN user u ON u.id=c.use_uid ORDER BY c.id DESC';
        $rows = Database::connection()->query($sql)->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function cardCreate(Request $request): never
    {
        $this->writeCard($request, null);
    }

    public function cardEdit(Request $request): never
    {
        $this->writeCard($request, $this->id($request));
    }

    public function cardRemove(Request $request): never
    {
        $this->deleteSimple('card', $request, '卡密不存在', '卡密已删除');
    }

    public function cardSwitch(Request $request): never
    {
        $this->switchSimple('card', $request, '卡密状态已更新');
    }

    public function cardBatchGenerate(Request $request): never
    {
        $this->staff($request);
        $groupId = (int) $request->input('group_id', 0);
        $count = min(max((int) $request->input('count', 1), 1), 1000);
        $prefix = trim((string) $request->input('prefix', ''));
        if ($groupId <= 0) {
            Response::error('请选择卡密分组', 422, 422);
        }
        $db = Database::connection();
        $created = [];
        for ($index = 0; $index < $count; $index++) {
            $secret = ($prefix !== '' ? $prefix . '_' : '') . strtoupper(bin2hex(random_bytes(8)));
            $query = $db->prepare('INSERT INTO card (group_id,secret,value_type,value,meal_id,use_uid,use_time,status,created_at,updated_at) VALUES (:group_id,:secret,1,0,0,0,0,1,:created_at,:updated_at)');
            $query->execute([':group_id' => $groupId, ':secret' => $secret, ':created_at' => time(), ':updated_at' => time()]);
            $created[] = $secret;
        }
        Response::success(['list' => $created], '卡密生成成功');
    }

    public function areaList(Request $request): never
    {
        $this->staff($request);
        $rows = Database::connection()->query('SELECT * FROM area ORDER BY parent_id ASC, area_id ASC')->fetchAll();
        Response::success(['list' => $rows, 'count' => count($rows), 'total' => count($rows)]);
    }

    public function areaCreate(Request $request): never
    {
        $this->writeArea($request, null);
    }

    public function areaEdit(Request $request): never
    {
        $areaId = (int) ($request->input('area_id') ?? $request->input('id') ?? 0);
        if ($areaId <= 0) {
            Response::error('地区 ID 无效', 422, 422);
        }
        $this->writeArea($request, $areaId);
    }

    public function areaRemove(Request $request): never
    {
        $this->staff($request);
        $areaId = (int) ($request->input('area_id') ?? $request->input('id') ?? 0);
        if ($areaId <= 0) {
            Response::error('地区 ID 无效', 422, 422);
        }
        $db = Database::connection();
        $children = $db->prepare('SELECT COUNT(*) FROM area WHERE parent_id=:parent_id');
        $children->execute([':parent_id' => $areaId]);
        if ((int) $children->fetchColumn() > 0) {
            Response::error('该地区存在下级地区，不能直接删除', 422, 422);
        }
        $delete = $db->prepare('DELETE FROM area WHERE area_id=:area_id');
        $delete->execute([':area_id' => $areaId]);
        if ($delete->rowCount() !== 1) {
            Response::error('地区不存在', 404, 404);
        }
        Response::success(['area_id' => $areaId], '地区已删除');
    }

    public function areaSwitch(Request $request): never
    {
        $this->staff($request);
        $areaId = (int) ($request->input('area_id') ?? $request->input('id') ?? 0);
        if ($areaId <= 0) {
            Response::error('地区 ID 无效', 422, 422);
        }
        $status = filter_var($request->input('status'), FILTER_VALIDATE_INT);
        if ($status === false || !in_array((int) $status, [0, 1], true)) {
            Response::error('状态值只能为 0 或 1', 422, 422);
        }
        $update = Database::connection()->prepare('UPDATE area SET status=:status WHERE area_id=:area_id');
        $update->execute([':status' => (int) $status, ':area_id' => $areaId]);
        if ($update->rowCount() !== 1) {
            Response::error('地区不存在或状态未变化', 404, 404);
        }
        Response::success(['area_id' => $areaId, 'status' => (int) $status], '地区状态已更新');
    }

    public function proxyPoolList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM proxy_pool ORDER BY id DESC')->fetchAll();
        Response::success($this->paginateWithPageSize($this->filter($rows, $params), $params));
    }

    public function proxyPoolCreate(Request $request): never
    {
        $this->writeProxyPool($request, null);
    }

    public function proxyPoolEdit(Request $request): never
    {
        $this->writeProxyPool($request, $this->id($request));
    }

    public function proxyPoolRemove(Request $request): never
    {
        $this->deleteSimple('proxy_pool', $request, '代理池不存在', '代理池已删除');
    }

    public function proxyPoolSwitch(Request $request): never
    {
        $this->switchSimple('proxy_pool', $request, '代理池状态已更新');
    }

    public function proxyPoolItems(Request $request, string $action): never
    {
        $this->staff($request);
        Response::error('当前数据库结构没有 Go 版代理池明细表，不能伪造代理明细：' . $action, 501, 501);
    }

    public function optionTemplates(Request $request): never
    {
        $this->staff($request);
        $root = dirname(__DIR__, 3);
        $frontend = [];
        foreach ([
            'index' => '首页模板',
            'pay' => '支付页模板',
            'cashier' => '收银台模板',
            'user' => '商户中心模板',
        ] as $type => $label) {
            $frontend[] = $this->templateCatalogItem($root, $type, $label);
            foreach ($this->templateManifests($root, $type) as $item) {
                $frontend[] = $item;
            }
        }

        Response::success([
            'frontend' => $frontend,
            'backend' => [
                $this->templateCatalogItem($root, 'admin', '后台中心模板'),
            ],
        ]);
    }

    public function connectItems(Request $request): never
    {
        $this->staff($request);
        Response::success([
            'connect' => $this->notificationConnectors(),
            'events' => $this->notificationEvents(),
        ]);
    }

    public function connectTemplates(Request $request): never
    {
        $this->staff($request);
        $templates = [];
        foreach (Database::connection()->query('SELECT * FROM notification_template ORDER BY id ASC')->fetchAll() as $template) {
            $code = (string) ($template['code'] ?? '');
            $channel = (string) ($template['channel'] ?? '');
            if ($code !== '' && $channel !== '') {
                $templates[$code . "\n" . $channel] = $template;
            }
        }

        $rows = [];
        foreach ($this->notificationEvents() as $event) {
            $eventId = (string) $event['value'];
            $row = [
                'event_id' => $eventId,
                'event_name' => (string) $event['label'],
            ];
            foreach ($this->notificationConnectors() as $connector) {
                $channel = (string) $connector['value'];
                $code = $eventId . ':' . $channel;
                $template = $templates[$code . "\n" . $channel] ?? null;
                $name = is_array($template) ? (string) ($template['name'] ?? '') : (string) $event['label'];
                $content = is_array($template) ? (string) ($template['content'] ?? '') : '';
                $row[$channel] = [
                    'id' => is_array($template) ? (int) ($template['id'] ?? 0) : 0,
                    'name' => $name,
                    'title' => $name,
                    'code' => $code,
                    'channel' => $channel,
                    'source_type' => $channel,
                    'event_id' => $eventId,
                    'event_name' => (string) $event['label'],
                    'template_id' => '',
                    'template_title' => $name,
                    'template_content' => $content,
                    'content' => $content,
                    'status' => is_array($template) ? (int) ($template['status'] ?? 2) : 2,
                    'has_chose' => true,
                    'is_global' => $channel === 'global' ? 1 : 0,
                ];
            }
            $rows[] = $row;
        }

        Response::success(['data' => $rows, 'count' => count($rows), 'total' => count($rows)]);
    }

    public function serviceAccountPoolList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM service_account_pool ORDER BY id DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['config'] = $this->jsonArray($row['config'] ?? '{}');
        }
        unset($row);
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function qrcodeTemplateList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM qrcode_template ORDER BY sort ASC,id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function qrcodeTemplateCreate(Request $request): never
    {
        $this->writeQrcodeTemplate($request, null);
    }

    public function qrcodeTemplateEdit(Request $request): never
    {
        $this->writeQrcodeTemplate($request, $this->id($request));
    }

    public function qrcodeTemplateRemove(Request $request): never
    {
        $this->deleteSimple('qrcode_template', $request, '二维码模板不存在', '二维码模板已删除');
    }

    public function qrcodeTemplateSwitch(Request $request): never
    {
        $this->switchSimple('qrcode_template', $request, '二维码模板状态已更新');
    }

    public function templateList(Request $request, string $type): never
    {
        $this->staff($request);
        $allowed = ['index', 'pay', 'cashier', 'user', 'admin'];
        if (!in_array($type, $allowed, true)) {
            Response::error('模板类型无效', 404, 404);
        }
        $directory = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type;
        $label = $this->templateTypeLabel($type);
        $items = [$this->templateCatalogItem(dirname(__DIR__, 3), $type, $label)];
        if (is_dir($directory)) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [] as $manifest) {
                $data = json_decode((string) file_get_contents($manifest), true);
                if (is_array($data)) {
                    $data['id'] = (string) ($data['id'] ?? $type . ':' . basename(dirname($manifest)));
                    $data['value'] = (string) ($data['value'] ?? basename(dirname($manifest)));
                    $data['type'] = $type;
                    $data['type_label'] = $label;
                    $data['path'] = str_replace('\\', '/', substr(dirname($manifest), strlen(dirname(__DIR__, 3)) + 1));
                    $data['has_screenshot'] = is_file(dirname($manifest) . DIRECTORY_SEPARATOR . 'screenshot.png');
                    $items[] = $data;
                }
            }
        }
        Response::success(['list' => $items, 'count' => count($items), 'total' => count($items)]);
    }

    public function templateApiList(Request $request): never
    {
        $this->staff($request);
        $type = trim((string) $request->input('type', ''));
        $types = $type === '' ? $this->templateTypesData() : [$this->templateTypeData($type)];
        $items = [];
        foreach ($types as $item) {
            $items = array_merge($items, $this->templateListData((string) $item['value']));
        }
        Response::success(['list' => $items, 'count' => count($items), 'total' => count($items)]);
    }

    public function templateTypes(Request $request): never
    {
        $this->staff($request);
        Response::success(['list' => $this->templateTypesData(), 'count' => 5, 'total' => 5]);
    }

    public function templateScreenshot(Request $request, ?string $routeType = null): never
    {
        $this->staff($request);
        $type = trim((string) ($routeType ?? $request->input('type', '')));
        if ($type === '') {
            Response::error('模板类型不能为空', 422, 422);
        }
        $this->templateTypeData($type);
        $template = trim((string) ($request->input('template') ?? $request->input('id') ?? 'default'));
        $root = dirname(__DIR__, 3);
        if ($template === 'default' || $template === $type . ':default') {
            $path = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . 'screenshot.png';
            if (!is_file($path)) {
                $path = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'template-' . $type . '-default.png';
            }
        } else {
            $name = str_contains($template, ':') ? (string) substr($template, strrpos($template, ':') + 1) : $template;
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
                Response::error('模板标识无效', 422, 422);
            }
            $path = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'screenshot.png';
        }
        $real = realpath($path);
        $publicRoot = realpath($root . DIRECTORY_SEPARATOR . 'public');
        if ($real === false || $publicRoot === false || !str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR) || !is_file($real)) {
            Response::error('模板截图不存在', 404, 404);
        }
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($real) : 'image/png';
        header('Content-Type: ' . ($mime !== '' ? $mime : 'image/png'));
        header('Content-Length: ' . (string) filesize($real));
        readfile($real);
        exit;
    }

    public function thirdConnectChatList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM third_connect_chat ORDER BY sort ASC,id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function thirdConnectChatCreate(Request $request): never
    {
        $this->writeThirdConnectChat($request, null);
    }

    public function thirdConnectChatEdit(Request $request): never
    {
        $this->writeThirdConnectChat($request, $this->id($request));
    }

    public function thirdConnectChatRemove(Request $request): never
    {
        $this->deleteSimple('third_connect_chat', $request, '聊天方案不存在', '聊天方案已删除');
    }

    public function thirdConnectChatSwitch(Request $request): never
    {
        $this->switchSimple('third_connect_chat', $request, '聊天方案状态已更新');
    }

    public function configWizardCheck(Request $request): never
    {
        $this->staff($request);
        $root = dirname(__DIR__, 3);
        $permissionChecks = [];
        foreach ([
            'var' => '运行时目录',
            'public/uploads' => '上传目录',
            'database' => '数据库结构目录',
            'public' => '公开资源目录',
        ] as $relative => $label) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $permissionChecks[] = [
                'path' => $relative,
                'label' => $label,
                'exists' => $exists,
                'writable' => $writable,
                'message' => $exists
                    ? ($writable ? $label . '可写' : $label . '不可写，请调整目录权限')
                    : $label . '不存在',
            ];
        }

        $themeChecks = [];
        foreach ([
            'index' => '首页模板',
            'pay' => '支付页模板',
            'cashier' => '收银台模板',
            'user' => '商户中心模板',
            'admin' => '后台中心模板',
        ] as $type => $label) {
            $path = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type;
            $entry = $path . DIRECTORY_SEPARATOR . 'index.html';
            if ($type === 'cashier') {
                $entry = $path . DIRECTORY_SEPARATOR . 'index.html';
            }
            $exists = is_dir($path) && (is_file($entry) || is_file($path . DIRECTORY_SEPARATOR . 'manifest.json'));
            $themeChecks[] = [
                'template_type' => $type,
                'template_type_label' => $label,
                'exists' => $exists,
                'can_one_click_install' => false,
                'recommend_app' => null,
                'message' => $exists ? $label . '已安装' : $label . '目录或入口文件缺失',
            ];
        }

        $redisDsn = trim((string) getenv('XARR_REDIS_DSN'));
        Response::success([
            'permission_checks' => $permissionChecks,
            'theme_checks' => $themeChecks,
            'redis_check' => [
                'configured' => $redisDsn !== '',
                'connected' => false,
                'address' => $redisDsn,
                'db' => 0,
                'has_pass' => false,
                'message' => $redisDsn === ''
                    ? '当前未配置 Redis，本地 PHP 版本使用数据库和文件运行态'
                    : '当前 PHP 版本未内置 Redis 客户端连接检测',
            ],
        ]);
    }

    public function appItemsList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $items = $this->appItems();
        $type = trim((string) ($params['type'] ?? ''));
        $query = trim((string) ($params['query'] ?? ''));
        if ($type !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => (string) ($item['type'] ?? '') === $type,
            ));
        }
        if ($query !== '') {
            $items = array_values(array_filter($items, static function (array $item) use ($query): bool {
                $haystack = implode(' ', [
                    (string) ($item['name'] ?? ''),
                    (string) ($item['title'] ?? ''),
                    (string) ($item['description'] ?? ''),
                ]);
                return stripos($haystack, $query) !== false;
            }));
        }
        Response::success(['list' => $items, 'count' => count($items), 'total' => count($items)]);
    }

    public function appItemsScanLocalPlugins(Request $request): never
    {
        $this->staff($request);
        $groups = [];
        $typeLabels = [
            'pay' => '支付插件',
            'sms' => '短信插件',
            'storage' => '存储插件',
        ];

        foreach (PluginRuntime::diagnostics() as $diagnostic) {
            $manifest = is_array($diagnostic['manifest'] ?? null) ? $diagnostic['manifest'] : [];
            $type = trim((string) ($manifest['type'] ?? 'pay'));
            if (!isset($typeLabels[$type])) {
                continue;
            }
            $name = trim((string) ($manifest['name'] ?? $diagnostic['directory'] ?? ''));
            if ($name === '' || str_starts_with($name, '.')) {
                continue;
            }
            $valid = ($diagnostic['valid'] ?? false) === true;
            $group = $groups[$type] ?? [
                'plugin_type' => $type,
                'type_name' => $typeLabels[$type],
                'plugins' => [],
            ];
            $group['plugins'][] = [
                'name' => $name,
                'path' => 'plugins/' . $type . '/' . (string) ($diagnostic['directory'] ?? $name),
                'title' => (string) ($manifest['title'] ?? $name),
                'description' => (string) ($manifest['description'] ?? ''),
                'version' => (string) ($manifest['version'] ?? ''),
                'valid' => $valid,
                'errors' => array_values(array_map('strval', $diagnostic['errors'] ?? [])),
                'installed' => $valid && PluginRuntime::manifest($name) !== null,
            ];
            $groups[$type] = $group;
        }

        Response::success(['plugins' => array_values($groups)]);
    }

    public function appItemsLoadLocalPlugin(Request $request): never
    {
        $this->staff($request);
        $type = trim((string) $request->input('plugin_type', ''));
        $name = trim((string) $request->input('plugin_name', ''));
        if ($type !== 'pay') {
            Response::error('本地插件类型无效', 422, 422);
        }
        if ($name === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $name) !== 1) {
            Response::error('本地插件名称无效', 422, 422);
        }

        $manifest = PluginRuntime::manifest($name);
        if ($manifest === null) {
            foreach (PluginRuntime::diagnostics() as $diagnostic) {
                if ((string) ($diagnostic['directory'] ?? '') !== $name) {
                    continue;
                }
                $errors = array_values(array_map('strval', $diagnostic['errors'] ?? []));
                Response::error('插件清单无效：' . implode('；', $errors), 422, 422);
            }
            Response::error('本地插件不存在', 404, 404);
        }

        PaymentPluginRegistry::clearCache();
        Response::success([
            'plugin_uuid' => $name,
            'plugin_name' => $name,
            'list' => $this->appItems(),
        ], '本地插件已加载');
    }

    public function appItemsScanTemplates(Request $request): never
    {
        $this->staff($request);
        $root = dirname(__DIR__, 3);
        $types = [
            'index' => '首页模板',
            'pay' => '支付页模板',
            'cashier' => '收银台模板',
            'user' => '商户中心模板',
            'admin' => '后台中心模板',
        ];
        $groups = [];
        foreach ($types as $type => $label) {
            $directory = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type;
            $templates = [];
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [] as $manifestPath) {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                if (!is_array($manifest)) {
                    continue;
                }
                $templateRoot = dirname($manifestPath);
                $name = trim((string) ($manifest['name'] ?? basename($templateRoot)));
                if ($name === '' || str_starts_with($name, '.')) {
                    continue;
                }
                $templates[] = [
                    'name' => $name,
                    'path' => str_replace('\\', '/', substr($templateRoot, strlen($root) + 1)),
                    'title' => (string) ($manifest['title'] ?? $name),
                    'version' => (string) ($manifest['version'] ?? ''),
                    'installed' => false,
                    'app_uuid' => $type . ':' . basename($templateRoot),
                ];
            }
            if ($templates !== []) {
                $groups[] = [
                    'template_type' => $type,
                    'type_name' => $label,
                    'templates' => $templates,
                ];
            }
        }
        Response::success(['templates' => $groups]);
    }

    public function appItemsLoadLocalTemplate(Request $request): never
    {
        $this->staff($request);
        $type = trim((string) $request->input('template_type', ''));
        $name = trim((string) $request->input('template_name', ''));
        if (!in_array($type, ['index', 'pay', 'cashier', 'user', 'admin'], true)
            || $name === ''
            || preg_match('/^[a-zA-Z0-9._-]+$/', $name) !== 1
        ) {
            Response::error('本地主题参数无效', 422, 422);
        }
        $root = dirname(__DIR__, 3);
        $path = realpath($root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . $name);
        $base = realpath($root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type);
        if ($path === false || $base === false || dirname($path) !== $base || !is_file($path . DIRECTORY_SEPARATOR . 'manifest.json')) {
            Response::error('本地主题不存在或清单无效', 404, 404);
        }
        Response::success([
            'template_uuid' => $type . ':' . $name,
            'template_type' => $type,
            'template_name' => $name,
        ], '本地主题已加载');
    }

    public function appItemsAction(Request $request, string $action): never
    {
        $this->staff($request);
        if (!in_array($action, ['enable', 'disable', 'reload', 'uninstall'], true)) {
            Response::error('应用操作不支持', 422, 422);
        }

        $uuid = trim((string) (
            $request->input('uuid')
            ?? $request->input('plugin_name')
            ?? $request->input('name')
            ?? ''
        ));
        if ($uuid === '') {
            Response::error('插件名称不能为空', 422, 422);
        }

        try {
            $item = $this->findAppItem($uuid);
            $name = (string) $item['plugin_name'];
            if (!in_array((int) ($item['type'] ?? 0), [1, 4, 6], true)) {
                throw new \RuntimeException('该应用类型不支持启用、停止或重载');
            }
            if ($action === 'enable') {
                PluginRuntime::setState($name, 'enable');
                PluginRuntime::setState($name, 'start');
                $message = '插件已启用并启动';
            } elseif ($action === 'disable') {
                PluginRuntime::setState($name, 'disable');
                PluginRuntime::setState($name, 'stop');
                $message = '插件已禁用并停止';
            } elseif ($action === 'reload') {
                if (PluginRuntime::manifest($name) === null) {
                    throw new \RuntimeException('插件不存在');
                }
                PaymentPluginRegistry::clearCache();
                $message = '插件已重载';
            } else {
                PluginRuntime::remove($name);
                $message = '插件已卸载';
            }
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422, 422);
        }

        Response::success(['list' => $this->appItems()], $message);
    }

    public function appItemsBatchAction(Request $request, string $action): never
    {
        $this->staff($request);
        if (!in_array($action, ['enable', 'disable', 'uninstall'], true)) {
            Response::error('批量应用操作不支持', 422, 422);
        }

        $uuids = $request->input('uuids', []);
        if (is_string($uuids)) {
            $decoded = json_decode($uuids, true);
            $uuids = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $uuids);
        }
        if (!is_array($uuids)) {
            Response::error('请选择要操作的插件', 422, 422);
        }

        $success = 0;
        $failures = [];
        foreach (array_values(array_unique(array_filter(array_map('strval', $uuids)))) as $uuid) {
            try {
                $item = $this->findAppItem($uuid);
                $name = (string) $item['plugin_name'];
                if (!in_array((int) ($item['type'] ?? 0), [1, 4, 6], true)) {
                    throw new \RuntimeException('该应用类型不支持批量启停');
                }
                if ($action === 'enable') {
                    PluginRuntime::setState($name, 'enable');
                    PluginRuntime::setState($name, 'start');
                } elseif ($action === 'disable') {
                    PluginRuntime::setState($name, 'disable');
                    PluginRuntime::setState($name, 'stop');
                } else {
                    PluginRuntime::remove($name);
                }
                $success++;
            } catch (\Throwable $exception) {
                $failures[] = ['name' => $uuid, 'message' => $exception->getMessage()];
            }
        }

        $failed = count($failures);
        $label = $action === 'enable' ? '启动' : ($action === 'disable' ? '停止' : '卸载');
        Response::success([
            'success_count' => $success,
            'failed_count' => $failed,
            'failures' => $failures,
            'list' => $this->appItems(),
        ], $failed === 0 ? "批量{$label}完成" : "批量{$label}完成，部分插件失败");
    }

    public function smsChannelList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM sms_channel ORDER BY id DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['options'] = $this->jsonArray($row['options'] ?? '{}');
        }
        unset($row);
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function smsChannelWrite(Request $request): never
    {
        $action = $request->path();
        if (str_ends_with($action, '/remove')) {
            $this->deleteSimple('sms_channel', $request, '短信渠道不存在', '短信渠道已删除');
        }
        if (str_ends_with($action, '/switch-status')) {
            $this->switchSimple('sms_channel', $request, '短信渠道状态已更新');
        }
        $this->writeSmsChannel($request, str_ends_with($action, '/edit') ? $this->id($request) : null);
    }

    public function smsChannelTest(Request $request): never
    {
        $this->staff($request);
        Response::error('本地版未配置短信服务商', 501, 501);
    }

    public function smsPluginList(Request $request): never
    {
        $this->staff($request);
        $items = [];
        foreach ($this->pluginManifestsByType('sms') as $manifest) {
            unset($manifest['_path']);
            $items[] = $manifest;
        }
        Response::success(['list' => $items, 'count' => count($items), 'total' => count($items)]);
    }

    public function smsPluginFormItems(Request $request): never
    {
        $this->staff($request);
        $pluginName = trim((string) ($request->input('plugin_name') ?? $request->input('name') ?? ''));
        if ($pluginName === '') {
            Response::success(['plugin_name' => '', 'items' => []]);
        }
        foreach ($this->pluginManifestsByType('sms') as $manifest) {
            $name = (string) ($manifest['name'] ?? $manifest['plugin_name'] ?? $manifest['uuid'] ?? '');
            if ($name !== $pluginName) {
                continue;
            }
            $items = $manifest['form_items'] ?? $manifest['formItems'] ?? $manifest['config'] ?? [];
            Response::success([
                'plugin_name' => $pluginName,
                'items' => is_array($items) ? $items : [],
                'manifest' => $manifest,
            ]);
        }
        Response::error('短信插件不存在', 404, 404);
    }

    public function runtime(Request $request): never
    {
        $this->staff($request);
        $pid = (int) (getmypid() ?: 0);
        $startedAt = $this->processStartedAt($pid);
        $memory = $this->systemMemory();
        $cpu = $this->systemCpu();

        Response::success([
            'sysOsName' => php_uname('s') . ' ' . php_uname('r'),
            'sysOsArch' => php_uname('m'),
            'sysComputerName' => (string) (gethostname() ?: php_uname('n')),
            'pid' => $pid,
            'goRunTime' => $this->durationText(max(0, time() - $startedAt)),
            'goStartTime' => $startedAt,
            'goUsed' => memory_get_usage(true),
            'runUser' => $this->runtimeUser(),
            'cpuNum' => $cpu['count'],
            'cpuUsed' => number_format($cpu['usage'], 2, '.', '') . '%',
            'cpuAvg5' => number_format($cpu['avg5'], 2, '.', ''),
            'cpuAvg15' => number_format($cpu['avg15'], 2, '.', ''),
            'memTotal' => $memory['total'],
            'memUsed' => $memory['used'],
            'memFree' => $memory['free'],
            'memUsage' => number_format($memory['usage'], 2, '.', '') . '%',
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'timezone' => date_default_timezone_get(),
        ]);
    }

    public function checkVersion(Request $request): never
    {
        $this->staff($request);
        Response::error('当前版本未配置在线版本检查服务', 501, 501);
    }

    public function versionLogs(Request $request): never
    {
        $this->staff($request);
        Response::error('当前版本未配置版本历史服务', 501, 501);
    }

    public function externalSystemAction(Request $request, string $action): never
    {
        $this->staff($request);
        Response::error('本地版不允许通过后台停止、重启或在线更新服务: ' . $action, 501, 501);
    }

    private function processStartedAt(int $pid): int
    {
        if ($pid > 0 && is_readable('/proc/' . $pid . '/stat') && is_readable('/proc/stat')) {
            $stat = (string) file_get_contents('/proc/' . $pid . '/stat');
            if (preg_match('/\)\s+(.+)$/', $stat, $match) === 1) {
                $fields = preg_split('/\s+/', trim($match[1])) ?: [];
                $ticks = (int) ($fields[19] ?? 0);
                $bootTime = $this->linuxBootTime();
                $clockTicks = $this->clockTicks();
                if ($ticks > 0 && $bootTime > 0 && $clockTicks > 0) {
                    return $bootTime + (int) floor($ticks / $clockTicks);
                }
            }
        }

        return (int) ($_SERVER['REQUEST_TIME'] ?? time());
    }

    private function linuxBootTime(): int
    {
        $stat = is_readable('/proc/stat') ? (string) file_get_contents('/proc/stat') : '';
        foreach (preg_split('/\R/', $stat) ?: [] as $line) {
            if (preg_match('/^btime\s+(\d+)$/', trim($line), $match) === 1) {
                return (int) $match[1];
            }
        }
        return 0;
    }

    private function clockTicks(): int
    {
        return 100;
    }

    /** @return array{total:int, used:int, free:int, usage:float} */
    private function systemMemory(): array
    {
        $info = [];
        if (is_readable('/proc/meminfo')) {
            foreach (preg_split('/\R/', (string) file_get_contents('/proc/meminfo')) ?: [] as $line) {
                if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', trim($line), $match) === 1) {
                    $info[$match[1]] = (int) $match[2] * 1024;
                }
            }
        }

        $total = (int) ($info['MemTotal'] ?? 0);
        $free = (int) ($info['MemAvailable'] ?? $info['MemFree'] ?? 0);
        if ($total <= 0) {
            $total = max($this->iniBytes((string) ini_get('memory_limit')), memory_get_usage(true));
            $free = max(0, $total - memory_get_usage(true));
        }
        $used = max(0, $total - $free);

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'usage' => $total > 0 ? min(100.0, max(0.0, ($used / $total) * 100)) : 0.0,
        ];
    }

    /** @return array{count:int, usage:float, avg5:float, avg15:float} */
    private function systemCpu(): array
    {
        $count = $this->cpuCount();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        $avg5 = is_array($load) ? (float) ($load[1] ?? $load[0] ?? 0) : 0.0;
        $avg15 = is_array($load) ? (float) ($load[2] ?? $load[0] ?? 0) : 0.0;
        $usage = $this->cpuUsagePercent($count, is_array($load) ? (float) ($load[0] ?? 0) : 0.0);

        return [
            'count' => $count,
            'usage' => $usage,
            'avg5' => $avg5,
            'avg15' => $avg15,
        ];
    }

    private function cpuCount(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $content = (string) file_get_contents('/proc/cpuinfo');
            $count = preg_match_all('/^processor\s*:/m', $content);
            if ($count > 0) {
                return $count;
            }
        }
        $windows = filter_var((string) getenv('NUMBER_OF_PROCESSORS'), FILTER_VALIDATE_INT);
        return is_int($windows) && $windows > 0 ? $windows : 1;
    }

    private function cpuUsagePercent(int $cpuCount, float $loadAvg1): float
    {
        $current = $this->readCpuTimes();
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'var';
        $snapshot = $root . DIRECTORY_SEPARATOR . 'system-runtime-cpu.json';
        if ($current !== null) {
            $previous = is_readable($snapshot) ? json_decode((string) file_get_contents($snapshot), true) : null;
            if (!is_dir($root)) {
                @mkdir($root, 0775, true);
            }
            @file_put_contents($snapshot, json_encode($current, JSON_UNESCAPED_SLASHES));
            if (is_array($previous) && isset($previous['total'], $previous['idle'])) {
                $totalDelta = (int) $current['total'] - (int) $previous['total'];
                $idleDelta = (int) $current['idle'] - (int) $previous['idle'];
                if ($totalDelta > 0) {
                    return min(100.0, max(0.0, (1 - ($idleDelta / $totalDelta)) * 100));
                }
            }
        }

        return $cpuCount > 0 ? min(100.0, max(0.0, ($loadAvg1 / $cpuCount) * 100)) : 0.0;
    }

    /** @return array{total:int, idle:int}|null */
    private function readCpuTimes(): ?array
    {
        if (!is_readable('/proc/stat')) {
            return null;
        }
        $line = strtok((string) file_get_contents('/proc/stat'), "\n");
        if (!is_string($line) || !str_starts_with($line, 'cpu ')) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        array_shift($parts);
        $values = array_map('intval', $parts);
        $total = array_sum($values);
        $idle = (int) ($values[3] ?? 0) + (int) ($values[4] ?? 0);
        return ['total' => $total, 'idle' => $idle];
    }

    private function durationText(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;
        return sprintf('%d天 %02d:%02d:%02d', $days, $hours, $minutes, $seconds);
    }

    private function runtimeUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && isset($user['name'])) {
                return (string) $user['name'];
            }
        }
        return (string) (getenv('USERNAME') ?: getenv('USER') ?: get_current_user());
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return memory_get_usage(true);
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function redisTest(Request $request): never
    {
        $this->staff($request);
        Response::error('Redis 未配置', 501, 501);
    }

    public function redisSave(Request $request): never
    {
        $this->staff($request);
        Response::error('本地版未启用 Redis 配置写入', 501, 501);
    }

    public function appStore(Request $request, string $action): never
    {
        $this->staff($request);
        Response::error('当前版本未配置在线应用商店服务：' . $action, 501, 501);
    }

    public function sharedChannelCapability(Request $request): never
    {
        $this->staff($request);
        Response::success(['enabled' => false, 'providers' => []]);
    }

    public function sharedChannelList(Request $request): never
    {
        $this->staff($request);
        Response::error('共享通道存储和服务商集成尚未实现', 501, 501);
    }

    public function sharedChannelWrite(Request $request): never
    {
        $this->staff($request);
        Response::error('本地版未配置共享通道服务', 501, 501);
    }

    public function sharedChannelRemove(Request $request): never
    {
        $this->staff($request);
        Response::error('本地版未配置共享通道服务', 501, 501);
    }

    /** @return list<array<string, mixed>> */
    private function appItems(): array
    {
        $typeMap = [
            'pay' => 1,
            'sms' => 4,
            'storage' => 6,
            'index' => 2,
            'pay_template' => 3,
            'cashier' => 5,
        ];
        $items = [];
        foreach (PluginRuntime::manifests() as $manifest) {
            unset($manifest['_path']);
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $enabled = ($manifest['enabled'] ?? false) === true;
            $running = ($manifest['running'] ?? false) === true;
            $manifest['uuid'] = (string) ($manifest['uuid'] ?? $name);
            $manifest['plugin_name'] = $name;
            $manifest['type'] = $typeMap[(string) ($manifest['type'] ?? '')] ?? 0;
            $manifest['status'] = $enabled ? 1 : 2;
            $manifest['running'] = $running;
            $manifest['install_status'] = 2;
            $manifest['can_enable'] = in_array((int) $manifest['type'], [1, 4, 6], true);
            $manifest['can_disable'] = $manifest['can_enable'];
            $manifest['can_reload'] = $manifest['can_enable'];
            $manifest['can_uninstall'] = true;
            $manifest['author'] = (string) ($manifest['author'] ?? '本地插件');
            try {
                $manifest['config'] = PluginConfigStore::read(Database::connection(), $name);
            } catch (\Throwable) {
                $manifest['config'] = [];
            }
            $items[] = $manifest;
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private function findAppItem(string $uuid): array
    {
        $uuid = trim($uuid);
        foreach ($this->appItems() as $item) {
            if ($uuid === (string) ($item['uuid'] ?? '')
                || $uuid === (string) ($item['plugin_name'] ?? '')
                || $uuid === (string) ($item['name'] ?? '')
            ) {
                return $item;
            }
        }
        Response::error('应用不存在或未安装', 404, 404);
    }

    public function workOrderCategoryList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT * FROM work_order_category ORDER BY sort ASC,id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function workOrderCategoryCreate(Request $request): never
    {
        $this->writeWorkOrderCategory($request, null);
    }

    public function workOrderCategoryEdit(Request $request): never
    {
        $this->writeWorkOrderCategory($request, $this->id($request));
    }

    public function workOrderCategoryRemove(Request $request): never
    {
        $this->staff($request);
        $id = $this->id($request);
        $db = Database::connection();
        $used = $db->prepare('SELECT COUNT(*) FROM work_order WHERE category_id=:id');
        $used->execute([':id' => $id]);
        if ((int) $used->fetchColumn() > 0) {
            Response::error('该分类仍被工单使用，不能删除', 409, 409);
        }
        $delete = $db->prepare('DELETE FROM work_order_category WHERE id=:id');
        $delete->execute([':id' => $id]);
        $this->affected($delete->rowCount(), '工单分类不存在');
        Response::success(null, '工单分类已删除');
    }

    public function workOrderCategorySwitch(Request $request): never
    {
        $this->switchSimple('work_order_category', $request, '工单分类状态已更新');
    }

    public function workOrderList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $rows = Database::connection()->query('SELECT w.*, u.username, u.merchant_name, c.name AS cate_name FROM work_order w LEFT JOIN user u ON u.id=w.uid LEFT JOIN work_order_category c ON c.id=w.category_id ORDER BY w.id DESC')->fetchAll();
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function workOrderDetail(Request $request): never
    {
        $this->staff($request);
        $id = $this->id($request);
        $query = Database::connection()->prepare('SELECT w.*, u.username, u.merchant_name, c.name AS cate_name FROM work_order w LEFT JOIN user u ON u.id=w.uid LEFT JOIN work_order_category c ON c.id=w.category_id WHERE w.id=:id LIMIT 1');
        $query->execute([':id' => $id]);
        $row = $query->fetch();
        if (!is_array($row)) {
            Response::error('工单不存在', 404, 404);
        }
        $row['attachments'] = $this->jsonArray($row['attachments'] ?? '[]');
        Response::success($row);
    }

    public function workOrderEdit(Request $request): never
    {
        $this->staff($request);
        $p = $request->all();
        $id = (int) ($p['id'] ?? 0);
        $isCreate = $id <= 0 && str_ends_with($request->path(), '/create');
        if (!$isCreate && $id <= 0) {
            Response::error('记录 ID 无效', 422, 422);
        }
        $uid = (int) ($p['uid'] ?? 0);
        $categoryId = (int) ($p['category_id'] ?? $p['cate_id'] ?? 0);
        $title = trim((string) ($p['title'] ?? ''));
        $content = trim((string) ($p['content'] ?? ''));
        if ($title === '' || $content === '') {
            Response::error('工单标题和内容不能为空', 422, 422);
        }
        $db = Database::connection();
        $category = $db->prepare('SELECT id FROM work_order_category WHERE id=:id LIMIT 1');
        $category->execute([':id' => $categoryId]);
        if (!$category->fetchColumn()) {
            Response::error('请选择有效的工单分类', 422, 422);
        }
        $status = $this->workOrderStatus($p['status'] ?? 1);
        $priority = filter_var($p['priority'] ?? 0, FILTER_VALIDATE_INT);
        if ($priority === false || $priority < 0 || $priority > 2) {
            Response::error('工单优先级只能为 0、1 或 2', 422, 422);
        }
        $now = time();
        if ($isCreate) {
            $merchant = $db->prepare('SELECT id FROM user WHERE id=:id AND status=1 LIMIT 1');
            $merchant->execute([':id' => $uid]);
            if (!$merchant->fetchColumn()) {
                Response::error('请选择有效的商户', 422, 422);
            }
            $attachments = $this->workOrderAttachments($p['attachments'] ?? [], 0);
            $insert = $db->prepare('INSERT INTO work_order (uid,category_id,title,content,attachments,status,priority,created_at,updated_at,closed_at) VALUES (:uid,:category_id,:title,:content,:attachments,:status,:priority,:created_at,:updated_at,:closed_at)');
            $insert->execute([
                ':uid' => $uid,
                ':category_id' => $categoryId,
                ':title' => $title,
                ':content' => $content,
                ':attachments' => json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':status' => $status,
                ':priority' => $priority,
                ':created_at' => $now,
                ':updated_at' => $now,
                ':closed_at' => $status === 3 ? $now : null,
            ]);
            Response::success(['id' => (int) $db->lastInsertId()], '工单已创建');
        }
        $q = $db->prepare('UPDATE work_order SET category_id=:category_id,title=:title,content=:content,status=:status,priority=:priority,updated_at=:updated_at,closed_at=:closed_at WHERE id=:id');
        $q->execute([
            ':category_id' => $categoryId,
            ':title' => $title,
            ':content' => $content,
            ':status' => $status,
            ':priority' => $priority,
            ':updated_at' => $now,
            ':closed_at' => $status === 3 ? $now : null,
            ':id' => $id,
        ]);
        $this->affected($q->rowCount(), '工单不存在');
        Response::success(['id' => $id], '工单已保存');
    }

    public function workOrderRemove(Request $request): never
    {
        $this->staff($request);
        $db = Database::connection();
        $id = $this->id($request);
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM work_order_reply WHERE pid=:id')->execute([':id' => $id]);
            $delete = $db->prepare('DELETE FROM work_order WHERE id=:id');
            $delete->execute([':id' => $id]);
            if ($delete->rowCount() === 0) {
                $db->rollBack();
                Response::error('工单不存在', 404, 404);
            }
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        Response::success(null, '工单已删除');
    }

    public function workOrderSwitch(Request $request): never
    {
        $this->staff($request);
        $status = $this->workOrderStatus($request->input('status'));
        $now = time();
        $query = Database::connection()->prepare('UPDATE work_order SET status=:status,updated_at=:updated_at,closed_at=:closed_at WHERE id=:id');
        $query->execute([
            ':status' => $status,
            ':updated_at' => $now,
            ':closed_at' => $status === 3 ? $now : null,
            ':id' => $this->id($request),
        ]);
        $this->affected($query->rowCount(), '工单不存在');
        Response::success(null, '工单状态已更新');
    }

    public function workOrderReplyList(Request $request): never
    {
        $this->staff($request);
        $params = $request->all();
        $db = Database::connection();
        $pid = (int) ($params['pid'] ?? 0);
        if ($pid > 0) {
            $query = $db->prepare('SELECT r.*, u.username, u.merchant_name FROM work_order_reply r LEFT JOIN user u ON u.id=r.uid WHERE r.pid=:pid ORDER BY r.id ASC');
            $query->execute([':pid' => $pid]);
            $rows = $query->fetchAll();
        } else {
            $rows = $db->query('SELECT r.*, u.username, u.merchant_name FROM work_order_reply r LEFT JOIN user u ON u.id=r.uid ORDER BY r.id ASC')->fetchAll();
        }
        Response::success($this->paginate($this->filter($rows, $params), $params));
    }

    public function workOrderReplyCreate(Request $request): never
    {
        $this->staff($request);
        $p = $request->all();
        $pid = (int) ($p['pid'] ?? $p['work_order_id'] ?? 0);
        $content = trim((string) ($p['content'] ?? ''));
        if ($pid <= 0 || $content === '') {
            Response::error('工单和回复内容不能为空', 422, 422);
        }
        $check = Database::connection()->prepare('SELECT COUNT(*) FROM work_order WHERE id=:id');
        $check->execute([':id' => $pid]);
        if ((int) $check->fetchColumn() === 0) {
            Response::error('工单不存在', 404, 404);
        }
        $now = time();
        $attachments = $this->workOrderAttachments($p['attachments'] ?? [], 0);
        $q = Database::connection()->prepare('INSERT INTO work_order_reply (pid,uid,content,attachments,status,created_at,updated_at) VALUES (:pid,0,:content,:attachments,1,:created_at,:updated_at)');
        $q->execute([
            ':pid' => $pid,
            ':content' => $content,
            ':attachments' => json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        Database::connection()->prepare('UPDATE work_order SET status=2,updated_at=:updated_at,closed_at=NULL WHERE id=:id')->execute([':updated_at' => $now, ':id' => $pid]);
        Response::success(['id' => (int) Database::connection()->lastInsertId()], '回复已提交');
    }

    public function workOrderReplyEdit(Request $request): never
    {
        $this->staff($request);
        $id = $this->id($request);
        $content = trim((string) $request->input('content', ''));
        if ($content === '') {
            Response::error('回复内容不能为空', 422, 422);
        }
        $q = Database::connection()->prepare('UPDATE work_order_reply SET content=:content,status=:status,updated_at=:updated_at WHERE id=:id');
        $q->execute([':content' => $content, ':status' => $this->status($request->input('status', 1)), ':updated_at' => time(), ':id' => $id]);
        $this->affected($q->rowCount(), '回复不存在');
        Response::success(['id' => $id], '回复已保存');
    }

    public function workOrderReplyRemove(Request $request): never
    {
        $this->staff($request);
        $q = Database::connection()->prepare('DELETE FROM work_order_reply WHERE id=:id');
        $q->execute([':id' => $this->id($request)]);
        $this->affected($q->rowCount(), '回复不存在');
        Response::success(null, '回复已删除');
    }

    public function workOrderReplySwitch(Request $request): never
    {
        $this->staff($request);
        $q = Database::connection()->prepare('UPDATE work_order_reply SET status=:status,updated_at=:updated_at WHERE id=:id');
        $q->execute([':status' => $this->status($request->input('status')), ':updated_at' => time(), ':id' => $this->id($request)]);
        $this->affected($q->rowCount(), '回复不存在');
        Response::success(null, '回复状态已更新');
    }

    private function writeChannelGateway(Request $request, ?int $id): never
    {
        $this->staff($request);
        $values = $this->channelGatewayValues($request->all());
        $db = Database::connection();
        if ($id !== null) {
            $exists = $db->prepare('SELECT COUNT(*) FROM channel_gateway WHERE id = :id');
            $exists->execute([':id' => $id]);
            if ((int) $exists->fetchColumn() === 0) {
                Response::error('支付网关不存在', 404, 404);
            }
            $query = $db->prepare('UPDATE channel_gateway SET name=:name,addr=:addr,channel_code=:channel_code,pay_type=:pay_type,status=:status,options=:options,active_time=:active_time,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            Response::success(['id' => $id], '支付网关已保存');
        }
        $query = $db->prepare('INSERT INTO channel_gateway (name,addr,channel_code,pay_type,status,options,active_time,created_at,updated_at) VALUES (:name,:addr,:channel_code,:pay_type,:status,:options,:active_time,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '支付网关已创建');
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function channelGatewayValues(array $params): array
    {
        $name = trim((string) ($params['name'] ?? ''));
        $addr = trim((string) ($params['addr'] ?? $params['url'] ?? ''));
        $channelCode = trim((string) ($params['channel_code'] ?? ''));
        $payType = trim((string) ($params['pay_type'] ?? ''));
        if ($name === '' || $addr === '') {
            Response::error('网关名称和地址不能为空', 422, 422);
        }
        if ($channelCode === '' || $payType === '') {
            Response::error('支付方式和通道不能为空', 422, 422);
        }
        $parts = parse_url($addr);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === '') {
            Response::error('网关地址必须是完整的 HTTP 或 HTTPS 地址', 422, 422);
        }

        $db = Database::connection();
        $type = $db->prepare('SELECT status FROM pay_type WHERE value = :value LIMIT 1');
        $type->execute([':value' => $payType]);
        if ((int) $type->fetchColumn() !== 1) {
            Response::error('支付方式不存在或已停用', 422, 422);
        }

        $channel = $db->prepare('SELECT status, type, plugin_name FROM pay_channel WHERE code = :code LIMIT 1');
        $channel->execute([':code' => $channelCode]);
        $channelRow = $channel->fetch();
        if (!is_array($channelRow)) {
            Response::error('支付通道不存在', 404, 404);
        }
        if ((int) ($channelRow['status'] ?? 0) !== 1) {
            Response::error('支付通道已停用', 422, 422);
        }
        if ((string) ($channelRow['type'] ?? '') !== $payType) {
            Response::error('支付方式与通道不匹配', 422, 422);
        }
        $pluginName = trim((string) ($channelRow['plugin_name'] ?? ''));
        if ($pluginName === '' || !PaymentPluginRegistry::usableForPayType($pluginName, $payType)) {
            Response::error('支付插件未启用或不支持当前支付方式', 422, 422);
        }

        $options = $params['options'] ?? [];
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            if (!is_array($decoded)) {
                Response::error('网关配置必须是有效的 JSON 对象', 422, 422);
            }
            $options = $decoded;
        }
        if (!is_array($options)) {
            Response::error('网关配置格式无效', 422, 422);
        }
        $activeTime = filter_var($params['active_time'] ?? 0, FILTER_VALIDATE_INT);
        if ($activeTime === false || (int) $activeTime < 0) {
            Response::error('网关启用时间无效', 422, 422);
        }

        return [
            ':name' => $name,
            ':addr' => $addr,
            ':channel_code' => $channelCode,
            ':pay_type' => $payType,
            ':status' => $this->status($params['status'] ?? 1),
            ':options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':active_time' => (int) $activeTime,
            ':updated_at' => time(),
        ];
    }

    private function writeDomainWhite(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $domain = trim((string) ($p['domain'] ?? ''));
        if ($domain === '') {
            Response::error('域名不能为空', 422, 422);
        }
        $values = [
            ':domain' => $domain,
            ':type' => (int) ($p['type'] ?? 1),
            ':username' => trim((string) ($p['username'] ?? '')),
            ':remark' => trim((string) ($p['remark'] ?? '')),
            ':reason' => trim((string) ($p['reason'] ?? '')),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $query = $db->prepare('UPDATE domain_white SET domain=:domain,type=:type,username=:username,remark=:remark,reason=:reason,status=:status,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            $this->affected($query->rowCount(), '域名白名单不存在');
            Response::success(['id' => $id], '域名白名单已保存');
        }
        $query = $db->prepare('INSERT INTO domain_white (domain,type,username,remark,reason,status,created_at,updated_at) VALUES (:domain,:type,:username,:remark,:reason,:status,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '域名白名单已创建');
    }

    private function writeBlackData(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $value = trim((string) ($p['black_value'] ?? $p['value'] ?? ''));
        if ($value === '') {
            Response::error('黑名单值不能为空', 422, 422);
        }
        $values = [
            ':type' => (int) ($p['type'] ?? 1),
            ':black_value' => $value,
            ':reason' => trim((string) ($p['reason'] ?? '')),
            ':remark' => trim((string) ($p['remark'] ?? '')),
            ':expire_at' => (int) ($p['expire_at'] ?? 0),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $query = $db->prepare('UPDATE black_data SET type=:type,black_value=:black_value,reason=:reason,remark=:remark,expire_at=:expire_at,status=:status,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            $this->affected($query->rowCount(), '黑名单记录不存在');
            Response::success(['id' => $id], '黑名单记录已保存');
        }
        $query = $db->prepare('INSERT INTO black_data (type,black_value,reason,remark,expire_at,status,created_at,updated_at) VALUES (:type,:black_value,:reason,:remark,:expire_at,:status,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '黑名单记录已创建');
    }

    private function writeMeal(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? $p['title'] ?? ''));
        if ($name === '') {
            Response::error('套餐名称不能为空', 422, 422);
        }
        $values = [
            ':name' => $name,
            ':price' => (int) ($p['price'] ?? $p['amount'] ?? 0),
            ':days' => (int) ($p['days'] ?? $p['duration'] ?? 0),
            ':description' => trim((string) ($p['description'] ?? '')),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $query = $db->prepare('UPDATE meal SET name=:name,price=:price,days=:days,description=:description,status=:status,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            $this->affected($query->rowCount(), '商户套餐不存在');
            Response::success(['id' => $id], '商户套餐已保存');
        }
        $query = $db->prepare('INSERT INTO meal (name,price,days,description,status,created_at,updated_at) VALUES (:name,:price,:days,:description,:status,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '商户套餐已创建');
    }

    private function writeCardGroup(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            Response::error('卡密分组名称不能为空', 422, 422);
        }
        $values = [
            ':name' => $name,
            ':value_type' => (int) ($p['value_type'] ?? 1),
            ':value' => (int) ($p['value'] ?? 0),
            ':meal_id' => (int) ($p['meal_id'] ?? $p['package_id'] ?? 0),
            ':total_limit' => (int) ($p['total_limit'] ?? 0),
            ':time_limit' => (int) ($p['time_limit'] ?? 0),
            ':day_limit' => (int) ($p['day_limit'] ?? 0),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $query = $db->prepare('UPDATE card_group SET name=:name,value_type=:value_type,value=:value,meal_id=:meal_id,total_limit=:total_limit,time_limit=:time_limit,day_limit=:day_limit,status=:status,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            $this->affected($query->rowCount(), '卡密分组不存在');
            Response::success(['id' => $id], '卡密分组已保存');
        }
        $query = $db->prepare('INSERT INTO card_group (name,value_type,value,meal_id,total_limit,time_limit,day_limit,status,created_at,updated_at) VALUES (:name,:value_type,:value,:meal_id,:total_limit,:time_limit,:day_limit,:status,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '卡密分组已创建');
    }

    private function writeCard(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $secret = trim((string) ($p['secret'] ?? ''));
        $groupId = (int) ($p['group_id'] ?? 0);
        if ($secret === '' || $groupId <= 0) {
            Response::error('卡密和分组不能为空', 422, 422);
        }
        $values = [
            ':group_id' => $groupId,
            ':secret' => $secret,
            ':value_type' => (int) ($p['value_type'] ?? 1),
            ':value' => (int) ($p['value'] ?? 0),
            ':meal_id' => (int) ($p['meal_id'] ?? 0),
            ':status' => (int) ($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $query = $db->prepare('UPDATE card SET group_id=:group_id,secret=:secret,value_type=:value_type,value=:value,meal_id=:meal_id,status=:status,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            $this->affected($query->rowCount(), '卡密不存在');
            Response::success(['id' => $id], '卡密已保存');
        }
        $query = $db->prepare('INSERT INTO card (group_id,secret,value_type,value,meal_id,use_uid,use_time,status,created_at,updated_at) VALUES (:group_id,:secret,:value_type,:value,:meal_id,0,0,:status,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '卡密已创建');
    }

    private function writeProxyPool(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            Response::error('代理池名称不能为空', 422, 422);
        }
        $values = [
            ':name' => $name,
            ':provinces' => trim((string) ($p['provinces'] ?? '')),
            ':city' => trim((string) ($p['city'] ?? '')),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $query = $db->prepare('UPDATE proxy_pool SET name=:name,provinces=:provinces,city=:city,status=:status,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            $this->affected($query->rowCount(), '代理池不存在');
            Response::success(['id' => $id], '代理池已保存');
        }
        $query = $db->prepare('INSERT INTO proxy_pool (name,provinces,city,status,created_at,updated_at) VALUES (:name,:provinces,:city,:status,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '代理池已创建');
    }

    private function writeQrcodeTemplate(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            Response::error('二维码模板名称不能为空', 422, 422);
        }
        $values = [
            ':name' => $name,
            ':uri' => trim((string) ($p['uri'] ?? '')),
            ':config' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':status' => $this->status($p['status'] ?? 1),
            ':sort' => (int) ($p['sort'] ?? 50),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $query = $db->prepare('UPDATE qrcode_template SET name=:name,uri=:uri,config=:config,status=:status,sort=:sort,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            $this->affected($query->rowCount(), '二维码模板不存在');
            Response::success(['id' => $id], '二维码模板已保存');
        }
        $query = $db->prepare('INSERT INTO qrcode_template (name,uri,config,status,sort,created_at,updated_at) VALUES (:name,:uri,:config,:status,:sort,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '二维码模板已创建');
    }

    private function writeThirdConnectChat(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $label = trim((string) ($p['label'] ?? ''));
        $value = trim((string) ($p['value'] ?? ''));
        if ($label === '' || $value === '') {
            Response::error('聊天方案名称和值不能为空', 422, 422);
        }
        $values = [
            ':label' => $label,
            ':value' => $value,
            ':sort' => (int) ($p['sort'] ?? 50),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $query = $db->prepare('UPDATE third_connect_chat SET label=:label,value=:value,sort=:sort,status=:status,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            $this->affected($query->rowCount(), '聊天方案不存在');
            Response::success(['id' => $id], '聊天方案已保存');
        }
        $query = $db->prepare('INSERT INTO third_connect_chat (label,value,sort,status,created_at,updated_at) VALUES (:label,:value,:sort,:status,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '聊天方案已创建');
    }

    private function writeWorkOrderCategory(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            Response::error('工单分类名称不能为空', 422, 422);
        }
        $values = [':name' => $name, ':status' => $this->status($p['status'] ?? 1), ':sort' => (int) ($p['sort'] ?? 50), ':updated_at' => time()];
        $db = Database::connection();
        if ($id !== null) {
            $q = $db->prepare('UPDATE work_order_category SET name=:name,status=:status,sort=:sort,updated_at=:updated_at WHERE id=:id');
            $q->execute($values + [':id' => $id]);
            $this->affected($q->rowCount(), '工单分类不存在');
            Response::success(['id' => $id], '工单分类已保存');
        }
        $now = time();
        $q = $db->prepare('INSERT INTO work_order_category (name,status,sort,created_at,updated_at) VALUES (:name,:status,:sort,:created_at,:updated_at)');
        $q->execute($values + [':created_at' => $now]);
        Response::success(['id' => (int) $db->lastInsertId()], '工单分类已创建');
    }

    private function workOrderStatus(mixed $value): int
    {
        $status = filter_var($value, FILTER_VALIDATE_INT);
        if ($status === false || !in_array($status, [1, 2, 3], true)) {
            Response::error('工单状态只能为 1、2 或 3', 422, 422);
        }
        return $status;
    }

    /** @return list<string> */
    private function workOrderAttachments(mixed $value, int $uid): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value) || count($value) > 5) {
            Response::error('工单附件格式无效或超过 5 个', 422, 422);
        }
        $urls = array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== ''
        )));
        if (count($urls) !== count($value)) {
            Response::error('工单附件地址无效', 422, 422);
        }
        if ($urls === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($urls), '?'));
        $query = Database::connection()->prepare(
            "SELECT url FROM upload_file WHERE uid=? AND scope='images' AND status=1 AND url IN ({$placeholders})"
        );
        $query->execute(array_merge([$uid], $urls));
        if (count($query->fetchAll(PDO::FETCH_COLUMN)) !== count($urls)) {
            Response::error('附件不存在或不属于当前账号', 422, 422);
        }
        return $urls;
    }

    /** @return array<string,mixed> */
    private function templateCatalogItem(string $root, string $type, string $label): array
    {
        $path = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type;
        return [
            'id' => 'default',
            'value' => 'default',
            'name' => '默认' . $label,
            'title' => '默认' . $label,
            'type' => $type,
            'type_label' => $label,
            'path' => 'public/' . $type,
            'exists' => is_dir($path),
            'has_screenshot' => is_file($path . DIRECTORY_SEPARATOR . 'screenshot.png'),
            'is_default' => true,
        ];
    }

    /** @return array{value:string,label:string} */
    private function templateTypeData(string $type): array
    {
        $types = $this->templateTypesData();
        foreach ($types as $item) {
            if ((string) $item['value'] === $type) {
                return $item;
            }
        }
        Response::error('模板类型无效', 404, 404);
    }

    private function templateTypeLabel(string $type): string
    {
        return match ($type) {
            'index' => '首页模板',
            'pay' => '支付页模板',
            'cashier' => '收银台模板',
            'user' => '商户中心模板',
            'admin' => '后台中心模板',
            default => '模板',
        };
    }

    /** @return list<array{value:string,label:string}> */
    private function templateTypesData(): array
    {
        return [
            ['value' => 'index', 'label' => '首页模板'],
            ['value' => 'pay', 'label' => '支付页模板'],
            ['value' => 'cashier', 'label' => '收银台模板'],
            ['value' => 'user', 'label' => '商户中心模板'],
            ['value' => 'admin', 'label' => '后台中心模板'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function templateListData(string $type): array
    {
        $root = dirname(__DIR__, 3);
        $directory = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type;
        $label = $this->templateTypeData($type)['label'];
        $items = [$this->templateCatalogItem($root, $type, $label)];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                continue;
            }
            $templateRoot = dirname($manifestPath);
            $name = basename($templateRoot);
            $items[] = $manifest + [
                'id' => $type . ':' . $name,
                'value' => $name,
                'name' => (string) ($manifest['name'] ?? $name),
                'title' => (string) ($manifest['title'] ?? $manifest['name'] ?? $name),
                'type' => $type,
                'type_label' => $label,
                'path' => str_replace('\\', '/', substr($templateRoot, strlen($root) + 1)),
                'exists' => true,
                'has_screenshot' => is_file($templateRoot . DIRECTORY_SEPARATOR . 'screenshot.png'),
                'is_default' => false,
            ];
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function templateManifests(string $root, string $type): array
    {
        $items = [];
        $directory = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $type;
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                continue;
            }
            $templateRoot = dirname($manifestPath);
            $name = (string) ($manifest['name'] ?? basename($templateRoot));
            $items[] = $manifest + [
                'id' => $type . ':' . basename($templateRoot),
                'value' => basename($templateRoot),
                'name' => $name,
                'title' => $name,
                'type' => $type,
                'type_label' => match ($type) {
                    'index' => '首页模板',
                    'pay' => '支付页模板',
                    'cashier' => '收银台模板',
                    'user' => '商户中心模板',
                    default => '模板',
                },
                'path' => str_replace('\\', '/', substr($templateRoot, strlen($root) + 1)),
                'exists' => true,
                'has_screenshot' => is_file($templateRoot . DIRECTORY_SEPARATOR . 'screenshot.png'),
                'is_default' => false,
            ];
        }
        return $items;
    }

    private function writeArea(Request $request, ?int $areaId): never
    {
        $this->staff($request);
        $db = Database::connection();
        $parentId = (int) ($request->input('parent_id') ?? $request->input('pid') ?? 0);
        $name = trim((string) ($request->input('name') ?? $request->input('label') ?? ''));
        if ($name === '') {
            Response::error('地区名称不能为空', 422, 422);
        }
        if ($parentId < 0) {
            Response::error('上级地区 ID 无效', 422, 422);
        }
        if ($parentId > 0) {
            $parent = $db->prepare('SELECT area_id FROM area WHERE area_id=:area_id LIMIT 1');
            $parent->execute([':area_id' => $parentId]);
            if ($parent->fetchColumn() === false) {
                Response::error('上级地区不存在', 404, 404);
            }
        }
        $status = filter_var($request->input('status', 1), FILTER_VALIDATE_INT);
        if ($status === false || !in_array((int) $status, [0, 1], true)) {
            Response::error('状态值只能为 0 或 1', 422, 422);
        }
        if ($areaId === null) {
            $requestedId = (int) ($request->input('area_id') ?? $request->input('id') ?? 0);
            if ($requestedId > 0) {
                $areaId = $requestedId;
            } else {
                $areaId = (int) ($db->query('SELECT COALESCE(MAX(area_id), 0) + 1 FROM area')->fetchColumn());
            }
            if ($areaId <= 0) {
                Response::error('无法生成地区 ID', 500, 500);
            }
            $exists = $db->prepare('SELECT COUNT(*) FROM area WHERE area_id=:area_id');
            $exists->execute([':area_id' => $areaId]);
            if ((int) $exists->fetchColumn() > 0) {
                Response::error('地区 ID 已存在', 409, 409);
            }
            $insert = $db->prepare('INSERT INTO area (area_id,parent_id,name,status) VALUES (:area_id,:parent_id,:name,:status)');
            $insert->execute([':area_id' => $areaId, ':parent_id' => $parentId, ':name' => $name, ':status' => (int) $status]);
            Response::success(['area_id' => $areaId, 'parent_id' => $parentId, 'name' => $name, 'status' => (int) $status], '地区已创建');
        }
        $exists = $db->prepare('SELECT COUNT(*) FROM area WHERE area_id=:area_id');
        $exists->execute([':area_id' => $areaId]);
        if ((int) $exists->fetchColumn() === 0) {
            Response::error('地区不存在', 404, 404);
        }
        if ($parentId === $areaId) {
            Response::error('地区不能将自身设为上级地区', 422, 422);
        }
        $update = $db->prepare('UPDATE area SET parent_id=:parent_id,name=:name,status=:status WHERE area_id=:area_id');
        $update->execute([':area_id' => $areaId, ':parent_id' => $parentId, ':name' => $name, ':status' => (int) $status]);
        Response::success(['area_id' => $areaId, 'parent_id' => $parentId, 'name' => $name, 'status' => (int) $status], '地区已保存');
    }

    /** @return list<array<string,string|bool>> */
    private function notificationConnectors(): array
    {
        return [
            ['value' => 'global', 'label' => '全局模板', 'is_global' => true],
            ['value' => 'system', 'label' => '站内通知', 'is_global' => false],
            ['value' => 'email', 'label' => '邮件', 'is_global' => false],
            ['value' => 'sms', 'label' => '短信', 'is_global' => false],
            ['value' => 'wechat', 'label' => '微信公众号', 'is_global' => false],
            ['value' => 'wxpusher', 'label' => 'WxPusher', 'is_global' => false],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function notificationEvents(): array
    {
        $common = [
            ['value' => 'subject', 'label' => '通知标题'],
            ['value' => 'username', 'label' => '商户账号'],
            ['value' => 'merchant_name', 'label' => '商户名称'],
            ['value' => 'order_id', 'label' => '系统订单号'],
            ['value' => 'out_order_id', 'label' => '商户订单号'],
            ['value' => 'trade_amount', 'label' => '交易金额'],
            ['value' => 'pay_type', 'label' => '支付方式'],
            ['value' => 'pay_time', 'label' => '支付时间'],
            ['value' => 'amount', 'label' => '金额'],
            ['value' => 'status', 'label' => '状态'],
            ['value' => 'remark', 'label' => '备注'],
        ];

        return [
            ['value' => 'order_paid', 'label' => '订单支付成功', 'rules' => $common],
            ['value' => 'order_failed', 'label' => '订单支付失败', 'rules' => $common],
            ['value' => 'recharge_paid', 'label' => '余额充值成功', 'rules' => $common],
            ['value' => 'withdraw_submitted', 'label' => '提现申请提交', 'rules' => $common],
            ['value' => 'withdraw_audited', 'label' => '提现审核结果', 'rules' => $common],
            ['value' => 'work_order_reply', 'label' => '工单收到回复', 'rules' => $common],
            ['value' => 'merchant_registered', 'label' => '商户注册成功', 'rules' => $common],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function pluginManifestsByType(string $type): array
    {
        $root = (string) (getenv('XARR_PLUGIN_DIR') ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'plugins');
        $items = [];
        foreach (glob($root . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                continue;
            }
            $manifest['_path'] = $manifestPath;
            $manifest['type'] = $type;
            $manifest['name'] = (string) ($manifest['name'] ?? basename(dirname($manifestPath)));
            $items[] = $manifest;
        }
        return $items;
    }

    private function writeNotification(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $title = trim((string) ($p['title'] ?? ''));
        $content = trim((string) ($p['content'] ?? ''));
        if ($title === '' || $content === '') {
            Response::error('通知标题和内容不能为空', 422, 422);
        }
        $db = Database::connection();
        $values = [
            ':title' => $title,
            ':content' => $content,
            ':type' => trim((string) ($p['type'] ?? 'system')),
            ':target' => trim((string) ($p['target'] ?? '')),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        if ($id !== null) {
            $q = $db->prepare('UPDATE notification SET title=:title, content=:content, type=:type, target=:target, status=:status, updated_at=:updated_at WHERE id=:id');
            $q->execute($values + [':id' => $id]);
            $this->affected($q->rowCount(), '通知不存在');
            Response::success(['id' => $id], '通知已保存');
        }
        $q = $db->prepare('INSERT INTO notification (title,content,type,target,status,created_at,updated_at) VALUES (:title,:content,:type,:target,:status,:updated_at,:updated_at)');
        $q->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '通知已创建');
    }

    private function writeNotificationTemplate(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? $p['title'] ?? $p['template_title'] ?? ''));
        $code = trim((string) ($p['code'] ?? ''));
        if ($name === '' || $code === '') {
            Response::error('模板名称和编码不能为空', 422, 422);
        }
        $db = Database::connection();
        $values = [
            ':name' => $name,
            ':code' => $code,
            ':content' => (string) ($p['template_content'] ?? $p['content'] ?? ''),
            ':channel' => trim((string) ($p['source_type'] ?? $p['channel'] ?? 'system')),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        try {
            if ($id !== null) {
                $q = $db->prepare('UPDATE notification_template SET name=:name,code=:code,content=:content,channel=:channel,status=:status,updated_at=:updated_at WHERE id=:id');
                $q->execute($values + [':id' => $id]);
                $this->affected($q->rowCount(), '通知模板不存在');
                Response::success(['id' => $id], '通知模板已保存');
            }
            $q = $db->prepare('INSERT INTO notification_template (name,code,content,channel,status,created_at,updated_at) VALUES (:name,:code,:content,:channel,:status,:updated_at,:updated_at)');
            $q->execute($values);
        } catch (\PDOException $e) {
            Response::error('通知模板编码已存在或数据无效', 422, 422);
        }
        Response::success(['id' => (int) $db->lastInsertId()], '通知模板已创建');
    }

    private function writePage(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? $p['title'] ?? ''));
        if ($name === '') {
            Response::error('页面名称不能为空', 422, 422);
        }
        $values = [
            ':name' => $name,
            ':path' => trim((string) ($p['path'] ?? '')),
            ':title' => trim((string) ($p['title'] ?? $name)),
            ':content' => (string) ($p['content'] ?? ''),
            ':status' => $this->status($p['status'] ?? 1),
            ':sort' => (int) ($p['sort'] ?? 50),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $q = $db->prepare('UPDATE page SET name=:name,path=:path,title=:title,content=:content,status=:status,sort=:sort,updated_at=:updated_at WHERE id=:id');
            $q->execute($values + [':id' => $id]);
            $this->affected($q->rowCount(), '页面不存在');
            Response::success(['id' => $id], '页面已保存');
        }
        $q = $db->prepare('INSERT INTO page (name,path,title,content,status,sort,created_at,updated_at) VALUES (:name,:path,:title,:content,:status,:sort,:updated_at,:updated_at)');
        $q->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '页面已创建');
    }

    private function writePolling(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            Response::error('轮询规则名称不能为空', 422, 422);
        }
        $accounts = $p['account_ids'] ?? [];
        if (!is_string($accounts)) {
            $accounts = json_encode(is_array($accounts) ? array_values($accounts) : [], JSON_UNESCAPED_UNICODE);
        }
        $values = [
            ':name' => $name,
            ':pay_type' => trim((string) ($p['pay_type'] ?? '')),
            ':channel_code' => trim((string) ($p['channel_code'] ?? '')),
            ':account_ids' => (string) $accounts,
            ':status' => $this->status($p['status'] ?? 1),
            ':sort' => (int) ($p['sort'] ?? 50),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $q = $db->prepare('UPDATE polling_rule SET name=:name,pay_type=:pay_type,channel_code=:channel_code,account_ids=:account_ids,status=:status,sort=:sort,updated_at=:updated_at WHERE id=:id');
            $q->execute($values + [':id' => $id]);
            $this->affected($q->rowCount(), '轮询规则不存在');
            Response::success(['id' => $id], '轮询规则已保存');
        }
        $q = $db->prepare('INSERT INTO polling_rule (name,pay_type,channel_code,account_ids,status,sort,created_at,updated_at) VALUES (:name,:pay_type,:channel_code,:account_ids,:status,:sort,:updated_at,:updated_at)');
        $q->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '轮询规则已创建');
    }

    private function writeStorageChannel(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? ''));
        $driver = trim((string) ($p['driver'] ?? $p['plugin_name'] ?? 'local'));
        if ($name === '' || $driver === '') {
            Response::error('存储通道名称和驱动不能为空', 422, 422);
        }
        $options = $p['options'] ?? [];
        if (!is_string($options)) {
            $options = json_encode(is_array($options) ? $options : [], JSON_UNESCAPED_UNICODE);
        }
        $values = [
            ':name' => $name,
            ':driver' => $driver,
            ':options' => (string) $options,
            ':is_default' => $this->status($p['is_default'] ?? 0),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $q = $db->prepare('UPDATE storage_channel SET name=:name,driver=:driver,options=:options,is_default=:is_default,status=:status,updated_at=:updated_at WHERE id=:id');
            $q->execute($values + [':id' => $id]);
            $this->affected($q->rowCount(), '存储通道不存在');
            Response::success(['id' => $id], '存储通道已保存');
        }
        $q = $db->prepare('INSERT INTO storage_channel (name,driver,options,is_default,status,created_at,updated_at) VALUES (:name,:driver,:options,:is_default,:status,:updated_at,:updated_at)');
        $q->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '存储通道已创建');
    }

    private function writeThirdAccount(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? ''));
        $provider = trim((string) ($p['provider'] ?? $p['type'] ?? ''));
        if ($name === '' || $provider === '') {
            Response::error('第三方账号名称和提供商不能为空', 422, 422);
        }
        $options = $p['options'] ?? [];
        if (!is_string($options)) {
            $options = json_encode(is_array($options) ? $options : [], JSON_UNESCAPED_UNICODE);
        }
        $values = [
            ':name' => $name,
            ':provider' => $provider,
            ':account' => trim((string) ($p['account'] ?? '')),
            ':options' => (string) $options,
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $q = $db->prepare('UPDATE third_account SET name=:name,provider=:provider,account=:account,options=:options,status=:status,updated_at=:updated_at WHERE id=:id');
            $q->execute($values + [':id' => $id]);
            $this->affected($q->rowCount(), '第三方账号不存在');
            Response::success(['id' => $id], '第三方账号已保存');
        }
        $q = $db->prepare('INSERT INTO third_account (name,provider,account,options,status,created_at,updated_at) VALUES (:name,:provider,:account,:options,:status,:updated_at,:updated_at)');
        $q->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '第三方账号已创建');
    }

    private function writeSmsChannel(Request $request, ?int $id): never
    {
        $this->staff($request);
        $p = $request->all();
        $name = trim((string) ($p['name'] ?? ''));
        $plugin = trim((string) ($p['plugin_name'] ?? $p['plugin'] ?? 'local'));
        if ($name === '' || $plugin === '') {
            Response::error('短信渠道名称和插件不能为空', 422, 422);
        }
        $values = [
            ':name' => $name,
            ':plugin_name' => $plugin,
            ':options' => json_encode($p['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':status' => $this->status($p['status'] ?? 1),
            ':updated_at' => time(),
        ];
        $db = Database::connection();
        if ($id !== null) {
            $query = $db->prepare('UPDATE sms_channel SET name=:name,plugin_name=:plugin_name,options=:options,status=:status,updated_at=:updated_at WHERE id=:id');
            $query->execute($values + [':id' => $id]);
            $this->affected($query->rowCount(), '短信渠道不存在');
            Response::success(['id' => $id], '短信渠道已保存');
        }
        $query = $db->prepare('INSERT INTO sms_channel (name,plugin_name,options,status,created_at,updated_at) VALUES (:name,:plugin_name,:options,:status,:updated_at,:updated_at)');
        $query->execute($values);
        Response::success(['id' => (int) $db->lastInsertId()], '短信渠道已创建');
    }

    private function deleteSimple(string $table, Request $request, string $missing, string $success): never
    {
        $this->staff($request);
        $allowed = ['page', 'polling_rule', 'storage_channel', 'third_account', 'third_order_log', 'work_order', 'work_order_category', 'work_order_reply', 'channel_gateway', 'domain_white', 'black_data', 'meal', 'card_group', 'card', 'proxy_pool', 'qrcode_template', 'third_connect_chat', 'sms_channel'];
        if (!in_array($table, $allowed, true)) {
            Response::error('不支持的删除对象', 500, 500);
        }
        $q = Database::connection()->prepare('DELETE FROM `' . $table . '` WHERE id=:id');
        $q->execute([':id' => $this->id($request)]);
        $this->affected($q->rowCount(), $missing);
        Response::success(null, $success);
    }

    private function switchSimple(string $table, Request $request, string $message): never
    {
        $this->staff($request);
        $allowed = ['notification', 'notification_template', 'page', 'polling_rule', 'storage_channel', 'third_account', 'work_order_category', 'work_order', 'work_order_reply', 'channel_gateway', 'domain_white', 'black_data', 'meal', 'card_group', 'card', 'proxy_pool', 'qrcode_template', 'third_connect_chat', 'sms_channel'];
        if (!in_array($table, $allowed, true)) {
            Response::error('不支持的状态对象', 500, 500);
        }
        $q = Database::connection()->prepare('UPDATE `' . $table . '` SET status=:status, updated_at=:updated_at WHERE id=:id');
        $q->execute([':status' => $this->status($request->input('status')), ':updated_at' => time(), ':id' => $this->id($request)]);
        $this->affected($q->rowCount(), '记录不存在');
        Response::success(null, $message);
    }

    /** @return array<string, mixed> */
    private function staff(Request $request): array
    {
        $token = trim((string) $request->header('Authorization'));
        if ($token === '') {
            Response::json(['code' => 404, 'message' => '未登录', 'data' => [], 'redirect' => '']);
        }
        $q = Database::connection()->prepare('SELECT * FROM staff WHERE token=:token AND status=1 LIMIT 1');
        $q->execute([':token' => $token]);
        $staff = $q->fetch();
        if (!is_array($staff)) {
            Response::json(['code' => 401, 'message' => '登录已失效', 'data' => [], 'redirect' => '']);
        }
        return $staff;
    }

    /** @return array<string,mixed> */
    private function staffBearer(Request $request): array
    {
        $header = trim((string) $request->header('Authorization'));
        $token = preg_replace('/^Bearer\s+/i', '', $header) ?? '';
        if ($token === '') {
            Response::json(['code' => 404, 'message' => '未登录', 'data' => [], 'redirect' => '']);
        }
        $query = Database::connection()->prepare('SELECT * FROM staff WHERE token=:token AND status=1 LIMIT 1');
        $query->execute([':token' => $token]);
        $staff = $query->fetch();
        if (!is_array($staff)) {
            Response::json(['code' => 401, 'message' => '登录已失效', 'data' => [], 'redirect' => '']);
        }
        return $staff;
    }

    private function id(Request $request): int
    {
        $id = (int) ($request->input('id') ?? $request->input('pid') ?? 0);
        if ($id <= 0) {
            Response::error('记录 ID 无效', 422, 422);
        }
        return $id;
    }

    private function status(mixed $value): int
    {
        $status = filter_var($value, FILTER_VALIDATE_INT);
        if ($status === false || !in_array((int) $status, [0, 1, 2], true)) {
            Response::error('状态值只能为 0、1 或 2', 422, 422);
        }
        return (int) $status;
    }

    private function affected(int $count, string $message): void
    {
        if ($count === 0) {
            Response::error($message, 404, 404);
        }
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $params */
    private function filter(array $rows, array $params): array
    {
        $query = trim((string) ($params['query'] ?? $params['keyword'] ?? ''));
        $status = $params['status'] ?? '';
        return array_values(array_filter($rows, static function (array $row) use ($query, $status): bool {
            if ($status !== '' && (string) ($row['status'] ?? '') !== (string) $status) {
                return false;
            }
            return $query === '' || stripos(implode(' ', array_map(static fn (mixed $v): string => (string) $v, $row)), $query) !== false;
        }));
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $params @return array<string,mixed> */
    private function paginate(array $rows, array $params): array
    {
        $page = max((int) ($params['page'] ?? 1), 1);
        $limit = min(max((int) ($params['limit'] ?? 20), 1), 200);
        return ['list' => array_slice($rows, ($page - 1) * $limit, $limit), 'count' => count($rows), 'total' => count($rows), 'page' => $page, 'limit' => $limit];
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $params */
    private function paginateWithPageSize(array $rows, array $params): array
    {
        $page = max((int) ($params['page'] ?? 1), 1);
        $limit = min(max((int) ($params['pageSize'] ?? $params['limit'] ?? 20), 1), 200);
        return ['list' => array_slice($rows, ($page - 1) * $limit, $limit), 'count' => count($rows), 'total' => count($rows), 'page' => $page, 'pageSize' => $limit, 'limit' => $limit];
    }

    private function deleteMany(string $table, Request $request, string $message): never
    {
        $this->staff($request);
        $allowed = ['channel_gateway'];
        if (!in_array($table, $allowed, true)) {
            Response::error('不支持的批量删除对象', 500, 500);
        }
        $ids = $request->input('ids', []);
        if (!is_array($ids) || $ids === []) {
            Response::error('请选择要删除的记录', 422, 422);
        }
        $query = Database::connection()->prepare('DELETE FROM `' . $table . '` WHERE id=:id');
        $removed = 0;
        foreach ($ids as $id) {
            $query->execute([':id' => (int) $id]);
            $removed += $query->rowCount();
        }
        Response::success(['removed' => $removed], $message);
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function logPath(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name));
        if ($name === '' || !str_ends_with($name, '.log')) {
            Response::error('日志文件名无效', 422, 422);
        }

        foreach ($this->logEntries() as $entry) {
            if ((string) ($entry['path'] ?? '') === $name) {
                return (string) $entry['_path'];
            }
        }

        $matches = array_values(array_filter(
            $this->logEntries(),
            static fn (array $entry): bool => basename((string) ($entry['path'] ?? '')) === basename($name),
        ));
        if (count($matches) === 1) {
            return (string) $matches[0]['_path'];
        }
        Response::error('日志文件不存在', 404, 404);
    }

    /** @return list<array{name:string,path:string,size:int,updated_at:int,source:string,_path:string}> */
    private function logEntries(): array
    {
        $items = [];
        foreach ($this->logRoots() as $root) {
            $directory = realpath($root['directory']);
            if ($directory === false || !is_dir($directory) || !is_readable($directory)) {
                continue;
            }
            $files = [];
            try {
                if (($root['recursive'] ?? false) === true) {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    );
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $files[] = $file;
                        }
                    }
                } else {
                    $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $files[] = $file;
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }

            foreach ($files as $file) {
                if (!$file->isReadable() || strtolower($file->getExtension()) !== 'log') {
                    continue;
                }
                $filename = $file->getFilename();
                $patterns = $root['patterns'] ?? ['*.log'];
                $matched = false;
                foreach ($patterns as $pattern) {
                    if (fnmatch((string) $pattern, $filename, FNM_CASEFOLD)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
                $path = $file->getRealPath();
                if ($path === false) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($path, strlen($directory) + 1));
                $logical = trim($root['prefix'] . '/' . $relative, '/');
                $items[$logical] = [
                    'name' => $filename,
                    'path' => $logical,
                    'size' => (int) $file->getSize(),
                    'updated_at' => (int) $file->getMTime(),
                    'source' => (string) $root['source'],
                    '_path' => $path,
                ];
            }
        }
        return array_values($items);
    }

    /** @return list<array{directory:string,prefix:string,source:string,recursive:bool,patterns:list<string>}> */
    private function logRoots(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        return [
            [
                'directory' => $projectRoot . DIRECTORY_SEPARATOR . 'var',
                'prefix' => 'var',
                'source' => '应用运行日志',
                'recursive' => false,
                'patterns' => ['php-*.log'],
            ],
            [
                'directory' => $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'logs',
                'prefix' => 'runtime/logs',
                'source' => '应用运行目录',
                'recursive' => false,
                'patterns' => ['*.log'],
            ],
        ];
    }

    private function uploadRoot(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
    }

    private function resolveUploadPath(string $candidate): ?string
    {
        $candidate = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate));
        if ($candidate === '') {
            return null;
        }

        $root = realpath($this->uploadRoot());
        if ($root === false) {
            return null;
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $projectRoot = dirname(__DIR__, 3);
        $path = $candidate;
        $urlPrefix = DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $urlPrefix)) {
            $path = $this->uploadRoot() . DIRECTORY_SEPARATOR . substr($path, strlen($urlPrefix));
        } elseif (!preg_match('/^[A-Za-z]:\\\\|^' . preg_quote(DIRECTORY_SEPARATOR, '/') . '/', $path)) {
            $path = $this->uploadRoot() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }

        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }
        $resolved = rtrim($resolved, DIRECTORY_SEPARATOR);
        if ($resolved === $root || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $resolved;
    }

    /** @return array<string,mixed> */
    private function uploadFileDescriptor(string $root, string $path, \SplFileInfo $file): array
    {
        $relative = ltrim(str_replace(['\\', '/'], '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)))), '/');
        $url = '/uploads/' . $relative;
        return [
            'path' => $url,
            'url' => $url,
            'name' => $file->getFilename(),
            'size' => $file->getSize(),
            'mime' => function_exists('mime_content_type') ? (string) mime_content_type($path) : '',
            'modified_at' => $file->getMTime(),
        ];
    }
}
