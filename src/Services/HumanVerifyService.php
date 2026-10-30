<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 人工验证队列：节点检测到 TikTok 人机/滑块后入队，UI 通知并人工确认后恢复分任务。
 * 不自动过验证；仅暂停 + 通知 + 恢复。
 */
class HumanVerifyService
{
    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        DB::query(
            "CREATE TABLE IF NOT EXISTS human_verify_notices (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL DEFAULT 0,
              batch_id INT NOT NULL DEFAULT 0,
              item_id INT NOT NULL DEFAULT 0,
              task_type VARCHAR(64) NOT NULL DEFAULT '',
              reason VARCHAR(512) NOT NULL DEFAULT '',
              screenshot_path VARCHAR(512) NOT NULL DEFAULT '',
              status VARCHAR(32) NOT NULL DEFAULT 'pending',
              worker_id VARCHAR(128) NOT NULL DEFAULT '',
              meta_json MEDIUMTEXT NULL,
              resolved_by INT NOT NULL DEFAULT 0,
              resolved_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_hvn_status (status),
              KEY idx_hvn_item (item_id),
              KEY idx_hvn_batch (batch_id),
              KEY idx_hvn_account (account_id),
              KEY idx_hvn_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$schemaReady = true;
    }

    /**
     * Node 上报：暂停当前 running 分任务 + 总任务（不释放浏览器环境），写入通知。
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function reportFromNode(array $payload): array
    {
        self::ensureSchema();
        AccountWorkBatchService::ensureSchema();

        $accountId = (int)($payload['accountId'] ?? $payload['account_id'] ?? 0);
        $batchId = (int)($payload['batchId'] ?? $payload['batch_id'] ?? 0);
        $itemId = (int)($payload['itemId'] ?? $payload['item_id'] ?? 0);
        $taskType = trim((string)($payload['taskType'] ?? $payload['task_type'] ?? ''));
        $reason = trim((string)($payload['reason'] ?? $payload['errorMessage'] ?? $payload['error_message'] ?? '需要人工完成 TikTok 验证'));
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }
        $screenshot = trim((string)($payload['screenshot'] ?? $payload['screenshotPath'] ?? $payload['screenshot_path'] ?? ''));
        if (mb_strlen($screenshot) > 500) {
            // data:image 太长时不落库路径，仅记标记
            $screenshot = str_starts_with($screenshot, 'data:') ? '[inline-screenshot]' : mb_substr($screenshot, 0, 500);
        }
        $workerId = trim((string)($payload['workerId'] ?? $payload['worker_id'] ?? ''));
        $meta = $payload['meta'] ?? $payload['result'] ?? null;
        if (!is_array($meta)) {
            $meta = is_string($meta) && $meta !== '' ? ['raw' => $meta] : [];
        }
        $meta['status'] = (string)($payload['status'] ?? $meta['status'] ?? 'security_paused');

        if ($itemId > 0) {
            $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]);
            if ($item) {
                if ($batchId <= 0) {
                    $batchId = (int)($item['batch_id'] ?? 0);
                }
                if ($accountId <= 0) {
                    $accountId = (int)($item['account_id'] ?? 0);
                }
                if ($taskType === '') {
                    $taskType = (string)($item['task_type'] ?? '');
                }
            }
        }

        // 同一分任务若已有 pending 通知则复用（避免重复铃铛）
        $existing = null;
        if ($itemId > 0) {
            $existing = DB::selectOne(
                "SELECT * FROM human_verify_notices WHERE item_id=? AND status='pending' ORDER BY id DESC LIMIT 1",
                [$itemId]
            );
        }

        $resultJson = json_encode(
            array_merge($meta, ['human_verify' => true, 'reason' => $reason]),
            JSON_UNESCAPED_UNICODE
        );

        if ($itemId > 0) {
            // running / pending → paused；不写 finished_at，便于恢复后重跑
            DB::query(
                "UPDATE account_work_items
                 SET status='paused',
                     error_message=?,
                     result_json=?,
                     finished_at=NULL,
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status IN ('running','pending','paused')",
                [$reason !== '' ? $reason : '需要人工验证', $resultJson, $itemId]
            );
        }

        if ($batchId > 0) {
            // 暂停总任务并释放领取锁，但绝不 releaseEnv（窗口留给人工拖滑块）
            DB::query(
                "UPDATE account_work_batches
                 SET status='paused', locked_by='', lease_until=NULL, updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status IN ('pending','running','paused')",
                [$batchId]
            );
            // 同批未开始的子任务一并暂停，避免被其他节点领走
            DB::query(
                "UPDATE account_work_items
                 SET status='paused', updated_at=CURRENT_TIMESTAMP
                 WHERE batch_id=? AND status='pending'",
                [$batchId]
            );
            try {
                AccountWorkBatchService::appendLog(
                    $batchId,
                    $itemId,
                    '检测到 TikTok 人机/安全验证，已暂停等待人工处理：' . ($reason !== '' ? $reason : '请在浏览器完成验证'),
                    'warn',
                    $accountId,
                    0,
                    ['event' => 'human_verify', 'task_type' => $taskType]
                );
            } catch (\Throwable $e) {
                // ignore log failures
            }
        }

        if ($existing) {
            DB::query(
                "UPDATE human_verify_notices
                 SET account_id=?, batch_id=?, task_type=?, reason=?, screenshot_path=?, worker_id=?, meta_json=?, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [
                    $accountId,
                    $batchId,
                    $taskType,
                    $reason,
                    $screenshot,
                    $workerId,
                    json_encode($meta, JSON_UNESCAPED_UNICODE),
                    (int)$existing['id'],
                ]
            );
            $id = (int)$existing['id'];
        } else {
            DB::query(
                "INSERT INTO human_verify_notices
                 (account_id, batch_id, item_id, task_type, reason, screenshot_path, status, worker_id, meta_json)
                 VALUES (?,?,?,?,?,?,?,?,?)",
                [
                    $accountId,
                    $batchId,
                    $itemId,
                    $taskType,
                    $reason,
                    $screenshot,
                    'pending',
                    $workerId,
                    json_encode($meta, JSON_UNESCAPED_UNICODE),
                ]
            );
            $id = (int)DB::getPdo()->lastInsertId();
        }

        return self::format(DB::selectOne('SELECT * FROM human_verify_notices WHERE id=?', [$id]) ?: [
            'id' => $id,
            'account_id' => $accountId,
            'batch_id' => $batchId,
            'item_id' => $itemId,
            'task_type' => $taskType,
            'reason' => $reason,
            'status' => 'pending',
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function listPending(int $limit = 50): array
    {
        self::ensureSchema();
        $limit = max(1, min(100, $limit));
        $rows = DB::select(
            "SELECT n.*, a.username AS account_username, a.nickname AS account_nickname
             FROM human_verify_notices n
             LEFT JOIN accounts a ON a.id = n.account_id
             WHERE n.status='pending'
             ORDER BY n.id DESC
             LIMIT {$limit}"
        );
        return array_map([self::class, 'format'], $rows ?: []);
    }

    public static function countPending(): int
    {
        self::ensureSchema();
        $row = DB::selectOne("SELECT COUNT(*) AS c FROM human_verify_notices WHERE status='pending'");
        return (int)($row['c'] ?? 0);
    }

    /**
     * 人工确认已完成验证 → 恢复分任务与总任务。
     *
     * @return array<string,mixed>
     */
    public static function resolve(int $id, int $userId = 0, string $action = 'resume'): array
    {
        self::ensureSchema();
        $notice = DB::selectOne('SELECT * FROM human_verify_notices WHERE id=?', [$id]);
        if (!$notice) {
            throw new \RuntimeException('通知不存在');
        }
        if (($notice['status'] ?? '') !== 'pending') {
            return self::format($notice);
        }

        $batchId = (int)($notice['batch_id'] ?? 0);
        $itemId = (int)($notice['item_id'] ?? 0);
        $newStatus = $action === 'dismiss' ? 'dismissed' : 'resolved';

        DB::query(
            "UPDATE human_verify_notices
             SET status=?, resolved_by=?, resolved_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='pending'",
            [$newStatus, $userId, $id]
        );

        if ($action !== 'dismiss' && $batchId > 0) {
            $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
            if ($batch && ($batch['status'] ?? '') === 'paused') {
                AccountWorkBatchService::resumeBatch($batchId);
            } elseif ($itemId > 0) {
                $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]);
                if ($item && ($item['status'] ?? '') === 'paused') {
                    AccountWorkBatchService::resumeItem($itemId);
                }
            }
            try {
                AccountWorkBatchService::appendLog(
                    $batchId,
                    $itemId,
                    '人工确认已完成验证，已恢复任务继续执行',
                    'info',
                    (int)($notice['account_id'] ?? 0),
                    0,
                    ['event' => 'human_verify_resolved', 'notice_id' => $id]
                );
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return self::format(DB::selectOne('SELECT * FROM human_verify_notices WHERE id=?', [$id]) ?: $notice);
    }

    /** @param array<string,mixed> $row */
    public static function format(array $row): array
    {
        $meta = [];
        if (!empty($row['meta_json'])) {
            $decoded = json_decode((string)$row['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'accountId' => (int)($row['account_id'] ?? 0),
            'accountUsername' => (string)($row['account_username'] ?? ''),
            'accountNickname' => (string)($row['account_nickname'] ?? ''),
            'batchId' => (int)($row['batch_id'] ?? 0),
            'itemId' => (int)($row['item_id'] ?? 0),
            'taskType' => (string)($row['task_type'] ?? ''),
            'reason' => (string)($row['reason'] ?? ''),
            'screenshotPath' => (string)($row['screenshot_path'] ?? ''),
            'status' => (string)($row['status'] ?? 'pending'),
            'workerId' => (string)($row['worker_id'] ?? ''),
            'meta' => $meta,
            'resolvedBy' => (int)($row['resolved_by'] ?? 0),
            'resolvedAt' => $row['resolved_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }
}
