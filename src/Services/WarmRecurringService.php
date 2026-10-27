<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 常态化自动养号：扫描已结束的养号任务，按间隔重新入队并挂到账号会话总任务。
 */
class WarmRecurringService
{
    /**
     * @return array{scanned:int, reset:int, skipped:int, items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 50): array
    {
        AccountWorkBatchService::ensureSchema();
        $limit = max(1, min(200, $limit));

        $tasks = DB::select(
            "SELECT * FROM tasks
             WHERE type='warm' AND status IN ('completed','failed')
             ORDER BY COALESCE(finished_at, updated_at) ASC
             LIMIT {$limit}"
        ) ?: [];

        $scanned = 0;
        $reset = 0;
        $skipped = 0;
        $items = [];

        foreach ($tasks as $task) {
            $scanned++;
            $id = (int)$task['id'];
            $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
            if (!filter_var($payload['recurring'] ?? $payload['isRecurring'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $skipped++;
                continue;
            }

            $days = max(1, min(365, (int)($payload['recurringDays'] ?? $payload['repeatDays'] ?? 1)));
            $anchor = (string)(
                $payload['recurringLastRunAt']
                ?? $task['finished_at']
                ?? $task['updated_at']
                ?? ''
            );
            $anchorTs = $anchor !== '' ? (int)strtotime($anchor) : 0;
            if ($anchorTs > 0 && (time() - $anchorTs) < ($days * 86400)) {
                $skipped++;
                $items[] = [
                    'id' => $id,
                    'ok' => false,
                    'reason' => "未到间隔（每 {$days} 天，锚点 {$anchor}）",
                ];
                continue;
            }

            // 仍有进行中的分项则跳过，避免重复挂载
            $open = DB::selectOne(
                "SELECT COUNT(*) AS c FROM account_work_items
                 WHERE task_ref_id=? AND status IN ('pending','running')",
                [$id]
            );
            if ((int)($open['c'] ?? 0) > 0) {
                $skipped++;
                $items[] = ['id' => $id, 'ok' => false, 'reason' => '仍有进行中的子任务'];
                continue;
            }

            try {
                $result = self::resetAndAttach($task, $payload, $days);
                $reset++;
                $items[] = array_merge(['id' => $id, 'ok' => true], $result);
            } catch (\Throwable $e) {
                $skipped++;
                $items[] = ['id' => $id, 'ok' => false, 'reason' => $e->getMessage()];
            }
        }

        return compact('scanned', 'reset', 'skipped', 'items');
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $payload
     * @return array{batches:int, items:int, days:int}
     */
    private static function resetAndAttach(array $task, array $payload, int $days): array
    {
        $taskId = (int)$task['id'];
        $now = date('Y-m-d H:i:s');

        // 每轮刷新执行计划（概率落点重新随机）
        $accountIds = array_values(array_filter(array_map('intval', $payload['accountIds'] ?? [])));
        if (!$accountIds && !empty($task['group_id'])) {
            $accountIds = array_map(
                'intval',
                array_column(
                    DB::select('SELECT id FROM accounts WHERE enabled=1 AND group_id=?', [(int)$task['group_id']]) ?: [],
                    'id'
                )
            );
        }
        $plansByAccount = [];
        if ($accountIds) {
            foreach ($accountIds as $aid) {
                $plansByAccount[(string)$aid] = WarmExecutionPlan::build($payload);
            }
            $payload['executionPlans'] = $plansByAccount;
            $payload['executionPlan'] = $plansByAccount[(string)$accountIds[0]]
                ?? WarmExecutionPlan::build($payload);
        } else {
            $payload['executionPlan'] = WarmExecutionPlan::build($payload);
            $payload['executionPlans'] = new \stdClass();
        }

        $payload['recurring'] = true;
        $payload['recurringDays'] = $days;
        $payload['recurringLastRunAt'] = $now;
        $payload['recurringCycle'] = (int)($payload['recurringCycle'] ?? 0) + 1;

        $n = DB::query(
            "UPDATE tasks
             SET status='queued',
                 success_count=0,
                 fail_count=0,
                 finished_at=NULL,
                 payload=?,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status IN ('completed','failed')",
            [json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $taskId]
        )->rowCount();
        if ($n < 1) {
            throw new \RuntimeException('任务状态已变化，无法重置');
        }

        DB::query(
            'INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)',
            [$taskId, 'info', "常态化养号：第 {$payload['recurringCycle']} 轮入队（间隔 {$days} 天）"]
        );

        // attachFromTask：有进行中总任务则挂入，否则新建
        $attached = AccountWorkBatchService::attachFromTask($taskId);

        return [
            'batches' => (int)($attached['batches'] ?? 0),
            'items' => (int)($attached['items'] ?? 0),
            'days' => $days,
            'cycle' => (int)$payload['recurringCycle'],
        ];
    }
}
