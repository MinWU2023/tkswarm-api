<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 视频增值（视频增量）：
 *  - 选一条已发布视频 + 一批账号（随机 / 自定义）+「视频增量」评论模板 + 最小/最大间隔
 *  - 在最小~最大间隔内随机，链式生成每个账号的具体执行时间（明细）
 *  - 计划任务 video-boost:dispatch 到点把明细推给「创建该任务的管理用户」的账号会话总任务
 *  - Node 执行：搜索发布者昵称 → 主页 → 下滑找到视频 → 看完暂停 → 点赞 → 评论 → 转发
 */
class VideoBoostService
{
    public const TASK_TYPE = 'video_boost';
    public const TEMPLATE_TYPE = 'video_boost';

    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        DB::query(
            "CREATE TABLE IF NOT EXISTS video_boost_tasks (
              id INT AUTO_INCREMENT PRIMARY KEY,
              owner_id INT NOT NULL DEFAULT 0,
              name VARCHAR(191) NOT NULL DEFAULT '',
              material_id INT NOT NULL DEFAULT 0,
              video_title VARCHAR(500) NOT NULL DEFAULT '',
              video_desc TEXT NULL,
              video_url VARCHAR(500) NOT NULL DEFAULT '',
              video_id VARCHAR(64) NOT NULL DEFAULT '',
              publisher_account_id INT NOT NULL DEFAULT 0,
              publisher_username VARCHAR(128) NOT NULL DEFAULT '',
              publisher_nickname VARCHAR(255) NOT NULL DEFAULT '',
              account_mode VARCHAR(16) NOT NULL DEFAULT 'random',
              account_ids_json MEDIUMTEXT NULL,
              account_count INT NOT NULL DEFAULT 0,
              template_id INT NOT NULL DEFAULT 0,
              comment_mode VARCHAR(16) NOT NULL DEFAULT 'ai',
              comment_ai_prompt MEDIUMTEXT NULL,
              comment_text TEXT NULL,
              min_interval_minutes DECIMAL(10,2) NOT NULL DEFAULT 1,
              max_interval_minutes DECIMAL(10,2) NOT NULL DEFAULT 5,
              do_like TINYINT(1) NOT NULL DEFAULT 1,
              do_comment TINYINT(1) NOT NULL DEFAULT 1,
              do_share TINYINT(1) NOT NULL DEFAULT 1,
              save_traffic TINYINT(1) NOT NULL DEFAULT 1,
              status VARCHAR(16) NOT NULL DEFAULT 'pending',
              dispatch_enabled TINYINT(1) NOT NULL DEFAULT 1,
              scheduled_count INT NOT NULL DEFAULT 0,
              enqueued_count INT NOT NULL DEFAULT 0,
              success_count INT NOT NULL DEFAULT 0,
              failed_count INT NOT NULL DEFAULT 0,
              error_message VARCHAR(500) NOT NULL DEFAULT '',
              deleted_at DATETIME NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_vbt_owner (owner_id),
              KEY idx_vbt_status (status),
              KEY idx_vbt_material (material_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        DB::query(
            "CREATE TABLE IF NOT EXISTS video_boost_task_items (
              id INT AUTO_INCREMENT PRIMARY KEY,
              task_id INT NOT NULL,
              account_id INT NOT NULL,
              owner_id INT NOT NULL DEFAULT 0,
              scheduled_at DATETIME NOT NULL,
              action_plan_json MEDIUMTEXT NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'pending',
              work_item_id INT NULL,
              error_message VARCHAR(500) NOT NULL DEFAULT '',
              result_json MEDIUMTEXT NULL,
              executed_at DATETIME NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uk_vbti_task_account (task_id, account_id),
              KEY idx_vbti_due (status, scheduled_at),
              KEY idx_vbti_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$schemaReady = true;
    }

    // ------------------------------------------------------------------
    // 消息模板分类「视频增量」默认模板
    // ------------------------------------------------------------------

    public static function defaultAiPrompt(): string
    {
        return "You are a real TikTok viewer who just finished watching a video. "
            . "Write ONE short, natural English comment based on the video title and description.\n"
            . "Rules:\n"
            . "1. English only, no quotation marks, no hashtags spam\n"
            . "2. 8–30 words, casual and genuine, optional 1 emoji\n"
            . "3. React to something specific from the title/description\n"
            . "4. No ads, links, or politics\n"
            . "Video title: {{video_title}}\n"
            . "Video description: {{video_desc}}\n"
            . "Author: {{author}}";
    }

    /** 幂等：保证「视频增量」分类下至少有一条内置模板 */
    public static function ensureDefaultTemplate(): void
    {
        try {
            $c = (int)(DB::selectOne(
                "SELECT COUNT(*) AS c FROM interaction_templates WHERE type=?",
                [self::TEMPLATE_TYPE]
            )['c'] ?? 0);
            if ($c > 0) {
                return;
            }
            $seeds = [
                ['视频增量 · AI 评论', 'ai', self::defaultAiPrompt(), 0],
                ['视频增量 · 固定评论', 'fixed', 'Love this one, great content!', 1],
            ];
            foreach ($seeds as [$name, $mode, $content, $sort]) {
                DB::query(
                    'INSERT INTO interaction_templates
                     (type, name, content_mode, content, fixed_text, ai_prompt, enabled, sort_order, is_builtin, owner_id)
                     VALUES (?,?,?,?,?,?,1,?,1,NULL)',
                    [
                        self::TEMPLATE_TYPE,
                        $name,
                        $mode,
                        $content,
                        $mode === 'fixed' ? $content : '',
                        $mode === 'ai' ? $content : '',
                        $sort,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // interaction_templates 尚未建表时忽略，模板控制器 ensureSchema 会再补
        }
    }

    /**
     * 可选「视频增量」模板。
     * @return list<array<string,mixed>>
     */
    public static function listTemplates(): array
    {
        self::ensureDefaultTemplate();
        $filters = ['type=?', 'enabled=1'];
        $params = [self::TEMPLATE_TYPE];
        $oid = DataScope::filterOwnerId();
        if ($oid !== null) {
            $filters[] = '(owner_id=? OR owner_id IS NULL OR is_builtin=1)';
            $params[] = $oid;
        }
        $rows = DB::select(
            'SELECT id, name, content_mode, content, fixed_text, ai_prompt, is_builtin
             FROM interaction_templates WHERE ' . implode(' AND ', $filters) . '
             ORDER BY sort_order ASC, id ASC',
            $params
        ) ?: [];
        return array_map(static function (array $r): array {
            $mode = strtolower(trim((string)($r['content_mode'] ?? 'fixed')));
            $mode = $mode === 'ai' ? 'ai' : 'fixed';
            $content = trim((string)($r['content'] ?? ''));
            if ($content === '') {
                $content = $mode === 'ai'
                    ? trim((string)($r['ai_prompt'] ?? ''))
                    : trim((string)($r['fixed_text'] ?? ''));
            }
            return [
                'id' => (int)$r['id'],
                'name' => (string)($r['name'] ?? ''),
                'contentMode' => $mode,
                'content' => $content,
                'isBuiltin' => !empty($r['is_builtin']),
            ];
        }, $rows);
    }

    // ------------------------------------------------------------------
    // 视频选择
    // ------------------------------------------------------------------

    /**
     * 可选视频：已发布且有发布账号 + 视频链接/ID 的素材。
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,pageSize:int}
     */
    public static function listVideos(string $keyword = '', int $page = 1, int $pageSize = 12): array
    {
        $filters = [
            "m.publish_status='published'",
            'm.publish_account_id>0',
            "(m.platform_video_url<>'' OR m.platform_video_id<>'')",
        ];
        $params = [];
        [$matSql, $matParams] = DataScope::materialListSql('m');
        $params = array_merge($params, $matParams);
        if ($keyword !== '') {
            $filters[] = '(m.name LIKE ? OR m.share_title LIKE ? OR m.platform_video_id LIKE ? OR a.username LIKE ? OR a.nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $where = 'WHERE ' . implode(' AND ', $filters) . $matSql;
        $pageSize = max(1, min(50, $pageSize));
        $total = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM materials m LEFT JOIN accounts a ON a.id=m.publish_account_id {$where}",
            $params
        )['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT m.id, m.name, m.share_title, m.share_caption, m.description,
                    m.platform_video_id, m.platform_video_url, m.publish_account_id,
                    CASE WHEN m.cover_base64 IS NOT NULL AND m.cover_base64 <> '' THEN 1 ELSE 0 END AS has_cover,
                    a.username AS publisher_username, a.nickname AS publisher_nickname
             FROM materials m
             LEFT JOIN accounts a ON a.id=m.publish_account_id
             {$where}
             ORDER BY m.id DESC
             LIMIT {$offset}, {$pageSize}",
            $params
        ) ?: [];
        $items = array_map(static function (array $r): array {
            $id = (int)$r['id'];
            $hasCover = (int)($r['has_cover'] ?? 0) === 1;
            return [
                'id' => $id,
                'title' => self::pickTitle($r),
                'description' => self::pickDesc($r),
                'videoId' => (string)($r['platform_video_id'] ?? ''),
                'videoUrl' => self::buildVideoUrl($r),
                'publishAccountId' => (int)($r['publish_account_id'] ?? 0),
                'publisherUsername' => ltrim((string)($r['publisher_username'] ?? ''), '@'),
                'publisherNickname' => (string)($r['publisher_nickname'] ?? ''),
                'coverUrl' => $hasCover ? ('/api/materials/' . $id . '/cover') : '',
            ];
        }, $rows);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'pageSize' => $pageSize];
    }

    /** @param array<string,mixed> $r */
    private static function pickTitle(array $r): string
    {
        $t = trim((string)($r['share_title'] ?? ''));
        if ($t === '') {
            $t = trim((string)($r['name'] ?? ''));
        }
        return $t !== '' ? $t : ('素材 #' . (int)($r['id'] ?? 0));
    }

    /** @param array<string,mixed> $r */
    private static function pickDesc(array $r): string
    {
        $d = trim((string)($r['share_caption'] ?? ''));
        if ($d === '') {
            $d = trim((string)($r['description'] ?? ''));
        }
        return $d;
    }

    /** @param array<string,mixed> $r */
    private static function buildVideoUrl(array $r): string
    {
        $url = trim((string)($r['platform_video_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }
        $vid = trim((string)($r['platform_video_id'] ?? ''));
        $uname = ltrim(trim((string)($r['publisher_username'] ?? '')), '@');
        if ($vid !== '' && $uname !== '') {
            return "https://www.tiktok.com/@{$uname}/video/{$vid}";
        }
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private static function resolveVideo(int $materialId): array
    {
        if ($materialId < 1) {
            throw new \InvalidArgumentException('请选择视频');
        }
        [$matSql, $matParams] = DataScope::materialListSql('m');
        $row = DB::selectOne(
            "SELECT m.id, m.name, m.share_title, m.share_caption, m.description,
                    m.platform_video_id, m.platform_video_url, m.publish_account_id,
                    a.username AS publisher_username, a.nickname AS publisher_nickname
             FROM materials m
             LEFT JOIN accounts a ON a.id=m.publish_account_id
             WHERE m.id=? {$matSql}",
            array_merge([$materialId], $matParams)
        );
        if (!$row) {
            throw new \InvalidArgumentException('视频不存在或无权使用');
        }
        $pubId = (int)($row['publish_account_id'] ?? 0);
        $url = self::buildVideoUrl($row);
        $vid = trim((string)($row['platform_video_id'] ?? ''));
        if ($pubId < 1 || ($url === '' && $vid === '')) {
            throw new \InvalidArgumentException('该素材尚未发布成功，没有发布账号或视频链接，无法做视频增值');
        }
        $username = ltrim(trim((string)($row['publisher_username'] ?? '')), '@');
        $nickname = trim((string)($row['publisher_nickname'] ?? ''));
        if ($username === '' && $nickname === '') {
            throw new \InvalidArgumentException('发布账号缺少用户名/昵称，无法搜索');
        }
        return [
            'materialId' => (int)$row['id'],
            'title' => mb_substr(self::pickTitle($row), 0, 500),
            'description' => self::pickDesc($row),
            'videoUrl' => mb_substr($url, 0, 500),
            'videoId' => mb_substr($vid, 0, 64),
            'publisherAccountId' => $pubId,
            'publisherUsername' => mb_substr($username, 0, 128),
            'publisherNickname' => mb_substr($nickname, 0, 255),
        ];
    }

    // ------------------------------------------------------------------
    // 模板快照
    // ------------------------------------------------------------------

    /**
     * @return array{templateId:int,commentMode:string,commentAiPrompt:string,commentText:string}
     */
    private static function resolveTemplate(int $templateId, bool $required): array
    {
        if ($templateId < 1) {
            if ($required) {
                throw new \InvalidArgumentException('请选择「视频增量」消息模板');
            }
            return [
                'templateId' => 0,
                'commentMode' => 'ai',
                'commentAiPrompt' => self::defaultAiPrompt(),
                'commentText' => '',
            ];
        }
        self::ensureDefaultTemplate();
        $row = DB::selectOne(
            "SELECT id, content_mode, content, fixed_text, ai_prompt FROM interaction_templates
             WHERE id=? AND type=? AND enabled=1",
            [$templateId, self::TEMPLATE_TYPE]
        );
        if (!$row) {
            throw new \InvalidArgumentException('所选消息模板不存在、已停用或不属于「视频增量」分类');
        }
        $mode = strtolower(trim((string)($row['content_mode'] ?? 'fixed'))) === 'ai' ? 'ai' : 'fixed';
        $content = trim((string)($row['content'] ?? ''));
        if ($content === '') {
            $content = $mode === 'ai'
                ? trim((string)($row['ai_prompt'] ?? ''))
                : trim((string)($row['fixed_text'] ?? ''));
        }
        if ($content === '') {
            throw new \InvalidArgumentException('所选消息模板内容为空');
        }
        return [
            'templateId' => (int)$row['id'],
            'commentMode' => $mode,
            'commentAiPrompt' => $mode === 'ai' ? $content : '',
            'commentText' => $mode === 'fixed' ? $content : '',
        ];
    }

    // ------------------------------------------------------------------
    // 账号
    // ------------------------------------------------------------------

    /**
     * 当前用户可用账号池（启用、数据范围内、排除发布者本人）。
     * @return list<array{id:int,owner_id:int,username:string}>
     */
    private static function eligibleAccounts(int $publisherAccountId): array
    {
        $filters = ['a.enabled=1'];
        $params = [];
        if ($publisherAccountId > 0) {
            $filters[] = 'a.id<>?';
            $params[] = $publisherAccountId;
        }
        DataScope::applyAccountListScope('a.owner_id', $filters, $params);
        $rows = DB::select(
            'SELECT a.id, a.owner_id, a.username FROM accounts a WHERE ' . implode(' AND ', $filters) . ' ORDER BY a.id ASC',
            $params
        ) ?: [];
        return array_map(static fn(array $r) => [
            'id' => (int)$r['id'],
            'owner_id' => (int)($r['owner_id'] ?? 0),
            'username' => (string)($r['username'] ?? ''),
        ], $rows);
    }

    public static function eligibleCount(int $materialId): int
    {
        $pub = 0;
        if ($materialId > 0) {
            $pub = (int)(DB::selectOne('SELECT publish_account_id FROM materials WHERE id=?', [$materialId])['publish_account_id'] ?? 0);
        }
        return count(self::eligibleAccounts($pub));
    }

    /**
     * @param array<string,mixed> $input
     * @return array{mode:string,ids:list<int>,count:int,picked:list<int>}
     */
    private static function resolveAccounts(array $input, int $publisherAccountId): array
    {
        $pool = self::eligibleAccounts($publisherAccountId);
        if (!$pool) {
            throw new \InvalidArgumentException('没有可用的执行账号（已排除视频发布账号本身）');
        }
        $mode = strtolower(trim((string)($input['accountMode'] ?? $input['account_mode'] ?? 'random')));
        if ($mode === 'custom') {
            $raw = $input['accountIds'] ?? $input['account_ids'] ?? [];
            if (!is_array($raw)) {
                $raw = preg_split('/[,\s]+/', (string)$raw) ?: [];
            }
            $want = array_values(array_unique(array_filter(array_map('intval', $raw))));
            if (!$want) {
                throw new \InvalidArgumentException('自定义账号请至少选择 1 个账号');
            }
            $set = array_fill_keys($want, true);
            $picked = [];
            foreach ($pool as $a) {
                if (isset($set[$a['id']])) {
                    $picked[] = $a['id'];
                }
            }
            if (!$picked) {
                throw new \InvalidArgumentException('所选账号均不可用（已禁用、无权限或为视频发布账号）');
            }
            shuffle($picked);
            return ['mode' => 'custom', 'ids' => $want, 'count' => count($picked), 'picked' => $picked];
        }

        $count = (int)($input['accountCount'] ?? $input['account_count'] ?? 0);
        $max = count($pool);
        if ($count < 1 || $count > $max) {
            throw new \InvalidArgumentException("随机账号数量须在 1 ~ {$max} 之间");
        }
        $ids = array_map(static fn(array $a) => $a['id'], $pool);
        shuffle($ids);
        return ['mode' => 'random', 'ids' => [], 'count' => $count, 'picked' => array_slice($ids, 0, $count)];
    }

    // ------------------------------------------------------------------
    // 排程：首个账号立即，其后每个 = 上一个 + [min,max] 随机分钟
    // ------------------------------------------------------------------

    /**
     * @param list<int> $accountIds
     * @return list<array{account_id:int,scheduled_at:string}>
     */
    public static function buildSchedule(array $accountIds, float $minMin, float $maxMin): array
    {
        $out = [];
        $cursor = time();
        foreach ($accountIds as $i => $aid) {
            if ($i > 0) {
                $cursor += self::randomSeconds($minMin, $maxMin);
            }
            $out[] = ['account_id' => (int)$aid, 'scheduled_at' => date('Y-m-d H:i:s', $cursor)];
        }
        return $out;
    }

    private static function randomSeconds(float $minMin, float $maxMin): int
    {
        $lo = (int)round($minMin * 60);
        $hi = (int)round($maxMin * 60);
        if ($hi <= $lo) {
            return max(1, $lo);
        }
        return random_int(max(1, $lo), $hi);
    }

    // ------------------------------------------------------------------
    // 创建 / 编辑
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function create(array $input, int $ownerId): array
    {
        self::ensureSchema();
        $cfg = self::normalizeInput($input, null);
        $taskId = DB::transaction(static function () use ($cfg, $ownerId) {
            DB::query(
                "INSERT INTO video_boost_tasks
                 (owner_id, name, material_id, video_title, video_desc, video_url, video_id,
                  publisher_account_id, publisher_username, publisher_nickname,
                  account_mode, account_ids_json, account_count, template_id,
                  comment_mode, comment_ai_prompt, comment_text,
                  min_interval_minutes, max_interval_minutes,
                  do_like, do_comment, do_share, save_traffic,
                  status, dispatch_enabled, scheduled_count)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',1,?)",
                self::taskColumnValues($cfg, $ownerId, true)
            );
            $id = (int)DB::getPdo()->lastInsertId();
            self::insertItems($id, $ownerId, $cfg);
            return $id;
        });
        $task = self::getTask((int)$taskId, true);
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
        $row = DB::selectOne('SELECT * FROM video_boost_tasks WHERE id=? AND deleted_at IS NULL', [$id]);
        if (!$row) {
            throw new \InvalidArgumentException('任务不存在');
        }
        if (!self::canRebuildSchedule($id)) {
            throw new \InvalidArgumentException('该任务已有明细入队/执行，不能再编辑；请先取消任务后重新创建');
        }
        $cfg = self::normalizeInput($input, $row);
        $ownerId = (int)($row['owner_id'] ?? 0);
        DB::transaction(static function () use ($id, $cfg, $ownerId) {
            DB::query('DELETE FROM video_boost_task_items WHERE task_id=?', [$id]);
            self::insertItems($id, $ownerId, $cfg);
            DB::query(
                "UPDATE video_boost_tasks SET
                   name=?, material_id=?, video_title=?, video_desc=?, video_url=?, video_id=?,
                   publisher_account_id=?, publisher_username=?, publisher_nickname=?,
                   account_mode=?, account_ids_json=?, account_count=?, template_id=?,
                   comment_mode=?, comment_ai_prompt=?, comment_text=?,
                   min_interval_minutes=?, max_interval_minutes=?,
                   do_like=?, do_comment=?, do_share=?, save_traffic=?,
                   status='pending', scheduled_count=?, enqueued_count=0, success_count=0, failed_count=0,
                   error_message='', updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                array_merge(self::taskColumnValues($cfg, $ownerId, false), [$id])
            );
        });
        $task = self::getTask($id, true);
        if (!$task) {
            throw new \RuntimeException('更新失败');
        }
        return $task;
    }

    /**
     * 解析并校验输入，得到完整配置（含排程）。
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $old
     * @return array<string,mixed>
     */
    private static function normalizeInput(array $input, ?array $old): array
    {
        $pick = static function (array $keys, mixed $default = null) use ($input): mixed {
            foreach ($keys as $k) {
                if (array_key_exists($k, $input)) {
                    return $input[$k];
                }
            }
            return $default;
        };
        $materialId = (int)$pick(['materialId', 'material_id'], $old['material_id'] ?? 0);
        $video = self::resolveVideo($materialId);

        $minMin = (float)$pick(['minIntervalMinutes', 'min_interval_minutes'], $old['min_interval_minutes'] ?? 0);
        $maxMin = (float)$pick(['maxIntervalMinutes', 'max_interval_minutes'], $old['max_interval_minutes'] ?? 0);
        if ($minMin <= 0 || $maxMin <= 0 || $minMin > $maxMin) {
            throw new \InvalidArgumentException('间隔分钟须满足 0 < 最小间隔 ≤ 最大间隔');
        }

        $doLike = self::boolInput($pick(['doLike', 'do_like']), $old ? ((int)$old['do_like'] === 1) : true);
        $doComment = self::boolInput($pick(['doComment', 'do_comment']), $old ? ((int)$old['do_comment'] === 1) : true);
        $doShare = self::boolInput($pick(['doShare', 'do_share']), $old ? ((int)$old['do_share'] === 1) : true);
        $saveTraffic = self::boolInput($pick(['saveTraffic', 'save_traffic']), $old ? ((int)$old['save_traffic'] === 1) : true);

        $tplId = (int)$pick(['templateId', 'template_id'], $old['template_id'] ?? 0);
        $tpl = self::resolveTemplate($tplId, $doComment);

        $accInput = $input;
        if ($old) {
            foreach (['accountMode' => 'account_mode', 'accountCount' => 'account_count'] as $camel => $snake) {
                if (!array_key_exists($camel, $accInput) && !array_key_exists($snake, $accInput)) {
                    $accInput[$camel] = $old[$snake] ?? null;
                }
            }
            if (!array_key_exists('accountIds', $accInput) && !array_key_exists('account_ids', $accInput)) {
                $accInput['accountIds'] = json_decode((string)($old['account_ids_json'] ?? '[]'), true) ?: [];
            }
        }
        $acc = self::resolveAccounts($accInput, $video['publisherAccountId']);
        $schedule = self::buildSchedule($acc['picked'], $minMin, $maxMin);

        $name = trim((string)$pick(['name'], $old['name'] ?? ''));
        if ($name === '') {
            $name = '视频增值-' . date('mdHis');
        }

        return [
            'name' => mb_substr($name, 0, 191),
            'video' => $video,
            'accountMode' => $acc['mode'],
            'accountIds' => $acc['ids'],
            'accountCount' => $acc['count'],
            'template' => $tpl,
            'minMin' => $minMin,
            'maxMin' => $maxMin,
            'doLike' => $doLike,
            'doComment' => $doComment,
            'doShare' => $doShare,
            'saveTraffic' => $saveTraffic,
            'schedule' => $schedule,
        ];
    }

    /**
     * @param array<string,mixed> $cfg
     * @return list<mixed>
     */
    private static function taskColumnValues(array $cfg, int $ownerId, bool $forInsert): array
    {
        $v = $cfg['video'];
        $t = $cfg['template'];
        $vals = [];
        if ($forInsert) {
            $vals[] = $ownerId;
        }
        array_push(
            $vals,
            $cfg['name'],
            $v['materialId'],
            $v['title'],
            $v['description'],
            $v['videoUrl'],
            $v['videoId'],
            $v['publisherAccountId'],
            $v['publisherUsername'],
            $v['publisherNickname'],
            $cfg['accountMode'],
            json_encode($cfg['accountIds'], JSON_UNESCAPED_UNICODE),
            $cfg['accountCount'],
            $t['templateId'],
            $t['commentMode'],
            $t['commentAiPrompt'],
            $t['commentText'],
            $cfg['minMin'],
            $cfg['maxMin'],
            $cfg['doLike'] ? 1 : 0,
            $cfg['doComment'] ? 1 : 0,
            $cfg['doShare'] ? 1 : 0,
            $cfg['saveTraffic'] ? 1 : 0,
            count($cfg['schedule'])
        );
        return $vals;
    }

    /** @param array<string,mixed> $cfg */
    private static function insertItems(int $taskId, int $ownerId, array $cfg): void
    {
        $plan = json_encode([
            'doLike' => $cfg['doLike'],
            'doComment' => $cfg['doComment'],
            'doShare' => $cfg['doShare'],
            'commentMode' => $cfg['template']['commentMode'],
            'commentAiTemplateId' => $cfg['template']['templateId'],
            'commentAiPrompt' => $cfg['template']['commentAiPrompt'],
            'commentText' => $cfg['template']['commentText'],
        ], JSON_UNESCAPED_UNICODE);
        foreach ($cfg['schedule'] as $row) {
            DB::query(
                "INSERT INTO video_boost_task_items
                 (task_id, account_id, owner_id, scheduled_at, action_plan_json, status)
                 VALUES (?,?,?,?,?,'pending')",
                [$taskId, (int)$row['account_id'], $ownerId, $row['scheduled_at'], $plan]
            );
        }
    }

    public static function canRebuildSchedule(int $taskId): bool
    {
        $hit = DB::selectOne(
            "SELECT id FROM video_boost_task_items
             WHERE task_id=? AND status IN ('enqueued','running','success')
             LIMIT 1",
            [$taskId]
        );
        return !$hit;
    }

    private static function boolInput(mixed $v, bool $default): bool
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string)$v));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

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
            $filters[] = '(t.name LIKE ? OR t.video_title LIKE ? OR t.publisher_username LIKE ? OR t.publisher_nickname LIKE ? OR CAST(t.id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($status !== '' && in_array($status, ['pending', 'running', 'completed', 'cancelled', 'failed'], true)) {
            $filters[] = 't.status=?';
            $params[] = $status;
        }
        $where = 'WHERE ' . implode(' AND ', $filters);
        $pageSize = max(1, min(50, $pageSize));
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM video_boost_tasks t {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT t.* FROM video_boost_tasks t {$where} ORDER BY t.id DESC LIMIT {$offset}, {$pageSize}",
            $params
        ) ?: [];

        $locked = [];
        $ids = array_values(array_filter(array_map(static fn(array $r) => (int)$r['id'], $rows)));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::select(
                "SELECT DISTINCT task_id FROM video_boost_task_items
                 WHERE task_id IN ({$ph}) AND status IN ('enqueued','running','success')",
                $ids
            ) ?: [] as $lr) {
                $locked[(int)$lr['task_id']] = true;
            }
        }
        return [
            'items' => array_map(static fn(array $r) => self::formatTask($r, !isset($locked[(int)$r['id']])), $rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => $pageSize,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function getTask(int $id, bool $withItems = false, int $itemPage = 1, int $itemPageSize = 50): ?array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM video_boost_tasks WHERE id=? AND deleted_at IS NULL', [$id]);
        if (!$row) {
            return null;
        }
        $task = self::formatTask($row, self::canRebuildSchedule($id));
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
        $total = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM video_boost_task_items WHERE task_id=?', [$taskId])['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT i.*, a.username AS account_username, a.nickname AS account_nickname
             FROM video_boost_task_items i
             LEFT JOIN accounts a ON a.id=i.account_id
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

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function formatTask(array $row, ?bool $canEdit = null): array
    {
        $ids = json_decode((string)($row['account_ids_json'] ?? '[]'), true);
        return [
            'id' => (int)$row['id'],
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'materialId' => (int)($row['material_id'] ?? 0),
            'videoTitle' => (string)($row['video_title'] ?? ''),
            'videoDesc' => (string)($row['video_desc'] ?? ''),
            'videoUrl' => (string)($row['video_url'] ?? ''),
            'videoId' => (string)($row['video_id'] ?? ''),
            'publisherAccountId' => (int)($row['publisher_account_id'] ?? 0),
            'publisherUsername' => (string)($row['publisher_username'] ?? ''),
            'publisherNickname' => (string)($row['publisher_nickname'] ?? ''),
            'accountMode' => (string)($row['account_mode'] ?? 'random'),
            'accountIds' => is_array($ids) ? array_values(array_map('intval', $ids)) : [],
            'accountCount' => (int)($row['account_count'] ?? 0),
            'templateId' => (int)($row['template_id'] ?? 0),
            'commentMode' => (string)($row['comment_mode'] ?? 'ai'),
            'minIntervalMinutes' => (float)($row['min_interval_minutes'] ?? 0),
            'maxIntervalMinutes' => (float)($row['max_interval_minutes'] ?? 0),
            'doLike' => (int)($row['do_like'] ?? 1) === 1,
            'doComment' => (int)($row['do_comment'] ?? 1) === 1,
            'doShare' => (int)($row['do_share'] ?? 1) === 1,
            'saveTraffic' => (int)($row['save_traffic'] ?? 1) === 1,
            'status' => (string)($row['status'] ?? 'pending'),
            'dispatchEnabled' => (int)($row['dispatch_enabled'] ?? 1) === 1,
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

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function formatItem(array $row): array
    {
        $plan = json_decode((string)($row['action_plan_json'] ?? '{}'), true);
        $result = json_decode((string)($row['result_json'] ?? ''), true);
        return [
            'id' => (int)$row['id'],
            'taskId' => (int)$row['task_id'],
            'accountId' => (int)$row['account_id'],
            'accountUsername' => (string)($row['account_username'] ?? ''),
            'accountNickname' => (string)($row['account_nickname'] ?? ''),
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'scheduledAt' => (string)($row['scheduled_at'] ?? ''),
            'status' => (string)($row['status'] ?? 'pending'),
            'workItemId' => isset($row['work_item_id']) ? (int)$row['work_item_id'] : null,
            'errorMessage' => (string)($row['error_message'] ?? ''),
            'executedAt' => (string)($row['executed_at'] ?? ''),
            'actionPlan' => is_array($plan) ? $plan : [],
            'result' => is_array($result) ? $result : null,
        ];
    }

    // ------------------------------------------------------------------
    // 取消 / 删除 / 派发开关
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public static function cancel(int $id): array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT id FROM video_boost_tasks WHERE id=? AND deleted_at IS NULL', [$id]);
        if (!$row) {
            throw new \InvalidArgumentException('任务不存在');
        }
        DB::query(
            "UPDATE video_boost_task_items SET status='cancelled', updated_at=CURRENT_TIMESTAMP
             WHERE task_id=? AND status IN ('pending','enqueued')",
            [$id]
        );
        AccountWorkBatchService::cancelItemsForTask(self::TASK_TYPE, $id);
        DB::query("UPDATE video_boost_tasks SET status='cancelled', updated_at=CURRENT_TIMESTAMP WHERE id=?", [$id]);
        return self::getTask($id, true) ?: ['id' => $id, 'status' => 'cancelled'];
    }

    public static function delete(int $id): void
    {
        self::ensureSchema();
        self::cancel($id);
        DB::query('UPDATE video_boost_tasks SET deleted_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$id]);
    }

    /** @return array<string,mixed> */
    public static function setDispatchEnabled(int $id, bool $enabled): array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT id FROM video_boost_tasks WHERE id=? AND deleted_at IS NULL', [$id]);
        if (!$row) {
            throw new \InvalidArgumentException('任务不存在');
        }
        DB::query(
            'UPDATE video_boost_tasks SET dispatch_enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$enabled ? 1 : 0, $id]
        );
        return self::getTask($id) ?: ['id' => $id];
    }

    // ------------------------------------------------------------------
    // 派发：到期明细 → 推给「创建任务的管理用户」的账号会话总任务
    // ------------------------------------------------------------------

    /**
     * @return array{scanned:int,enqueued:int,failed:int,skipped:int,items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 50): array
    {
        self::ensureSchema();
        AccountWorkBatchService::ensureSchema();
        $limit = max(1, min(200, $limit));
        $rows = DB::select(
            "SELECT i.*, t.status AS task_status, t.owner_id AS task_owner_id,
                    t.id AS _task_id, t.material_id, t.video_title, t.video_desc, t.video_url, t.video_id,
                    t.publisher_account_id, t.publisher_username, t.publisher_nickname, t.save_traffic
             FROM video_boost_task_items i
             INNER JOIN video_boost_tasks t ON t.id=i.task_id
             WHERE i.status='pending'
               AND i.scheduled_at<=NOW()
               AND t.deleted_at IS NULL
               AND t.status NOT IN ('cancelled','failed')
               AND COALESCE(t.dispatch_enabled,1)=1
             ORDER BY i.scheduled_at ASC, i.id ASC
             LIMIT {$limit}"
        ) ?: [];

        $enqueued = 0;
        $failed = 0;
        $skipped = 0;
        $items = [];
        foreach ($rows as $row) {
            $res = self::enqueueDueItem($row);
            $items[] = array_merge(['id' => (int)$row['id'], 'taskId' => (int)$row['_task_id']], $res);
            if (!empty($res['ok'])) {
                $enqueued++;
            } elseif (!empty($res['skipped'])) {
                $skipped++;
            } else {
                $failed++;
            }
        }
        return ['scanned' => count($rows), 'enqueued' => $enqueued, 'failed' => $failed, 'skipped' => $skipped, 'items' => $items];
    }

    /**
     * @param array<string,mixed> $row tick 联表后的明细行
     * @return array<string,mixed>
     */
    public static function enqueueDueItem(array $row): array
    {
        $itemId = (int)($row['id'] ?? 0);
        $taskId = (int)($row['_task_id'] ?? $row['task_id'] ?? 0);
        $accountId = (int)($row['account_id'] ?? 0);
        if ($itemId < 1 || $taskId < 1 || $accountId < 1) {
            return ['ok' => false, 'reason' => '明细数据无效'];
        }
        // 原子占位，避免多个 cron 进程重复派发
        $claimed = DB::execute(
            "UPDATE video_boost_task_items SET status='enqueued', updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='pending'",
            [$itemId]
        );
        if ($claimed < 1) {
            return ['ok' => false, 'skipped' => true, 'reason' => '明细已被处理'];
        }
        try {
            // 推送给创建任务的管理用户；任务无归属时才回落到账号归属
            $ownerId = (int)($row['task_owner_id'] ?? $row['owner_id'] ?? 0);
            if ($ownerId < 1) {
                $acc = DB::selectOne('SELECT owner_id FROM accounts WHERE id=?', [$accountId]);
                $ownerId = (int)($acc['owner_id'] ?? 0);
            }
            if ($ownerId < 1) {
                throw new \RuntimeException('任务没有归属用户，无法推送');
            }
            $plan = json_decode((string)($row['action_plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $nickname = trim((string)($row['publisher_nickname'] ?? ''));
            $username = ltrim(trim((string)($row['publisher_username'] ?? '')), '@');
            $payload = [
                'taskId' => $taskId,
                'itemId' => $itemId,
                'materialId' => (int)($row['material_id'] ?? 0),
                'videoTitle' => (string)($row['video_title'] ?? ''),
                'videoDesc' => (string)($row['video_desc'] ?? ''),
                'videoUrl' => (string)($row['video_url'] ?? ''),
                'videoId' => (string)($row['video_id'] ?? ''),
                'publisherAccountId' => (int)($row['publisher_account_id'] ?? 0),
                'publisherUsername' => $username,
                'publisherNickname' => $nickname,
                // 按「发布视频的账号昵称」搜索，没有昵称才用用户名
                'searchKeyword' => $nickname !== '' ? $nickname : $username,
                'actionPlan' => $plan,
                'saveTraffic' => !array_key_exists('save_traffic', $row) || (int)$row['save_traffic'] === 1,
            ];
            $enq = AccountWorkBatchService::enqueueItem($accountId, self::TASK_TYPE, $payload, $taskId, $ownerId);
            DB::query(
                "UPDATE video_boost_task_items SET owner_id=?, work_item_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$ownerId, (int)$enq['item_id'], $itemId]
            );
            DB::query(
                "UPDATE video_boost_tasks
                 SET enqueued_count=enqueued_count+1,
                     status=IF(status='pending','running',status),
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$taskId]
            );
            return [
                'ok' => true,
                'batch_id' => (int)$enq['batch_id'],
                'work_item_id' => (int)$enq['item_id'],
                'owner_id' => $ownerId,
            ];
        } catch (\Throwable $e) {
            DB::query(
                "UPDATE video_boost_task_items
                 SET status='failed', error_message=?, executed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [mb_substr($e->getMessage(), 0, 500), $itemId]
            );
            DB::query('UPDATE video_boost_tasks SET failed_count=failed_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$taskId]);
            self::refreshTaskStatus($taskId);
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Node / 账号总任务 回写
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $data success, error, result(any)
     * @return array<string,mixed>
     */
    public static function finishItem(int $itemId, array $data): array
    {
        self::ensureSchema();
        $item = DB::selectOne('SELECT * FROM video_boost_task_items WHERE id=?', [$itemId]);
        if (!$item) {
            throw new \InvalidArgumentException('明细不存在');
        }
        $taskId = (int)$item['task_id'];
        $prev = (string)$item['status'];
        $success = (bool)($data['success'] ?? false);
        $error = trim((string)($data['error'] ?? $data['errorMessage'] ?? ''));
        $resultJson = null;
        if (isset($data['result']) && is_array($data['result'])) {
            $resultJson = json_encode($data['result'], JSON_UNESCAPED_UNICODE);
        }

        if ($prev === 'cancelled') {
            return self::getTask($taskId) ?: ['id' => $taskId];
        }
        if ($success) {
            DB::query(
                "UPDATE video_boost_task_items
                 SET status='success', error_message='', result_json=COALESCE(?, result_json),
                     executed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$resultJson, $itemId]
            );
        } else {
            DB::query(
                "UPDATE video_boost_task_items
                 SET status='failed', error_message=?, result_json=COALESCE(?, result_json),
                     executed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [mb_substr($error !== '' ? $error : '视频增值失败', 0, 500), $resultJson, $itemId]
            );
        }
        // 计数以明细表为准重算，天然幂等（Node 回写与总任务收口可能各调一次）
        self::refreshTaskStatus($taskId);
        return self::getTask($taskId) ?: ['id' => $taskId, 'itemId' => $itemId];
    }

    public static function refreshTaskStatus(int $taskId): void
    {
        $s = DB::selectOne(
            "SELECT SUM(status='pending') AS pending_n, SUM(status='enqueued') AS enqueued_n,
                    SUM(status='running') AS running_n, SUM(status='success') AS success_n,
                    SUM(status='failed') AS failed_n, COUNT(*) AS total_n
             FROM video_boost_task_items WHERE task_id=?",
            [$taskId]
        );
        $pending = (int)($s['pending_n'] ?? 0);
        $enqueued = (int)($s['enqueued_n'] ?? 0);
        $running = (int)($s['running_n'] ?? 0);
        $success = (int)($s['success_n'] ?? 0);
        $failed = (int)($s['failed_n'] ?? 0);
        $total = (int)($s['total_n'] ?? 0);

        $task = DB::selectOne('SELECT status FROM video_boost_tasks WHERE id=?', [$taskId]);
        if (!$task || (string)$task['status'] === 'cancelled') {
            return;
        }
        $status = 'pending';
        if ($pending + $enqueued + $running > 0) {
            $status = ($enqueued + $running + $success + $failed) > 0 ? 'running' : 'pending';
        } elseif ($total > 0) {
            $status = ($success === 0 && $failed > 0) ? 'failed' : 'completed';
        }
        DB::query(
            "UPDATE video_boost_tasks
             SET status=?, success_count=?, failed_count=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status<>'cancelled'",
            [$status, $success, $failed, $taskId]
        );
    }
}
