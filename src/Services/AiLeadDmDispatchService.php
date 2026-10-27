<?php
declare(strict_types=1);

namespace App\Services;

/**
 * AI拓客私信常态化推送：线索优先，推送时动态绑定可用账号。
 */
class AiLeadDmDispatchService
{
    /**
     * @return array{scanned:int,enqueued:int,skipped:int,failed:int,items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 30): array
    {
        AiLeadExecutionService::ensureSchema();
        AiLeadVideoWeightService::ensureSchema();
        AccountWorkBatchService::ensureSchema();

        $limit = max(1, min(100, $limit));
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
