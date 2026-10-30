<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * AI矩阵 · 拉取视频数据：
 * 按已发布视频素材所属账号分组创建分任务，挂入账号会话总任务；
 * Node 抓取后回写素材互动数据与评论树。
 */
class PullCommentService
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        DB::query(
            "CREATE TABLE IF NOT EXISTS pull_comment_tasks (
              id INT AUTO_INCREMENT PRIMARY KEY,
              owner_id INT NOT NULL DEFAULT 0,
              account_id INT NOT NULL DEFAULT 0,
              name VARCHAR(255) NOT NULL DEFAULT '',
              status VARCHAR(32) NOT NULL DEFAULT 'pending',
              browser_backend VARCHAR(32) NOT NULL DEFAULT 'bit',
              material_ids_json TEXT NULL,
              video_count INT NOT NULL DEFAULT 0,
              done_count INT NOT NULL DEFAULT 0,
              comment_count INT NOT NULL DEFAULT 0,
              error_message VARCHAR(512) NOT NULL DEFAULT '',
              started_at DATETIME NULL,
              finished_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_pct_owner (owner_id),
              KEY idx_pct_account (account_id),
              KEY idx_pct_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        DB::query(
            "CREATE TABLE IF NOT EXISTS material_comments (
              id INT AUTO_INCREMENT PRIMARY KEY,
              material_id INT NOT NULL DEFAULT 0,
              account_id INT NOT NULL DEFAULT 0,
              owner_id INT NOT NULL DEFAULT 0,
              parent_id INT NOT NULL DEFAULT 0,
              platform_comment_id VARCHAR(128) NOT NULL DEFAULT '',
              username VARCHAR(128) NOT NULL DEFAULT '',
              nickname VARCHAR(255) NOT NULL DEFAULT '',
              uuid VARCHAR(64) NOT NULL DEFAULT '',
              profile_url VARCHAR(500) NOT NULL DEFAULT '',
              avatar_url VARCHAR(500) NOT NULL DEFAULT '',
              comment_text TEXT NULL,
              digg_count INT NOT NULL DEFAULT 0,
              reply_count INT NOT NULL DEFAULT 0,
              comment_time VARCHAR(64) NOT NULL DEFAULT '',
              commented_at DATETIME NULL,
              pulled_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_mc_material (material_id),
              KEY idx_mc_parent (parent_id),
              KEY idx_mc_account (account_id),
              KEY idx_mc_user (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::ensureMaterialStatColumns();
    }

    private static function ensureMaterialStatColumns(): void
    {
        $cols = array_column(DB::select('SHOW COLUMNS FROM materials') ?: [], 'Field');
        $alters = [
            'view_count' => 'ALTER TABLE materials ADD COLUMN view_count BIGINT NOT NULL DEFAULT 0',
            'like_count' => 'ALTER TABLE materials ADD COLUMN like_count BIGINT NOT NULL DEFAULT 0',
            'comment_count' => 'ALTER TABLE materials ADD COLUMN comment_count INT NOT NULL DEFAULT 0',
            'platform_published_at' => 'ALTER TABLE materials ADD COLUMN platform_published_at DATETIME NULL',
            'last_comment_pulled_at' => 'ALTER TABLE materials ADD COLUMN last_comment_pulled_at DATETIME NULL',
            'origin' => "ALTER TABLE materials ADD COLUMN origin VARCHAR(32) NOT NULL DEFAULT 'local' COMMENT 'local=线下素材 online=Studio线上视频'",
        ];
        foreach ($alters as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    /**
     * 把 tiktok_videos 里尚无对应 materials 的条目补成 origin=online 素材，
     * 供「拉取视频数据」勾选拉评论（拉取视频列表入库失败时也能用）。
     *
     * @return array{created:int,skipped:int}
     */
    public static function backfillOnlineMaterialsFromSyncedVideos(int $accountId = 0, int $limit = 80): array
    {
        self::ensureSchema();
        AccountWorkBatchService::ensureMaterialsPublishColumns();
        // 历史线上素材 owner_id 为空时，按发布账号归属回填；账号亦为空则挂当前登录用户
        try {
            $fallbackOwner = 0;
            try {
                $fallbackOwner = DataScope::writeMaterialOwnerId();
            } catch (\Throwable $e) {
                $fallbackOwner = 0;
            }
            if ($fallbackOwner > 0) {
                DB::query(
                    "UPDATE materials m
                     LEFT JOIN accounts a ON a.id=m.publish_account_id
                     SET m.owner_id = COALESCE(NULLIF(a.owner_id, 0), ?)
                     WHERE m.origin='online' AND (m.owner_id IS NULL OR m.owner_id=0)",
                    [$fallbackOwner]
                );
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $limit = max(1, min(200, $limit));
        $params = [];
        $accSql = '';
        if ($accountId > 0) {
            $accSql = ' AND v.account_id=?';
            $params[] = $accountId;
        }
        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $params = array_merge($params, $scopeParams);
        // materials.platform_video_id 与 tiktok_videos.video_id 可能分属 unicode_ci / 0900_ai_ci，直接 = 会 1267
        $rows = DB::select(
            "SELECT v.account_id, v.video_id, v.video_url, v.description, v.thumbnail_url,
                    a.username, a.nickname, a.owner_id
             FROM tiktok_videos v
             INNER JOIN accounts a ON a.id=v.account_id
             WHERE v.video_id<>''
               AND NOT EXISTS (
                 SELECT 1 FROM materials m
                 WHERE m.publish_account_id=v.account_id
                   AND m.platform_video_id COLLATE utf8mb4_unicode_ci
                     = CONVERT(v.video_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
               )" . $accSql . $scopeSql . "
             ORDER BY v.id DESC
             LIMIT {$limit}",
            $params
        ) ?: [];
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $aid = (int)($row['account_id'] ?? 0);
            $videoId = trim((string)($row['video_id'] ?? ''));
            if ($aid < 1 || $videoId === '') {
                $skipped += 1;
                continue;
            }
            $accName = trim((string)($row['username'] ?? $row['nickname'] ?? ''));
            $videoUrl = trim((string)($row['video_url'] ?? ''));
            if ($videoUrl === '') {
                $videoUrl = $accName !== ''
                    ? ('https://www.tiktok.com/@' . rawurlencode($accName) . '/video/' . $videoId)
                    : ('https://www.tiktok.com/video/' . $videoId);
            }
            $titleRaw = trim((string)($row['description'] ?? ''));
            if ($titleRaw === '') {
                $titleRaw = '视频 ' . $videoId;
            }
            $name = mb_substr($titleRaw, 0, 191);
            $shareTitle = mb_substr($titleRaw, 0, 500);
            $thumb = trim((string)($row['thumbnail_url'] ?? ''));
            $coverStore = null;
            if ($thumb !== '' && (str_starts_with($thumb, 'http://') || str_starts_with($thumb, 'https://') || str_starts_with($thumb, 'data:'))) {
                $coverStore = mb_substr($thumb, 0, 2000);
            }
            // 公海账号 owner_id 为空时，挂到当前登录用户，否则 materialListSql 会把线上视频滤掉
            $ownerId = (int)($row['owner_id'] ?? 0);
            if ($ownerId < 1) {
                try {
                    $ownerId = DataScope::writeMaterialOwnerId();
                } catch (\Throwable $e) {
                    $ownerId = 0;
                }
            }
            $fileName = 'online-' . $videoId . '.mp4';
            try {
                DB::query(
                    "INSERT INTO materials(
                        name, path, type, file_path, file_name, file_ext, mime_type, size_bytes, description, tags, status,
                        publish_status, publish_account_id, publish_account, platform_video_id, platform_video_url,
                        sha256, cover_base64, share_title, share_caption, owner_id, origin
                     ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [
                        $name,
                        '',
                        'video',
                        '',
                        mb_substr($fileName, 0, 255),
                        'mp4',
                        'video/mp4',
                        0,
                        'Studio 线上视频',
                        'online-studio',
                        'ready',
                        'published',
                        $aid,
                        mb_substr($accName, 0, 191),
                        mb_substr($videoId, 0, 64),
                        mb_substr($videoUrl, 0, 500),
                        '',
                        $coverStore,
                        $shareTitle,
                        '',
                        $ownerId > 0 ? $ownerId : null,
                        'online',
                    ]
                );
                $created += 1;
            } catch (\Throwable $e) {
                $skipped += 1;
            }
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,pageSize:int}
     */
    public static function listTasks(int $ownerId, int $page = 1, int $pageSize = 20, string $keyword = '', string $status = ''): array
    {
        self::ensureSchema();
        $filters = [];
        $params = [];
        if ($ownerId > 0) {
            $filters[] = 't.owner_id=?';
            $params[] = $ownerId;
        }
        if ($keyword !== '') {
            $filters[] = '(t.name LIKE ? OR CAST(t.id AS CHAR) LIKE ? OR a.username LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }
        if ($status !== '' && in_array($status, ['pending', 'queued', 'running', 'completed', 'failed', 'cancelled'], true)) {
            $filters[] = 't.status=?';
            $params[] = $status;
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $pageSize = max(1, min(50, $pageSize));
        $total = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM pull_comment_tasks t
             LEFT JOIN accounts a ON a.id=t.account_id
             {$where}",
            $params
        )['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT t.*, a.username AS account_username, a.nickname AS account_nickname
             FROM pull_comment_tasks t
             LEFT JOIN accounts a ON a.id=t.account_id
             {$where}
             ORDER BY t.id DESC
             LIMIT {$offset}, {$pageSize}",
            $params
        ) ?: [];

        return [
            'items' => array_map([self::class, 'formatTask'], $rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => $pageSize,
        ];
    }

    public static function getTask(int $id): ?array
    {
        self::ensureSchema();
        $row = DB::selectOne(
            "SELECT t.*, a.username AS account_username, a.nickname AS account_nickname
             FROM pull_comment_tasks t
             LEFT JOIN accounts a ON a.id=t.account_id
             WHERE t.id=?",
            [$id]
        );
        return $row ? self::formatTask($row) : null;
    }

    /**
     * 选择已发布素材 → 按发布账号分组 → 每账号一条分任务并挂入总任务。
     * @param list<int> $materialIds
     * @return array{tasks:list<array<string,mixed>>,created:int,enqueued:int}
     */
    public static function createFromMaterials(array $materialIds, int $ownerId, string $browserBackend = 'bit', string $name = '', bool $restrictOwner = true): array
    {
        self::ensureSchema();
        AccountWorkBatchService::ensureSchema();

        $ids = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        if (!$ids) {
            throw new \InvalidArgumentException('请至少选择一个已发布视频素材');
        }
        $backend = $browserBackend === 'tkswarm' ? 'tkswarm' : 'bit';
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "SELECT id, name, publish_account_id, publish_account, platform_video_id, platform_video_url, owner_id
             FROM materials
             WHERE id IN ({$ph})",
            $ids
        ) ?: [];
        if (!$rows) {
            throw new \InvalidArgumentException('未找到所选素材');
        }

        $byAccount = [];
        foreach ($rows as $r) {
            $aid = (int)($r['publish_account_id'] ?? 0);
            $url = trim((string)($r['platform_video_url'] ?? ''));
            $vid = trim((string)($r['platform_video_id'] ?? ''));
            if ($aid < 1) {
                continue;
            }
            if ($url === '' && $vid === '') {
                continue;
            }
            if ($restrictOwner && $ownerId > 0 && (int)($r['owner_id'] ?? 0) > 0 && (int)$r['owner_id'] !== $ownerId) {
                continue;
            }
            if (!isset($byAccount[$aid])) {
                $byAccount[$aid] = [];
            }
            $byAccount[$aid][] = $r;
        }
        if (!$byAccount) {
            throw new \InvalidArgumentException('所选素材均无有效发布账号或平台视频链接，请先完成发布');
        }

        $created = [];
        $enqueued = 0;
        $taskName = self::buildTaskName();
        foreach ($byAccount as $accountId => $mats) {
            $acc = DB::selectOne('SELECT id, username, nickname, owner_id FROM accounts WHERE id=?', [$accountId]);
            if (!$acc) {
                continue;
            }
            $accOwner = (int)($acc['owner_id'] ?? 0);
            $preferOwner = $ownerId;
            // 超管代创建：总任务归属对齐发布账号 owner，便于运营管理在「任务汇总」中看到
            if (DataScope::isSuper() && !DataScope::isActing() && $accOwner > 0) {
                $preferOwner = $accOwner;
            }
            $slotOwner = AccountWorkBatchService::resolveWorkOwnerId($accountId, $preferOwner);
            $matIds = array_map(static fn ($m) => (int)$m['id'], $mats);

            DB::query(
                "INSERT INTO pull_comment_tasks
                 (owner_id, account_id, name, status, browser_backend, material_ids_json, video_count)
                 VALUES (?,?,?,'queued',?,?,?)",
                [
                    $slotOwner,
                    $accountId,
                    mb_substr($taskName, 0, 255),
                    $backend,
                    json_encode($matIds, JSON_UNESCAPED_UNICODE),
                    count($matIds),
                ]
            );
            $taskId = (int)DB::getPdo()->lastInsertId();

            $videos = [];
            foreach ($mats as $m) {
                $videos[] = [
                    'materialId' => (int)$m['id'],
                    'materialName' => (string)($m['name'] ?? ''),
                    'videoId' => (string)($m['platform_video_id'] ?? ''),
                    'videoUrl' => (string)($m['platform_video_url'] ?? ''),
                ];
            }

            $out = AccountWorkBatchService::enqueueItem(
                $accountId,
                'pull_comments',
                [
                    'source' => 'pull_comment_wizard',
                    'browserBackend' => $backend,
                    'accountId' => $accountId,
                    'accountUsername' => (string)($acc['username'] ?? ''),
                    'pullTaskId' => $taskId,
                    'videos' => $videos,
                ],
                $taskId,
                $slotOwner
            );
            if (!empty($out['item_id'])) {
                $enqueued++;
            }
            $created[] = self::getTask($taskId);
        }

        if (!$created) {
            throw new \InvalidArgumentException('未能创建任何拉取视频数据分任务');
        }

        return [
            'tasks' => $created,
            'created' => count($created),
            'enqueued' => $enqueued,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function updateTask(int $id, array $data): ?array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM pull_comment_tasks WHERE id=?', [$id]);
        if (!$row) {
            return null;
        }
        if (!in_array((string)($row['status'] ?? ''), ['pending', 'queued', 'failed'], true)) {
            throw new \InvalidArgumentException('仅待执行 / 失败的任务可编辑');
        }
        $name = trim((string)($data['name'] ?? $row['name'] ?? ''));
        $backend = (($data['browserBackend'] ?? $data['browser_backend'] ?? $row['browser_backend'] ?? 'bit') === 'tkswarm')
            ? 'tkswarm' : 'bit';
        $materialIds = $data['materialIds'] ?? $data['material_ids'] ?? null;
        if (is_array($materialIds)) {
            self::replaceTaskMaterials($row, $materialIds, $name, $backend);
            return self::getTask($id);
        }
        DB::query(
            'UPDATE pull_comment_tasks SET name=?, browser_backend=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [mb_substr($name !== '' ? $name : (string)$row['name'], 0, 255), $backend, $id]
        );
        return self::getTask($id);
    }

    /** 登录用户名-vdata-时间戳 */
    public static function buildTaskName(): string
    {
        $user = DataScope::loginUser();
        $username = trim((string)($user['username'] ?? ''));
        $username = preg_replace('/\s+/u', '', $username) ?? '';
        if ($username === '') {
            $username = 'user' . max(0, (int)($user['id'] ?? 0));
        }
        return mb_substr($username . '-vdata-' . date('YmdHis'), 0, 255);
    }

    /**
     * 待执行任务更换视频：只保留本账号的素材，取消旧分项后重新挂入总任务。
     * @param array<string,mixed> $row
     * @param list<mixed> $materialIds
     */
    private static function replaceTaskMaterials(array $row, array $materialIds, string $name, string $backend): void
    {
        $taskId = (int)$row['id'];
        $accountId = (int)$row['account_id'];
        $ids = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        if (!$ids) {
            throw new \InvalidArgumentException('请至少选择一个视频');
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $mats = DB::select(
            "SELECT id, name, platform_video_id, platform_video_url, publish_account_id
             FROM materials
             WHERE id IN ({$ph}) AND publish_account_id=?",
            [...$ids, $accountId]
        ) ?: [];
        $videos = [];
        foreach ($mats as $m) {
            $url = trim((string)($m['platform_video_url'] ?? ''));
            $vid = trim((string)($m['platform_video_id'] ?? ''));
            if ($url === '' && $vid === '') {
                continue;
            }
            $videos[] = [
                'materialId' => (int)$m['id'],
                'materialName' => (string)($m['name'] ?? ''),
                'videoId' => $vid,
                'videoUrl' => $url,
            ];
        }
        if (!$videos) {
            throw new \InvalidArgumentException('所选视频不属于该分任务账号，或没有平台链接');
        }
        $matIds = array_map(static fn ($v) => (int)$v['materialId'], $videos);
        $taskName = trim($name) !== '' ? trim($name) : (string)$row['name'];
        $acc = DB::selectOne('SELECT owner_id, username FROM accounts WHERE id=?', [$accountId]);
        $accOwner = (int)($acc['owner_id'] ?? 0);
        $preferOwner = (int)($row['owner_id'] ?? 0);
        if (DataScope::isSuper() && !DataScope::isActing() && $accOwner > 0) {
            $preferOwner = $accOwner;
        }
        $slotOwner = AccountWorkBatchService::resolveWorkOwnerId($accountId, $preferOwner);
        DB::query(
            "UPDATE pull_comment_tasks
             SET owner_id=?, name=?, browser_backend=?, material_ids_json=?, video_count=?, status='queued',
                 error_message='', done_count=0, finished_at=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$slotOwner, mb_substr($taskName, 0, 255), $backend, json_encode($matIds), count($matIds), $taskId]
        );
        AccountWorkBatchService::cancelItemsForTask('pull_comments', $taskId);
        AccountWorkBatchService::enqueueItem(
            $accountId,
            'pull_comments',
            [
                'source' => 'pull_comment_wizard',
                'browserBackend' => $backend,
                'accountId' => $accountId,
                'accountUsername' => (string)($acc['username'] ?? ''),
                'pullTaskId' => $taskId,
                'videos' => $videos,
            ],
            $taskId,
            $slotOwner
        );
    }

    public static function cancelTask(int $id): ?array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM pull_comment_tasks WHERE id=?', [$id]);
        if (!$row) {
            return null;
        }
        DB::query(
            "UPDATE pull_comment_tasks SET status='cancelled', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$id]
        );
        AccountWorkBatchService::cancelItemsForTask('pull_comments', $id);
        return self::getTask($id);
    }

    public static function deleteTask(int $id): bool
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT id FROM pull_comment_tasks WHERE id=?', [$id]);
        if (!$row) {
            return false;
        }
        AccountWorkBatchService::cancelItemsForTask('pull_comments', $id);
        DB::query('DELETE FROM pull_comment_tasks WHERE id=?', [$id]);
        return true;
    }

    public static function markRunning(int $taskId): void
    {
        self::ensureSchema();
        DB::query(
            "UPDATE pull_comment_tasks
             SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status IN ('pending','queued','failed')",
            [$taskId]
        );
    }

    /**
     * Node 回写单个视频抓取结果。
     * @param array<string,mixed> $payload
     * @return array{materialId:int,comments:int}
     */
    public static function ingestVideoResult(int $taskId, array $payload): array
    {
        self::ensureSchema();
        $task = DB::selectOne('SELECT * FROM pull_comment_tasks WHERE id=?', [$taskId]);
        if (!$task) {
            throw new \InvalidArgumentException('拉取视频数据任务不存在');
        }
        $materialId = (int)($payload['materialId'] ?? $payload['material_id'] ?? 0);
        if ($materialId < 1) {
            throw new \InvalidArgumentException('缺少 materialId');
        }
        $mat = DB::selectOne('SELECT id, owner_id, publish_account_id FROM materials WHERE id=?', [$materialId]);
        if (!$mat) {
            throw new \InvalidArgumentException('素材不存在');
        }

        $views = max(0, (int)($payload['views'] ?? $payload['viewCount'] ?? $payload['view_count'] ?? 0));
        $likes = max(0, (int)($payload['likes'] ?? $payload['likeCount'] ?? $payload['like_count'] ?? 0));
        $publishedAt = self::normalizeDateTime((string)($payload['publishedAt'] ?? $payload['published_at'] ?? $payload['createTime'] ?? ''));
        $comments = $payload['comments'] ?? [];
        if (!is_array($comments)) {
            $comments = [];
        }

        // 先写互动数据，避免评论入库失败时播放量/点赞丢失
        $commentTotal = count(array_filter($comments, static fn ($c) => is_array($c) && trim((string)($c['username'] ?? '')) !== ''));
        $sets = [
            'view_count=?',
            'like_count=?',
            'comment_count=?',
            'last_comment_pulled_at=CURRENT_TIMESTAMP',
            'updated_at=CURRENT_TIMESTAMP',
        ];
        $params = [$views, $likes, $commentTotal];
        if ($publishedAt !== null) {
            $sets[] = 'platform_published_at=?';
            $params[] = $publishedAt;
        }
        $params[] = $materialId;
        DB::query('UPDATE materials SET ' . implode(', ', $sets) . ' WHERE id=?', $params);

        $saved = self::upsertComments(
            $materialId,
            (int)($mat['publish_account_id'] ?? $task['account_id'] ?? 0),
            (int)($mat['owner_id'] ?? $task['owner_id'] ?? 0),
            $comments
        );

        if ($saved !== $commentTotal) {
            DB::query(
                'UPDATE materials SET comment_count=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$saved, $materialId]
            );
        }

        DB::query(
            'UPDATE pull_comment_tasks
             SET done_count=done_count+1,
                 comment_count=comment_count+?,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=?',
            [$saved, $taskId]
        );

        return ['materialId' => $materialId, 'comments' => $saved, 'views' => $views, 'likes' => $likes];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function finishTask(int $taskId, bool $success, array $payload = []): ?array
    {
        self::ensureSchema();
        $status = $success ? 'completed' : 'failed';
        $err = mb_substr(trim((string)($payload['error'] ?? $payload['errorMessage'] ?? '')), 0, 512);
        DB::query(
            "UPDATE pull_comment_tasks
             SET status=?, error_message=?, finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$status, $err, $taskId]
        );
        return self::getTask($taskId);
    }

    /**
     * @return array{material:array<string,mixed>|null,comments:list<array<string,mixed>>,stats:array<string,mixed>}
     */
    public static function materialDetail(int $materialId): array
    {
        self::ensureSchema();
        $row = DB::selectOne(
            "SELECT m.*, u.username AS owner_name, a.username AS publish_account_name, a.nickname AS publish_account_nickname
             FROM materials m
             LEFT JOIN admin_users u ON u.id=m.owner_id
             LEFT JOIN accounts a ON a.id=m.publish_account_id
             WHERE m.id=?",
            [$materialId]
        );
        if (!$row) {
            return ['material' => null, 'comments' => [], 'stats' => []];
        }
        $comments = DB::select(
            "SELECT * FROM material_comments WHERE material_id=? ORDER BY parent_id ASC, id ASC",
            [$materialId]
        ) ?: [];
        $tree = self::buildCommentTree($comments);
        self::markCreatorComments($tree, (string)($row['publish_account_name'] ?? ''));
        return [
            'material' => self::formatMaterial($row),
            'comments' => $tree,
            'stats' => [
                'views' => (int)($row['view_count'] ?? 0),
                'likes' => (int)($row['like_count'] ?? 0),
                'comments' => (int)($row['comment_count'] ?? count($comments)),
                'publishedAt' => (string)($row['platform_published_at'] ?? ''),
                'lastPulledAt' => (string)($row['last_comment_pulled_at'] ?? ''),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $comments
     */
    private static function upsertComments(int $materialId, int $accountId, int $ownerId, array $comments): int
    {
        $saved = 0;
        $now = date('Y-m-d H:i:s');
        $idByKey = [];

        // 先一级评论，再回复
        usort($comments, static function ($a, $b) {
            $ap = (string)(($a['parentUsername'] ?? $a['parent_username'] ?? '') ?: '');
            $bp = (string)(($b['parentUsername'] ?? $b['parent_username'] ?? '') ?: '');
            if ($ap === '' && $bp !== '') {
                return -1;
            }
            if ($ap !== '' && $bp === '') {
                return 1;
            }
            return 0;
        });

        foreach ($comments as $c) {
            if (!is_array($c)) {
                continue;
            }
            $username = trim((string)($c['username'] ?? ''));
            $text = trim((string)($c['comment_text'] ?? $c['commentText'] ?? $c['text'] ?? ''));
            if ($username === '' || $text === '') {
                continue;
            }
            $nickname = trim((string)($c['nickname'] ?? $username));
            $profileUrl = trim((string)($c['profile_url'] ?? $c['profileUrl'] ?? ''));
            if ($profileUrl === '') {
                $profileUrl = 'https://www.tiktok.com/@' . ltrim($username, '@');
            }
            $avatarUrl = trim((string)($c['avatar_url'] ?? $c['avatarUrl'] ?? ''));
            $uuid = trim((string)($c['uuid'] ?? ''));
            if ($uuid === '') {
                $uuid = 'u_' . md5(strtolower($username));
            }
            $platformCid = trim((string)($c['platform_comment_id'] ?? $c['commentId'] ?? $c['id'] ?? ''));
            $digg = max(0, (int)($c['digg_count'] ?? $c['diggCount'] ?? $c['likes'] ?? 0));
            $commentTime = trim((string)($c['comment_time'] ?? $c['commentTime'] ?? $c['time'] ?? ''));
            $commentedAt = self::normalizeDateTime($commentTime);
            $parentUsername = trim((string)($c['parentUsername'] ?? $c['parent_username'] ?? ''));
            $parentText = trim((string)($c['parentText'] ?? $c['parent_text'] ?? ''));
            $parentId = 0;
            if ($parentUsername !== '') {
                $pkey = strtolower($parentUsername) . '|' . mb_substr($parentText, 0, 60);
                if (isset($idByKey[$pkey])) {
                    $parentId = $idByKey[$pkey];
                } else {
                    $prow = DB::selectOne(
                        "SELECT id FROM material_comments
                         WHERE material_id=? AND LOWER(username)=LOWER(?) AND comment_text LIKE ?
                         ORDER BY id DESC LIMIT 1",
                        [$materialId, $parentUsername, mb_substr($parentText, 0, 40) . '%']
                    );
                    $parentId = (int)($prow['id'] ?? 0);
                }
            }

            $existing = DB::selectOne(
                "SELECT id FROM material_comments
                 WHERE material_id=? AND LOWER(username)=LOWER(?) AND comment_text=?
                 LIMIT 1",
                [$materialId, $username, mb_substr($text, 0, 2000)]
            );
            if ($existing) {
                $cid = (int)$existing['id'];
                DB::query(
                    "UPDATE material_comments
                     SET parent_id=?, nickname=?, uuid=?, profile_url=?, avatar_url=?,
                         digg_count=?, comment_time=?, commented_at=COALESCE(?, commented_at),
                         pulled_at=?, updated_at=CURRENT_TIMESTAMP
                     WHERE id=?",
                    [
                        $parentId,
                        mb_substr($nickname, 0, 255),
                        mb_substr($uuid, 0, 64),
                        mb_substr($profileUrl, 0, 500),
                        mb_substr($avatarUrl, 0, 500),
                        $digg,
                        mb_substr($commentTime, 0, 64),
                        $commentedAt,
                        $now,
                        $cid,
                    ]
                );
            } else {
                DB::query(
                    "INSERT INTO material_comments
                     (material_id, account_id, owner_id, parent_id, platform_comment_id,
                      username, nickname, uuid, profile_url, avatar_url, comment_text,
                      digg_count, comment_time, commented_at, pulled_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [
                        $materialId,
                        $accountId,
                        $ownerId,
                        $parentId,
                        mb_substr($platformCid, 0, 128),
                        mb_substr($username, 0, 128),
                        mb_substr($nickname, 0, 255),
                        mb_substr($uuid, 0, 64),
                        mb_substr($profileUrl, 0, 500),
                        mb_substr($avatarUrl, 0, 500),
                        mb_substr($text, 0, 2000),
                        $digg,
                        mb_substr($commentTime, 0, 64),
                        $commentedAt,
                        $now,
                    ]
                );
                $cid = (int)DB::getPdo()->lastInsertId();
            }
            $idByKey[strtolower($username) . '|' . mb_substr($text, 0, 60)] = $cid;
            $saved++;
        }

        // 刷新一级评论的 reply_count（MySQL 不允许 UPDATE 子查询直接引用同表，需派生表）
        DB::query(
            'UPDATE material_comments c
             LEFT JOIN (
               SELECT parent_id, COUNT(*) AS reply_cnt
               FROM material_comments
               WHERE material_id=? AND parent_id>0
               GROUP BY parent_id
             ) rc ON rc.parent_id=c.id
             SET c.reply_count=COALESCE(rc.reply_cnt, 0)
             WHERE c.material_id=? AND c.parent_id=0',
            [$materialId, $materialId]
        );

        return $saved;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function buildCommentTree(array $rows): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $item = self::formatComment($r);
            $item['replies'] = [];
            $byId[(int)$item['id']] = $item;
        }
        $roots = [];
        foreach ($byId as $id => &$item) {
            $pid = (int)($item['parentId'] ?? 0);
            if ($pid > 0 && isset($byId[$pid])) {
                $item['replyToUsername'] = (string)($byId[$pid]['username'] ?? '');
                $item['replyToNickname'] = (string)($byId[$pid]['nickname'] ?? '');
                $byId[$pid]['replies'][] = &$item;
            } else {
                $roots[] = &$item;
            }
        }
        unset($item);
        return $roots;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     */
    private static function markCreatorComments(array &$nodes, string $publishAccount): void
    {
        $pub = strtolower(ltrim(trim($publishAccount), '@'));
        if ($pub === '') {
            return;
        }
        foreach ($nodes as &$node) {
            $uname = strtolower(ltrim((string)($node['username'] ?? ''), '@'));
            if ($uname !== '' && $uname === $pub) {
                $node['isCreator'] = true;
            }
            if (!empty($node['replies']) && is_array($node['replies'])) {
                self::markCreatorComments($node['replies'], $publishAccount);
            }
        }
        unset($node);
    }

    /** @param array<string,mixed> $row */
    private static function formatTask(array $row): array
    {
        $matIds = json_decode((string)($row['material_ids_json'] ?? '[]'), true);
        if (!is_array($matIds)) {
            $matIds = [];
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'accountId' => (int)($row['account_id'] ?? 0),
            'accountUsername' => (string)($row['account_username'] ?? ''),
            'accountNickname' => (string)($row['account_nickname'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'status' => (string)($row['status'] ?? 'pending'),
            'browserBackend' => (string)($row['browser_backend'] ?? 'bit'),
            'materialIds' => array_values(array_map('intval', $matIds)),
            'videoCount' => (int)($row['video_count'] ?? 0),
            'doneCount' => (int)($row['done_count'] ?? 0),
            'commentCount' => (int)($row['comment_count'] ?? 0),
            'errorMessage' => (string)($row['error_message'] ?? ''),
            'startedAt' => (string)($row['started_at'] ?? ''),
            'finishedAt' => (string)($row['finished_at'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function formatComment(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'materialId' => (int)($row['material_id'] ?? 0),
            'parentId' => (int)($row['parent_id'] ?? 0),
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
            'uuid' => (string)($row['uuid'] ?? ''),
            'profileUrl' => (string)($row['profile_url'] ?? ''),
            'avatarUrl' => (string)($row['avatar_url'] ?? ''),
            'commentText' => (string)($row['comment_text'] ?? ''),
            'diggCount' => (int)($row['digg_count'] ?? 0),
            'replyCount' => (int)($row['reply_count'] ?? 0),
            'commentTime' => (string)($row['comment_time'] ?? ''),
            'commentedAt' => (string)($row['commented_at'] ?? ''),
            'pulledAt' => (string)($row['pulled_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function formatMaterial(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'fileName' => (string)($row['file_name'] ?? ''),
            'fileExt' => (string)($row['file_ext'] ?? ''),
            'mimeType' => (string)($row['mime_type'] ?? ''),
            'sizeBytes' => (int)($row['size_bytes'] ?? 0),
            'description' => (string)($row['description'] ?? ''),
            'tags' => (string)($row['tags'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'publishStatus' => (string)($row['publish_status'] ?? ''),
            'publishAccountId' => (int)($row['publish_account_id'] ?? 0),
            'publishAccount' => (string)($row['publish_account'] ?? $row['publish_account_name'] ?? ''),
            'publishAccountNickname' => (string)($row['publish_account_nickname'] ?? ''),
            'platformVideoId' => (string)($row['platform_video_id'] ?? ''),
            'platformVideoUrl' => (string)($row['platform_video_url'] ?? ''),
            'shareTitle' => (string)($row['share_title'] ?? ''),
            'shareCaption' => (string)($row['share_caption'] ?? ''),
            'coverBase64' => (string)($row['cover_base64'] ?? ''),
            'viewCount' => (int)($row['view_count'] ?? 0),
            'likeCount' => (int)($row['like_count'] ?? 0),
            'commentCount' => (int)($row['comment_count'] ?? 0),
            'platformPublishedAt' => (string)($row['platform_published_at'] ?? ''),
            'lastCommentPulledAt' => (string)($row['last_comment_pulled_at'] ?? ''),
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'ownerName' => (string)($row['owner_name'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'streamUrl' => '/api/materials/' . (int)($row['id'] ?? 0) . '/stream',
        ];
    }

    private static function normalizeDateTime(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $raw)) {
            return str_replace('T', ' ', strlen($raw) === 10 ? $raw . ' 00:00:00' : $raw);
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
