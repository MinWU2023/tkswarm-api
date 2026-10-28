<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * AI营销 · 自动增粉：
 * 创建主任务 → 随机抽源账号生成明细时间链 → cron 到期入队账号总任务 → Node 关注成功写关系表。
 */
class AutoFollowService
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        DB::query(
            "CREATE TABLE IF NOT EXISTS auto_follow_tasks (
              id INT AUTO_INCREMENT PRIMARY KEY,
              owner_id INT NOT NULL DEFAULT 0,
              name VARCHAR(255) NOT NULL DEFAULT '',
              target_account_id INT NOT NULL DEFAULT 0,
              target_tiktok_uid VARCHAR(128) NOT NULL DEFAULT '',
              target_username VARCHAR(128) NOT NULL DEFAULT '',
              target_display VARCHAR(255) NOT NULL DEFAULT '',
              follow_count INT NOT NULL DEFAULT 0,
              min_interval_hours DECIMAL(10,2) NOT NULL DEFAULT 1,
              max_interval_hours DECIMAL(10,2) NOT NULL DEFAULT 2,
              search_keyword VARCHAR(255) NOT NULL DEFAULT '',
              stay_seconds_min INT NOT NULL DEFAULT 8,
              stay_seconds_max INT NOT NULL DEFAULT 20,
              watch_video_count INT NOT NULL DEFAULT 3,
              like_rate INT NOT NULL DEFAULT 40,
              comment_rate INT NOT NULL DEFAULT 20,
              comment_mode VARCHAR(16) NOT NULL DEFAULT 'ai',
              comment_ai_template_id INT NOT NULL DEFAULT 0,
              comment_ai_prompt TEXT NULL,
              comment_text VARCHAR(512) NOT NULL DEFAULT '',
              status VARCHAR(32) NOT NULL DEFAULT 'pending',
              scheduled_count INT NOT NULL DEFAULT 0,
              enqueued_count INT NOT NULL DEFAULT 0,
              success_count INT NOT NULL DEFAULT 0,
              failed_count INT NOT NULL DEFAULT 0,
              error_message VARCHAR(512) NOT NULL DEFAULT '',
              deleted_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_aft_owner (owner_id),
              KEY idx_aft_status (status),
              KEY idx_aft_target_acc (target_account_id),
              KEY idx_aft_target_uid (target_tiktok_uid)
            )             ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureTaskHomepageColumns();

        DB::query(
            "CREATE TABLE IF NOT EXISTS auto_follow_task_items (
              id INT AUTO_INCREMENT PRIMARY KEY,
              task_id INT NOT NULL DEFAULT 0,
              source_account_id INT NOT NULL DEFAULT 0,
              owner_id INT NOT NULL DEFAULT 0,
              scheduled_at DATETIME NOT NULL,
              action_plan_json MEDIUMTEXT NULL,
              status VARCHAR(32) NOT NULL DEFAULT 'pending',
              work_item_id INT NOT NULL DEFAULT 0,
              error_message VARCHAR(512) NOT NULL DEFAULT '',
              executed_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uk_afti_task_source (task_id, source_account_id),
              KEY idx_afti_due (status, scheduled_at),
              KEY idx_afti_source (source_account_id),
              KEY idx_afti_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureItemPlanColumn();

        DB::query(
            "CREATE TABLE IF NOT EXISTS account_follow_relations (
              id INT AUTO_INCREMENT PRIMARY KEY,
              follower_account_id INT NOT NULL DEFAULT 0,
              followed_account_id INT NOT NULL DEFAULT 0,
              followed_tiktok_uid VARCHAR(128) NOT NULL DEFAULT '',
              followed_username VARCHAR(128) NOT NULL DEFAULT '',
              source VARCHAR(64) NOT NULL DEFAULT 'auto_follow',
              task_id INT NOT NULL DEFAULT 0,
              task_item_id INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uk_afr_pair (follower_account_id, followed_account_id, followed_tiktok_uid),
              KEY idx_afr_follower (follower_account_id),
              KEY idx_afr_followed_acc (followed_account_id),
              KEY idx_afr_followed_uid (followed_tiktok_uid),
              KEY idx_afr_followed_user (followed_username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function ensureTaskHomepageColumns(): void
    {
        $cols = array_column(DB::select('SHOW COLUMNS FROM auto_follow_tasks') ?: [], 'Field');
        $alters = [
            'search_keyword' => "ADD COLUMN search_keyword VARCHAR(255) NOT NULL DEFAULT '' AFTER max_interval_hours",
            'stay_seconds_min' => "ADD COLUMN stay_seconds_min INT NOT NULL DEFAULT 8 AFTER search_keyword",
            'stay_seconds_max' => "ADD COLUMN stay_seconds_max INT NOT NULL DEFAULT 20 AFTER stay_seconds_min",
            'watch_video_count' => "ADD COLUMN watch_video_count INT NOT NULL DEFAULT 3 AFTER stay_seconds_max",
            'like_rate' => "ADD COLUMN like_rate INT NOT NULL DEFAULT 40 AFTER watch_video_count",
            'comment_rate' => "ADD COLUMN comment_rate INT NOT NULL DEFAULT 20 AFTER like_rate",
            'comment_mode' => "ADD COLUMN comment_mode VARCHAR(16) NOT NULL DEFAULT 'ai' AFTER comment_rate",
            'comment_ai_template_id' => "ADD COLUMN comment_ai_template_id INT NOT NULL DEFAULT 0 AFTER comment_mode",
            'comment_ai_prompt' => "ADD COLUMN comment_ai_prompt TEXT NULL AFTER comment_ai_template_id",
            'comment_text' => "ADD COLUMN comment_text VARCHAR(512) NOT NULL DEFAULT '' AFTER comment_ai_prompt",
        ];
        foreach ($alters as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    DB::query("ALTER TABLE auto_follow_tasks {$sql}");
                } catch (\Throwable $e) {
                }
            }
        }
    }

    private static function ensureItemPlanColumn(): void
    {
        $cols = array_column(DB::select('SHOW COLUMNS FROM auto_follow_task_items') ?: [], 'Field');
        if (!in_array('action_plan_json', $cols, true)) {
            try {
                DB::query('ALTER TABLE auto_follow_task_items ADD COLUMN action_plan_json MEDIUMTEXT NULL AFTER scheduled_at');
            } catch (\Throwable $e) {
            }
        }
    }

    public static function buildTaskName(): string
    {
        $user = DataScope::loginUser();
        $username = trim((string)($user['username'] ?? ''));
        $username = preg_replace('/\s+/u', '', $username) ?? '';
        if ($username === '') {
            $username = 'user' . max(0, (int)($user['id'] ?? 0));
        }
        return mb_substr($username . '-autofollow-' . date('YmdHis'), 0, 255);
    }

    /**
     * @return array{targetAccountId:int,targetTiktokUid:string,targetUsername:string,targetDisplay:string}
     */
    public static function resolveTarget(array $input): array
    {
        $targetAccountId = (int)($input['targetAccountId'] ?? $input['target_account_id'] ?? 0);
        $targetTiktokUid = trim((string)($input['targetTiktokUid'] ?? $input['target_tiktok_uid'] ?? ''));
        $targetUsername = ltrim(trim((string)($input['targetUsername'] ?? $input['target_username'] ?? '')), '@');
        $external = trim((string)($input['externalTarget'] ?? $input['external_target'] ?? ''));

        if ($targetAccountId > 0) {
            $acc = DB::selectOne('SELECT id, username, nickname FROM accounts WHERE id=?', [$targetAccountId]);
            if (!$acc) {
                throw new \InvalidArgumentException('系统账号不存在');
            }
            $uname = ltrim(trim((string)($acc['username'] ?? '')), '@');
            $nick = trim((string)($acc['nickname'] ?? ''));
            return [
                'targetAccountId' => (int)$acc['id'],
                'targetTiktokUid' => '',
                'targetUsername' => $uname,
                'targetDisplay' => $nick !== '' ? ($nick . ' (@' . $uname . ')') : ('@' . $uname . ' #' . (int)$acc['id']),
            ];
        }

        if ($external !== '') {
            if (ctype_digit($external) && strlen($external) <= 10) {
                $byId = DB::selectOne('SELECT id, username, nickname FROM accounts WHERE id=?', [(int)$external]);
                if ($byId) {
                    return self::resolveTarget(['targetAccountId' => (int)$byId['id']]);
                }
            }
            if (preg_match('/^\d{8,}$/', $external)) {
                $targetTiktokUid = $external;
            } else {
                $targetUsername = ltrim($external, '@');
            }
        }

        $targetUsername = ltrim($targetUsername, '@');
        if ($targetUsername !== '') {
            $acc = DB::selectOne(
                'SELECT id, username, nickname FROM accounts WHERE username=? LIMIT 1',
                [$targetUsername]
            );
            if ($acc) {
                return self::resolveTarget(['targetAccountId' => (int)$acc['id']]);
            }
        }

        if ($targetTiktokUid === '' && $targetUsername === '') {
            throw new \InvalidArgumentException('请选择系统账号，或填写外部 TikTok 用户名/UID');
        }

        $display = $targetUsername !== ''
            ? ('@' . $targetUsername)
            : ('UID:' . $targetTiktokUid);

        return [
            'targetAccountId' => 0,
            'targetTiktokUid' => mb_substr($targetTiktokUid, 0, 128),
            'targetUsername' => mb_substr($targetUsername, 0, 128),
            'targetDisplay' => mb_substr($display, 0, 255),
        ];
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private static function eligibleAccountsWhere(array $target): array
    {
        $targetAccountId = (int)($target['targetAccountId'] ?? 0);
        $targetUid = trim((string)($target['targetTiktokUid'] ?? ''));
        $targetUsername = ltrim(trim((string)($target['targetUsername'] ?? '')), '@');

        $filters = ['a.enabled=1'];
        $params = [];
        if ($targetAccountId > 0) {
            $filters[] = 'a.id <> ?';
            $params[] = $targetAccountId;
        }

        $relParts = [];
        $relParams = [];
        if ($targetAccountId > 0) {
            $relParts[] = 'followed_account_id=?';
            $relParams[] = $targetAccountId;
        }
        if ($targetUid !== '') {
            $relParts[] = "(followed_tiktok_uid<>'' AND followed_tiktok_uid=?)";
            $relParams[] = $targetUid;
        }
        if ($targetUsername !== '') {
            $relParts[] = "(followed_username<>'' AND LOWER(followed_username)=LOWER(?))";
            $relParams[] = $targetUsername;
        }
        if ($relParts) {
            $filters[] = 'a.id NOT IN (
              SELECT follower_account_id FROM account_follow_relations WHERE ' . implode(' OR ', $relParts) . '
            )';
            array_push($params, ...$relParams);
        }

        return ['WHERE ' . implode(' AND ', $filters), $params];
    }

    /**
     * 可选源账号：排除目标本身 + 已有关注关系 + 禁用账号。
     * @return list<array{id:int,owner_id:int,username:string}>
     */
    public static function eligibleAccounts(array $target): array
    {
        self::ensureSchema();
        [$where, $params] = self::eligibleAccountsWhere($target);
        $rows = DB::select(
            "SELECT a.id, a.owner_id, a.username FROM accounts a {$where} ORDER BY a.id ASC",
            $params
        ) ?: [];

        return array_map(static fn(array $r) => [
            'id' => (int)$r['id'],
            'owner_id' => (int)($r['owner_id'] ?? 0),
            'username' => (string)($r['username'] ?? ''),
        ], $rows);
    }

    public static function eligibleCount(array $target): int
    {
        self::ensureSchema();
        [$where, $params] = self::eligibleAccountsWhere($target);
        return (int)(DB::selectOne("SELECT COUNT(*) AS c FROM accounts a {$where}", $params)['c'] ?? 0);
    }

    /**
     * @return list<array{id:int,username:string,nickname:string,label:string}>
     */
    public static function searchSystemAccounts(string $keyword, int $limit = 30): array
    {
        $limit = max(1, min(50, $limit));
        $keyword = trim($keyword);
        $params = [];
        $filters = [];
        if ($keyword !== '') {
            if (ctype_digit($keyword)) {
                $filters[] = '(a.id=? OR a.username LIKE ? OR a.nickname LIKE ?)';
                $like = '%' . $keyword . '%';
                array_push($params, (int)$keyword, $like, $like);
            } else {
                $kw = ltrim($keyword, '@');
                $filters[] = '(a.username LIKE ? OR a.nickname LIKE ?)';
                $like = '%' . $kw . '%';
                array_push($params, $like, $like);
            }
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $rows = DB::select(
            "SELECT a.id, a.username, a.nickname FROM accounts a {$where} ORDER BY a.id DESC LIMIT {$limit}",
            $params
        ) ?: [];
        return array_map(static function (array $r) {
            $uname = ltrim(trim((string)($r['username'] ?? '')), '@');
            $nick = trim((string)($r['nickname'] ?? ''));
            return [
                'id' => (int)$r['id'],
                'username' => $uname,
                'nickname' => $nick,
                'label' => $nick !== '' ? ($nick . ' (@' . $uname . ')') : ('@' . $uname . ' #' . (int)$r['id']),
            ];
        }, $rows);
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,pageSize:int}
     */
    public static function listTasks(int $ownerId, int $page = 1, int $pageSize = 20, string $keyword = '', string $status = ''): array
    {
        self::ensureSchema();
        $filters = ['t.deleted_at IS NULL'];
        $params = [];
        if ($ownerId > 0) {
            $filters[] = 't.owner_id=?';
            $params[] = $ownerId;
        }
        if ($keyword !== '') {
            $filters[] = '(t.name LIKE ? OR t.target_display LIKE ? OR t.target_username LIKE ? OR CAST(t.id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($status !== '' && in_array($status, ['pending', 'running', 'completed', 'cancelled', 'failed'], true)) {
            $filters[] = 't.status=?';
            $params[] = $status;
        }
        $where = 'WHERE ' . implode(' AND ', $filters);
        $pageSize = max(1, min(50, $pageSize));
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM auto_follow_tasks t {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT t.* FROM auto_follow_tasks t {$where} ORDER BY t.id DESC LIMIT {$offset}, {$pageSize}",
            $params
        ) ?: [];

        $lockedIds = [];
        $taskIds = array_values(array_filter(array_map(static fn(array $r) => (int)$r['id'], $rows)));
        if ($taskIds) {
            $ph = implode(',', array_fill(0, count($taskIds), '?'));
            $lockedRows = DB::select(
                "SELECT DISTINCT task_id FROM auto_follow_task_items
                 WHERE task_id IN ({$ph}) AND status IN ('enqueued','running','success')",
                $taskIds
            ) ?: [];
            foreach ($lockedRows as $lr) {
                $lockedIds[(int)($lr['task_id'] ?? 0)] = true;
            }
        }

        return [
            'items' => array_map(
                static fn(array $r) => self::formatTask($r, !isset($lockedIds[(int)$r['id']])),
                $rows
            ),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => $pageSize,
        ];
    }

    public static function getTask(int $id, bool $withItems = false, int $itemPage = 1, int $itemPageSize = 50): ?array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM auto_follow_tasks WHERE id=? AND deleted_at IS NULL', [$id]);
        if (!$row) {
            return null;
        }
        $task = self::formatTask($row);
        if ($withItems) {
            $task['items'] = self::listItems($id, $itemPage, $itemPageSize);
        }
        return $task;
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,pageSize:int}
     */
    public static function listItems(int $taskId, int $page = 1, int $pageSize = 50): array
    {
        self::ensureSchema();
        $pageSize = max(1, min(100, $pageSize));
        $total = (int)(DB::selectOne(
            'SELECT COUNT(*) AS c FROM auto_follow_task_items WHERE task_id=?',
            [$taskId]
        )['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT i.*, a.username AS source_username, a.nickname AS source_nickname
             FROM auto_follow_task_items i
             LEFT JOIN accounts a ON a.id=i.source_account_id
             WHERE i.task_id=?
             ORDER BY i.scheduled_at ASC, i.id ASC
             LIMIT {$offset}, {$pageSize}",
            [$taskId]
        ) ?: [];

        return [
            'items' => array_map([self::class, 'formatItem'], $rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function create(array $input, int $ownerId): array
    {
        self::ensureSchema();
        $target = self::resolveTarget($input);
        $followCount = (int)($input['followCount'] ?? $input['follow_count'] ?? 0);
        $minH = (float)($input['minIntervalHours'] ?? $input['min_interval_hours'] ?? 0);
        $maxH = (float)($input['maxIntervalHours'] ?? $input['max_interval_hours'] ?? 0);

        if ($minH <= 0 || $maxH <= 0 || $minH > $maxH) {
            throw new \InvalidArgumentException('间隔小时须满足 0 < 最小间隔 ≤ 最大间隔');
        }
        $eligible = self::eligibleAccounts($target);
        $eligibleCount = count($eligible);
        if ($eligibleCount < 1) {
            throw new \InvalidArgumentException('没有可用的增粉源账号（可能均已关注该目标）');
        }
        if ($followCount < 1 || $followCount > $eligibleCount) {
            throw new \InvalidArgumentException("增粉数量须在 1 ~ {$eligibleCount} 之间");
        }

        $picked = self::pickRandom($eligible, $followCount);
        $schedule = self::buildSchedule($picked, $minH, $maxH);
        $homeCfg = AutoFollowHomepagePlan::normalizeTaskConfig($input, null);
        if ($homeCfg['searchKeyword'] === '') {
            $homeCfg['searchKeyword'] = (string)$target['targetUsername'];
        }
        $name = self::buildTaskName();

        $taskId = DB::transaction(static function () use (
            $ownerId,
            $name,
            $target,
            $followCount,
            $minH,
            $maxH,
            $schedule,
            $homeCfg
        ) {
            DB::query(
                "INSERT INTO auto_follow_tasks
                 (owner_id, name, target_account_id, target_tiktok_uid, target_username, target_display,
                  follow_count, min_interval_hours, max_interval_hours,
                  search_keyword, stay_seconds_min, stay_seconds_max, watch_video_count,
                  like_rate, comment_rate, comment_mode, comment_ai_template_id, comment_ai_prompt, comment_text,
                  status, scheduled_count)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?)",
                [
                    $ownerId,
                    $name,
                    $target['targetAccountId'],
                    $target['targetTiktokUid'],
                    $target['targetUsername'],
                    $target['targetDisplay'],
                    $followCount,
                    $minH,
                    $maxH,
                    $homeCfg['searchKeyword'],
                    $homeCfg['staySecondsMin'],
                    $homeCfg['staySecondsMax'],
                    $homeCfg['watchVideoCount'],
                    $homeCfg['likeRate'],
                    $homeCfg['commentRate'],
                    $homeCfg['commentMode'],
                    $homeCfg['commentAiTemplateId'],
                    $homeCfg['commentAiPrompt'],
                    $homeCfg['commentText'],
                    count($schedule),
                ]
            );
            $id = (int)DB::getPdo()->lastInsertId();
            self::insertItems($id, $schedule, $homeCfg, $target);
            return $id;
        });

        $task = self::getTask($taskId, true);
        if (!$task) {
            throw new \RuntimeException('创建失败');
        }
        return $task;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM auto_follow_tasks WHERE id=? AND deleted_at IS NULL', [$id]);
        if (!$row) {
            throw new \InvalidArgumentException('任务不存在');
        }
        if (!self::canRebuildSchedule($id)) {
            throw new \InvalidArgumentException('已有明细入队或执行，不可再改调度参数，仅可取消剩余待执行项');
        }

        $target = self::resolveTarget(array_merge([
            'targetAccountId' => (int)$row['target_account_id'],
            'targetTiktokUid' => (string)$row['target_tiktok_uid'],
            'targetUsername' => (string)$row['target_username'],
        ], $input));

        $followCount = array_key_exists('followCount', $input) || array_key_exists('follow_count', $input)
            ? (int)($input['followCount'] ?? $input['follow_count'] ?? 0)
            : (int)$row['follow_count'];
        $minH = array_key_exists('minIntervalHours', $input) || array_key_exists('min_interval_hours', $input)
            ? (float)($input['minIntervalHours'] ?? $input['min_interval_hours'] ?? 0)
            : (float)$row['min_interval_hours'];
        $maxH = array_key_exists('maxIntervalHours', $input) || array_key_exists('max_interval_hours', $input)
            ? (float)($input['maxIntervalHours'] ?? $input['max_interval_hours'] ?? 0)
            : (float)$row['max_interval_hours'];

        if ($minH <= 0 || $maxH <= 0 || $minH > $maxH) {
            throw new \InvalidArgumentException('间隔小时须满足 0 < 最小间隔 ≤ 最大间隔');
        }
        $eligible = self::eligibleAccounts($target);
        $eligibleCount = count($eligible);
        if ($followCount < 1 || $followCount > $eligibleCount) {
            throw new \InvalidArgumentException("增粉数量须在 1 ~ {$eligibleCount} 之间");
        }

        $picked = self::pickRandom($eligible, $followCount);
        $schedule = self::buildSchedule($picked, $minH, $maxH);
        $homeCfg = AutoFollowHomepagePlan::normalizeTaskConfig($input, [
            'search_keyword' => $row['search_keyword'] ?? '',
            'stay_seconds_min' => $row['stay_seconds_min'] ?? 8,
            'stay_seconds_max' => $row['stay_seconds_max'] ?? 20,
            'watch_video_count' => $row['watch_video_count'] ?? 3,
            'like_rate' => $row['like_rate'] ?? 40,
            'comment_rate' => $row['comment_rate'] ?? 20,
            'comment_mode' => $row['comment_mode'] ?? 'ai',
            'comment_ai_template_id' => $row['comment_ai_template_id'] ?? 0,
            'comment_ai_prompt' => $row['comment_ai_prompt'] ?? '',
            'comment_text' => $row['comment_text'] ?? '',
        ]);
        if ($homeCfg['searchKeyword'] === '') {
            $homeCfg['searchKeyword'] = (string)$target['targetUsername'];
        }

        DB::transaction(static function () use ($id, $target, $followCount, $minH, $maxH, $schedule, $homeCfg) {
            DB::query('DELETE FROM auto_follow_task_items WHERE task_id=?', [$id]);
            self::insertItems($id, $schedule, $homeCfg, $target);
            DB::query(
                "UPDATE auto_follow_tasks SET
                   target_account_id=?, target_tiktok_uid=?, target_username=?, target_display=?,
                   follow_count=?, min_interval_hours=?, max_interval_hours=?,
                   search_keyword=?, stay_seconds_min=?, stay_seconds_max=?, watch_video_count=?,
                   like_rate=?, comment_rate=?, comment_mode=?, comment_ai_template_id=?,
                   comment_ai_prompt=?, comment_text=?,
                   status='pending', scheduled_count=?, enqueued_count=0, success_count=0, failed_count=0,
                   error_message='', updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [
                    $target['targetAccountId'],
                    $target['targetTiktokUid'],
                    $target['targetUsername'],
                    $target['targetDisplay'],
                    $followCount,
                    $minH,
                    $maxH,
                    $homeCfg['searchKeyword'],
                    $homeCfg['staySecondsMin'],
                    $homeCfg['staySecondsMax'],
                    $homeCfg['watchVideoCount'],
                    $homeCfg['likeRate'],
                    $homeCfg['commentRate'],
                    $homeCfg['commentMode'],
                    $homeCfg['commentAiTemplateId'],
                    $homeCfg['commentAiPrompt'],
                    $homeCfg['commentText'],
                    count($schedule),
                    $id,
                ]
            );
        });

        $task = self::getTask($id, true);
        if (!$task) {
            throw new \RuntimeException('更新失败');
        }
        return $task;
    }

    public static function canRebuildSchedule(int $taskId): bool
    {
        $hit = DB::selectOne(
            "SELECT id FROM auto_follow_task_items
             WHERE task_id=? AND status IN ('enqueued','running','success')
             LIMIT 1",
            [$taskId]
        );
        return !$hit;
    }

    public static function cancel(int $id): array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM auto_follow_tasks WHERE id=? AND deleted_at IS NULL', [$id]);
        if (!$row) {
            throw new \InvalidArgumentException('任务不存在');
        }
        DB::query(
            "UPDATE auto_follow_task_items
             SET status='cancelled', updated_at=CURRENT_TIMESTAMP
             WHERE task_id=? AND status IN ('pending','enqueued')",
            [$id]
        );
        AccountWorkBatchService::cancelItemsForTask('auto_follow', $id);
        DB::query(
            "UPDATE auto_follow_tasks SET status='cancelled', updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$id]
        );
        $task = self::getTask($id, true);
        return $task ?: ['id' => $id, 'status' => 'cancelled'];
    }

    public static function delete(int $id): void
    {
        self::ensureSchema();
        self::cancel($id);
        DB::query(
            'UPDATE auto_follow_tasks SET deleted_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$id]
        );
    }

    /** 运营专员 / 运营管理角色用户随机一个 */
    public static function randomOpsOwnerId(): int
    {
        $row = DB::selectOne(
            "SELECT u.id
             FROM admin_users u
             INNER JOIN admin_roles r ON r.id=u.role_id
             WHERE u.status=1
               AND (r.name LIKE '%运营专员%' OR r.name LIKE '%运营管理%')
             ORDER BY RAND()
             LIMIT 1"
        );
        return (int)($row['id'] ?? 0);
    }

    public static function resolveItemOwnerId(int $sourceAccountId): int
    {
        $acc = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$sourceAccountId]);
        if (!$acc) {
            throw new \InvalidArgumentException('源账号不存在');
        }
        $owner = (int)($acc['owner_id'] ?? 0);
        if ($owner > 0) {
            return $owner;
        }
        $fallback = self::randomOpsOwnerId();
        if ($fallback < 1) {
            throw new \RuntimeException('账号无关联用户，且系统中无可用的运营专员/运营管理用户');
        }
        return $fallback;
    }

    /**
     * 将到期明细入队账号总任务。
     * @return array{ok:bool,reason?:string,batch_id?:int,item_id?:int,work_item_id?:int}
     */
    public static function enqueueDueItem(array $itemRow, array $taskRow): array
    {
        self::ensureSchema();
        AccountWorkBatchService::ensureSchema();

        $itemId = (int)($itemRow['id'] ?? 0);
        $taskId = (int)($taskRow['id'] ?? 0);
        $sourceAccountId = (int)($itemRow['source_account_id'] ?? 0);
        if ($itemId < 1 || $taskId < 1 || $sourceAccountId < 1) {
            return ['ok' => false, 'reason' => '明细数据无效'];
        }

        $status = (string)($itemRow['status'] ?? '');
        if ($status !== 'pending') {
            return ['ok' => false, 'reason' => '明细状态非 pending'];
        }

        $taskStatus = (string)($taskRow['status'] ?? '');
        if (in_array($taskStatus, ['cancelled', 'failed'], true)) {
            return ['ok' => false, 'reason' => '主任务已取消或失败'];
        }

        try {
            $ownerId = self::resolveItemOwnerId($sourceAccountId);
            $actionPlan = [];
            $rawPlan = (string)($itemRow['action_plan_json'] ?? '');
            if ($rawPlan !== '') {
                $decoded = json_decode($rawPlan, true);
                if (is_array($decoded)) {
                    $actionPlan = $decoded;
                }
            }
            if (!$actionPlan) {
                $homeCfg = AutoFollowHomepagePlan::normalizeTaskConfig([], [
                    'search_keyword' => $taskRow['search_keyword'] ?? '',
                    'stay_seconds_min' => $taskRow['stay_seconds_min'] ?? 8,
                    'stay_seconds_max' => $taskRow['stay_seconds_max'] ?? 20,
                    'watch_video_count' => $taskRow['watch_video_count'] ?? 3,
                    'like_rate' => $taskRow['like_rate'] ?? 40,
                    'comment_rate' => $taskRow['comment_rate'] ?? 20,
                    'comment_mode' => $taskRow['comment_mode'] ?? 'ai',
                    'comment_ai_template_id' => $taskRow['comment_ai_template_id'] ?? 0,
                    'comment_ai_prompt' => $taskRow['comment_ai_prompt'] ?? '',
                    'comment_text' => $taskRow['comment_text'] ?? '',
                ]);
                $actionPlan = AutoFollowHomepagePlan::build($homeCfg, [
                    'targetUsername' => (string)($taskRow['target_username'] ?? ''),
                    'targetDisplay' => (string)($taskRow['target_display'] ?? ''),
                ]);
                DB::query(
                    'UPDATE auto_follow_task_items SET action_plan_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [json_encode($actionPlan, JSON_UNESCAPED_UNICODE), $itemId]
                );
            }
            $payload = [
                'taskId' => $taskId,
                'itemId' => $itemId,
                'targetAccountId' => (int)($taskRow['target_account_id'] ?? 0),
                'targetTiktokUid' => (string)($taskRow['target_tiktok_uid'] ?? ''),
                'targetUsername' => (string)($taskRow['target_username'] ?? ''),
                'targetDisplay' => (string)($taskRow['target_display'] ?? ''),
                'searchKeyword' => (string)($actionPlan['searchKeyword'] ?? $taskRow['search_keyword'] ?? ''),
                'actionPlan' => $actionPlan,
            ];
            $enqueued = AccountWorkBatchService::enqueueItem(
                $sourceAccountId,
                'auto_follow',
                $payload,
                $taskId,
                $ownerId
            );
            DB::query(
                "UPDATE auto_follow_task_items
                 SET status='enqueued', owner_id=?, work_item_id=?, updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status='pending'",
                [$ownerId, (int)$enqueued['item_id'], $itemId]
            );
            DB::query(
                "UPDATE auto_follow_tasks
                 SET enqueued_count=enqueued_count+1,
                     status=IF(status='pending','running',status),
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$taskId]
            );
            return [
                'ok' => true,
                'batch_id' => (int)$enqueued['batch_id'],
                'item_id' => $itemId,
                'work_item_id' => (int)$enqueued['item_id'],
            ];
        } catch (\Throwable $e) {
            DB::query(
                "UPDATE auto_follow_task_items
                 SET status='failed', error_message=?, executed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [mb_substr($e->getMessage(), 0, 500), $itemId]
            );
            DB::query(
                "UPDATE auto_follow_tasks
                 SET failed_count=failed_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$taskId]
            );
            self::refreshTaskStatus($taskId);
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Node 回写关注结果。
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function finishItem(int $itemId, array $data): array
    {
        self::ensureSchema();
        $item = DB::selectOne('SELECT * FROM auto_follow_task_items WHERE id=?', [$itemId]);
        if (!$item) {
            throw new \InvalidArgumentException('明细不存在');
        }
        $taskId = (int)$item['task_id'];
        $task = DB::selectOne('SELECT * FROM auto_follow_tasks WHERE id=?', [$taskId]);
        if (!$task) {
            throw new \InvalidArgumentException('主任务不存在');
        }

        $success = (bool)($data['success'] ?? false);
        $already = (bool)($data['alreadyFollowed'] ?? $data['already_followed'] ?? false);
        $error = trim((string)($data['error'] ?? $data['errorMessage'] ?? ''));
        $followedUsername = ltrim(trim((string)($data['followedUsername'] ?? $data['followed_username'] ?? $task['target_username'] ?? '')), '@');
        $followedUid = trim((string)($data['followedTiktokUid'] ?? $data['followed_tiktok_uid'] ?? $task['target_tiktok_uid'] ?? ''));
        $followedAccountId = (int)($data['followedAccountId'] ?? $data['followed_account_id'] ?? $task['target_account_id'] ?? 0);
        $sourceAccountId = (int)$item['source_account_id'];

        if ($success || $already) {
            self::upsertFollowRelation(
                $sourceAccountId,
                $followedAccountId,
                $followedUid,
                $followedUsername,
                $taskId,
                $itemId
            );
            $prev = (string)$item['status'];
            DB::query(
                "UPDATE auto_follow_task_items
                 SET status='success', error_message='', executed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$itemId]
            );
            if (!in_array($prev, ['success'], true)) {
                DB::query(
                    'UPDATE auto_follow_tasks SET success_count=success_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$taskId]
                );
            }
        } else {
            $prev = (string)$item['status'];
            DB::query(
                "UPDATE auto_follow_task_items
                 SET status='failed', error_message=?, executed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [mb_substr($error !== '' ? $error : '关注失败', 0, 500), $itemId]
            );
            if (!in_array($prev, ['failed'], true)) {
                DB::query(
                    'UPDATE auto_follow_tasks SET failed_count=failed_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$taskId]
                );
            }
        }

        self::refreshTaskStatus($taskId);
        $out = self::getTask($taskId);
        return $out ?: ['id' => $taskId, 'itemId' => $itemId];
    }

    public static function refreshTaskStatus(int $taskId): void
    {
        $stats = DB::selectOne(
            "SELECT
               SUM(status='pending') AS pending_n,
               SUM(status='enqueued') AS enqueued_n,
               SUM(status='running') AS running_n,
               SUM(status='success') AS success_n,
               SUM(status='failed') AS failed_n,
               SUM(status='cancelled') AS cancelled_n,
               COUNT(*) AS total_n
             FROM auto_follow_task_items WHERE task_id=?",
            [$taskId]
        );
        $pending = (int)($stats['pending_n'] ?? 0);
        $enqueued = (int)($stats['enqueued_n'] ?? 0);
        $running = (int)($stats['running_n'] ?? 0);
        $success = (int)($stats['success_n'] ?? 0);
        $failed = (int)($stats['failed_n'] ?? 0);
        $total = (int)($stats['total_n'] ?? 0);

        $task = DB::selectOne('SELECT status FROM auto_follow_tasks WHERE id=?', [$taskId]);
        if (!$task || (string)$task['status'] === 'cancelled') {
            return;
        }

        $status = 'pending';
        if ($pending + $enqueued + $running > 0) {
            $status = ($enqueued + $running + $success + $failed) > 0 ? 'running' : 'pending';
        } elseif ($total > 0) {
            $status = ($success > 0 && $failed === 0) ? 'completed'
                : (($success === 0 && $failed > 0) ? 'failed' : 'completed');
        }

        DB::query(
            "UPDATE auto_follow_tasks
             SET status=?, success_count=?, failed_count=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status<>'cancelled'",
            [$status, $success, $failed, $taskId]
        );
    }

    public static function upsertFollowRelation(
        int $followerAccountId,
        int $followedAccountId,
        string $followedTiktokUid,
        string $followedUsername,
        int $taskId = 0,
        int $taskItemId = 0,
        string $source = 'auto_follow'
    ): void {
        self::ensureSchema();
        if ($followerAccountId < 1) {
            return;
        }
        $followedUsername = ltrim(trim($followedUsername), '@');
        $followedTiktokUid = trim($followedTiktokUid);
        // 系统目标：uid 空串；外部目标：account_id=0，uid 优先，否则用 username 填入 uid 槽保证唯一键可区分
        if ($followedAccountId > 0) {
            $followedTiktokUid = '';
        } elseif ($followedTiktokUid === '' && $followedUsername !== '') {
            $followedTiktokUid = 'u:' . mb_strtolower($followedUsername);
        }
        if ($followedAccountId < 1 && $followedTiktokUid === '') {
            return;
        }

        $exist = DB::selectOne(
            'SELECT id FROM account_follow_relations
             WHERE follower_account_id=? AND followed_account_id=? AND followed_tiktok_uid=?
             LIMIT 1',
            [$followerAccountId, $followedAccountId, mb_substr($followedTiktokUid, 0, 128)]
        );
        if ($exist) {
            DB::query(
                'UPDATE account_follow_relations
                 SET followed_username=?, source=?, task_id=?, task_item_id=?
                 WHERE id=?',
                [
                    mb_substr($followedUsername, 0, 128),
                    mb_substr($source, 0, 64),
                    $taskId,
                    $taskItemId,
                    (int)$exist['id'],
                ]
            );
            return;
        }
        DB::query(
            "INSERT INTO account_follow_relations
             (follower_account_id, followed_account_id, followed_tiktok_uid, followed_username, source, task_id, task_item_id)
             VALUES (?,?,?,?,?,?,?)",
            [
                $followerAccountId,
                $followedAccountId,
                mb_substr($followedTiktokUid, 0, 128),
                mb_substr($followedUsername, 0, 128),
                mb_substr($source, 0, 64),
                $taskId,
                $taskItemId,
            ]
        );
    }

    /**
     * @param list<array{id:int,owner_id:int,username:string}> $pool
     * @return list<array{id:int,owner_id:int,username:string}>
     */
    private static function pickRandom(array $pool, int $n): array
    {
        shuffle($pool);
        return array_slice($pool, 0, $n);
    }

    /**
     * @param list<array{id:int,owner_id:int,username:string}> $picked
     * @return list<array{source_account_id:int,scheduled_at:string}>
     */
    private static function buildSchedule(array $picked, float $minH, float $maxH): array
    {
        $out = [];
        $cursor = time();
        foreach ($picked as $i => $acc) {
            if ($i > 0) {
                $hours = self::randomHours($minH, $maxH);
                $cursor += (int)round($hours * 3600);
            }
            $out[] = [
                'source_account_id' => (int)$acc['id'],
                'scheduled_at' => date('Y-m-d H:i:s', $cursor),
            ];
        }
        return $out;
    }

    private static function randomHours(float $minH, float $maxH): float
    {
        if ($maxH <= $minH) {
            return $minH;
        }
        $minCenti = (int)round($minH * 100);
        $maxCenti = (int)round($maxH * 100);
        return random_int($minCenti, $maxCenti) / 100;
    }

    /**
     * @param list<array{source_account_id:int,scheduled_at:string}> $schedule
     * @param array<string,mixed> $homeCfg
     * @param array{targetUsername?:string,targetDisplay?:string} $target
     */
    private static function insertItems(int $taskId, array $schedule, array $homeCfg = [], array $target = []): void
    {
        foreach ($schedule as $row) {
            $plan = AutoFollowHomepagePlan::build($homeCfg, $target);
            DB::query(
                "INSERT INTO auto_follow_task_items
                 (task_id, source_account_id, scheduled_at, action_plan_json, status)
                 VALUES (?,?,?,?,'pending')",
                [
                    $taskId,
                    (int)$row['source_account_id'],
                    $row['scheduled_at'],
                    json_encode($plan, JSON_UNESCAPED_UNICODE),
                ]
            );
        }
    }

    /** @param array<string,mixed> $row */
    public static function formatTask(array $row, ?bool $canEdit = null): array
    {
        return [
            'id' => (int)$row['id'],
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'targetAccountId' => (int)($row['target_account_id'] ?? 0),
            'targetTiktokUid' => (string)($row['target_tiktok_uid'] ?? ''),
            'targetUsername' => (string)($row['target_username'] ?? ''),
            'targetDisplay' => (string)($row['target_display'] ?? ''),
            'followCount' => (int)($row['follow_count'] ?? 0),
            'minIntervalHours' => (float)($row['min_interval_hours'] ?? 0),
            'maxIntervalHours' => (float)($row['max_interval_hours'] ?? 0),
            'searchKeyword' => (string)($row['search_keyword'] ?? ''),
            'staySecondsMin' => (int)($row['stay_seconds_min'] ?? 8),
            'staySecondsMax' => (int)($row['stay_seconds_max'] ?? 20),
            'watchVideoCount' => (int)($row['watch_video_count'] ?? 3),
            'likeRate' => (int)($row['like_rate'] ?? 40),
            'commentRate' => (int)($row['comment_rate'] ?? 20),
            'commentMode' => (string)($row['comment_mode'] ?? 'ai'),
            'commentAiTemplateId' => (int)($row['comment_ai_template_id'] ?? 0),
            'commentAiPrompt' => (string)($row['comment_ai_prompt'] ?? ''),
            'commentText' => (string)($row['comment_text'] ?? ''),
            'status' => (string)($row['status'] ?? 'pending'),
            'scheduledCount' => (int)($row['scheduled_count'] ?? 0),
            'enqueuedCount' => (int)($row['enqueued_count'] ?? 0),
            'successCount' => (int)($row['success_count'] ?? 0),
            'failedCount' => (int)($row['failed_count'] ?? 0),
            'errorMessage' => (string)($row['error_message'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'canEdit' => $canEdit ?? self::canRebuildSchedule((int)$row['id']),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function formatItem(array $row): array
    {
        $uname = ltrim(trim((string)($row['source_username'] ?? '')), '@');
        $nick = trim((string)($row['source_nickname'] ?? ''));
        $plan = null;
        $raw = (string)($row['action_plan_json'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $plan = $decoded;
            }
        }
        return [
            'id' => (int)$row['id'],
            'taskId' => (int)($row['task_id'] ?? 0),
            'sourceAccountId' => (int)($row['source_account_id'] ?? 0),
            'sourceUsername' => $uname,
            'sourceNickname' => $nick,
            'sourceLabel' => $nick !== '' ? ($nick . ' (@' . $uname . ')') : ('@' . $uname . ' #' . (int)($row['source_account_id'] ?? 0)),
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'scheduledAt' => (string)($row['scheduled_at'] ?? ''),
            'actionPlan' => $plan,
            'status' => (string)($row['status'] ?? 'pending'),
            'workItemId' => (int)($row['work_item_id'] ?? 0),
            'errorMessage' => (string)($row['error_message'] ?? ''),
            'executedAt' => (string)($row['executed_at'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }
}
