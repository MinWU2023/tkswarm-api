<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 扫描到期自动增粉明细并入队账号会话总任务。
 */
class AutoFollowDispatchService
{
    /**
     * @return array{scanned:int,enqueued:int,failed:int,skipped:int,items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 50): array
    {
        AutoFollowService::ensureSchema();
        AccountWorkBatchService::ensureSchema();

        $limit = max(1, min(200, $limit));
        $rows = DB::select(
            "SELECT i.*, t.id AS _task_id, t.status AS task_status,
                    t.target_account_id, t.target_tiktok_uid, t.target_username, t.target_display,
                    t.owner_id AS task_owner_id
             FROM auto_follow_task_items i
             INNER JOIN auto_follow_tasks t ON t.id=i.task_id
             WHERE i.status='pending'
               AND i.scheduled_at<=NOW()
               AND t.deleted_at IS NULL
               AND t.status NOT IN ('cancelled','failed')
             ORDER BY i.scheduled_at ASC, i.id ASC
             LIMIT {$limit}"
        ) ?: [];

        $enqueued = 0;
        $failed = 0;
        $skipped = 0;
        $items = [];

        foreach ($rows as $row) {
            $taskRow = [
                'id' => (int)$row['_task_id'],
                'status' => (string)$row['task_status'],
                'target_account_id' => (int)$row['target_account_id'],
                'target_tiktok_uid' => (string)$row['target_tiktok_uid'],
                'target_username' => (string)$row['target_username'],
                'target_display' => (string)$row['target_display'],
                'owner_id' => (int)$row['task_owner_id'],
            ];
            $result = AutoFollowService::enqueueDueItem($row, $taskRow);
            $items[] = array_merge(['id' => (int)$row['id'], 'taskId' => (int)$row['_task_id']], $result);
            if (!empty($result['ok'])) {
                $enqueued++;
            } elseif (($result['reason'] ?? '') === '明细状态非 pending' || str_contains((string)($result['reason'] ?? ''), '已取消')) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        return [
            'scanned' => count($rows),
            'enqueued' => $enqueued,
            'failed' => $failed,
            'skipped' => $skipped,
            'items' => $items,
        ];
    }
}
