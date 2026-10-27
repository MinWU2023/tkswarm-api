<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AgentChatService;
use App\Services\Ai\AiPlatformCatalog;
use App\Services\DataScope;
use App\Services\SeedMeta;
use PDOException;

class AgentController extends Controller
{
    public function templates(Request $request): Response
    {
        SeedMeta::ensure();
        $this->ensureAgentsSchema();
        $rows = DB::select(
            'SELECT `key`, name, description, payload_json, sort_order FROM agent_templates ORDER BY sort_order, `key`'
        );
        $out = [];
        foreach ($rows as $row) {
            $payload = json_decode($row['payload_json'] ?? '{}', true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $out[] = array_merge([
                'key' => $row['key'],
                'name' => $row['name'],
                'description' => $row['description'],
            ], $payload);
        }
        return $this->success($out);
    }

    public function list(Request $request): Response
    {
        $this->ensureAgentsSchema();
        DataScope::ensureColumns();
        $filters = [];
        $params = [];
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') {
            $filters[] = '(name LIKE ? OR description LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $this->applyOwnerFilter('a.owner_id', $filters, $params);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $page = (int)$request->input('page', $request->get('page', 0));
        $pageSize = (int)$request->input('pageSize', $request->input('page_size', $request->get('pageSize', 20)));
        if ($page > 0) {
            $pageSize = max(1, min(100, $pageSize > 0 ? $pageSize : 20));
            $totalRow = DB::selectOne("SELECT COUNT(*) AS c FROM agents a {$where}", $params);
            $total = (int)($totalRow['c'] ?? 0);
            $pages = max(1, (int)ceil($total / $pageSize));
            $page = min(max(1, $page), $pages);
            $offset = ($page - 1) * $pageSize;
            $rows = DB::select(
                "SELECT a.*, p.name AS platform_name, m.name AS model_name
                 FROM agents a
                 LEFT JOIN ai_platforms p ON p.id=a.platform_id
                 LEFT JOIN ai_platform_models m ON m.platform_id=a.platform_id AND m.code=a.model_id
                 {$where} ORDER BY a.id DESC LIMIT {$offset}, {$pageSize}",
                $params
            );
            return $this->success([
                'items' => array_map([$this, 'mapAgent'], $rows ?: []),
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'pages' => $pages,
            ]);
        }
        $rows = DB::select(
            "SELECT a.*, p.name AS platform_name, m.name AS model_name
             FROM agents a
             LEFT JOIN ai_platforms p ON p.id=a.platform_id
             LEFT JOIN ai_platform_models m ON m.platform_id=a.platform_id AND m.code=a.model_id
             {$where} ORDER BY a.id DESC",
            $params
        );
        return $this->success(array_map([$this, 'mapAgent'], $rows));
    }

    public function detail(Request $request, string $id): Response
    {
        $this->ensureAgentsSchema();
        DataScope::ensureColumns();
        $agent = DB::selectOne('SELECT * FROM agents WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($agent)) {
            return $err;
        }
        return $this->success($this->mapAgent($agent));
    }

    public function save(Request $request): Response
    {
        return $this->create($request);
    }

    public function create(Request $request): Response
    {
        $this->ensureAgentsSchema();
        DataScope::ensureColumns();
        try {
            $body = $this->parseBody($request);
            $payload = $this->persistPayload($body);
            DB::query(
                'INSERT INTO agents(name,description,avatar,enabled,model_type,model_id,platform_id,api_base,api_key,temperature,reply_tone,reply_language,role_info,mission,rules,openings_json,success_terminate_json,fail_terminate_json,poll_json,owner_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $payload['name'], $payload['description'], $payload['avatar'], $payload['enabled'],
                    $payload['model_type'], $payload['model_id'], $payload['platform_id'],
                    $payload['api_base'], $payload['api_key'],
                    $payload['temperature'], $payload['reply_tone'], $payload['reply_language'],
                    $payload['role_info'], $payload['mission'], $payload['rules'],
                    $payload['openings_json'], $payload['success_terminate_json'],
                    $payload['fail_terminate_json'], $payload['poll_json'],
                    $this->writeOwnerId(),
                ]
            );
            $id = (int)DB::getPdo()->lastInsertId();
            $row = DB::selectOne('SELECT * FROM agents WHERE id=?', [$id]);
            return $this->success($this->mapAgent($row), '智能体已创建');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return $this->error('智能体名称已存在', 409);
            }
            throw $e;
        }
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureAgentsSchema();
        DataScope::ensureColumns();
        $existing = DB::selectOne('SELECT * FROM agents WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($existing)) {
            return $err;
        }
        try {
            $body = $this->parseBody($request);
            $payload = $this->persistPayload($body, $existing);
            DB::query(
                'UPDATE agents SET name=?,description=?,avatar=?,enabled=?,model_type=?,model_id=?,platform_id=?,api_base=?,api_key=?,temperature=?,reply_tone=?,reply_language=?,role_info=?,mission=?,rules=?,openings_json=?,success_terminate_json=?,fail_terminate_json=?,poll_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [
                    $payload['name'], $payload['description'], $payload['avatar'], $payload['enabled'],
                    $payload['model_type'], $payload['model_id'], $payload['platform_id'],
                    $payload['api_base'], $payload['api_key'],
                    $payload['temperature'], $payload['reply_tone'], $payload['reply_language'],
                    $payload['role_info'], $payload['mission'], $payload['rules'],
                    $payload['openings_json'], $payload['success_terminate_json'],
                    $payload['fail_terminate_json'], $payload['poll_json'], (int)$id,
                ]
            );
            $row = DB::selectOne('SELECT * FROM agents WHERE id=?', [(int)$id]);
            return $this->success($this->mapAgent($row), '智能体已更新');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return $this->error('智能体名称已存在', 409);
            }
            throw $e;
        }
    }

    public function patchStatus(Request $request, string $id): Response
    {
        $this->ensureAgentsSchema();
        DataScope::ensureColumns();
        $existing = DB::selectOne('SELECT id, owner_id FROM agents WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($existing)) {
            return $err;
        }
        $enabled = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN);
        DB::query(
            'UPDATE agents SET enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$enabled ? 1 : 0, (int)$id]
        );
        $row = DB::selectOne('SELECT * FROM agents WHERE id=?', [(int)$id]);
        return $this->success($this->mapAgent($row), $enabled ? '已启用' : '已禁用');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureAgentsSchema();
        DataScope::ensureColumns();
        $existing = DB::selectOne('SELECT id, owner_id FROM agents WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($existing)) {
            return $err;
        }
        DB::query('DELETE FROM agents WHERE id=?', [(int)$id]);
        return $this->success(null, '智能体已删除');
    }

    public function batchDelete(Request $request): Response
    {
        $this->ensureAgentsSchema();
        DataScope::ensureColumns();
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $ids = array_slice($ids, 0, 100);
        if (!$ids) {
            return $this->error('请选择智能体', 400);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $stmt = DB::query("DELETE FROM agents WHERE id IN ({$placeholders}){$ownSql}", array_merge($ids, $ownParams));
        return $this->success(['deleted' => $stmt->rowCount()], '批量删除完成');
    }

    public function testChat(Request $request, string $id): Response
    {
        $this->ensureAgentsSchema();
        DataScope::ensureColumns();
        $agent = DB::selectOne('SELECT * FROM agents WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($agent)) {
            return $err;
        }
        $content = trim((string)$request->input('content', ''));
        if ($content === '') {
            return $this->error('请输入消息', 400);
        }
        try {
            $reply = AgentChatService::reply($agent, $content, (array)$request->input('history', []));
            return $this->success(['reply' => $reply, 'source' => 'api', 'terminated' => false]);
        } catch (\Throwable $e) {
            return $this->error('智能体调用失败：' . $e->getMessage(), 422);
        }
    }

    private function parseBody(Request $request): array
    {
        AiPlatformCatalog::ensure();
        $all = $request->all();
        $name = trim((string)($all['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('智能体名称不能为空');
        }
        $platformId = (int)($all['platformId'] ?? $all['platform_id'] ?? 0);
        $modelId = trim((string)($all['modelId'] ?? $all['model_id'] ?? ''));
        if ($platformId < 1) {
            throw new \InvalidArgumentException('请选择 AI 开放平台');
        }
        if ($modelId === '') {
            throw new \InvalidArgumentException('请选择模型');
        }
        AgentChatService::assertPlatformModel($platformId, $modelId);
        $tone = trim((string)($all['replyTone'] ?? $all['reply_tone'] ?? 'friendly'));
        if (!isset(AgentChatService::TONES[$tone])) {
            $tone = 'friendly';
        }
        $lang = trim((string)($all['replyLanguage'] ?? $all['reply_language'] ?? 'zh'));
        if ($lang === 'auto' || !isset(AgentChatService::LANGUAGES[$lang])) {
            $lang = 'zh';
        }
        $all['platformId'] = $platformId;
        $all['modelId'] = $modelId;
        $all['replyTone'] = $tone;
        $all['replyLanguage'] = $lang;
        return $all;
    }

    private function persistPayload(array $body, ?array $existing = null): array
    {
        $platformId = (int)($body['platformId'] ?? $body['platform_id'] ?? $existing['platform_id'] ?? 0);
        $modelId = trim((string)($body['modelId'] ?? $body['model_id'] ?? $existing['model_id'] ?? ''));

        return [
            'name' => trim((string)$body['name']),
            'description' => trim((string)($existing['description'] ?? '')),
            'avatar' => trim((string)($existing['avatar'] ?? '')),
            'enabled' => filter_var($body['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'model_type' => 'platform',
            'model_id' => $modelId,
            'platform_id' => $platformId > 0 ? $platformId : null,
            'api_base' => '',
            'api_key' => '',
            'temperature' => 0.7,
            'reply_tone' => trim((string)($body['replyTone'] ?? 'friendly')),
            'reply_language' => trim((string)($body['replyLanguage'] ?? 'zh')),
            'role_info' => '',
            'mission' => '',
            'rules' => '',
            'openings_json' => json_encode(['items' => [], 'autoOpening' => false], JSON_UNESCAPED_UNICODE),
            'success_terminate_json' => json_encode(['enabled' => false, 'keywords' => [], 'reply' => '', 'match' => 'contains'], JSON_UNESCAPED_UNICODE),
            'fail_terminate_json' => json_encode(['enabled' => false, 'keywords' => [], 'reply' => '', 'match' => 'contains'], JSON_UNESCAPED_UNICODE),
            'poll_json' => json_encode(['enabled' => false, 'resources' => [], 'limit' => 1], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function mapAgent(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $platformId = isset($row['platform_id']) ? (int)$row['platform_id'] : 0;
        $modelId = (string)($row['model_id'] ?? '');
        $platformName = (string)($row['platform_name'] ?? '');
        $modelName = (string)($row['model_name'] ?? '');
        if ($platformId > 0 && ($platformName === '' || $modelName === '')) {
            $extra = DB::selectOne(
                'SELECT p.name AS platform_name, m.name AS model_name
                 FROM ai_platforms p
                 LEFT JOIN ai_platform_models m ON m.platform_id=p.id AND m.code=?
                 WHERE p.id=?',
                [$modelId, $platformId]
            );
            if ($extra) {
                $platformName = $platformName !== '' ? $platformName : (string)($extra['platform_name'] ?? '');
                $modelName = $modelName !== '' ? $modelName : (string)($extra['model_name'] ?? '');
            }
        }

        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'enabled' => !empty($row['enabled']),
            'platformId' => $platformId ?: null,
            'platformName' => $platformName,
            'modelId' => $modelId,
            'modelName' => $modelName !== '' ? $modelName : $modelId,
            'replyTone' => $row['reply_tone'] ?? 'friendly',
            'replyLanguage' => $row['reply_language'] ?? 'zh',
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    private function ensureAgentsSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        AiPlatformCatalog::ensure();

        $cols = DB::select('SHOW COLUMNS FROM agents');
        $names = array_column($cols ?: [], 'Field');
        $alters = [
            'description' => "ALTER TABLE agents ADD COLUMN description VARCHAR(512) NOT NULL DEFAULT ''",
            'avatar' => "ALTER TABLE agents ADD COLUMN avatar VARCHAR(512) NOT NULL DEFAULT ''",
            'enabled' => "ALTER TABLE agents ADD COLUMN enabled TINYINT NOT NULL DEFAULT 1",
            'model_type' => "ALTER TABLE agents ADD COLUMN model_type VARCHAR(64) NOT NULL DEFAULT 'platform'",
            'model_id' => "ALTER TABLE agents ADD COLUMN model_id VARCHAR(128) NOT NULL DEFAULT ''",
            'platform_id' => 'ALTER TABLE agents ADD COLUMN platform_id INT NULL',
            'temperature' => "ALTER TABLE agents ADD COLUMN temperature DOUBLE NOT NULL DEFAULT 0.7",
            'reply_tone' => "ALTER TABLE agents ADD COLUMN reply_tone VARCHAR(64) NOT NULL DEFAULT 'friendly'",
            'reply_language' => "ALTER TABLE agents ADD COLUMN reply_language VARCHAR(32) NOT NULL DEFAULT 'zh'",
            'role_info' => "ALTER TABLE agents ADD COLUMN role_info TEXT NULL",
            'mission' => "ALTER TABLE agents ADD COLUMN mission TEXT NULL",
            'rules' => "ALTER TABLE agents ADD COLUMN rules TEXT NULL",
            'openings_json' => "ALTER TABLE agents ADD COLUMN openings_json MEDIUMTEXT NULL",
            'success_terminate_json' => "ALTER TABLE agents ADD COLUMN success_terminate_json MEDIUMTEXT NULL",
            'fail_terminate_json' => "ALTER TABLE agents ADD COLUMN fail_terminate_json MEDIUMTEXT NULL",
            'poll_json' => "ALTER TABLE agents ADD COLUMN poll_json MEDIUMTEXT NULL",
        ];
        foreach ($alters as $col => $sql) {
            if (!in_array($col, $names, true)) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e) {
                }
            }
        }
        try {
            DB::query("ALTER TABLE agents MODIFY api_base VARCHAR(512) NOT NULL DEFAULT ''");
            DB::query("ALTER TABLE agents MODIFY api_key VARCHAR(512) NOT NULL DEFAULT ''");
            DB::query("ALTER TABLE agents MODIFY name VARCHAR(191) NOT NULL");
        } catch (\Throwable $e) {
        }
    }
}
