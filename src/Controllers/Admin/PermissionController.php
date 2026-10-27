<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Core\Auth\RBAC;
use App\Services\DataScope;
use App\Services\CompanyService;

class PermissionController extends Controller
{
    /** 细粒度权限：任一码命中即可；鉴权关闭时放行 */
    private function assertAnyPermission(array $codes): ?Response
    {
        try {
            $row = DB::selectOne("SELECT `value` FROM settings WHERE `key`='authEnabled'");
            if ($row) {
                $val = json_decode((string)$row['value'], true);
                if ($val === null && $row['value'] !== 'null') {
                    $val = $row['value'];
                }
                if (!filter_var($val, FILTER_VALIDATE_BOOLEAN)) {
                    return null;
                }
            }
        } catch (\Throwable $e) {
        }
        $user = $GLOBALS['auth_user'] ?? null;
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            return $this->error('未登录', 401);
        }
        $held = RBAC::userPermissionCodes($uid);
        foreach ($codes as $code) {
            if (RBAC::matchesHeldCode((string)$code, $held)) {
                return null;
            }
        }
        return $this->error('当前角色无权执行此操作（需要：' . implode(' / ', $codes) . '）', 403);
    }

    private static function roleName(int $roleId): string
    {
        if ($roleId <= 0) {
            return '';
        }
        $role = DB::table('admin_roles')->where('id', $roleId)->first();
        return (string)($role['name'] ?? '');
    }

    private static function isEnterpriseCustomerRole(int $roleId): bool
    {
        return str_contains(self::roleName($roleId), '企业客户');
    }

    private static function isOperatorRole(int $roleId): bool
    {
        return str_contains(self::roleName($roleId), '运营专员');
    }

    /** 企业客户 / 运营专员：不可设子级，需关联企业 */
    private static function isCompanyScopedRole(int $roleId): bool
    {
        return self::isEnterpriseCustomerRole($roleId) || self::isOperatorRole($roleId);
    }

    /** 不可配置子级管理员的角色 */
    private static function isNoChildAdminRole(int $roleId): bool
    {
        if ($roleId === 1) {
            return true;
        }
        $name = self::roleName($roleId);
        return str_contains($name, '超级管理员')
            || str_contains($name, '运营管理')
            || self::isCompanyScopedRole($roleId);
    }

    /** @return list<int> */
    private static function parseCompanyIds(Request $request): array
    {
        $raw = $request->input('companyIds', $request->input('company_ids', null));
        if (is_array($raw)) {
            return array_values(array_unique(array_filter(array_map('intval', $raw))));
        }
        $single = $request->input('companyId', $request->input('company_id'));
        if ($single === null || $single === '') {
            return [];
        }
        $id = (int)$single;
        return $id > 0 ? [$id] : [];
    }

    // 权限管理主页面
    public function index(Request $request): Response
    {
        return $this->view('admin.rbac', [
            'title' => '系统权限与角色管理',
            'user' => $GLOBALS['auth_user'] ?? null
        ]);
    }

    // ==========================================
    // 1. 权限节点管理
    // ==========================================

    // 权限节点列表
    public function permissions(Request $request): Response
    {
        if ($deny = $this->assertAnyPermission(['rbac.permissions.view', 'rbac.manage'])) {
            return $deny;
        }
        $keyword = trim((string)$request->get('keyword', ''));
        $module = trim((string)$request->get('module', ''));

        $query = DB::table('admin_permissions')->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC');

        if (!empty($module)) {
            $query->where('module', $module);
        }

        $nodes = $query->get();

        if (!empty($keyword)) {
            $nodes = array_values(array_filter($nodes, function($item) use ($keyword) {
                return stripos($item['name'], $keyword) !== false 
                    || stripos($item['code'], $keyword) !== false 
                    || stripos($item['module'], $keyword) !== false;
            }));
        }

        return $this->success($nodes);
    }

    // 保存权限节点（新增 / 编辑）
    public function savePermission(Request $request): Response
    {
        $id = $request->input('id');
        $deny = $this->assertAnyPermission($id
            ? ['rbac.permissions.update', 'rbac.manage']
            : ['rbac.permissions.create', 'rbac.manage']);
        if ($deny) {
            return $deny;
        }
        $id = $request->input('id');
        $module = trim((string)$request->input('module', 'system'));
        $code = trim((string)$request->input('code', ''));
        $name = trim((string)$request->input('name', ''));
        $sortOrder = (int)$request->input('sort_order', 0);

        if (empty($code)) {
            return $this->error('权限标识符 (Code) 不能为空');
        }
        if (empty($name)) {
            return $this->error('权限名称不能为空');
        }

        if ($id) {
            $exist = DB::table('admin_permissions')->where('id', (int)$id)->first();
            if (!$exist) {
                return $this->error('权限节点不存在', 404);
            }
            DB::table('admin_permissions')->where('id', (int)$id)->update([
                'module' => $module,
                'code' => $code,
                'name' => $name,
                'sort_order' => $sortOrder
            ]);
        } else {
            $existCode = DB::table('admin_permissions')->where('code', $code)->first();
            if ($existCode) {
                return $this->error('权限标识符已存在: ' . $code);
            }
            DB::table('admin_permissions')->insert([
                'module' => $module,
                'code' => $code,
                'name' => $name,
                'sort_order' => $sortOrder,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

        return $this->success(null, '权限节点已保存');
    }

    // 删除权限节点
    public function deletePermission(Request $request): Response
    {
        if ($deny = $this->assertAnyPermission(['rbac.permissions.delete', 'rbac.manage'])) {
            return $deny;
        }
        $id = (int)$request->input('id');
        if ($id <= 0) {
            return $this->error('无效的权限节点 ID');
        }
        DB::table('admin_permissions')->where('id', $id)->delete();
        return $this->success(null, '权限节点已删除');
    }

    // ==========================================
    // 2. 用户角色管理
    // ==========================================

    // 角色管理列表
    public function roles(Request $request): Response
    {
        if ($deny = $this->assertAnyPermission(['rbac.roles.view', 'rbac.manage', 'rbac.users.view', 'rbac.users.create', 'rbac.users.update'])) {
            return $deny;
        }
        $roles = DB::table('admin_roles')->orderBy('id', 'ASC')->get();
        // 统计每个角色的用户数量
        foreach ($roles as &$r) {
            $userCount = DB::table('admin_users')->where('role_id', (int)$r['id'])->count();
            $r['user_count'] = $userCount;
        }
        return $this->success($roles);
    }

    // 保存角色（新增 / 编辑）
    public function saveRole(Request $request): Response
    {
        $id = $request->input('id');
        if ($deny = $this->assertAnyPermission($id
            ? ['rbac.roles.update', 'rbac.manage']
            : ['rbac.roles.create', 'rbac.manage'])) {
            return $deny;
        }
        $name = trim((string)$request->input('name', ''));
        $description = (string)$request->input('description', '');
        $permissions = $request->input('permissions', []);
        $status = $request->input('status', 1);

        if (empty($name)) {
            return $this->error('角色名称不能为空');
        }

        $permsJson = is_array($permissions) ? json_encode(array_values($permissions)) : (string)$permissions;

        if ($id) {
            DB::table('admin_roles')->where('id', (int)$id)->update([
                'name' => $name,
                'description' => $description,
                'permissions' => $permsJson,
                'status' => (int)$status,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            $exist = DB::table('admin_roles')->where('name', $name)->first();
            if ($exist) {
                return $this->error('角色名称已存在');
            }
            DB::table('admin_roles')->insert([
                'name' => $name,
                'description' => $description,
                'permissions' => $permsJson,
                'status' => (int)$status,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
        return $this->success(null, '角色配置已更新');
    }

    // 删除角色
    public function deleteRole(Request $request): Response
    {
        if ($deny = $this->assertAnyPermission(['rbac.roles.delete', 'rbac.manage'])) {
            return $deny;
        }
        $id = (int)$request->input('id');
        if ($id <= 1) {
            return $this->error('系统超级管理员角色禁止删除');
        }
        $hasUser = DB::table('admin_users')->where('role_id', $id)->first();
        if ($hasUser) {
            return $this->error('该角色下仍有绑定的用户，无法删除');
        }
        DB::table('admin_roles')->where('id', $id)->delete();
        return $this->success(null, '角色已成功删除');
    }

    // ==========================================
    // 3. 管理用户管理
    // ==========================================

    // 管理用户列表
    public function users(Request $request): Response
    {
        if ($deny = $this->assertAnyPermission(['rbac.users.view', 'rbac.manage'])) {
            return $deny;
        }
        DataScope::ensureChildrenTable();
        CompanyService::ensureSchema();
        \App\Services\PlanTask\PlanTaskRecipientResolver::ensureAdminEmailColumn();
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 20);
        $keyword = trim((string)$request->get('keyword', ''));
        $status = $request->get('status', null);

        $query = DB::table('admin_users')
            ->select(['id', 'username', 'nickname', 'email', 'role_id', 'company_id', 'status', 'created_at', 'updated_at'])
            ->orderBy('id', 'ASC');

        if ($status !== null && $status !== '') {
            $query->where('status', (int)$status);
        }

        if (!empty($keyword)) {
            $query->where('username', 'LIKE', "%{$keyword}%")
                  ->orWhere('nickname', 'LIKE', "%{$keyword}%");
        }

        $res = $query->paginate($page, $pageSize);

        $roles = DB::table('admin_roles')->get();
        $roleMap = [];
        foreach ($roles as $r) {
            $roleMap[$r['id']] = $r['name'];
        }

        if (!empty($res['items'])) {
            $userIds = array_map(static fn($row) => (int)($row['id'] ?? 0), $res['items']);
            $userCompanyMap = CompanyService::companyIdsMapForUsers($userIds);
            $allCompanyIds = [];
            foreach ($userCompanyMap as $ids) {
                foreach ($ids as $cid) {
                    $allCompanyIds[] = $cid;
                }
            }
            $companyMap = CompanyService::mapByIds(array_values(array_unique($allCompanyIds)));
            foreach ($res['items'] as &$item) {
                $uid = (int)$item['id'];
                $item['role_name'] = $roleMap[$item['role_id']] ?? '未知角色';
                $item['childIds'] = DataScope::childIdsOf($uid);
                $item['children'] = array_map(static function ($c) {
                    return [
                        'id' => (int)$c['id'],
                        'username' => $c['username'],
                        'nickname' => $c['nickname'] ?? '',
                    ];
                }, DataScope::childrenOf($uid));
                $cids = $userCompanyMap[$uid] ?? [];
                if (!$cids) {
                    $fallback = (int)($item['company_id'] ?? 0);
                    if ($fallback > 0) {
                        $cids = [$fallback];
                    }
                }
                $item['companyIds'] = $cids;
                $item['companies'] = array_values(array_filter(array_map(static function ($cid) use ($companyMap) {
                    return $companyMap[$cid] ?? null;
                }, $cids)));
                $primaryId = $cids[0] ?? 0;
                $item['company_id'] = $primaryId > 0 ? $primaryId : null;
                $item['company'] = $primaryId > 0 ? ($companyMap[$primaryId] ?? null) : null;
            }
            unset($item);
        }

        return $this->success($res);
    }

    // 保存管理用户（新增 / 编辑）
    public function saveUser(Request $request): Response
    {
        $id = $request->input('id');
        $need = $id
            ? ['rbac.users.update', 'rbac.manage']
            : ['rbac.users.create', 'rbac.manage'];
        if ($deny = $this->assertAnyPermission($need)) {
            return $deny;
        }
        DataScope::ensureChildrenTable();
        CompanyService::ensureSchema();
        \App\Services\PlanTask\PlanTaskRecipientResolver::ensureAdminEmailColumn();
        $username = trim((string)$request->input('username', ''));
        $nickname = trim((string)$request->input('nickname', ''));
        $email = strtolower(trim((string)$request->input('email', '')));
        $password = (string)$request->input('password', '');
        $roleId = (int)$request->input('role_id', 1);
        $status = (int)$request->input('status', 1);
        $childIds = $request->input('childIds', $request->input('child_ids', []));
        if (!is_array($childIds)) {
            $childIds = [];
        }
        $companyIds = self::parseCompanyIds($request);
        $isEnterpriseRole = self::isEnterpriseCustomerRole($roleId);
        $isCompanyScopedRole = self::isCompanyScopedRole($roleId);
        if (self::isNoChildAdminRole($roleId)) {
            $childIds = [];
        }

        if (empty($username)) {
            return $this->error('用户名不能为空');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('邮箱格式不正确');
        }

        $authUser = $GLOBALS['auth_user'] ?? null;
        $authIsSuper = $authUser && (int)($authUser['role_id'] ?? 0) === 1;

        if ($id) {
            $exist = DB::table('admin_users')->where('id', (int)$id)->first();
            if (!$exist) {
                return $this->error('用户不存在', 404);
            }

            if (!$authIsSuper && $authUser) {
                $authId = (int)$authUser['id'];
                // 非超管只能编辑自己，或无权改他人（简化：仅超管可管全部用户）
                if ($authId !== (int)$id && !DataScope::isDirectChild($authId, (int)$id)) {
                    // 允许父级编辑自己勾选的子级资料
                    return $this->error('无权编辑该管理员', 403);
                }
            }

            $update = [
                'nickname' => $nickname ?: $username,
                'email' => $email,
                'role_id' => $roleId,
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if (!empty($password)) {
                $update['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
            }

            DB::table('admin_users')->where('id', (int)$id)->update($update);

            if ($isCompanyScopedRole) {
                if ($isEnterpriseRole && !$companyIds) {
                    return $this->error('企业客户角色请至少关联一个企业');
                }
                try {
                    CompanyService::bindCompanies((int)$id, $companyIds);
                } catch (\InvalidArgumentException $e) {
                    return $this->error($e->getMessage());
                }
                $err = DataScope::setChildren((int)$id, []);
                if ($err) {
                    return $err;
                }
            } else {
                try {
                    CompanyService::bindCompanies((int)$id, []);
                } catch (\Throwable $e) {
                }
                if (self::isNoChildAdminRole($roleId)) {
                    $err = DataScope::setChildren((int)$id, []);
                    if ($err) {
                        return $err;
                    }
                } elseif ($request->input('childIds') !== null || $request->input('child_ids') !== null) {
                    $err = DataScope::setChildren((int)$id, $childIds);
                    if ($err) {
                        return $err;
                    }
                }
            }

            return $this->success(null, '管理员资料已更新');
        }

        if (empty($password)) {
            return $this->error('初始密码不能为空');
        }
        $exist = DB::table('admin_users')->where('username', $username)->first();
        if ($exist) {
            return $this->error('该用户名已存在');
        }

        $createEnterprise = filter_var(
            $request->input('createEnterprise', $request->input('create_enterprise', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($isEnterpriseRole && !$createEnterprise && !$companyIds) {
            return $this->error('企业客户角色请至少关联一个企业');
        }

        try {
            if ($createEnterprise) {
                $companyInput = $request->input('company', []);
                if (!is_array($companyInput)) {
                    $companyInput = [];
                }
                $result = CompanyService::createWithUser(
                    [
                        'username' => $username,
                        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                        'nickname' => $nickname ?: $username,
                        'email' => $email,
                        'role_id' => $roleId,
                        'status' => $status,
                    ],
                    [
                        'name' => trim((string)($companyInput['name'] ?? $request->input('companyName', $request->input('company_name', '')))),
                        'contact_name' => trim((string)($companyInput['contactName'] ?? $companyInput['contact_name'] ?? $request->input('contactName', $request->input('contact_name', '')))),
                        'contact_phone' => trim((string)($companyInput['contactPhone'] ?? $companyInput['contact_phone'] ?? $request->input('contactPhone', $request->input('contact_phone', '')))),
                        'contact_email' => trim((string)($companyInput['contactEmail'] ?? $companyInput['contact_email'] ?? $request->input('contactEmail', $request->input('contact_email', '')))),
                        'industry_id' => (int)($companyInput['industryId'] ?? $companyInput['industry_id'] ?? $request->input('industryId', $request->input('industry_id', 0))),
                    ]
                );
                $newId = $result['userId'];
            } else {
                $insert = [
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'nickname' => $nickname ?: $username,
                    'email' => $email,
                    'role_id' => $roleId,
                    'status' => $status,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($isCompanyScopedRole && $companyIds) {
                    $insert['company_id'] = $companyIds[0];
                }
                $newId = (int)DB::table('admin_users')->insert($insert);
                if ($isCompanyScopedRole && $companyIds) {
                    CompanyService::bindCompanies($newId, $companyIds);
                }
            }
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        if (self::isNoChildAdminRole($roleId)) {
            $err = DataScope::setChildren($newId, []);
            if ($err) {
                return $err;
            }
        } elseif ($childIds) {
            $err = DataScope::setChildren($newId, $childIds);
            if ($err) {
                return $err;
            }
        }

        return $this->success(['id' => $newId], $createEnterprise ? '企业与管理用户已创建' : '管理员已创建');
    }

    // 删除管理用户
    public function deleteUser(Request $request): Response
    {
        if ($deny = $this->assertAnyPermission(['rbac.users.delete', 'rbac.manage'])) {
            return $deny;
        }
        DataScope::ensureChildrenTable();
        $id = (int)$request->input('id');
        if ($id === 1) {
            return $this->error('初始默认超级管理员禁止删除');
        }
        $exist = DB::table('admin_users')->where('id', $id)->first();
        if (!$exist) {
            return $this->error('用户不存在', 404);
        }

        $childIds = DataScope::childIdsOf($id);
        if ($childIds) {
            return $this->error('请先解除其下的子级管理员再删除', 422);
        }

        $owned = DataScope::countOwnedBusiness($id);
        if ($owned > 0) {
            return $this->error("该用户名下仍有 {$owned} 条业务数据，请先转移或清空后再删除", 422);
        }

        try {
            DB::query('DELETE FROM admin_user_children WHERE parent_id=? OR child_id=?', [$id, $id]);
        } catch (\Throwable $e) {
        }
        CompanyService::detachUser($id);
        DB::table('admin_sessions')->where('user_id', $id)->delete();
        DB::table('admin_users')->where('id', $id)->delete();
        return $this->success(null, '管理员账号已删除');
    }

    // ==========================================
    // 4. 操作审计日志管理
    // ==========================================

    // 操作日志列表
    public function logs(Request $request): Response
    {
        if ($deny = $this->assertAnyPermission(['rbac.logs.view', 'rbac.log', 'rbac.manage', 'logs.view'])) {
            return $deny;
        }
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 20);
        $keyword = trim((string)$request->get('keyword', ''));

        $query = DB::table('admin_logs')->orderBy('id', 'DESC');

        if ($keyword !== '') {
            $like = "%{$keyword}%";
            $matchedUsers = DB::table('admin_users')
                ->where('username', 'LIKE', $like)
                ->orWhere('nickname', 'LIKE', $like)
                ->get();
            $matchedIds = array_values(array_filter(array_map('intval', array_column($matchedUsers ?: [], 'id'))));

            $parts = ['action LIKE ?', 'module LIKE ?', 'path LIKE ?', 'ip LIKE ?'];
            $bindings = [$like, $like, $like, $like];
            if ($matchedIds !== []) {
                $ph = implode(',', array_fill(0, count($matchedIds), '?'));
                $parts[] = "user_id IN ({$ph})";
                foreach ($matchedIds as $id) {
                    $bindings[] = $id;
                }
            }
            $query->whereRaw('(' . implode(' OR ', $parts) . ')', $bindings);
        }

        $res = $query->paginate($page, $pageSize);

        // 绑定用户名
        $userIds = array_unique(array_filter(array_column($res['items'] ?? [], 'user_id')));
        $userMap = [];
        if (!empty($userIds)) {
            $users = DB::table('admin_users')->whereIn('id', $userIds)->get();
            foreach ($users as $u) {
                $userMap[$u['id']] = $u['username'];
            }
        }

        if (!empty($res['items'])) {
            foreach ($res['items'] as &$item) {
                $uid = (int)($item['user_id'] ?? 0);
                $item['username'] = $userMap[$uid] ?? ($uid === 0 ? 'guest' : 'System');
                $item['path'] = $item['path'] ?? '';
            }
            unset($item);
        }

        return $this->success($res);
    }

    // 清空操作审计日志
    public function clearLogs(Request $request): Response
    {
        if ($deny = $this->assertAnyPermission(['rbac.logs.clear', 'rbac.manage'])) {
            return $deny;
        }
        DB::execute("TRUNCATE TABLE admin_logs");
        return $this->success(null, '全部操作审计日志已清空');
    }
}
