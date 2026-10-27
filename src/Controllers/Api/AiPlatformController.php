<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiHelper;
use App\Services\Ai\AiPlatformCatalog;
use App\Services\DataScope;

/**
 * AI 开放平台：平台卡片 + 开发配置 1:N
 */
class AiPlatformController extends Controller
{
    public function platforms(Request $request): Response
    {
        AiPlatformCatalog::ensure();
        $region = trim((string)$request->get('region', ''));
        $keyword = trim((string)$request->get('keyword', ''));
        $sql = 'SELECT p.*,
            (SELECT COUNT(*) FROM ai_platform_configs c WHERE c.platform_id=p.id) AS config_count,
            (SELECT COUNT(*) FROM ai_platform_configs c WHERE c.platform_id=p.id AND c.is_active=1) AS active_count
            FROM ai_platforms p WHERE 1=1';
        $params = [];
        if ($region !== '') {
            $sql .= ' AND p.region=?';
            $params[] = $region;
        }
        if ($keyword !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.code LIKE ? OR p.description LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';
        $rows = DB::select($sql, $params) ?: [];
        $active = $this->findGlobalActive();
        return $this->success([
            'items' => array_map([$this, 'mapPlatform'], $rows),
            'active' => $active,
            'regions' => [
                ['code' => 'cn', 'label' => '国内'],
                ['code' => 'intl', 'label' => '国际'],
            ],
        ]);
    }

    public function platformDetail(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        $row = DB::selectOne('SELECT * FROM ai_platforms WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('平台不存在', 404);
        }
        return $this->success($this->mapPlatform($row, true));
    }

    public function updatePlatform(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        $row = DB::selectOne('SELECT * FROM ai_platforms WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('平台不存在', 404);
        }
        $enabled = array_key_exists('enabled', $request->all())
            ? (filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
            : (int)$row['enabled'];
        $sort = array_key_exists('sort_order', $request->all())
            ? (int)$request->input('sort_order')
            : (int)$row['sort_order'];
        DB::query('UPDATE ai_platforms SET enabled=?, sort_order=? WHERE id=?', [$enabled, $sort, (int)$id]);
        $fresh = DB::selectOne('SELECT * FROM ai_platforms WHERE id=?', [(int)$id]);
        return $this->success($this->mapPlatform($fresh ?: $row, true), '平台已更新');
    }

    public function models(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        $platform = DB::selectOne('SELECT * FROM ai_platforms WHERE id=?', [(int)$id]);
        if (!$platform) {
            return $this->error('平台不存在', 404);
        }
        $enabledOnly = filter_var($request->get('enabled', ''), FILTER_VALIDATE_BOOLEAN);
        $sql = 'SELECT * FROM ai_platform_models WHERE platform_id=?';
        $params = [(int)$id];
        if ($enabledOnly) {
            $sql .= ' AND enabled=1';
        }
        $sql .= ' ORDER BY is_default DESC, sort_order ASC, id ASC';
        $rows = DB::select($sql, $params) ?: [];
        return $this->success([
            'platform' => $this->mapPlatform($platform),
            'items' => array_map([$this, 'mapModel'], $rows),
        ]);
    }

    public function createModel(Request $request): Response
    {
        AiPlatformCatalog::ensure();
        $platformId = (int)$request->input('platformId', $request->input('platform_id', 0));
        $platform = DB::selectOne('SELECT * FROM ai_platforms WHERE id=?', [$platformId]);
        if (!$platform) {
            return $this->error('平台不存在', 404);
        }
        try {
            $payload = $this->parseModelBody($request, $platformId);
            if (!empty($payload['is_default'])) {
                DB::query('UPDATE ai_platform_models SET is_default=0 WHERE platform_id=?', [$platformId]);
            }
            DB::query(
                'INSERT INTO ai_platform_models(platform_id, code, name, enabled, is_default, sort_order) VALUES (?,?,?,?,?,?)',
                [
                    $payload['platform_id'],
                    $payload['code'],
                    $payload['name'],
                    $payload['enabled'],
                    $payload['is_default'],
                    $payload['sort_order'],
                ]
            );
            $id = (int)DB::getPdo()->lastInsertId();
            $row = DB::selectOne('SELECT * FROM ai_platform_models WHERE id=?', [$id]);
            return $this->success($this->mapModel($row), '模型已添加');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('添加失败：模型编码可能已存在', 409);
        }
    }

    public function updateModel(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        $old = DB::selectOne('SELECT * FROM ai_platform_models WHERE id=?', [(int)$id]);
        if (!$old) {
            return $this->error('模型不存在', 404);
        }
        try {
            $payload = $this->parseModelBody($request, (int)$old['platform_id'], $old);
            if (!empty($payload['is_default'])) {
                DB::query(
                    'UPDATE ai_platform_models SET is_default=0 WHERE platform_id=? AND id<>?',
                    [(int)$old['platform_id'], (int)$id]
                );
            }
            DB::query(
                'UPDATE ai_platform_models SET code=?, name=?, enabled=?, is_default=?, sort_order=? WHERE id=?',
                [
                    $payload['code'],
                    $payload['name'],
                    $payload['enabled'],
                    $payload['is_default'],
                    $payload['sort_order'],
                    (int)$id,
                ]
            );
            $row = DB::selectOne('SELECT * FROM ai_platform_models WHERE id=?', [(int)$id]);
            return $this->success($this->mapModel($row), '模型已更新');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('更新失败：模型编码可能已存在', 409);
        }
    }

    public function patchModelStatus(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        $old = DB::selectOne('SELECT * FROM ai_platform_models WHERE id=?', [(int)$id]);
        if (!$old) {
            return $this->error('模型不存在', 404);
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        DB::query('UPDATE ai_platform_models SET enabled=? WHERE id=?', [$enabled, (int)$id]);
        $row = DB::selectOne('SELECT * FROM ai_platform_models WHERE id=?', [(int)$id]);
        return $this->success($this->mapModel($row), $enabled ? '已启用' : '已停用');
    }

    public function deleteModel(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        $old = DB::selectOne('SELECT * FROM ai_platform_models WHERE id=?', [(int)$id]);
        if (!$old) {
            return $this->error('模型不存在', 404);
        }
        DB::query('DELETE FROM ai_platform_models WHERE id=?', [(int)$id]);
        return $this->success(null, '模型已删除');
    }

    public function configs(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        DataScope::ensureColumns();
        $platform = DB::selectOne('SELECT * FROM ai_platforms WHERE id=?', [(int)$id]);
        if (!$platform) {
            return $this->error('平台不存在', 404);
        }
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $rows = DB::select(
            'SELECT * FROM ai_platform_configs WHERE platform_id=?' . $ownSql . ' ORDER BY is_active DESC, id DESC',
            array_merge([(int)$id], $ownParams)
        ) ?: [];
        $schema = $this->parseSchema($platform);
        return $this->success([
            'platform' => $this->mapPlatform($platform, true),
            'items' => array_map(fn ($r) => $this->mapConfig($r, $schema), $rows),
        ]);
    }

    public function createConfig(Request $request): Response
    {
        AiPlatformCatalog::ensure();
        DataScope::ensureColumns();
        try {
            $body = $request->all();
            $platformId = (int)($body['platform_id'] ?? $body['platformId'] ?? 0);
            $platform = DB::selectOne('SELECT * FROM ai_platforms WHERE id=?', [$platformId]);
            if (!$platform) {
                return $this->error('平台不存在', 404);
            }
            $name = trim((string)($body['name'] ?? ''));
            if ($name === '') {
                return $this->error('配置名称不能为空', 400);
            }
            $schema = $this->parseSchema($platform);
            $inputCfg = is_array($body['config'] ?? null) ? $body['config'] : $body;
            $applied = AiHelper::applySchema($schema, $inputCfg, null);
            if (empty($applied['config']['base_url']) && !empty($platform['default_base_url'])) {
                $applied['config']['base_url'] = $platform['default_base_url'];
            }
            $stored = AiHelper::encryptSecrets($applied['config'], $applied['secret_keys']);
            $enabled = filter_var($body['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $activate = filter_var($body['is_active'] ?? $body['isActive'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $remark = trim((string)($body['remark'] ?? ''));
            $row = [
                'platform_id' => $platformId,
                'name' => $name,
                'config_json' => json_encode($stored, JSON_UNESCAPED_UNICODE),
                'enabled' => $enabled,
                'is_active' => 0,
                'remark' => $remark,
            ];
            $this->stampOwner($row);
            $id = (int)DB::table('ai_platform_configs')->insert($row);
            if ($activate) {
                $this->activateConfigId($id);
            }
            $fresh = DB::selectOne('SELECT * FROM ai_platform_configs WHERE id=?', [$id]);
            return $this->success($this->mapConfig($fresh, $schema), '配置已创建');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('创建失败：' . $e->getMessage(), 500);
        }
    }

    public function updateConfig(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        DataScope::ensureColumns();
        $cfg = DB::selectOne('SELECT * FROM ai_platform_configs WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($cfg)) {
            return $err;
        }
        $platform = DB::selectOne('SELECT * FROM ai_platforms WHERE id=?', [(int)$cfg['platform_id']]);
        if (!$platform) {
            return $this->error('平台不存在', 404);
        }
        try {
            $body = $request->all();
            $schema = $this->parseSchema($platform);
            $existing = json_decode((string)($cfg['config_json'] ?? '{}'), true);
            if (!is_array($existing)) {
                $existing = [];
            }
            $secretKeys = AiHelper::secretKeysFromSchema($schema);
            $existingPlain = AiHelper::decryptSecrets($existing, $secretKeys);
            $inputCfg = is_array($body['config'] ?? null) ? $body['config'] : $body;
            $applied = AiHelper::applySchema($schema, $inputCfg, $existingPlain);
            $stored = AiHelper::encryptSecrets($applied['config'], $applied['secret_keys']);
            $name = array_key_exists('name', $body) ? trim((string)$body['name']) : (string)$cfg['name'];
            if ($name === '') {
                return $this->error('配置名称不能为空', 400);
            }
            $enabled = array_key_exists('enabled', $body)
                ? (filter_var($body['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
                : (int)$cfg['enabled'];
            $remark = array_key_exists('remark', $body) ? trim((string)$body['remark']) : (string)$cfg['remark'];
            DB::query(
                'UPDATE ai_platform_configs SET name=?, config_json=?, enabled=?, remark=? WHERE id=?',
                [$name, json_encode($stored, JSON_UNESCAPED_UNICODE), $enabled, $remark, (int)$id]
            );
            if (array_key_exists('is_active', $body) || array_key_exists('isActive', $body)) {
                $want = filter_var($body['is_active'] ?? $body['isActive'], FILTER_VALIDATE_BOOLEAN);
                if ($want) {
                    $this->activateConfigId((int)$id);
                } elseif (!empty($cfg['is_active'])) {
                    DB::query('UPDATE ai_platform_configs SET is_active=0 WHERE id=?', [(int)$id]);
                }
            }
            $fresh = DB::selectOne('SELECT * FROM ai_platform_configs WHERE id=?', [(int)$id]);
            return $this->success($this->mapConfig($fresh, $schema), '配置已更新');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('更新失败：' . $e->getMessage(), 500);
        }
    }

    public function deleteConfig(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        DataScope::ensureColumns();
        $cfg = DB::selectOne('SELECT * FROM ai_platform_configs WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($cfg)) {
            return $err;
        }
        DB::query('DELETE FROM ai_platform_configs WHERE id=?', [(int)$id]);
        return $this->success(null, '配置已删除');
    }

    public function activateConfig(Request $request, string $id): Response
    {
        AiPlatformCatalog::ensure();
        DataScope::ensureColumns();
        $cfg = DB::selectOne('SELECT * FROM ai_platform_configs WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($cfg)) {
            return $err;
        }
        if (empty($cfg['enabled'])) {
            return $this->error('请先启用该配置再设为全局启用', 400);
        }
        $platform = DB::selectOne('SELECT * FROM ai_platforms WHERE id=?', [(int)$cfg['platform_id']]);
        if (!$platform || empty($platform['enabled'])) {
            return $this->error('请先启用所属平台', 400);
        }
        $this->activateConfigId((int)$id);
        $schema = $this->parseSchema($platform);
        $fresh = DB::selectOne('SELECT * FROM ai_platform_configs WHERE id=?', [(int)$id]);
        return $this->success($this->mapConfig($fresh, $schema), '已设为全局启用配置');
    }

    public function active(Request $request): Response
    {
        AiPlatformCatalog::ensure();
        $active = $this->findGlobalActive();
        return $this->success(['active' => $active]);
    }

    public function test(Request $request): Response
    {
        AiPlatformCatalog::ensure();
        DataScope::ensureColumns();
        $configId = (int)$request->input('config_id', $request->input('configId', 0));
        $prompt = trim((string)$request->input('prompt', $request->input('content', '你好，请用一句话介绍你自己。')));
        if ($configId <= 0) {
            return $this->error('请指定 config_id', 400);
        }
        $cfg = DB::selectOne('SELECT * FROM ai_platform_configs WHERE id=?', [$configId]);
        if ($err = $this->assertOwns($cfg)) {
            return $err;
        }
        try {
            $result = AiClient::chat(
                [['role' => 'user', 'content' => $prompt]],
                ['config_id' => $configId, 'timeout' => 40, 'operation' => 'platform_test']
            );
            return $this->success([
                'reply' => $result['content'],
                'model' => $result['model'] ?? null,
                'platform' => $result['platform'] ?? null,
                'usage' => $result['usage'] ?? null,
            ], '连通测试成功');
        } catch (\Throwable $e) {
            return $this->error('连通测试失败：' . $e->getMessage(), 422);
        }
    }

    private function activateConfigId(int $id): void
    {
        DB::query('UPDATE ai_platform_configs SET is_active=0 WHERE is_active=1');
        DB::query('UPDATE ai_platform_configs SET is_active=1, enabled=1 WHERE id=?', [$id]);
        $row = DB::selectOne('SELECT platform_id FROM ai_platform_configs WHERE id=?', [$id]);
        if ($row) {
            DB::query('UPDATE ai_platforms SET enabled=1 WHERE id=?', [(int)$row['platform_id']]);
        }
    }

    private function findGlobalActive(): ?array
    {
        $row = DB::selectOne(
            'SELECT c.*, p.code AS platform_code, p.name AS platform_name, p.region, p.driver, p.icon, p.field_schema_json
             FROM ai_platform_configs c
             INNER JOIN ai_platforms p ON p.id=c.platform_id
             WHERE c.is_active=1
             ORDER BY c.id DESC LIMIT 1'
        );
        if (!$row) {
            return null;
        }
        $schema = $this->parseSchema(['field_schema_json' => $row['field_schema_json']]);
        $mapped = $this->mapConfig($row, $schema);
        $mapped['platformCode'] = $row['platform_code'];
        $mapped['platformName'] = $row['platform_name'];
        $mapped['platformRegion'] = $row['region'];
        $mapped['platformDriver'] = $row['driver'];
        $mapped['platformIcon'] = $row['icon'];
        return $mapped;
    }

    /** @return list<array<string,mixed>> */
    private function parseSchema(array $platform): array
    {
        $schema = json_decode((string)($platform['field_schema_json'] ?? '[]'), true);
        return is_array($schema) ? $schema : [];
    }

    /** @param array<string,mixed>|null $old */
    private function parseModelBody(Request $request, int $platformId, ?array $old = null): array
    {
        $code = trim((string)$request->input('code', $old['code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('请填写模型编码');
        }
        $name = trim((string)$request->input('name', $old['name'] ?? ''));
        if ($name === '') {
            $name = $code;
        }
        return [
            'platform_id' => $platformId,
            'code' => $code,
            'name' => $name,
            'enabled' => filter_var($request->input('enabled', $old['enabled'] ?? true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'is_default' => filter_var($request->input('isDefault', $request->input('is_default', $old['is_default'] ?? false)), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'sort_order' => max(0, (int)$request->input('sortOrder', $request->input('sort_order', $old['sort_order'] ?? 0))),
        ];
    }

    private function mapModel(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'platformId' => (int)$row['platform_id'],
            'code' => (string)$row['code'],
            'name' => (string)($row['name'] ?? ''),
            'enabled' => !empty($row['enabled']),
            'isDefault' => !empty($row['is_default']),
            'sortOrder' => (int)($row['sort_order'] ?? 0),
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    private function mapPlatform(?array $row, bool $withSchema = false): ?array
    {
        if (!$row) {
            return null;
        }
        $item = [
            'id' => (int)$row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'region' => $row['region'],
            'regionLabel' => AiHelper::regionLabel((string)$row['region']),
            'driver' => $row['driver'],
            'icon' => $row['icon'] ?? 'bot',
            'description' => $row['description'] ?? '',
            'defaultBaseUrl' => $row['default_base_url'] ?? '',
            'enabled' => !empty($row['enabled']),
            'sortOrder' => (int)($row['sort_order'] ?? 0),
            'configCount' => isset($row['config_count']) ? (int)$row['config_count'] : null,
            'activeCount' => isset($row['active_count']) ? (int)$row['active_count'] : null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
        if ($withSchema) {
            $item['fields'] = $this->parseSchema($row);
        }
        return $item;
    }

    private function mapConfig(?array $row, array $schema): ?array
    {
        if (!$row) {
            return null;
        }
        $config = json_decode((string)($row['config_json'] ?? '{}'), true);
        if (!is_array($config)) {
            $config = [];
        }
        $secretKeys = AiHelper::secretKeysFromSchema($schema);
        $hasSecrets = [];
        foreach ($secretKeys as $k) {
            $hasSecrets[$k] = !empty($config[$k]);
        }
        $masked = AiHelper::maskSecrets($config, $secretKeys);
        return [
            'id' => (int)$row['id'],
            'platformId' => (int)$row['platform_id'],
            'name' => $row['name'],
            'config' => $masked,
            'hasSecrets' => $hasSecrets,
            'enabled' => !empty($row['enabled']),
            'isActive' => !empty($row['is_active']),
            'remark' => $row['remark'] ?? '',
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }
}
