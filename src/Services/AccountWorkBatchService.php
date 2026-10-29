<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 账号浏览器会话总任务编排：
 * 账号(1) → account_work_batches(N) → account_work_items(N) → 各类任务主表
 *
 * 规则：
 * - 存在 pending/running 总任务时，新分任务挂到该总任务
 * - 否则新建总任务
 * - 登录 / 登出是会话钩子，不是分任务
 * - 比特环境：账号可绑多个；领取总任务时占用空闲环境(busy)，结束/异常释放(idle)
 * - 调度：优先有空闲环境的账号；无空闲则等待；Node 并发由设置 taskConcurrency 控制（默认 6，范围 3–20）
 *
 * 会话内执行顺序（priority 越小越先）：
 * 登录 → 客服/拓客 → 修改资料 → 发布视频 → … → 登出
 *
 * batch_scope：
 * - general：发布/客服/养号等常规总任务（可合并追加）
 * - ai_lead：AI 拓客独立总任务（不与 general 合并）
 *
 * 同账号调度：general 与 ai_lead 独立总任务，可分别推送；执行时 ai_lead 等环境空闲，不抢占常规任务浏览器。
 */
class AccountWorkBatchService
{
    public const BATCH_SCOPE_GENERAL = 'general';
    public const BATCH_SCOPE_AI_LEAD = 'ai_lead';
    /**
     * 允许使用服务器浏览器（Linux 常驻服务）的分任务类型。
     * 架构约定：服务器浏览器只跑长期占用的公共任务；多账号客服（坐席）对接后在此追加。
     */
    public const SERVER_BROWSER_TASK_TYPES = ['ai_lead', 'ai_lead_dm'];
    /** @var array<string,int> 越小越先执行 */
    public const TYPE_PRIORITY = [
        'modify_profile' => 5,  // 修改资料（紧接登录后）
        'publish' => 10,        // 发布视频
        'delete_video' => 20,   // 删除视频
        'comment_reply' => 30,  // 回复评论
        'pull_comments' => 32,  // 拉取视频数据
        'dm_reply' => 40,       // 回复私信
        'message' => 40,        // 私信类（与回复私信同序）
        'ai_lead' => 45,        // AI拓客
        'ai_lead_dm' => 46,     // AI拓客·私信测试（独立）
        'auto_follow' => 48,    // 自动增粉
        'warm' => 50,           // 养号操作
        'diagnose' => 55,       // 账号诊断截图（养号后可选）
        'dm_sync' => 60,
        'sync_fans' => 70,
        'pull_stats' => 75,     // 拉取 Studio 数据（粉丝/获赞/28天指标）
        'sync' => 80,
        'profile' => 90,        // 兼容旧「同步资料」类型，执行侧改为拉取
    ];

    /** 需要走浏览器账号会话的任务类型 */
    public const BROWSER_TYPES = [
        'modify_profile', 'publish', 'delete_video', 'comment_reply', 'pull_comments', 'dm_reply', 'message', 'ai_lead', 'ai_lead_dm', 'auto_follow', 'warm',
        'diagnose', 'dm_sync', 'sync_fans', 'pull_stats', 'sync', 'profile',
    ];

    public static function ensureSchema(): void
    {
        static $tablesDone = false;
        if (!$tablesDone) {
            $tablesDone = true;
            self::ensureBaseTables();
        }
        self::ensureBatchColumns();
        self::ensureItemColumns();
        self::backfillOwnerIds();
    }

    private static function ensureBaseTables(): void
    {
        DB::query(
            "CREATE TABLE IF NOT EXISTS account_work_batches (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              owner_id INT NOT NULL DEFAULT 0,
              batch_scope VARCHAR(32) NOT NULL DEFAULT 'general',
              status VARCHAR(32) NOT NULL DEFAULT 'pending',
              locked_by VARCHAR(128) NOT NULL DEFAULT '',
              lease_until DATETIME NULL,
              item_total INT NOT NULL DEFAULT 0,
              item_success INT NOT NULL DEFAULT 0,
              item_fail INT NOT NULL DEFAULT 0,
              last_error TEXT NULL,
              started_at DATETIME NULL,
              finished_at DATETIME NULL,
              heartbeat_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_awb_account_status (account_id, status),
              KEY idx_awb_status_lease (status, lease_until),
              KEY idx_awb_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        DB::query(
            "CREATE TABLE IF NOT EXISTS account_work_items (
              id INT AUTO_INCREMENT PRIMARY KEY,
              batch_id INT NOT NULL,
              account_id INT NOT NULL,
              owner_id INT NOT NULL DEFAULT 0,
              task_type VARCHAR(50) NOT NULL,
              task_ref_id INT NOT NULL DEFAULT 0,
              priority INT NOT NULL DEFAULT 100,
              seq INT NOT NULL DEFAULT 0,
              status VARCHAR(32) NOT NULL DEFAULT 'pending',
              payload_json MEDIUMTEXT NULL,
              result_json MEDIUMTEXT NULL,
              error_message TEXT NULL,
              original_username VARCHAR(128) NULL DEFAULT NULL,
              username_fail_reason TEXT NULL,
              nickname_error TEXT NULL,
              nickname_locked_at DATETIME NULL DEFAULT NULL,
              started_at DATETIME NULL,
              finished_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_awi_batch_status (batch_id, status, priority, seq, id),
              KEY idx_awi_account (account_id, status),
              KEY idx_awi_task_ref (task_type, task_ref_id),
              KEY idx_awi_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 纠正未执行分任务的优先级，对齐当前会话顺序
        foreach (self::TYPE_PRIORITY as $type => $priority) {
            try {
                DB::query(
                    "UPDATE account_work_items SET priority=?, updated_at=CURRENT_TIMESTAMP
                     WHERE task_type=? AND status IN ('pending','running') AND priority<>?",
                    [$priority, $type, $priority]
                );
            } catch (\Throwable $e) {
            }
        }

        AccountBrowserEnvService::ensureSchema();

        DB::query(
            "CREATE TABLE IF NOT EXISTS account_work_logs (
              id BIGINT AUTO_INCREMENT PRIMARY KEY,
              batch_id INT NOT NULL DEFAULT 0,
              item_id INT NOT NULL DEFAULT 0,
              account_id INT NOT NULL DEFAULT 0,
              task_id INT NOT NULL DEFAULT 0,
              level VARCHAR(16) NOT NULL DEFAULT 'info',
              message TEXT NOT NULL,
              meta_json MEDIUMTEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_awl_item_time (item_id, id),
              KEY idx_awl_batch_time (batch_id, id),
              KEY idx_awl_account_time (account_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 「修改资料」逐字段执行记录（用户名/昵称/签名/头像 各自成功与否及原因）
        DB::query(
            "CREATE TABLE IF NOT EXISTS account_profile_change_logs (
              id BIGINT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              owner_id INT NOT NULL DEFAULT 0,
              batch_id INT NOT NULL DEFAULT 0,
              item_id INT NOT NULL DEFAULT 0,
              task_id INT NOT NULL DEFAULT 0,
              status VARCHAR(16) NOT NULL DEFAULT 'ok',
              message TEXT NULL,
              screenshot VARCHAR(191) NOT NULL DEFAULT '',
              username_target VARCHAR(128) NOT NULL DEFAULT '',
              username_original VARCHAR(128) NOT NULL DEFAULT '',
              username_requested TINYINT(1) NOT NULL DEFAULT 0,
              username_ok TINYINT(1) NOT NULL DEFAULT 0,
              username_reason TEXT NULL,
              nickname_target VARCHAR(255) NOT NULL DEFAULT '',
              nickname_original VARCHAR(255) NOT NULL DEFAULT '',
              nickname_requested TINYINT(1) NOT NULL DEFAULT 0,
              nickname_ok TINYINT(1) NOT NULL DEFAULT 0,
              nickname_reason TEXT NULL,
              bio_target VARCHAR(500) NOT NULL DEFAULT '',
              bio_requested TINYINT(1) NOT NULL DEFAULT 0,
              bio_ok TINYINT(1) NOT NULL DEFAULT 0,
              bio_reason TEXT NULL,
              avatar_target VARCHAR(500) NOT NULL DEFAULT '',
              avatar_requested TINYINT(1) NOT NULL DEFAULT 0,
              avatar_ok TINYINT(1) NOT NULL DEFAULT 0,
              avatar_reason TEXT NULL,
              operator_id INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_apcl_account_time (account_id, id),
              KEY idx_apcl_item (item_id),
              KEY idx_apcl_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** 每次请求都校验列（避免 PHP 常驻进程 static 跳过迁移） */
    private static function ensureBatchColumns(): void
    {
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM account_work_batches') ?: [], 'Field');
            if (!$cols) {
                return;
            }
            if (!in_array('deleted_at', $cols, true)) {
                DB::query('ALTER TABLE account_work_batches ADD COLUMN deleted_at DATETIME NULL AFTER finished_at');
                DB::query('ALTER TABLE account_work_batches ADD KEY idx_awb_deleted (deleted_at)');
                $cols[] = 'deleted_at';
            }
            if (!in_array('push_status', $cols, true)) {
                DB::query("ALTER TABLE account_work_batches ADD COLUMN push_status VARCHAR(32) NOT NULL DEFAULT 'none' AFTER status");
                DB::query('ALTER TABLE account_work_batches ADD KEY idx_awb_push (push_status, status)');
                $cols[] = 'push_status';
            }
            if (!in_array('batch_scope', $cols, true)) {
                self::addBatchScopeColumn();
            }
        } catch (\Throwable $e) {
            error_log('[AccountWorkBatchService] ensureBatchColumns: ' . $e->getMessage());
        }
    }

    /** 修改资料分任务：原 Username / 失败原因 / 昵称 7 天限制字段 */
    private static function ensureItemColumns(): void
    {
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM account_work_items') ?: [], 'Field');
            if (!$cols) {
                return;
            }
            $alters = [
                'original_username' => "ALTER TABLE account_work_items ADD COLUMN original_username VARCHAR(128) NULL DEFAULT NULL COMMENT '修改资料：原账号名称 Username' AFTER error_message",
                'username_fail_reason' => "ALTER TABLE account_work_items ADD COLUMN username_fail_reason TEXT NULL COMMENT '修改资料：账号名称修改失败原因' AFTER original_username",
                'nickname_error' => "ALTER TABLE account_work_items ADD COLUMN nickname_error TEXT NULL COMMENT '修改资料：昵称错误提示' AFTER username_fail_reason",
                'nickname_locked_at' => "ALTER TABLE account_work_items ADD COLUMN nickname_locked_at DATETIME NULL DEFAULT NULL COMMENT '修改资料：昵称触发7天限制时间' AFTER nickname_error",
            ];
            foreach ($alters as $col => $sql) {
                if (!in_array($col, $cols, true)) {
                    try {
                        DB::query($sql);
                        $cols[] = $col;
                    } catch (\Throwable $e) {
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[AccountWorkBatchService] ensureItemColumns: ' . $e->getMessage());
        }
    }

    /**
     * 修改资料分任务：原 Username / 失败原因 / 昵称 7 天限制字段。
     *
     * 除了把明细写进 account_work_items，还负责把账号表恢复到 TikTok 的真实状态：
     * - Username 被占用 → accounts.username 恢复原账号名（否则头像/昵称也提交不了）
     * - 昵称触发 7 天限制 → accounts.nickname 恢复原昵称，并记录 nickname_changed_at
     * - 昵称本次改成功 → 同样记录 nickname_changed_at，供「修改资料」弹框禁用输入
     * 这样即使 node 端 /profile-outcome 回写失败，账号表也不会停在错误状态。
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $result
     */
    private static function applyModifyProfileItemMeta(
        int $itemId,
        array $payload,
        ?array $result = null,
        int $accountId = 0
    ): void {
        if ($itemId < 1) {
            return;
        }
        self::ensureItemColumns();
        $sets = [];
        $bind = [];

        $original = trim((string)($payload['originalUsername'] ?? $payload['original_username'] ?? ''));
        if ($original === '' && is_array($result)) {
            $original = trim((string)($result['originalUsername'] ?? $result['original_username'] ?? ''));
        }
        $original = ltrim($original, '@');

        $hasOriginalNick = array_key_exists('originalNickname', $payload)
            || array_key_exists('original_nickname', $payload);
        $originalNick = $hasOriginalNick
            ? trim((string)($payload['originalNickname'] ?? $payload['original_nickname'] ?? ''))
            : '';
        $wantedNick = trim((string)($payload['nickname'] ?? $payload['displayName'] ?? ''));
        $nickRequested = $wantedNick !== '' && (!$hasOriginalNick || $wantedNick !== $originalNick);

        $failReason = '';
        $nickErr = '';
        $lockedAt = '';
        $nickLocked = false;
        $nickSucceeded = false;
        $freshRun = false;
        // 本次是否真的把新 Username 提交成功（成功后原账号名作废，登录应改用新名）
        $wantedUsername = ltrim(trim((string)($payload['username'] ?? $payload['handle'] ?? '')), '@');
        $usernameConfirmed = false;

        if (is_array($result)) {
            $freshRun = $result !== [];
            $failReason = trim((string)($result['usernameFailReason'] ?? $result['username_fail_reason'] ?? ''));
            if ($failReason === '' && (!empty($result['usernameConflict']) || !empty($result['username_conflict']))) {
                $failReason = '账号名称已被占用，已恢复原账号名';
            }
            // 没有失败原因 = 新 Username 已在 TikTok 落地，原名不再用于登录
            $usernameConfirmed = $freshRun
                && $failReason === ''
                && $wantedUsername !== ''
                && ($original === '' || strcasecmp($wantedUsername, $original) !== 0);
            $nickErr = trim((string)($result['nicknameError'] ?? $result['nickname_error'] ?? ''));
            $lockedAt = trim((string)($result['nicknameLockedAt'] ?? $result['nickname_locked_at'] ?? ''));
            $nickLocked = !empty($result['nameLocked'])
                || !empty($result['name_locked'])
                || !empty($result['nicknameLocked'])
                || !empty($result['nickname_locked'])
                || $nickErr !== '';
            // node 端只在昵称真正写入后才回传 nickname；配合「确实请求改昵称」判断本次成功
            $nickSucceeded = $nickRequested && !$nickLocked && (
                !empty($result['nicknameSuccess'])
                || !empty($result['nickname_success'])
                || !empty($result['nicknameFilled'])
                || trim((string)($result['nickname'] ?? '')) !== ''
            );
            if ($nickSucceeded) {
                $nickLocked = false;
            }
        }

        if ($original !== '') {
            $sets[] = 'original_username=?';
            $bind[] = $original;
        }
        if ($failReason !== '') {
            $sets[] = 'username_fail_reason=?';
            $bind[] = $failReason;
        } elseif ($freshRun) {
            // 重试成功后清掉上一次失败残留
            $sets[] = 'username_fail_reason=NULL';
        }
        if ($nickErr !== '') {
            $sets[] = 'nickname_error=?';
            $bind[] = $nickErr;
        } elseif ($freshRun) {
            $sets[] = 'nickname_error=NULL';
        }
        if ($lockedAt !== '') {
            $sets[] = 'nickname_locked_at=?';
            $bind[] = $lockedAt;
        } elseif ($nickLocked) {
            $sets[] = 'nickname_locked_at=CURRENT_TIMESTAMP';
        } elseif ($freshRun) {
            $sets[] = 'nickname_locked_at=NULL';
        }

        if ($sets) {
            $bind[] = $itemId;
            try {
                DB::query(
                    'UPDATE account_work_items SET ' . implode(', ', $sets) . ', updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    $bind
                );
            } catch (\Throwable $e) {
            }
        }

        if (!$freshRun) {
            return;
        }
        if ($accountId < 1) {
            try {
                $row = DB::selectOne('SELECT account_id FROM account_work_items WHERE id=?', [$itemId]);
                $accountId = (int)($row['account_id'] ?? 0);
            } catch (\Throwable $e) {
                $accountId = 0;
            }
        }
        self::reconcileAccountAfterModifyProfile(
            $accountId,
            $original,
            $failReason,
            $nickLocked,
            $nickSucceeded,
            $hasOriginalNick,
            $originalNick,
            $usernameConfirmed
        );
    }

    /**
     * 把账号表恢复到 TikTok 的真实状态（幂等，失败不阻断分任务收尾）。
     */
    private static function reconcileAccountAfterModifyProfile(
        int $accountId,
        string $originalUsername,
        string $failReason,
        bool $nickLocked,
        bool $nickSucceeded,
        bool $hasOriginalNick,
        string $originalNick,
        bool $usernameConfirmed = false
    ): void {
        if ($accountId < 1) {
            return;
        }
        // 新 Username 已在 TikTok 生效：清掉备用原名，后续登录改用新名
        if ($usernameConfirmed) {
            try {
                DB::query('UPDATE accounts SET username_prev=NULL WHERE id=?', [$accountId]);
            } catch (\Throwable $e) {
            }
        }
        if ($failReason === '' && !$nickLocked && !$nickSucceeded) {
            return;
        }
        try {
            $row = DB::selectOne('SELECT id, username, nickname FROM accounts WHERE id=?', [$accountId]);
        } catch (\Throwable $e) {
            return;
        }
        if (!$row) {
            return;
        }
        $accSets = [];
        $accBind = [];

        if ($failReason !== '' && $originalUsername !== '') {
            $cur = ltrim((string)($row['username'] ?? ''), '@');
            if ($cur !== $originalUsername) {
                $dup = null;
                try {
                    $dup = DB::selectOne(
                        'SELECT id FROM accounts WHERE username=? AND id<>? LIMIT 1',
                        [$originalUsername, $accountId]
                    );
                } catch (\Throwable $e) {
                    $dup = null;
                }
                if (!$dup) {
                    $accSets[] = 'username=?';
                    $accBind[] = $originalUsername;
                }
            }
        }
        if ($nickLocked && $hasOriginalNick) {
            $accSets[] = 'nickname=?';
            $accBind[] = $originalNick;
        }
        if (($nickLocked || $nickSucceeded) && self::ensureAccountProfileColumns()) {
            $accSets[] = 'nickname_changed_at=CURRENT_TIMESTAMP';
        }
        if (!$accSets) {
            return;
        }
        $accSets[] = 'updated_at=CURRENT_TIMESTAMP';
        $accBind[] = $accountId;
        try {
            DB::query('UPDATE accounts SET ' . implode(', ', $accSets) . ' WHERE id=?', $accBind);
        } catch (\Throwable $e) {
        }
    }

    /** accounts.nickname_changed_at 是否存在（缺失则补列），用于昵称 7 天限制记录 */
    private static function ensureAccountProfileColumns(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            if (!$cols) {
                return $ok = false;
            }
            if (!in_array('nickname_changed_at', $cols, true)) {
                try {
                    DB::query('ALTER TABLE accounts ADD COLUMN nickname_changed_at DATETIME NULL DEFAULT NULL');
                } catch (\Throwable $e) {
                }
                $cols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            }
            $ok = in_array('nickname_changed_at', $cols, true);
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * accounts.username_prev：系统里被改掉的「原账号名」。
     * 改资料任务把新名字写进系统后，TikTok 那边还是旧名字，登录必须用旧名字，
     * 否则用新名字登录必然失败（新名字还没在 TikTok 生效）。
     */
    public static function ensureUsernamePrevColumn(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            if (!$cols) {
                return $ok = false;
            }
            if (!in_array('username_prev', $cols, true)) {
                try {
                    DB::query(
                        "ALTER TABLE accounts ADD COLUMN username_prev VARCHAR(128) NULL DEFAULT NULL COMMENT '改资料前原账号名（TikTok 实际仍在用）'"
                    );
                } catch (\Throwable $e) {
                }
                $cols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            }
            $ok = in_array('username_prev', $cols, true);
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    private static function addBatchScopeColumn(): void
    {
        try {
            DB::query(
                "ALTER TABLE account_work_batches
                 ADD COLUMN batch_scope VARCHAR(32) NOT NULL DEFAULT 'general'
                 COMMENT 'general|ai_lead'
                 AFTER owner_id"
            );
        } catch (\Throwable $e) {
            DB::query(
                "ALTER TABLE account_work_batches
                 ADD COLUMN batch_scope VARCHAR(32) NOT NULL DEFAULT 'general'"
            );
        }
        try {
            DB::query(
                'ALTER TABLE account_work_batches ADD KEY idx_awb_account_scope (account_id, batch_scope, status)'
            );
        } catch (\Throwable $e) {
            // 索引已存在
        }
    }

    /** 入队前强制确认 batch_scope 列存在 */
    private static function ensureBatchScopeReady(): void
    {
        self::ensureSchema();
        $cols = array_column(DB::select('SHOW COLUMNS FROM account_work_batches') ?: [], 'Field');
        if (in_array('batch_scope', $cols, true)) {
            return;
        }
        self::addBatchScopeColumn();
        $cols = array_column(DB::select('SHOW COLUMNS FROM account_work_batches') ?: [], 'Field');
        if (!in_array('batch_scope', $cols, true)) {
            throw new \RuntimeException('account_work_batches 缺少 batch_scope 列，请执行 migrations/056_account_work_batches_batch_scope.sql');
        }
    }

    /**
     * 写入执行日志（执行中实时落库）。
     * @param array<string,mixed>|null $meta
     */
    public static function appendLog(
        int $batchId,
        int $itemId,
        string $message,
        string $level = 'info',
        int $accountId = 0,
        int $taskId = 0,
        ?array $meta = null
    ): void {
        self::ensureSchema();
        $message = trim($message);
        if ($message === '') {
            return;
        }
        $level = strtolower(trim($level));
        if (!in_array($level, ['debug', 'info', 'warn', 'warning', 'error', 'success'], true)) {
            $level = 'info';
        }
        if ($level === 'warning') {
            $level = 'warn';
        }
        try {
            DB::query(
                'INSERT INTO account_work_logs(batch_id, item_id, account_id, task_id, level, message, meta_json)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    $batchId,
                    $itemId,
                    $accountId,
                    $taskId,
                    $level,
                    mb_substr($message, 0, 4000),
                    $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (\Throwable $e) {
            // 日志失败不影响主流程
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listLogs(int $batchId = 0, int $itemId = 0, int $limit = 200): array
    {
        self::ensureSchema();
        $limit = max(1, min(500, $limit));
        if ($itemId > 0) {
            $rows = DB::select(
                'SELECT * FROM account_work_logs WHERE item_id=? ORDER BY id DESC LIMIT ' . $limit,
                [$itemId]
            ) ?: [];
        } elseif ($batchId > 0) {
            $rows = DB::select(
                'SELECT * FROM account_work_logs WHERE batch_id=? ORDER BY id DESC LIMIT ' . $limit,
                [$batchId]
            ) ?: [];
        } else {
            return [];
        }
        foreach ($rows as &$row) {
            $row['meta'] = json_decode((string)($row['meta_json'] ?? ''), true) ?: [];
            unset($row['meta_json']);
        }
        unset($row);
        return array_reverse($rows);
    }

    /**
     * 解析 TikTok 登录应使用的账号名。
     * 系统里已写入新 Username、但「修改资料」分任务尚未在 TikTok 提交成功时，必须用原账号名登录。
     *
     * @return array{login_username:string,system_username:string,pending_username:string,reason:string,work_item_id:int}
     */
    public static function resolveLoginUsername(int $accountId, string $systemUsername = ''): array
    {
        self::ensureSchema();
        self::ensureItemColumns();
        self::ensureUsernamePrevColumn();
        $systemUsername = ltrim(trim($systemUsername), '@');
        $fallback = [
            'login_username' => $systemUsername,
            'system_username' => $systemUsername,
            'pending_username' => '',
            'reason' => '',
            'work_item_id' => 0,
        ];
        if ($accountId < 1) {
            return $fallback;
        }

        // 系统里改过名、TikTok 上还没生效 → 原名仍有效，登录必须用原名
        $prevUsername = '';
        try {
            $acc = DB::selectOne('SELECT username, username_prev FROM accounts WHERE id=?', [$accountId]);
            if ($acc) {
                $prevUsername = ltrim(trim((string)($acc['username_prev'] ?? '')), '@');
                if ($systemUsername === '') {
                    $systemUsername = ltrim(trim((string)($acc['username'] ?? '')), '@');
                    $fallback['login_username'] = $systemUsername;
                    $fallback['system_username'] = $systemUsername;
                }
            }
        } catch (\Throwable $e) {
            $prevUsername = '';
        }
        if ($prevUsername !== '' && strcasecmp($prevUsername, $systemUsername) !== 0) {
            return [
                'login_username' => $prevUsername,
                'system_username' => $systemUsername,
                'pending_username' => $systemUsername,
                'reason' => 'pending_username_change',
                'work_item_id' => 0,
            ];
        }

        try {
            $items = DB::select(
                "SELECT i.id, i.status, i.payload_json, i.original_username, b.status AS batch_status
                 FROM account_work_items i
                 INNER JOIN account_work_batches b ON b.id = i.batch_id AND b.deleted_at IS NULL
                 WHERE i.account_id = ?
                   AND i.task_type = 'modify_profile'
                   AND i.status IN ('pending', 'running')
                   AND b.status IN ('pending', 'running', 'paused')
                 ORDER BY i.id DESC",
                [$accountId]
            ) ?: [];
        } catch (\Throwable $e) {
            return $fallback;
        }

        foreach ($items as $item) {
            $payload = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
            $wanted = ltrim(trim((string)($payload['username'] ?? $payload['handle'] ?? '')), '@');
            $original = ltrim(trim((string)(
                $item['original_username']
                ?? $payload['originalUsername']
                ?? $payload['original_username']
                ?? ''
            )), '@');
            // 任务里没记原名（首次入队还没执行过）→ 用系统留的原名兜底
            if ($original === '') {
                $original = $prevUsername;
            }
            if ($wanted === '' || $original === '' || strcasecmp($wanted, $original) === 0) {
                continue;
            }
            return [
                'login_username' => $original,
                'system_username' => $systemUsername,
                'pending_username' => $wanted,
                'reason' => 'pending_modify_profile',
                'work_item_id' => (int)($item['id'] ?? 0),
            ];
        }

        return $fallback;
    }

    /**
     * 写入一条「修改资料」逐字段执行记录。
     * @param array<string,mixed> $fields username/nickname/bio/avatar => {requested,target,original,ok,reason}
     */
    public static function appendProfileChangeLog(int $accountId, array $fields, array $meta = []): int
    {
        self::ensureSchema();
        if ($accountId < 1) {
            return 0;
        }
        $pick = static function (string $key, string $prop, $default = '') use ($fields) {
            $node = $fields[$key] ?? [];
            if (!is_array($node)) {
                return $default;
            }
            return $node[$prop] ?? $default;
        };
        $text = static function ($value, int $max = 4000): string {
            $value = trim((string)$value);
            return $value === '' ? '' : mb_substr($value, 0, $max);
        };
        $flag = static function ($value): int {
            return !empty($value) ? 1 : 0;
        };
        $status = strtolower(trim((string)($meta['status'] ?? 'ok')));
        if (!in_array($status, ['ok', 'failed', 'skipped'], true)) {
            $status = 'ok';
        }
        $ownerId = (int)($meta['owner_id'] ?? 0);
        if ($ownerId < 1) {
            $acc = DB::selectOne('SELECT owner_id FROM accounts WHERE id=?', [$accountId]);
            $ownerId = (int)($acc['owner_id'] ?? 0);
        }
        $row = [
            'account_id' => $accountId,
            'owner_id' => $ownerId,
            'batch_id' => (int)($meta['batch_id'] ?? 0),
            'item_id' => (int)($meta['item_id'] ?? 0),
            'task_id' => (int)($meta['task_id'] ?? 0),
            'status' => $status,
            'message' => $text($meta['message'] ?? ''),
            'screenshot' => $text($meta['screenshot'] ?? '', 191),
            'username_target' => $text($pick('username', 'target'), 128),
            'username_original' => $text($pick('username', 'original'), 128),
            'username_requested' => $flag($pick('username', 'requested', false)),
            'username_ok' => $flag($pick('username', 'ok', false)),
            'username_reason' => $text($pick('username', 'reason')),
            'nickname_target' => $text($pick('nickname', 'target'), 255),
            'nickname_original' => $text($pick('nickname', 'original'), 255),
            'nickname_requested' => $flag($pick('nickname', 'requested', false)),
            'nickname_ok' => $flag($pick('nickname', 'ok', false)),
            'nickname_reason' => $text($pick('nickname', 'reason')),
            'bio_target' => $text($pick('bio', 'target'), 500),
            'bio_requested' => $flag($pick('bio', 'requested', false)),
            'bio_ok' => $flag($pick('bio', 'ok', false)),
            'bio_reason' => $text($pick('bio', 'reason')),
            'avatar_target' => $text($pick('avatar', 'target'), 500),
            'avatar_requested' => $flag($pick('avatar', 'requested', false)),
            'avatar_ok' => $flag($pick('avatar', 'ok', false)),
            'avatar_reason' => $text($pick('avatar', 'reason')),
            'operator_id' => (int)($meta['operator_id'] ?? 0),
        ];
        $cols = array_keys($row);
        DB::query(
            'INSERT INTO account_profile_change_logs(' . implode(',', $cols) . ')'
            . ' VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
            array_values($row)
        );
        return (int)DB::getPdo()->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public static function listProfileChangeLogs(int $accountId, int $limit = 50): array
    {
        self::ensureSchema();
        if ($accountId < 1) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $rows = DB::select(
            'SELECT * FROM account_profile_change_logs WHERE account_id=? ORDER BY id DESC LIMIT ' . $limit,
            [$accountId]
        ) ?: [];
        return array_map(static function (array $row): array {
            $fieldOf = static function (string $key) use ($row): array {
                return [
                    'requested' => (int)($row[$key . '_requested'] ?? 0) === 1,
                    'ok' => (int)($row[$key . '_ok'] ?? 0) === 1,
                    'target' => (string)($row[$key . '_target'] ?? ''),
                    'original' => (string)($row[$key . '_original'] ?? ''),
                    'reason' => (string)($row[$key . '_reason'] ?? ''),
                ];
            };
            return [
                'id' => (int)$row['id'],
                'accountId' => (int)$row['account_id'],
                'batchId' => (int)$row['batch_id'],
                'itemId' => (int)$row['item_id'],
                'taskId' => (int)$row['task_id'],
                'status' => (string)($row['status'] ?? 'ok'),
                'message' => (string)($row['message'] ?? ''),
                'screenshot' => (string)($row['screenshot'] ?? ''),
                'fields' => [
                    'username' => $fieldOf('username'),
                    'nickname' => $fieldOf('nickname'),
                    'bio' => $fieldOf('bio'),
                    'avatar' => $fieldOf('avatar'),
                ],
                'createdAt' => (string)($row['created_at'] ?? ''),
            ];
        }, $rows);
    }

    public static function priorityFor(string $type): int
    {
        return self::TYPE_PRIORITY[$type] ?? 100;
    }

    public static function isBrowserType(string $type): bool
    {
        return in_array($type, self::BROWSER_TYPES, true);
    }

    /**
     * 账号常规总任务中，是否已有「权重更高」（priority 更小）且未完成的分项。
     * 用于常态化养号：有发布/改资料等更高优先级待办时，不抢先入队/推送。
     */
    public static function accountHasHigherPriorityPending(int $accountId, int $thanPriority): bool
    {
        self::ensureSchema();
        if ($accountId < 1) {
            return false;
        }
        $row = DB::selectOne(
            "SELECT i.id FROM account_work_items i
             INNER JOIN account_work_batches b ON b.id = i.batch_id
             WHERE i.account_id=?
               AND i.status IN ('pending','running')
               AND i.priority < ?
               AND b.deleted_at IS NULL
               AND b.status IN ('pending','running','paused')
               AND COALESCE(b.batch_scope,'general') <> 'ai_lead'
             LIMIT 1",
            [$accountId, $thanPriority]
        );
        return !empty($row);
    }

    /**
     * 账号是否已有指定类型、尚未执行完的分项（可限定 task_ref）。
     * 防止常态化养号对同一任务反复挂 pending。
     */
    public static function accountHasPendingTaskType(int $accountId, string $taskType, int $taskRefId = 0): bool
    {
        self::ensureSchema();
        if ($accountId < 1 || $taskType === '') {
            return false;
        }
        $params = [$accountId, $taskType];
        $refSql = '';
        if ($taskRefId > 0) {
            $refSql = ' AND i.task_ref_id=?';
            $params[] = $taskRefId;
        }
        $row = DB::selectOne(
            "SELECT i.id FROM account_work_items i
             INNER JOIN account_work_batches b ON b.id = i.batch_id
             WHERE i.account_id=? AND i.task_type=?
               AND i.status IN ('pending','running')
               {$refSql}
               AND b.deleted_at IS NULL
               AND b.status IN ('pending','running','paused')
             LIMIT 1",
            $params
        );
        return !empty($row);
    }

    /**
     * 父任务是否仍有未执行完的分项（任意账号）。
     */
    public static function taskHasOpenWorkItems(int $taskRefId): bool
    {
        if ($taskRefId < 1) {
            return false;
        }
        self::ensureSchema();
        $row = DB::selectOne(
            "SELECT COUNT(*) AS c FROM account_work_items
             WHERE task_ref_id=? AND status IN ('pending','running')",
            [$taskRefId]
        );
        return (int)($row['c'] ?? 0) > 0;
    }

    /** 任务是否已到可执行时间（scheduled_at 为空或已到点；按库当前时间，与 PHP Asia/Shanghai 对齐） */
    public static function isTaskDue(?array $task): bool
    {
        if (!$task) {
            return false;
        }
        $scheduled = trim((string)($task['scheduled_at'] ?? ''));
        if ($scheduled === '') {
            return true;
        }
        $ts = strtotime($scheduled);
        if ($ts === false) {
            return true;
        }
        return $ts <= time();
    }

    /**
     * 把已到点的 queued 浏览器任务挂入账号会话（定时发布用）。
     * @return int 本次新挂载的分项数
     */
    public static function attachDueQueuedTasks(?int $ownerFilter = null): int
    {
        self::ensureSchema();
        $params = [];
        $ownerSql = '';
        if ($ownerFilter !== null && $ownerFilter > 0) {
            $ownerSql = ' AND (t.owner_id=? OR t.owner_id=0)';
            $params[] = $ownerFilter;
        }
        $rows = DB::select(
            "SELECT t.id FROM tasks t
             WHERE t.status='queued'
               AND t.type IN ('publish','message','warm','sync_fans','dm_sync','sync','profile')
               AND (t.scheduled_at IS NULL OR t.scheduled_at<=CURRENT_TIMESTAMP)
               AND NOT EXISTS (
                 SELECT 1 FROM account_work_items i
                 WHERE i.task_ref_id=t.id AND i.status IN ('pending','running')
               )
               {$ownerSql}
             ORDER BY t.id ASC
             LIMIT 40",
            $params
        ) ?: [];
        $items = 0;
        foreach ($rows as $row) {
            $r = self::attachFromTask((int)($row['id'] ?? 0), true);
            $items += (int)($r['items'] ?? 0);
        }
        return $items;
    }

    /**
     * 任务入队后：按账号拆分并挂到 pending/running 总任务（没有则新建）。
     * @param bool $forceDue 定时未到点时跳过挂载（由 attachDueQueuedTasks 到点后再挂）
     * @return array{batches:int, items:int, deferred?:bool}
     */
    public static function attachFromTask(int $taskId, bool $forceDue = false): array
    {
        self::ensureSchema();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [$taskId]);
        if (!$task) {
            return ['batches' => 0, 'items' => 0];
        }
        $type = (string)($task['type'] ?? '');
        if (!self::isBrowserType($type)) {
            return ['batches' => 0, 'items' => 0];
        }
        if (in_array($type, ['ai_lead', 'ai_lead_dm'], true)) {
            return ['batches' => 0, 'items' => 0];
        }

        // 定时未到：不挂会话，等 claim 时 attachDueQueuedTasks 再挂
        if (!$forceDue && !self::isTaskDue($task)) {
            return ['batches' => 0, 'items' => 0, 'deferred' => true];
        }

        // 避免重复挂载未完成项
        $existing = DB::selectOne(
            "SELECT COUNT(*) AS c FROM account_work_items
             WHERE task_type=? AND task_ref_id=? AND status IN ('pending','running')",
            [$type, $taskId]
        );
        if ((int)($existing['c'] ?? 0) > 0) {
            return ['batches' => 0, 'items' => 0];
        }

        $slots = self::expandTaskSlots($task);
        if (!$slots) {
            return ['batches' => 0, 'items' => 0];
        }

        $ownerId = (int)($task['owner_id'] ?? 0);
        $batches = 0;
        $items = 0;
        $deferredHigher = 0;
        $typePriority = self::priorityFor($type);
        foreach ($slots as $slot) {
            $accountId = (int)$slot['account_id'];
            if ($accountId < 1) {
                continue;
            }
            // 养号：同账号已有未执行的本任务分项 → 不重复挂
            if ($type === 'warm' && self::accountHasPendingTaskType($accountId, 'warm', $taskId)) {
                continue;
            }
            // 养号：同账号总任务里已有更高权重分项（改资料/发布等）→ 先不挂，等它们跑完再由 attachDue/cron 挂入排队
            // 这样不会单独拉起「纯养号」会话去挤占；高优先级任务之后插入时，claim-item 仍按 priority 取下一条
            if ($type === 'warm' && self::accountHasHigherPriorityPending($accountId, $typePriority)) {
                $deferredHigher++;
                continue;
            }
            $slotOwner = $ownerId;
            if ($slotOwner < 1) {
                $accRow = DB::selectOne('SELECT owner_id FROM accounts WHERE id=?', [$accountId]);
                $slotOwner = (int)($accRow['owner_id'] ?? 0);
            }
            $batch = self::resolveOrCreateOpenBatch($accountId, $slotOwner);
            if (!empty($batch['_created'])) {
                $batches++;
            }
            $seq = self::nextSeq((int)$batch['id']);
            DB::query(
                "INSERT INTO account_work_items
                 (batch_id, account_id, owner_id, task_type, task_ref_id, priority, seq, status, payload_json)
                 VALUES (?,?,?,?,?,?,?,'pending',?)",
                [
                    (int)$batch['id'],
                    $accountId,
                    $slotOwner,
                    $type,
                    $taskId,
                    $typePriority,
                    $seq,
                    json_encode($slot['payload'], JSON_UNESCAPED_UNICODE),
                ]
            );
            DB::query(
                'UPDATE account_work_batches SET item_total=item_total+1, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [(int)$batch['id']]
            );
            $items++;

            // 养号结束后诊断：同一会话再挂一条 diagnose（priority 55，在 warm 之后）
            if ($type === 'warm' && filter_var($slot['payload']['diagnoseAfterWarm'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $seq2 = self::nextSeq((int)$batch['id']);
                DB::query(
                    "INSERT INTO account_work_items
                     (batch_id, account_id, owner_id, task_type, task_ref_id, priority, seq, status, payload_json)
                     VALUES (?,?,?,?,?,?,?,'pending',?)",
                    [
                        (int)$batch['id'],
                        $accountId,
                        $slotOwner,
                        'diagnose',
                        $taskId,
                        self::priorityFor('diagnose'),
                        $seq2,
                        json_encode([
                            'source' => 'warm',
                            'accountId' => $accountId,
                            'browserBackend' => (function ($raw) { $be = strtolower(trim((string)$raw)); return in_array($be, ['bit','tkswarm','linux'], true) ? $be : 'bit'; })($slot['payload']['browserBackend'] ?? $slot['payload']['browser_backend'] ?? 'bit'),
                        ], JSON_UNESCAPED_UNICODE),
                    ]
                );
                DB::query(
                    'UPDATE account_work_batches SET item_total=item_total+1, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [(int)$batch['id']]
                );
                $items++;
            }
        }

        if ($items > 0) {
            DB::query(
                'INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)',
                [$taskId, 'info', "已挂载账号会话总任务：{$items} 个分项"]
            );
        } elseif ($deferredHigher > 0 && $type === 'warm') {
            DB::query(
                'INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)',
                [$taskId, 'info', "养号暂缓挂载：{$deferredHigher} 个账号存在更高优先级待办，将按权重排队稍后入列"]
            );
        }
        return [
            'batches' => $batches,
            'items' => $items,
            'deferred_higher' => $deferredHigher,
        ];
    }

    /**
     * 直接按账号追加分任务（删视频 / 回评等不一定走 tasks 表时可用）。
     * @param array<string,mixed> $payload
     */
    public static function enqueueItem(
        int $accountId,
        string $taskType,
        array $payload = [],
        int $taskRefId = 0,
        ?int $ownerId = null
    ): array {
        self::ensureSchema();
        if ($accountId < 1) {
            throw new \InvalidArgumentException('accountId 无效');
        }
        if (!self::isBrowserType($taskType)) {
            throw new \InvalidArgumentException("不支持的任务类型: {$taskType}");
        }
        // AI 拓客必须走独立总任务，禁止挂入 general batch
        if (in_array($taskType, ['ai_lead', 'ai_lead_dm'], true)) {
            return self::enqueueDedicatedItem($accountId, $taskType, $payload, $taskRefId, $ownerId);
        }
        $acc = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
        if (!$acc) {
            throw new \InvalidArgumentException('账号不存在');
        }
        $owner = $ownerId ?? (int)($acc['owner_id'] ?? 0);
        $batch = self::resolveOrCreateOpenBatch($accountId, $owner);
        $seq = self::nextSeq((int)$batch['id']);
        DB::query(
            "INSERT INTO account_work_items
             (batch_id, account_id, owner_id, task_type, task_ref_id, priority, seq, status, payload_json)
             VALUES (?,?,?,?,?,?,?,'pending',?)",
            [
                (int)$batch['id'],
                $accountId,
                $owner,
                $taskType,
                $taskRefId,
                self::priorityFor($taskType),
                $seq,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );
        $itemId = (int)DB::getPdo()->lastInsertId();
        if ($taskType === 'modify_profile') {
            self::applyModifyProfileItemMeta($itemId, $payload, null, $accountId);
        }
        DB::query(
            'UPDATE account_work_batches SET item_total=item_total+1, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [(int)$batch['id']]
        );
        return [
            'batch_id' => (int)$batch['id'],
            'item_id' => $itemId,
            'created_batch' => !empty($batch['_created']),
        ];
    }

    /**
     * 独立总任务入队（不复用账号上 pending/running 的 batch，便于 WSS 单独推送）。
     * 用于 AI 拓客等需独立前台任务的场景。
     * @param array<string,mixed> $payload
     */
    public static function enqueueDedicatedItem(
        int $accountId,
        string $taskType,
        array $payload = [],
        int $taskRefId = 0,
        ?int $ownerId = null
    ): array {
        self::ensureBatchScopeReady();
        AccountBrowserEnvService::ensureSchema();
        if ($accountId < 1) {
            throw new \InvalidArgumentException('accountId 无效');
        }
        if (!self::isBrowserType($taskType)) {
            throw new \InvalidArgumentException("不支持的任务类型: {$taskType}");
        }
        $acc = DB::selectOne(
            'SELECT id, owner_id, browser_profile_id, browser_backend, tkswarm_browser_profile_id FROM accounts WHERE id=?',
            [$accountId]
        );
        if (!$acc) {
            throw new \InvalidArgumentException('账号不存在');
        }
        $owner = self::resolveWorkOwnerId($accountId, $ownerId);
        $wantBackend = strtolower(trim((string)($payload['browserBackend'] ?? $payload['browser_backend'] ?? '')));
        if (!in_array($wantBackend, ['bit', 'tkswarm', 'linux'], true)) {
            $wantBackend = 'bit';
        }
        // Linux 服务器浏览器：与桌面自研共用环境包；入队不强制绑，由 node 拉包/复用/补建。
        if ($wantBackend === 'linux') {
            self::assertServerBrowserTask($taskType);
        } else {
            $profileId = $wantBackend === 'tkswarm'
                ? trim((string)($acc['tkswarm_browser_profile_id'] ?? ''))
                : trim((string)($acc['browser_profile_id'] ?? ''));
            if ($profileId === '') {
                $profileId = trim((string)($acc['browser_profile_id'] ?? ''));
            }
            if ($profileId !== '') {
                AccountBrowserEnvService::bind($accountId, $profileId, true, '', $wantBackend);
            }
        }

        DB::query(
            "INSERT INTO account_work_batches (account_id, owner_id, batch_scope, status) VALUES (?,?, ?, 'pending')",
            [$accountId, $owner, self::BATCH_SCOPE_AI_LEAD]
        );
        $batchId = (int)DB::getPdo()->lastInsertId();
        DB::query(
            "INSERT INTO account_work_items
             (batch_id, account_id, owner_id, task_type, task_ref_id, priority, seq, status, payload_json)
             VALUES (?,?,?,?,?,?,?, 'pending', ?)",
            [
                $batchId,
                $accountId,
                $owner,
                $taskType,
                $taskRefId,
                self::priorityFor($taskType),
                1,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );
        $itemId = (int)DB::getPdo()->lastInsertId();
        DB::query(
            'UPDATE account_work_batches SET item_total=1, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$batchId]
        );
        return [
            'batch_id' => $batchId,
            'item_id' => $itemId,
            'created_batch' => true,
        ];
    }

    /**
     * 查找可追加的总任务，没有则新建。
     * @return array<string,mixed>
     */
    public static function resolveOrCreateOpenBatch(int $accountId, int $ownerId): array
    {
        self::ensureBatchScopeReady();
        $batchParams = [$accountId];
        $ownerSql = '';
        if ($ownerId > 0) {
            $ownerSql = ' AND owner_id=?';
            $batchParams[] = $ownerId;
        }
        $open = DB::selectOne(
            "SELECT * FROM account_work_batches
             WHERE account_id=?{$ownerSql} AND status IN ('pending','running','paused')
               AND deleted_at IS NULL
               AND COALESCE(batch_scope, 'general') <> 'ai_lead'
             ORDER BY FIELD(status,'running','pending','paused'), id ASC
             LIMIT 1",
            $batchParams
        );
        if ($open) {
            $open['_created'] = false;
            return $open;
        }
        DB::query(
            "INSERT INTO account_work_batches (account_id, owner_id, batch_scope, status) VALUES (?,?, ?, 'pending')",
            [$accountId, $ownerId, self::BATCH_SCOPE_GENERAL]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        $row = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$id]) ?: [
            'id' => $id,
            'account_id' => $accountId,
            'owner_id' => $ownerId,
            'status' => 'pending',
        ];
        $row['_created'] = true;
        return $row;
    }

    public static function nextSeq(int $batchId): int
    {
        $row = DB::selectOne('SELECT COALESCE(MAX(seq), 0) AS m FROM account_work_items WHERE batch_id=?', [$batchId]);
        return (int)($row['m'] ?? 0) + 1;
    }

    /** @param array<string,mixed> $batch */
    public static function isAiLeadBatchRow(array $batch): bool
    {
        if (($batch['batch_scope'] ?? '') === self::BATCH_SCOPE_AI_LEAD) {
            return true;
        }
        $batchId = (int)($batch['id'] ?? 0);
        if ($batchId < 1) {
            return false;
        }
        $row = DB::selectOne(
            "SELECT
               SUM(CASE WHEN task_type IN ('ai_lead','ai_lead_dm') THEN 1 ELSE 0 END) AS ai_cnt,
               SUM(CASE WHEN task_type NOT IN ('ai_lead','ai_lead_dm') THEN 1 ELSE 0 END) AS other_cnt
             FROM account_work_items WHERE batch_id=?",
            [$batchId]
        );
        return (int)($row['ai_cnt'] ?? 0) > 0 && (int)($row['other_cnt'] ?? 0) === 0;
    }

    /**
     * 该账号是否仍有未完成的常规总任务（AI 拓客须等待）。
     */
    public static function accountHasPendingGeneralWork(int $accountId, int $excludeBatchId = 0): bool
    {
        self::ensureSchema();
        if ($accountId < 1) {
            return false;
        }
        $params = [$accountId];
        $excludeSql = '';
        if ($excludeBatchId > 0) {
            $excludeSql = ' AND b.id<>?';
            $params[] = $excludeBatchId;
        }
        $row = DB::selectOne(
            "SELECT b.id FROM account_work_batches b
             WHERE b.account_id=? AND b.deleted_at IS NULL
               AND COALESCE(b.batch_scope,'general') <> 'ai_lead'
               AND b.status IN ('pending','running')
               {$excludeSql}
               AND EXISTS (
                 SELECT 1 FROM account_work_items i
                 WHERE i.batch_id=b.id AND i.status IN ('pending','running')
               )
             LIMIT 1",
            $params
        );
        return !empty($row);
    }

    /**
     * Node 领取下一个可执行的账号总任务。
     * 仅领取「账号尚有空闲比特环境」的 pending 总任务；领取时占用环境为 busy。
     * @return array<string,mixed>|null
     */
    public static function claimBatch(string $workerId, int $leaseSeconds = 300, ?int $ownerFilter = null): ?array
    {
        self::ensureSchema();
        self::reclaimExpiredLeases();
        // 把已到点的定时任务挂进会话，再领取
        try {
            self::attachDueQueuedTasks($ownerFilter);
        } catch (\Throwable $e) {
        }

        $excludeIds = [];
        // 指定后端暂无空闲环境时跳过该 batch，避免反复领同一条卡住
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $params = [];
            $ownerSql = '';
            if ($ownerFilter !== null && $ownerFilter > 0) {
                // 含 owner_id=0 的公海 batch：领取时再按账号可见范围绑定到当前切换用户
                $ownerSql = ' AND (b.owner_id=? OR b.owner_id=0)';
                $params[] = $ownerFilter;
            }
            $excludeSql = '';
            if ($excludeIds) {
                $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
                $excludeSql = " AND b.id NOT IN ({$placeholders})";
                foreach ($excludeIds as $eid) {
                    $params[] = $eid;
                }
            }

            // 常规总任务优先；同账号有 general 未完成时不领 ai_lead
            // pending 分项须已到 scheduled_at（无任务行 / 无定时 视为可领）
            $batch = DB::selectOne(
                "SELECT b.* FROM account_work_batches b
                 WHERE b.status='pending'
                   AND b.deleted_at IS NULL
                   AND EXISTS (
                     SELECT 1 FROM account_work_items i
                     LEFT JOIN tasks t ON t.id=i.task_ref_id
                     WHERE i.batch_id=b.id AND i.status='pending'
                       AND (t.id IS NULL OR t.scheduled_at IS NULL OR t.scheduled_at<=CURRENT_TIMESTAMP)
                   )
                   AND EXISTS (
                     SELECT 1 FROM account_browser_envs e
                     WHERE e.account_id=b.account_id AND e.saturation='idle'
                   )
                   AND (
                     COALESCE(b.batch_scope,'general') <> 'ai_lead'
                     OR NOT EXISTS (
                       SELECT 1 FROM account_work_batches g
                       WHERE g.account_id=b.account_id AND g.deleted_at IS NULL
                         AND g.id <> b.id
                         AND COALESCE(g.batch_scope,'general') <> 'ai_lead'
                         AND g.status IN ('pending','running')
                         AND EXISTS (
                           SELECT 1 FROM account_work_items gi
                           WHERE gi.batch_id=g.id AND gi.status IN ('pending','running')
                         )
                     )
                   )
                   {$ownerSql}
                   {$excludeSql}
                 ORDER BY
                   CASE WHEN COALESCE(b.batch_scope,'general') = 'ai_lead' THEN 1 ELSE 0 END,
                   b.id ASC
                 LIMIT 1",
                $params
            );
            if (!$batch) {
                return null;
            }

            $batchId = (int)$batch['id'];
            if (!self::ensureBatchVisibleToScope($batch, $ownerFilter)) {
                $excludeIds[] = $batchId;
                continue;
            }
            self::stampBatchOwnerFromScope($batch);

            $leaseUntil = date('Y-m-d H:i:s', time() + max(60, $leaseSeconds));
            DB::query(
                "UPDATE account_work_batches
                 SET status='running',
                     push_status='offered',
                     locked_by=?,
                     lease_until=?,
                     heartbeat_at=CURRENT_TIMESTAMP,
                     started_at=COALESCE(started_at, CURRENT_TIMESTAMP),
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status='pending'",
                [$workerId, $leaseUntil, $batchId]
            );
            $fresh = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
            if (!$fresh || ($fresh['status'] ?? '') !== 'running' || ($fresh['locked_by'] ?? '') !== $workerId) {
                $excludeIds[] = $batchId;
                continue;
            }

            $preferBackend = self::preferredBackendFromBatch($batchId);
            $env = self::leaseOrServerEnv(
                (int)$fresh['account_id'],
                $batchId,
                $workerId,
                $leaseSeconds,
                $preferBackend
            );
            if (!$env) {
                // 抢到总任务但对应后端环境不可用：退回 pending，试下一条
                DB::query(
                    "UPDATE account_work_batches
                     SET status='pending', push_status='none', locked_by='', lease_until=NULL, browser_env_id=NULL, updated_at=CURRENT_TIMESTAMP
                     WHERE id=?",
                    [$batchId]
                );
                $excludeIds[] = $batchId;
                continue;
            }

            $fresh = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]) ?: $fresh;
            $account = DB::selectOne(
                'SELECT id, username, browser_profile_id, browser_backend, tkswarm_browser_profile_id, linux_browser_profile_id, login_status, has_session, enabled FROM accounts WHERE id=?',
                [(int)$fresh['account_id']]
            );
            return self::enrichBatchClaim($fresh, $account, $env);
        }

        return null;
    }

    /**
     * 按 batchId 定点领取（AI拓客启用后立即推送，避免被其他 pending 抢走或轮询漏领）
     * @return array<string,mixed>|null
     */
    public static function claimSpecificBatch(int $batchId, string $workerId, int $leaseSeconds = 300): ?array
    {
        self::ensureSchema();
        AccountBrowserEnvService::ensureSchema();
        self::reclaimExpiredLeases();
        AccountBrowserEnvService::reclaimExpiredEnvLeases();

        if ($batchId < 1 || $workerId === '') {
            return null;
        }

        $batch = DB::selectOne(
            "SELECT * FROM account_work_batches
             WHERE id=? AND deleted_at IS NULL
               AND (
                 status='pending'
                 OR (status='running' AND COALESCE(push_status,'none') IN ('offered','none'))
               )
             LIMIT 1",
            [$batchId]
        );
        if (!$batch) {
            return null;
        }
        self::stampBatchOwnerFromScope($batch);

        // 已 offered 且仍有 pending 分项：直接封装再推一次
        if (($batch['status'] ?? '') === 'running' && ($batch['push_status'] ?? '') === 'offered') {
            $pending = DB::selectOne(
                "SELECT id FROM account_work_items WHERE batch_id=? AND status='pending' LIMIT 1",
                [$batchId]
            );
            if ($pending) {
                $account = DB::selectOne(
                    'SELECT id, username, browser_profile_id, browser_backend, tkswarm_browser_profile_id, linux_browser_profile_id, login_status, has_session, enabled FROM accounts WHERE id=?',
                    [(int)$batch['account_id']]
                );
                $env = null;
                if (!empty($batch['browser_env_id'])) {
                    $env = DB::selectOne('SELECT * FROM account_browser_envs WHERE id=?', [(int)$batch['browser_env_id']]);
                }
                if (!$env) {
                    $preferBackend = self::preferredBackendFromBatch($batchId);
                    $env = self::leaseOrServerEnv(
                        (int)$batch['account_id'],
                        $batchId,
                        $workerId,
                        $leaseSeconds,
                        $preferBackend
                    );
                }
                if ($env) {
                    DB::query(
                        "UPDATE account_work_batches SET locked_by=?, lease_until=?, heartbeat_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                        [
                            $workerId,
                            date('Y-m-d H:i:s', time() + max(60, $leaseSeconds)),
                            $batchId,
                        ]
                    );
                    $fresh = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]) ?: $batch;
                    return self::enrichBatchClaim($fresh, $account, $env);
                }
            }
        }

        if (($batch['status'] ?? '') !== 'pending') {
            // running 但无可推分项
            if (($batch['status'] ?? '') === 'running') {
                return null;
            }
        }

        $leaseUntil = date('Y-m-d H:i:s', time() + max(60, $leaseSeconds));
        DB::query(
            "UPDATE account_work_batches
             SET status='running',
                 push_status='offered',
                 locked_by=?,
                 lease_until=?,
                 heartbeat_at=CURRENT_TIMESTAMP,
                 started_at=COALESCE(started_at, CURRENT_TIMESTAMP),
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='pending'",
            [$workerId, $leaseUntil, $batchId]
        );
        $fresh = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$fresh || ($fresh['status'] ?? '') !== 'running') {
            return null;
        }

        $preferBackend = self::preferredBackendFromBatch($batchId);
        $env = self::leaseOrServerEnv(
            (int)$fresh['account_id'],
            $batchId,
            $workerId,
            $leaseSeconds,
            $preferBackend
        );
        if (!$env) {
            // 仍无空闲：先回收过期 busy
            try {
                DB::query(
                    "UPDATE account_browser_envs
                     SET saturation='idle', locked_by='', batch_id=NULL, lease_until=NULL, updated_at=CURRENT_TIMESTAMP
                     WHERE account_id=? AND saturation='busy'
                       AND (lease_until IS NULL OR lease_until < NOW())",
                    [(int)$fresh['account_id']]
                );
            } catch (\Throwable $e) {
            }
            $env = self::leaseOrServerEnv(
                (int)$fresh['account_id'],
                $batchId,
                $workerId,
                $leaseSeconds,
                $preferBackend
            );
        }
        if (!$env) {
            // 仅当无「常规总任务」在跑时才强制释放，避免 AI 拓客定点推送打断发布/客服等
            $accountId = (int)$fresh['account_id'];
            $generalRunning = DB::selectOne(
                "SELECT b.id FROM account_work_batches b
                 WHERE b.account_id=? AND b.status='running' AND b.id<>?
                   AND COALESCE(b.batch_scope,'general') <> 'ai_lead'
                   AND EXISTS (
                     SELECT 1 FROM account_work_items i
                     WHERE i.batch_id=b.id AND i.task_type NOT IN ('ai_lead','ai_lead_dm')
                   )
                 LIMIT 1",
                [$accountId, $batchId]
            );
            if (!$generalRunning) {
                try {
                    AccountBrowserEnvService::forceReleaseAccount($accountId);
                } catch (\Throwable $e) {
                }
                $env = self::leaseOrServerEnv(
                    $accountId,
                    $batchId,
                    $workerId,
                    $leaseSeconds,
                    $preferBackend
                );
            }
        }
        if (!$env) {
            // AI 拓客独立 batch：允许先推送，执行侧等待环境空闲，不 forceRelease 常规任务
            if (self::isAiLeadBatchRow($fresh)) {
                $account = DB::selectOne(
                    'SELECT id, username, browser_profile_id, browser_backend, tkswarm_browser_profile_id, linux_browser_profile_id, login_status, has_session, enabled FROM accounts WHERE id=?',
                    [(int)$fresh['account_id']]
                );
                $formatted = self::enrichBatchClaim($fresh, $account, null);
                $formatted['env_pending'] = true;
                $formatted['wait_reason'] = self::hasRunningGeneralBatch((int)$fresh['account_id'])
                    ? 'general_running'
                    : 'env_busy';
                return $formatted;
            }
            DB::query(
                "UPDATE account_work_batches
                 SET status='pending', push_status='none', locked_by='', lease_until=NULL, browser_env_id=NULL, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$batchId]
            );
            return null;
        }

        $fresh = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]) ?: $fresh;
        $account = DB::selectOne(
            'SELECT id, username, browser_profile_id, browser_backend, tkswarm_browser_profile_id, linux_browser_profile_id, login_status, has_session, enabled FROM accounts WHERE id=?',
            [(int)$fresh['account_id']]
        );
        return self::enrichBatchClaim($fresh, $account, $env);
    }

    /**
     * 取消误挂入常规总任务的 AI 拓客分项（保留 general 内其他分项）。
     */
    public static function cancelMisplacedAiLeadItems(int $leadId): int
    {
        self::ensureSchema();
        if ($leadId < 1) {
            return 0;
        }
        $rows = DB::select(
            "SELECT i.id, i.batch_id FROM account_work_items i
             INNER JOIN account_work_batches b ON b.id=i.batch_id
             WHERE i.task_type IN ('ai_lead','ai_lead_dm') AND i.task_ref_id=?
               AND i.status IN ('pending','running')
               AND COALESCE(b.batch_scope,'general') <> ?",
            [$leadId, self::BATCH_SCOPE_AI_LEAD]
        ) ?: [];
        $n = 0;
        foreach ($rows as $row) {
            DB::query(
                "UPDATE account_work_items SET status='cancelled', updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [(int)$row['id']]
            );
            $n++;
            $batchId = (int)($row['batch_id'] ?? 0);
            if ($batchId > 0) {
                $live = DB::selectOne(
                    "SELECT COUNT(*) AS c FROM account_work_items WHERE batch_id=? AND status IN ('pending','running')",
                    [$batchId]
                );
                DB::query(
                    'UPDATE account_work_batches SET item_total=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [(int)($live['c'] ?? 0), $batchId]
                );
            }
        }
        return $n;
    }

    /** 该账号是否有常规总任务正在占用浏览器（非 AI 拓客独立 batch） */
    public static function hasRunningGeneralBatch(int $accountId): bool
    {
        self::ensureSchema();
        if ($accountId < 1) {
            return false;
        }
        $row = DB::selectOne(
            "SELECT b.id FROM account_work_batches b
             WHERE b.account_id=? AND b.status='running' AND b.deleted_at IS NULL
               AND COALESCE(b.batch_scope,'general') <> 'ai_lead'
               AND EXISTS (
                 SELECT 1 FROM account_work_items i
                 WHERE i.batch_id=b.id AND i.task_type NOT IN ('ai_lead','ai_lead_dm')
               )
             LIMIT 1",
            [$accountId]
        );
        return !empty($row);
    }

    public static function heartbeat(int $batchId, string $workerId, int $leaseSeconds = 300): bool
    {
        self::ensureSchema();
        $leaseUntil = date('Y-m-d H:i:s', time() + max(60, $leaseSeconds));
        $stmt = DB::query(
            "UPDATE account_work_batches
             SET lease_until=?, heartbeat_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='running' AND locked_by=?",
            [$leaseUntil, $batchId, $workerId]
        );
        if ($stmt->rowCount() > 0) {
            AccountBrowserEnvService::renewEnvLease($batchId, $workerId, $leaseSeconds);
            return true;
        }
        return false;
    }

    /**
     * 领取总任务内下一条 pending 分任务（running 期间新挂入的也会被取到）。
     * 严格按权重排队：priority ASC（越小越先），再 seq、id。
     * 因此会话中途插入「发布」等更高优先级分项时，会在下一条领取时插到养号前面，而不是另起进程互挤。
     * @return array<string,mixed>|null
     */
    public static function claimNextItem(int $batchId, string $workerId): ?array
    {
        self::ensureSchema();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch || ($batch['status'] ?? '') !== 'running' || ($batch['locked_by'] ?? '') !== $workerId) {
            return null;
        }
        if (!empty($batch['deleted_at']) || ($batch['status'] ?? '') === 'deleted') {
            return null;
        }

        // 每次只取当前待定列表里权重最高的一条（含中途新挂入）
        $item = DB::selectOne(
            "SELECT i.* FROM account_work_items i
             LEFT JOIN tasks t ON t.id=i.task_ref_id
             WHERE i.batch_id=? AND i.status='pending'
               AND (t.id IS NULL OR t.scheduled_at IS NULL OR t.scheduled_at<=CURRENT_TIMESTAMP)
             ORDER BY i.priority ASC, i.seq ASC, i.id ASC
             LIMIT 1",
            [$batchId]
        );
        if (!$item) {
            return null;
        }

        DB::query(
            "UPDATE account_work_items
             SET status='running', started_at=COALESCE(started_at,CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='pending'",
            [(int)$item['id']]
        );
        $fresh = DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [(int)$item['id']]);
        if (!$fresh || ($fresh['status'] ?? '') !== 'running') {
            return null;
        }
        $type = (string)($fresh['task_type'] ?? '');
        self::appendLog(
            $batchId,
            (int)$fresh['id'],
            "开始执行子任务 #{$fresh['id']}（{$type}）",
            'info',
            (int)($fresh['account_id'] ?? 0),
            (int)($fresh['task_ref_id'] ?? 0),
            ['event' => 'item_start', 'task_type' => $type, 'worker' => $workerId]
        );
        return self::formatItem($fresh);
    }

    /**
     * @param array<string,mixed>|null $result
     */
    public static function finishItem(
        int $batchId,
        int $itemId,
        string $workerId,
        string $status,
        ?array $result = null,
        string $errorMessage = ''
    ): array {
        self::ensureSchema();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch || ($batch['locked_by'] ?? '') !== $workerId) {
            throw new \RuntimeException('总任务未领取或不属于当前节点');
        }
        $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=? AND batch_id=?', [$itemId, $batchId]);
        if (!$item) {
            throw new \RuntimeException('分任务不存在');
        }
        if (!in_array($status, ['completed', 'failed', 'skipped'], true)) {
            $status = 'failed';
        }

        DB::query(
            "UPDATE account_work_items
             SET status=?, result_json=?, error_message=?, finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [
                $status,
                $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE),
                $errorMessage,
                $itemId,
            ]
        );

        if (($item['task_type'] ?? '') === 'modify_profile') {
            $payload = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
            self::applyModifyProfileItemMeta(
                $itemId,
                is_array($payload) ? $payload : [],
                $result,
                (int)($item['account_id'] ?? 0)
            );
        }

        if ($status === 'completed') {
            DB::query(
                'UPDATE account_work_batches SET item_success=item_success+1, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$batchId]
            );
        } elseif ($status === 'failed') {
            DB::query(
                'UPDATE account_work_batches SET item_fail=item_fail+1, last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$errorMessage, $batchId]
            );
        }

        $type = (string)($item['task_type'] ?? '');
        $logLevel = $status === 'completed' ? 'success' : ($status === 'skipped' ? 'warn' : 'error');
        $logMsg = $status === 'completed'
            ? "子任务 #{$itemId}（{$type}）执行成功"
            : ($status === 'skipped'
                ? "子任务 #{$itemId}（{$type}）已跳过" . ($errorMessage !== '' ? "：{$errorMessage}" : '')
                : "子任务 #{$itemId}（{$type}）执行失败" . ($errorMessage !== '' ? "：{$errorMessage}" : ''));
        self::appendLog(
            $batchId,
            $itemId,
            $logMsg,
            $logLevel,
            (int)($item['account_id'] ?? 0),
            (int)($item['task_ref_id'] ?? 0),
            ['event' => 'item_finish', 'status' => $status]
        );

        self::mirrorItemToTask($item, $status, $result, $errorMessage);
        self::maybeFinishParentTask((string)$item['task_type'], (int)$item['task_ref_id']);

        if ($status === 'completed' && (string)($item['task_type'] ?? '') === 'publish') {
            try {
                self::markMaterialPublishedFromWorkItem($item, is_array($result) ? $result : null);
            } catch (\Throwable $e) {
                // 发布已成功，回写失败不阻断分任务完成
            }
        }

        if ($type === 'auto_follow') {
            self::syncAutoFollowWorkItem($item, $status, $result, $errorMessage);
        }

        if ($status === 'completed' && $type === 'pull_stats') {
            try {
                $fresh = DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]) ?: $item;
                \App\Services\PlanTask\PullDataAnalysisService::enqueueFromWorkItem(is_array($fresh) ? $fresh : $item);
            } catch (\Throwable $e) {
                error_log('[pull_data_analysis] enqueue failed: ' . $e->getMessage());
            }
        }

        return [
            'item' => self::formatItem(DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]) ?: $item),
            'has_more' => self::hasPendingItems($batchId),
        ];
    }

    public static function pauseBatch(int $batchId): array
    {
        self::ensureSchema();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch) {
            throw new \RuntimeException('总任务不存在');
        }
        if (!empty($batch['deleted_at']) || ($batch['status'] ?? '') === 'deleted') {
            throw new \RuntimeException('总任务已删除');
        }
        if (!in_array($batch['status'] ?? '', ['pending', 'running'], true)) {
            throw new \RuntimeException('仅待执行或执行中的总任务可暂停');
        }
        DB::query(
            "UPDATE account_work_batches
             SET status='paused', locked_by='', lease_until=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status IN ('pending','running')",
            [$batchId]
        );
        AccountBrowserEnvService::releaseEnvByBatch($batchId);
        // 未开始的子任务一并标记暂停，便于汇总页展示与单独恢复
        DB::query(
            "UPDATE account_work_items
             SET status='paused', updated_at=CURRENT_TIMESTAMP
             WHERE batch_id=? AND status='pending'",
            [$batchId]
        );
        return DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]) ?: ['id' => $batchId, 'status' => 'paused'];
    }

    /**
     * 软删除总任务：停止调度、释放环境、取消未完成子任务；记录保留可查。
     */
    public static function softDeleteBatch(int $batchId): array
    {
        self::ensureSchema();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch) {
            throw new \RuntimeException('总任务不存在');
        }
        if (!empty($batch['deleted_at']) || ($batch['status'] ?? '') === 'deleted') {
            return DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]) ?: [
                'id' => $batchId,
                'status' => 'deleted',
            ];
        }

        AccountBrowserEnvService::releaseEnvByBatch($batchId, (string)($batch['locked_by'] ?? '') ?: null);

        DB::query(
            "UPDATE account_work_items
             SET status='cancelled',
                 finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP),
                 error_message=COALESCE(NULLIF(error_message,''), '总任务已删除'),
                 updated_at=CURRENT_TIMESTAMP
             WHERE batch_id=? AND status IN ('pending','running','paused')",
            [$batchId]
        );

        DB::query(
            "UPDATE account_work_batches
             SET status='deleted',
                 push_status='none',
                 deleted_at=CURRENT_TIMESTAMP,
                 locked_by='',
                 lease_until=NULL,
                 browser_env_id=NULL,
                 finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP),
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND deleted_at IS NULL",
            [$batchId]
        );

        return DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]) ?: [
            'id' => $batchId,
            'status' => 'deleted',
        ];
    }

    /**
     * 批量清除总任务（软删除）：供「勾选清除 / 全部清除」使用。
     * 语义与单个删除一致：取消未完成子任务、释放环境租用、写 deleted_at；
     * 记录仍保留，可在状态筛选「已删除」中查看。
     *
     * @param list<int> $ids 勾选的总任务 ID（$all=true 时忽略）
     * @param bool $all true = 按数据权限清除全部未删除总任务
     * @return array{requested:int,deleted:int,skipped:int,ids:list<int>}
     */
    public static function clearBatches(array $ids = [], bool $all = false): array
    {
        self::ensureSchema();
        DataScope::ensureColumns();
        [$ownSql, $ownParams] = DataScope::workBatchListSql('b');

        if ($all) {
            $rows = DB::select(
                "SELECT b.id FROM account_work_batches b
                 WHERE b.deleted_at IS NULL AND b.status<>'deleted'" . $ownSql,
                $ownParams
            ) ?: [];
            $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        } else {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $ids),
                static fn($v) => $v > 0
            )));
        }

        $requested = count($ids);
        if ($requested === 0) {
            return ['requested' => 0, 'deleted' => 0, 'skipped' => 0, 'ids' => []];
        }

        $deleted = 0;
        $skipped = 0;
        $done = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            // 只清除权限范围内、且尚未删除的总任务（越权/已删除计入 skipped）
            $live = DB::select(
                "SELECT b.id FROM account_work_batches b
                 WHERE b.id IN ({$ph}) AND b.deleted_at IS NULL AND b.status<>'deleted'" . $ownSql,
                array_merge($chunk, $ownParams)
            ) ?: [];
            $liveIds = array_map(static fn($r) => (int)$r['id'], $live);
            $skipped += count($chunk) - count($liveIds);
            if (!$liveIds) {
                continue;
            }
            $livePh = implode(',', array_fill(0, count($liveIds), '?'));
            try {
                DB::query(
                    "UPDATE account_work_items
                     SET status='cancelled',
                         finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP),
                         error_message=COALESCE(NULLIF(error_message,''), '总任务已清除'),
                         updated_at=CURRENT_TIMESTAMP
                     WHERE batch_id IN ({$livePh}) AND status IN ('pending','running','paused')",
                    $liveIds
                );
                DB::query(
                    "UPDATE account_work_batches
                     SET status='deleted',
                         push_status='none',
                         deleted_at=CURRENT_TIMESTAMP,
                         locked_by='',
                         lease_until=NULL,
                         browser_env_id=NULL,
                         finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP),
                         updated_at=CURRENT_TIMESTAMP
                     WHERE id IN ({$livePh}) AND deleted_at IS NULL",
                    $liveIds
                );
                AccountBrowserEnvService::releaseEnvByBatches($liveIds);
                $deleted += count($liveIds);
                $done = array_merge($done, $liveIds);
            } catch (\Throwable $e) {
                $skipped += count($liveIds);
            }
        }

        return [
            'requested' => $requested,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'ids' => $done,
        ];
    }

    public static function resumeBatch(int $batchId): array
    {
        self::ensureSchema();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch) {
            throw new \RuntimeException('总任务不存在');
        }
        if (!empty($batch['deleted_at']) || ($batch['status'] ?? '') === 'deleted') {
            throw new \RuntimeException('已删除的总任务不可恢复执行');
        }
        if (($batch['status'] ?? '') !== 'paused') {
            throw new \RuntimeException('仅已暂停的总任务可恢复');
        }
        DB::query(
            "UPDATE account_work_items
             SET status='pending', updated_at=CURRENT_TIMESTAMP
             WHERE batch_id=? AND status='paused'",
            [$batchId]
        );
        DB::query(
            "UPDATE account_work_batches
             SET status='pending', locked_by='', lease_until=NULL, finished_at=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$batchId]
        );
        return DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]) ?: ['id' => $batchId, 'status' => 'pending'];
    }

    public static function pauseItem(int $itemId): array
    {
        self::ensureSchema();
        $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]);
        if (!$item) {
            throw new \RuntimeException('子任务不存在');
        }
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$item['batch_id']]);
        if (!empty($batch['deleted_at']) || ($batch['status'] ?? '') === 'deleted') {
            throw new \RuntimeException('总任务已删除，无法操作子任务');
        }
        if (($item['status'] ?? '') !== 'pending') {
            throw new \RuntimeException('仅待执行的子任务可暂停');
        }
        DB::query(
            "UPDATE account_work_items SET status='paused', updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'",
            [$itemId]
        );
        return self::formatItem(DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]) ?: $item);
    }

    public static function resumeItem(int $itemId): array
    {
        self::ensureSchema();
        $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]);
        if (!$item) {
            throw new \RuntimeException('子任务不存在');
        }
        if (($item['status'] ?? '') !== 'paused') {
            throw new \RuntimeException('仅已暂停的子任务可恢复');
        }
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [(int)$item['batch_id']]);
        if (!empty($batch['deleted_at']) || ($batch['status'] ?? '') === 'deleted') {
            throw new \RuntimeException('总任务已删除，无法恢复子任务');
        }
        if ($batch && ($batch['status'] ?? '') === 'paused') {
            throw new \RuntimeException('请先恢复所属总任务，再恢复子任务');
        }
        DB::query(
            "UPDATE account_work_items SET status='pending', updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='paused'",
            [$itemId]
        );
        // 若总任务已结束但还有待执行项，拉回 pending
        if ($batch && in_array($batch['status'] ?? '', ['completed', 'failed', 'cancelled'], true)) {
            DB::query(
                "UPDATE account_work_batches
                 SET status='pending', finished_at=NULL, locked_by='', lease_until=NULL, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [(int)$item['batch_id']]
            );
        }
        return self::formatItem(DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]) ?: $item);
    }

    /**
     * 总任务重置：全部子任务 → pending，总任务 → pending（释放锁，计数归零）。
     */
    public static function resetBatch(int $batchId): array
    {
        self::ensureSchema();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch) {
            throw new \RuntimeException('总任务不存在');
        }
        if (!empty($batch['deleted_at']) || ($batch['status'] ?? '') === 'deleted') {
            throw new \RuntimeException('已删除的总任务不可重置，请先筛选「已删除」外的记录');
        }

        AccountBrowserEnvService::releaseEnvByBatch($batchId);

        DB::query(
            "UPDATE account_work_items
             SET status='pending',
                 error_message=NULL,
                 result_json=NULL,
                 started_at=NULL,
                 finished_at=NULL,
                 updated_at=CURRENT_TIMESTAMP
             WHERE batch_id=?",
            [$batchId]
        );
        $itemCount = (int)(DB::selectOne(
            'SELECT COUNT(*) AS c FROM account_work_items WHERE batch_id=?',
            [$batchId]
        )['c'] ?? 0);

        DB::query(
            "UPDATE account_work_batches
             SET status='pending',
                 push_status='none',
                 locked_by='',
                 lease_until=NULL,
                 browser_env_id=NULL,
                 heartbeat_at=NULL,
                 started_at=NULL,
                 finished_at=NULL,
                 last_error=NULL,
                 item_total=?,
                 item_success=0,
                 item_fail=0,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$itemCount, $batchId]
        );

        return DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId])
            ?: ['id' => $batchId, 'status' => 'pending', 'item_total' => $itemCount];
    }

    /**
     * 子任务重置：仅该子任务 → pending，并将所属总任务拉回 pending（其它子任务状态不变）。
     */
    public static function resetItem(int $itemId): array
    {
        self::ensureSchema();
        $item = DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]);
        if (!$item) {
            throw new \RuntimeException('子任务不存在');
        }
        $batchId = (int)$item['batch_id'];
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch) {
            throw new \RuntimeException('总任务不存在');
        }
        if (!empty($batch['deleted_at']) || ($batch['status'] ?? '') === 'deleted') {
            throw new \RuntimeException('总任务已删除，无法重置子任务');
        }

        // 若总任务正在跑且锁着环境，释放以便重新领取
        if (($batch['status'] ?? '') === 'running') {
            AccountBrowserEnvService::releaseEnvByBatch($batchId);
        }

        DB::query(
            "UPDATE account_work_items
             SET status='pending',
                 error_message=NULL,
                 result_json=NULL,
                 started_at=NULL,
                 finished_at=NULL,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$itemId]
        );

        $ok = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM account_work_items WHERE batch_id=? AND status='completed'",
            [$batchId]
        )['c'] ?? 0);
        $fail = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM account_work_items WHERE batch_id=? AND status='failed'",
            [$batchId]
        )['c'] ?? 0);

        DB::query(
            "UPDATE account_work_batches
             SET status='pending',
                 push_status='none',
                 locked_by='',
                 lease_until=NULL,
                 browser_env_id=NULL,
                 heartbeat_at=NULL,
                 finished_at=NULL,
                 last_error=NULL,
                 item_success=?,
                 item_fail=?,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$ok, $fail, $batchId]
        );

        return self::formatItem(DB::selectOne('SELECT * FROM account_work_items WHERE id=?', [$itemId]) ?: $item);
    }

    public static function finishBatch(int $batchId, string $workerId, ?string $forceStatus = null, string $errorMessage = ''): array
    {
        self::ensureSchema();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch) {
            throw new \RuntimeException('总任务不存在');
        }
        if (($batch['status'] ?? '') === 'paused') {
            return [
                'id' => $batchId,
                'status' => 'paused',
                'has_more' => true,
                'message' => '总任务已暂停',
            ];
        }
        if (($batch['locked_by'] ?? '') !== $workerId && ($batch['status'] ?? '') === 'running') {
            throw new \RuntimeException('总任务不属于当前节点');
        }

        // 仍有 pending：不关批次（允许继续跑）；除非强制取消/失败收口
        if (!in_array($forceStatus, ['cancelled', 'failed'], true) && self::hasPendingItems($batchId)) {
            return [
                'id' => $batchId,
                'status' => 'running',
                'has_more' => true,
                'message' => '仍有未执行分任务，保持会话',
            ];
        }

        $fresh = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        $success = (int)($fresh['item_success'] ?? 0);
        $fail = (int)($fresh['item_fail'] ?? 0);
        $err = mb_substr(trim($errorMessage), 0, 500);
        if ($forceStatus === 'cancelled') {
            $status = 'cancelled';
            DB::query(
                "UPDATE account_work_items SET status='cancelled', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE batch_id=? AND status IN ('pending','running','paused')",
                [$batchId]
            );
        } elseif ($forceStatus === 'failed') {
            $status = 'failed';
            $openItems = DB::select(
                "SELECT id, task_type, task_ref_id, payload_json FROM account_work_items
                 WHERE batch_id=? AND status IN ('pending','running','paused')",
                [$batchId]
            ) ?: [];
            $pending = count($openItems);
            DB::query(
                "UPDATE account_work_items
                 SET status='failed', error_message=?, finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE batch_id=? AND status IN ('pending','running','paused')",
                [$err !== '' ? $err : '执行失败', $batchId]
            );
            if ($pending > 0) {
                DB::query(
                    'UPDATE account_work_batches SET item_fail=item_fail+?, last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$pending, $err !== '' ? $err : '执行失败', $batchId]
                );
                $fail += $pending;
            } elseif ($err !== '') {
                DB::query(
                    'UPDATE account_work_batches SET last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$err, $batchId]
                );
            }
            // 批量失败时补调父任务/拓客收口（跳过了逐条 finishItem）
            $seenRefs = [];
            foreach ($openItems as $it) {
                $tt = (string)($it['task_type'] ?? '');
                $ref = (int)($it['task_ref_id'] ?? 0);
                $key = $tt . ':' . $ref;
                if ($ref < 1 || isset($seenRefs[$key])) {
                    continue;
                }
                $seenRefs[$key] = true;
                try {
                    self::maybeFinishParentTask($tt, $ref);
                } catch (\Throwable $e) {
                }
                if ($tt === 'ai_lead' && $ref > 0) {
                    try {
                        AiLeadExecutionService::markRunFinished($ref, false, [
                            'error' => $err !== '' ? $err : '执行失败',
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
            }
        } else {
            $status = ($fail > 0 && $success < 1) ? 'failed' : 'completed';
        }

        DB::query(
            "UPDATE account_work_batches
             SET status=?, push_status='none', locked_by='', lease_until=NULL, finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$status, $batchId]
        );
        AccountBrowserEnvService::releaseEnvByBatch($batchId, $workerId);

        return [
            'id' => $batchId,
            'status' => $status,
            'item_success' => $success,
            'item_fail' => $fail,
            'has_more' => false,
        ];
    }

    /** 取消某任务主表关联的未执行分项 */
    public static function cancelItemsForTask(string $taskType, int $taskRefId): int
    {
        self::ensureSchema();
        $stmt = DB::query(
            "UPDATE account_work_items
             SET status='cancelled', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP, error_message='任务已取消'
             WHERE task_type=? AND task_ref_id=? AND status IN ('pending','running')",
            [$taskType, $taskRefId]
        );
        $n = $stmt->rowCount();
        if ($n < 1) {
            return 0;
        }
        // 若批次已无 pending/running 项，收尾
        $batchIds = array_column(
            DB::select(
                'SELECT DISTINCT batch_id FROM account_work_items WHERE task_type=? AND task_ref_id=?',
                [$taskType, $taskRefId]
            ) ?: [],
            'batch_id'
        );
        foreach ($batchIds as $bid) {
            if (!self::hasActiveItems((int)$bid)) {
                DB::query(
                    "UPDATE account_work_batches
                     SET status=IF(item_fail>0 AND item_success<1,'failed','completed'),
                         locked_by='', lease_until=NULL,
                         finished_at=COALESCE(finished_at,CURRENT_TIMESTAMP),
                         updated_at=CURRENT_TIMESTAMP
                     WHERE id=? AND status IN ('pending','running')",
                    [(int)$bid]
                );
            }
        }
        return $n;
    }

    public static function hasPendingItems(int $batchId): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS c FROM account_work_items WHERE batch_id=? AND status='pending'",
            [$batchId]
        );
        return (int)($row['c'] ?? 0) > 0;
    }

    public static function hasActiveItems(int $batchId): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS c FROM account_work_items WHERE batch_id=? AND status IN ('pending','running')",
            [$batchId]
        );
        return (int)($row['c'] ?? 0) > 0;
    }

    public static function reclaimExpiredLeases(): void
    {
        $expired = DB::select(
            "SELECT id FROM account_work_batches
             WHERE status='running' AND lease_until IS NOT NULL AND lease_until < CURRENT_TIMESTAMP"
        ) ?: [];
        foreach ($expired as $row) {
            AccountBrowserEnvService::releaseEnvByBatch((int)$row['id']);
        }
        DB::query(
            "UPDATE account_work_batches
             SET status='pending', push_status='none', locked_by='', lease_until=NULL, browser_env_id=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE status='running' AND lease_until IS NOT NULL AND lease_until < CURRENT_TIMESTAMP
               AND deleted_at IS NULL"
        );
        DB::query(
            "UPDATE account_work_items i
             INNER JOIN account_work_batches b ON b.id=i.batch_id
             SET i.status='pending', i.updated_at=CURRENT_TIMESTAMP
             WHERE i.status='running' AND b.status='pending'"
        );
        AccountBrowserEnvService::reclaimExpiredEnvLeases();
    }

    /**
     * 强制失败收口（不要求 worker 租约匹配）。
     * 用于比特权限等不可重试错误，避免 release 后无限重推。
     *
     * @param bool $notifyParents false=仅终止批次/释放环境（重新推送时跳过写父任务失败态）
     */
    public static function forceFailBatch(int $batchId, string $errorMessage = '', bool $notifyParents = true): array
    {
        self::ensureSchema();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch) {
            throw new \RuntimeException('总任务不存在');
        }
        $st = (string)($batch['status'] ?? '');
        if (in_array($st, ['completed', 'failed', 'cancelled', 'deleted'], true)) {
            // 仍尽量释放环境，避免残留 busy 挡重新推送
            try {
                AccountBrowserEnvService::releaseEnvByBatch($batchId, null);
            } catch (\Throwable $e) {
            }
            return [
                'id' => $batchId,
                'status' => $st,
                'forced' => false,
                'message' => '总任务已结束',
                'account_id' => (int)($batch['account_id'] ?? 0),
            ];
        }
        $err = mb_substr(trim($errorMessage) !== '' ? trim($errorMessage) : '执行失败', 0, 500);
        $workerId = (string)($batch['locked_by'] ?? '');
        $openItems = DB::select(
            "SELECT id, task_type, task_ref_id FROM account_work_items
             WHERE batch_id=? AND status IN ('pending','running','paused')",
            [$batchId]
        ) ?: [];
        $pending = count($openItems);
        DB::query(
            "UPDATE account_work_items
             SET status='failed', error_message=?, finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
             WHERE batch_id=? AND status IN ('pending','running','paused')",
            [$err, $batchId]
        );
        if ($pending > 0) {
            DB::query(
                'UPDATE account_work_batches SET item_fail=item_fail+?, last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$pending, $err, $batchId]
            );
        } else {
            DB::query(
                'UPDATE account_work_batches SET last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$err, $batchId]
            );
        }
        DB::query(
            "UPDATE account_work_batches
             SET status='failed', push_status='none', locked_by='', lease_until=NULL,
                 finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$batchId]
        );
        AccountBrowserEnvService::releaseEnvByBatch($batchId, $workerId !== '' ? $workerId : null);

        if ($notifyParents) {
            $seenRefs = [];
            foreach ($openItems as $it) {
                $tt = (string)($it['task_type'] ?? '');
                $ref = (int)($it['task_ref_id'] ?? 0);
                $key = $tt . ':' . $ref;
                if ($ref < 1 || isset($seenRefs[$key])) {
                    continue;
                }
                $seenRefs[$key] = true;
                try {
                    self::maybeFinishParentTask($tt, $ref);
                } catch (\Throwable $e) {
                }
                if ($tt === 'ai_lead') {
                    try {
                        AiLeadExecutionService::markRunFinished($ref, false, ['error' => $err]);
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
        self::appendLog($batchId, 0, '强制失败收口：' . $err, 'error', (int)($batch['account_id'] ?? 0), 0, [
            'event' => 'force_fail',
            'notify_parents' => $notifyParents,
        ]);

        return [
            'id' => $batchId,
            'status' => 'failed',
            'forced' => true,
            'item_fail_added' => $pending,
            'last_error' => $err,
            'account_id' => (int)($batch['account_id'] ?? 0),
        ];
    }

    /**
     * 将已领取但未真正执行的总任务退回 pending（不取消分任务）。
     * 用于：WSS 推送失败 / 前台未接单 / Node 重启后孤儿租约。
     */
    public static function releaseClaim(int $batchId, string $workerId): array
    {
        self::ensureSchema();
        $batch = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if (!$batch) {
            throw new \RuntimeException('总任务不存在');
        }
        if (($batch['status'] ?? '') !== 'running') {
            return [
                'id' => $batchId,
                'status' => $batch['status'] ?? '',
                'released' => false,
                'message' => '总任务未在运行中',
            ];
        }
        if (($batch['locked_by'] ?? '') !== '' && ($batch['locked_by'] ?? '') !== $workerId) {
            throw new \RuntimeException('总任务不属于当前节点');
        }

        AccountBrowserEnvService::releaseEnvByBatch($batchId, $workerId);
        DB::query(
            "UPDATE account_work_items
             SET status='pending', updated_at=CURRENT_TIMESTAMP
             WHERE batch_id=? AND status='running'",
            [$batchId]
        );
        DB::query(
            "UPDATE account_work_batches
             SET status='pending', push_status='none', locked_by='', lease_until=NULL, browser_env_id=NULL,
                 heartbeat_at=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='running'",
            [$batchId]
        );

        return [
            'id' => $batchId,
            'status' => 'pending',
            'push_status' => 'none',
            'released' => true,
        ];
    }

    /** 前台已接单开始执行：offered → executing，避免被重复补推 */
    public static function markPushExecuting(int $batchId, string $workerId): bool
    {
        self::ensureSchema();
        $stmt = DB::query(
            "UPDATE account_work_batches
             SET push_status='executing', heartbeat_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='running' AND locked_by=? AND push_status IN ('offered','none','executing')",
            [$batchId, $workerId]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * 仅列出「已推送未执行」的孤儿会话（push_status=offered），供 Node 重启后补推。
     * executing 中的不再补推，避免一直刷 WSS。
     * @return list<array<string,mixed>>
     */
    public static function listClaimedByWorker(string $workerId, int $limit = 10): array
    {
        self::ensureSchema();
        self::reclaimExpiredLeases();
        $rows = DB::select(
            "SELECT b.* FROM account_work_batches b
             WHERE b.status='running' AND b.locked_by=?
               AND b.deleted_at IS NULL
               AND COALESCE(b.push_status,'none')='offered'
               AND EXISTS (
                 SELECT 1 FROM account_work_items i
                 WHERE i.batch_id=b.id AND i.status='pending'
               )
             ORDER BY b.id ASC
             LIMIT " . max(1, min(50, $limit)),
            [$workerId]
        ) ?: [];

        $out = [];
        foreach ($rows as $fresh) {
            $account = DB::selectOne(
                'SELECT id, username, browser_profile_id, browser_backend, tkswarm_browser_profile_id, linux_browser_profile_id, login_status, has_session, enabled FROM accounts WHERE id=?',
                [(int)$fresh['account_id']]
            );
            $env = null;
            if (!empty($fresh['browser_env_id'])) {
                $env = DB::selectOne('SELECT * FROM account_browser_envs WHERE id=?', [(int)$fresh['browser_env_id']]);
            }
            $out[] = self::enrichBatchClaim($fresh, $account, $env);
        }
        return $out;
    }

    /**
     * 从 tasks 行展开为账号槽位（与 NodeBridge claim 逻辑对齐）。
     * @return list<array{account_id:int, payload:array<string,mixed>}>
     */
    public static function expandTaskSlots(array $task): array
    {
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $slots = [];

        if (is_array($payload['pairs'] ?? null)) {
            foreach ($payload['pairs'] as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $aid = (int)($pair['accountId'] ?? $pair['account_id'] ?? 0);
                $mid = (int)($pair['materialId'] ?? $pair['material_id'] ?? 0);
                if ($aid < 1) {
                    continue;
                }
                $slots[] = [
                    'account_id' => $aid,
                    'payload' => array_merge($payload, [
                        'accountId' => $aid,
                        'materialId' => $mid ?: null,
                    ]),
                ];
            }
            return $slots;
        }

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
        if (!$accountIds) {
            return [];
        }

        $materialIds = array_values(array_filter(array_map('intval', $payload['materialIds'] ?? [])));
        $strategy = (string)($payload['publishStrategy'] ?? $payload['publishMode'] ?? 'sequential');
        $matCount = count($materialIds);
        if ($strategy === 'random' && $matCount > 1) {
            shuffle($materialIds);
        }

        foreach ($accountIds as $i => $aid) {
            $mid = null;
            if ($matCount) {
                if (in_array($strategy, ['deduplicate', 'independent'], true)) {
                    $mid = $materialIds[$i] ?? null;
                } else {
                    $mid = $materialIds[$i % $matCount];
                }
            }
            $slotPayload = array_merge($payload, [
                'accountId' => $aid,
                'materialId' => $mid,
            ]);
            // 养号：优先用保存时按账号固化的计划，缺省再现算
            if ((string)($task['type'] ?? '') === 'warm') {
                $plans = is_array($payload['executionPlans'] ?? null) ? $payload['executionPlans'] : [];
                $plan = $plans[(string)$aid] ?? ($payload['executionPlan'] ?? null);
                if (!is_array($plan) || empty($plan['keywords'])) {
                    $plan = WarmExecutionPlan::build($payload);
                }
                $slotPayload['executionPlan'] = $plan;
                $slotPayload['commentText'] = WarmExecutionPlan::COMMENT_TEXT;
            }
            $slots[] = [
                'account_id' => $aid,
                'payload' => $slotPayload,
            ];
        }
        return $slots;
    }

    /** @param array<string,mixed>|null $result */
    /**
     * 发布成功后回写素材：publish_status + 关联账号
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $result
     */
    public static function markMaterialPublishedFromWorkItem(array $item, ?array $result = null): bool
    {
        $payload = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
        $result = is_array($result) ? $result : [];
        $mid = (int)($payload['materialId'] ?? $payload['material_id'] ?? 0);
        if ($mid < 1) {
            $mid = (int)($result['materialId'] ?? $result['material_id'] ?? 0);
        }
        if ($mid < 1 && is_array($result['material'] ?? null)) {
            $mid = (int)($result['material']['id'] ?? 0);
        }
        $accountId = (int)($item['account_id'] ?? 0);
        if ($accountId < 1) {
            $accountId = (int)($result['accountId'] ?? $result['account_id'] ?? 0);
        }
        $deleteAfter = !empty($payload['deleteAfterPublish']) || !empty($result['deleteAfterPublish']);
        // 父任务 payload 也可能带 deleteAfterPublish
        $taskRefId = (int)($item['task_ref_id'] ?? 0);
        if (!$deleteAfter && $taskRefId > 0) {
            $task = DB::selectOne('SELECT payload FROM tasks WHERE id=?', [$taskRefId]);
            $taskPayload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
            $deleteAfter = !empty($taskPayload['deleteAfterPublish']);
        }
        return self::markMaterialPublished($mid, $accountId, $deleteAfter, (string)($result['videoId'] ?? $result['video_id'] ?? ''), (string)($result['videoUrl'] ?? $result['video_url'] ?? ''));
    }

    public static function markMaterialPublished(
        int $materialId,
        int $accountId = 0,
        bool $deleteAfter = false,
        string $platformVideoId = '',
        string $platformVideoUrl = ''
    ): bool {
        if ($materialId < 1) {
            return false;
        }
        self::ensureMaterialsPublishColumns();
        $accountName = '';
        if ($accountId > 0) {
            $acc = DB::selectOne('SELECT username FROM accounts WHERE id=?', [$accountId]);
            $accountName = (string)($acc['username'] ?? '');
        }
        $platformVideoId = trim($platformVideoId);
        $platformVideoUrl = trim($platformVideoUrl);

        // 先写状态+账号（必成功），再补平台 video_id
        if ($deleteAfter) {
            DB::query(
                "UPDATE materials
                 SET publish_status='published', publish_account_id=?, publish_account=?, status='missing', updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$accountId > 0 ? $accountId : null, $accountName, $materialId]
            );
        } else {
            DB::query(
                "UPDATE materials
                 SET publish_status='published', publish_account_id=?, publish_account=?, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$accountId > 0 ? $accountId : null, $accountName, $materialId]
            );
        }
        if ($platformVideoId !== '' || $platformVideoUrl !== '') {
            DB::query(
                "UPDATE materials
                 SET platform_video_id=IF(?='', platform_video_id, ?),
                     platform_video_url=IF(?='', platform_video_url, ?),
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$platformVideoId, $platformVideoId, $platformVideoUrl, $platformVideoUrl, $materialId]
            );
        }
        if ($accountId > 0) {
            try {
                AiLeadVideoWeightService::recordFirstVideoPublished($accountId);
            } catch (\Throwable $e) {
            }
        }
        return true;
    }

    /** 确保 materials 发布相关列存在（避免迁移未跑导致整段 UPDATE 失败） */
    public static function ensureMaterialsPublishColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $need = [
            'publish_status' => "ALTER TABLE materials ADD COLUMN publish_status VARCHAR(32) NOT NULL DEFAULT 'unused'",
            'publish_account_id' => 'ALTER TABLE materials ADD COLUMN publish_account_id INT NULL',
            'publish_account' => "ALTER TABLE materials ADD COLUMN publish_account VARCHAR(191) NOT NULL DEFAULT ''",
            'platform_video_id' => "ALTER TABLE materials ADD COLUMN platform_video_id VARCHAR(64) NOT NULL DEFAULT ''",
            'platform_video_url' => "ALTER TABLE materials ADD COLUMN platform_video_url VARCHAR(500) NOT NULL DEFAULT ''",
        ];
        try {
            $cols = DB::select('SHOW COLUMNS FROM materials') ?: [];
            $have = [];
            foreach ($cols as $c) {
                $have[strtolower((string)($c['Field'] ?? $c['field'] ?? ''))] = true;
            }
            foreach ($need as $col => $sql) {
                if (empty($have[$col])) {
                    try {
                        DB::query($sql);
                    } catch (\Throwable $e) {
                        // ignore race / already exists
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** 平台视频删除成功后，回写关联素材为未发布 */
    public static function clearMaterialPublishByPlatformVideo(int $accountId, string $videoId): void
    {
        $videoId = trim($videoId);
        if ($accountId < 1 || $videoId === '') {
            return;
        }
        self::ensureMaterialsPublishColumns();
        DB::query(
            "UPDATE materials
             SET publish_status='unused', platform_video_id='', platform_video_url='', updated_at=CURRENT_TIMESTAMP
             WHERE publish_account_id=? AND platform_video_id=?",
            [$accountId, $videoId]
        );
    }

    /** 独立主任务表类型，task_ref_id 不指向 legacy tasks 表 */
    private const STANDALONE_TASK_TYPES = ['auto_follow', 'pull_comments'];

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $result
     */
    private static function syncAutoFollowWorkItem(array $item, string $status, ?array $result, string $errorMessage): void
    {
        $payload = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
        $afItemId = (int)($payload['itemId'] ?? $payload['item_id'] ?? 0);
        if ($afItemId < 1) {
            return;
        }
        $inner = is_array($result['result'] ?? null) ? $result['result'] : (is_array($result) ? $result : []);
        try {
            AutoFollowService::finishItem($afItemId, [
                'success' => $status === 'completed',
                'alreadyFollowed' => (bool)($inner['alreadyFollowed'] ?? $inner['already_followed'] ?? false),
                'error' => $errorMessage !== '' ? $errorMessage : (string)($inner['error'] ?? ''),
                'followedAccountId' => (int)($payload['targetAccountId'] ?? $payload['target_account_id'] ?? 0),
                'followedTiktokUid' => (string)($payload['targetTiktokUid'] ?? $payload['target_tiktok_uid'] ?? ''),
                'followedUsername' => (string)($payload['targetUsername'] ?? $payload['target_username'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            // Node 可能已回写；幂等收口，不阻断账号总任务分项完成
        }
    }

    private static function mirrorItemToTask(array $item, string $status, ?array $result, string $errorMessage): void
    {
        $taskRefId = (int)($item['task_ref_id'] ?? 0);
        $accountId = (int)($item['account_id'] ?? 0);
        $taskType = (string)($item['task_type'] ?? '');
        if ($taskRefId < 1 || in_array($taskType, self::STANDALONE_TASK_TYPES, true)) {
            return;
        }
        $mapStatus = $status === 'completed' ? 'success' : ($status === 'skipped' ? 'skipped' : 'failed');
        DB::query(
            "INSERT INTO task_runs(task_id, account_id, status, error_message, started_at, finished_at)
             VALUES (?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)",
            [$taskRefId, $accountId ?: null, $mapStatus, $errorMessage]
        );
        DB::query(
            "INSERT INTO task_action_results(task_id, account_id, action_type, status, result_json, error_message)
             VALUES (?,?,?,?,?,?)",
            [
                $taskRefId,
                $accountId,
                $taskType,
                $mapStatus,
                $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE),
                $errorMessage,
            ]
        );
        if ($mapStatus === 'success') {
            DB::query('UPDATE tasks SET success_count=success_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$taskRefId]);
        } elseif ($mapStatus === 'failed') {
            DB::query('UPDATE tasks SET fail_count=fail_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$taskRefId]);
        }
    }

    private static function maybeFinishParentTask(string $taskType, int $taskRefId): void
    {
        if ($taskRefId < 1 || in_array($taskType, self::STANDALONE_TASK_TYPES, true)) {
            return;
        }
        // 同一 task_ref 下可能挂有关联分项（如 warm + diagnose），全部结束后再关父任务
        $open = DB::selectOne(
            "SELECT COUNT(*) AS c FROM account_work_items
             WHERE task_ref_id=? AND status IN ('pending','running')",
            [$taskRefId]
        );
        if ((int)($open['c'] ?? 0) > 0) {
            return;
        }
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [$taskRefId]);
        if (!$task || !in_array($task['status'] ?? '', ['queued', 'running'], true)) {
            return;
        }
        $success = (int)($task['success_count'] ?? 0);
        $fail = (int)($task['fail_count'] ?? 0);
        $status = ($fail > 0 && $success < 1) ? 'failed' : 'completed';
        DB::query(
            "UPDATE tasks SET status=?, finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$status, $taskRefId]
        );
        DB::query(
            'INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)',
            [$taskRefId, 'info', "账号会话编排结束：成功 {$success}，失败 {$fail}"]
        );
    }

    /** @param array<string,mixed>|null $account @param array<string,mixed>|null $env */
    private static function enrichBatchClaim(array $batch, ?array $account, ?array $env = null): array
    {
        $formatted = self::formatBatchClaim($batch, $account, $env);
        $items = DB::select(
            "SELECT task_type, task_ref_id, payload_json FROM account_work_items
             WHERE batch_id=? AND status IN ('pending','running')
             ORDER BY priority ASC, seq ASC, id ASC
             LIMIT 8",
            [(int)$batch['id']]
        ) ?: [];
        $formatted['items'] = array_map(static function (array $it): array {
            $payload = json_decode((string)($it['payload_json'] ?? '{}'), true) ?: [];
            return [
                'task_type' => (string)($it['task_type'] ?? ''),
                'task_ref_id' => (int)($it['task_ref_id'] ?? 0),
                'payload' => $payload,
            ];
        }, $items);
        $formatted['task_label'] = self::batchTaskLabel($items);
        return $formatted;
    }

    /** @param list<array<string,mixed>> $items */
    private static function batchTaskLabel(array $items): string
    {
        if (!$items) {
            return '账号会话';
        }
        $first = $items[0];
        $type = (string)($first['task_type'] ?? '');
        $labels = [
            'ai_lead' => 'AI拓客',
            'ai_lead_dm' => 'AI拓客私信测试',
            'warm' => '养号',
            'publish' => '发布视频',
            'comment_reply' => '回复评论',
            'pull_comments' => '拉取视频数据',
            'auto_follow' => '自动增粉',
            'dm_reply' => '回复私信',
        ];
        $base = $labels[$type] ?? $type;
        if ($type === 'ai_lead') {
            $payload = json_decode((string)($first['payload_json'] ?? '{}'), true) ?: [];
            $leadId = (int)($payload['leadId'] ?? $payload['lead_id'] ?? $first['task_ref_id'] ?? 0);
            if ($leadId > 0) {
                return $base . ' #' . $leadId;
            }
        }
        if ($type === 'auto_follow') {
            $payload = json_decode((string)($first['payload_json'] ?? '{}'), true) ?: [];
            $taskId = (int)($payload['taskId'] ?? $payload['task_id'] ?? $first['task_ref_id'] ?? 0);
            if ($taskId > 0) {
                return $base . ' #' . $taskId;
            }
        }
        return $base;
    }

    /** 从批次待执行分项 payload 读取任务指定的浏览器后端 */
    private static function preferredBackendFromBatch(int $batchId): ?string
    {
        $item = DB::selectOne(
            "SELECT payload_json FROM account_work_items
             WHERE batch_id=? AND status IN ('pending','running')
             ORDER BY priority ASC, seq ASC, id ASC
             LIMIT 1",
            [$batchId]
        );
        if (!$item) {
            return null;
        }
        $payload = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
        $be = strtolower(trim((string)($payload['browserBackend'] ?? $payload['browser_backend'] ?? '')));
        if ($be === 'tkswarm' || $be === 'bit' || $be === 'linux') {
            return $be;
        }
        return null;
    }

    /**
     * 服务器浏览器（Linux）：不走中心库环境租用锁（多人共用服务器）；
     * 预填 linux / tkswarm 共用 profileId，供 node 拉包或复用；皆空则 node 补建。
     */
    private static function leaseOrServerEnv(
        int $accountId,
        int $batchId,
        string $workerId,
        int $leaseSeconds = 300,
        ?string $preferredBackend = null
    ): ?array {
        if (strtolower(trim((string)$preferredBackend)) === 'linux') {
            $acc = DB::selectOne(
                'SELECT linux_browser_profile_id, tkswarm_browser_profile_id FROM accounts WHERE id=?',
                [$accountId]
            ) ?: [];
            $profileId = trim((string)($acc['linux_browser_profile_id'] ?? ''));
            if ($profileId === '') {
                $profileId = trim((string)($acc['tkswarm_browser_profile_id'] ?? ''));
            }
            return [
                'id' => 0,
                'account_id' => $accountId,
                'browser_profile_id' => $profileId,
                'browser_backend' => 'linux',
                'is_primary' => 0,
            ];
        }
        return AccountBrowserEnvService::leaseEnv(
            $accountId,
            $batchId,
            $workerId,
            $leaseSeconds,
            $preferredBackend
        );
    }
    /** 服务器浏览器（Linux）只给 AI 拓客 / 客服类任务用，其它类型直接拒绝 */
    private static function assertServerBrowserTask(string $taskType): void
    {
        if (!in_array(strtolower(trim($taskType)), self::SERVER_BROWSER_TASK_TYPES, true)) {
            throw new \InvalidArgumentException(
                '服务器浏览器只用于 AI 拓客和在线多账号客服，' . $taskType . ' 任务不能使用'
            );
        }
    }

    /** 入队/推送用的归属：优先账号/任务 owner，否则当前有效身份（含切换后） */
    public static function resolveWorkOwnerId(int $accountId, ?int $ownerId = null): int
    {
        $owner = max(0, (int)($ownerId ?? 0));
        if ($owner < 1 && $accountId > 0) {
            $acc = DB::selectOne('SELECT owner_id FROM accounts WHERE id=?', [$accountId]);
            $owner = (int)($acc['owner_id'] ?? 0);
        }
        if ($owner < 1) {
            $owner = DataScope::writeOwnerId();
        }
        return max(0, $owner);
    }

    /**
     * 公海 batch（owner_id=0）在领取/推送时绑定到当前切换用户，便于 WSS 按 owner 匹配。
     *
     * @param array<string,mixed> $batch
     */
    public static function stampBatchOwnerFromScope(array &$batch): void
    {
        $batchId = (int)($batch['id'] ?? 0);
        if ($batchId < 1) {
            return;
        }
        $scopeOwner = DataScope::filterOwnerId();
        if ($scopeOwner === null || $scopeOwner < 1) {
            return;
        }
        $acc = DB::selectOne(
            'SELECT id, owner_id, company_id FROM accounts WHERE id=?',
            [(int)($batch['account_id'] ?? 0)]
        );
        if (!DataScope::accountOwnsRow($acc)) {
            return;
        }
        $cur = (int)($batch['owner_id'] ?? 0);
        if ($cur === $scopeOwner) {
            return;
        }
        // 切换身份后领取：将 batch 归属对齐当前有效用户，否则 WSS 按 owner 匹配会推失败
        DB::query(
            'UPDATE account_work_batches SET owner_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$scopeOwner, $batchId]
        );
        DB::query(
            'UPDATE account_work_items SET owner_id=? WHERE batch_id=?',
            [$scopeOwner, $batchId]
        );
        $batch['owner_id'] = $scopeOwner;
    }

    /**
     * @param array<string,mixed> $batch
     */
    private static function ensureBatchVisibleToScope(array $batch, ?int $ownerFilter): bool
    {
        $batchOwner = (int)($batch['owner_id'] ?? 0);
        if ($ownerFilter !== null && $ownerFilter > 0) {
            if ($batchOwner > 0 && $batchOwner !== $ownerFilter) {
                return false;
            }
            if ($batchOwner === 0) {
                $acc = DB::selectOne(
                    'SELECT id, owner_id, company_id FROM accounts WHERE id=?',
                    [(int)($batch['account_id'] ?? 0)]
                );
                return DataScope::accountOwnsRow($acc);
            }
        }
        return true;
    }

    private static function resolvePushOwnerId(array $batch): int
    {
        $ownerId = (int)($batch['owner_id'] ?? 0);
        if ($ownerId > 0) {
            return $ownerId;
        }
        $scopeOwner = DataScope::filterOwnerId();
        return ($scopeOwner !== null && $scopeOwner > 0) ? $scopeOwner : 0;
    }

    /** @param array<string,mixed>|null $account @param array<string,mixed>|null $env */
    private static function formatBatchClaim(array $batch, ?array $account, ?array $env = null): array
    {
        $profileId = (string)($env['browser_profile_id'] ?? $account['browser_profile_id'] ?? '');
        // 批次自己的后端最权威：AI 拓客/客服选了服务器浏览器时，不能被账号默认的 bit 覆盖，
        // 否则 node 会去开比特环境、按 127.0.0.1 写代理。
        $batchBackend = strtolower(trim((string)($batch['browser_backend'] ?? '')));
        $backend = in_array($batchBackend, ['bit', 'tkswarm', 'linux'], true)
            ? $batchBackend
            : strtolower(trim((string)($env['browser_backend'] ?? $account['browser_backend'] ?? 'bit')));
        if (!in_array($backend, ['bit', 'tkswarm', 'linux'], true)) {
            $backend = 'bit';
        }
        if ($backend === 'linux') {
            // 服务器与桌面自研共用环境包：优先 linux 绑定，其次 tkswarm 同 ID；仍空则 node 拉包/补建
            $profileId = trim((string)($account['linux_browser_profile_id'] ?? ''));
            if ($profileId === '') {
                $profileId = trim((string)($account['tkswarm_browser_profile_id'] ?? ''));
            }
        }
        $tkProfile = (string)($account['tkswarm_browser_profile_id'] ?? '');
        if ($backend === 'tkswarm' && $tkProfile === '' && $profileId !== '') {
            $tkProfile = $profileId;
        }
        return [
            'id' => (int)$batch['id'],
            'account_id' => (int)$batch['account_id'],
            'owner_id' => self::resolvePushOwnerId($batch),
            'batch_scope' => (string)($batch['batch_scope'] ?? self::BATCH_SCOPE_GENERAL),
            'browser_env_id' => (int)($env['id'] ?? $batch['browser_env_id'] ?? 0),
            'browser_profile_id' => $profileId,
            'browser_backend' => $backend,
            'status' => $batch['status'] ?? 'running',
            'push_status' => $batch['push_status'] ?? 'none',
            'locked_by' => $batch['locked_by'] ?? '',
            'lease_until' => $batch['lease_until'] ?? null,
            'item_total' => (int)($batch['item_total'] ?? 0),
            'item_success' => (int)($batch['item_success'] ?? 0),
            'item_fail' => (int)($batch['item_fail'] ?? 0),
            'need_login' => true,
            'account' => [
                'id' => (int)($account['id'] ?? $batch['account_id']),
                'username' => $account['username'] ?? '',
                'browser_profile_id' => $profileId,
                'browser_backend' => $backend,
                'tkswarm_browser_profile_id' => $tkProfile,
                'linux_browser_profile_id' => (string)($account['linux_browser_profile_id'] ?? ''),
                'login_status' => $account['login_status'] ?? 'offline',
                'has_session' => !empty($account['has_session']),
                'enabled' => !isset($account['enabled']) || !empty($account['enabled']),
            ],
        ];
    }

    /** @param array<string,mixed> $item */
    private static function formatItem(array $item): array
    {
        $payload = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
        $result = json_decode((string)($item['result_json'] ?? '{}'), true) ?: [];
        return [
            'id' => (int)$item['id'],
            'batch_id' => (int)$item['batch_id'],
            'account_id' => (int)$item['account_id'],
            'task_type' => $item['task_type'] ?? '',
            'task_ref_id' => (int)($item['task_ref_id'] ?? 0),
            'priority' => (int)($item['priority'] ?? 100),
            'seq' => (int)($item['seq'] ?? 0),
            'status' => $item['status'] ?? 'pending',
            'payload' => $payload,
            'result' => is_array($result) ? $result : [],
            'error_message' => (string)($item['error_message'] ?? ''),
            'original_username' => (string)($item['original_username'] ?? ''),
            'username_fail_reason' => (string)($item['username_fail_reason'] ?? ''),
            'nickname_error' => (string)($item['nickname_error'] ?? ''),
            'nickname_locked_at' => $item['nickname_locked_at'] ?? null,
        ];
    }

    /** 历史数据：从账号归属回填 owner_id */
    private static function backfillOwnerIds(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query(
                'UPDATE account_work_batches b
                 INNER JOIN accounts a ON a.id=b.account_id
                 SET b.owner_id=a.owner_id
                 WHERE b.owner_id=0 AND a.owner_id>0'
            );
            DB::query(
                'UPDATE account_work_items i
                 INNER JOIN account_work_batches b ON b.id=i.batch_id
                 SET i.owner_id=b.owner_id
                 WHERE i.owner_id=0 AND b.owner_id>0'
            );
            DB::query(
                'UPDATE account_work_items i
                 INNER JOIN accounts a ON a.id=i.account_id
                 SET i.owner_id=a.owner_id
                 WHERE i.owner_id=0 AND a.owner_id>0'
            );
        } catch (\Throwable $e) {
        }
    }
}
