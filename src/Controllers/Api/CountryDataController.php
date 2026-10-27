<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\CountryDataService;
use App\Services\CountryDataSeed;
use PDOException;

class CountryDataController extends Controller
{
    public function options(Request $request): Response
    {
        CountryDataService::ensureSchema();
        CountryDataService::seedIfEmpty();
        $rows = DB::select(
            'SELECT id, code, name_zh, name_en, continent, sort_order FROM country_data WHERE enabled=1 ORDER BY sort_order ASC, name_zh ASC, id ASC'
        ) ?: [];
        return $this->success([
            'items' => array_map(static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'code' => strtoupper((string)($row['code'] ?? '')),
                    'nameZh' => (string)($row['name_zh'] ?? ''),
                    'nameEn' => (string)($row['name_en'] ?? ''),
                    'continent' => (string)($row['continent'] ?? ''),
                    'sortOrder' => (int)($row['sort_order'] ?? 0),
                ];
            }, $rows),
            'total' => count($rows),
        ]);
    }

    public function list(Request $request): Response
    {
        CountryDataService::ensureSchema();
        CountryDataService::seedIfEmpty();

        $keyword = trim((string)$request->get('keyword', ''));
        $enabled = $request->get('enabled', null);
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, min(100, (int)$request->get('pageSize', 20)));

        $filters = [];
        $params = [];
        if ($keyword !== '') {
            $filters[] = '(code LIKE ? OR name_zh LIKE ? OR name_en LIKE ? OR continent LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($enabled !== null && $enabled !== '') {
            $filters[] = 'enabled=?';
            $params[] = filter_var($enabled, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM country_data {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;

        $rows = DB::select(
            "SELECT * FROM country_data {$where} ORDER BY sort_order ASC, name_zh ASC, id ASC LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        $enabledCount = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM country_data WHERE enabled=1')['c'] ?? 0);

        return $this->success([
            'items' => array_map([CountryDataService::class, 'mapRow'], $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => $pages,
            'stats' => [
                'total' => (int)(DB::selectOne('SELECT COUNT(*) AS c FROM country_data')['c'] ?? 0),
                'enabled' => $enabledCount,
            ],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        CountryDataService::ensureSchema();
        $row = DB::selectOne('SELECT * FROM country_data WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('国家数据不存在', 404);
        }
        return $this->success(CountryDataService::mapRow($row));
    }

    public function create(Request $request): Response
    {
        CountryDataService::ensureSchema();
        try {
            $payload = $this->parseBody($request);
            $id = (int)DB::table('country_data')->insert($payload);
            $row = DB::selectOne('SELECT * FROM country_data WHERE id=?', [$id]);
            return $this->success(CountryDataService::mapRow($row), '已添加国家');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->error('添加失败：' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, string $id): Response
    {
        CountryDataService::ensureSchema();
        $old = DB::selectOne('SELECT * FROM country_data WHERE id=?', [(int)$id]);
        if (!$old) {
            return $this->error('国家数据不存在', 404);
        }
        try {
            $payload = $this->parseBody($request, (int)$id);
            DB::query(
                'UPDATE country_data SET code=?, name_zh=?, name_en=?, continent=?, sort_order=?, enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [
                    $payload['code'],
                    $payload['name_zh'],
                    $payload['name_en'],
                    $payload['continent'],
                    $payload['sort_order'],
                    $payload['enabled'],
                    (int)$id,
                ]
            );
            $row = DB::selectOne('SELECT * FROM country_data WHERE id=?', [(int)$id]);
            return $this->success(CountryDataService::mapRow($row), '已更新国家');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->error('更新失败：' . $e->getMessage(), 500);
        }
    }

    public function delete(Request $request, string $id): Response
    {
        CountryDataService::ensureSchema();
        $row = DB::selectOne('SELECT * FROM country_data WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('国家数据不存在', 404);
        }
        DB::query('DELETE FROM country_data WHERE id=?', [(int)$id]);
        return $this->success(['id' => (int)$id], '已删除');
    }

    public function reseed(Request $request): Response
    {
        CountryDataService::ensureSchema();
        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);
        $count = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM country_data')['c'] ?? 0);
        if ($count > 0 && !$force) {
            return $this->error('已有国家数据，如需重新导入请传 force=true', 422);
        }
        if ($force) {
            DB::query('DELETE FROM country_data');
        }
        $n = CountryDataService::importRows(CountryDataSeed::rows());
        $total = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM country_data')['c'] ?? 0);
        return $this->success(['imported' => $n, 'total' => $total], "已导入 {$n} 条国家数据");
    }

    private function parseBody(Request $request, int $selfId = 0): array
    {
        $code = CountryDataService::normalizeCode((string)$request->input('code', ''));
        $nameZh = trim((string)$request->input('nameZh', $request->input('name_zh', '')));
        $nameEn = trim((string)$request->input('nameEn', $request->input('name_en', '')));
        $continent = trim((string)$request->input('continent', ''));
        $sortOrder = (int)$request->input('sortOrder', $request->input('sort_order', 0));
        $enabled = $request->input('enabled', true);
        $enabled = $enabled === true || $enabled === 1 || $enabled === '1' || $enabled === 'true';

        if ($code === '' || strlen($code) !== 2 || !preg_match('/^[A-Z]{2}$/', $code)) {
            throw new \InvalidArgumentException('请填写有效的两位国家代码（ISO alpha-2）');
        }
        if ($nameZh === '' && $nameEn === '') {
            throw new \InvalidArgumentException('请填写中文名或英文名');
        }

        $exist = DB::selectOne('SELECT id FROM country_data WHERE code=? AND id<>?', [$code, $selfId]);
        if ($exist) {
            throw new \InvalidArgumentException('国家代码已存在');
        }

        return [
            'code' => $code,
            'name_zh' => mb_substr($nameZh !== '' ? $nameZh : $nameEn, 0, 128),
            'name_en' => mb_substr($nameEn !== '' ? $nameEn : $nameZh, 0, 128),
            'continent' => mb_substr($continent, 0, 32),
            'sort_order' => $sortOrder,
            'enabled' => $enabled ? 1 : 0,
        ];
    }
}
