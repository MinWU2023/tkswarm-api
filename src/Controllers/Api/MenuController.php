<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\MenuPermissionService;

class MenuController extends Controller
{
    public function tree(Request $request): Response
    {
        \App\Services\SeedMeta::ensure();
        MenuPermissionService::syncAll();
        $rows = DB::table('navigation_menus')
            ->where('visible', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        $map = [];
        $roots = [];

        foreach ($rows as $item) {
            // 扫码登录功能已移除，即使库中仍有记录也不进顶栏
            if ((string)($item['subview'] ?? '') === 'qr' || (string)($item['title'] ?? '') === '扫码登录') {
                continue;
            }
            $permCode = trim((string)($item['permission_code'] ?? ''));
            if ($permCode === '') {
                $permCode = MenuPermissionService::codeForMenuId((int)$item['id']);
            }
            // public / * 保持原样，供全角色可见
            $map[$item['id']] = [
                'id' => (int)$item['id'],
                'parentId' => $item['parent_id'] ? (int)$item['parent_id'] : null,
                'title' => $item['title'],
                'icon' => $item['icon'],
                'path' => $item['path'],
                'view' => $item['view'],
                'subview' => $item['subview'],
                'sortOrder' => (int)$item['sort_order'],
                'visible' => (bool)$item['visible'],
                'permissionCode' => $permCode,
                'children' => []
            ];
        }

        foreach ($rows as $item) {
            $id = (int)$item['id'];
            $parentId = $item['parent_id'] ? (int)$item['parent_id'] : null;
            if ($parentId && isset($map[$parentId])) {
                $map[$parentId]['children'][] = &$map[$id];
            } else {
                $roots[] = &$map[$id];
            }
        }

        $user = $GLOBALS['auth_user'] ?? null;
        $permCodes = MenuPermissionService::permissionCodesForUser(is_array($user) ? $user : null);
        $roots = MenuPermissionService::filterTree($roots, $permCodes);

        return $this->success($roots);
    }

    public function list(Request $request): Response
    {
        $rows = DB::table('navigation_menus')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
        return $this->success($rows);
    }

    // 保存菜单（新增 / 编辑）
    public function save(Request $request): Response
    {
        $id = $request->input('id');
        $parentId = $request->input('parent_id');
        $parentId = ($parentId !== '' && $parentId !== null) ? (int)$parentId : null;
        $title = trim((string)$request->input('title', ''));
        $icon = trim((string)$request->input('icon', ''));
        $path = trim((string)$request->input('path', ''));
        $view = trim((string)$request->input('view', ''));
        $subview = trim((string)$request->input('subview', ''));
        $sortOrder = (int)$request->input('sort_order', 0);
        $visible = (int)$request->input('visible', 1);

        if (empty($title)) {
            return $this->error('菜单标题不能为空');
        }

        if ($id) {
            DB::table('navigation_menus')->where('id', (int)$id)->update([
                'parent_id' => $parentId,
                'title' => $title,
                'icon' => $icon,
                'path' => $path,
                'view' => $view,
                'subview' => $subview,
                'sort_order' => $sortOrder,
                'visible' => $visible,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            MenuPermissionService::syncOne([
                'id' => (int)$id,
                'title' => $title,
                'view' => $view,
                'subview' => $subview,
            ]);
            return $this->success(null, '菜单项已更新');
        } else {
            $newId = (int)DB::table('navigation_menus')->insert([
                'parent_id' => $parentId,
                'title' => $title,
                'icon' => $icon,
                'path' => $path,
                'view' => $view,
                'subview' => $subview,
                'sort_order' => $sortOrder,
                'visible' => $visible,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            MenuPermissionService::syncOne([
                'id' => $newId,
                'title' => $title,
                'view' => $view,
                'subview' => $subview,
            ]);
            return $this->success(['id' => $newId], '菜单项已新增');
        }
    }

    // 删除菜单
    public function delete(Request $request): Response
    {
        $id = (int)$request->input('id');
        if ($id <= 0) {
            return $this->error('无效的菜单 ID');
        }
        $child = DB::table('navigation_menus')->where('parent_id', $id)->first();
        if ($child) {
            return $this->error('该菜单存在子菜单，请先删除子菜单');
        }
        MenuPermissionService::removeForMenu($id);
        DB::table('navigation_menus')->where('id', $id)->delete();
        return $this->success(null, '菜单已删除');
    }

    public function updateById(Request $request, string $id): Response
    {
        $requestParams = $request->all();
        $requestParams['id'] = (int)$id;
        // reuse save via temporary merge
        $_merged = $requestParams;
        $parentId = $_merged['parent_id'] ?? $_merged['parentId'] ?? null;
        $parentId = ($parentId !== '' && $parentId !== null) ? (int)$parentId : null;
        $title = trim((string)($_merged['title'] ?? ''));
        if ($title === '') {
            return $this->error('菜单标题不能为空');
        }
        DB::table('navigation_menus')->where('id', (int)$id)->update([
            'parent_id' => $parentId,
            'title' => $title,
            'icon' => trim((string)($_merged['icon'] ?? '')),
            'path' => trim((string)($_merged['path'] ?? '')),
            'view' => trim((string)($_merged['view'] ?? '')),
            'subview' => trim((string)($_merged['subview'] ?? '')),
            'sort_order' => (int)($_merged['sort_order'] ?? $_merged['sortOrder'] ?? 0),
            'visible' => (int)($_merged['visible'] ?? 1),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        MenuPermissionService::syncOne([
            'id' => (int)$id,
            'title' => $title,
            'view' => trim((string)($_merged['view'] ?? '')),
            'subview' => trim((string)($_merged['subview'] ?? '')),
        ]);
        return $this->success(null, '菜单项已更新');
    }

    public function deleteById(Request $request, string $id): Response
    {
        $child = DB::table('navigation_menus')->where('parent_id', (int)$id)->first();
        if ($child) {
            return $this->error('该菜单存在子菜单，请先删除子菜单');
        }
        MenuPermissionService::removeForMenu((int)$id);
        DB::table('navigation_menus')->where('id', (int)$id)->delete();
        return $this->success(null, '菜单已删除');
    }

    /** 手动同步全部菜单权限节点（管理员） */
    public function syncPermissions(Request $request): Response
    {
        $n = MenuPermissionService::syncAll();
        return $this->success(['synced' => $n], "已同步 {$n} 个菜单权限");
    }
}
