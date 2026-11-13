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
    public static function tick(int $limit = 50, ?int $taskId = null): array
    {
        AutoFollowService::ensureSchema();
        AccountWorkBatchService::ensureSchema();

        $limit = max(1, min(200, $limit));
        $params = [];
        $taskFilter = '';
        if ($taskId !== null && $taskId > 0) {
            $taskFilter = ' AND i.task_id=?';
            $params[] = $taskId;
        }
        $rows = DB::select(
            "SELECT i.*,
                    t.id AS _task_id, t.status AS task_status, t.owner_id AS task_owner_id,
                    t.dispatch_enabled, t.save_traffic,
                    t.target_account_id, t.target_tiktok_uid, t.target_username, t.target_display,
                    t.search_keyword, t.stay_seconds_min, t.stay_seconds_max, t.watch_video_count,
                    t.like_rate, t.comment_rate, t.comment_mode,
                    t.comment_ai_template_id, t.comment_ai_prompt, t.comment_text
             FROM auto_follow_task_items i
             INNER JOIN auto_follow_tasks t ON t.id=i.task_id
             WHERE i.status='pending'
               AND i.scheduled_at<=NOW()
               AND t.deleted_at IS NULL
               AND t.status NOT IN ('cancelled','failed')
               AND COALESCE(t.dispatch_enabled, 1)=1
               {$taskFilter}
             ORDER BY i.scheduled_at ASC, i.id ASC
             LIMIT {$limit}",
            $params
        ) ?: [];

        $enqueued = 0;
        $failed = 0;
        $skipped = 0;
        $items = [];

        foreach ($rows as $row) {
            $taskRow = [
                'id' => (int)$row['_task_id'],
                'status' => (string)$row['task_status'],
                'owner_id' => (int)$row['task_owner_id'],
                'dispatch_enabled' => (int)($row['dispatch_enabled'] ?? 1),
                'save_traffic' => (int)($row['save_traffic'] ?? 1),
                'target_account_id' => (int)$row['target_account_id'],
                'target_tiktok_uid' => (string)$row['target_tiktok_uid'],
                'target_username' => (string)$row['target_username'],
                'target_display' => (string)$row['target_display'],
                'search_keyword' => (string)($row['search_keyword'] ?? ''),
                'stay_seconds_min' => (int)($row['stay_seconds_min'] ?? 8),
                'stay_seconds_max' => (int)($row['stay_seconds_max'] ?? 20),
                'watch_video_count' => (int)($row['watch_video_count'] ?? 3),
                'like_rate' => (int)($row['like_rate'] ?? 40),
                'comment_rate' => (int)($row['comment_rate'] ?? 20),
                'comment_mode' => (string)($row['comment_mode'] ?? 'ai'),
                'comment_ai_template_id' => (int)($row['comment_ai_template_id'] ?? 0),
                'comment_ai_prompt' => (string)($row['comment_ai_prompt'] ?? ''),
                'comment_text' => (string)($row['comment_text'] ?? ''),
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

    /** 只派发某一任务下已到期的明细（创建后立即触发 / 手工「立即派发」） */
    public static function tickForTask(int $taskId, int $limit = 50): array
    {
        return self::tick($limit, $taskId);
    }
}
