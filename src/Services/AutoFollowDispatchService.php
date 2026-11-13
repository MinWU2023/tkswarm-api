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

        // 回收卡住的浏览器环境租约，便于 Node 领取已入队的增粉分项
        try {
            AccountBrowserEnvService::ensureSchema();
            AccountBrowserEnvService::reclaimExpiredEnvLeases();
            AccountWorkBatchService::reclaimExpiredLeases();
            AccountBrowserEnvService::backfillFromAccounts();
        } catch (\Throwable $e) {
        }

        $stuck = self::diagnoseStuckEnqueued(20);

        return [
            'scanned' => count($rows),
            'enqueued' => $enqueued,
            'failed' => $failed,
            'skipped' => $skipped,
            'items' => $items,
            'stuck' => $stuck,
        ];
    }

    /** 只派发某一任务下已到期的明细（创建后立即触发 / 手工「立即派发」） */
    public static function tickForTask(int $taskId, int $limit = 50): array
    {
        return self::tick($limit, $taskId);
    }

    /**
     * 诊断「明细已入队但矩阵未执行」的常见原因，供 schedule 日志输出。
     * @return list<array<string,mixed>>
     */
    public static function diagnoseStuckEnqueued(int $limit = 20): array
    {
        AutoFollowService::ensureSchema();
        AccountWorkBatchService::ensureSchema();
        AccountBrowserEnvService::ensureSchema();
        $limit = max(1, min(100, $limit));
        $rows = DB::select(
            "SELECT i.id, i.task_id, i.source_account_id, i.work_item_id, i.scheduled_at,
                    wi.status AS work_status, wi.batch_id,
                    b.status AS batch_status, b.push_status, b.owner_id AS batch_owner_id, b.locked_by,
                    (SELECT COUNT(*) FROM account_browser_envs e
                      WHERE e.account_id=i.source_account_id AND e.saturation='idle') AS idle_envs,
                    (SELECT COUNT(*) FROM account_browser_envs e
                      WHERE e.account_id=i.source_account_id) AS env_total
             FROM auto_follow_task_items i
             LEFT JOIN account_work_items wi ON wi.id=i.work_item_id
             LEFT JOIN account_work_batches b ON b.id=wi.batch_id
             WHERE i.status='enqueued'
             ORDER BY i.scheduled_at ASC, i.id ASC
             LIMIT {$limit}"
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $hints = [];
            $workStatus = (string)($r['work_status'] ?? '');
            $batchStatus = (string)($r['batch_status'] ?? '');
            $idle = (int)($r['idle_envs'] ?? 0);
            $envTotal = (int)($r['env_total'] ?? 0);
            if ($workStatus === '') {
                $hints[] = 'work_item 缺失';
            } elseif ($workStatus !== 'pending' && $workStatus !== 'running') {
                $hints[] = 'work_item=' . $workStatus;
            }
            if ($batchStatus === '') {
                $hints[] = 'batch 缺失';
            } elseif ($batchStatus === 'pending' && $idle < 1) {
                $hints[] = $envTotal < 1 ? '账号无浏览器环境' : '无 idle 环境(被占用)';
            } elseif ($batchStatus === 'pending') {
                $hints[] = '等 Node 领取(需本机 Node 在跑；无前台时会本机直跑)';
            } elseif ($batchStatus === 'running') {
                $hints[] = 'batch 执行中 push=' . (string)($r['push_status'] ?? '');
            }
            $out[] = [
                'id' => (int)$r['id'],
                'taskId' => (int)$r['task_id'],
                'accountId' => (int)$r['source_account_id'],
                'workItemId' => (int)($r['work_item_id'] ?? 0),
                'batchId' => (int)($r['batch_id'] ?? 0),
                'workStatus' => $workStatus,
                'batchStatus' => $batchStatus,
                'idleEnvs' => $idle,
                'hint' => $hints ? implode('；', $hints) : 'ok',
            ];
        }
        return $out;
    }
}
