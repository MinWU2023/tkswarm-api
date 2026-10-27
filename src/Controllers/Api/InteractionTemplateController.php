<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;
use App\Services\WarmCommentService;
use PDOException;

/**
 * 互动消息模板：评论 / 回复评论 / 私信 / 回复私信
 * 每条仅一种内容：固定文案(fixed) 或 AI 提示词(ai)
 */
class InteractionTemplateController extends Controller
{
    public const TYPES = [
        'comment' => '评论',
        'comment_reply' => '回复评论',
        'dm' => '私信',
        'dm_reply' => '回复私信',
    ];

    public const CONTENT_MODES = [
        'fixed' => '固定文案',
        'ai' => 'AI 提示词',
    ];

    public function list(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $type = trim((string)$request->get('type', ''));
        $contentMode = strtolower(trim((string)$request->get('contentMode', $request->get('content_mode', ''))));
        $keyword = trim((string)$request->get('keyword', ''));
        $filters = [];
        $params = [];
        if ($type !== '' && isset(self::TYPES[$type])) {
            $filters[] = 'type=?';
            $params[] = $type;
        }
        if ($contentMode !== '' && isset(self::CONTENT_MODES[$contentMode])) {
            $filters[] = 'content_mode=?';
            $params[] = $contentMode;
        }
        if ($keyword !== '') {
            $filters[] = '(name LIKE ? OR content LIKE ? OR fixed_text LIKE ? OR ai_prompt LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $oid = DataScope::filterOwnerId();
        if ($oid !== null) {
            $filters[] = '(owner_id=? OR owner_id IS NULL OR is_builtin=1)';
            $params[] = $oid;
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $rows = DB::select(
            "SELECT * FROM interaction_templates {$where} ORDER BY FIELD(type,'comment','comment_reply','dm','dm_reply'), FIELD(content_mode,'fixed','ai'), sort_order ASC, id DESC",
            $params
        ) ?: [];
        $counts = [];
        foreach (array_keys(self::TYPES) as $t) {
            $counts[$t] = 0;
        }
        $rawCounts = DB::select(
            'SELECT type, COUNT(*) AS c FROM interaction_templates GROUP BY type'
        ) ?: [];
        foreach ($rawCounts as $c) {
            $t = (string)($c['type'] ?? '');
            if (isset($counts[$t])) {
                $counts[$t] = (int)$c['c'];
            }
        }
        return $this->success([
            'items' => array_map([$this, 'mapRow'], $rows),
            'types' => $this->typeList(),
            'contentModes' => $this->contentModeList(),
            'counts' => $counts,
        ]);
    }

    public function detail(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM interaction_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccess($row)) {
            return $err;
        }
        return $this->success($this->mapRow($row));
    }

    /**
     * 根据 AI 评论 / 回复评论模板 + 上下文生成英文文案
     * type=comment（默认）| comment_reply
     */
    public function generateComment(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $type = strtolower(trim((string)$request->input('type', 'comment')));
        if (!in_array($type, ['comment', 'comment_reply'], true)) {
            $type = 'comment';
        }
        $templateId = (int)$request->input('templateId', $request->input('template_id', 0));
        $prompt = trim((string)$request->input('prompt', $request->input('content', $request->input('aiPrompt', ''))));
        $variables = $request->input('variables', []);
        if (!is_array($variables)) {
            $variables = [];
        }
        if ($prompt === '' && $templateId > 0) {
            $row = DB::selectOne('SELECT * FROM interaction_templates WHERE id=?', [$templateId]);
            if ($err = $this->assertAccess($row)) {
                return $err;
            }
            if (($row['type'] ?? '') !== $type) {
                return $this->error($type === 'comment_reply' ? '仅支持回复评论类型模板' : '仅支持评论类型模板', 422);
            }
            $mapped = $this->mapRow($row);
            if (($mapped['contentMode'] ?? '') !== 'ai') {
                return $this->error('请选择 AI 提示词类型的模板', 422);
            }
            $prompt = trim((string)($mapped['content'] ?? ''));
        }
        try {
            if ($type === 'comment_reply') {
                if ($prompt === '') {
                    $prompt = \App\Services\CommentReplyService::defaultAiPrompt();
                }
                $result = \App\Services\CommentReplyService::generateReply($prompt, $variables);
                // 与评论接口字段对齐，前端可统一读 comment / reply
                $result['comment'] = $result['reply'] ?? '';
                return $this->success($result);
            }
            if ($prompt === '') {
                $prompt = WarmCommentService::defaultAiPrompt();
            }
            $result = WarmCommentService::generateEnglishComment($prompt, $variables);
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error(($type === 'comment_reply' ? '生成回复' : '生成评论') . '失败：' . $e->getMessage(), 500);
        }
    }

    public function create(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        try {
            $payload = $this->parseBody($request);
            $row = [
                'type' => $payload['type'],
                'name' => $payload['name'],
                'content_mode' => $payload['content_mode'],
                'content' => $payload['content'],
                'fixed_text' => $payload['fixed_text'],
                'ai_prompt' => $payload['ai_prompt'],
                'enabled' => $payload['enabled'],
                'sort_order' => $payload['sort_order'],
                'is_builtin' => 0,
            ];
            $this->stampOwner($row);
            $id = (int)DB::table('interaction_templates')->insert($row);
            $fresh = DB::selectOne('SELECT * FROM interaction_templates WHERE id=?', [$id]);
            return $this->success($this->mapRow($fresh), '消息模板已创建');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->error('创建失败：' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM interaction_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccess($old, true)) {
            return $err;
        }
        try {
            $payload = $this->parseBody($request);
            DB::query(
                'UPDATE interaction_templates SET type=?, name=?, content_mode=?, content=?, fixed_text=?, ai_prompt=?, enabled=?, sort_order=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [
                    $payload['type'],
                    $payload['name'],
                    $payload['content_mode'],
                    $payload['content'],
                    $payload['fixed_text'],
                    $payload['ai_prompt'],
                    $payload['enabled'],
                    $payload['sort_order'],
                    (int)$id,
                ]
            );
            $fresh = DB::selectOne('SELECT * FROM interaction_templates WHERE id=?', [(int)$id]);
            return $this->success($this->mapRow($fresh), '消息模板已更新');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function patchStatus(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM interaction_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccess($old, true)) {
            return $err;
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        DB::query('UPDATE interaction_templates SET enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$enabled, (int)$id]);
        $fresh = DB::selectOne('SELECT * FROM interaction_templates WHERE id=?', [(int)$id]);
        return $this->success($this->mapRow($fresh), $enabled ? '已启用' : '已停用');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM interaction_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccess($old, true)) {
            return $err;
        }
        if (!empty($old['is_builtin'])) {
            return $this->error('内置模板不可删除，可停用或编辑文案', 422);
        }
        DB::query('DELETE FROM interaction_templates WHERE id=?', [(int)$id]);
        return $this->success(null, '消息模板已删除');
    }

    /** @return list<array{code:string,label:string}> */
    private function typeList(): array
    {
        $out = [];
        foreach (self::TYPES as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }
        return $out;
    }

    /** @return list<array{code:string,label:string}> */
    private function contentModeList(): array
    {
        $out = [];
        foreach (self::CONTENT_MODES as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }
        return $out;
    }

    /** @param array<string,mixed>|null $row */
    private function mapRow(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $type = (string)($row['type'] ?? '');
        $mode = $this->resolveContentMode($row);
        $content = $this->resolveContent($row, $mode);
        return [
            'id' => (int)$row['id'],
            'type' => $type,
            'typeLabel' => self::TYPES[$type] ?? $type,
            'name' => (string)($row['name'] ?? ''),
            'contentMode' => $mode,
            'contentModeLabel' => self::CONTENT_MODES[$mode] ?? $mode,
            'content' => $content,
            // 兼容旧前端字段
            'fixedText' => $mode === 'fixed' ? $content : '',
            'aiPrompt' => $mode === 'ai' ? $content : '',
            'enabled' => !empty($row['enabled']),
            'sortOrder' => (int)($row['sort_order'] ?? 0),
            'isBuiltin' => !empty($row['is_builtin']),
            'ownerId' => isset($row['owner_id']) ? (int)$row['owner_id'] : null,
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private function resolveContentMode(array $row): string
    {
        $mode = strtolower(trim((string)($row['content_mode'] ?? '')));
        if (isset(self::CONTENT_MODES[$mode])) {
            return $mode;
        }
        $fixed = trim((string)($row['fixed_text'] ?? ''));
        $prompt = trim((string)($row['ai_prompt'] ?? ''));
        if ($fixed === '' && $prompt !== '') {
            return 'ai';
        }
        return 'fixed';
    }

    /** @param array<string,mixed> $row */
    private function resolveContent(array $row, string $mode): string
    {
        $content = trim((string)($row['content'] ?? ''));
        if ($content !== '') {
            return $content;
        }
        if ($mode === 'ai') {
            return trim((string)($row['ai_prompt'] ?? ''));
        }
        return trim((string)($row['fixed_text'] ?? ''));
    }

    /**
     * @return array{
     *   type:string,name:string,content_mode:string,content:string,
     *   fixed_text:string,ai_prompt:string,enabled:int,sort_order:int
     * }
     */
    private function parseBody(Request $request): array
    {
        $type = trim((string)$request->input('type', ''));
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('类型无效，须为 comment / comment_reply / dm / dm_reply');
        }
        $name = trim((string)$request->input('name', ''));
        if ($name === '') {
            throw new \InvalidArgumentException('请填写模板名称');
        }

        $mode = strtolower(trim((string)$request->input('contentMode', $request->input('content_mode', ''))));
        $content = trim((string)$request->input('content', ''));

        // 兼容旧提交：只传 fixedText / aiPrompt
        if ($mode === '' || !isset(self::CONTENT_MODES[$mode])) {
            $fixed = trim((string)$request->input('fixedText', $request->input('fixed_text', '')));
            $prompt = trim((string)$request->input('aiPrompt', $request->input('ai_prompt', '')));
            if ($prompt !== '' && $fixed === '') {
                $mode = 'ai';
                $content = $prompt;
            } elseif ($fixed !== '') {
                $mode = 'fixed';
                $content = $fixed;
            } else {
                $mode = 'fixed';
            }
        }
        if ($content === '') {
            if ($mode === 'ai') {
                $content = trim((string)$request->input('aiPrompt', $request->input('ai_prompt', '')));
            } else {
                $content = trim((string)$request->input('fixedText', $request->input('fixed_text', '')));
            }
        }
        if (!isset(self::CONTENT_MODES[$mode])) {
            throw new \InvalidArgumentException('内容类型须为固定文案或 AI 提示词');
        }
        if ($content === '') {
            throw new \InvalidArgumentException($mode === 'ai' ? '请填写 AI 提示词' : '请填写固定文案');
        }

        return [
            'type' => $type,
            'name' => $name,
            'content_mode' => $mode,
            'content' => $content,
            // 双写旧列，便于未跑迁移的环境仍可读
            'fixed_text' => $mode === 'fixed' ? $content : '',
            'ai_prompt' => $mode === 'ai' ? $content : '',
            'enabled' => filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'sort_order' => max(0, (int)$request->input('sortOrder', $request->input('sort_order', 0))),
        ];
    }

    /** @param array<string,mixed>|null $row */
    private function assertAccess(?array $row, bool $forWrite = false): ?Response
    {
        if (!$row) {
            return $this->error('模板不存在', 404);
        }
        if (!empty($row['is_builtin']) || $row['owner_id'] === null || $row['owner_id'] === '') {
            return null;
        }
        return $this->assertOwns($row);
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            DB::query(
                "CREATE TABLE IF NOT EXISTS `interaction_templates` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `type` VARCHAR(32) NOT NULL,
                  `name` VARCHAR(128) NOT NULL DEFAULT '',
                  `content_mode` VARCHAR(16) NOT NULL DEFAULT 'fixed',
                  `content` MEDIUMTEXT NOT NULL,
                  `fixed_text` TEXT NOT NULL,
                  `ai_prompt` MEDIUMTEXT NOT NULL,
                  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                  `sort_order` INT NOT NULL DEFAULT 0,
                  `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
                  `owner_id` INT NULL,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  KEY `idx_it_type` (`type`),
                  KEY `idx_it_content_mode` (`content_mode`),
                  KEY `idx_it_owner` (`owner_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
        }
        $this->migrateContentColumns();
        $this->seedDefaults();
        $done = true;
    }

    /** 给已有表补 content_mode / content，并拆分旧双字段行 */
    private function migrateContentColumns(): void
    {
        static $migrated = false;
        if ($migrated) {
            return;
        }
        $migrated = true;
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM interaction_templates') ?: [], 'Field');
        } catch (\Throwable $e) {
            return;
        }
        if (!$cols) {
            return;
        }
        if (!in_array('content_mode', $cols, true)) {
            try {
                DB::query("ALTER TABLE interaction_templates ADD COLUMN content_mode VARCHAR(16) NOT NULL DEFAULT 'fixed' AFTER name");
            } catch (\Throwable $e) {
            }
        }
        if (!in_array('content', $cols, true)) {
            try {
                DB::query('ALTER TABLE interaction_templates ADD COLUMN content MEDIUMTEXT NULL AFTER content_mode');
            } catch (\Throwable $e) {
            }
        }
        try {
            // 空 content：从旧列灌入
            DB::query(
                "UPDATE interaction_templates
                 SET content_mode='fixed', content=fixed_text
                 WHERE (content IS NULL OR TRIM(content)='')
                   AND TRIM(IFNULL(fixed_text,''))<>''"
            );
            DB::query(
                "UPDATE interaction_templates
                 SET content_mode='ai', content=ai_prompt
                 WHERE (content IS NULL OR TRIM(content)='')
                   AND TRIM(IFNULL(fixed_text,''))=''
                   AND TRIM(IFNULL(ai_prompt,''))<>''"
            );
            // 双字段都有且尚未拆过：拆出 AI 行（用名称标记避免重复拆）
            $dual = DB::select(
                "SELECT * FROM interaction_templates
                 WHERE TRIM(IFNULL(fixed_text,''))<>''
                   AND TRIM(IFNULL(ai_prompt,''))<>''
                   AND content_mode='fixed'
                   AND content=fixed_text"
            ) ?: [];
            foreach ($dual as $row) {
                $aiName = (string)($row['name'] ?? '') . ' (AI)';
                $exists = DB::selectOne(
                    "SELECT id FROM interaction_templates WHERE type=? AND name=? AND content_mode='ai' AND owner_id <=> ? LIMIT 1",
                    [$row['type'], $aiName, $row['owner_id'] ?? null]
                );
                if ($exists) {
                    continue;
                }
                try {
                    DB::query(
                        'INSERT INTO interaction_templates
                         (type, name, content_mode, content, fixed_text, ai_prompt, enabled, sort_order, is_builtin, owner_id)
                         VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [
                            $row['type'],
                            $aiName,
                            'ai',
                            trim((string)$row['ai_prompt']),
                            '',
                            trim((string)$row['ai_prompt']),
                            (int)($row['enabled'] ?? 1),
                            (int)($row['sort_order'] ?? 0) + 100,
                            (int)($row['is_builtin'] ?? 0),
                            $row['owner_id'] ?? null,
                        ]
                    );
                } catch (\Throwable $e) {
                }
                // 原行清掉 ai_prompt，避免再次拆分
                try {
                    DB::query(
                        "UPDATE interaction_templates SET ai_prompt='' WHERE id=?",
                        [(int)$row['id']]
                    );
                } catch (\Throwable $e) {
                }
            }
            DB::query("UPDATE interaction_templates SET content='' WHERE content IS NULL");
        } catch (\Throwable $e) {
        }
    }

    private function seedDefaults(): void
    {
        $c = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM interaction_templates')['c'] ?? 0);
        if ($c > 0) {
            return;
        }
        $seeds = [
            [
                'comment', '默认评论', 'fixed',
                'Nice video, loved it!',
            ],
            [
                'comment', '默认评论 AI', 'ai',
                "Based on this TikTok video, write one short natural English comment.\nRules:\n1. English only, no quotation marks\n2. 8–40 words, casual tone, optional 1 emoji\n3. No ads, links, or politics\nVideo: {{video_desc}}\nAuthor: {{author}}",
            ],
            [
                'comment_reply', '默认回复评论', 'fixed',
                '谢谢你的留言，欢迎常来看～',
            ],
            [
                'comment_reply', '默认回复评论 AI', 'ai',
                "你是 TikTok 评论回复助手。针对一条用户评论生成简短友好的回复。\n要求：\n1. 只输出回复正文\n2. 10～35 字，礼貌、真诚\n3. 不要攻击、引战\n原评论：{{comment_text}}\n视频主题：{{video_desc}}",
            ],
            [
                'dm', '默认私信', 'fixed',
                '你好，很高兴认识你～',
            ],
            [
                'dm', '默认私信 AI', 'ai',
                "你是 TikTok 私信开场助手。生成一条自然的私信开场白。\n要求：\n1. 只输出私信正文\n2. 20～60 字，亲切不推销\n3. 可结合对方资料\n对方昵称：{{nickname}}\n对方简介：{{bio}}",
            ],
            [
                'dm_reply', '默认回复私信', 'fixed',
                '收到啦，稍后回复你～',
            ],
            [
                'dm_reply', '默认回复私信 AI', 'ai',
                "你是 TikTok 私信回复助手。根据对方来信生成得体回复。\n要求：\n1. 只输出回复正文\n2. 语气友好、简洁\n3. 不要承诺无法兑现的事\n对方来信：{{message_text}}\n对方昵称：{{nickname}}",
            ],
        ];
        foreach ($seeds as $i => [$type, $name, $mode, $content]) {
            try {
                DB::query(
                    'INSERT INTO interaction_templates
                     (type, name, content_mode, content, fixed_text, ai_prompt, enabled, sort_order, is_builtin, owner_id)
                     VALUES (?,?,?,?,?,?,1,?,1,NULL)',
                    [
                        $type,
                        $name,
                        $mode,
                        $content,
                        $mode === 'fixed' ? $content : '',
                        $mode === 'ai' ? $content : '',
                        $i,
                    ]
                );
            } catch (\Throwable $e) {
            }
        }
    }
}
