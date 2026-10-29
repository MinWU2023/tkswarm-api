<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 常态化自动养号：扫描已结束的养号任务，按间隔重新入队并挂到账号会话总任务。
 *
 * 入队约束（避免频繁推送 / 挤占高优先级任务）：
 * 1) 同一 task 仍有 pending/running 分项 → 不重复入队
 * 2) 任一账号的常规总任务里已有更高权重（priority 更小）待办 → 本轮不重置、不挂载
 * 3) 挂入后与发布等任务同处一个待定列表，由 claim-item 按 priority 排队，后插的高优先级会插到养号前面
 */
class WarmRecurringService
{
    /**
     * @return array{scanned:int, reset:int, skipped:int, items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 50): array
    {
        AccountWorkBatchService::ensureSchema();
        self::ensureRecurringDispatchColumn();
        $limit = max(1, min(200, $limit));

        $tasks = DB::select(
            "SELECT * FROM tasks
             WHERE type='warm' AND status IN ('completed','failed')
               AND COALESCE(recurring_dispatch_enabled, 1)=1
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
            if (array_key_exists('recurring_dispatch_enabled', $task) && (int)$task['recurring_dispatch_enabled'] === 0) {
                $skipped++;
                $items[] = ['id' => $id, 'ok' => false, 'reason' => '常态化自动化派发已关闭'];
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

            // 已挂过分项但未执行完 → 绝不反复推送
            if (AccountWorkBatchService::taskHasOpenWorkItems($id)) {
                $skipped++;
                $items[] = ['id' => $id, 'ok' => false, 'reason' => '仍有未执行的养号分项，跳过重复推送'];
                continue;
            }

            $accountIds = self::resolveAccountIds($task, $payload);
            $gate = self::assertAccountsReadyForRecurringWarm($accountIds, $id);
            if (!$gate['ok']) {
                $skipped++;
                $items[] = ['id' => $id, 'ok' => false, 'reason' => $gate['reason']];
                continue;
            }

            try {
                $result = self::resetAndAttach($task, $payload, $days, $accountIds);
                if ((int)($result['items'] ?? 0) < 1) {
                    // 未挂上分项：恢复原结束状态，且不推进 recurringLastRunAt（避免空跑占间隔）
                    self::revertFailedReset($task, $payload, (string)($result['revert_reason'] ?? '未挂载分项'));
                    $skipped++;
                    $items[] = [
                        'id' => $id,
                        'ok' => false,
                        'reason' => (string)($result['revert_reason'] ?? '重置后未挂载任何分项'),
                    ];
                    continue;
                }
                $reset++;
                $items[] = array_merge(['id' => $id, 'ok' => true], $result);
            } catch (\Throwable $e) {
                $skipped++;
                $items[] = ['id' => $id, 'ok' => false, 'reason' => $e->getMessage()];
            }
        }

        // 顺带：已 queued、因更高优先级暂缓挂载的养号，到点可再试挂入（不改 status / lastRun）
        self::retryAttachDeferredQueuedWarms($limit);

        return compact('scanned', 'reset', 'skipped', 'items');
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private static function resolveAccountIds(array $task, array $payload): array
    {
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
        return array_values(array_unique(array_filter($accountIds)));
    }

    /**
     * @param list<int> $accountIds
     * @return array{ok:bool, reason?:string}
     */
    private static function assertAccountsReadyForRecurringWarm(array $accountIds, int $taskId): array
    {
        if (!$accountIds) {
            return ['ok' => true];
        }
        $warmPri = AccountWorkBatchService::priorityFor('warm');
        foreach ($accountIds as $aid) {
            if (AccountWorkBatchService::accountHasPendingTaskType($aid, 'warm', $taskId)) {
                return ['ok' => false, 'reason' => "账号 #{$aid} 已有未执行的本任务养号分项"];
            }
            if (AccountWorkBatchService::accountHasPendingTaskType($aid, 'warm', 0)) {
                // 同账号任意未执行养号（含其它任务挂的）也不再叠一轮常态化
                return ['ok' => false, 'reason' => "账号 #{$aid} 已有未执行的养号分项"];
            }
            if (AccountWorkBatchService::accountHasHigherPriorityPending($aid, $warmPri)) {
                return ['ok' => false, 'reason' => "账号 #{$aid} 存在更高优先级待办，养号应排队等待"];
            }
        }
        return ['ok' => true];
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $payload
     * @param list<int> $accountIds
     * @return array{batches:int, items:int, days:int, cycle:int, deferred_higher?:int}
     */
    private static function resetAndAttach(array $task, array $payload, int $days, array $accountIds): array
    {
        $taskId = (int)$task['id'];
        $now = date('Y-m-d H:i:s');

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

        $nextCycle = (int)($payload['recurringCycle'] ?? 0) + 1;
        $payload['recurring'] = true;
        $payload['recurringDays'] = $days;
        // 先写入排队态；仅当成功挂上分项后才固化 lastRunAt / cycle（见下方二次 UPDATE）
        $payloadForQueue = $payload;
        $payloadForQueue['recurringCycle'] = $nextCycle;
        $payloadForQueue['recurringLastRunAt'] = $now;

        $n = DB::query(
            "UPDATE tasks
             SET status='queued',
                 success_count=0,
                 fail_count=0,
                 finished_at=NULL,
                 payload=?,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status IN ('completed','failed')",
            [json_encode($payloadForQueue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $taskId]
        )->rowCount();
        if ($n < 1) {
            throw new \RuntimeException('任务状态已变化，无法重置');
        }

        $attached = AccountWorkBatchService::attachFromTask($taskId);
        $itemCount = (int)($attached['items'] ?? 0);
        if ($itemCount < 1) {
            $reason = (int)($attached['deferred_higher'] ?? 0) > 0
                ? '账号存在更高优先级待办，养号暂缓入列'
                : '无可挂载账号或分项已存在';
            return [
                'batches' => 0,
                'items' => 0,
                'deferred_higher' => (int)($attached['deferred_higher'] ?? 0),
                'days' => $days,
                'cycle' => (int)($payload['recurringCycle'] ?? 0),
                'revert_reason' => $reason,
            ];
        }

        DB::query(
            'UPDATE tasks SET payload=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [json_encode($payloadForQueue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $taskId]
        );
        DB::query(
            'INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)',
            [$taskId, 'info', "常态化养号：第 {$nextCycle} 轮入队（间隔 {$days} 天，挂载 {$itemCount} 个分项）"]
        );

        return [
            'batches' => (int)($attached['batches'] ?? 0),
            'items' => $itemCount,
            'deferred_higher' => (int)($attached['deferred_higher'] ?? 0),
            'days' => $days,
            'cycle' => $nextCycle,
        ];
    }

    /**
     * @param array<string,mixed> $task 重置前的行
     * @param array<string,mixed> $payload 重置前的 payload
     */
    private static function revertFailedReset(array $task, array $payload, string $reason): void
    {
        $taskId = (int)$task['id'];
        $prevStatus = in_array(($task['status'] ?? ''), ['completed', 'failed'], true)
            ? (string)$task['status']
            : 'completed';
        DB::query(
            "UPDATE tasks
             SET status=?,
                 finished_at=COALESCE(?, CURRENT_TIMESTAMP),
                 payload=?,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='queued'",
            [
                $prevStatus,
                $task['finished_at'] ?? null,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $taskId,
            ]
        );
        DB::query(
            'INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)',
            [$taskId, 'info', '常态化入队暂缓：' . mb_substr($reason, 0, 200)]
        );
    }

    /** 已 queued 但暂未挂上分项的养号（曾因更高优先级 defer）→ 再试挂载 */
    private static function retryAttachDeferredQueuedWarms(int $limit): void
    {
        $rows = DB::select(
            "SELECT id FROM tasks
             WHERE type='warm' AND status='queued'
               AND COALESCE(recurring_dispatch_enabled, 1)=1
               AND NOT EXISTS (
                 SELECT 1 FROM account_work_items i
                 WHERE i.task_ref_id=tasks.id AND i.status IN ('pending','running')
               )
             ORDER BY id ASC
             LIMIT {$limit}"
        ) ?: [];
        foreach ($rows as $row) {
            $tid = (int)($row['id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            try {
                AccountWorkBatchService::attachFromTask($tid, true);
            } catch (\Throwable $e) {
                // ignore single failure
            }
        }
    }

    private static function ensureRecurringDispatchColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM tasks') ?: [], 'Field');
            if (!in_array('recurring_dispatch_enabled', $cols, true)) {
                DB::query(
                    "ALTER TABLE tasks ADD COLUMN recurring_dispatch_enabled TINYINT(1) NOT NULL DEFAULT 1
                     COMMENT '养号常态化派发开关'"
                );
            }
        } catch (\Throwable $e) {
        }
    }
}
