<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

class IndustryDataService
{
    public const MAX_LEVEL = 4;

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `industry_data` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `parent_id` INT NOT NULL DEFAULT 0,
              `level` TINYINT NOT NULL DEFAULT 1 COMMENT '1-4级',
              `name` VARCHAR(128) NOT NULL DEFAULT '',
              `code` VARCHAR(128) NOT NULL DEFAULT '',
              `description` VARCHAR(512) NOT NULL DEFAULT '',
              `sort_order` INT NOT NULL DEFAULT 0,
              `enabled` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_ind_parent` (`parent_id`),
              KEY `idx_ind_level` (`level`),
              KEY `idx_ind_code` (`code`),
              UNIQUE KEY `uk_ind_parent_code` (`parent_id`, `code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
        }
    }

    public static function seedIfEmpty(): int
    {
        self::ensureSchema();
        $count = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM industry_data')['c'] ?? 0);
        if ($count > 0) {
            return 0;
        }
        return self::importTree(IndustryDataSeed::tree());
    }

    /** @param list<array{name:string,code?:string,children?:list}> $tree */
    public static function importTree(array $tree, int $parentId = 0, int $level = 1): int
    {
        self::ensureSchema();
        $inserted = 0;
        $sort = 0;
        foreach ($tree as $node) {
            $sort += 10;
            $name = trim((string)($node['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $code = trim((string)($node['code'] ?? ''));
            if ($code === '') {
                $code = self::slug($name);
            }
            $code = self::uniqueCode($parentId, $code);
            $id = (int)DB::table('industry_data')->insert([
                'parent_id' => $parentId,
                'level' => min(self::MAX_LEVEL, max(1, $level)),
                'name' => mb_substr($name, 0, 128),
                'code' => mb_substr($code, 0, 128),
                'description' => '',
                'sort_order' => $sort,
                'enabled' => 1,
            ]);
            $inserted++;
            $children = $node['children'] ?? null;
            if (is_array($children) && $children && $level < self::MAX_LEVEL) {
                $inserted += self::importTree($children, $id, $level + 1);
            }
        }
        return $inserted;
    }

    public static function slug(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?: $s;
        return trim($s, '-') ?: ('n-' . substr(md5($name), 0, 8));
    }

    private static function uniqueCode(int $parentId, string $code): string
    {
        $base = $code;
        $i = 0;
        while (true) {
            $try = $i > 0 ? ($base . '-' . $i) : $base;
            $exist = DB::selectOne(
                'SELECT id FROM industry_data WHERE parent_id=? AND code=?',
                [$parentId, mb_substr($try, 0, 128)]
            );
            if (!$exist) {
                return mb_substr($try, 0, 128);
            }
            $i++;
        }
    }

    /** @param list<array<string,mixed>> $rows */
    public static function buildTree(array $rows, int $parentId = 0): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ((int)($row['parent_id'] ?? 0) !== $parentId) {
                continue;
            }
            $id = (int)$row['id'];
            $item = self::mapRow($row);
            $children = self::buildTree($rows, $id);
            if ($children) {
                $item['children'] = $children;
            }
            $out[] = $item;
        }
        usort($out, static fn($a, $b) => ($a['sortOrder'] <=> $b['sortOrder']) ?: ($a['id'] <=> $b['id']));
        return $out;
    }

    /** @param list<array<string,mixed>> $nodes @return list<array<string,mixed>> */
    public static function flattenTree(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $children = $node['children'] ?? null;
            $item = $node;
            unset($item['children']);
            $out[] = $item;
            if (is_array($children) && $children) {
                array_push($out, ...self::flattenTree($children));
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    public static function mapRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'parentId' => (int)($row['parent_id'] ?? 0),
            'level' => (int)($row['level'] ?? 1),
            'name' => (string)($row['name'] ?? ''),
            'code' => (string)($row['code'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'sortOrder' => (int)($row['sort_order'] ?? 0),
            'enabled' => !empty($row['enabled']),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public static function computeLevel(int $parentId): int
    {
        if ($parentId <= 0) {
            return 1;
        }
        $parent = DB::selectOne('SELECT level FROM industry_data WHERE id=?', [$parentId]);
        if (!$parent) {
            return 1;
        }
        return min(self::MAX_LEVEL, (int)$parent['level'] + 1);
    }

    public static function hasChildren(int $id): bool
    {
        return (int)(DB::selectOne('SELECT COUNT(*) AS c FROM industry_data WHERE parent_id=?', [$id])['c'] ?? 0) > 0;
    }

    /** @return list<int> */
    public static function descendantIds(int $id): array
    {
        $ids = [];
        $queue = [$id];
        while ($queue) {
            $cur = array_shift($queue);
            $children = DB::select('SELECT id FROM industry_data WHERE parent_id=?', [$cur]) ?: [];
            foreach ($children as $c) {
                $cid = (int)$c['id'];
                $ids[] = $cid;
                $queue[] = $cid;
            }
        }
        return $ids;
    }

    /** @return list<array{id:int,name:string,level:int}> */
    public static function breadcrumb(int $id): array
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

    public static function pathLabel(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $parts = array_map(static fn($p) => (string)($p['name'] ?? ''), self::breadcrumb($id));
        return implode(' / ', array_filter($parts));
    }

    /** @return array{id:int,label:string,path:list<array{id:int,name:string,level:int}>}|null */
    public static function resolveMeta(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = DB::selectOne('SELECT id, name, level FROM industry_data WHERE id=?', [$id]);
        if (!$row) {
            return null;
        }
        $path = self::breadcrumb($id);
        return [
            'id' => (int)$row['id'],
            'label' => self::pathLabel($id),
            'name' => (string)$row['name'],
            'level' => (int)$row['level'],
            'path' => $path,
        ];
    }
}
