<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountWorkBatchService;
use App\Services\DataScope;

class TaskController extends Controller
{
    public function list(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(200, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;
        $filters = [];
        $params = [];
        if ($type = trim((string)$request->get('type', ''))) {
            $types = array_values(array_filter(array_map('trim', explode(',', $type))));
            if (count($types) > 1) {
                $ph = implode(',', array_fill(0, count($types), '?'));
                $filters[] = "t.type IN ({$ph})";
                foreach ($types as $t) {
                    $params[] = $t;
                }
            } elseif ($types) {
                $filters[] = 't.type=?';
                $params[] = $types[0];
            }
        }
        if ($status = trim((string)$request->get('status', ''))) {
            $filters[] = 't.status=?';
            $params[] = $status;
        }
        $this->applyOwnerFilter('t.owner_id', $filters, $params);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM tasks t {$where}", $params)['c'] ?? 0);
        $rows = DB::select(
            "SELECT t.*, g.name AS group_name FROM tasks t LEFT JOIN `groups` g ON g.id=t.group_id {$where} ORDER BY t.id DESC LIMIT {$pageSize} OFFSET {$offset}",
            $params
        );
        $items = array_map([$this, 'mapTask'], $rows);
        $pages = max(1, (int)ceil($total / $pageSize));
        return $this->success([
            'items' => $items,
            'total' => $total,
            'page' => min($page, $pages),
            'pages' => $pages,
            'pageSize' => $pageSize,
        ]);
    }

    public function exportCsv(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $filters = [];
        $params = [];
        if ($type = trim((string)$request->get('type', ''))) {
            $types = array_values(array_filter(array_map('trim', explode(',', $type))));
            if (count($types) > 1) {
                $ph = implode(',', array_fill(0, count($types), '?'));
                $filters[] = "t.type IN ({$ph})";
                foreach ($types as $t) {
                    $params[] = $t;
                }
            } elseif ($types) {
                $filters[] = 't.type=?';
                $params[] = $types[0];
            }
        }
        if ($status = trim((string)$request->get('status', ''))) {
            $filters[] = 't.status=?';
            $params[] = $status;
        }
        if ($name = trim((string)$request->get('name', $request->get('keyword', '')))) {
            $filters[] = 't.name LIKE ?';
            $params[] = '%' . $name . '%';
        }
        $dateFrom = trim((string)$request->get('dateFrom', $request->get('date_from', '')));
        $dateTo = trim((string)$request->get('dateTo', $request->get('date_to', '')));
        if ($dateFrom !== '') {
            $ts = strtotime($dateFrom);
            if ($ts) {
                $filters[] = 't.created_at >= ?';
                $params[] = date('Y-m-d H:i:s', $ts);
            }
        }
        if ($dateTo !== '') {
            $ts = strtotime($dateTo);
            if ($ts) {
                // 若只有日期，算到当天结束
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                    $ts = strtotime($dateTo . ' 23:59:59');
                }
                $filters[] = 't.created_at <= ?';
                $params[] = date('Y-m-d H:i:s', $ts);
            }
        }
        $idMin = (int)$request->get('idMin', $request->get('id_min', 0));
        $idMax = (int)$request->get('idMax', $request->get('id_max', 0));
        if ($idMin > 0) {
            $filters[] = 't.id >= ?';
            $params[] = $idMin;
        }
        if ($idMax > 0) {
            $filters[] = 't.id <= ?';
            $params[] = $idMax;
        }
        $this->applyOwnerFilter('t.owner_id', $filters, $params);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $rows = DB::select(
            "SELECT t.*, g.name AS group_name FROM tasks t LEFT JOIN `groups` g ON g.id=t.group_id {$where} ORDER BY t.id DESC LIMIT 5000",
            $params
        );

        $publishMode = strtolower(trim((string)$request->get('publishMode', $request->get('publish_mode', ''))));
        if (in_array($publishMode, ['random', 'ordered', 'independent'], true)) {
            $rows = array_values(array_filter($rows, static function ($r) use ($publishMode) {
                $payload = json_decode((string)($r['payload'] ?? '{}'), true) ?: [];
                $mode = strtolower((string)($payload['publishMode'] ?? $payload['publish_mode'] ?? ''));
                if ($mode === '') {
                    $strategy = strtolower((string)($payload['publishStrategy'] ?? ''));
                    $mode = $strategy === 'deduplicate' ? 'independent' : ($strategy === 'pairs' ? 'ordered' : 'random');
                }
                return $mode === $publishMode;
            }));
        }

        $fieldDefs = $this->exportFieldDefs();
        $fieldsRaw = trim((string)$request->get('fields', ''));
        $headersRaw = trim((string)$request->get('headers', ''));
        $selected = $fieldsRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $fieldsRaw))))
            : array_keys($fieldDefs);
        $customHeaders = [];
        if ($headersRaw !== '') {
            $decoded = json_decode($headersRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $customHeaders[(string)$k] = trim((string)$v);
                }
            } else {
                // 与 fields 一一对应：header1,header2,...
                $parts = array_map('trim', explode(',', $headersRaw));
                foreach ($selected as $i => $f) {
                    if (isset($parts[$i]) && $parts[$i] !== '') {
                        $customHeaders[$f] = $parts[$i];
                    }
                }
            }
        }
        $cols = [];
        foreach ($selected as $f) {
            if (!isset($fieldDefs[$f])) {
                continue;
            }
            $cols[] = [
                'key' => $f,
                'label' => (isset($customHeaders[$f]) && $customHeaders[$f] !== '')
                    ? $customHeaders[$f]
                    : $fieldDefs[$f],
            ];
        }
        if (!$cols) {
            foreach ($fieldDefs as $k => $label) {
                $cols[] = ['key' => $k, 'label' => $label];
            }
        }

        $esc = fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"';
        $lines = [implode(',', array_map(static fn($c) => '"' . str_replace('"', '""', $c['label']) . '"', $cols))];
        foreach ($rows as $r) {
            $payload = json_decode((string)($r['payload'] ?? '{}'), true) ?: [];
            $mapped = $this->mapExportRow($r, $payload);
            $line = [];
            foreach ($cols as $c) {
                $line[] = $esc($mapped[$c['key']] ?? '');
            }
            $lines[] = implode(',', $line);
        }
        return Response::make("\xEF\xBB\xBF" . implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="tkswarm-tasks.csv"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** 导入模板 CSV（固定格式：一行 = 1 账号 + 1 视频 → 独立发布任务） */
    public function importTemplate(Request $request): Response
    {
        $lines = [
            'account_id,material_id,task_name,publish_title,publish_caption',
            '1,10,独立发布示例,标题可选,文案可选',
        ];
        return Response::make("\xEF\xBB\xBF" . implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="task-import-template.csv"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * 按固定模板导入：每行创建 1 个独立发布任务（publishMode=independent）
     * body: { content: "csv text", autoStart?: bool }
     */
    public function import(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $content = (string)$request->input('content', '');
        if (trim($content) === '') {
            return $this->error('请粘贴或上传 CSV 内容', 422);
        }
        $autoStart = (bool)$request->input('autoStart', false);
        $lines = preg_split('/\r?\n/', $content) ?: [];
        $rows = [];
        $header = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // 去掉 BOM
            if (str_starts_with($line, "\xEF\xBB\xBF")) {
                $line = substr($line, 3);
            }
            $cols = str_getcsv($line);
            if ($header === null) {
                $header = array_map(static fn($h) => strtolower(trim((string)$h)), $cols);
                continue;
            }
            if (count($cols) === 1 && trim((string)$cols[0]) === '') {
                continue;
            }
            $item = [];
            foreach ($header as $i => $key) {
                $item[$key] = isset($cols[$i]) ? trim((string)$cols[$i]) : '';
            }
            $rows[] = $item;
        }
        if (!$header) {
            return $this->error('CSV 为空或缺少表头', 422);
        }
        $requiredOk = in_array('account_id', $header, true) && in_array('material_id', $header, true);
        if (!$requiredOk) {
            return $this->error('表头必须包含 account_id, material_id（可另含 task_name, publish_title, publish_caption）', 422);
        }
        if (count($rows) > 500) {
            return $this->error('单次最多导入 500 行', 422);
        }

        $created = [];
        $errors = [];
        $ownerId = $this->writeOwnerId();
        foreach ($rows as $idx => $row) {
            $lineNo = $idx + 2; // 含表头
            $accountId = (int)($row['account_id'] ?? 0);
            $materialId = (int)($row['material_id'] ?? 0);
            if ($accountId <= 0 || $materialId <= 0) {
                $errors[] = "第{$lineNo}行：account_id / material_id 无效";
                continue;
            }
            $acc = DB::selectOne('SELECT id, username, owner_id FROM accounts WHERE id=?', [$accountId]);
            if (!$acc) {
                $errors[] = "第{$lineNo}行：账号 #{$accountId} 不存在";
                continue;
            }
            if (!DataScope::ownsRow($acc)) {
                $errors[] = "第{$lineNo}行：无权使用账号 #{$accountId}";
                continue;
            }
            $mat = DB::selectOne('SELECT id, name, file_name, owner_id, status FROM materials WHERE id=?', [$materialId]);
            if (!$mat) {
                $errors[] = "第{$lineNo}行：素材 #{$materialId} 不存在";
                continue;
            }
            if (!DataScope::ownsMaterialRow($mat)) {
                $errors[] = "第{$lineNo}行：无权使用素材 #{$materialId}";
                continue;
            }
            $uname = (string)($acc['username'] ?? ('#' . $accountId));
            $mname = (string)($mat['name'] ?: ($mat['file_name'] ?? ('#' . $materialId)));
            $taskName = trim((string)($row['task_name'] ?? ''));
            if ($taskName === '') {
                $taskName = "独立发布 account({$uname})-video({$mname})";
            }
            $taskName = mb_substr(preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', '', $taskName) ?: '独立发布', 0, 160);
            $payload = [
                'pushChannel' => 'bit',
                'accountIds' => [$accountId],
                'materialIds' => [$materialId],
                'pairs' => [['accountId' => $accountId, 'materialId' => $materialId]],
                'folderPath' => '',
                'publishMode' => 'independent',
                'publishStrategy' => 'pairs',
                'publishTitle' => trim((string)($row['publish_title'] ?? '')),
                'publishCaption' => trim((string)($row['publish_caption'] ?? '')),
                'hashtags' => '',
                'threadCount' => 1,
                'saveTraffic' => false,
                'deleteAfterPublish' => false,
            ];
            try {
                DB::query(
                    'INSERT INTO tasks(name,type,group_id,total_count,scheduled_at,payload,status,owner_id) VALUES (?,?,?,?,?,?,"draft",?)',
                    [
                        $taskName,
                        'publish',
                        null,
                        1,
                        null,
                        json_encode($payload, JSON_UNESCAPED_UNICODE),
                        $ownerId,
                    ]
                );
                $id = (int)DB::getPdo()->lastInsertId();
                $this->event($id, 'info', '由 CSV 导入创建（独立发布）');
                if ($autoStart) {
                    DB::query('UPDATE tasks SET status=?, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?', ['queued', $id]);
                    $this->event($id, 'info', '导入后自动入队');
                }
                $created[] = ['id' => $id, 'name' => $taskName, 'line' => $lineNo];
            } catch (\Throwable $e) {
                $errors[] = "第{$lineNo}行：写入失败 " . $e->getMessage();
            }
        }

        return $this->success([
            'created' => count($created),
            'failed' => count($errors),
            'items' => $created,
            'errors' => array_slice($errors, 0, 50),
        ], count($created) ? ('已导入 ' . count($created) . ' 个独立发布任务') : '没有成功导入的行');
    }

    /** @return array<string,string> field => default header label */
    private function exportFieldDefs(): array
    {
        return [
            'id' => 'ID',
            'name' => '任务名称',
            'type' => '类型',
            'publish_mode' => '发布模式',
            'status' => '状态',
            'group_name' => '分组',
            'total_count' => '总数',
            'success_count' => '成功',
            'fail_count' => '失败',
            'scheduled_at' => '计划时间',
            'started_at' => '开始时间',
            'finished_at' => '结束时间',
            'created_at' => '创建时间',
            'account_ids' => '账号ID',
            'material_ids' => '素材ID',
        ];
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $payload */
    private function mapExportRow(array $r, array $payload): array
    {
        $mode = (string)($payload['publishMode'] ?? $payload['publish_mode'] ?? '');
        if ($mode === '') {
            $strategy = strtolower((string)($payload['publishStrategy'] ?? ''));
            $mode = $strategy === 'deduplicate' ? 'independent' : ($strategy === 'pairs' ? 'ordered' : ((string)($r['type'] ?? '') === 'publish' ? 'random' : ''));
        }
        $modeLabel = match ($mode) {
            'ordered' => '群发任务',
            'independent' => '独立发布',
            'random' => '随机发布',
            default => $mode ?: ((string)($r['type'] ?? '')),
        };
        $accountIds = $payload['accountIds'] ?? [];
        $materialIds = $payload['materialIds'] ?? [];
        if (!empty($payload['pairs']) && is_array($payload['pairs'])) {
            $aids = [];
            $mids = [];
            foreach ($payload['pairs'] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $aids[] = (int)($p['accountId'] ?? $p['account_id'] ?? 0);
                $mids[] = (int)($p['materialId'] ?? $p['material_id'] ?? 0);
            }
            if ($aids) {
                $accountIds = array_values(array_unique(array_filter($aids)));
            }
            if ($mids) {
                $materialIds = array_values(array_unique(array_filter($mids)));
            }
        }
        return [
            'id' => $r['id'] ?? '',
            'name' => $r['name'] ?? '',
            'type' => $r['type'] ?? '',
            'publish_mode' => $modeLabel,
            'status' => $r['status'] ?? '',
            'group_name' => $r['group_name'] ?? '',
            'total_count' => $r['total_count'] ?? '',
            'success_count' => $r['success_count'] ?? '',
            'fail_count' => $r['fail_count'] ?? '',
            'scheduled_at' => $r['scheduled_at'] ?? '',
            'started_at' => $r['started_at'] ?? '',
            'finished_at' => $r['finished_at'] ?? '',
            'created_at' => $r['created_at'] ?? '',
            'account_ids' => is_array($accountIds) ? implode('|', array_map('intval', $accountIds)) : (string)$accountIds,
            'material_ids' => is_array($materialIds) ? implode('|', array_map('intval', $materialIds)) : (string)$materialIds,
        ];
    }

    public function create(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $body = $this->parseBody($request);
        if ($err = $this->validateBody($body)) {
            return $this->error($err, 400);
        }
        DB::query(
            'INSERT INTO tasks(name,type,group_id,total_count,scheduled_at,payload,status,owner_id) VALUES (?,?,?,?,?,?,"draft",?)',
            [
                $body['name'], $body['type'], $body['groupId'], $body['totalCount'],
                $body['scheduledAt'], json_encode($body['payload'], JSON_UNESCAPED_UNICODE),
                $this->writeOwnerId(),
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        $this->event($id, 'info', '任务已创建');
        return $this->success(['id' => $id, ...$body, 'status' => 'draft'], '任务已创建');
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        if (!in_array($task['status'], ['draft', 'paused', 'failed', 'completed'], true)) {
            return $this->error('只有草稿、暂停、失败或已完成任务可以编辑', 409);
        }
        $body = $this->parseBody($request);
        if ($err = $this->validateBody($body)) {
            return $this->error($err, 400);
        }
        DB::query(
            'UPDATE tasks SET name=?, type=?, group_id=?, total_count=?, scheduled_at=?, payload=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $body['name'], $body['type'], $body['groupId'], $body['totalCount'],
                $body['scheduledAt'], json_encode($body['payload'], JSON_UNESCAPED_UNICODE), (int)$id,
            ]
        );
        $this->event((int)$id, 'info', '任务配置已更新');
        return $this->success(['id' => (int)$id, ...$body, 'status' => $task['status']], '任务已更新');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        DB::query('DELETE FROM tasks WHERE id=?', [(int)$id]);
        return $this->success(null, '任务已删除');
    }

    public function start(Request $request, string $id): Response
    {
        return $this->setStatus((int)$id, 'queued', ['draft', 'paused', 'failed', 'completed'], '任务已启动入队');
    }

    public function pause(Request $request, string $id): Response
    {
        return $this->setStatus((int)$id, 'paused', ['queued', 'running'], '任务已暂停');
    }

    public function cancel(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        if (!in_array($task['status'], ['queued', 'running', 'paused'], true)) {
            return $this->error('当前状态不可取消', 409);
        }
        DB::query("UPDATE tasks SET status='cancelled', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$id]);
        DB::query("UPDATE task_runs SET status='skipped', error_message='任务被取消', finished_at=CURRENT_TIMESTAMP WHERE task_id=? AND status='running'", [(int)$id]);
        AccountWorkBatchService::cancelItemsForTask((string)$task['type'], (int)$id);
        $this->event((int)$id, 'warn', '任务已取消');
        return $this->success(null, '任务已取消');
    }

    public function retry(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        if ($task['status'] !== 'failed') {
            return $this->error('仅失败任务可重试', 409);
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $payload['onlyFailed'] = 1;
        DB::query(
            "UPDATE tasks SET status='queued', payload=?, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [json_encode($payload, JSON_UNESCAPED_UNICODE), (int)$id]
        );
        AccountWorkBatchService::attachFromTask((int)$id);
        $this->event((int)$id, 'info', '失败任务已重新入队');
        return $this->success(null, '已重新入队');
    }

    public function batchRetry(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('taskIds', []));
        if (!$ids) {
            return $this->error('请选择失败任务', 400);
        }
        $changed = 0;
        foreach ($ids as $id) {
            $task = DB::selectOne('SELECT * FROM tasks WHERE id=? AND status=?', [$id, 'failed']);
            if (!$task || !DataScope::ownsRow($task)) {
                continue;
            }
            $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
            $payload['onlyFailed'] = 1;
            DB::query(
                "UPDATE tasks SET status='queued', payload=?, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [json_encode($payload, JSON_UNESCAPED_UNICODE), $id]
            );
            AccountWorkBatchService::attachFromTask($id);
            $this->event($id, 'info', '失败账号已批量重新进入队列');
            $changed++;
        }
        return $this->success(['requested' => count($ids), 'changed' => $changed], '批量重试已提交');
    }

    public function batchAction(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('taskIds', []));
        $action = (string)$request->input('action', '');
        if (!$ids) {
            return $this->error('请选择任务', 400);
        }
        if (!in_array($action, ['start', 'pause', 'cancel'], true)) {
            return $this->error('不支持的批量操作', 400);
        }
        $changed = 0;
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        if ($action === 'cancel') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = DB::query(
                "UPDATE tasks SET status='cancelled', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE id IN ({$ph}) AND status IN ('queued','running','paused'){$ownSql}",
                array_merge($ids, $ownParams)
            );
            $changed = $stmt->rowCount();
            foreach ($ids as $id) {
                $row = DB::selectOne('SELECT type FROM tasks WHERE id=?', [$id]);
                if ($row) {
                    AccountWorkBatchService::cancelItemsForTask((string)$row['type'], $id);
                }
                $this->event($id, 'warn', '任务已批量取消');
            }
        } else {
            $to = $action === 'start' ? 'queued' : 'paused';
            $allowed = $action === 'start' ? ['draft', 'paused', 'failed'] : ['queued', 'running'];
            foreach ($ids as $id) {
                $task = DB::selectOne('SELECT status, type, owner_id FROM tasks WHERE id=?', [$id]);
                if (!$task || !DataScope::ownsRow($task) || !in_array($task['status'], $allowed, true)) {
                    continue;
                }
                if ($action === 'start' && !in_array($task['type'], ['sync', 'profile', 'publish', 'message', 'warm', 'sync_fans', 'dm_sync'], true)) {
                    continue;
                }
                DB::query('UPDATE tasks SET status=?, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$to, $id]);
                if ($action === 'start') {
                    AccountWorkBatchService::attachFromTask($id);
                }
                $this->event($id, 'info', $action === 'start' ? '任务已批量启动' : '任务已批量暂停');
                $changed++;
            }
        }
        return $this->success(['requested' => count($ids), 'changed' => $changed], '批量操作完成');
    }

    public function batchDelete(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('taskIds', []));
        if (!$ids) {
            return $this->error('请选择任务', 400);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $stmt = DB::query("DELETE FROM tasks WHERE id IN ({$ph}){$ownSql}", array_merge($ids, $ownParams));
        return $this->success(['deleted' => $stmt->rowCount()], '批量删除完成');
    }

    public function preflight(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id,type,payload,group_id,total_count,owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $issues = [];
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', $payload['accountIds'] ?? []))));
        if ($selectedIds) {
            $ph = implode(',', array_fill(0, count($selectedIds), '?'));
            $accountCount = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM accounts WHERE enabled=1 AND id IN ({$ph})", $selectedIds)['c'] ?? 0);
            if ($accountCount !== count($selectedIds)) {
                $issues[] = '部分指定账号不存在或未启用';
            }
        } elseif (!empty($task['group_id'])) {
            $accountCount = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM accounts WHERE enabled=1 AND group_id=?', [(int)$task['group_id']])['c'] ?? 0);
        } else {
            $accountCount = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM accounts WHERE enabled=1')['c'] ?? 0);
        }
        if (!$accountCount) {
            $issues[] = '没有符合条件的启用账号';
        }
        if ((int)$task['total_count'] > 0 && $accountCount < (int)$task['total_count']) {
            $issues[] = "目标账号不足：需要 {$task['total_count']} 个，当前只有 {$accountCount} 个";
        }
        return $this->success(['ok' => !$issues, 'issues' => $issues, 'accountCount' => $accountCount]);
    }

    public function prepare(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $plan = [
            'taskId' => (int)$id,
            'type' => $task['type'],
            'payload' => json_decode((string)($task['payload'] ?? '{}'), true) ?: [],
            'preparedAt' => date('c'),
        ];
        DB::query(
            "INSERT INTO task_plans(task_id, status, plan_json) VALUES (?, 'prepared', ?)",
            [(int)$id, json_encode($plan, JSON_UNESCAPED_UNICODE)]
        );
        $planId = (int)DB::getPdo()->lastInsertId();
        $this->event((int)$id, 'info', '执行方案已生成');
        return $this->success(['id' => $planId, 'status' => 'prepared', 'plan' => $plan], '方案已准备');
    }

    public function plans(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $rows = DB::select('SELECT id,status,plan_json,created_at,confirmed_at FROM task_plans WHERE task_id=? ORDER BY id DESC', [(int)$id]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'status' => $row['status'],
                'plan' => json_decode((string)($row['plan_json'] ?? '{}'), true) ?: [],
                'created_at' => $row['created_at'],
                'confirmed_at' => $row['confirmed_at'],
            ];
        }
        return $this->success($out);
    }

    public function confirmPlan(Request $request, string $id, string $planId): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $plan = DB::selectOne('SELECT * FROM task_plans WHERE id=? AND task_id=?', [(int)$planId, (int)$id]);
        if (!$plan) {
            return $this->error('执行方案不存在', 404);
        }
        if ($plan['status'] !== 'prepared') {
            return $this->error('执行方案当前不能确认', 409);
        }
        DB::query("UPDATE task_plans SET status='confirmed', confirmed_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$planId]);
        $this->event((int)$id, 'info', '执行方案已确认，任务可按执行器策略运行');
        return $this->success(['id' => (int)$planId, 'status' => 'confirmed'], '方案已确认');
    }

    public function events(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $rows = DB::select('SELECT * FROM task_events WHERE task_id=? ORDER BY id DESC LIMIT 200', [(int)$id]);
        return $this->success($rows);
    }

    public function runs(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $rows = DB::select(
            'SELECT r.*, a.username FROM task_runs r LEFT JOIN accounts a ON a.id=r.account_id WHERE r.task_id=? ORDER BY r.id DESC LIMIT 500',
            [(int)$id]
        );
        return $this->success($rows);
    }

    public function results(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $rows = DB::select(
            "SELECT r.id,r.account_id,a.username,r.action_type,r.status,r.result_json,r.error_message,r.created_at
             FROM task_action_results r JOIN accounts a ON a.id=r.account_id WHERE r.task_id=? ORDER BY r.id DESC LIMIT 500",
            [(int)$id]
        );
        $out = [];
        foreach ($rows as $x) {
            $out[] = [
                ...$x,
                'result' => json_decode((string)($x['result_json'] ?? '{}'), true) ?: [],
                'result_json' => null,
            ];
        }
        return $this->success($out);
    }

    public function items(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $rows = DB::select(
            'SELECT i.*, a.username, m.name AS material_name
             FROM task_items i
             LEFT JOIN accounts a ON a.id=i.account_id
             LEFT JOIN materials m ON m.id=i.material_id
             WHERE i.task_id=? ORDER BY i.id ASC LIMIT 2000',
            [(int)$id]
        );
        foreach ($rows as &$row) {
            $row['meta'] = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
            unset($row['meta_json']);
        }
        return $this->success($rows);
    }

    public function retryFailedItems(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $stmt = DB::query(
            "UPDATE task_items SET status='pending', error_message='', updated_at=CURRENT_TIMESTAMP
             WHERE task_id=? AND status IN ('failed','skipped')",
            [(int)$id]
        );
        $this->event((int)$id, 'info', '失败/跳过条目已重试');
        return $this->success(['updated' => $stmt->rowCount()], '已重试失败条目');
    }

    public function batchUpdateItems(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $caption = (string)$request->input('caption', $request->input('content', ''));
        $ids = $this->idList($request->input('itemIds', []));
        if (!$ids) {
            return $this->error('请选择条目', 400);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = DB::query(
            "UPDATE task_items SET caption=?, updated_at=CURRENT_TIMESTAMP WHERE task_id=? AND id IN ({$ph})",
            array_merge([$caption, (int)$id], $ids)
        );
        return $this->success(['updated' => $stmt->rowCount()], '条目已更新');
    }

    public function updateItem(Request $request, string $id, string $itemId): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $item = DB::selectOne('SELECT * FROM task_items WHERE id=? AND task_id=?', [(int)$itemId, (int)$id]);
        if (!$item) {
            return $this->error('条目不存在', 404);
        }
        $caption = $request->input('caption', $request->input('content'));
        if ($caption !== null) {
            DB::query('UPDATE task_items SET caption=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(string)$caption, (int)$itemId]);
        }
        $row = DB::selectOne('SELECT * FROM task_items WHERE id=?', [(int)$itemId]);
        $row['meta'] = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
        unset($row['meta_json']);
        return $this->success($row, '条目已更新');
    }

    public function preview(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        return $this->success($this->mapTask($task));
    }

    private function setStatus(int $id, string $status, array $allowed, string $msg, bool $silent = false): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        if (!in_array($task['status'], $allowed, true)) {
            return $this->error('当前状态不可操作', 409);
        }
        if ($status === 'queued' && !in_array($task['type'], ['sync', 'profile', 'publish', 'message', 'warm', 'sync_fans', 'dm_sync'], true)) {
            return $this->error('该任务类型暂不支持启动', 422);
        }
        DB::query('UPDATE tasks SET status=?, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$status, $id]);
        if ($status === 'queued') {
            AccountWorkBatchService::attachFromTask($id);
        }
        $this->event($id, 'info', $msg);
        return $this->success($silent ? ['ok' => true] : null, $msg);
    }

    private function parseBody(Request $request): array
    {
        $type = (string)$request->input('type', 'sync');
        if (!in_array($type, ['publish', 'message', 'sync', 'profile', 'warm', 'sync_fans', 'dm_sync'], true)) {
            $type = 'sync';
        }
        $groupId = $request->input('groupId');
        $payload = $request->input('payload', []);
        if (!is_array($payload)) {
            $payload = [];
        }
        // frontend may flatten fields
        foreach (['accountIds', 'materialIds', 'content', 'templateId', 'uidGroupId', 'sendTargetType', 'fixedTargets', 'massChannel',
            'searchTerms', 'searchType', 'matchKeywords', 'videoCountMin', 'videoCountMax', 'followRate', 'likeRate', 'favoriteRate',
            'viewCommentRate', 'commentRate', 'commentPages', 'commentLikeRate', 'staySecondsMin', 'staySecondsMax', 'threadCount',
            'commentMode', 'commentTemplateIds', 'commentAiTemplateId', 'commentTexts', 'commentAiPrompt', 'commentText',
            'diagnoseAfterWarm', 'recurring', 'recurringDays', 'isRecurring', 'repeatDays'] as $k) {
            if ($request->input($k) !== null && !isset($payload[$k])) {
                $payload[$k] = $request->input($k);
            }
        }
        if ($type === 'warm') {
            $payload = $this->normalizeWarmPayload($payload);
        }
        $scheduled = $request->input('scheduledAt');
        if (is_string($scheduled) && $scheduled !== '') {
            $scheduled = date('Y-m-d H:i:s', strtotime($scheduled) ?: time());
        } else {
            $scheduled = null;
        }
        return [
            'name' => trim((string)$request->input('name', '')),
            'type' => $type,
            'groupId' => ($groupId === null || $groupId === '') ? null : (int)$groupId,
            'totalCount' => max(0, (int)$request->input('totalCount', 0)),
            'scheduledAt' => $scheduled,
            'payload' => $payload,
        ];
    }

    private function validateBody(array $body): ?string
    {
        if ($body['name'] === '') {
            return '任务名称不能为空';
        }
        if ($body['type'] === 'message') {
            $accountIds = array_filter(array_map('intval', $body['payload']['accountIds'] ?? []));
            if (!$accountIds && !$body['groupId']) {
                return '请勾选账号或选择账号分组';
            }
            $targetType = (string)($body['payload']['sendTargetType'] ?? 'imported');
            if ($targetType === 'fixed') {
                $lines = preg_split('/\r?\n/', (string)($body['payload']['fixedTargets'] ?? '')) ?: [];
                if (!array_filter(array_map('trim', $lines))) {
                    return '固定对象不能为空';
                }
            }
            if ($targetType === 'imported' && !(int)($body['payload']['uidGroupId'] ?? 0)) {
                return '请选择 UID 分组作为发送对象';
            }
            if ($targetType === 'followers') {
                // 粉丝目标依赖本地 relation，允许创建；无粉丝时执行阶段跳过
            }
            if (trim((string)($body['payload']['content'] ?? '')) === '' && !(int)($body['payload']['templateId'] ?? 0)) {
                return '请填写发送内容或选择话术模板';
            }
        }
        if (in_array($body['type'], ['sync_fans', 'dm_sync'], true)) {
            $accountIds = array_filter(array_map('intval', $body['payload']['accountIds'] ?? []));
            if (!$accountIds && !$body['groupId']) {
                return '请勾选账号或选择账号分组';
            }
        }
        if ($body['type'] === 'publish') {
            $pairs = $body['payload']['pairs'] ?? [];
            $pairRows = [];
            if (is_array($pairs)) {
                foreach ($pairs as $pair) {
                    if (!is_array($pair)) {
                        continue;
                    }
                    $aid = (int)($pair['accountId'] ?? $pair['account_id'] ?? 0);
                    $mid = (int)($pair['materialId'] ?? $pair['material_id'] ?? 0);
                    if ($aid > 0 && $mid > 0) {
                        $pairRows[] = ['accountId' => $aid, 'materialId' => $mid];
                    }
                }
            }
            if ($pairRows) {
                // 有序/自定义组合：以 pairs 为准
            } else {
                $materialIds = array_filter(array_map('intval', $body['payload']['materialIds'] ?? []));
                if (!$materialIds) {
                    return '发布任务需要关联素材';
                }
                $accountIds = array_filter(array_map('intval', $body['payload']['accountIds'] ?? []));
                if (!$accountIds && !$body['groupId']) {
                    return '请勾选账号或选择账号分组';
                }
            }
        }
        if ($body['type'] === 'warm') {
            $accountIds = array_filter(array_map('intval', $body['payload']['accountIds'] ?? []));
            if (!$accountIds && !$body['groupId']) {
                return '请勾选账号或选择账号分组';
            }
            $searchType = (string)($body['payload']['searchType'] ?? 'video');
            if (!in_array($searchType, ['video', 'top', 'hashtag', 'user', 'aweme'], true)) {
                return '无效的搜索类型';
            }
            if (trim((string)($body['payload']['searchTerms'] ?? '')) === '') {
                return '请填写搜索词或作品 ID';
            }
        }
        return null;
    }

    private function normalizeWarmPayload(array $payload): array
    {
        // 养号仅支持视频搜索
        $searchType = 'video';
        $minV = max(1, (int)($payload['videoCountMin'] ?? 10));
        $maxV = max($minV, (int)($payload['videoCountMax'] ?? $minV));
        $stayMin = max(1, (int)($payload['staySecondsMin'] ?? 10));
        $stayMax = max($stayMin, (int)($payload['staySecondsMax'] ?? 20));
        $clampRate = static fn($v) => max(0, min(100, (int)$v));
        $commentCfg = \App\Services\WarmCommentService::resolveFromPayload($payload);
        $normalized = array_merge($payload, [
            'searchType' => $searchType,
            'searchTerms' => trim((string)($payload['searchTerms'] ?? '')),
            'matchKeywords' => '',
            'videoCountMin' => min(100, $minV),
            'videoCountMax' => min(100, $maxV),
            'followRate' => $clampRate($payload['followRate'] ?? 0),
            'likeRate' => $clampRate($payload['likeRate'] ?? 0),
            'favoriteRate' => $clampRate($payload['favoriteRate'] ?? 0),
            'viewCommentRate' => $clampRate($payload['viewCommentRate'] ?? 0),
            // 发评论概率：显式 commentRate，否则沿用「评论」滑块
            'commentRate' => $clampRate($payload['commentRate'] ?? $payload['viewCommentRate'] ?? 0),
            'commentLikeRate' => $clampRate($payload['commentLikeRate'] ?? 0),
            'commentPages' => max(0, min(20, (int)($payload['commentPages'] ?? 3))),
            'staySecondsMin' => min(300, $stayMin),
            'staySecondsMax' => min(300, $stayMax),
            'threadCount' => max(1, min(3, (int)($payload['threadCount'] ?? 1))),
            'diagnoseAfterWarm' => filter_var($payload['diagnoseAfterWarm'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'recurring' => filter_var($payload['recurring'] ?? $payload['isRecurring'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'recurringDays' => max(1, min(365, (int)($payload['recurringDays'] ?? $payload['repeatDays'] ?? 1))),
        ], $commentCfg);
        if (empty($normalized['recurring'])) {
            $normalized['recurring'] = false;
            $normalized['recurringDays'] = max(1, (int)($normalized['recurringDays'] ?? 1));
        }
        // 保留既有轮次/上次触发时间（编辑保存时不丢）
        if (isset($payload['recurringLastRunAt'])) {
            $normalized['recurringLastRunAt'] = $payload['recurringLastRunAt'];
        }
        if (isset($payload['recurringCycle'])) {
            $normalized['recurringCycle'] = (int)$payload['recurringCycle'];
        }
        // 保存时固化执行计划；多账号则每账号独立一份随机落点
        $accountIds = array_values(array_filter(array_map('intval', $normalized['accountIds'] ?? [])));
        $plansByAccount = [];
        if ($accountIds) {
            foreach ($accountIds as $aid) {
                $plansByAccount[(string)$aid] = \App\Services\WarmExecutionPlan::build($normalized);
            }
            $normalized['executionPlans'] = $plansByAccount;
            $normalized['executionPlan'] = $plansByAccount[(string)$accountIds[0]]
                ?? \App\Services\WarmExecutionPlan::build($normalized);
        } else {
            $normalized['executionPlan'] = \App\Services\WarmExecutionPlan::build($normalized);
            $normalized['executionPlans'] = new \stdClass();
        }
        return $normalized;
    }

    private function mapTask(array $row): array
    {
        $row['payload'] = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
        return $row;
    }

    private function event(int $taskId, string $level, string $message): void
    {
        try {
            DB::query('INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)', [$taskId, $level, $message]);
        } catch (\Throwable $e) {
        }
    }

    private function idList(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        return array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 100);
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        DB::query("CREATE TABLE IF NOT EXISTS tasks (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          type VARCHAR(32) NOT NULL,
          status VARCHAR(32) NOT NULL DEFAULT 'draft',
          group_id INT NULL,
          payload MEDIUMTEXT NULL,
          total_count INT NOT NULL DEFAULT 0,
          success_count INT NOT NULL DEFAULT 0,
          fail_count INT NOT NULL DEFAULT 0,
          scheduled_at DATETIME NULL,
          started_at DATETIME NULL,
          finished_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS task_events (
          id INT AUTO_INCREMENT PRIMARY KEY,
          task_id INT NOT NULL,
          level VARCHAR(16) NOT NULL DEFAULT 'info',
          message TEXT NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS task_runs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          task_id INT NOT NULL,
          account_id INT NULL,
          status VARCHAR(32) NOT NULL DEFAULT 'pending',
          error_message TEXT NULL,
          started_at DATETIME NULL,
          finished_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS task_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          task_id INT NOT NULL,
          account_id INT NULL,
          material_id INT NULL,
          caption TEXT NULL,
          status VARCHAR(32) NOT NULL DEFAULT 'pending',
          error_message TEXT NULL,
          meta_json MEDIUMTEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS task_plans (
          id INT AUTO_INCREMENT PRIMARY KEY,
          task_id INT NOT NULL,
          status VARCHAR(32) NOT NULL DEFAULT 'prepared',
          plan_json MEDIUMTEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          confirmed_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS task_action_results (
          id INT AUTO_INCREMENT PRIMARY KEY,
          task_id INT NOT NULL,
          account_id INT NOT NULL,
          action_type VARCHAR(64) NOT NULL DEFAULT '',
          status VARCHAR(32) NOT NULL DEFAULT 'ok',
          result_json MEDIUMTEXT NULL,
          error_message TEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cols = array_column(DB::select('SHOW COLUMNS FROM tasks') ?: [], 'Field');
        $alters = [
            'group_id' => 'ALTER TABLE tasks ADD COLUMN group_id INT NULL',
            'payload' => 'ALTER TABLE tasks ADD COLUMN payload MEDIUMTEXT NULL',
            'total_count' => 'ALTER TABLE tasks ADD COLUMN total_count INT NOT NULL DEFAULT 0',
            'success_count' => 'ALTER TABLE tasks ADD COLUMN success_count INT NOT NULL DEFAULT 0',
            'fail_count' => 'ALTER TABLE tasks ADD COLUMN fail_count INT NOT NULL DEFAULT 0',
            'scheduled_at' => 'ALTER TABLE tasks ADD COLUMN scheduled_at DATETIME NULL',
            'started_at' => 'ALTER TABLE tasks ADD COLUMN started_at DATETIME NULL',
            'finished_at' => 'ALTER TABLE tasks ADD COLUMN finished_at DATETIME NULL',
        ];
        foreach ($alters as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e) {
                }
            }
        }
        try {
            DB::query("ALTER TABLE tasks MODIFY payload MEDIUMTEXT NULL");
            DB::query("ALTER TABLE tasks MODIFY status VARCHAR(32) NOT NULL DEFAULT 'draft'");
            DB::query("ALTER TABLE tasks MODIFY type VARCHAR(50) NOT NULL");
        } catch (\Throwable $e) {
        }
            try {
                \App\Services\SeedMeta::ensure();
            } catch (\Throwable $e) {
            }
            try {
                AccountWorkBatchService::ensureSchema();
            } catch (\Throwable $e) {
            }
    }
}
