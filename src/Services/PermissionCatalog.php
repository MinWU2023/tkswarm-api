<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 权限节点目录：按模块分组的 view/create/update/delete 细粒度节点，
 * 兼容历史粗粒度码（rbac.manage / publish.tasks 等）的别名与角色迁移。
 */
class PermissionCatalog
{
    /**
     * 旧权限 id → 拆分后需一并勾选的新 id。
     * @var array<int, list<int>>
     */
    private const ROLE_EXPAND = [
        2 => [89, 100],                    // account.create → import + update
        5 => [90, 101, 102],               // proxy.create → import + update + delete
        10 => [110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125], // rbac.manage
        11 => [122],                       // rbac.log → logs.view
        13 => [103, 104, 105, 106],        // group.manage → CRUD
        83 => [91, 92, 130, 131, 132, 133, 138, 139, 140, 141], // publish.tasks
        84 => [134, 135, 136, 137],        // publish.mass
        91 => [142, 143, 144, 145],        // warm.tasks
        92 => [138, 139, 140, 141],        // publish.materials
        76 => [97],                        // msg comment → + dm
        86 => [94, 95],                    // work.pause → + delete, reset
        77 => [151, 152, 153, 154],        // ai.leads (id 可能冲突，迁移时按 code 再补)
        82 => [148, 149, 150],             // agent.manage
        80 => [170, 171],                  // system.view → settings
    ];

    /**
     * 细粒度节点定义：[id, module, code, name, sort_order]
     * id 从 100 起，避开历史 1–97。
     * @return list<array{0:int,1:string,2:string,3:string,4:int}>
     */
    public static function fineNodes(): array
    {
        return [
            // —— 账号 ——
            [100, 'account', 'account.update', '编辑账号', 4],

            // —— 代理 ——
            [101, 'proxy', 'proxy.update', '编辑代理', 13],
            [102, 'proxy', 'proxy.delete', '删除代理', 14],

            // —— 分组 ——
            [103, 'group', 'group.view', '查看分组', 50],
            [104, 'group', 'group.create', '添加分组', 51],
            [105, 'group', 'group.update', '编辑分组', 52],
            [106, 'group', 'group.delete', '删除分组', 53],
            [174, 'group', 'group.assign', '分配分组用户', 54],

            // —— 权限：管理用户 ——
            [110, 'rbac', 'rbac.users.view', '查看管理用户', 110],
            [111, 'rbac', 'rbac.users.create', '添加管理用户', 111],
            [112, 'rbac', 'rbac.users.update', '编辑管理用户', 112],
            [113, 'rbac', 'rbac.users.delete', '删除管理用户', 113],

            // —— 权限：角色 ——
            [114, 'rbac', 'rbac.roles.view', '查看用户角色', 114],
            [115, 'rbac', 'rbac.roles.create', '添加用户角色', 115],
            [116, 'rbac', 'rbac.roles.update', '编辑用户角色', 116],
            [117, 'rbac', 'rbac.roles.delete', '删除用户角色', 117],

            // —— 权限：节点 ——
            [118, 'rbac', 'rbac.permissions.view', '查看权限节点', 118],
            [119, 'rbac', 'rbac.permissions.create', '添加权限节点', 119],
            [120, 'rbac', 'rbac.permissions.update', '编辑权限节点', 120],
            [121, 'rbac', 'rbac.permissions.delete', '删除权限节点', 121],

            // —— 权限：日志 / 菜单 ——
            [122, 'rbac', 'rbac.logs.view', '查看操作日志', 122],
            [123, 'rbac', 'rbac.logs.clear', '清空操作日志', 123],
            [124, 'rbac', 'rbac.menus.view', '查看后台菜单', 124],
            [125, 'rbac', 'rbac.menus.update', '编辑后台菜单', 125],

            // —— 发布任务 ——
            [130, 'publish', 'publish.tasks.view', '查看发布任务', 130],
            [131, 'publish', 'publish.tasks.create', '创建发布任务', 131],
            [132, 'publish', 'publish.tasks.update', '编辑发布任务', 132],
            [133, 'publish', 'publish.tasks.delete', '删除发布任务', 133],

            // —— 群发 ——
            [134, 'publish', 'publish.mass.view', '查看群发任务', 134],
            [135, 'publish', 'publish.mass.create', '创建群发任务', 135],
            [136, 'publish', 'publish.mass.update', '编辑群发任务', 136],
            [137, 'publish', 'publish.mass.delete', '删除群发任务', 137],

            // —— 素材 ——
            [138, 'publish', 'publish.materials.view', '查看素材', 138],
            [139, 'publish', 'publish.materials.create', '上传/添加素材', 139],
            [140, 'publish', 'publish.materials.update', '编辑素材', 140],
            [141, 'publish', 'publish.materials.delete', '删除素材', 141],

            // —— 养号 ——
            [142, 'publish', 'warm.view', '查看养号任务', 142],
            [143, 'publish', 'warm.create', '创建养号任务', 143],
            [144, 'publish', 'warm.update', '编辑养号任务', 144],
            [145, 'publish', 'warm.delete', '删除养号任务', 145],

            // —— 客服 ——
            [146, 'chat', 'chat.view', '查看客服会话', 146],
            [147, 'chat', 'chat.update', '管理客服会话设置', 147],

            // —— 智能体 ——
            [148, 'agent', 'agent.create', '创建智能体', 148],
            [149, 'agent', 'agent.update', '编辑智能体', 149],
            [150, 'agent', 'agent.delete', '删除智能体', 150],

            // —— AI 拓客 ——
            [151, 'ai', 'ai.leads.view', '查看AI拓客', 151],
            [152, 'ai', 'ai.leads.create', '创建AI拓客', 152],
            [153, 'ai', 'ai.leads.update', '编辑AI拓客', 153],
            [154, 'ai', 'ai.leads.delete', '删除AI拓客', 154],

            // —— 国家 / 行业 / 企业数据 ——
            [160, 'rbac', 'rbac.country_data.view', '查看国家数据', 160],
            [161, 'rbac', 'rbac.country_data.create', '添加国家数据', 161],
            [162, 'rbac', 'rbac.country_data.update', '编辑国家数据', 162],
            [163, 'rbac', 'rbac.country_data.delete', '删除国家数据', 163],
            [164, 'rbac', 'rbac.industry_data.view', '查看行业数据', 164],
            [165, 'rbac', 'rbac.industry_data.create', '添加行业数据', 165],
            [166, 'rbac', 'rbac.industry_data.update', '编辑行业数据', 166],
            [167, 'rbac', 'rbac.industry_data.delete', '删除行业数据', 167],
            [168, 'rbac', 'rbac.company_data.view', '查看企业数据', 168],
            [169, 'rbac', 'rbac.company_data.create', '添加企业数据', 169],
            [172, 'rbac', 'rbac.company_data.update', '编辑企业数据', 172],
            [173, 'rbac', 'rbac.company_data.delete', '删除企业数据', 173],

            // —— 系统设置 ——
            [170, 'system', 'system.settings.view', '查看基础设置', 170],
            [171, 'system', 'system.settings.update', '保存基础设置', 171],
        ];
    }

    /**
     * canPermission / RBAC 别名：检查 $code 时，用户拥有列表中任一 code 即视为通过。
     * @return array<string, list<string>>
     */
    public static function aliases(): array
    {
        $legacy = [
            'matrix.publish' => ['publish.tasks', 'publish.tasks.view', 'publish.tasks.create'],
            'matrix.mass' => ['publish.mass', 'publish.mass.view', 'publish.mass.create'],
            // 粗粒度 code：仅兼容旧码；细节点不得反向满足粗粒度（否则 view 会误开 create）
            'publish.tasks' => ['matrix.publish'],
            'publish.mass' => ['matrix.mass'],
            'warm.tasks' => ['publish.tasks', 'matrix.publish', 'account.create'],
            'publish.materials' => ['publish.tasks', 'matrix.publish'],
            'account.import' => ['account.create'],
            'account.update' => ['account.create'],
            'proxy.import' => ['proxy.create'],
            'proxy.update' => ['proxy.create'],
            'proxy.delete' => ['proxy.create'],
            'group.view' => ['group.manage', 'account.view'],
            'group.create' => ['group.manage'],
            'group.update' => ['group.manage'],
            'group.delete' => ['group.manage'],
            // 分配分组用户：不挂到 group.manage，仅超管(*) / 运营管理显式授权
            'group.assign' => [],
            'group.manage' => [],
            'account.work' => [
                'account.work.pause', 'account.work.delete', 'account.work.reset',
                'account.create', 'publish.tasks', 'matrix.publish',
            ],
            'account.work.pause' => ['account.work', 'account.create', 'publish.tasks', 'matrix.publish'],
            'account.work.delete' => ['account.work', 'account.create', 'publish.tasks', 'matrix.publish'],
            'account.work.reset' => ['account.work', 'account.create', 'publish.tasks', 'matrix.publish'],
            'account.msg_template' => ['account.msg_template.comment', 'account.msg_template.dm'],
            'account.msg_template.comment' => ['account.msg_template', 'chat.manage', 'account.create', 'publish.tasks'],
            'account.msg_template.dm' => ['account.msg_template', 'chat.manage', 'account.create', 'publish.tasks'],
            'account.export' => ['account.view', 'account.create'],
            'logs.view' => ['account.view', 'rbac.manage', 'rbac.logs.view', 'rbac.log'],
            'agent.view' => ['agent.manage', 'agent.create', 'agent.update'],
            'agent.manage' => [],
            'agent.create' => ['agent.manage'],
            'agent.update' => ['agent.manage'],
            'agent.delete' => ['agent.manage'],
            'system.view' => ['rbac.manage', 'system.versions', 'system.help', 'system.tickets', 'system.settings.view', 'system.settings.update'],
            'system.settings.view' => ['system.view', 'rbac.manage'],
            'system.settings.update' => ['system.view', 'rbac.manage'],
            'chat.view' => ['chat.manage', 'chat.send'],
            'chat.update' => ['chat.manage'],
            'chat.manage' => [],
            'ai.leads' => [],
            'ai.leads.view' => ['ai.leads'],
            'ai.leads.create' => ['ai.leads'],
            'ai.leads.update' => ['ai.leads'],
            'ai.leads.delete' => ['ai.leads'],
            // rbac.manage 仅精确命中或 * / admin；不可被细节点反向满足
            'rbac.manage' => [],
            'rbac.log' => ['rbac.logs.view', 'rbac.manage'],
            'rbac.logs.view' => ['rbac.log', 'rbac.manage'],
            'rbac.logs.clear' => ['rbac.manage'],
            'rbac.users.view' => ['rbac.manage', 'rbac.users.create', 'rbac.users.update'],
            'rbac.users.create' => ['rbac.manage'],
            'rbac.users.update' => ['rbac.manage'],
            'rbac.users.delete' => ['rbac.manage'],
            'rbac.roles.view' => ['rbac.manage', 'rbac.roles.create', 'rbac.roles.update'],
            'rbac.roles.create' => ['rbac.manage'],
            'rbac.roles.update' => ['rbac.manage'],
            'rbac.roles.delete' => ['rbac.manage'],
            'rbac.permissions.view' => ['rbac.manage', 'rbac.permissions.create', 'rbac.permissions.update'],
            'rbac.permissions.create' => ['rbac.manage'],
            'rbac.permissions.update' => ['rbac.manage'],
            'rbac.permissions.delete' => ['rbac.manage'],
            'rbac.menus.view' => ['rbac.manage', 'rbac.menus.update'],
            'rbac.menus.update' => ['rbac.manage'],
            'rbac.country_data' => ['rbac.manage'],
            'rbac.country_data.view' => ['rbac.country_data', 'rbac.manage', 'rbac.country_data.create', 'rbac.country_data.update'],
            'rbac.country_data.create' => ['rbac.country_data', 'rbac.manage'],
            'rbac.country_data.update' => ['rbac.country_data', 'rbac.manage'],
            'rbac.country_data.delete' => ['rbac.country_data', 'rbac.manage'],
            'rbac.industry_data' => ['rbac.manage'],
            'rbac.industry_data.view' => ['rbac.industry_data', 'rbac.manage', 'rbac.industry_data.create', 'rbac.industry_data.update'],
            'rbac.industry_data.create' => ['rbac.industry_data', 'rbac.manage'],
            'rbac.industry_data.update' => ['rbac.industry_data', 'rbac.manage'],
            'rbac.industry_data.delete' => ['rbac.industry_data', 'rbac.manage'],
            'rbac.company_data' => ['rbac.manage', 'rbac.industry_data'],
            'rbac.company_data.view' => ['rbac.company_data', 'rbac.manage', 'rbac.company_data.create', 'rbac.company_data.update'],
            'rbac.company_data.create' => ['rbac.company_data', 'rbac.manage'],
            'rbac.company_data.update' => ['rbac.company_data', 'rbac.manage'],
            'rbac.company_data.delete' => ['rbac.company_data', 'rbac.manage'],
            'publish.tasks.view' => ['publish.tasks', 'matrix.publish', 'publish.mass', 'warm.tasks'],
            'publish.tasks.create' => ['publish.tasks', 'matrix.publish'],
            'publish.tasks.update' => ['publish.tasks', 'matrix.publish'],
            'publish.tasks.delete' => ['publish.tasks', 'matrix.publish'],
            'publish.mass.view' => ['publish.mass', 'matrix.mass', 'publish.tasks'],
            'publish.mass.create' => ['publish.mass', 'matrix.mass'],
            'publish.mass.update' => ['publish.mass', 'matrix.mass'],
            'publish.mass.delete' => ['publish.mass', 'matrix.mass'],
            'publish.materials.view' => ['publish.materials', 'publish.tasks'],
            'publish.materials.create' => ['publish.materials'],
            'publish.materials.update' => ['publish.materials'],
            'publish.materials.delete' => ['publish.materials'],
            'warm.view' => ['warm.tasks', 'publish.tasks'],
            'warm.create' => ['warm.tasks'],
            'warm.update' => ['warm.tasks'],
            'warm.delete' => ['warm.tasks'],
        ];
        return $legacy;
    }

    public static function matchesCode(string $required, array $heldCodes): bool
    {
        if (in_array('*', $heldCodes, true) || in_array('admin', $heldCodes, true)) {
            return true;
        }
        if (in_array($required, $heldCodes, true)) {
            return true;
        }
        foreach (self::aliases()[$required] ?? [] as $alt) {
            if (in_array($alt, $heldCodes, true)) {
                return true;
            }
        }
        return false;
    }

    public static function ensureSplitNodes(): void
    {
        try {
            self::upsertNodes();
            self::migrateRoles();
            self::ensureOpsAdminUserPerms();
            self::ensureAccountWizardMenuPerms();
            self::seedPermDicts();
        } catch (\Throwable $e) {
            error_log('[PermissionCatalog] ensureSplitNodes: ' . $e->getMessage());
        }
    }

    /**
     * 账号向导「拉取数据 / 修改资料」：菜单节点对全部角色开放（public）。
     * 同时把 menu.451 / menu.452 权限 id 写入所有非 * 角色，避免旧勾选列表缺项。
     */
    private static function ensureAccountWizardMenuPerms(): void
    {
        foreach ([
            ['id' => 451, 'title' => '拉取数据', 'view' => 'accountWizard', 'subview' => 'pull_stats'],
            ['id' => 452, 'title' => '修改资料', 'view' => 'accountWizard', 'subview' => 'modify_profile'],
        ] as $menu) {
            MenuPermissionService::syncOne(array_merge($menu, ['permission_code' => 'public']));
        }
        try {
            DB::query("UPDATE navigation_menus SET permission_code='public' WHERE id IN (451,452)");
        } catch (\Throwable $e) {
        }

        $codeToId = [];
        foreach (DB::select('SELECT id, code FROM admin_permissions') as $row) {
            $codeToId[(string)$row['code']] = (int)$row['id'];
        }
        // 确保权限表里仍有对应节点（供角色管理展示）；菜单本身用 public 放行
        foreach ([451 => '拉取数据', 452 => '修改资料'] as $menuId => $title) {
            $code = MenuPermissionService::codeForMenuId((int)$menuId);
            DB::query(
                "INSERT INTO admin_permissions (module, code, name, sort_order)
                 VALUES ('menu', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE module='menu', name=VALUES(name), sort_order=VALUES(sort_order)",
                [$code, mb_substr('菜单：' . $title . '（全角色）', 0, 100), 5000 + (int)$menuId]
            );
        }
        foreach (DB::select('SELECT id, code FROM admin_permissions') as $row) {
            $codeToId[(string)$row['code']] = (int)$row['id'];
        }
        $grantIds = [];
        foreach (['menu.451', 'menu.452'] as $c) {
            if (!empty($codeToId[$c])) {
                $grantIds[] = (int)$codeToId[$c];
            }
        }
        if (!$grantIds) {
            return;
        }
        $roles = DB::select('SELECT id, permissions FROM admin_roles') ?: [];
        foreach ($roles as $role) {
            $ids = json_decode((string)($role['permissions'] ?? '[]'), true);
            if (!is_array($ids) || in_array('*', $ids, true)) {
                continue;
            }
            $set = [];
            foreach ($ids as $raw) {
                $id = (int)$raw;
                if ($id > 0) {
                    $set[$id] = true;
                }
            }
            $changed = false;
            foreach ($grantIds as $gid) {
                if (!isset($set[$gid])) {
                    $set[$gid] = true;
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }
            DB::query(
                'UPDATE admin_roles SET permissions=? WHERE id=?',
                [json_encode(array_map('intval', array_keys($set)), JSON_UNESCAPED_UNICODE), (int)$role['id']]
            );
        }
    }

    /**
     * 菜单 view|subview → 细粒度功能码。
     * 角色勾了对应 menu.* 但未勾细节点时，页面会进得去却被前端/API 拦截。
     * @return array<string, list<string>>
     */
    private static function menuViewFineCodes(): array
    {
        return [
            'rbac|users' => [
                'rbac.users.view', 'rbac.users.create', 'rbac.users.update', 'rbac.users.delete',
                'rbac.roles.view',
            ],
            'rbac|roles' => [
                'rbac.roles.view', 'rbac.roles.create', 'rbac.roles.update', 'rbac.roles.delete',
            ],
            'rbac|permissions' => [
                'rbac.permissions.view', 'rbac.permissions.create', 'rbac.permissions.update', 'rbac.permissions.delete',
            ],
            'rbac|menus' => ['rbac.menus.view', 'rbac.menus.update'],
            'rbac|logs' => ['rbac.logs.view', 'rbac.log'],
            'rbac|country_data' => [
                'rbac.country_data', 'rbac.country_data.view', 'rbac.country_data.create',
                'rbac.country_data.update', 'rbac.country_data.delete',
            ],
            'rbac|industry_data' => [
                'rbac.industry_data', 'rbac.industry_data.view', 'rbac.industry_data.create',
                'rbac.industry_data.update', 'rbac.industry_data.delete',
            ],
            'rbac|company_data' => [
                'rbac.company_data', 'rbac.company_data.view', 'rbac.company_data.create',
                'rbac.company_data.update', 'rbac.company_data.delete',
            ],
        ];
    }

    /** 运营管理：保证可打开并维护「管理用户」列表（含选角色） */
    private static function ensureOpsAdminUserPerms(): void
    {
        self::ensureRoleCodesByName('%运营管理%', [
            'rbac.users.view', 'rbac.users.create', 'rbac.users.update', 'rbac.users.delete',
            'rbac.roles.view',
            'group.assign',
        ]);
    }

    /** @param list<string> $needCodes */
    private static function ensureRoleCodesByName(string $roleNameLike, array $needCodes): void
    {
        $codeToId = [];
        foreach (DB::select('SELECT id, code FROM admin_permissions') as $row) {
            $codeToId[(string)$row['code']] = (int)$row['id'];
        }
        $needIds = [];
        foreach ($needCodes as $code) {
            if (!empty($codeToId[$code])) {
                $needIds[] = (int)$codeToId[$code];
            }
        }
        if (!$needIds) {
            return;
        }
        $roles = DB::select('SELECT id, permissions FROM admin_roles WHERE name LIKE ?', [$roleNameLike]);
        foreach ($roles as $role) {
            $ids = json_decode((string)($role['permissions'] ?? '[]'), true);
            if (!is_array($ids) || in_array('*', $ids, true)) {
                continue;
            }
            $set = [];
            foreach ($ids as $raw) {
                $id = (int)$raw;
                if ($id > 0) {
                    $set[$id] = true;
                }
            }
            $changed = false;
            foreach ($needIds as $nid) {
                if (!isset($set[$nid])) {
                    $set[$nid] = true;
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }
            $newIds = array_map('strval', array_keys($set));
            sort($newIds, SORT_NUMERIC);
            DB::query(
                'UPDATE admin_roles SET permissions=? WHERE id=?',
                [json_encode($newIds, JSON_UNESCAPED_UNICODE), (int)$role['id']]
            );
        }
    }

    private static function upsertNodes(): void
    {
        DB::query("UPDATE admin_permissions SET name='创建账号' WHERE code='account.create'");
        DB::query("UPDATE admin_permissions SET name='添加代理' WHERE code='proxy.create'");
        DB::query("UPDATE admin_permissions SET name='发布任务写入' WHERE code='publish.tasks'");
        DB::query("UPDATE admin_permissions SET module='publish' WHERE module='matrix'");
        DB::query("UPDATE admin_permissions SET name='系统角色与权限（总控·兼容）' WHERE code='rbac.manage'");

        DB::query(
            "UPDATE admin_permissions SET code='account.msg_template.comment', name='管理评论模板'
             WHERE id=76 OR code='account.msg_template'"
        );
        DB::query(
            "UPDATE admin_permissions SET code='account.work.pause', name='暂停矩阵任务'
             WHERE id=86 OR code='account.work'"
        );

        $legacy = [
            [89, 'account', 'account.import', '导入账号', 2],
            [90, 'proxy', 'proxy.import', '批量导入代理', 11],
            [91, 'publish', 'warm.tasks', '养号任务写入', 21],
            [92, 'publish', 'publish.materials', '素材管理写入', 22],
            [94, 'account', 'account.work.delete', '删除矩阵任务', 86],
            [95, 'account', 'account.work.reset', '重置矩阵任务', 87],
            [97, 'account', 'account.msg_template.dm', '管理私信模板', 77],
        ];
        foreach (array_merge($legacy, self::fineNodes()) as [$id, $mod, $code, $name, $sort]) {
            DB::query(
                "INSERT INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE module=VALUES(module), code=VALUES(code), name=VALUES(name), sort_order=VALUES(sort_order)",
                [$id, $mod, $code, $name, $sort]
            );
        }
    }

    private static function migrateRoles(): void
    {
        $codeToId = [];
        foreach (DB::select('SELECT id, code FROM admin_permissions') as $row) {
            $codeToId[(string)$row['code']] = (int)$row['id'];
        }

        // 按 code 补 expand：持有粗粒度 code 时补齐细节点
        $codeExpand = [
            'rbac.manage' => [
                'rbac.users.view', 'rbac.users.create', 'rbac.users.update', 'rbac.users.delete',
                'rbac.roles.view', 'rbac.roles.create', 'rbac.roles.update', 'rbac.roles.delete',
                'rbac.permissions.view', 'rbac.permissions.create', 'rbac.permissions.update', 'rbac.permissions.delete',
                'rbac.logs.view', 'rbac.logs.clear', 'rbac.menus.view', 'rbac.menus.update',
            ],
            'rbac.country_data' => ['rbac.country_data.view', 'rbac.country_data.create', 'rbac.country_data.update', 'rbac.country_data.delete'],
            'rbac.industry_data' => ['rbac.industry_data.view', 'rbac.industry_data.create', 'rbac.industry_data.update', 'rbac.industry_data.delete'],
            'rbac.company_data' => ['rbac.company_data.view', 'rbac.company_data.create', 'rbac.company_data.update', 'rbac.company_data.delete'],
            'group.manage' => ['group.view', 'group.create', 'group.update', 'group.delete'],
            'publish.tasks' => ['publish.tasks.view', 'publish.tasks.create', 'publish.tasks.update', 'publish.tasks.delete'],
            'publish.mass' => ['publish.mass.view', 'publish.mass.create', 'publish.mass.update', 'publish.mass.delete'],
            'publish.materials' => ['publish.materials.view', 'publish.materials.create', 'publish.materials.update', 'publish.materials.delete'],
            'warm.tasks' => ['warm.view', 'warm.create', 'warm.update', 'warm.delete'],
            'ai.leads' => ['ai.leads.view', 'ai.leads.create', 'ai.leads.update', 'ai.leads.delete'],
            'agent.manage' => ['agent.create', 'agent.update', 'agent.delete'],
            'system.view' => ['system.settings.view', 'system.settings.update'],
            'account.create' => ['account.update'],
            'proxy.create' => ['proxy.update', 'proxy.delete'],
            'chat.manage' => ['chat.view', 'chat.update'],
        ];

        $roles = DB::select('SELECT id, permissions FROM admin_roles');
        foreach ($roles as $role) {
            $ids = json_decode((string)($role['permissions'] ?? '[]'), true);
            if (!is_array($ids) || in_array('*', $ids, true)) {
                continue;
            }
            $set = [];
            $heldCodes = [];
            foreach ($ids as $raw) {
                $id = (int)$raw;
                if ($id <= 0) {
                    continue;
                }
                $set[$id] = true;
                foreach (self::ROLE_EXPAND[$id] ?? [] as $extra) {
                    $set[(int)$extra] = true;
                }
            }
            foreach (DB::select(
                'SELECT code FROM admin_permissions WHERE id IN (' . implode(',', array_map('intval', array_keys($set)) ?: [0]) . ')'
            ) as $r) {
                $heldCodes[] = (string)$r['code'];
            }
            foreach ($heldCodes as $code) {
                foreach ($codeExpand[$code] ?? [] as $extraCode) {
                    if (isset($codeToId[$extraCode])) {
                        $set[$codeToId[$extraCode]] = true;
                    }
                }
            }
            // 已勾菜单但未勾细节点：按菜单 view/subview 补齐功能码
            $menuIds = [];
            foreach ($heldCodes as $code) {
                if (preg_match('/^menu\.(\d+)$/', (string)$code, $m)) {
                    $menuIds[] = (int)$m[1];
                }
            }
            if ($menuIds) {
                $ph = implode(',', array_fill(0, count($menuIds), '?'));
                $menuRows = DB::select(
                    "SELECT id, view, subview FROM navigation_menus WHERE id IN ({$ph})",
                    $menuIds
                ) ?: [];
                $menuMap = self::menuViewFineCodes();
                foreach ($menuRows as $menu) {
                    $key = trim((string)($menu['view'] ?? '')) . '|' . trim((string)($menu['subview'] ?? ''));
                    foreach ($menuMap[$key] ?? [] as $extraCode) {
                        if (isset($codeToId[$extraCode])) {
                            $set[$codeToId[$extraCode]] = true;
                        }
                    }
                }
            }
            $newIds = array_map('strval', array_keys($set));
            sort($newIds, SORT_NUMERIC);
            $oldJson = json_encode(array_map('strval', $ids), JSON_UNESCAPED_UNICODE);
            $newJson = json_encode($newIds, JSON_UNESCAPED_UNICODE);
            if ($oldJson !== $newJson) {
                DB::query('UPDATE admin_roles SET permissions=? WHERE id=?', [$newJson, (int)$role['id']]);
            }
        }
    }

    private static function seedPermDicts(): void
    {
        $modules = [
            ['account', '账号', 1],
            ['proxy', '代理', 2],
            ['group', '分组', 3],
            ['publish', 'AI矩阵', 4],
            ['chat', '客服', 5],
            ['agent', '智能体', 6],
            ['ai', 'AI营销', 7],
            ['rbac', '权限管理', 8],
            ['system', '系统', 9],
            ['logs', '日志', 10],
        ];
        foreach ($modules as $i => [$code, $label, $sort]) {
            self::upsertDict('perm_module', $code, $label, $sort);
        }

        $subgroups = [
            ['account.view', '账号·列表'],
            ['account.create', '账号·添加'],
            ['account.update', '账号·编辑'],
            ['account.delete', '账号·删除'],
            ['account.import', '账号·导入'],
            ['account.export', '账号·导出'],
            ['account.work', '矩阵任务'],
            ['account.msg_template', '消息模板'],
            ['proxy.view', '代理·列表'],
            ['proxy.create', '代理·添加'],
            ['proxy.update', '代理·编辑'],
            ['proxy.delete', '代理·删除'],
            ['group.view', '分组·列表'],
            ['group.create', '分组·添加'],
            ['group.update', '分组·编辑'],
            ['group.delete', '分组·删除'],
            ['group.assign', '分组·分配用户'],
            ['publish.tasks', '发布任务'],
            ['publish.mass', '群发任务'],
            ['publish.materials', '素材管理'],
            ['warm.tasks', '养号任务'],
            ['rbac.users', '管理用户'],
            ['rbac.roles', '用户角色'],
            ['rbac.permissions', '权限节点'],
            ['rbac.logs', '操作日志'],
            ['rbac.menus', '后台菜单'],
            ['rbac.country_data', '国家数据'],
            ['rbac.industry_data', '行业数据'],
            ['rbac.company_data', '企业数据'],
            ['chat.manage', '客服管理'],
            ['agent.manage', '智能体'],
            ['ai.leads', 'AI拓客'],
            ['system.view', '系统设置'],
            ['menu.visible', '菜单节点'],
        ];
        foreach ($subgroups as $i => [$code, $label]) {
            self::upsertDict('perm_subgroup', $code, $label, $i + 1);
        }
    }

    private static function upsertDict(string $type, string $code, string $label, int $sort): void
    {
        try {
            DB::query(
                "INSERT INTO meta_dicts(`type`, code, label, sort_order)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE label=VALUES(label), sort_order=VALUES(sort_order)",
                [$type, $code, $label, $sort]
            );
        } catch (\Throwable $e) {
            // meta_dicts 可能尚未建表
        }
    }
}
