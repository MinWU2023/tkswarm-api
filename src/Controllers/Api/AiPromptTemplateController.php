<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\Ai\AiPromptCatalog;
use App\Services\Ai\AiPromptHelper;
use App\Services\DataScope;
use PDOException;

/**
 * AI 提示词模板：增删改查 + 变量占位渲染
 */
class AiPromptTemplateController extends Controller
{
    public function list(Request $request): Response
    {
        AiPromptCatalog::ensure();
        DataScope::ensureColumns();
        $category = trim((string)$request->get('category', ''));
        $keyword = trim((string)$request->get('keyword', ''));
        $enabled = $request->get('enabled', '');
        $filters = [];
        $params = [];
        if ($category !== '') {
            $filters[] = 'category=?';
            $params[] = $category;
        }
        if ($keyword !== '') {
            $filters[] = '(name LIKE ? OR code LIKE ? OR description LIKE ? OR content LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($enabled === '1' || $enabled === '0' || $enabled === 1 || $enabled === 0 || $enabled === true || $enabled === false) {
            $filters[] = 'enabled=?';
            $params[] = filter_var($enabled, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $oid = DataScope::filterOwnerId();
        if ($oid !== null) {
            // 可见：本人创建 + 内置/无归属系统模板
            $filters[] = '(owner_id=? OR owner_id IS NULL OR is_builtin=1)';
            $params[] = $oid;
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $rows = DB::select(
            "SELECT * FROM ai_prompt_templates {$where} ORDER BY sort_order ASC, id DESC",
            $params
        ) ?: [];
        return $this->success([
            'items' => array_map([$this, 'mapRow'], $rows),
            'categories' => AiPromptCatalog::categories(),
        ]);
    }

    public function detail(Request $request, string $id): Response
    {
        AiPromptCatalog::ensure();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM ai_prompt_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertPromptAccess($row)) {
            return $err;
        }
        return $this->success($this->mapRow($row));
    }

    public function create(Request $request): Response
    {
        AiPromptCatalog::ensure();
        DataScope::ensureColumns();
        try {
            $payload = $this->parseBody($request);
            $code = $payload['code'];
            if ($code !== '') {
                $dup = DB::selectOne('SELECT id FROM ai_prompt_templates WHERE code=?', [$code]);
                if ($dup) {
                    return $this->error('模板代码已存在', 409);
                }
            } else {
                $code = 'custom_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 10);
            }
            $row = [
                'code' => $code,
                'name' => $payload['name'],
                'category' => $payload['category'],
                'description' => $payload['description'],
                'content' => $payload['content'],
                'variables_json' => json_encode($payload['variables'], JSON_UNESCAPED_UNICODE),
                'enabled' => $payload['enabled'],
                'sort_order' => $payload['sort_order'],
                'is_builtin' => 0,
            ];
            $this->stampOwner($row);
            $id = (int)DB::table('ai_prompt_templates')->insert($row);
            $fresh = DB::selectOne('SELECT * FROM ai_prompt_templates WHERE id=?', [$id]);
            return $this->success($this->mapRow($fresh), '提示词模板已创建');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return $this->error('模板代码已存在', 409);
            }
            throw $e;
        }
    }

    public function update(Request $request, string $id): Response
    {
        AiPromptCatalog::ensure();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM ai_prompt_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertPromptAccess($row)) {
            return $err;
        }
        try {
            $payload = $this->parseBody($request, $row);
            DB::query(
                'UPDATE ai_prompt_templates SET name=?, category=?, description=?, content=?, variables_json=?, enabled=?, sort_order=? WHERE id=?',
                [
                    $payload['name'],
                    $payload['category'],
                    $payload['description'],
                    $payload['content'],
                    json_encode($payload['variables'], JSON_UNESCAPED_UNICODE),
                    $payload['enabled'],
                    $payload['sort_order'],
                    (int)$id,
                ]
            );
            $fresh = DB::selectOne('SELECT * FROM ai_prompt_templates WHERE id=?', [(int)$id]);
            return $this->success($this->mapRow($fresh), '提示词模板已更新');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function delete(Request $request, string $id): Response
    {
        AiPromptCatalog::ensure();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM ai_prompt_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertPromptAccess($row)) {
            return $err;
        }
        if (!empty($row['is_builtin'])) {
            return $this->error('内置模板不可删除，可停用或复制后修改', 422);
        }
        // 非超管不可删他人模板
        $oid = DataScope::filterOwnerId();
        if ($oid !== null && (int)($row['owner_id'] ?? 0) !== $oid) {
            return $this->error('无权删除该模板', 403);
        }
        DB::query('DELETE FROM ai_prompt_templates WHERE id=?', [(int)$id]);
        return $this->success(null, '提示词模板已删除');
    }

    public function patchStatus(Request $request, string $id): Response
    {
        AiPromptCatalog::ensure();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM ai_prompt_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertPromptAccess($row)) {
            return $err;
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        DB::query('UPDATE ai_prompt_templates SET enabled=? WHERE id=?', [$enabled, (int)$id]);
        $fresh = DB::selectOne('SELECT * FROM ai_prompt_templates WHERE id=?', [(int)$id]);
        return $this->success($this->mapRow($fresh), $enabled ? '已启用' : '已停用');
    }

    /** 按变量渲染预览 */
    public function render(Request $request, string $id): Response
    {
        AiPromptCatalog::ensure();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM ai_prompt_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertPromptAccess($row)) {
            return $err;
        }
        $mapped = $this->mapRow($row);
        $values = $request->input('variables', $request->input('values', []));
        if (!is_array($values)) {
            $values = [];
        }
        $resolved = AiPromptHelper::resolveValues($mapped['variables'], $values);
        if (!$resolved['ok']) {
            return $this->error('缺少必填变量：' . implode('、', $resolved['missing']), 422);
        }
        $rendered = AiPromptHelper::render((string)$mapped['content'], $resolved['values']);
        return $this->success([
            'rendered' => $rendered,
            'variables' => $resolved['values'],
            'template' => [
                'id' => $mapped['id'],
                'name' => $mapped['name'],
                'code' => $mapped['code'],
            ],
        ]);
    }

    public function parseVariables(Request $request): Response
    {
        $content = (string)$request->input('content', '');
        $declared = $request->input('variables', []);
        $vars = AiPromptHelper::normalizeVariables(is_array($declared) ? $declared : [], $content);
        return $this->success([
            'keys' => AiPromptHelper::extractKeys($content),
            'variables' => $vars,
            'summary' => AiPromptHelper::variablesSummary($vars),
        ]);
    }

    /** 本人 / 内置 / 无归属系统模板可读可改 */
    private function assertPromptAccess(?array $row): ?Response
    {
        if (!$row) {
            return $this->error('资源不存在', 404);
        }
        $oid = DataScope::filterOwnerId();
        if ($oid === null) {
            return null;
        }
        if (!empty($row['is_builtin']) || ($row['owner_id'] ?? null) === null || (int)$row['owner_id'] === 0) {
            return null;
        }
        if ((int)$row['owner_id'] === $oid) {
            return null;
        }
        return $this->error('无权访问该资源', 403);
    }

    /** @return array{name:string,code:string,category:string,description:string,content:string,variables:list,enabled:int,sort_order:int} */
    private function parseBody(Request $request, ?array $existing = null): array
    {
        $all = $request->all();
        $name = trim((string)($all['name'] ?? ''));
        $content = (string)($all['content'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('模板名称不能为空');
        }
        if (trim($content) === '') {
            throw new \InvalidArgumentException('提示词内容不能为空');
        }
        $code = trim((string)($all['code'] ?? ($existing['code'] ?? '')));
        if ($code !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,62}$/', $code)) {
            throw new \InvalidArgumentException('模板代码需以字母开头，仅含字母数字下划线');
        }
        $category = trim((string)($all['category'] ?? 'general')) ?: 'general';
        $allowed = array_column(AiPromptCatalog::categories(), 'code');
        if (!in_array($category, $allowed, true)) {
            $category = 'general';
        }
        $variables = AiPromptHelper::normalizeVariables($all['variables'] ?? [], $content);
        return [
            'name' => $name,
            'code' => $code,
            'category' => $category,
            'description' => trim((string)($all['description'] ?? '')),
            'content' => $content,
            'variables' => $variables,
            'enabled' => filter_var($all['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'sort_order' => (int)($all['sort_order'] ?? ($existing['sort_order'] ?? 100)),
        ];
    }

    private function mapRow(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $variables = AiPromptHelper::normalizeVariables($row['variables_json'] ?? '[]', (string)($row['content'] ?? ''));
        $cat = (string)($row['category'] ?? 'general');
        $catLabel = $cat;
        foreach (AiPromptCatalog::categories() as $c) {
            if ($c['code'] === $cat) {
                $catLabel = $c['label'];
                break;
            }
        }
        return [
            'id' => (int)$row['id'],
            'code' => $row['code'] ?? '',
            'name' => $row['name'] ?? '',
            'category' => $cat,
            'categoryLabel' => $catLabel,
            'description' => $row['description'] ?? '',
            'content' => $row['content'] ?? '',
            'variables' => $variables,
            'variablesSummary' => AiPromptHelper::variablesSummary($variables),
            'enabled' => !empty($row['enabled']),
            'sortOrder' => (int)($row['sort_order'] ?? 0),
            'isBuiltin' => !empty($row['is_builtin']),
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }
}
