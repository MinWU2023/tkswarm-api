<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\Ai\AiCallLogger;
use App\Services\DataScope;

/**
 * AI 调用记录查询
 */
class AiCallLogController extends Controller
{
    public function list(Request $request): Response
    {
        AiCallLogger::ensureTable();
        DataScope::ensureColumns();

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $status = trim((string)$request->get('status', ''));
        $operation = trim((string)$request->get('operation', ''));
        $platformId = (int)$request->get('platformId', $request->get('platform_id', 0));
        $userId = (int)$request->get('userId', $request->get('user_id', 0));
        $dateFrom = trim((string)$request->get('dateFrom', $request->get('from', '')));
        $dateTo = trim((string)$request->get('dateTo', $request->get('to', '')));

        $filters = [];
        $params = [];
        if ($status !== '' && in_array($status, ['success', 'failed'], true)) {
            $filters[] = 'status=?';
            $params[] = $status;
        }
        if ($operation !== '') {
            $filters[] = 'operation=?';
            $params[] = $operation;
        }
        if ($platformId > 0) {
            $filters[] = 'platform_id=?';
            $params[] = $platformId;
        }
        if ($userId > 0) {
            $filters[] = 'user_id=?';
            $params[] = $userId;
        }
        if ($keyword !== '') {
            $filters[] = '(platform_name LIKE ? OR platform_code LIKE ? OR config_name LIKE ? OR username LIKE ? OR operation LIKE ? OR error_message LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateFrom)) {
            $filters[] = 'created_at >= ?';
            $params[] = substr($dateFrom, 0, 10) . ' 00:00:00';
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateTo)) {
            $filters[] = 'created_at <= ?';
            $params[] = substr($dateTo, 0, 10) . ' 23:59:59';
        }

        $oid = DataScope::filterOwnerId();
        if ($oid !== null) {
            $filters[] = '(owner_id=? OR owner_id IS NULL OR user_id=?)';
            $params[] = $oid;
            $params[] = $oid;
        }

        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM ai_call_logs {$where}", $params)['c'] ?? 0);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT id, platform_id, platform_code, platform_name, config_id, config_name,
                    user_id, username, operation, status, error_message, model,
                    input_bytes, output_bytes, duration_ms, created_at,
                    LEFT(request_content, 200) AS request_preview,
                    LEFT(response_content, 200) AS response_preview
             FROM ai_call_logs {$where}
             ORDER BY id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        return $this->success([
            'items' => array_map([$this, 'mapListRow'], $rows),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $pageSize)),
            'operations' => $this->operationOptions(),
        ]);
    }

    public function detail(Request $request, string $id): Response
    {
        AiCallLogger::ensureTable();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM ai_call_logs WHERE id=?', [(int)$id]);
        if ($err = $this->assertLogAccess($row)) {
            return $err;
        }
        return $this->success($this->mapDetailRow($row));
    }

    public function delete(Request $request, string $id): Response
    {
        AiCallLogger::ensureTable();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM ai_call_logs WHERE id=?', [(int)$id]);
        if ($err = $this->assertLogAccess($row)) {
            return $err;
        }
        DB::query('DELETE FROM ai_call_logs WHERE id=?', [(int)$id]);
        return $this->success(null, '记录已删除');
    }

    public function batchDelete(Request $request): Response
    {
        AiCallLogger::ensureTable();
        DataScope::ensureColumns();
        $ids = $request->input('ids', []);
        if (!is_array($ids) || !$ids) {
            return $this->error('请选择要删除的记录', 400);
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($x) => $x > 0));
        if (!$ids) {
            return $this->error('无效的记录 ID', 400);
        }
        $oid = DataScope::filterOwnerId();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $sql = "DELETE FROM ai_call_logs WHERE id IN ({$ph})";
        if ($oid !== null) {
            $sql .= ' AND (owner_id=? OR user_id=?)';
            $params[] = $oid;
            $params[] = $oid;
        }
        $stmt = DB::query($sql, $params);
        return $this->success(['deleted' => $stmt->rowCount()], '批量删除完成');
    }

    public function clear(Request $request): Response
    {
        AiCallLogger::ensureTable();
        DataScope::ensureColumns();
        $before = trim((string)$request->input('before', ''));
        $oid = DataScope::filterOwnerId();
        $params = [];
        $sql = 'DELETE FROM ai_call_logs WHERE 1=1';
        if ($before !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $before)) {
            $sql .= ' AND created_at < ?';
            $params[] = substr($before, 0, 10) . ' 00:00:00';
        }
        if ($oid !== null) {
            $sql .= ' AND (owner_id=? OR user_id=?)';
            $params[] = $oid;
            $params[] = $oid;
        }
        $stmt = DB::query($sql, $params);
        return $this->success(['deleted' => $stmt->rowCount()], '清理完成');
    }

    private function assertLogAccess(?array $row): ?Response
    {
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $oid = DataScope::filterOwnerId();
        if ($oid === null) {
            return null;
        }
        $owner = (int)($row['owner_id'] ?? 0);
        $uid = (int)($row['user_id'] ?? 0);
        if ($owner === $oid || $uid === $oid || ($row['owner_id'] ?? null) === null) {
            return null;
        }
        return $this->error('无权访问该记录', 403);
    }

    /** @return list<array{code:string,label:string}> */
    private function operationOptions(): array
    {
        return [
            ['code' => 'chat', 'label' => '对话调用'],
            ['code' => 'platform_test', 'label' => '平台连通测试'],
            ['code' => 'translate', 'label' => '翻译'],
            ['code' => 'agent', 'label' => '智能体'],
            ['code' => 'prompt_render', 'label' => '提示词渲染'],
        ];
    }

    private function mapListRow(array $row): array
    {
        $in = (int)($row['input_bytes'] ?? 0);
        $out = (int)($row['output_bytes'] ?? 0);
        return [
            'id' => (int)$row['id'],
            'platformId' => isset($row['platform_id']) ? (int)$row['platform_id'] : null,
            'platformCode' => $row['platform_code'] ?? '',
            'platformName' => $row['platform_name'] ?? '',
            'configId' => isset($row['config_id']) ? (int)$row['config_id'] : null,
            'configName' => $row['config_name'] ?? '',
            'userId' => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'username' => $row['username'] ?? '',
            'operation' => $row['operation'] ?? '',
            'operationLabel' => $this->operationLabel((string)($row['operation'] ?? '')),
            'status' => $row['status'] ?? '',
            'errorMessage' => $row['error_message'] ?? '',
            'model' => $row['model'] ?? '',
            'inputBytes' => $in,
            'outputBytes' => $out,
            'inputKb' => AiCallLogger::bytesToKb($in),
            'outputKb' => AiCallLogger::bytesToKb($out),
            'durationMs' => (int)($row['duration_ms'] ?? 0),
            'requestPreview' => $row['request_preview'] ?? '',
            'responsePreview' => $row['response_preview'] ?? '',
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    private function mapDetailRow(array $row): array
    {
        $base = $this->mapListRow($row);
        $base['requestContent'] = $row['request_content'] ?? '';
        $base['responseContent'] = $row['response_content'] ?? '';
        $meta = json_decode((string)($row['meta_json'] ?? ''), true);
        $base['meta'] = is_array($meta) ? $meta : null;
        return $base;
    }

    private function operationLabel(string $code): string
    {
        foreach ($this->operationOptions() as $op) {
            if ($op['code'] === $code) {
                return $op['label'];
            }
        }
        return $code !== '' ? $code : '-';
    }
}
