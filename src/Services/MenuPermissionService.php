<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 后台菜单 ↔ 权限节点：每个 navigation_menus 条目对应 admin_permissions.code = menu.{id}
 */
class MenuPermissionService
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `navigation_menus` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `parent_id` INT NULL,
              `title` VARCHAR(100) NOT NULL,
              `icon` VARCHAR(50) NOT NULL DEFAULT '',
              `path` VARCHAR(255) NOT NULL DEFAULT '',
              `view` VARCHAR(100) NOT NULL DEFAULT '',
              `subview` VARCHAR(100) NOT NULL DEFAULT '',
              `sort_order` INT NOT NULL DEFAULT 0,
              `visible` TINYINT(1) NOT NULL DEFAULT 1,
              `permission_code` VARCHAR(128) NOT NULL DEFAULT '',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_parent` (`parent_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $cols = array_column(DB::select('SHOW COLUMNS FROM navigation_menus') ?: [], 'Field');
            if (!in_array('permission_code', $cols, true)) {
                DB::query("ALTER TABLE navigation_menus ADD COLUMN permission_code VARCHAR(128) NOT NULL DEFAULT '' AFTER visible");
            }
        } catch (\Throwable $e) {
        }
    }

    public static function codeForMenuId(int $menuId): string
    {
        return 'menu.' . max(0, $menuId);
    }

    public static function syncAll(): int
    {
        self::ensureSchema();
        $rows = DB::select('SELECT id, title, parent_id, view, subview, permission_code FROM navigation_menus ORDER BY id ASC') ?: [];
        $n = 0;
        foreach ($rows as $row) {
            self::syncOne($row);
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $menu */
    public static function syncOne(array $menu): string
    {
        self::ensureSchema();
        $id = (int)($menu['id'] ?? 0);
        if ($id <= 0) {
            return '';
        }
        // public / *：全角色可进，不覆盖为 menu.{id}
        $existing = strtolower(trim((string)($menu['permission_code'] ?? '')));
        if ($existing === 'public' || $existing === '*') {
            DB::query('UPDATE navigation_menus SET permission_code=? WHERE id=?', [$existing, $id]);
            return $existing;
        }
        $code = self::codeForMenuId($id);
        $title = trim((string)($menu['title'] ?? ''));
        $view = trim((string)($menu['view'] ?? ''));
        $subview = trim((string)($menu['subview'] ?? ''));
        $pathHint = $view !== '' ? ($view . ($subview !== '' ? '/' . $subview : '')) : '';
        $label = '菜单：' . ($title !== '' ? $title : ('#' . $id));
        if ($pathHint !== '') {
            $label .= ' (' . $pathHint . ')';
        }
        $sort = 5000 + $id;
        DB::query(
            "INSERT INTO admin_permissions (module, code, name, sort_order)
             VALUES ('menu', ?, ?, ?)
             ON DUPLICATE KEY UPDATE module='menu', name=VALUES(name), sort_order=VALUES(sort_order)",
            [$code, mb_substr($label, 0, 100), $sort]
        );
        DB::query('UPDATE navigation_menus SET permission_code=? WHERE id=?', [$code, $id]);
        return $code;
    }

    /** @return list<string> */
    public static function permissionCodesForUser(?array $user): array
    {
        if (!$user) {
            return ['*'];
        }
        $roleId = (int)($user['role_id'] ?? 0);
        if ($roleId === 1 || $roleId === 0) {
            return ['*'];
        }
        $role = DB::selectOne('SELECT permissions, status FROM admin_roles WHERE id=?', [$roleId]);
        if (!$role || (int)($role['status'] ?? 0) !== 1) {
            return [];
        }
        $ids = json_decode((string)($role['permissions'] ?? '[]'), true);
        if (!is_array($ids)) {
            $ids = [];
        }
        if (in_array('*', $ids, true)) {
            return ['*'];
        }
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "SELECT code FROM admin_permissions WHERE id IN ({$ph})",
            array_map('intval', $ids)
        ) ?: [];
        return array_values(array_unique(array_map(static fn($r) => (string)$r['code'], $rows)));
    }

    /** @param list<string> $permCodes */
    public static function usesMenuPermissionMode(array $permCodes): bool
    {
        if (in_array('*', $permCodes, true)) {
            return true;
        }
        foreach ($permCodes as $c) {
            if (str_starts_with((string)$c, 'menu.')) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $permCodes */
    public static function canSeeMenu(string $permissionCode, array $permCodes, bool $strictMenuMode): bool
    {
        if (in_array('*', $permCodes, true)) {
            return true;
        }
        $code = trim($permissionCode);
        if ($code === '' || $code === '*' || strcasecmp($code, 'public') === 0) {
            return true;
        }
        if (!$strictMenuMode) {
            return true;
        }
        return in_array($code, $permCodes, true);
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param list<string> $permCodes
     * @return list<array<string,mixed>>
     */
    public static function filterTree(array $nodes, array $permCodes): array
    {
        if (in_array('*', $permCodes, true)) {
            return $nodes;
        }
        if (!$permCodes) {
            return [];
        }
        $strict = self::usesMenuPermissionMode($permCodes);
        $out = [];
        foreach ($nodes as $node) {
            $children = self::filterTree($node['children'] ?? [], $permCodes);
            $code = (string)($node['permissionCode'] ?? $node['permission_code'] ?? '');
            $selfOk = self::canSeeMenu($code, $permCodes, $strict);
            if ($selfOk || $children) {
                $node['children'] = $children;
                $out[] = $node;
            }
        }
        return $out;
    }

    public static function removeForMenu(int $menuId): void
    {
        if ($menuId <= 0) {
            return;
        }
        $code = self::codeForMenuId($menuId);
        try {
            DB::query('DELETE FROM admin_permissions WHERE code=?', [$code]);
        } catch (\Throwable $e) {
        }
    }

    /** @return array{code:string,title:string,menuId:int}|null */
    public static function resolveRoute(string $view, string $subview = ''): ?array
    {
        self::ensureSchema();
        $view = trim($view);
        $subview = trim($subview);
        $row = DB::selectOne(
            'SELECT id, title, permission_code FROM navigation_menus
             WHERE visible=1 AND view=? AND subview=?
             ORDER BY (CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END), sort_order ASC, id ASC
             LIMIT 1',
            [$view, $subview]
        );
        if (!$row) {
            $row = DB::selectOne(
                'SELECT id, title, permission_code FROM navigation_menus
                 WHERE visible=1 AND view=? AND (subview=\'\' OR subview IS NULL)
                 ORDER BY sort_order ASC, id ASC LIMIT 1',
                [$view]
            );
        }
        if (!$row) {
            return null;
        }
        $code = trim((string)($row['permission_code'] ?? ''));
        if ($code === '') {
            $code = self::codeForMenuId((int)$row['id']);
        }
        return [
            'code' => $code,
            'title' => (string)($row['title'] ?? ''),
            'menuId' => (int)$row['id'],
        ];
    }
}
