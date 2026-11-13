<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountWorkBatchService;
use App\Services\CompanyService;
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
            // 支持多状态：queued,draft,paused 或 pending（待执行别名）
            $statuses = array_values(array_filter(array_map('trim', explode(',', $status))));
            if (count($statuses) === 1 && strtolower($statuses[0]) === 'pending') {
                $statuses = ['draft', 'queued', 'paused', 'running'];
            }
            $statuses = array_values(array_unique(array_filter($statuses)));
            if (count($statuses) > 1) {
                $ph = implode(',', array_fill(0, count($statuses), '?'));
                $filters[] = "t.status IN ({$ph})";
                foreach ($statuses as $st) {
                    $params[] = $st;
                }
            } elseif ($statuses) {
                $filters[] = 't.status=?';
                $params[] = $statuses[0];
            }
        }
        if ($name = trim((string)$request->get('name', $request->get('keyword', '')))) {
            $filters[] = 't.name LIKE ?';
            $params[] = '%' . $name . '%';
        }
        $source = trim((string)$request->get('source', ''));
        if ($source === 'company_entrust') {
            if ($err = $this->applyCompanyEntrustListFilter($filters, $params)) {
                return $err;
            }
        } else {
            // ��ͨ�����б���������ҵί��
            $filters[] = "(t.payload IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(t.payload, '$.source')) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(t.payload, '$.source'))<>'company_entrust')";
            $this->applyOwnerFilter('t.owner_id', $filters, $params);
        }
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
                // ��ֻ�����ڣ��㵽�������
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
                // �� fields һһ��Ӧ��header1,header2,...
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

    /** ����ģ�� CSV���̶���ʽ��һ�� = 1 �˺� + 1 ��Ƶ �� ������������ */
    public function importTemplate(Request $request): Response
    {
        $lines = [
            'account_id,material_id,task_name,publish_title,publish_caption',
            '1,10,��������ʾ��,�����ѡ,�İ���ѡ',
        ];
        return Response::make("\xEF\xBB\xBF" . implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="task-import-template.csv"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * ���̶�ģ�嵼�룺ÿ�д��� 1 ��������������publishMode=independent��
     * body: { content: "csv text", autoStart?: bool }
     */
    public function import(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $content = (string)$request->input('content', '');
        if (trim($content) === '') {
            return $this->error('��ճ�����ϴ� CSV ����', 422);
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
            // ȥ�� BOM
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
            return $this->error('CSV Ϊ�ջ�ȱ�ٱ�ͷ', 422);
        }
        $requiredOk = in_array('account_id', $header, true) && in_array('material_id', $header, true);
        if (!$requiredOk) {
            return $this->error('��ͷ������� account_id, material_id�������� task_name, publish_title, publish_caption��', 422);
        }
        if (count($rows) > 500) {
            return $this->error('������ർ�� 500 ��', 422);
        }

        $created = [];
        $errors = [];
        $ownerId = $this->writeOwnerId();
        foreach ($rows as $idx => $row) {
            $lineNo = $idx + 2; // ����ͷ
            $accountId = (int)($row['account_id'] ?? 0);
            $materialId = (int)($row['material_id'] ?? 0);
            if ($accountId <= 0 || $materialId <= 0) {
                $errors[] = "��{$lineNo}�У�account_id / material_id ��Ч";
                continue;
            }
            $acc = DB::selectOne('SELECT id, username, owner_id FROM accounts WHERE id=?', [$accountId]);
            if (!$acc) {
                $errors[] = "��{$lineNo}�У��˺� #{$accountId} ������";
                continue;
            }
            if (!DataScope::ownsRow($acc)) {
                $errors[] = "��{$lineNo}�У���Ȩʹ���˺� #{$accountId}";
                continue;
            }
            $mat = DB::selectOne('SELECT id, name, file_name, owner_id, status FROM materials WHERE id=?', [$materialId]);
            if (!$mat) {
                $errors[] = "��{$lineNo}�У��ز� #{$materialId} ������";
                continue;
            }
            if (!DataScope::ownsMaterialRow($mat)) {
                $errors[] = "��{$lineNo}�У���Ȩʹ���ز� #{$materialId}";
                continue;
            }
            $uname = (string)($acc['username'] ?? ('#' . $accountId));
            $mname = (string)($mat['name'] ?: ($mat['file_name'] ?? ('#' . $materialId)));
            $taskName = trim((string)($row['task_name'] ?? ''));
            if ($taskName === '') {
                $taskName = "�������� account({$uname})-video({$mname})";
            }
            $taskName = self::clampTaskName(
                preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', '', $taskName) ?: '��������'
            );
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
                $now = DB::now();
                DB::query(
                    'INSERT INTO tasks(name,type,group_id,total_count,scheduled_at,payload,status,owner_id,created_at,updated_at) VALUES (?,?,?,?,?,?,"draft",?,?,?)',
                    [
                        $taskName,
                        'publish',
                        null,
                        1,
                        null,
                        json_encode($payload, JSON_UNESCAPED_UNICODE),
                        $ownerId,
                        $now,
                        $now,
                    ]
                );
                $id = (int)DB::getPdo()->lastInsertId();
                $this->event($id, 'info', '�� CSV ���봴��������������');
                if ($autoStart) {
                    DB::query('UPDATE tasks SET status=?, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?', ['queued', $id]);
                    $this->event($id, 'info', '������Զ����');
                }
                $created[] = ['id' => $id, 'name' => $taskName, 'line' => $lineNo];
            } catch (\Throwable $e) {
                $errors[] = "��{$lineNo}�У�д��ʧ�� " . $e->getMessage();
            }
        }

        return $this->success([
            'created' => count($created),
            'failed' => count($errors),
            'items' => $created,
            'errors' => array_slice($errors, 0, 50),
        ], count($created) ? ('�ѵ��� ' . count($created) . ' ��������������') : 'û�гɹ��������');
    }

    /** @return array<string,string> field => default header label */
    private function exportFieldDefs(): array
    {
        return [
            'id' => 'ID',
            'name' => '��������',
            'type' => '����',
            'publish_mode' => '����ģʽ',
            'status' => '״̬',
            'group_name' => '����',
            'total_count' => '����',
            'success_count' => '�ɹ�',
            'fail_count' => 'ʧ��',
            'scheduled_at' => '�ƻ�ʱ��(�й�)',
            'schedule_local_at' => '���ض�ʱ',
            'schedule_country' => '��ʱ����',
            'schedule_timezone' => '�˺�ʱ��',
            'started_at' => '��ʼʱ��',
            'finished_at' => '����ʱ��',
            'created_at' => '����ʱ��',
            'account_ids' => '�˺�ID',
            'material_ids' => '�ز�ID',
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
            'ordered' => 'Ⱥ������',
            'independent' => '��������',
            'random' => '�������',
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
            'scheduled_at' => $payload['scheduleChinaAt'] ?? ($r['scheduled_at'] ?? ''),
            'schedule_local_at' => $payload['scheduleLocalAt'] ?? '',
            'schedule_country' => $payload['scheduleCountry'] ?? ($payload['scheduleCountryCode'] ?? ''),
            'schedule_timezone' => $payload['scheduleTimezone'] ?? '',
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
        $now = DB::now();
        DB::query(
            'INSERT INTO tasks(name,type,group_id,total_count,scheduled_at,payload,status,owner_id,created_at,updated_at) VALUES (?,?,?,?,?,?,"draft",?,?,?)',
            [
                $body['name'], $body['type'], $body['groupId'], $body['totalCount'],
                $body['scheduledAt'], json_encode($body['payload'], JSON_UNESCAPED_UNICODE),
                $this->writeOwnerId(),
                $now,
                $now,
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        $this->event($id, 'info', '�����Ѵ���');
        return $this->success(['id' => $id, ...$body, 'status' => 'draft'], '�����Ѵ���');
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        if (!in_array($task['status'], ['draft', 'paused', 'failed', 'completed'], true)) {
            return $this->error('ֻ�вݸ塢��ͣ��ʧ�ܻ������������Ա༭', 409);
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
        $this->event((int)$id, 'info', '���������Ѹ���');
        return $this->success(['id' => (int)$id, ...$body, 'status' => $task['status']], '�����Ѹ���');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        DB::query('DELETE FROM tasks WHERE id=?', [(int)$id]);
        return $this->success(null, '������ɾ��');
    }

    public function start(Request $request, string $id): Response
    {
        return $this->setStatus((int)$id, 'queued', ['draft', 'paused', 'failed', 'completed'], '�������������');
    }

    public function pause(Request $request, string $id): Response
    {
        return $this->setStatus((int)$id, 'paused', ['queued', 'running'], '��������ͣ');
    }

    public function cancel(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        if (!in_array($task['status'], ['queued', 'running', 'paused'], true)) {
            return $this->error('��ǰ״̬����ȡ��', 409);
        }
        DB::query("UPDATE tasks SET status='cancelled', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$id]);
        DB::query("UPDATE task_runs SET status='skipped', error_message='����ȡ��', finished_at=CURRENT_TIMESTAMP WHERE task_id=? AND status='running'", [(int)$id]);
        AccountWorkBatchService::cancelItemsForTask((string)$task['type'], (int)$id);
        $this->event((int)$id, 'warn', '������ȡ��');
        return $this->success(null, '������ȡ��');
    }

    public function retry(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        if ($task['status'] !== 'failed') {
            return $this->error('��ʧ�����������', 409);
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $payload['onlyFailed'] = 1;
        DB::query(
            "UPDATE tasks SET status='queued', payload=?, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [json_encode($payload, JSON_UNESCAPED_UNICODE), (int)$id]
        );
        AccountWorkBatchService::attachFromTask((int)$id);
        $this->event((int)$id, 'info', 'ʧ���������������');
        return $this->success(null, '���������');
    }

    public function batchRetry(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('taskIds', []));
        if (!$ids) {
            return $this->error('��ѡ��ʧ������', 400);
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
            $this->event($id, 'info', 'ʧ���˺����������½������');
            $changed++;
        }
        return $this->success(['requested' => count($ids), 'changed' => $changed], '�����������ύ');
    }

    /**
     * �������أ����ų�̬���Զ����ɷ����ƻ����� warm:recurring����
     * ������ status / payload.recurring ���ö������رպ� cron ����������ӡ�
     */
    public function patchRecurringDispatch(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if (!$task) {
            return $this->error('���񲻴���', 404);
        }
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        if ((string)($task['type'] ?? '') !== 'warm') {
            return $this->error('����������֧�ֳ�̬���ɷ�����', 422);
        }
        $raw = $request->input('recurringDispatchEnabled', $request->input('recurring_dispatch_enabled', null));
        if ($raw === null) {
            return $this->error('���ṩ recurringDispatchEnabled', 422);
        }
        $enabled = $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';
        DB::query(
            'UPDATE tasks SET recurring_dispatch_enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$enabled ? 1 : 0, (int)$id]
        );
        $this->event(
            (int)$id,
            'info',
            $enabled ? '�ѿ�����̬���Զ����ɷ�' : '�ѹرճ�̬���Զ����ɷ�'
        );
        $fresh = DB::selectOne('SELECT t.*, g.name AS group_name FROM tasks t LEFT JOIN `groups` g ON g.id=t.group_id WHERE t.id=?', [(int)$id]);
        return $this->success(
            $this->mapTask($fresh ?: $task),
            $enabled ? '�ѿ�����̬���Զ����ɷ�' : '�ѹرճ�̬���Զ����ɷ�'
        );
    }

    /**
     * �����������ų�̬���Զ����ɷ���
     */
    public function batchRecurringDispatch(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('taskIds', $request->input('ids', [])));
        if (!$ids) {
            return $this->error('��ѡ������', 400);
        }
        $raw = $request->input('recurringDispatchEnabled', $request->input('recurring_dispatch_enabled', null));
        if ($raw === null) {
            return $this->error('���ṩ recurringDispatchEnabled', 422);
        }
        $enabled = $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';
        $changed = 0;
        foreach ($ids as $tid) {
            $task = DB::selectOne('SELECT id, type, owner_id FROM tasks WHERE id=?', [$tid]);
            if (!$task || (string)($task['type'] ?? '') !== 'warm' || !DataScope::ownsRow($task)) {
                continue;
            }
            DB::query(
                'UPDATE tasks SET recurring_dispatch_enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$enabled ? 1 : 0, $tid]
            );
            $this->event(
                $tid,
                'info',
                $enabled ? '������������̬���Զ����ɷ�' : '�������رճ�̬���Զ����ɷ�'
            );
            $changed++;
        }
        return $this->success(
            ['requested' => count($ids), 'changed' => $changed],
            $enabled ? '������������̬���Զ����ɷ�' : '�������رճ�̬���Զ����ɷ�'
        );
    }

    public function batchAction(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('taskIds', []));
        $action = (string)$request->input('action', '');
        if (!$ids) {
            return $this->error('��ѡ������', 400);
        }
        if (!in_array($action, ['start', 'pause', 'cancel'], true)) {
            return $this->error('��֧�ֵ���������', 400);
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
                $this->event($id, 'warn', '����������ȡ��');
            }
        } else {
            $to = $action === 'start' ? 'queued' : 'paused';
            $allowed = $action === 'start' ? ['draft', 'paused', 'failed'] : ['queued', 'running'];
            foreach ($ids as $id) {
                $task = DB::selectOne('SELECT status, type, owner_id FROM tasks WHERE id=?', [$id]);
                if (!$task || !DataScope::ownsRow($task) || !in_array($task['status'], $allowed, true)) {
                    continue;
                }
                if ($action === 'start' && !in_array($task['type'], ['sync', 'profile', 'publish', 'publish_images', 'message', 'warm', 'sync_fans', 'dm_sync', 'comment_reply', 'pull_video_list', 'pull_comments'], true)) {
                    continue;
                }
                DB::query('UPDATE tasks SET status=?, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$to, $id]);
                if ($action === 'start') {
                    AccountWorkBatchService::attachFromTask($id);
                }
                $this->event($id, 'info', $action === 'start' ? '��������������' : '������������ͣ');
                $changed++;
            }
        }
        return $this->success(['requested' => count($ids), 'changed' => $changed], '�����������');
    }

    public function batchDelete(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('taskIds', []));
        if (!$ids) {
            return $this->error('��ѡ������', 400);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $stmt = DB::query("DELETE FROM tasks WHERE id IN ({$ph}){$ownSql}", array_merge($ids, $ownParams));
        return $this->success(['deleted' => $stmt->rowCount()], '����ɾ�����');
    }

    public function preflight(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id,type,payload,group_id,total_count,owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $issues = [];
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', $payload['accountIds'] ?? []))));
        if ($selectedIds) {
            $ph = implode(',', array_fill(0, count($selectedIds), '?'));
            $accountCount = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM accounts WHERE enabled=1 AND id IN ({$ph})", $selectedIds)['c'] ?? 0);
            if ($accountCount !== count($selectedIds)) {
                $issues[] = '����ָ���˺Ų����ڻ�δ����';
            }
        } elseif (!empty($task['group_id'])) {
            $accountCount = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM accounts WHERE enabled=1 AND group_id=?', [(int)$task['group_id']])['c'] ?? 0);
        } else {
            $accountCount = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM accounts WHERE enabled=1')['c'] ?? 0);
        }
        if (!$accountCount) {
            $issues[] = 'û�з��������������˺�';
        }
        if ((int)$task['total_count'] > 0 && $accountCount < (int)$task['total_count']) {
            $issues[] = "Ŀ���˺Ų��㣺��Ҫ {$task['total_count']} ������ǰֻ�� {$accountCount} ��";
        }
        return $this->success(['ok' => !$issues, 'issues' => $issues, 'accountCount' => $accountCount]);
    }

    public function prepare(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
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
        $this->event((int)$id, 'info', 'ִ�з���������');
        return $this->success(['id' => $planId, 'status' => 'prepared', 'plan' => $plan], '������׼��');
    }

    public function plans(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
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
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        $plan = DB::selectOne('SELECT * FROM task_plans WHERE id=? AND task_id=?', [(int)$planId, (int)$id]);
        if (!$plan) {
            return $this->error('ִ�з���������', 404);
        }
        if ($plan['status'] !== 'prepared') {
            return $this->error('ִ�з�����ǰ����ȷ��', 409);
        }
        DB::query("UPDATE task_plans SET status='confirmed', confirmed_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$planId]);
        $this->event((int)$id, 'info', 'ִ�з�����ȷ�ϣ�����ɰ�ִ������������');
        return $this->success(['id' => (int)$planId, 'status' => 'confirmed'], '������ȷ��');
    }

    public function events(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
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
        if ($err = $this->assertTaskAccess($task)) {
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
        if ($err = $this->assertTaskAccess($task)) {
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
        if ($err = $this->assertTaskAccess($task)) {
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
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        $stmt = DB::query(
            "UPDATE task_items SET status='pending', error_message='', updated_at=CURRENT_TIMESTAMP
             WHERE task_id=? AND status IN ('failed','skipped')",
            [(int)$id]
        );
        $this->event((int)$id, 'info', 'ʧ��/������Ŀ������');
        return $this->success(['updated' => $stmt->rowCount()], '������ʧ����Ŀ');
    }

    public function batchUpdateItems(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        $caption = (string)$request->input('caption', $request->input('content', ''));
        $ids = $this->idList($request->input('itemIds', []));
        if (!$ids) {
            return $this->error('��ѡ����Ŀ', 400);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = DB::query(
            "UPDATE task_items SET caption=?, updated_at=CURRENT_TIMESTAMP WHERE task_id=? AND id IN ({$ph})",
            array_merge([$caption, (int)$id], $ids)
        );
        return $this->success(['updated' => $stmt->rowCount()], '��Ŀ�Ѹ���');
    }

    public function updateItem(Request $request, string $id, string $itemId): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        $item = DB::selectOne('SELECT * FROM task_items WHERE id=? AND task_id=?', [(int)$itemId, (int)$id]);
        if (!$item) {
            return $this->error('��Ŀ������', 404);
        }
        $caption = $request->input('caption', $request->input('content'));
        if ($caption !== null) {
            DB::query('UPDATE task_items SET caption=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(string)$caption, (int)$itemId]);
        }
        $row = DB::selectOne('SELECT * FROM task_items WHERE id=?', [(int)$itemId]);
        $row['meta'] = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
        unset($row['meta_json']);
        return $this->success($row, '��Ŀ�Ѹ���');
    }

    public function preview(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        return $this->success($this->mapTask($task));
    }

    private function setStatus(int $id, string $status, array $allowed, string $msg, bool $silent = false): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [$id]);
        if ($err = $this->assertTaskAccess($task)) {
            return $err;
        }
        if (!in_array($task['status'], $allowed, true)) {
            return $this->error('��ǰ״̬���ɲ���', 409);
        }
        if ($status === 'queued' && !in_array($task['type'], [
            'sync', 'profile', 'publish', 'publish_images', 'message', 'warm', 'sync_fans', 'dm_sync',
            'comment_reply', 'pull_video_list', 'pull_comments',
        ], true)) {
            return $this->error('该任务类型暂不支持启动', 422);
        }
        DB::query('UPDATE tasks SET status=?, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$status, $id]);
        if ($status === 'queued') {
            $attached = AccountWorkBatchService::attachFromTask($id);
            if (!empty($attached['deferred'])) {
                $when = trim((string)($task['scheduled_at'] ?? ''));
                $msg = $when !== ''
                    ? "��������ӣ����� {$when}���й�ʱ�䣩����ִ��"
                    : '��������ӣ��ȴ���������ִ��';
            }
        }
        $this->event($id, 'info', $msg);
        return $this->success($silent ? ['ok' => true] : null, $msg);
    }

    private function parseBody(Request $request): array
    {
        $type = (string)$request->input('type', 'sync');
        if (!in_array($type, ['publish', 'publish_images', 'message', 'sync', 'profile', 'warm', 'sync_fans', 'dm_sync'], true)) {
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
            'name' => self::clampTaskName((string)$request->input('name', '')),
            'type' => $type,
            'groupId' => ($groupId === null || $groupId === '') ? null : (int)$groupId,
            'totalCount' => max(0, (int)$request->input('totalCount', 0)),
            'scheduledAt' => $scheduled,
            'payload' => $payload,
        ];
    }

    /** tasks.name ���ޣ��� ensureSchema / Ǩ��һ�£� */
    private const TASK_NAME_MAX = 255;

    private static function clampTaskName(string $name): string
    {
        $name = trim(preg_replace('/[\r\n]+/u', ' ', $name) ?? $name);
        if ($name === '') {
            return '';
        }
        return mb_substr($name, 0, self::TASK_NAME_MAX);
    }

    private function validateBody(array $body): ?string
    {
        if ($body['name'] === '') {
            return '�������Ʋ���Ϊ��';
        }
        if ($body['type'] === 'message') {
            $accountIds = array_filter(array_map('intval', $body['payload']['accountIds'] ?? []));
            if (!$accountIds && !$body['groupId']) {
                return '�빴ѡ�˺Ż�ѡ���˺ŷ���';
            }
            $targetType = (string)($body['payload']['sendTargetType'] ?? 'imported');
            if ($targetType === 'fixed') {
                $lines = preg_split('/\r?\n/', (string)($body['payload']['fixedTargets'] ?? '')) ?: [];
                if (!array_filter(array_map('trim', $lines))) {
                    return '�̶�������Ϊ��';
                }
            }
            if ($targetType === 'imported' && !(int)($body['payload']['uidGroupId'] ?? 0)) {
                return '��ѡ�� UID ������Ϊ���Ͷ���';
            }
            if ($targetType === 'followers') {
                // ��˿Ŀ���������� relation�������������޷�˿ʱִ�н׶�����
            }
            if (trim((string)($body['payload']['content'] ?? '')) === '' && !(int)($body['payload']['templateId'] ?? 0)) {
                return '����д�������ݻ�ѡ����ģ��';
            }
        }
        if (in_array($body['type'], ['sync_fans', 'dm_sync'], true)) {
            $accountIds = array_filter(array_map('intval', $body['payload']['accountIds'] ?? []));
            if (!$accountIds && !$body['groupId']) {
                return '�빴ѡ�˺Ż�ѡ���˺ŷ���';
            }
        }
        if ($body['type'] === 'publish_images') {
            $accountIds = array_values(array_filter(array_map('intval', $body['payload']['accountIds'] ?? [])));
            if (count($accountIds) !== 1) {
                return '图片发布只能选择 1 个账号';
            }
            $imgs = $body['payload']['images'] ?? [];
            if (!is_array($imgs) || count($imgs) < 1) {
                return '请至少选择 1 张图片';
            }
            if (count($imgs) > 35) {
                return '图片最多 35 张';
            }
            $styles = $body['payload']['musicStyles'] ?? [];
            if (is_array($styles) && count($styles) > 5) {
                return '音乐风格最多选 5 个';
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
                // ����/�Զ�����ϣ��� pairs Ϊ׼
            } else {
                $materialIds = array_filter(array_map('intval', $body['payload']['materialIds'] ?? []));
                if (!$materialIds) {
                    return '����������Ҫ�����ز�';
                }
                $accountIds = array_filter(array_map('intval', $body['payload']['accountIds'] ?? []));
                if (!$accountIds && !$body['groupId']) {
                    return '�빴ѡ�˺Ż�ѡ���˺ŷ���';
                }
            }
        }
        if ($body['type'] === 'warm') {
            $accountIds = array_filter(array_map('intval', $body['payload']['accountIds'] ?? []));
            if (!$accountIds && !$body['groupId']) {
                return '�빴ѡ�˺Ż�ѡ���˺ŷ���';
            }
            $searchType = (string)($body['payload']['searchType'] ?? 'video');
            if (!in_array($searchType, ['video', 'top', 'hashtag', 'user', 'aweme'], true)) {
                return '��Ч����������';
            }
            if (trim((string)($body['payload']['searchTerms'] ?? '')) === '') {
                return '����д�����ʻ���Ʒ ID';
            }
        }
        return null;
    }

    private function normalizeWarmPayload(array $payload): array
    {
        // ���Ž�֧����Ƶ����
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
            // �����۸��ʣ���ʽ commentRate���������á����ۡ�����
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
        // ���������ִ�/�ϴδ���ʱ�䣨�༭����ʱ������
        if (isset($payload['recurringLastRunAt'])) {
            $normalized['recurringLastRunAt'] = $payload['recurringLastRunAt'];
        }
        if (isset($payload['recurringCycle'])) {
            $normalized['recurringCycle'] = (int)$payload['recurringCycle'];
        }
        // ����ʱ�̻�ִ�мƻ������˺���ÿ�˺Ŷ���һ��������
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
        $row['recurringDispatchEnabled'] = !array_key_exists('recurring_dispatch_enabled', $row)
            || (int)($row['recurring_dispatch_enabled'] ?? 1) === 1;
        return $row;
    }

    /**
     * �ͻ�ί���б��ɼ��ԣ�����/��Ӫ������ȫ������ӪרԱ��������ҵ����ҵ�ͻ��ύ��ί�С�
     * @param list<string> $filters
     * @param list<mixed> $params
     */
    private function applyCompanyEntrustListFilter(array &$filters, array &$params): ?Response
    {
        $filters[] = "JSON_UNQUOTE(JSON_EXTRACT(t.payload, '$.source'))='company_entrust'";
        if (DataScope::isSuper() || DataScope::isOpsAdmin()) {
            return null;
        }
        if (DataScope::isOperator()) {
            $ownerIds = $this->operatorLinkedEnterpriseUserIds();
            if (!$ownerIds) {
                $filters[] = '1=0';
                return null;
            }
            $ph = implode(',', array_fill(0, count($ownerIds), '?'));
            $filters[] = "t.owner_id IN ({$ph})";
            array_push($params, ...$ownerIds);
            return null;
        }
        // ������ɫ��������
        $oid = DataScope::filterOwnerId();
        if ($oid === null) {
            return null;
        }
        $filters[] = 't.owner_id=?';
        $params[] = $oid;
        return null;
    }

    /** @return list<int> */
    private function operatorLinkedEnterpriseUserIds(): array
    {
        $selfId = (int)(DataScope::user()['id'] ?? DataScope::effectiveUser()['id'] ?? 0);
        CompanyService::ensureSchema();
        $companyIds = $selfId > 0 ? CompanyService::companyIdsOfUser($selfId) : [];
        if (!$companyIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($companyIds), '?'));
        $rows = DB::select(
            "SELECT DISTINCT u.id
             FROM admin_users u
             INNER JOIN admin_roles r ON r.id = u.role_id
             INNER JOIN company_users cu ON cu.user_id = u.id
             WHERE u.status = 1
               AND r.name LIKE '%企业客户%'
               AND cu.company_id IN ({$ph})",
            $companyIds
        ) ?: [];
        return array_values(array_unique(array_filter(array_map(
            static fn($r) => (int)($r['id'] ?? 0),
            $rows
        ))));
    }

    private function isCompanyEntrustTask(?array $task): bool
    {
        if (!$task) {
            return false;
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            return false;
        }
        return (string)($payload['source'] ?? '') === 'company_entrust';
    }

    /** �����������ҵί������Ӫ�ɼ��� */
    private function assertTaskAccess(?array $task): ?Response
    {
        if (!$task) {
            return $this->error('资源不存在', 404);
        }
        $ownErr = $this->assertOwns($task);
        if ($ownErr === null) {
            return null;
        }
        if (!$this->isCompanyEntrustTask($task)) {
            return $ownErr;
        }
        if (DataScope::isSuper() || DataScope::isOpsAdmin()) {
            return null;
        }
        if (DataScope::isOperator()) {
            $ownerIds = $this->operatorLinkedEnterpriseUserIds();
            if (in_array((int)($task['owner_id'] ?? 0), $ownerIds, true)) {
                return null;
            }
            return $this->error('无权操作该客户委托任务', 403);
        }
        return $ownErr;
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
            'recurring_dispatch_enabled' => "ALTER TABLE tasks ADD COLUMN recurring_dispatch_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '���ų�̬���ɷ�����'",
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
            // install.sql / �ɿ��Ϊ VARCHAR(100)���������ز�ƴ�������� 1406
            DB::query('ALTER TABLE tasks MODIFY name VARCHAR(255) NOT NULL');
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
