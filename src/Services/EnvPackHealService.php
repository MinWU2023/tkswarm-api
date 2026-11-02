<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 中心库环境包缺文件自愈：
 * - 扫描 DB 有绑定、但 storage/browser-env-packs 无实体文件的 tkswarm/linux 环境
 * - 条件：有关联用户、创建超过 10 分钟、尚未 queued/done
 * - 向关联用户推送 env_pack_upload 分任务：本机有包则静默上传；无包则重建后上传
 * - 补齐（或替换绑定）后标记 done，不再入队
 */
class EnvPackHealService
{
    public const TASK_TYPE = 'env_pack_upload';
    public const MIN_AGE_SECONDS = 600;

    /**
     * @return array{scanned:int,enqueued:int,skipped:int,healed_already:int,items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 30): array
    {
        AccountBrowserEnvService::ensureSchema();
        AccountWorkBatchService::ensureSchema();
        $limit = max(1, min(200, $limit));

        $rows = DB::select(
            "SELECT e.*,
                    a.owner_id AS account_owner_id,
                    a.username AS account_username,
                    COALESCE(NULLIF(e.owner_user_id, 0), NULLIF(a.owner_id, 0), 0) AS eff_owner_id
             FROM account_browser_envs e
             INNER JOIN accounts a ON a.id = e.account_id
             WHERE e.account_id > 0
               AND LOWER(TRIM(COALESCE(e.browser_backend, e.browser_type, ''))) IN ('tkswarm', 'linux')
               AND COALESCE(e.pack_heal_status, '') NOT IN ('done')
               AND e.created_at <= DATE_SUB(NOW(), INTERVAL " . (int)self::MIN_AGE_SECONDS . " SECOND)
             ORDER BY e.id ASC
             LIMIT " . (int)($limit * 4)
        ) ?: [];

        $scanned = 0;
        $enqueued = 0;
        $skipped = 0;
        $healedAlready = 0;
        $items = [];

        foreach ($rows as $row) {
            if ($enqueued >= $limit) {
                break;
            }
            $scanned++;
            $envId = (int)($row['id'] ?? 0);
            $profileId = trim((string)($row['browser_profile_id'] ?? ''));
            $accountId = (int)($row['account_id'] ?? 0);
            $ownerId = (int)($row['eff_owner_id'] ?? 0);
            $backend = AccountBrowserEnvService::normalizeBackend(
                (string)($row['browser_backend'] ?? $row['browser_type'] ?? 'tkswarm')
            );
            $healStatus = trim((string)($row['pack_heal_status'] ?? ''));

            if ($envId < 1 || $profileId === '' || $accountId < 1) {
                $skipped++;
                $items[] = ['id' => $envId, 'ok' => false, 'reason' => '无效环境行'];
                continue;
            }
            if ($ownerId < 1) {
                $skipped++;
                $items[] = ['id' => $envId, 'profileId' => $profileId, 'ok' => false, 'reason' => '无关联用户'];
                continue;
            }
            if ($backend === 'bit') {
                $skipped++;
                continue;
            }

            // 包文件已在：视为已补齐，标记 done 防再扫
            if (self::packFilePresent($row)) {
                self::markHealStatus($envId, 'done');
                $healedAlready++;
                $items[] = ['id' => $envId, 'profileId' => $profileId, 'ok' => true, 'reason' => '包已存在，标记 done'];
                continue;
            }

            if ($healStatus === 'queued') {
                // 仍有未完成分任务 → 跳过；无未完成则允许重推（失败后可恢复）
                if (self::hasOpenHealItem($accountId, $profileId, $envId)) {
                    $skipped++;
                    $items[] = ['id' => $envId, 'profileId' => $profileId, 'ok' => false, 'reason' => '已有待执行补齐任务'];
                    continue;
                }
            }

            // 桌面侧执行：linux 与 tkswarm 共用环境包，推送给关联用户时统一走 tkswarm
            $pushBackend = $backend === 'linux' ? 'tkswarm' : $backend;

            try {
                $payload = [
                    'profileId' => $profileId,
                    'envId' => $envId,
                    'browserBackend' => $pushBackend,
                    'browser_backend' => $pushBackend,
                    'source' => 'env_pack_heal',
                    'silent' => true,
                    'label' => (string)($row['label'] ?? ''),
                    'ownerUserId' => $ownerId,
                ];
                // 独立总任务：避免并进业务批次后被迫开窗登录
                $enqueuedRow = self::enqueueDedicatedHealItem(
                    $accountId,
                    $ownerId,
                    $envId,
                    $payload
                );
                self::markHealStatus($envId, 'queued');
                $enqueued++;
                $items[] = [
                    'id' => $envId,
                    'profileId' => $profileId,
                    'accountId' => $accountId,
                    'ownerId' => $ownerId,
                    'ok' => true,
                    'batch_id' => (int)($enqueuedRow['batch_id'] ?? 0),
                    'item_id' => (int)($enqueuedRow['item_id'] ?? 0),
                ];
            } catch (\Throwable $e) {
                $skipped++;
                $items[] = [
                    'id' => $envId,
                    'profileId' => $profileId,
                    'ok' => false,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'scanned' => $scanned,
            'enqueued' => $enqueued,
            'skipped' => $skipped,
            'healed_already' => $healedAlready,
            'items' => $items,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function packFilePresent(array $row): bool
    {
        $path = trim((string)($row['pack_path'] ?? ''));
        if ($path === '' || !is_file($path)) {
            return false;
        }
        return (int)($row['pack_revision'] ?? 0) > 0 || (int)(@filesize($path) ?: 0) > 64;
    }

    public static function markHealStatus(int $envId, string $status): void
    {
        if ($envId < 1) {
            return;
        }
        $status = trim($status);
        if (!in_array($status, ['', 'queued', 'done'], true)) {
            return;
        }
        try {
            DB::query(
                "UPDATE account_browser_envs
                 SET pack_heal_status=?, pack_heal_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$status, $envId]
            );
        } catch (\Throwable $e) {
        }
    }

    public static function markHealDoneByProfileId(string $profileId, string $reason = ''): void
    {
        $profileId = trim($profileId);
        if ($profileId === '') {
            return;
        }
        try {
            DB::query(
                "UPDATE account_browser_envs
                 SET pack_heal_status='done', pack_heal_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE browser_profile_id=?",
                [$profileId]
            );
        } catch (\Throwable $e) {
        }
        if ($reason !== '') {
            error_log('[env_pack_heal] done profile=' . $profileId . ' · ' . $reason);
        }
    }

    /**
     * 分任务结束回调：成功 → done；失败 → 清空 queued 以便下次 tick 重推。
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $result
     */
    public static function onWorkItemFinished(array $item, string $status, ?array $result = null): void
    {
        if (($item['task_type'] ?? '') !== self::TASK_TYPE) {
            return;
        }
        $payload = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
        if (is_array($result) && !empty($result['payload']) && is_array($result['payload'])) {
            $payload = array_merge($payload, $result['payload']);
        }
        $profileId = trim((string)($payload['profileId'] ?? $payload['profile_id'] ?? ''));
        $envId = (int)($payload['envId'] ?? $payload['env_id'] ?? $item['task_ref_id'] ?? 0);
        $previousProfileId = trim((string)($result['previousProfileId'] ?? $result['previous_profile_id'] ?? ''));

        if ($status === 'completed') {
            if ($envId > 0) {
                self::markHealStatus($envId, 'done');
            }
            if ($profileId !== '') {
                self::markHealDoneByProfileId($profileId, 'upload_ok');
            }
            // 代建替换：旧 ID 也标记 done，避免反复扫孤儿缺包行
            if ($previousProfileId !== '' && $previousProfileId !== $profileId) {
                self::markHealDoneByProfileId($previousProfileId, 'replaced_by_' . ($result['profileId'] ?? ''));
            }
            $newPid = trim((string)($result['profileId'] ?? ''));
            if ($newPid !== '' && $newPid !== $profileId) {
                self::markHealDoneByProfileId($newPid, 'provisioned');
            }
            return;
        }

        // failed / skipped：允许下次再入队
        if ($envId > 0) {
            self::markHealStatus($envId, '');
        }
    }

    /**
     * 新建独立 general 总任务（不与发布/养号等合并），便于 Node 走静默补齐路径。
     * @param array<string,mixed> $payload
     * @return array{batch_id:int,item_id:int}
     */
    private static function enqueueDedicatedHealItem(
        int $accountId,
        int $ownerId,
        int $envId,
        array $payload
    ): array {
        AccountWorkBatchService::ensureSchema();
        DB::query(
            "INSERT INTO account_work_batches (account_id, owner_id, batch_scope, status)
             VALUES (?,?, ?, 'pending')",
            [$accountId, $ownerId, AccountWorkBatchService::BATCH_SCOPE_GENERAL]
        );
        $batchId = (int)DB::getPdo()->lastInsertId();
        DB::query(
            "INSERT INTO account_work_items
             (batch_id, account_id, owner_id, task_type, task_ref_id, priority, seq, status, payload_json)
             VALUES (?,?,?,?,?,?,?, 'pending', ?)",
            [
                $batchId,
                $accountId,
                $ownerId,
                self::TASK_TYPE,
                $envId,
                AccountWorkBatchService::priorityFor(self::TASK_TYPE),
                1,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );
        $itemId = (int)DB::getPdo()->lastInsertId();
        DB::query(
            'UPDATE account_work_batches SET item_total=1, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$batchId]
        );
        return ['batch_id' => $batchId, 'item_id' => $itemId];
    }

    private static function hasOpenHealItem(int $accountId, string $profileId, int $envId): bool
    {
        $rows = DB::select(
            "SELECT id, payload_json, task_ref_id FROM account_work_items
             WHERE account_id=? AND task_type=? AND status IN ('pending','running')
             ORDER BY id DESC LIMIT 20",
            [$accountId, self::TASK_TYPE]
        ) ?: [];
        foreach ($rows as $row) {
            if ($envId > 0 && (int)($row['task_ref_id'] ?? 0) === $envId) {
                return true;
            }
            $p = json_decode((string)($row['payload_json'] ?? '{}'), true) ?: [];
            $pid = trim((string)($p['profileId'] ?? $p['profile_id'] ?? ''));
            if ($pid !== '' && $pid === $profileId) {
                return true;
            }
            if ($envId > 0 && (int)($p['envId'] ?? $p['env_id'] ?? 0) === $envId) {
                return true;
            }
        }
        return false;
    }
}
