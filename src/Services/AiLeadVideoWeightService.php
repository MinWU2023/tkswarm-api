<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * AI拓客账号视频权重：首次发视频激活天数 → 日私信额度；私信间隔设置。
 */
class AiLeadVideoWeightService
{
    /** 系统默认私信间隔（秒） */
    public const DEFAULT_INTERVAL_MIN_SEC = 180;
    public const DEFAULT_INTERVAL_MAX_SEC = 600;

    /** @var list<array{days:int,daily_dm_limit:int}> */
    public const DEFAULT_TIERS = [
        ['days' => 0, 'daily_dm_limit' => 1],
        ['days' => 3, 'daily_dm_limit' => 2],
        ['days' => 7, 'daily_dm_limit' => 3],
        ['days' => 14, 'daily_dm_limit' => 5],
        ['days' => 30, 'daily_dm_limit' => 8],
    ];

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $acols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            if (!in_array('first_video_published_at', $acols, true)) {
                DB::query(
                    "ALTER TABLE accounts ADD COLUMN first_video_published_at DATETIME NULL COMMENT '首次发视频成功时间'"
                );
            }
            DB::query("CREATE TABLE IF NOT EXISTS `account_video_weight_tiers` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `owner_id` INT NOT NULL DEFAULT 0,
              `days` INT NOT NULL DEFAULT 0,
              `daily_dm_limit` INT NOT NULL DEFAULT 1,
              `sort_order` INT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_avwt_owner_days` (`owner_id`, `days`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            DB::query("CREATE TABLE IF NOT EXISTS `account_dm_settings` (
              `account_id` INT NOT NULL PRIMARY KEY,
              `dm_interval_min_sec` INT NOT NULL DEFAULT 180,
              `dm_interval_max_sec` INT NOT NULL DEFAULT 600,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            AiLeadExecutionService::ensureSchema();
            $dcols = array_column(DB::select('SHOW COLUMNS FROM ai_lead_account_daily') ?: [], 'Field');
            if (!in_array('daily_limit', $dcols, true)) {
                DB::query(
                    "ALTER TABLE ai_lead_account_daily ADD COLUMN daily_limit INT NOT NULL DEFAULT 0 COMMENT '当日私信上限' AFTER times"
                );
            }
            self::seedDefaultTiersIfEmpty();
        } catch (\Throwable $e) {
            // ignore bootstrap races
        }
    }

    private static function seedDefaultTiersIfEmpty(): void
    {
        $cnt = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM account_video_weight_tiers WHERE owner_id=0')['c'] ?? 0);
        if ($cnt > 0) {
            return;
        }
        $order = 0;
        foreach (self::DEFAULT_TIERS as $tier) {
            $order++;
            DB::query(
                'INSERT INTO account_video_weight_tiers (owner_id, days, daily_dm_limit, sort_order) VALUES (0,?,?,?)',
                [(int)$tier['days'], (int)$tier['daily_dm_limit'], $order]
            );
        }
    }

    /** 记录账号首次发视频成功时间（仅写一次） */
    public static function recordFirstVideoPublished(int $accountId): void
    {
        self::ensureSchema();
        if ($accountId < 1) {
            return;
        }
        DB::query(
            'UPDATE accounts SET first_video_published_at=NOW()
             WHERE id=? AND first_video_published_at IS NULL',
            [$accountId]
        );
    }

    /** 激活天数：从首次发视频到当前；未发视频返回 null */
    public static function getActivationDays(int $accountId): ?int
    {
        self::ensureSchema();
        if ($accountId < 1) {
            return null;
        }
        $row = DB::selectOne(
            'SELECT first_video_published_at FROM accounts WHERE id=?',
            [$accountId]
        );
        $at = trim((string)($row['first_video_published_at'] ?? ''));
        if ($at === '') {
            return null;
        }
        try {
            $start = new \DateTimeImmutable(substr($at, 0, 10));
            $today = new \DateTimeImmutable('today');
            if ($start > $today) {
                return 0;
            }
            return $start->diff($today)->days;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 从已发布素材回填首次发视频时间（仅补空值）。
     * @return array{scanned:int,updated:int}
     */
    public static function backfillFirstVideoPublished(int $limit = 500): array
    {
        self::ensureSchema();
        $limit = max(1, min(5000, $limit));
        $rows = DB::select(
            "SELECT a.id AS account_id, MIN(m.updated_at) AS first_at
             FROM accounts a
             INNER JOIN materials m ON m.publish_account_id=a.id
               AND m.publish_status='published'
             WHERE a.first_video_published_at IS NULL
             GROUP BY a.id
             ORDER BY a.id ASC
             LIMIT {$limit}"
        ) ?: [];
        $updated = 0;
        foreach ($rows as $r) {
            $aid = (int)($r['account_id'] ?? 0);
            $at = trim((string)($r['first_at'] ?? ''));
            if ($aid < 1 || $at === '') {
                continue;
            }
            DB::query(
                'UPDATE accounts SET first_video_published_at=? WHERE id=? AND first_video_published_at IS NULL',
                [$at, $aid]
            );
            $updated++;
        }
        if ($updated > 0) {
            self::refreshAllDailyQuotas();
        }
        return ['scanned' => count($rows), 'updated' => $updated];
    }

    /** @return array{minSec:int,maxSec:int} */
    public static function getAccountInterval(int $accountId): array
    {
        self::ensureSchema();
        $row = DB::selectOne(
            'SELECT dm_interval_min_sec, dm_interval_max_sec FROM account_dm_settings WHERE account_id=?',
            [$accountId]
        );
        $min = max(30, (int)($row['dm_interval_min_sec'] ?? self::DEFAULT_INTERVAL_MIN_SEC));
        $max = max($min, (int)($row['dm_interval_max_sec'] ?? self::DEFAULT_INTERVAL_MAX_SEC));
        return ['minSec' => $min, 'maxSec' => $max];
    }

    /** @return array{minSec:int,maxSec:int} */
    public static function saveAccountInterval(int $accountId, int $minSec, int $maxSec): array
    {
        self::ensureSchema();
        if ($accountId < 1) {
            throw new \InvalidArgumentException('accountId 无效');
        }
        $minSec = max(30, $minSec);
        $maxSec = max($minSec, $maxSec);
        DB::query(
            'INSERT INTO account_dm_settings (account_id, dm_interval_min_sec, dm_interval_max_sec)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE dm_interval_min_sec=VALUES(dm_interval_min_sec),
                                   dm_interval_max_sec=VALUES(dm_interval_max_sec),
                                   updated_at=CURRENT_TIMESTAMP',
            [$accountId, $minSec, $maxSec]
        );
        return ['minSec' => $minSec, 'maxSec' => $maxSec];
    }

    /**
     * @return list<array{id:int,days:int,dailyDmLimit:int,sortOrder:int}>
     */
    public static function listTiers(int $ownerId = 0): array
    {
        self::ensureSchema();
        $ownerId = max(0, $ownerId);
        $rows = DB::select(
            'SELECT id, days, daily_dm_limit, sort_order FROM account_video_weight_tiers
             WHERE owner_id=? ORDER BY days ASC, sort_order ASC, id ASC',
            [$ownerId]
        ) ?: [];
        if (!$rows && $ownerId > 0) {
            $rows = DB::select(
                'SELECT id, days, daily_dm_limit, sort_order FROM account_video_weight_tiers
                 WHERE owner_id=0 ORDER BY days ASC, sort_order ASC, id ASC'
            ) ?: [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'days' => (int)($r['days'] ?? 0),
                'dailyDmLimit' => max(0, (int)($r['daily_dm_limit'] ?? 0)),
                'sortOrder' => (int)($r['sort_order'] ?? 0),
            ];
        }
        return $out;
    }

    public static function resolveDailyLimit(int $accountId, ?int $ownerId = null): int
    {
        self::ensureSchema();
        $days = self::getActivationDays($accountId);
        if ($days === null) {
            // 未激活：保守 1 条/天
            return 1;
        }
        if ($ownerId === null) {
            $acc = DB::selectOne('SELECT owner_id FROM accounts WHERE id=?', [$accountId]);
            $ownerId = (int)($acc['owner_id'] ?? 0);
        }
        $tiers = self::listTiers($ownerId);
        if (!$tiers) {
            $tiers = self::listTiers(0);
        }
        $limit = 1;
        foreach ($tiers as $tier) {
            if ($days >= (int)$tier['days']) {
                $limit = max($limit, (int)$tier['dailyDmLimit']);
            }
        }
        return max(1, $limit);
    }

    /**
     * 刷新账号当日额度记录（计划任务调用）。
     * @return array{accountId:int,dailyLimit:int,usedToday:int,date:string}
     */
    public static function refreshDailyQuota(int $accountId, ?string $date = null): array
    {
        self::ensureSchema();
        $date = $date ?: date('Y-m-d');
        $limit = self::resolveDailyLimit($accountId);
        $row = DB::selectOne(
            'SELECT id, times, daily_limit FROM ai_lead_account_daily WHERE account_id=? AND date=?',
            [$accountId, $date]
        );
        if ($row) {
            DB::query(
                'UPDATE ai_lead_account_daily SET daily_limit=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$limit, (int)$row['id']]
            );
            return [
                'accountId' => $accountId,
                'dailyLimit' => $limit,
                'usedToday' => (int)($row['times'] ?? 0),
                'date' => $date,
            ];
        }
        try {
            DB::query(
                'INSERT INTO ai_lead_account_daily (account_id, times, daily_limit, date) VALUES (?,?,0,?)',
                [$accountId, $limit, $date]
            );
        } catch (\Throwable $e) {
            DB::query(
                'UPDATE ai_lead_account_daily SET daily_limit=?, updated_at=CURRENT_TIMESTAMP
                 WHERE account_id=? AND date=?',
                [$limit, $accountId, $date]
            );
        }
        $fresh = DB::selectOne(
            'SELECT times FROM ai_lead_account_daily WHERE account_id=? AND date=?',
            [$accountId, $date]
        );
        return [
            'accountId' => $accountId,
            'dailyLimit' => $limit,
            'usedToday' => (int)($fresh['times'] ?? 0),
            'date' => $date,
        ];
    }

    public static function getDailyLimit(int $accountId, ?string $date = null): int
    {
        self::ensureSchema();
        $date = $date ?: date('Y-m-d');
        $row = DB::selectOne(
            'SELECT daily_limit FROM ai_lead_account_daily WHERE account_id=? AND date=?',
            [$accountId, $date]
        );
        $stored = (int)($row['daily_limit'] ?? 0);
        if ($stored > 0) {
            return $stored;
        }
        return self::resolveDailyLimit($accountId);
    }

    public static function getUsedToday(int $accountId, ?string $date = null): int
    {
        self::ensureSchema();
        $date = $date ?: date('Y-m-d');
        $row = DB::selectOne(
            'SELECT times FROM ai_lead_account_daily WHERE account_id=? AND date=?',
            [$accountId, $date]
        );
        return max(0, (int)($row['times'] ?? 0));
    }

    public static function getRemainingToday(int $accountId, ?string $date = null): int
    {
        $limit = self::getDailyLimit($accountId, $date);
        $used = self::getUsedToday($accountId, $date);
        return max(0, $limit - $used);
    }

    /** 最近一次成功私信时间 */
    public static function getLastSentAt(int $accountId): ?string
    {
        self::ensureSchema();
        $row = DB::selectOne(
            "SELECT updated_at FROM ai_lead_records
             WHERE account_id=? AND status='sent'
             ORDER BY updated_at DESC LIMIT 1",
            [$accountId]
        );
        $at = trim((string)($row['updated_at'] ?? ''));
        return $at !== '' ? $at : null;
    }

    /**
     * 账号是否满足私信条件（间隔 + 日次数）。
     * @return array{can:bool,reason:string,usedToday:int,dailyLimit:int,remaining:int,activationDays:?int,nextAvailableAt:?string,intervalMinSec:int,intervalMaxSec:int}
     */
    public static function canSendDmNow(int $accountId): array
    {
        self::ensureSchema();
        $interval = self::getAccountInterval($accountId);
        $dailyLimit = self::getDailyLimit($accountId);
        $usedToday = self::getUsedToday($accountId);
        $remaining = max(0, $dailyLimit - $usedToday);
        $activationDays = self::getActivationDays($accountId);

        if ($remaining < 1) {
            return [
                'can' => false,
                'reason' => '今日私信额度已满',
                'usedToday' => $usedToday,
                'dailyLimit' => $dailyLimit,
                'remaining' => 0,
                'activationDays' => $activationDays,
                'nextAvailableAt' => date('Y-m-d 00:00:00', strtotime('+1 day')),
                'intervalMinSec' => $interval['minSec'],
                'intervalMaxSec' => $interval['maxSec'],
            ];
        }

        $lastAt = self::getLastSentAt($accountId);
        if ($lastAt !== null) {
            $lastTs = strtotime($lastAt);
            if ($lastTs !== false) {
                $elapsed = time() - $lastTs;
                $required = self::requiredIntervalSec($accountId, $lastAt, $interval);
                if ($elapsed < $required) {
                    return [
                        'can' => false,
                        'reason' => '私信间隔未到',
                        'usedToday' => $usedToday,
                        'dailyLimit' => $dailyLimit,
                        'remaining' => $remaining,
                        'activationDays' => $activationDays,
                        'nextAvailableAt' => date('Y-m-d H:i:s', $lastTs + $required),
                        'intervalMinSec' => $interval['minSec'],
                        'intervalMaxSec' => $interval['maxSec'],
                    ];
                }
            }
        }

        return [
            'can' => true,
            'reason' => '',
            'usedToday' => $usedToday,
            'dailyLimit' => $dailyLimit,
            'remaining' => $remaining,
            'activationDays' => $activationDays,
            'nextAvailableAt' => null,
            'intervalMinSec' => $interval['minSec'],
            'intervalMaxSec' => $interval['maxSec'],
        ];
    }

    /**
     * 同一账号、同一上次发送时间 → 稳定随机间隔（min~max）。
     * @param array{minSec:int,maxSec:int} $interval
     */
    private static function requiredIntervalSec(int $accountId, string $lastAt, array $interval): int
    {
        $min = $interval['minSec'];
        $max = $interval['maxSec'];
        if ($max <= $min) {
            return $min;
        }
        $seed = crc32($accountId . '|' . $lastAt);
        $span = $max - $min + 1;
        return $min + ($seed % $span);
    }

    /**
     * 批量刷新所有有关联拓客/有私信记录的账号当日额度。
     * @return array{updated:int}
     */
    public static function refreshAllDailyQuotas(?string $date = null): array
    {
        self::ensureSchema();
        $date = $date ?: date('Y-m-d');
        $rows = DB::select(
            "SELECT DISTINCT account_id FROM (
               SELECT account_id FROM ai_lead_accounts
               UNION
               SELECT account_id FROM ai_lead_records WHERE account_id>0
               UNION
               SELECT id AS account_id FROM accounts WHERE first_video_published_at IS NOT NULL
             ) t WHERE account_id>0"
        ) ?: [];
        $updated = 0;
        foreach ($rows as $r) {
            $aid = (int)($r['account_id'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            self::refreshDailyQuota($aid, $date);
            $updated++;
        }
        return ['updated' => $updated, 'date' => $date];
    }

    /**
     * 阶梯阈值 → 区间展示（dayTo 空表示无上限）。
     * @param list<array{days?:int,dailyDmLimit?:int,daily_dm_limit?:int}> $tiers
     * @return list<array{dayFrom:int,dayTo:?int,dailyDmLimit:int}>
     */
    public static function tiersToRanges(array $tiers): array
    {
        $norm = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            $norm[] = [
                'days' => max(0, (int)($tier['days'] ?? 0)),
                'dailyDmLimit' => max(1, (int)($tier['dailyDmLimit'] ?? $tier['daily_dm_limit'] ?? 1)),
            ];
        }
        usort($norm, static fn($a, $b) => $a['days'] <=> $b['days']);
        $out = [];
        $n = count($norm);
        for ($i = 0; $i < $n; $i++) {
            $from = $norm[$i]['days'];
            $to = null;
            if ($i + 1 < $n) {
                $to = max($from, $norm[$i + 1]['days'] - 1);
            }
            $out[] = [
                'dayFrom' => $from,
                'dayTo' => $to,
                'dailyDmLimit' => $norm[$i]['dailyDmLimit'],
            ];
        }
        return $out;
    }

    /**
     * 区间表单 → 阶梯阈值（按 dayFrom 写入；校验不重叠）。
     * @param list<array{dayFrom?:int,day_from?:int,dayTo?:int|null,day_to?:int|null,dailyDmLimit?:int,daily_dm_limit?:int}> $ranges
     * @return list<array{days:int,dailyDmLimit:int}>
     */
    public static function rangesToTiers(array $ranges): array
    {
        $rows = [];
        foreach ($ranges as $r) {
            if (!is_array($r)) {
                continue;
            }
            $from = max(0, (int)($r['dayFrom'] ?? $r['day_from'] ?? 0));
            $toRaw = $r['dayTo'] ?? $r['day_to'] ?? null;
            $to = ($toRaw === '' || $toRaw === null) ? null : max(0, (int)$toRaw);
            $limit = max(1, (int)($r['dailyDmLimit'] ?? $r['daily_dm_limit'] ?? 1));
            if ($to !== null && $to < $from) {
                throw new \InvalidArgumentException("区间无效：起始天数 {$from} 大于结束天数 {$to}");
            }
            $rows[] = ['dayFrom' => $from, 'dayTo' => $to, 'dailyDmLimit' => $limit];
        }
        if ($rows === []) {
            throw new \InvalidArgumentException('请至少配置一条区间规则');
        }
        usort($rows, static fn($a, $b) => $a['dayFrom'] <=> $b['dayFrom']);
        $prevTo = null;
        $prevFrom = null;
        foreach ($rows as $row) {
            if ($prevFrom !== null && $row['dayFrom'] === $prevFrom) {
                throw new \InvalidArgumentException("起始天数重复：{$row['dayFrom']}");
            }
            if ($prevTo !== null && $row['dayFrom'] <= $prevTo) {
                throw new \InvalidArgumentException(
                    "区间重叠：{$row['dayFrom']} 与上一档结束日 {$prevTo} 冲突"
                );
            }
            if ($prevTo !== null && $row['dayFrom'] > $prevTo + 1) {
                // 允许空洞：空洞天数沿用上一档阈值逻辑（days>=prevFrom）
            }
            $prevFrom = $row['dayFrom'];
            $prevTo = $row['dayTo'];
        }
        $tiers = [];
        foreach ($rows as $row) {
            $tiers[] = [
                'days' => $row['dayFrom'],
                'dailyDmLimit' => $row['dailyDmLimit'],
            ];
        }
        return $tiers;
    }

    /**
     * 保存权重阶梯。owner_id=0 为系统默认（仅超管接口调用）；>0 为租户自定义。
     * 租户传空数组则清空自定义（回落到系统默认）；系统默认不允许清空。
     * @param list<array{days?:int,dailyDmLimit?:int,daily_dm_limit?:int}> $tiers
     * @return list<array{id:int,days:int,dailyDmLimit:int,sortOrder:int}>
     */
    public static function saveTiers(int $ownerId, array $tiers, bool $allowSystem = false): array
    {
        self::ensureSchema();
        $ownerId = max(0, $ownerId);
        if ($ownerId < 1 && !$allowSystem) {
            throw new \InvalidArgumentException('仅支持保存租户自定义权重');
        }
        $normalized = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            $normalized[] = [
                'days' => max(0, (int)($tier['days'] ?? 0)),
                'dailyDmLimit' => max(1, (int)($tier['dailyDmLimit'] ?? $tier['daily_dm_limit'] ?? 1)),
            ];
        }
        usort($normalized, static fn($a, $b) => $a['days'] <=> $b['days']);
        if ($ownerId < 1 && $normalized === []) {
            throw new \InvalidArgumentException('系统默认权重至少保留一条规则');
        }
        DB::query('DELETE FROM account_video_weight_tiers WHERE owner_id=?', [$ownerId]);
        $order = 0;
        $seenDays = [];
        foreach ($normalized as $tier) {
            $days = $tier['days'];
            if (isset($seenDays[$days])) {
                continue;
            }
            $seenDays[$days] = true;
            $order++;
            DB::query(
                'INSERT INTO account_video_weight_tiers (owner_id, days, daily_dm_limit, sort_order) VALUES (?,?,?,?)',
                [$ownerId, $days, $tier['dailyDmLimit'], $order]
            );
        }
        if ($ownerId < 1) {
            self::seedDefaultTiersIfEmpty();
        }
        self::refreshAllDailyQuotas();
        return self::listTiers($ownerId);
    }

    /**
     * 超管保存系统默认区间规则（owner_id=0）。
     * @param list<array{dayFrom?:int,dayTo?:int|null,dailyDmLimit?:int}> $ranges
     * @return array{tiers:list,ranges:list}
     */
    public static function saveSystemRanges(array $ranges): array
    {
        $tiers = self::rangesToTiers($ranges);
        $saved = self::saveTiers(0, $tiers, true);
        return [
            'tiers' => $saved,
            'ranges' => self::tiersToRanges($saved),
        ];
    }

    /** @return array<string,mixed> */
    public static function accountWeightSummary(int $accountId): array
    {
        self::ensureSchema();
        $activationDays = self::getActivationDays($accountId);
        $interval = self::getAccountInterval($accountId);
        $dailyLimit = self::getDailyLimit($accountId);
        $usedToday = self::getUsedToday($accountId);
        $check = self::canSendDmNow($accountId);
        $row = DB::selectOne(
            'SELECT first_video_published_at FROM accounts WHERE id=?',
            [$accountId]
        );
        return [
            'accountId' => $accountId,
            'firstVideoPublishedAt' => (string)($row['first_video_published_at'] ?? ''),
            'activationDays' => $activationDays,
            'dailyLimit' => $dailyLimit,
            'usedToday' => $usedToday,
            'remainingToday' => max(0, $dailyLimit - $usedToday),
            'canSendNow' => !empty($check['can']),
            'blockReason' => (string)($check['reason'] ?? ''),
            'nextAvailableAt' => $check['nextAvailableAt'] ?? null,
            'dmIntervalMinSec' => $interval['minSec'],
            'dmIntervalMaxSec' => $interval['maxSec'],
        ];
    }
}
