<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountWorkBatchService;
use App\Services\DataScope;

/**
 * 账号会话总任务查询 / 暂停恢复 / 手动挂载（UI 用）。
 */
class AccountWorkController extends Controller
{
    public function listBatches(Request $request): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $accountId = (int)$request->get('accountId', $request->get('account_id', 0));
        $status = trim((string)$request->get('status', ''));
        $keyword = trim((string)$request->get('keyword', $request->get('q', '')));
        $createdFrom = trim((string)$request->get('createdFrom', $request->get('created_from', '')));
        $createdTo = trim((string)$request->get('createdTo', $request->get('created_to', '')));
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $params = [];
        [$ownSql, $ownParams] = DataScope::workBatchListSql('b');
        $params = array_merge($params, $ownParams);
        if ($accountId > 0) {
            $where[] = 'b.account_id=?';
            $params[] = $accountId;
        }
        $includeDeleted = (int)$request->get('includeDeleted', $request->get('include_deleted', 0)) === 1;
        if ($status === 'deleted') {
            $where[] = "(b.status='deleted' OR b.deleted_at IS NOT NULL)";
        } elseif ($status !== '') {
            $where[] = 'b.status=?';
            $params[] = $status;
            if (!$includeDeleted) {
                $where[] = 'b.deleted_at IS NULL';
            }
        } elseif (!$includeDeleted) {
            $where[] = "b.deleted_at IS NULL AND b.status<>'deleted'";
        }
        if ($keyword !== '') {
            $where[] = '(a.username LIKE ? OR CAST(b.account_id AS CHAR) = ? OR CAST(b.id AS CHAR) = ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $keyword;
            $params[] = $keyword;
        }
        // 按创建时间筛选（日期字符串 YYYY-MM-DD 或完整 datetime）
        if ($createdFrom !== '') {
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdFrom)
                ? $createdFrom . ' 00:00:00'
                : $createdFrom;
            $where[] = 'b.created_at >= ?';
            $params[] = $from;
        }
        if ($createdTo !== '') {
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdTo)
                ? $createdTo . ' 23:59:59'
                : $createdTo;
            $where[] = 'b.created_at <= ?';
            $params[] = $to;
        }
        $sqlWhere = implode(' AND ', $where) . $ownSql;
        $total = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM account_work_batches b
             LEFT JOIN accounts a ON a.id=b.account_id
             WHERE {$sqlWhere}",
            $params
        )['c'] ?? 0);
        $rows = DB::select(
            "SELECT b.*, a.username,
                    (SELECT COUNT(*) FROM account_work_items i WHERE i.batch_id=b.id) AS item_count,
                    (SELECT COUNT(*) FROM account_work_items i WHERE i.batch_id=b.id AND i.status='pending') AS pending_count,
                    (SELECT COUNT(*) FROM account_work_items i WHERE i.batch_id=b.id AND i.status='paused') AS paused_count
             FROM account_work_batches b
             LEFT JOIN accounts a ON a.id=b.account_id
             WHERE {$sqlWhere}
             ORDER BY b.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        // 列表展示以实时 item_count 为准，顺带纠偏偏大的 item_total
        foreach ($rows as &$row) {
            $live = (int)($row['item_count'] ?? 0);
            if ((int)($row['item_total'] ?? 0) !== $live) {
                try {
                    DB::query(
                        'UPDATE account_work_batches SET item_total=? WHERE id=?',
                        [$live, (int)$row['id']]
                    );
                } catch (\Throwable $e) {
                }
                $row['item_total'] = $live;
            }
        }
        unset($row);

        return $this->success([
            'list' => $rows,
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => (int)ceil($total / max(1, $pageSize)),
        ]);
    }

    public function batchItems(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $batch = DB::selectOne(
            "SELECT b.*, a.username
             FROM account_work_batches b
             LEFT JOIN accounts a ON a.id=b.account_id
             WHERE b.id=?",
            [(int)$id]
        );
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        $items = DB::select(
            'SELECT * FROM account_work_items WHERE batch_id=? ORDER BY priority ASC, seq ASC, id ASC',
            [(int)$id]
        ) ?: [];
        foreach ($items as &$item) {
            $item['payload'] = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
            $item['result'] = json_decode((string)($item['result_json'] ?? '{}'), true) ?: [];
            unset($item['payload_json'], $item['result_json']);
        }
        unset($item);

        // 纠偏：冗余 item_total 与真实行数不一致时写回，避免汇总「子任务 9」却只列出 7 条
        $liveCount = count($items);
        $storedTotal = (int)($batch['item_total'] ?? 0);
        if ($storedTotal !== $liveCount) {
            try {
                DB::query(
                    'UPDATE account_work_batches SET item_total=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$liveCount, (int)$id]
                );
                $batch['item_total'] = $liveCount;
            } catch (\Throwable $e) {
            }
        }
        $batch['item_count'] = $liveCount;
        $batch['pending_count'] = count(array_filter(
            $items,
            static fn($it) => ($it['status'] ?? '') === 'pending'
        ));
        $batch['paused_count'] = count(array_filter(
            $items,
            static fn($it) => ($it['status'] ?? '') === 'paused'
        ));

        return $this->success(['batch' => $batch, 'items' => $items, 'total' => $liveCount]);
    }

    public function pauseBatch(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        try {
            $out = AccountWorkBatchService::pauseBatch((int)$id);
            return $this->success($out, '总任务已暂停');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function resumeBatch(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        try {
            $out = AccountWorkBatchService::resumeBatch((int)$id);
            return $this->success($out, '总任务已恢复');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function deleteBatch(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        try {
            $out = AccountWorkBatchService::softDeleteBatch((int)$id);
            return $this->success($out, '总任务已删除，不再执行');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    /**
     * 批量清除总任务：body { ids: number[] } 勾选清除；body { all: true } 清除全部（按数据权限）。
     * 语义与单个删除一致（软删除），记录仍可按状态「已删除」查看。
     */
    public function clearBatches(Request $request): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $all = (bool)$request->input('all', $request->input('clearAll', $request->input('clear_all', false)));
        $raw = $request->input('ids', $request->input('batchIds', $request->input('batch_ids', [])));
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                $id = (int)$v;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        $ids = array_values($ids);
        if (!$all && !$ids) {
            return $this->error('请先勾选要清除的总任务', 422);
        }
        try {
            $out = AccountWorkBatchService::clearBatches($ids, $all);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
        $msg = $all ? "已清除全部 {$out['deleted']} 个总任务" : "已清除 {$out['deleted']} 个总任务";
        if (!empty($out['skipped'])) {
            $msg .= "，跳过 {$out['skipped']} 个（已删除或无权操作）";
        }
        return $this->success($out, $msg);
    }

    /** 强制失败收口（比特权限等不可重试场景，停止空转重推） */
    public function forceFailBatch(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        $errorMessage = trim((string)$request->input('errorMessage', $request->input('error_message', '手动强制失败收口')));
        try {
            $out = AccountWorkBatchService::forceFailBatch((int)$id, $errorMessage);
            return $this->success($out, '已强制失败，不再重推');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function pauseItem(Request $request, string $id, string $itemId): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=? AND batch_id=?', [(int)$itemId, (int)$id]);
        if (!$item) {
            return $this->error('子任务不存在', 404);
        }
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        try {
            $out = AccountWorkBatchService::pauseItem((int)$itemId);
            return $this->success($out, '子任务已暂停');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function resumeItem(Request $request, string $id, string $itemId): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=? AND batch_id=?', [(int)$itemId, (int)$id]);
        if (!$item) {
            return $this->error('子任务不存在', 404);
        }
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        try {
            $out = AccountWorkBatchService::resumeItem((int)$itemId);
            return $this->success($out, '子任务已恢复');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function resetBatch(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        try {
            $out = AccountWorkBatchService::resetBatch((int)$id);
            return $this->success($out, '总任务及全部子任务已重置为待执行');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function resetItem(Request $request, string $id, string $itemId): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=? AND batch_id=?', [(int)$itemId, (int)$id]);
        if (!$item) {
            return $this->error('子任务不存在', 404);
        }
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        try {
            $out = AccountWorkBatchService::resetItem((int)$itemId);
            return $this->success($out, '子任务及所属总任务已重置为待执行');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    /** 子任务 / 总任务执行日志列表 */
    public function itemLogs(Request $request, string $id, string $itemId): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=? AND batch_id=?', [(int)$itemId, (int)$id]);
        if (!$item) {
            return $this->error('子任务不存在', 404);
        }
        $limit = min(500, max(1, (int)$request->get('pageSize', $request->get('limit', 200))));
        $logs = AccountWorkBatchService::listLogs((int)$id, (int)$itemId, $limit);
        return $this->success([
            'batchId' => (int)$id,
            'itemId' => (int)$itemId,
            'taskType' => (string)($item['task_type'] ?? ''),
            'status' => (string)($item['status'] ?? ''),
            'items' => $logs,
            'total' => count($logs),
        ]);
    }

    public function batchLogs(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$id]);
        if ($err = $this->assertWorkBatchOwns($batch)) {
            return $err;
        }
        $limit = min(500, max(1, (int)$request->get('pageSize', $request->get('limit', 200))));
        $logs = AccountWorkBatchService::listLogs((int)$id, 0, $limit);
        return $this->success([
            'batchId' => (int)$id,
            'items' => $logs,
            'total' => count($logs),
        ]);
    }

    public function enqueue(Request $request): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $taskType = (string)$request->input('taskType', $request->input('task_type', ''));
        $taskRefId = (int)$request->input('taskRefId', $request->input('task_ref_id', 0));
        $payload = $request->input('payload', []);
        if (!is_array($payload)) {
            $payload = [];
        }
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        try {
            $out = AccountWorkBatchService::enqueueItem(
                $accountId,
                $taskType,
                $payload,
                $taskRefId,
                $this->writeOwnerId()
            );
            return $this->success($out, '已挂入账号会话总任务');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 400);
        }
    }
}
