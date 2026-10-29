<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * AI拓客执行编排：选账号、生成 payload、入库回调
 */
class AiLeadExecutionService
{
    /** @deprecated 请使用 AiLeadVideoWeightService::resolveDailyLimit */
    public const DAILY_DM_LIMIT = 3;

    /** 归一化浏览器后端：保留 linux（服务器浏览器，环境由 node 自动创建） */
    private static function normalizeLeadBackend($raw): string
    {
        $be = strtolower(trim((string)$raw));
        return in_array($be, ['bit', 'tkswarm', 'linux'], true) ? $be : 'bit';
    }
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM ai_leads') ?: [], 'Field');
            if (!in_array('run_status', $cols, true)) {
                DB::query("ALTER TABLE ai_leads ADD COLUMN run_status VARCHAR(32) NOT NULL DEFAULT 'idle' AFTER status");
            }
            if (!in_array('last_run_at', $cols, true)) {
                DB::query('ALTER TABLE ai_leads ADD COLUMN last_run_at DATETIME NULL AFTER run_status');
            }
            if (!in_array('stats_json', $cols, true)) {
                DB::query('ALTER TABLE ai_leads ADD COLUMN stats_json MEDIUMTEXT NULL AFTER last_run_at');
            }
            if (!in_array('progress_json', $cols, true)) {
                DB::query('ALTER TABLE ai_leads ADD COLUMN progress_json MEDIUMTEXT NULL AFTER stats_json');
            }
            if (!in_array('dm_message', $cols, true)) {
                DB::query("ALTER TABLE ai_leads ADD COLUMN dm_message TEXT NULL AFTER keywords");
            }
            // 私信常态派发开关：与任务 status 独立，关闭后 cron 不再入队 ai_lead_dm
            if (!in_array('dm_dispatch_enabled', $cols, true)) {
                DB::query(
                    "ALTER TABLE ai_leads ADD COLUMN dm_dispatch_enabled TINYINT(1) NOT NULL DEFAULT 1
                     COMMENT '是否允许私信派发' AFTER dm_message"
                );
            }
            $ccols = array_column(DB::select('SHOW COLUMNS FROM ai_lead_creators') ?: [], 'Field');
            if (!in_array('profile_url', $ccols, true)) {
                DB::query("ALTER TABLE ai_lead_creators ADD COLUMN profile_url VARCHAR(512) NOT NULL DEFAULT '' AFTER avatar");
            }
            if (!in_array('intent_score', $ccols, true)) {
                DB::query("ALTER TABLE ai_lead_creators ADD COLUMN intent_score TINYINT NOT NULL DEFAULT 0 COMMENT '意向程度1-10' AFTER status");
            }
            try {
                $cidx = array_column(DB::select('SHOW INDEX FROM ai_lead_creators') ?: [], 'Key_name');
                if (!in_array('idx_alc_creator_intent', $cidx, true)) {
                    DB::query('ALTER TABLE ai_lead_creators ADD KEY idx_alc_creator_intent (lead_id, intent_score)');
                }
            } catch (\Throwable $e) {
            }
            try {
                DB::query(
                    'DELETE c FROM ai_lead_creators c
                     WHERE c.followers=0 AND c.likes=0 AND c.account_id=0
                       AND NOT EXISTS (SELECT 1 FROM ai_lead_videos v WHERE v.creator_id=c.id)
                       AND EXISTS (
                         SELECT 1 FROM ai_lead_comments cm
                         WHERE cm.lead_id=c.lead_id AND cm.ai_pass=1
                           AND (LOWER(cm.username)=LOWER(c.username) OR (cm.uuid<>"" AND cm.uuid=c.uuid))
                       )'
                );
            } catch (\Throwable $e) {
            }
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_videos` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `lead_id` INT NOT NULL,
              `creator_id` INT NOT NULL DEFAULT 0,
              `account_id` INT NOT NULL DEFAULT 0,
              `video_url` VARCHAR(512) NOT NULL DEFAULT '',
              `video_cover` VARCHAR(512) NOT NULL DEFAULT '',
              `video_name` VARCHAR(255) NOT NULL DEFAULT '',
              `likes` INT NOT NULL DEFAULT 0,
              `views` INT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_alv_lead` (`lead_id`),
              KEY `idx_alv_creator` (`creator_id`),
              UNIQUE KEY `uk_alv_lead_url` (`lead_id`, `video_url`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try {
                $vcols = array_column(DB::select('SHOW COLUMNS FROM ai_lead_videos') ?: [], 'Field');
                if (!in_array('views', $vcols, true)) {
                    DB::query('ALTER TABLE ai_lead_videos ADD COLUMN views INT NOT NULL DEFAULT 0 AFTER likes');
                }
                // 旧数据曾把播放量写进 likes：迁到 views，点赞留给详情页回填
                DB::query('UPDATE ai_lead_videos SET views = likes, likes = 0 WHERE views = 0 AND likes > 0');
            } catch (\Throwable $e) {
            }
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_comments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `lead_id` INT NOT NULL,
              `video_id` INT NOT NULL DEFAULT 0,
              `uuid` VARCHAR(64) NOT NULL DEFAULT '',
              `username` VARCHAR(128) NOT NULL DEFAULT '',
              `nickname` VARCHAR(255) NOT NULL DEFAULT '',
              `profile_url` VARCHAR(512) NOT NULL DEFAULT '',
              `comment_text` TEXT NULL,
              `comment_text_zh` TEXT NULL COMMENT '评论中文译文',
              `ai_pass` TINYINT(1) NOT NULL DEFAULT 0,
              `ai_reason` VARCHAR(512) NOT NULL DEFAULT '',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_alc_lead` (`lead_id`),
              KEY `idx_alc_video` (`video_id`),
              KEY `idx_alc_pass` (`lead_id`, `ai_pass`),
              UNIQUE KEY `uk_alcmt_lead_uuid` (`lead_id`, `uuid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // 旧表补唯一键 / updated_at（已存在则忽略）
            try {
                $cmCols = array_column(DB::select('SHOW COLUMNS FROM ai_lead_comments') ?: [], 'Field');
                if (!in_array('updated_at', $cmCols, true)) {
                    DB::query('ALTER TABLE ai_lead_comments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
                }
                if (!in_array('comment_text_zh', $cmCols, true)) {
                    DB::query("ALTER TABLE ai_lead_comments ADD COLUMN comment_text_zh TEXT NULL COMMENT '评论中文译文' AFTER comment_text");
                }
                if (!in_array('intent_score', $cmCols, true)) {
                    DB::query("ALTER TABLE ai_lead_comments ADD COLUMN intent_score TINYINT NOT NULL DEFAULT 0 COMMENT '意向程度1-10' AFTER ai_reason");
                }
                if (!in_array('industry_id', $cmCols, true)) {
                    DB::query('ALTER TABLE ai_lead_comments ADD COLUMN industry_id INT NOT NULL DEFAULT 0 AFTER intent_score');
                    DB::query('ALTER TABLE ai_lead_comments ADD KEY idx_alcmt_industry (industry_id)');
                }
                $idx = array_column(DB::select('SHOW INDEX FROM ai_lead_comments') ?: [], 'Key_name');
                if (!in_array('uk_alcmt_lead_uuid', $idx, true)) {
                    DB::query('ALTER TABLE ai_lead_comments ADD UNIQUE KEY uk_alcmt_lead_uuid (lead_id, uuid)');
                }
                if (!in_array('idx_alc_intent', $idx, true)) {
                    DB::query('ALTER TABLE ai_lead_comments ADD KEY idx_alc_intent (lead_id, ai_pass, intent_score)');
                }
            } catch (\Throwable $e) {
            }
            try {
                $rcols = array_column(DB::select('SHOW COLUMNS FROM ai_lead_records') ?: [], 'Field');
                if (!in_array('lead_id', $rcols, true)) {
                    DB::query('ALTER TABLE ai_lead_records ADD COLUMN lead_id INT NOT NULL DEFAULT 0 AFTER id');
                    DB::query('ALTER TABLE ai_lead_records ADD KEY idx_alr_lead (lead_id)');
                }
                if (!in_array('error_message', $rcols, true)) {
                    DB::query("ALTER TABLE ai_lead_records ADD COLUMN error_message VARCHAR(512) NOT NULL DEFAULT '' AFTER message");
                }
            } catch (\Throwable $e) {
            }
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_account_daily` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `account_id` INT NOT NULL,
              `times` INT NOT NULL DEFAULT 0,
              `date` DATE NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_alad_account_date` (`account_id`, `date`),
              KEY `idx_alad_date` (`date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try {
                $ridx = array_column(DB::select('SHOW INDEX FROM ai_lead_records') ?: [], 'Key_name');
                if (!in_array('idx_alr_account_status_time', $ridx, true)) {
                    DB::query('ALTER TABLE ai_lead_records ADD KEY idx_alr_account_status_time (account_id, status, updated_at)');
                }
                if (!in_array('idx_alr_status_username', $ridx, true)) {
                    DB::query('ALTER TABLE ai_lead_records ADD KEY idx_alr_status_username (status, username(64))');
                }
            } catch (\Throwable $e) {
            }
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_shared_users` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `lead_id` INT NOT NULL,
              `user_id` INT NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_alsu_lead_user` (`lead_id`, `user_id`),
              KEY `idx_alsu_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_comment_shared_users` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `comment_id` INT NOT NULL,
              `user_id` INT NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_alcsu_comment_user` (`comment_id`, `user_id`),
              KEY `idx_alcsu_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try {
                DB::query(
                    'UPDATE ai_lead_comments cm
                     INNER JOIN ai_leads l ON l.id=cm.lead_id
                     SET cm.industry_id=l.industry_id
                     WHERE cm.industry_id=0 AND l.industry_id>0'
                );
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
            // ignore bootstrap races
        }
    }

    public static function leadIndustryId(int $leadId): int
    {
        if ($leadId <= 0) {
            return 0;
        }
        $row = DB::selectOne('SELECT industry_id FROM ai_leads WHERE id=?', [$leadId]);
        return (int)($row['industry_id'] ?? 0);
    }

    /** @param list<int> $userIds */
    public static function syncLeadSharedUsers(int $leadId, array $userIds): void
    {
        self::ensureSchema();
        DB::query('DELETE FROM ai_lead_shared_users WHERE lead_id=?', [$leadId]);
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            DB::query(
                'INSERT IGNORE INTO ai_lead_shared_users (lead_id, user_id) VALUES (?,?)',
                [$leadId, $uid]
            );
        }
        self::syncLeadSharedUsersToComments($leadId);
    }

    public static function syncLeadSharedUsersToComments(int $leadId): void
    {
        self::ensureSchema();
        $users = DB::select('SELECT user_id FROM ai_lead_shared_users WHERE lead_id=?', [$leadId]) ?: [];
        if (!$users) {
            return;
        }
        $comments = DB::select(
            'SELECT id FROM ai_lead_comments WHERE lead_id=? AND ai_pass=1',
            [$leadId]
        ) ?: [];
        foreach ($comments as $cm) {
            self::syncCommentSharedUsers((int)$cm['id'], $leadId);
        }
    }

    public static function syncCommentSharedUsers(int $commentId, int $leadId): void
    {
        self::ensureSchema();
        if ($commentId <= 0 || $leadId <= 0) {
            return;
        }
        $users = DB::select('SELECT user_id FROM ai_lead_shared_users WHERE lead_id=?', [$leadId]) ?: [];
        foreach ($users as $u) {
            $uid = (int)($u['user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            DB::query(
                'INSERT IGNORE INTO ai_lead_comment_shared_users (comment_id, user_id) VALUES (?,?)',
                [$commentId, $uid]
            );
        }
    }

    /** @param list<int> $commentIds @param list<int> $userIds @return array{comments:int,users:int,links:int,created:int} */
    public static function shareCommentsToUsers(array $commentIds, array $userIds): array
    {
        self::ensureSchema();
        $commentIds = array_values(array_unique(array_filter(array_map('intval', $commentIds))));
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $links = 0;
        $created = 0;
        foreach ($commentIds as $cid) {
            if ($cid <= 0) {
                continue;
            }
            $cm = DB::selectOne('SELECT id FROM ai_lead_comments WHERE id=? AND ai_pass=1', [$cid]);
            if (!$cm) {
                continue;
            }
            foreach ($userIds as $uid) {
                if ($uid <= 0) {
                    continue;
                }
                $links++;
                $stmt = DB::query(
                    'INSERT IGNORE INTO ai_lead_comment_shared_users (comment_id, user_id) VALUES (?,?)',
                    [$cid, $uid]
                );
                if ($stmt->rowCount() > 0) {
                    $created++;
                }
            }
        }
        return [
            'comments' => count($commentIds),
            'users' => count($userIds),
            'links' => $links,
            'created' => $created,
        ];
    }

    public static function defaultDmMessage(): string
    {
        return 'Hi! Thanks for your interest on TikTok. Message me if you need product details or a quote :)';
    }

    /** 去掉 query/hash，统一比较用 */
    public static function normalizeVideoUrl(string $url): string
    {
        $u = trim($url);
        if ($u === '') {
            return '';
        }
        $u = preg_replace('/[?#].*$/', '', $u) ?: $u;
        if (str_starts_with($u, '//')) {
            $u = 'https:' . $u;
        }
        if (str_starts_with($u, '/')) {
            $u = 'https://www.tiktok.com' . $u;
        }
        return mb_substr(rtrim($u, '/'), 0, 512);
    }

    public static function extractVideoId(string $url): string
    {
        if (preg_match('#/video/(\d+)#', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * 终止该拓客相关进行中的批次并释放比特环境（不重新入队）。
     * @return array{aborted_batches:list<int>,close_profiles:list<string>,released_accounts:list<int>}
     */
    public static function abortRunning(int $leadId, string $reason = '任务已终止'): array
    {
        self::ensureSchema();
        AccountWorkBatchService::ensureSchema();
        AccountBrowserEnvService::ensureSchema();

        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [$leadId]);
        if (!$lead) {
            return ['aborted_batches' => [], 'close_profiles' => [], 'released_accounts' => []];
        }

        $batchRows = DB::select(
            "SELECT DISTINCT b.id AS batch_id, b.account_id,
                    COALESCE(NULLIF(TRIM(e.browser_profile_id), ''), NULLIF(TRIM(a.browser_profile_id), '')) AS browser_profile_id
             FROM account_work_batches b
             INNER JOIN account_work_items i ON i.batch_id=b.id
             LEFT JOIN account_browser_envs e ON e.batch_id=b.id
             LEFT JOIN accounts a ON a.id=b.account_id
             WHERE i.task_type IN ('ai_lead','ai_lead_dm') AND i.task_ref_id=?
               AND b.status IN ('pending','running')
               AND b.deleted_at IS NULL",
            [$leadId]
        ) ?: [];

        $aborted = [];
        $closeProfiles = [];
        $accountIds = [];
        foreach ($batchRows as $row) {
            $bid = (int)($row['batch_id'] ?? 0);
            $aid = (int)($row['account_id'] ?? 0);
            if ($aid > 0) {
                $accountIds[] = $aid;
            }
            $pid = trim((string)($row['browser_profile_id'] ?? ''));
            if ($pid !== '') {
                $closeProfiles[] = $pid;
            }
            if ($bid < 1) {
                continue;
            }
            try {
                AccountWorkBatchService::forceFailBatch($bid, $reason, false);
                $aborted[] = $bid;
            } catch (\Throwable $e) {
            }
        }

        try {
            AccountWorkBatchService::cancelItemsForTask('ai_lead', $leadId);
            AccountWorkBatchService::cancelItemsForTask('ai_lead_dm', $leadId);
        } catch (\Throwable $e) {
        }

        $scrapeId = (int)($lead['scrape_account_id'] ?? 0);
        if ($scrapeId > 0) {
            $accountIds[] = $scrapeId;
        }
        foreach (array_values(array_unique(array_filter($accountIds))) as $aid) {
            try {
                $rel = AccountBrowserEnvService::forceReleaseAccount((int)$aid);
                foreach ($rel['profiles'] ?? [] as $p) {
                    if ($p !== '') {
                        $closeProfiles[] = $p;
                    }
                }
                $acc = DB::selectOne('SELECT browser_profile_id FROM accounts WHERE id=?', [(int)$aid]);
                $mainPid = trim((string)($acc['browser_profile_id'] ?? ''));
                if ($mainPid !== '') {
                    $closeProfiles[] = $mainPid;
                }
            } catch (\Throwable $e) {
            }
        }

        $closeProfiles = array_values(array_unique(array_filter($closeProfiles)));
        $accountIds = array_values(array_unique(array_filter($accountIds)));

        DB::query(
            "UPDATE ai_leads SET run_status='idle', progress_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [
                json_encode([
                    'phase' => 'aborted',
                    'abortedBatches' => $aborted,
                    'closeProfiles' => $closeProfiles,
                    'reason' => $reason,
                ], JSON_UNESCAPED_UNICODE),
                $leadId,
            ]
        );

        return [
            'aborted_batches' => $aborted,
            'close_profiles' => $closeProfiles,
            'released_accounts' => $accountIds,
        ];
    }

    /**
     * 重新推送：终止旧批次 + 释放环境 + 关窗 profile 列表 + 重新入队。
     * @return array<string,mixed>
     */
    public static function forceReschedule(int $leadId): array
    {
        self::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [$leadId]);
        if (!$lead) {
            return ['skipped' => true, 'reason' => 'not_found'];
        }

        if ((string)($lead['status'] ?? '') !== 'active') {
            DB::query(
                "UPDATE ai_leads SET status='active', updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$leadId]
            );
        }

        $abort = self::abortRunning($leadId, '用户重新推送：终止旧任务');
        AccountWorkBatchService::cancelMisplacedAiLeadItems($leadId);
        $schedule = self::scheduleIfActive($leadId, true);
        return array_merge($schedule, [
            'force' => true,
            'aborted_batches' => $abort['aborted_batches'],
            'close_profiles' => $abort['close_profiles'],
            'released_accounts' => $abort['released_accounts'],
        ]);
    }

    /**
     * 状态变为 active 时入队执行（按用户 / 按视频分线 payload）
     * @return array{batch_id?:int,item_id?:int,account_id?:int,skipped?:bool,reason?:string}
     */
    public static function scheduleIfActive(int $leadId, bool $forceFresh = false): array
    {
        self::ensureSchema();
        AccountWorkBatchService::ensureSchema();
        AccountBrowserEnvService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [$leadId]);
        if (!$lead || (string)($lead['status'] ?? '') !== 'active') {
            return ['skipped' => true, 'reason' => 'not_active'];
        }

        if ($forceFresh) {
            try {
                AccountWorkBatchService::cancelItemsForTask('ai_lead', $leadId);
                AccountWorkBatchService::cancelItemsForTask('ai_lead_dm', $leadId);
            } catch (\Throwable $e) {
            }
        }

        $queued = DB::selectOne(
            "SELECT i.id, i.batch_id, i.status FROM account_work_items i
             WHERE i.task_type='ai_lead' AND i.task_ref_id=? AND i.status IN ('pending','running')
             ORDER BY i.id DESC LIMIT 1",
            [$leadId]
        );
        if ($queued && !$forceFresh) {
            $batchRow = DB::selectOne(
                'SELECT id, batch_scope FROM account_work_batches WHERE id=?',
                [(int)($queued['batch_id'] ?? 0)]
            );
            if ($batchRow && (string)($batchRow['batch_scope'] ?? 'general') !== AccountWorkBatchService::BATCH_SCOPE_AI_LEAD) {
                AccountWorkBatchService::cancelMisplacedAiLeadItems($leadId);
            } else {
                return [
                    'skipped' => true,
                    'reason' => 'already_queued',
                    'batch_id' => (int)($queued['batch_id'] ?? 0),
                    'item_id' => (int)($queued['id'] ?? 0),
                ];
            }
        }

        // 无队列项但 run_status 仍为 running：视为陈旧状态，允许重新入队
        if ((string)($lead['run_status'] ?? '') === 'running') {
            DB::query(
                "UPDATE ai_leads SET run_status='idle', updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$leadId]
            );
        }

        $accountId = self::pickEligibleAccount($leadId);
        if ($accountId < 1) {
            // 兼容：优先使用任务指定的查数据账号
            $forced = (int)($lead['scrape_account_id'] ?? 0);
            if ($forced > 0) {
                $accountId = $forced;
            }
        }
        if ($accountId < 1) {
            return ['skipped' => true, 'reason' => 'no_eligible_account'];
        }

        $ownerId = AccountWorkBatchService::resolveWorkOwnerId(
            $accountId,
            (int)($lead['owner_id'] ?? 0) ?: null
        );
        if ((int)($lead['owner_id'] ?? 0) < 1 && $ownerId > 0) {
            DB::query(
                'UPDATE ai_leads SET owner_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND owner_id=0',
                [$ownerId, $leadId]
            );
        }

        $payload = self::buildPayload($lead, $accountId);
        $out = AccountWorkBatchService::enqueueDedicatedItem(
            $accountId,
            'ai_lead',
            $payload,
            $leadId,
            $ownerId
        );
        DB::query(
            "UPDATE ai_leads SET run_status='queued', last_run_at=NOW(), progress_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [
                json_encode([
                    'phase' => 'queued',
                    'accountId' => $accountId,
                    'batchId' => (int)($out['batch_id'] ?? 0),
                ], JSON_UNESCAPED_UNICODE),
                $leadId,
            ]
        );
        return array_merge($out, ['account_id' => $accountId, 'skipped' => false]);
    }

    public static function pickEligibleAccount(int $leadId): int
    {
        AccountBrowserEnvService::ensureSchema();
        $lead = DB::selectOne('SELECT scrape_account_id FROM ai_leads WHERE id=?', [$leadId]);
        $preferId = (int)($lead['scrape_account_id'] ?? 0);
        $rows = DB::select(
            "SELECT a.id, a.browser_profile_id, a.proxy_id, a.enabled, a.login_status,
                    p.status AS proxy_status,
                    (SELECT e.browser_profile_id FROM account_browser_envs e
                      WHERE e.account_id=a.id AND e.saturation='idle'
                      ORDER BY e.is_primary DESC, e.id ASC LIMIT 1) AS idle_profile_id,
                    (SELECT e2.browser_profile_id FROM account_browser_envs e2
                      WHERE e2.account_id=a.id
                      ORDER BY e2.is_primary DESC, e2.id ASC LIMIT 1) AS any_profile_id
             FROM ai_lead_accounts la
             INNER JOIN accounts a ON a.id=la.account_id
             LEFT JOIN proxies p ON p.id=a.proxy_id
             WHERE la.lead_id=?
             ORDER BY
               CASE WHEN a.id=? THEN 0 ELSE 1 END,
               CASE WHEN (
                 SELECT e.browser_profile_id FROM account_browser_envs e
                 WHERE e.account_id=a.id AND e.saturation='idle' LIMIT 1
               ) IS NOT NULL THEN 0 ELSE 1 END,
               la.id ASC",
            [$leadId, $preferId]
        ) ?: [];
        // 若指定了查数据账号，优先只认它（满足环境+代理条件时）
        if ($preferId > 0) {
            $preferRows = array_values(array_filter($rows, static fn($r) => (int)($r['id'] ?? 0) === $preferId));
            if ($preferRows) {
                $rows = array_merge($preferRows, array_values(array_filter($rows, static fn($r) => (int)($r['id'] ?? 0) !== $preferId)));
            }
        }
        $fallback = 0;
        foreach ($rows as $r) {
            $aid = (int)($r['id'] ?? 0);
            $proxyId = (int)($r['proxy_id'] ?? 0);
            if ($aid < 1 || $proxyId < 1) {
                continue;
            }
            // enabled 缺省/空视为可用；仅明确禁用(0)才跳过
            if (array_key_exists('enabled', $r) && $r['enabled'] !== null && (int)$r['enabled'] === 0) {
                continue;
            }
            $profile = trim((string)(
                $r['idle_profile_id']
                ?? $r['any_profile_id']
                ?? $r['browser_profile_id']
                ?? ''
            ));
            if ($profile === '') {
                continue;
            }
            try {
                AccountBrowserEnvService::bind($aid, $profile, true);
            } catch (\Throwable $e) {
                // 已绑定到本账号即可继续
            }
            // 有空闲环境优先立刻返回；无空闲也允许入队（等待 claim 时回收）
            if (!empty($r['idle_profile_id'])) {
                return $aid;
            }
            if ($fallback < 1) {
                $fallback = $aid;
            }
        }
        return $fallback;
    }

    /** @param array<string,mixed> $lead */
    public static function buildPayload(array $lead, int $accountId): array
    {
        $type = strtolower(trim((string)($lead['type'] ?? 'user')));
        if ($type === 'search' || $type === 'comment') {
            $type = 'user';
        }
        $keyword = trim((string)($lead['keywords'] ?? ''));
        $parts = preg_split('/[\r\n,，;；]+/u', $keyword) ?: [];
        $keyword = trim((string)($parts[0] ?? ''));
        $browserBackend = self::normalizeLeadBackend($lead['browser_backend'] ?? 'bit');
        return [
            'leadId' => (int)$lead['id'],
            'type' => $type,
            'accountId' => $accountId,
            'keyword' => $keyword,
            'limitFollows' => max(0, (int)($lead['limit_follows'] ?? 0)),
            'limitLikes' => max(0, (int)($lead['limit_likes'] ?? 0)),
            'limitLook' => max(0, (int)($lead['limit_look'] ?? 0)),
            'filterPrompt' => AiLeadFilterService::resolvePrompt(),
            'dmMessage' => trim((string)($lead['dm_message'] ?? '')) ?: self::defaultDmMessage(),
            'browserBackend' => $browserBackend,
        ];
    }

    /** 是否仍有待发私信目标 */
    public static function hasPendingDmTargets(int $leadId): bool
    {
        return count(self::listPendingDmTargets($leadId)) > 0;
    }

    /** 失败重试上限 */
    public const DM_FAIL_MAX_RETRIES = 5;

    /** 失败重试基础退避（小时） */
    public const DM_FAIL_BACKOFF_BASE_HOURS = 6;

    public static function isUsernameEligibleForDm(string $username): bool
    {
        self::ensureSchema();
        $username = trim($username);
        if ($username === '') {
            return false;
        }
        if (self::dmAlreadySent($username)['sent']) {
            return false;
        }
        $row = DB::selectOne(
            "SELECT COUNT(*) AS fail_count, MAX(updated_at) AS last_fail
             FROM ai_lead_records
             WHERE status='failed' AND username<>'' AND LOWER(username)=LOWER(?)",
            [$username]
        );
        $count = (int)($row['fail_count'] ?? 0);
        if ($count >= self::DM_FAIL_MAX_RETRIES) {
            return false;
        }
        if ($count < 1) {
            return true;
        }
        $last = strtotime((string)($row['last_fail'] ?? ''));
        if ($last === false) {
            return true;
        }
        $backoffHours = min(48, self::DM_FAIL_BACKOFF_BASE_HOURS * (2 ** min($count - 1, 3)));
        return (time() - $last) >= ($backoffHours * 3600);
    }

    /**
     * 待发私信目标（意向评论去重、排除已发送、失败退避）。
     * @return list<array<string,mixed>>
     */
    public static function listPendingDmTargets(int $leadId): array
    {
        self::ensureSchema();
        $sentRows = DB::select(
            "SELECT DISTINCT LOWER(username) AS u FROM ai_lead_records
             WHERE status='sent' AND username<>''"
        ) ?: [];
        $sentSet = [];
        foreach ($sentRows as $r) {
            $u = strtolower(trim((string)($r['u'] ?? '')));
            if ($u !== '') {
                $sentSet[$u] = true;
            }
        }

        $comments = DB::select(
            "SELECT id, uuid, username, nickname, profile_url, comment_text, intent_score
             FROM ai_lead_comments
             WHERE lead_id=? AND ai_pass=1 AND username<>''
             ORDER BY intent_score DESC, id ASC",
            [$leadId]
        ) ?: [];
        $targets = [];
        $seenUser = [];
        foreach ($comments as $c) {
            $username = trim((string)($c['username'] ?? ''));
            $key = strtolower($username);
            if ($username === '' || isset($seenUser[$key]) || isset($sentSet[$key])) {
                continue;
            }
            if (!self::isUsernameEligibleForDm($username)) {
                continue;
            }
            if (self::isUsernameDmQueued($username, $leadId)) {
                continue;
            }
            $seenUser[$key] = true;
            $targets[] = [
                'commentId' => (int)($c['id'] ?? 0),
                'uuid' => (string)($c['uuid'] ?? ''),
                'username' => $username,
                'nickname' => (string)($c['nickname'] ?? ''),
                'profileUrl' => (string)($c['profile_url'] ?? ''),
                'commentText' => (string)($c['comment_text'] ?? ''),
                'intentScore' => max(0, min(10, (int)($c['intent_score'] ?? 0))),
            ];
        }
        return $targets;
    }

    /**
     * 为账号关联的 active 拓客任务挑选最高意向待发目标（单条 SQL + 退避过滤）。
     * @return array{leadId:int,target:array<string,mixed>}|null
     */
    public static function pickBestTargetForAccount(int $accountId): ?array
    {
        self::ensureSchema();
        if ($accountId < 1) {
            return null;
        }
        $rows = DB::select(
            "SELECT cm.id AS comment_id, cm.uuid, cm.username, cm.nickname, cm.profile_url,
                    cm.comment_text, cm.intent_score, cm.lead_id
             FROM ai_lead_comments cm
             INNER JOIN ai_leads l ON l.id=cm.lead_id AND l.status='active'
             INNER JOIN ai_lead_accounts la ON la.lead_id=l.id AND la.account_id=?
             WHERE cm.ai_pass=1 AND cm.username<>''
               AND NOT EXISTS (
                 SELECT 1 FROM ai_lead_records r
                 WHERE r.status='sent' AND r.username<>''
                   AND LOWER(r.username)=LOWER(cm.username)
               )
             ORDER BY cm.intent_score DESC, cm.id ASC
             LIMIT 80",
            [$accountId]
        ) ?: [];
        $seen = [];
        foreach ($rows as $c) {
            $username = trim((string)($c['username'] ?? ''));
            $key = strtolower($username);
            if ($username === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (!self::isUsernameEligibleForDm($username)) {
                continue;
            }
            return [
                'leadId' => (int)($c['lead_id'] ?? 0),
                'target' => [
                    'commentId' => (int)($c['comment_id'] ?? 0),
                    'uuid' => (string)($c['uuid'] ?? ''),
                    'username' => $username,
                    'nickname' => (string)($c['nickname'] ?? ''),
                    'profileUrl' => (string)($c['profile_url'] ?? ''),
                    'commentText' => (string)($c['comment_text'] ?? ''),
                    'intentScore' => max(0, min(10, (int)($c['intent_score'] ?? 0))),
                ],
            ];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function mapCommentRowToTarget(array $row): array
    {
        return [
            'commentId' => (int)($row['comment_id'] ?? $row['id'] ?? 0),
            'uuid' => (string)($row['uuid'] ?? ''),
            'username' => trim((string)($row['username'] ?? '')),
            'nickname' => (string)($row['nickname'] ?? ''),
            'profileUrl' => (string)($row['profile_url'] ?? ''),
            'commentText' => (string)($row['comment_text'] ?? ''),
            'intentScore' => max(0, min(10, (int)($row['intent_score'] ?? 0))),
        ];
    }

    /** 账号是否已有 pending/running 的 ai_lead_dm 队列项 */
    public static function isAccountDmBusy(int $accountId): bool
    {
        if ($accountId < 1) {
            return false;
        }
        AccountWorkBatchService::ensureSchema();
        $row = DB::selectOne(
            "SELECT i.id FROM account_work_items i
             INNER JOIN account_work_batches b ON b.id=i.batch_id AND b.deleted_at IS NULL
             WHERE i.account_id=? AND i.task_type='ai_lead_dm'
               AND i.status IN ('pending','running')
             LIMIT 1",
            [$accountId]
        );
        return $row !== null;
    }

    /** 对方用户名是否已在私信队列中（pending/running） */
    public static function isUsernameDmQueued(string $username, int $leadId = 0): bool
    {
        AccountWorkBatchService::ensureSchema();
        $username = strtolower(trim(str_replace('@', '', $username)));
        if ($username === '') {
            return false;
        }
        $sql = "SELECT i.id FROM account_work_items i
                INNER JOIN account_work_batches b ON b.id=i.batch_id AND b.deleted_at IS NULL
                WHERE i.task_type='ai_lead_dm' AND i.status IN ('pending','running')";
        $params = [];
        if ($leadId > 0) {
            $sql .= ' AND i.task_ref_id=?';
            $params[] = $leadId;
        }
        $sql .= " AND (
            LOWER(TRIM(BOTH '@' FROM JSON_UNQUOTE(JSON_EXTRACT(i.payload_json, '$.username')))) = ?
            OR LOWER(TRIM(BOTH '@' FROM JSON_UNQUOTE(JSON_EXTRACT(i.payload_json, '$.forceTarget.username')))) = ?
        ) LIMIT 1";
        $params[] = $username;
        $params[] = $username;
        try {
            return DB::selectOne($sql, $params) !== null;
        } catch (\Throwable $e) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $username) . '%';
            $sql2 = "SELECT i.id FROM account_work_items i
                     INNER JOIN account_work_batches b ON b.id=i.batch_id AND b.deleted_at IS NULL
                     WHERE i.task_type='ai_lead_dm' AND i.status IN ('pending','running')
                       AND i.payload_json LIKE ? LIMIT 1";
            return DB::selectOne($sql2, ['%' . $username . '%']) !== null;
        }
    }

    /**
     * 全局待发私信线索（评论优先，按意向分排序；不含 account 绑定）。
     * @return list<array{leadId:int,lead:array<string,mixed>,target:array<string,mixed>}>
     */
    public static function listGlobalPendingDmTargets(int $limit = 50): array
    {
        self::ensureSchema();
        $limit = max(1, min(200, $limit));
        $fetch = max($limit * 4, 40);
        $rows = DB::select(
            "SELECT cm.id AS comment_id, cm.uuid, cm.username, cm.nickname, cm.profile_url,
                    cm.comment_text, cm.intent_score, cm.lead_id,
                    l.owner_id, l.keywords, l.browser_backend, l.dm_message
             FROM ai_lead_comments cm
             INNER JOIN ai_leads l ON l.id=cm.lead_id
               AND l.status='active'
               AND COALESCE(l.dm_dispatch_enabled, 1)=1
             WHERE cm.ai_pass=1 AND cm.username<>''
               AND NOT EXISTS (
                 SELECT 1 FROM ai_lead_records r
                 WHERE r.status='sent' AND r.username<>'' AND LOWER(r.username)=LOWER(cm.username)
               )
             ORDER BY cm.intent_score DESC, cm.id ASC
             LIMIT {$fetch}"
        ) ?: [];

        $out = [];
        $seenUser = [];
        foreach ($rows as $c) {
            if (count($out) >= $limit) {
                break;
            }
            $username = trim((string)($c['username'] ?? ''));
            $key = strtolower($username);
            if ($username === '' || isset($seenUser[$key])) {
                continue;
            }
            if (!self::isUsernameEligibleForDm($username)) {
                continue;
            }
            $leadId = (int)($c['lead_id'] ?? 0);
            if (self::isUsernameDmQueued($username, $leadId)) {
                continue;
            }
            $seenUser[$key] = true;
            $out[] = [
                'leadId' => $leadId,
                'lead' => $c,
                'target' => self::mapCommentRowToTarget($c),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $accounts
     * @return list<array<string,mixed>>
     */
    public static function sortAccountCandidatesForDm(array $accounts): array
    {
        usort($accounts, static function (array $a, array $b): int {
            $ac = !empty($a['canSendNow']) ? 0 : 1;
            $bc = !empty($b['canSendNow']) ? 0 : 1;
            if ($ac !== $bc) {
                return $ac <=> $bc;
            }
            $rem = (int)($b['remaining'] ?? 0) <=> (int)($a['remaining'] ?? 0);
            if ($rem !== 0) {
                return $rem;
            }
            $used = (int)($a['usedToday'] ?? 0) <=> (int)($b['usedToday'] ?? 0);
            if ($used !== 0) {
                return $used;
            }
            return (int)($a['accountId'] ?? 0) <=> (int)($b['accountId'] ?? 0);
        });
        return $accounts;
    }

    /**
     * 任务关联账号池中，为待发线索挑选当前最优可用账号（推送时绑定）。
     * @param array<string,mixed> $target
     * @param array{preferAccountId?:int,excludeAccountIds?:list<int>} $opts
     * @return array{accountId:int,ownerId:int,browserBackend:string,remaining:int,usedToday:int,dailyLimit:int}|null
     */
    public static function pickBestAccountForTarget(int $leadId, array $target = [], array $opts = []): ?array
    {
        self::ensureSchema();
        AiLeadVideoWeightService::ensureSchema();
        AccountWorkBatchService::ensureSchema();
        if ($leadId < 1) {
            return null;
        }

        $preferId = max(0, (int)($opts['preferAccountId'] ?? $opts['account_id'] ?? 0));
        $exclude = array_fill_keys(array_map('intval', $opts['excludeAccountIds'] ?? []), true);
        $today = date('Y-m-d');

        $accountRows = DB::select(
            "SELECT a.id, a.username, a.owner_id, a.enabled, a.proxy_id, a.browser_profile_id, a.browser_backend,
                    (SELECT e.browser_profile_id FROM account_browser_envs e
                      WHERE e.account_id=a.id
                      ORDER BY e.is_primary DESC, e.id ASC LIMIT 1) AS any_profile_id
             FROM ai_lead_accounts la
             INNER JOIN accounts a ON a.id=la.account_id
             WHERE la.lead_id=?",
            [$leadId]
        ) ?: [];

        $candidates = [];
        foreach ($accountRows as $r) {
            $aid = (int)($r['id'] ?? 0);
            if ($aid < 1 || isset($exclude[$aid])) {
                continue;
            }
            if (array_key_exists('enabled', $r) && $r['enabled'] !== null && (int)$r['enabled'] === 0) {
                continue;
            }
            if ((int)($r['proxy_id'] ?? 0) < 1) {
                continue;
            }
            $profile = trim((string)($r['any_profile_id'] ?? $r['browser_profile_id'] ?? ''));
            if ($profile === '') {
                continue;
            }
            if (self::isAccountDmBusy($aid)) {
                continue;
            }

            AiLeadVideoWeightService::refreshDailyQuota($aid, $today);
            $check = AiLeadVideoWeightService::canSendDmNow($aid);
            if (empty($check['can'])) {
                continue;
            }

            $candidates[] = [
                'accountId' => $aid,
                'ownerId' => (int)($r['owner_id'] ?? 0),
                'browserBackend' => self::normalizeLeadBackend($r['browser_backend'] ?? 'bit'),
                'remaining' => (int)($check['remaining'] ?? 0),
                'usedToday' => (int)($check['usedToday'] ?? 0),
                'dailyLimit' => (int)($check['dailyLimit'] ?? 1),
                'canSendNow' => true,
                'prefer' => $aid === $preferId ? 1 : 0,
            ];
        }

        if (!$candidates) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['prefer'] !== $b['prefer']) {
                return $b['prefer'] <=> $a['prefer'];
            }
            $rem = (int)($b['remaining'] ?? 0) <=> (int)($a['remaining'] ?? 0);
            if ($rem !== 0) {
                return $rem;
            }
            $used = (int)($a['usedToday'] ?? 0) <=> (int)($b['usedToday'] ?? 0);
            if ($used !== 0) {
                return $used;
            }
            return (int)($a['accountId'] ?? 0) <=> (int)($b['accountId'] ?? 0);
        });

        $best = $candidates[0];
        unset($best['prefer'], $best['canSendNow']);
        return $best;
    }

    /**
     * 入队单条 ai_lead_dm（dispatch / 测试共用）。
     * @param array<string,mixed> $target
     * @return array{batch_id:int,item_id:int,account_id:int}
     */
    public static function enqueueDmItem(
        int $leadId,
        int $accountId,
        array $target,
        array $payloadExtra = []
    ): array {
        AccountWorkBatchService::ensureSchema();
        $lead = DB::selectOne('SELECT owner_id, keywords, browser_backend FROM ai_leads WHERE id=?', [$leadId]);
        if (!$lead) {
            throw new \InvalidArgumentException('拓客任务不存在');
        }
        $ownerId = AccountWorkBatchService::resolveWorkOwnerId(
            $accountId,
            (int)($lead['owner_id'] ?? 0) ?: null
        );
        $kw = trim((string)($lead['keywords'] ?? ''));
        $kwParts = preg_split('/[\r\n,，;；]+/u', $kw) ?: [];
        $keyword = trim((string)($kwParts[0] ?? ''));
        $browserBackend = self::normalizeLeadBackend($lead['browser_backend'] ?? 'bit');

        $payload = array_merge([
            'leadId' => $leadId,
            'type' => 'ai_lead_dm',
            'limit' => 1,
            'keyword' => $keyword,
            'product' => $keyword,
            'forceTarget' => $target,
            'browserBackend' => $browserBackend,
            'username' => (string)($target['username'] ?? ''),
        ], $payloadExtra);

        $out = AccountWorkBatchService::enqueueDedicatedItem(
            $accountId,
            'ai_lead_dm',
            $payload,
            $leadId,
            $ownerId
        );

        return [
            'batch_id' => (int)($out['batch_id'] ?? $out['batchId'] ?? 0),
            'item_id' => (int)($out['item_id'] ?? $out['itemId'] ?? 0),
            'account_id' => $accountId,
        ];
    }

    /** @return array{dmsPending:int,dmsSent:int,dmsNeeded:int} */
    public static function dmProgressStats(int $leadId): array
    {
        self::ensureSchema();
        $pending = count(self::listPendingDmTargets($leadId));
        $sent = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM ai_lead_records WHERE lead_id=? AND status='sent'",
            [$leadId]
        )['c'] ?? 0);
        $needed = $pending + $sent;
        return ['dmsPending' => $pending, 'dmsSent' => $sent, 'dmsNeeded' => $needed];
    }

    /** 刷新任务 stats_json 中的私信进度字段 */
    public static function refreshLeadDmStats(int $leadId): array
    {
        self::ensureSchema();
        $prog = self::dmProgressStats($leadId);
        $lead = DB::selectOne('SELECT stats_json, status FROM ai_leads WHERE id=?', [$leadId]);
        if (!$lead) {
            return $prog;
        }
        $stats = json_decode((string)($lead['stats_json'] ?? ''), true);
        if (!is_array($stats)) {
            $stats = [];
        }
        $stats['dmsPending'] = $prog['dmsPending'];
        $stats['dmsSent'] = $prog['dmsSent'];
        $stats['dmsNeeded'] = max((int)($stats['dmsNeeded'] ?? 0), $prog['dmsNeeded']);
        DB::query(
            'UPDATE ai_leads SET stats_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [json_encode($stats, JSON_UNESCAPED_UNICODE), $leadId]
        );
        if ($prog['dmsPending'] < 1 && (string)($lead['status'] ?? '') === 'active') {
            $hasLeads = (int)($stats['leads'] ?? 0) > 0 || $prog['dmsSent'] > 0;
            if ($hasLeads) {
                DB::query(
                    "UPDATE ai_leads SET status='completed', run_status='done', updated_at=CURRENT_TIMESTAMP WHERE id=?",
                    [$leadId]
                );
            }
        }
        return $prog;
    }

    /**
     * 批量刷新 active/completed 任务的私信进度统计。
     * @return array{updated:int}
     */
    public static function syncAllLeadDmStats(int $limit = 200): array
    {
        self::ensureSchema();
        $limit = max(1, min(1000, $limit));
        $rows = DB::select(
            "SELECT id FROM ai_leads WHERE status IN ('active','completed') ORDER BY updated_at DESC LIMIT {$limit}"
        ) ?: [];
        foreach ($rows as $r) {
            self::refreshLeadDmStats((int)($r['id'] ?? 0));
        }
        return ['updated' => count($rows)];
    }

    /**
     * 独立入队「私信测试」任务（不跑抓取）。
     * @param array{accountId?:int,username?:string,limit?:int} $opts
     * @return array{batch_id?:int,item_id?:int,account_id?:int,skipped?:bool,reason?:string}
     */
    public static function scheduleDmTest(int $leadId, array $opts = []): array
    {
        self::ensureSchema();
        AccountWorkBatchService::ensureSchema();
        AccountBrowserEnvService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [$leadId]);
        if (!$lead) {
            return ['skipped' => true, 'reason' => 'not_found'];
        }

        $force = !empty($opts['force'])
            || filter_var($opts['forceRetry'] ?? $opts['force_retry'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $usernameOpt = trim((string)($opts['username'] ?? ''));
        if ($usernameOpt !== '') {
            // 指定用户重试：取消卡住的队列项，允许立即重新入队
            $force = true;
        }
        if ($force) {
            try {
                AccountWorkBatchService::cancelItemsForTask('ai_lead_dm', $leadId);
            } catch (\Throwable $e) {
            }
        } else {
            $queued = DB::selectOne(
                "SELECT i.id, i.batch_id, i.status FROM account_work_items i
                 WHERE i.task_type='ai_lead_dm' AND i.task_ref_id=? AND i.status IN ('pending','running')
                 ORDER BY i.id DESC LIMIT 1",
                [$leadId]
            );
            if ($queued) {
                return [
                    'skipped' => true,
                    'reason' => 'already_queued',
                    'batch_id' => (int)($queued['batch_id'] ?? 0),
                    'item_id' => (int)($queued['id'] ?? 0),
                ];
            }
        }

        $forcedAccount = max(0, (int)($opts['accountId'] ?? $opts['account_id'] ?? 0));
        $username = trim((string)($opts['username'] ?? ''));
        $username = ltrim($username, '@');
        $limit = max(0, (int)($opts['limit'] ?? 0));

        // 指定用户时附带 forceTarget，避免计划里因已发送/去重导致测不到
        $forceTarget = null;
        if ($username !== '') {
            $c = DB::selectOne(
                "SELECT id, uuid, username, nickname, profile_url, comment_text, intent_score
                 FROM ai_lead_comments
                 WHERE lead_id=? AND ai_pass=1 AND LOWER(username)=LOWER(?)
                 ORDER BY intent_score DESC, id DESC LIMIT 1",
                [$leadId, $username]
            );
            if (!$c) {
                $c = DB::selectOne(
                    "SELECT id, uuid, username, '' AS nickname, '' AS profile_url, message AS comment_text, 0 AS intent_score
                     FROM ai_lead_records
                     WHERE lead_id=? AND LOWER(username)=LOWER(?)
                     ORDER BY id DESC LIMIT 1",
                    [$leadId, $username]
                );
            }
            if ($c) {
                $forceTarget = [
                    'commentId' => (int)($c['id'] ?? 0),
                    'uuid' => (string)($c['uuid'] ?? ''),
                    'username' => (string)($c['username'] ?? $username),
                    'nickname' => (string)($c['nickname'] ?? ''),
                    'profileUrl' => (string)($c['profile_url'] ?? ('https://www.tiktok.com/@' . $username)),
                    'commentText' => (string)($c['comment_text'] ?? ''),
                    'intentScore' => (int)($c['intent_score'] ?? 0),
                ];
            } else {
                $forceTarget = [
                    'commentId' => 0,
                    'uuid' => '',
                    'username' => $username,
                    'nickname' => $username,
                    'profileUrl' => 'https://www.tiktok.com/@' . $username,
                    'commentText' => '',
                    'intentScore' => 0,
                ];
            }
        }

        $targetForPick = $forceTarget ?? ['username' => ''];
        $picked = self::pickBestAccountForTarget($leadId, $targetForPick, [
            'preferAccountId' => $forcedAccount,
        ]);
        if (!$picked) {
            return ['skipped' => true, 'reason' => 'no_eligible_account'];
        }
        $accountId = (int)($picked['accountId'] ?? 0);

        $ownerId = (int)($lead['owner_id'] ?? 0);
        if ($ownerId < 1 && !empty($picked['ownerId'])) {
            $ownerId = (int)$picked['ownerId'];
            DB::query(
                'UPDATE ai_leads SET owner_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND owner_id=0',
                [$ownerId, $leadId]
            );
        }

        $out = self::enqueueDmItem($leadId, $accountId, $targetForPick, [
            'mode' => 'dm_test',
            'type' => 'dm_test',
            'username' => $username,
            'limit' => $limit > 0 ? $limit : ($username !== '' ? 1 : 3),
            'testMode' => true,
            'forceTarget' => $forceTarget,
        ]);

        return [
            'batch_id' => (int)($out['batch_id'] ?? 0),
            'item_id' => (int)($out['item_id'] ?? 0),
            'account_id' => $accountId,
            'skipped' => false,
        ];
    }

    /**
     * 意向评论私信计划：去重用户 + 关联账号今日剩余额度。
     * @return array<string,mixed>
     */
    public static function buildDmPlan(int $leadId): array
    {
        self::ensureSchema();
        AiLeadVideoWeightService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [$leadId]);
        if (!$lead) {
            throw new \InvalidArgumentException('拓客任务不存在');
        }
        $today = date('Y-m-d');
        $message = trim((string)($lead['dm_message'] ?? ''));
        if ($message === '') {
            $message = self::defaultDmMessage();
        }

        $targets = self::listPendingDmTargets($leadId);

        $accountRows = DB::select(
            "SELECT a.id, a.username, a.nickname, a.enabled, a.proxy_id, a.browser_profile_id,
                    (SELECT e.browser_profile_id FROM account_browser_envs e
                      WHERE e.account_id=a.id
                      ORDER BY e.is_primary DESC, e.id ASC LIMIT 1) AS any_profile_id
             FROM ai_lead_accounts la
             INNER JOIN accounts a ON a.id=la.account_id
             WHERE la.lead_id=?
             ORDER BY la.id ASC",
            [$leadId]
        ) ?: [];

        $accounts = [];
        $capacity = 0;
        $maxDailyLimit = 1;
        foreach ($accountRows as $r) {
            $aid = (int)($r['id'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            if (array_key_exists('enabled', $r) && $r['enabled'] !== null && (int)$r['enabled'] === 0) {
                continue;
            }
            $proxyId = (int)($r['proxy_id'] ?? 0);
            $profile = trim((string)($r['any_profile_id'] ?? $r['browser_profile_id'] ?? ''));
            if ($proxyId < 1 || $profile === '') {
                continue;
            }
            AiLeadVideoWeightService::refreshDailyQuota($aid, $today);
            $weight = AiLeadVideoWeightService::accountWeightSummary($aid);
            $limit = (int)($weight['dailyLimit'] ?? 1);
            $used = (int)($weight['usedToday'] ?? 0);
            $remaining = (int)($weight['remainingToday'] ?? 0);
            if (!$weight['canSendNow'] && $remaining > 0) {
                // 间隔未到但仍有额度：计入总容量，dispatch 会等待
            }
            $maxDailyLimit = max($maxDailyLimit, $limit);
            $capacity += $remaining;
            $accounts[] = [
                'accountId' => $aid,
                'username' => (string)($r['username'] ?? ''),
                'nickname' => (string)($r['nickname'] ?? ''),
                'usedToday' => $used,
                'remaining' => $remaining,
                'dailyLimit' => $limit,
                'activationDays' => $weight['activationDays'],
                'canSendNow' => !empty($weight['canSendNow']),
                'blockReason' => (string)($weight['blockReason'] ?? ''),
                'nextAvailableAt' => $weight['nextAvailableAt'],
                'dmIntervalMinSec' => (int)($weight['dmIntervalMinSec'] ?? 0),
                'dmIntervalMaxSec' => (int)($weight['dmIntervalMaxSec'] ?? 0),
            ];
        }

        $targetsCount = count($targets);
        $accountsBound = count($accounts);
        $avgLimit = max(1, $maxDailyLimit);
        $accountsNeeded = $targetsCount > 0 ? (int)ceil($targetsCount / $avgLimit) : 0;
        $shortfall = max(0, $targetsCount - $capacity);
        $accountsMissing = $shortfall > 0 ? (int)ceil($shortfall / $avgLimit) : 0;

        $kw = trim((string)($lead['keywords'] ?? ''));
        $kwParts = preg_split('/[\r\n,，;；]+/u', $kw) ?: [];
        $keyword = trim((string)($kwParts[0] ?? ''));

        $accounts = self::sortAccountCandidatesForDm($accounts);

        return [
            'leadId' => $leadId,
            'message' => $message,
            'keyword' => $keyword,
            'product' => $keyword,
            'dailyLimit' => $avgLimit,
            'targets' => $targets,
            'accounts' => $accounts,
            'summary' => [
                'targets' => $targetsCount,
                'capacity' => $capacity,
                'accountsBound' => $accountsBound,
                'accountsNeeded' => $accountsNeeded,
                'accountsMissing' => $accountsMissing,
                'canSend' => min($targetsCount, $capacity),
            ],
        ];
    }

    /**
     * 该用户名是否已经成功发过私信（任意拓客任务）。
     * @return array{sent:bool,recordId:int,leadId:int}
     */
    public static function dmAlreadySent(string $username, string $uuid = ''): array
    {
        self::ensureSchema();
        $username = trim($username);
        $uuid = trim($uuid);
        if ($username === '' && $uuid === '') {
            return ['sent' => false, 'recordId' => 0, 'leadId' => 0];
        }
        $row = DB::selectOne(
            "SELECT id, lead_id FROM ai_lead_records
             WHERE status='sent'
               AND (
                 (?<>'' AND username<>'' AND LOWER(username)=LOWER(?))
                 OR (?<>'' AND uuid=?)
               )
             ORDER BY id DESC LIMIT 1",
            [$username, $username, $uuid, $uuid]
        );
        if (!$row) {
            return ['sent' => false, 'recordId' => 0, 'leadId' => 0];
        }
        return [
            'sent' => true,
            'recordId' => (int)($row['id'] ?? 0),
            'leadId' => (int)($row['lead_id'] ?? 0),
        ];
    }

    /**
     * 记录一次私信结果；成功时累加当日次数（上限 DAILY_DM_LIMIT）。
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function recordDm(int $leadId, array $data): array
    {
        self::ensureSchema();
        $accountId = max(0, (int)($data['accountId'] ?? $data['account_id'] ?? 0));
        $username = trim((string)($data['username'] ?? ''));
        $uuid = trim((string)($data['uuid'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));
        $errorMessage = trim((string)($data['errorMessage'] ?? $data['error_message'] ?? ''));
        $status = strtolower(trim((string)($data['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'sent', 'failed', 'skipped'], true)) {
            $status = 'failed';
        }
        if ($accountId < 1) {
            throw new \InvalidArgumentException('缺少私信账号');
        }
        if ($username === '') {
            throw new \InvalidArgumentException('缺少对方用户名');
        }
        if ($uuid === '') {
            $uuid = 'u_' . md5(strtolower($username));
        }

        $bound = DB::selectOne(
            'SELECT id FROM ai_lead_accounts WHERE lead_id=? AND account_id=?',
            [$leadId, $accountId]
        );
        if (!$bound) {
            throw new \InvalidArgumentException('账号未关联到此拓客任务');
        }

        // 同任务 + 同账号 + 同对方：更新原记录，避免重复测试产生多条
        $existing = DB::selectOne(
            "SELECT id, status, lead_log_id FROM ai_lead_records
             WHERE lead_id=? AND account_id=?
               AND (
                 (username<>'' AND LOWER(username)=LOWER(?))
                 OR (?<>'' AND uuid=?)
               )
             ORDER BY id DESC LIMIT 1",
            [$leadId, $accountId, $username, $uuid, $uuid]
        );
        $prevStatus = $existing ? strtolower((string)($existing['status'] ?? '')) : '';

        $bumped = false;
        $usedToday = 0;
        if ($status === 'sent' && $prevStatus !== 'sent') {
            $slot = self::bumpDailyDm($accountId);
            $bumped = (bool)($slot['bumped'] ?? false);
            $usedToday = (int)($slot['times'] ?? 0);
            if (!$bumped) {
                // 今日已满：改记 skipped，避免超发
                $status = 'skipped';
                $errorMessage = $errorMessage !== '' ? $errorMessage : '今日私信额度已满';
            }
        } else {
            $today = date('Y-m-d');
            $row = DB::selectOne(
                'SELECT times FROM ai_lead_account_daily WHERE account_id=? AND date=?',
                [$accountId, $today]
            );
            $usedToday = (int)($row['times'] ?? 0);
        }

        $leadLogId = max(0, (int)($data['leadLogId'] ?? $data['lead_log_id'] ?? 0));
        if ($leadLogId < 1 && $existing) {
            $leadLogId = max(0, (int)($existing['lead_log_id'] ?? 0));
        }

        $rowData = [
            'lead_id' => $leadId,
            'account_id' => $accountId,
            'lead_log_id' => $leadLogId,
            'uuid' => mb_substr($uuid, 0, 64),
            'username' => mb_substr($username, 0, 128),
            'message' => $message,
            'error_message' => mb_substr($errorMessage, 0, 512),
            'status' => $status,
        ];

        $updated = false;
        if ($existing) {
            $id = (int)$existing['id'];
            try {
                DB::query(
                    "UPDATE ai_lead_records
                     SET lead_log_id=?, uuid=?, username=?, message=?, error_message=?, status=?,
                         updated_at=CURRENT_TIMESTAMP
                     WHERE id=?",
                    [
                        $rowData['lead_log_id'],
                        $rowData['uuid'],
                        $rowData['username'],
                        $rowData['message'],
                        $rowData['error_message'],
                        $rowData['status'],
                        $id,
                    ]
                );
            } catch (\Throwable $e) {
                unset($rowData['error_message']);
                if ($errorMessage !== '') {
                    $rowData['message'] = trim($message . "\n[" . $errorMessage . ']');
                }
                DB::query(
                    "UPDATE ai_lead_records
                     SET lead_log_id=?, uuid=?, username=?, message=?, status=?, updated_at=CURRENT_TIMESTAMP
                     WHERE id=?",
                    [
                        $rowData['lead_log_id'],
                        $rowData['uuid'],
                        $rowData['username'],
                        $rowData['message'],
                        $rowData['status'],
                        $id,
                    ]
                );
            }
            $updated = true;
        } else {
            try {
                $id = (int)DB::table('ai_lead_records')->insert($rowData);
            } catch (\Throwable $e) {
                unset($rowData['error_message']);
                if ($errorMessage !== '') {
                    $rowData['message'] = trim($message . "\n[" . $errorMessage . ']');
                }
                $id = (int)DB::table('ai_lead_records')->insert($rowData);
            }
        }

        $dailyLimit = AiLeadVideoWeightService::getDailyLimit($accountId);
        self::refreshLeadDmStats($leadId);
        return [
            'id' => $id,
            'status' => $status,
            'updated' => $updated,
            'bumped' => $bumped,
            'usedToday' => $usedToday,
            'remaining' => max(0, $dailyLimit - $usedToday),
            'dailyLimit' => $dailyLimit,
            'errorMessage' => $errorMessage,
        ];
    }

    /**
     * 当日私信次数 +1（不超过账号当日上限）。
     * @return array{bumped:bool,times:int,remaining:int,dailyLimit:int}
     */
    public static function bumpDailyDm(int $accountId): array
    {
        self::ensureSchema();
        AiLeadVideoWeightService::ensureSchema();
        $limit = AiLeadVideoWeightService::getDailyLimit($accountId);
        $today = date('Y-m-d');
        $row = DB::selectOne(
            'SELECT id, times, daily_limit FROM ai_lead_account_daily WHERE account_id=? AND date=?',
            [$accountId, $today]
        );
        if (!$row) {
            AiLeadVideoWeightService::refreshDailyQuota($accountId, $today);
            $row = DB::selectOne(
                'SELECT id, times, daily_limit FROM ai_lead_account_daily WHERE account_id=? AND date=?',
                [$accountId, $today]
            );
        }
        if (!$row) {
            try {
                DB::query(
                    'INSERT INTO ai_lead_account_daily (account_id, times, daily_limit, date) VALUES (?,?,?,?)',
                    [$accountId, 1, $limit, $today]
                );
                return ['bumped' => true, 'times' => 1, 'remaining' => max(0, $limit - 1), 'dailyLimit' => $limit];
            } catch (\Throwable $e) {
                $row = DB::selectOne(
                    'SELECT id, times, daily_limit FROM ai_lead_account_daily WHERE account_id=? AND date=?',
                    [$accountId, $today]
                );
            }
        }
        $storedLimit = (int)($row['daily_limit'] ?? 0);
        if ($storedLimit > 0) {
            $limit = $storedLimit;
        }
        $times = (int)($row['times'] ?? 0);
        if ($times >= $limit) {
            return ['bumped' => false, 'times' => $times, 'remaining' => 0, 'dailyLimit' => $limit];
        }
        DB::query(
            'UPDATE ai_lead_account_daily SET times=times+1, daily_limit=IF(daily_limit>0,daily_limit,?), updated_at=CURRENT_TIMESTAMP WHERE id=? AND times<?',
            [$limit, (int)$row['id'], $limit]
        );
        $fresh = DB::selectOne('SELECT times FROM ai_lead_account_daily WHERE id=?', [(int)$row['id']]);
        $newTimes = (int)($fresh['times'] ?? ($times + 1));
        $bumped = $newTimes > $times;
        return [
            'bumped' => $bumped,
            'times' => $newTimes,
            'remaining' => max(0, $limit - $newTimes),
            'dailyLimit' => $limit,
        ];
    }

    /**
     * 仅用于视频/用户搜索入库的创作者，不含评论线索影子行。
     *
     * @param non-empty-string $alias ai_lead_creators 表别名
     */
    public static function scrapedVideoCreatorFilterSql(string $alias = 'c'): string
    {
        return "(
            EXISTS (SELECT 1 FROM ai_lead_videos v WHERE v.creator_id={$alias}.id AND v.lead_id={$alias}.lead_id)
            OR {$alias}.followers > 0
            OR {$alias}.likes > 0
            OR {$alias}.account_id > 0
        )";
    }

    /**
     * @deprecated 评论意向分仅存 ai_lead_comments；勿再将评论用户写入 ai_lead_creators
     */
    public static function bumpUserIntentScore(
        int $leadId,
        string $username,
        string $nickname = '',
        string $profileUrl = '',
        int $score = 0
    ): void {
        self::ensureSchema();
        $username = trim($username);
        $score = AiLeadFilterService::normalizeIntentScore($score);
        if ($username === '' || $score < 1) {
            return;
        }
        $uuid = 'u_' . md5(strtolower($username));
        $nickname = mb_substr(trim($nickname), 0, 255);
        $profileUrl = mb_substr(trim($profileUrl), 0, 512);
        if ($profileUrl === '') {
            $profileUrl = 'https://www.tiktok.com/@' . ltrim($username, '@');
        }
        $exist = DB::selectOne(
            'SELECT id, intent_score, nickname, profile_url FROM ai_lead_creators WHERE lead_id=? AND uuid=? LIMIT 1',
            [$leadId, $uuid]
        );
        if (!$exist) {
            $exist = DB::selectOne(
                'SELECT id, intent_score, nickname, profile_url FROM ai_lead_creators WHERE lead_id=? AND LOWER(username)=LOWER(?) LIMIT 1',
                [$leadId, $username]
            );
        }
        if ($exist) {
            $cur = (int)($exist['intent_score'] ?? 0);
            $newScore = max($cur, $score);
            $nick = trim((string)($exist['nickname'] ?? '')) !== '' ? (string)$exist['nickname'] : $nickname;
            $purl = trim((string)($exist['profile_url'] ?? '')) !== '' ? (string)$exist['profile_url'] : $profileUrl;
            DB::query(
                'UPDATE ai_lead_creators SET intent_score=?, nickname=?, profile_url=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$newScore, mb_substr($nick !== '' ? $nick : $username, 0, 255), $purl, (int)$exist['id']]
            );
            return;
        }
        DB::table('ai_lead_creators')->insert([
            'lead_id' => $leadId,
            'account_id' => 0,
            'lead_log_id' => 0,
            'uuid' => $uuid,
            'username' => mb_substr($username, 0, 128),
            'nickname' => $nickname !== '' ? $nickname : mb_substr($username, 0, 255),
            'avatar' => '',
            'profile_url' => $profileUrl,
            'signature' => '',
            'followers' => 0,
            'following' => 0,
            'likes' => 0,
            'region' => '',
            'status' => 'new',
            'intent_score' => $score,
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function upsertCreator(int $leadId, array $data): array
    {
        self::ensureSchema();
        $accountId = max(0, (int)($data['accountId'] ?? $data['account_id'] ?? 0));
        $username = trim((string)($data['username'] ?? ''));
        $uuid = trim((string)($data['uuid'] ?? ''));
        if ($uuid === '' && $username !== '') {
            $uuid = 'u_' . md5(strtolower($username));
        }
        if ($uuid === '') {
            throw new \InvalidArgumentException('缺少用户标识');
        }
        $intentScore = AiLeadFilterService::normalizeIntentScore(
            $data['intent_score'] ?? $data['intentScore'] ?? 0
        );
        $row = [
            'lead_id' => $leadId,
            'account_id' => $accountId,
            'lead_log_id' => 0,
            'uuid' => mb_substr($uuid, 0, 64),
            'username' => mb_substr($username, 0, 128),
            'nickname' => mb_substr(trim((string)($data['nickname'] ?? '')), 0, 255),
            'avatar' => mb_substr(trim((string)($data['avatar'] ?? '')), 0, 512),
            'profile_url' => mb_substr(trim((string)($data['profileUrl'] ?? $data['profile_url'] ?? '')), 0, 512),
            'signature' => trim((string)($data['signature'] ?? '')),
            'followers' => max(0, (int)($data['followers'] ?? 0)),
            'following' => max(0, (int)($data['following'] ?? 0)),
            'likes' => max(0, (int)($data['likes'] ?? 0)),
            'region' => mb_substr(trim((string)($data['region'] ?? '')), 0, 64),
            'status' => 'new',
            'intent_score' => $intentScore,
        ];
        $exist = DB::selectOne('SELECT id, intent_score FROM ai_lead_creators WHERE lead_id=? AND uuid=?', [$leadId, $row['uuid']]);
        if (!$exist && $username !== '') {
            $exist = DB::selectOne(
                'SELECT id, intent_score FROM ai_lead_creators WHERE lead_id=? AND LOWER(username)=LOWER(?) LIMIT 1',
                [$leadId, $username]
            );
        }
        if ($exist) {
            $keepScore = max((int)($exist['intent_score'] ?? 0), $intentScore);
            DB::query(
                'UPDATE ai_lead_creators SET account_id=?, uuid=?, username=?, nickname=?, avatar=?, profile_url=?, signature=?, followers=?, following=?, likes=?, region=?, intent_score=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [
                    $row['account_id'], $row['uuid'], $row['username'], $row['nickname'], $row['avatar'], $row['profile_url'],
                    $row['signature'], $row['followers'], $row['following'], $row['likes'], $row['region'], $keepScore,
                    (int)$exist['id'],
                ]
            );
            $id = (int)$exist['id'];
        } else {
            $id = (int)DB::table('ai_lead_creators')->insert($row);
            self::bumpStat($leadId, 'creators', 1);
        }
        return ['id' => $id, 'uuid' => $row['uuid']];
    }

    /** @param array<string,mixed> $data */
    public static function upsertVideo(int $leadId, array $data): array
    {
        self::ensureSchema();
        $url = self::normalizeVideoUrl((string)($data['videoUrl'] ?? $data['video_url'] ?? ''));
        if ($url === '') {
            throw new \InvalidArgumentException('缺少视频地址');
        }
        $creatorId = max(0, (int)($data['creatorId'] ?? $data['creator_id'] ?? 0));
        $accountId = max(0, (int)($data['accountId'] ?? $data['account_id'] ?? 0));
        $videoIdKey = self::extractVideoId($url);
        $hasViews = array_key_exists('views', $data) || array_key_exists('view', $data);
        $hasLikes = array_key_exists('likes', $data);
        $viewsIn = $hasViews ? max(0, (int)($data['views'] ?? $data['view'] ?? 0)) : null;
        $likesIn = $hasLikes ? max(0, (int)($data['likes'] ?? 0)) : null;
        $cover = mb_substr(trim((string)($data['videoCover'] ?? $data['video_cover'] ?? '')), 0, 512);
        $name = mb_substr(trim((string)($data['videoName'] ?? $data['video_name'] ?? '')), 0, 255);

        $exist = DB::selectOne('SELECT id, likes, views, creator_id, account_id, video_cover, video_name FROM ai_lead_videos WHERE lead_id=? AND video_url=?', [$leadId, $url]);
        if (!$exist && $videoIdKey !== '') {
            $exist = DB::selectOne(
                'SELECT id, likes, views, creator_id, account_id, video_cover, video_name FROM ai_lead_videos WHERE lead_id=? AND video_url LIKE ? LIMIT 1',
                [$leadId, '%/video/' . $videoIdKey . '%']
            );
        }

        // 更新时未传的字段保留原值；点赞与浏览量分开存（详情页可只回填 likes）
        $views = $viewsIn !== null ? $viewsIn : (int)($exist['views'] ?? 0);
        $likes = $likesIn !== null ? $likesIn : (int)($exist['likes'] ?? 0);
        if ($creatorId < 1) {
            $creatorId = (int)($exist['creator_id'] ?? 0);
        }
        if ($accountId < 1) {
            $accountId = (int)($exist['account_id'] ?? 0);
        }
        if ($cover === '') {
            $cover = (string)($exist['video_cover'] ?? '');
        }
        if ($name === '') {
            $name = (string)($exist['video_name'] ?? '');
        }

        $row = [
            'lead_id' => $leadId,
            'creator_id' => $creatorId,
            'account_id' => $accountId,
            'video_url' => $url,
            'video_cover' => $cover,
            'video_name' => $name,
            'likes' => $likes,
            'views' => $views,
        ];
        if ($exist) {
            try {
                DB::query(
                    'UPDATE ai_lead_videos SET creator_id=?, account_id=?, video_url=?, video_cover=?, video_name=?, likes=?, views=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$row['creator_id'], $row['account_id'], $row['video_url'], $row['video_cover'], $row['video_name'], $row['likes'], $row['views'], (int)$exist['id']]
                );
            } catch (\Throwable $e) {
                // 无 views 列时：仅能存 likes；浏览量优先写入 likes 列（旧表兼容）
                DB::query(
                    'UPDATE ai_lead_videos SET creator_id=?, account_id=?, video_url=?, video_cover=?, video_name=?, likes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$row['creator_id'], $row['account_id'], $row['video_url'], $row['video_cover'], $row['video_name'], $row['views'] ?: $row['likes'], (int)$exist['id']]
                );
            }
            $id = (int)$exist['id'];
        } else {
            try {
                $id = (int)DB::table('ai_lead_videos')->insert($row);
            } catch (\Throwable $e) {
                unset($row['views']);
                $row['likes'] = $views ?: $likes;
                $id = (int)DB::table('ai_lead_videos')->insert($row);
            }
            self::bumpStat($leadId, 'videos', 1);
        }
        // 兼容旧明细：同步写入 ai_lead_logs（去重）
        try {
            $logExist = DB::selectOne(
                'SELECT id FROM ai_lead_logs WHERE lead_id=? AND video_url=? LIMIT 1',
                [$leadId, $row['video_url']]
            );
            if (!$logExist && $videoIdKey !== '') {
                $logExist = DB::selectOne(
                    'SELECT id FROM ai_lead_logs WHERE lead_id=? AND video_url LIKE ? LIMIT 1',
                    [$leadId, '%/video/' . $videoIdKey . '%']
                );
            }
            if ($logExist) {
                DB::query(
                    'UPDATE ai_lead_logs SET video_url=?, video_cover=?, video_name=? WHERE id=?',
                    [$row['video_url'], $row['video_cover'], $row['video_name'], (int)$logExist['id']]
                );
            } else {
                DB::query(
                    'INSERT INTO ai_lead_logs (lead_id, video_url, video_cover, video_name, comment) VALUES (?,?,?,?,?)',
                    [$leadId, $row['video_url'], $row['video_cover'], $row['video_name'], '']
                );
            }
        } catch (\Throwable $e) {
        }
        return ['id' => $id, 'videoUrl' => $row['video_url']];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{saved:int,updated:int,ids:list<int>}
     */
    public static function saveComments(int $leadId, int $videoId, array $items, bool $aiPass = true): array
    {
        self::ensureSchema();
        $ids = [];
        $inserted = 0;
        $updated = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $username = trim((string)($item['username'] ?? ''));
            $text = trim((string)($item['comment_text'] ?? $item['commentText'] ?? $item['text'] ?? ''));
            if ($username === '' && $text === '') {
                continue;
            }
            $uuid = trim((string)($item['uuid'] ?? ''));
            if ($uuid === '') {
                $uuid = 'c_' . md5(strtolower($username . '|' . mb_substr($text, 0, 80)));
            }
            $uuid = mb_substr($uuid, 0, 64);
            $nickname = mb_substr(trim((string)($item['nickname'] ?? '')), 0, 255);
            $profileUrl = mb_substr(trim((string)($item['profile_url'] ?? $item['profileUrl'] ?? '')), 0, 512);
            $reason = mb_substr(trim((string)($item['reason'] ?? $item['ai_reason'] ?? $item['aiReason'] ?? '')), 0, 512);
            $textZh = trim((string)(
                $item['comment_text_zh'] ?? $item['commentTextZh'] ?? $item['comment_text_zh_cn'] ?? ''
            ));
            $pass = $aiPass ? 1 : 0;
            $intentScore = AiLeadFilterService::normalizeIntentScore(
                $item['intent_score'] ?? $item['intentScore'] ?? $item['score'] ?? 0
            );
            if ($pass && $intentScore < 1) {
                $intentScore = AiLeadFilterService::guessIntentScore($text);
            }

            $exist = DB::selectOne(
                'SELECT id, ai_pass FROM ai_lead_comments WHERE lead_id=? AND uuid=? LIMIT 1',
                [$leadId, $uuid]
            );
            if (!$exist && $username !== '' && $text !== '') {
                $exist = DB::selectOne(
                    'SELECT id, ai_pass FROM ai_lead_comments WHERE lead_id=? AND video_id=? AND LOWER(username)=LOWER(?) AND comment_text=? LIMIT 1',
                    [$leadId, $videoId, $username, $text]
                );
            }
            $industryId = self::leadIndustryId($leadId);
            if ($exist) {
                DB::query(
                    'UPDATE ai_lead_comments SET video_id=?, uuid=?, username=?, nickname=?, profile_url=?, comment_text=?, comment_text_zh=?, ai_pass=?, ai_reason=?, intent_score=?, industry_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [
                        $videoId, $uuid, mb_substr($username, 0, 128), $nickname, $profileUrl,
                        $text, $textZh !== '' ? $textZh : null, $pass, $reason, $intentScore,
                        $industryId, (int)$exist['id'],
                    ]
                );
                $cid = (int)$exist['id'];
                $ids[] = $cid;
                $updated++;
            } else {
                $cid = (int)DB::table('ai_lead_comments')->insert([
                    'lead_id' => $leadId,
                    'video_id' => $videoId,
                    'uuid' => $uuid,
                    'username' => mb_substr($username, 0, 128),
                    'nickname' => $nickname,
                    'profile_url' => $profileUrl,
                    'comment_text' => $text,
                    'comment_text_zh' => $textZh !== '' ? $textZh : null,
                    'ai_pass' => $pass,
                    'ai_reason' => $reason,
                    'intent_score' => $intentScore,
                    'industry_id' => $industryId,
                ]);
                $ids[] = $cid;
                $inserted++;
            }
            if ($pass) {
                self::syncCommentSharedUsers($cid, $leadId);
            }
        }
        if ($inserted > 0 && $aiPass) {
            self::bumpStat($leadId, 'leads', $inserted);
        }
        return ['saved' => count($ids), 'inserted' => $inserted, 'updated' => $updated, 'ids' => $ids];
    }

    /**
     * @param array<string,mixed> $progress
     * @return array{aborted:bool,reason:string,status:string,run_status:string}
     */
    public static function updateProgress(int $leadId, array $progress, ?array $stats = null): array
    {
        self::ensureSchema();
        $lead = DB::selectOne('SELECT id, status, run_status FROM ai_leads WHERE id=?', [$leadId]);
        if (!$lead) {
            return ['aborted' => true, 'reason' => '任务不存在', 'status' => '', 'run_status' => ''];
        }
        $taskStatus = strtolower(trim((string)($lead['status'] ?? '')));
        $runStatus = strtolower(trim((string)($lead['run_status'] ?? '')));
        $phase = strtolower(trim((string)($progress['phase'] ?? '')));
        $manualDmTest = $phase === 'dm_test'
            || !empty($progress['testMode'])
            || (is_array($stats) && !empty($stats['testMode']));
        // 已暂停/停用：自动拓客流水线应退出；手动「测试/重试私信」仍允许执行
        if (!$manualDmTest && in_array($taskStatus, ['paused', 'draft'], true)) {
            return [
                'aborted' => true,
                'reason' => $taskStatus === 'paused' ? '任务已暂停' : '任务已停用',
                'status' => $taskStatus,
                'run_status' => $runStatus,
            ];
        }

        $sets = ['progress_json=?'];
        $params = [json_encode($progress, JSON_UNESCAPED_UNICODE)];
        if ($stats !== null) {
            $sets[] = 'stats_json=?';
            $params[] = json_encode($stats, JSON_UNESCAPED_UNICODE);
        }
        if (
            $phase !== ''
            && $phase !== 'queued'
            && $phase !== 'done'
            && $phase !== 'failed'
            && $phase !== 'aborted'
            && !($manualDmTest && in_array($taskStatus, ['paused', 'draft'], true))
        ) {
            $sets[] = "run_status='running'";
        }
        $params[] = $leadId;
        DB::query(
            'UPDATE ai_leads SET ' . implode(', ', $sets) . ', updated_at=CURRENT_TIMESTAMP WHERE id=?',
            $params
        );
        return [
            'aborted' => false,
            'reason' => '',
            'status' => $taskStatus,
            'run_status' => 'running',
        ];
    }

    public static function markRunFinished(int $leadId, bool $success, ?array $result = null): void
    {
        self::ensureSchema();
        $lead = DB::selectOne('SELECT status, stats_json FROM ai_leads WHERE id=?', [$leadId]);
        if (!$lead) {
            return;
        }
        $stats = json_decode((string)($lead['stats_json'] ?? ''), true);
        if (!is_array($stats)) {
            $stats = [];
        }
        if (is_array($result)) {
            foreach ([
                'creators', 'videos', 'comments', 'leads',
                'dmsSent', 'dmsFailed', 'dmsSkipped', 'dmsNeeded',
                'accountsBound', 'accountsUsed', 'accountsNeeded', 'accountsMissing',
                'capacityToday',
            ] as $k) {
                if (isset($result[$k])) {
                    $stats[$k] = (int)$result[$k];
                }
            }
            if (isset($result['summary'])) {
                $stats['summary'] = $result['summary'];
            }
            if (!empty($result['dmsPending'])) {
                $stats['dmsPending'] = (int)$result['dmsPending'];
            }
        }
        if ($success) {
            $dmProg = self::dmProgressStats($leadId);
            $stats['dmsPending'] = $dmProg['dmsPending'];
            $stats['dmsSent'] = $dmProg['dmsSent'];
            $stats['dmsNeeded'] = max((int)($stats['dmsNeeded'] ?? 0), $dmProg['dmsNeeded']);
        }
        $taskStatus = (string)($lead['status'] ?? 'active');
        if ($success && $taskStatus === 'active') {
            if (self::hasPendingDmTargets($leadId)) {
                $taskStatus = 'active';
            } else {
                $taskStatus = 'completed';
            }
        }
        $aborted = is_array($result) && !empty($result['aborted']);
        // 暂停/终止：保持任务状态，run_status 回 idle，避免被标成 failed
        if ($aborted || in_array($taskStatus, ['paused', 'draft'], true)) {
            $runStatus = 'idle';
            $phase = 'aborted';
        } else {
            if ($success && self::hasPendingDmTargets($leadId)) {
                $runStatus = 'idle';
                $phase = 'scrape_done';
            } else {
                $runStatus = $success ? 'done' : 'failed';
                $phase = $success ? 'done' : 'failed';
            }
        }
        DB::query(
            "UPDATE ai_leads SET run_status=?, status=?, stats_json=?, progress_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [
                $runStatus,
                $taskStatus,
                json_encode($stats, JSON_UNESCAPED_UNICODE),
                json_encode(['phase' => $phase, 'result' => $result], JSON_UNESCAPED_UNICODE),
                $leadId,
            ]
        );
    }

    private static function bumpStat(int $leadId, string $key, int $delta): void
    {
        $lead = DB::selectOne('SELECT stats_json FROM ai_leads WHERE id=?', [$leadId]);
        $stats = json_decode((string)($lead['stats_json'] ?? ''), true);
        if (!is_array($stats)) {
            $stats = [];
        }
        $stats[$key] = (int)($stats[$key] ?? 0) + max(0, $delta);
        DB::query(
            'UPDATE ai_leads SET stats_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [json_encode($stats, JSON_UNESCAPED_UNICODE), $leadId]
        );
    }
}
