<?php
declare(strict_types=1);

namespace App\Services;

/**
 * AI拓客私信常态化推送：线索优先，推送时动态绑定可用账号。
 */
class AiLeadDmDispatchService
{
    /**
     * 紧急终止：关闭全部任务的私信派发开关，并取消队列中 pending/running 的 ai_lead_dm。
     * 不清空线索/拓客任务主数据；cron 下次 tick 不会再入队。
     *
     * @return array{leads_disabled:int,items_cancelled:int,batches_closed:int}
     */
    public static function emergencyStopAll(): array
    {
        AiLeadExecutionService::ensureSchema();
        AccountWorkBatchService::ensureSchema();

        $leadsDisabled = 0;
        try {
            $stmt = DB::query(
                'UPDATE ai_leads
                 SET dm_dispatch_enabled=0, updated_at=CURRENT_TIMESTAMP
                 WHERE COALESCE(dm_dispatch_enabled, 1)=1'
            );
            $leadsDisabled = (int)$stmt->rowCount();
        } catch (\Throwable $e) {
            // 列可能尚未迁移：仍继续取消队列
        }

        $items = DB::select(
            "SELECT id, batch_id FROM account_work_items
             WHERE task_type='ai_lead_dm' AND status IN ('pending','running')"
        ) ?: [];
        $itemsCancelled = 0;
        if ($items) {
            $stmt = DB::query(
                "UPDATE account_work_items
                 SET status='cancelled',
                     finished_at=CURRENT_TIMESTAMP,
                     updated_at=CURRENT_TIMESTAMP,
                     error_message='紧急终止：私信派发已关闭'
                 WHERE task_type='ai_lead_dm' AND status IN ('pending','running')"
            );
            $itemsCancelled = (int)$stmt->rowCount();
        }

        $batchIds = array_values(array_unique(array_filter(array_map(
            static fn($r) => (int)($r['batch_id'] ?? 0),
            $items
        ))));
        $batchesClosed = 0;
        foreach ($batchIds as $bid) {
            if ($bid < 1) {
                continue;
            }
            if (!AccountWorkBatchService::hasActiveItems($bid)) {
                DB::query(
                    "UPDATE account_work_batches
                     SET status=IF(item_fail>0 AND item_success<1,'failed','completed'),
                         locked_by='', lease_until=NULL, push_status='none',
                         finished_at=COALESCE(finished_at,CURRENT_TIMESTAMP),
                         updated_at=CURRENT_TIMESTAMP
                     WHERE id=? AND status IN ('pending','running','paused')",
                    [$bid]
                );
                $batchesClosed++;
            }
        }

        return [
            'leads_disabled' => $leadsDisabled,
            'items_cancelled' => $itemsCancelled,
            'batches_closed' => $batchesClosed,
        ];
    }

    /**
     * @return array{scanned:int,enqueued:int,skipped:int,failed:int,items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 10): array
    {
        AiLeadExecutionService::ensureSchema();
        AiLeadVideoWeightService::ensureSchema();
        AccountWorkBatchService::ensureSchema();

        // 单轮入队上限收紧，避免短时间内灌太多 pending
        $limit = max(1, min(30, $limit));
        $candidates = AiLeadExecutionService::listGlobalPendingDmTargets($limit * 3);

        $enqueued = 0;
        $skipped = 0;
        $failed = 0;
        $items = [];
        $usedAccountsThisTick = [];

        foreach ($candidates as $entry) {
            if ($enqueued >= $limit) {
                break;
            }
            $leadId = (int)($entry['leadId'] ?? 0);
            $target = is_array($entry['target'] ?? null) ? $entry['target'] : [];
            $username = trim((string)($target['username'] ?? ''));
            if ($leadId < 1 || $username === '') {
                $skipped++;
                continue;
            }
            if (AiLeadExecutionService::isUsernameDmQueued($username, $leadId)) {
                $skipped++;
                continue;
            }

            $picked = AiLeadExecutionService::pickBestAccountForTarget($leadId, $target, [
                'excludeAccountIds' => array_keys($usedAccountsThisTick),
            ]);
            if (!$picked) {
                $skipped++;
                continue;
            }

            $accountId = (int)($picked['accountId'] ?? 0);
            try {
                $out = AiLeadExecutionService::enqueueDmItem($leadId, $accountId, $target, [
                    'mode' => 'dispatch',
                    'dispatch' => true,
                ]);
                $usedAccountsThisTick[$accountId] = true;
                $enqueued++;
                $items[] = [
                    'ok' => true,
                    'accountId' => $accountId,
                    'leadId' => $leadId,
                    'username' => $username,
                    'batchId' => (int)($out['batch_id'] ?? 0),
                    'itemId' => (int)($out['item_id'] ?? 0),
                ];
            } catch (\Throwable $e) {
                $failed++;
                $items[] = [
                    'ok' => false,
                    'accountId' => $accountId,
                    'leadId' => $leadId,
                    'username' => $username,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'scanned' => count($candidates),
            'enqueued' => $enqueued,
            'skipped' => $skipped,
            'failed' => $failed,
            'items' => $items,
        ];
    }
}
