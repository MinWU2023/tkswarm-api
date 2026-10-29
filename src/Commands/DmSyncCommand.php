<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Core\Database\DB;
use App\Services\UserSettingsService;

/**
 * 按消息设置入队私信同步任务（本机 Node task-runner 执行）
 * - messageSyncMode=api：合格 CK/指纹账号走 API；可按 dmSyncBitFallback 降级
 * - messageSyncMode=browser：有比特环境的账号走 Bit 爬收件箱
 * 用法: php artisan dm:sync
 */
class DmSyncCommand extends Command
{
    protected string $signature = 'dm:sync';
    protected string $description = '按设置间隔为合格账号入队 dm_sync 私信同步任务';

    public function handle(array $args = []): int
    {
        $this->ensureSchema();

        $dispatchOn = filter_var(
            UserSettingsService::setting('dmSyncDispatchEnabled', true),
            FILTER_VALIDATE_BOOLEAN
        );
        if (!$dispatchOn) {
            $this->warn('全局私信同步派发已关闭（settings.dmSyncDispatchEnabled=0），跳过入队');
            return 0;
        }

        $accounts = DB::select(
            "SELECT a.id, a.username, a.dm_channel, a.owner_id, a.fingerprint, a.browser_profile_id,
                    c.sync_messages, c.last_synced_at,
                    (SELECT cookie_encrypted FROM account_sessions s WHERE s.account_id=a.id LIMIT 1) AS cookie_encrypted
             FROM accounts a
             LEFT JOIN chat_connections c ON c.account_id=a.id
             WHERE a.enabled=1"
        );

        $queued = 0;
        $skipped = 0;
        /** @var array<int,array<string,mixed>> */
        $ownerSettingsCache = [];
        foreach ($accounts as $acc) {
            $ownerId = (int)($acc['owner_id'] ?? 0);
            if (!isset($ownerSettingsCache[$ownerId])) {
                $ownerSettingsCache[$ownerId] = UserSettingsService::getForUser($ownerId, false);
            }
            $settings = $ownerSettingsCache[$ownerId];
            $enabled = filter_var($settings['privateMessageSync'] ?? true, FILTER_VALIDATE_BOOLEAN);
            if (!$enabled) {
                $skipped++;
                continue;
            }
            $mode = strtolower((string)($settings['messageSyncMode'] ?? 'api'));
            if (!in_array($mode, ['api', 'browser'], true)) {
                $mode = 'api';
            }
            $bitFallback = filter_var($settings['dmSyncBitFallback'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $intervalMin = max(1, (int)($settings['messageSyncInterval'] ?? 10));
            if ($intervalMin > 120) {
                $intervalMin = (int)max(1, floor($intervalMin / 60));
            }

            $channel = (string)($acc['dm_channel'] ?? 'auto');
            $hasCk = trim((string)($acc['cookie_encrypted'] ?? '')) !== '';
            $hasFp = trim((string)($acc['fingerprint'] ?? '')) !== '';
            $hasBit = trim((string)($acc['browser_profile_id'] ?? '')) !== '';

            $via = 'api';
            $capable = false;
            if ($mode === 'browser' || $channel === 'browser') {
                $capable = $hasBit;
                $via = 'bit';
            } elseif ($channel === 'api') {
                $capable = $hasCk;
                $via = 'api';
            } else { // auto
                if ($mode === 'api') {
                    $capable = $hasCk && ($hasFp || $bitFallback);
                    $via = $hasCk ? 'api' : 'bit';
                    if (!$hasCk && $bitFallback && $hasBit) {
                        $capable = true;
                        $via = 'bit';
                    }
                }
            }

            if (!$capable) {
                $skipped++;
                continue;
            }
            if (isset($acc['sync_messages']) && (int)$acc['sync_messages'] === 0) {
                $skipped++;
                continue;
            }
            $last = $acc['last_synced_at'] ?? null;
            if ($last) {
                $elapsed = time() - strtotime((string)$last);
                if ($elapsed < $intervalMin * 60) {
                    $skipped++;
                    continue;
                }
            }
            if ($this->hasQueuedDmSync((int)$acc['id'])) {
                $skipped++;
                continue;
            }
            $payload = json_encode([
                'accountIds' => [(int)$acc['id']],
                'source' => 'dm:sync',
                'via' => $via,
                'preferBit' => $via === 'bit',
            ], JSON_UNESCAPED_UNICODE);
            $taskOwnerId = $ownerId > 0 ? $ownerId : 1;
            $name = '私信同步 #' . $acc['id'] . ' ' . ($acc['username'] ?? '') . ($via === 'bit' ? ' [bit]' : '');
            try {
                DB::query(
                    "INSERT INTO tasks(name, type, status, total_count, payload, owner_id, created_at, updated_at)
                     VALUES (?,?, 'queued', 1, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                    [$name, 'dm_sync', $payload, $ownerId]
                );
            } catch (\Throwable $e) {
                DB::query(
                    "INSERT INTO tasks(name, type, status, total_count, payload, created_at, updated_at)
                     VALUES (?,?, 'queued', 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                    [$name, 'dm_sync', $payload]
                );
            }
            $queued++;
            $this->info("已入队账号 #{$acc['id']} {$acc['username']} via={$via}");
        }

        $this->info("完成：入队 {$queued}，跳过 {$skipped}");
        return 0;
    }

    private function hasQueuedDmSync(int $accountId): bool
    {
        $needle = json_encode([$accountId]);
        try {
            $row = DB::selectOne(
                "SELECT id FROM tasks WHERE type='dm_sync' AND status IN ('queued','running')
                 AND JSON_CONTAINS(COALESCE(payload,'{}'), ?, '$.accountIds') LIMIT 1",
                [$needle]
            );
            if ($row) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        $rows = DB::select(
            "SELECT id, payload FROM tasks WHERE type='dm_sync' AND status IN ('queued','running') ORDER BY id DESC LIMIT 40"
        );
        foreach ($rows as $row) {
            $payload = json_decode((string)($row['payload'] ?? '{}'), true);
            $ids = $payload['accountIds'] ?? [];
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $aid) {
                if ((int)$aid === $accountId || (string)$aid === (string)$accountId) {
                    return true;
                }
            }
        }
        return false;
    }

    private function ensureSchema(): void
    {
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            if (!in_array('dm_channel', $cols, true)) {
                DB::query("ALTER TABLE accounts ADD COLUMN dm_channel VARCHAR(16) NOT NULL DEFAULT 'auto'");
            }
            if (!in_array('fingerprint', $cols, true)) {
                DB::query('ALTER TABLE accounts ADD COLUMN fingerprint TEXT NULL');
            }
        } catch (\Throwable $e) {
        }
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM chat_connections') ?: [], 'Field');
            if (!in_array('last_synced_at', $cols, true)) {
                DB::query('ALTER TABLE chat_connections ADD COLUMN last_synced_at DATETIME NULL');
            }
            if (!in_array('sync_messages', $cols, true)) {
                DB::query('ALTER TABLE chat_connections ADD COLUMN sync_messages TINYINT NOT NULL DEFAULT 1');
            }
        } catch (\Throwable $e) {
        }
        try {
            DB::query(
                "CREATE TABLE IF NOT EXISTS account_sessions (
                  account_id INT PRIMARY KEY,
                  cookie_encrypted MEDIUMTEXT NULL,
                  storage_encrypted MEDIUMTEXT NULL,
                  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
        }
    }
}
