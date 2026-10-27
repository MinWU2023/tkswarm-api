<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\IndustryDataService;
use App\Services\IndustryDataSeed;
use PDOException;

class IndustryDataController extends Controller
{
    public function list(Request $request): Response
    {
        IndustryDataService::ensureSchema();
        IndustryDataService::seedIfEmpty();

        $keyword = trim((string)$request->get('keyword', ''));
        $level = (int)$request->get('level', 0);
        $parentId = $request->get('parentId', $request->get('parent_id', null));
        $tree = filter_var($request->get('tree', '1'), FILTER_VALIDATE_BOOLEAN);
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, min(50, (int)$request->get('pageSize', 10)));
        $paginateL1Param = $request->get('paginateL1');
        $canPaginateL1 = $tree && $keyword === '' && $level < 1 && ($parentId === null || $parentId === '');
        if ($paginateL1Param === null || $paginateL1Param === '') {
            $paginateL1 = $canPaginateL1;
        } else {
            $paginateL1 = filter_var($paginateL1Param, FILTER_VALIDATE_BOOLEAN);
        }

        $filters = [];
        $params = [];
        if ($keyword !== '') {
            $filters[] = '(name LIKE ? OR code LIKE ? OR description LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }
        if ($level >= 1 && $level <= IndustryDataService::MAX_LEVEL) {
            $filters[] = 'level=?';
            $params[] = $level;
        }
        if ($parentId !== null && $parentId !== '') {
            $filters[] = 'parent_id=?';
            $params[] = (int)$parentId;
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $rows = DB::select(
            "SELECT * FROM industry_data {$where} ORDER BY level ASC, sort_order ASC, id ASC",
            $params
        ) ?: [];

        $mapped = array_map([IndustryDataService::class, 'mapRow'], $rows);
        $stats = $this->stats();

        $payload = [
            'flat' => $mapped,
            'total' => count($mapped),
            'stats' => $stats,
            'maxLevel' => IndustryDataService::MAX_LEVEL,
        ];
        if ($tree && $keyword === '' && $level < 1 && ($parentId === null || $parentId === '')) {
            $fullTree = IndustryDataService::buildTree($rows);
            if ($paginateL1 && $canPaginateL1) {
                $totalL1 = count($fullTree);
                $pages = max(1, (int)ceil($totalL1 / $pageSize));
                $page = min($page, $pages);
                $offset = ($page - 1) * $pageSize;
                $treePage = array_slice($fullTree, $offset, $pageSize);
                $payload['items'] = $treePage;
                $payload['flat'] = IndustryDataService::flattenTree($treePage);
                $payload['total'] = $totalL1;
                $payload['page'] = $page;
                $payload['pageSize'] = $pageSize;
                $payload['pages'] = $pages;
                $payload['paginateBy'] = 'l1';
            } else {
                $payload['items'] = $fullTree;
            }
        } else {
            $payload['items'] = $mapped;
        }
        return $this->success($payload);
    }

    public function parents(Request $request): Response
    {
        IndustryDataService::ensureSchema();
        $maxParentLevel = IndustryDataService::MAX_LEVEL - 1;
        $rows = DB::select(
            'SELECT * FROM industry_data WHERE level<=? AND enabled=1 ORDER BY level ASC, sort_order ASC, id ASC',
            [$maxParentLevel]
        ) ?: [];
        return $this->success(array_map([IndustryDataService::class, 'mapRow'], $rows));
    }

    public function show(Request $request, string $id): Response
    {
        IndustryDataService::ensureSchema();
        $row = DB::selectOne('SELECT * FROM industry_data WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('行业数据不存在', 404);
        }
        $mapped = IndustryDataService::mapRow($row);
        if ($mapped['parentId'] > 0) {
            $parent = DB::selectOne('SELECT id, name, level FROM industry_data WHERE id=?', [$mapped['parentId']]);
            $mapped['parentName'] = (string)($parent['name'] ?? '');
            $mapped['parentLevel'] = (int)($parent['level'] ?? 0);
        }
        $mapped['path'] = $this->breadcrumb((int)$id);
        return $this->success($mapped);
    }

    public function create(Request $request): Response
    {
        IndustryDataService::ensureSchema();
        try {
            $payload = $this->parseBody($request);
            $id = (int)DB::table('industry_data')->insert($payload);
            $row = DB::selectOne('SELECT * FROM industry_data WHERE id=?', [$id]);
            return $this->success(IndustryDataService::mapRow($row), '已添加行业数据');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->error('添加失败：' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, string $id): Response
    {
        IndustryDataService::ensureSchema();
        $old = DB::selectOne('SELECT * FROM industry_data WHERE id=?', [(int)$id]);
        if (!$old) {
            return $this->error('行业数据不存在', 404);
        }
        try {
            $payload = $this->parseBody($request, (int)$id);
            DB::query(
                'UPDATE industry_data SET parent_id=?, level=?, name=?, code=?, description=?, sort_order=?, enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [
                    $payload['parent_id'],
                    $payload['level'],
                    $payload['name'],
                    $payload['code'],
                    $payload['description'],
                    $payload['sort_order'],
                    $payload['enabled'],
                    (int)$id,
                ]
            );
            $this->syncDescendantLevels((int)$id, (int)$payload['level']);
            $row = DB::selectOne('SELECT * FROM industry_data WHERE id=?', [(int)$id]);
            return $this->success(IndustryDataService::mapRow($row), '已更新行业数据');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->error('更新失败：' . $e->getMessage(), 500);
        }
    }

    public function delete(Request $request, string $id): Response
    {
        IndustryDataService::ensureSchema();
        $row = DB::selectOne('SELECT * FROM industry_data WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('行业数据不存在', 404);
        }
        if (IndustryDataService::hasChildren((int)$id)) {
            return $this->error('请先删除下级分类', 422);
        }
        DB::query('DELETE FROM industry_data WHERE id=?', [(int)$id]);
        return $this->success(['id' => (int)$id], '已删除');
    }

    public function reseed(Request $request): Response
    {
        IndustryDataService::ensureSchema();
        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);
        $count = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM industry_data')['c'] ?? 0);
        if ($count > 0 && !$force) {
            return $this->error('已有行业数据，如需重新导入请传 force=true', 422);
        }
        if ($force) {
            DB::query('DELETE FROM industry_data');
        }
        $n = IndustryDataService::importTree(IndustryDataSeed::tree());
        return $this->success(['imported' => $n, 'stats' => $this->stats()], "已导入 {$n} 条外贸行业数据");
    }

    /** @return array<string,int> */
    private function stats(): array
    {
        $rows = DB::select(
            'SELECT level, COUNT(*) AS c FROM industry_data GROUP BY level ORDER BY level ASC'
        ) ?: [];
        $stats = ['total' => 0, 'l1' => 0, 'l2' => 0, 'l3' => 0, 'l4' => 0];
        foreach ($rows as $r) {
            $lv = (int)$r['level'];
            $c = (int)$r['c'];
            $stats['total'] += $c;
            if ($lv >= 1 && $lv <= 4) {
                $stats['l' . $lv] = $c;
            }
        }
        return $stats;
    }

    /** @return list<array{id:int,name:string,level:int}> */
    private function breadcrumb(int $id): array
    {
        $path = [];
        $cur = $id;
        $guard = 0;
        while ($cur > 0 && $guard < 10) {
            $guard++;
            $row = DB::selectOne('SELECT id, parent_id, name, level FROM industry_data WHERE id=?', [$cur]);
            if (!$row) {
                break;
            }
            array_unshift($path, [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'level' => (int)$row['level'],
            ]);
            $cur = (int)$row['parent_id'];
        }
        return $path;
    }

    private function parseBody(Request $request, int $selfId = 0): array
    {
        $parentId = (int)$request->input('parentId', $request->input('parent_id', 0));
        $name = trim((string)$request->input('name', ''));
        $code = trim((string)$request->input('code', ''));
        $description = trim((string)$request->input('description', ''));
        $sortOrder = (int)$request->input('sortOrder', $request->input('sort_order', 0));
        $enabled = $request->input('enabled', true);
        $enabled = $enabled === true || $enabled === 1 || $enabled === '1' || $enabled === 'true';

        if ($name === '') {
            throw new \InvalidArgumentException('请填写名称');
        }
        if ($parentId > 0) {
            $parent = DB::selectOne('SELECT id, level FROM industry_data WHERE id=?', [$parentId]);
            if (!$parent) {
                throw new \InvalidArgumentException('上级分类不存在');
            }
            if ((int)$parent['level'] >= IndustryDataService::MAX_LEVEL) {
                throw new \InvalidArgumentException('最多支持四级，无法在该上级下添加子级');
            }
            if ($selfId > 0) {
                $desc = IndustryDataService::descendantIds($selfId);
                if ($parentId === $selfId || in_array($parentId, $desc, true)) {
                    throw new \InvalidArgumentException('不能将上级设为自己或下级');
                }
            }
        } else {
            $parentId = 0;
        }

        $level = $parentId > 0
            ? min(IndustryDataService::MAX_LEVEL, (int)$parent['level'] + 1)
            : 1;

        if ($code === '') {
            $code = IndustryDataService::slug($name);
        }
        $exist = DB::selectOne(
            'SELECT id FROM industry_data WHERE parent_id=? AND code=? AND id<>?',
            [$parentId, mb_substr($code, 0, 128), $selfId]
        );
        if ($exist) {
            throw new \InvalidArgumentException('同级下编码已存在');
        }

        return [
            'parent_id' => $parentId,
            'level' => $level,
            'name' => mb_substr($name, 0, 128),
            'code' => mb_substr($code, 0, 128),
            'description' => mb_substr($description, 0, 512),
            'sort_order' => $sortOrder,
            'enabled' => $enabled ? 1 : 0,
        ];
    }

    private function syncDescendantLevels(int $id, int $level): void
    {
        $children = DB::select('SELECT id FROM industry_data WHERE parent_id=?', [$id]) ?: [];
        foreach ($children as $c) {
            $cid = (int)$c['id'];
            $childLevel = min(IndustryDataService::MAX_LEVEL, $level + 1);
            DB::query('UPDATE industry_data SET level=? WHERE id=?', [$childLevel, $cid]);
            if ($childLevel < IndustryDataService::MAX_LEVEL) {
                $this->syncDescendantLevels($cid, $childLevel);
            }
        }
    }
}
