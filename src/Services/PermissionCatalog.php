<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 权限节点拆分与别名：复合名称（/ 分隔）拆为单操作节点，并兼容历史角色勾选。
 */
class PermissionCatalog
{
    /** @var array<int, list<int>> 原权限 id => 拆分后需一并勾选的 id */
    private const ROLE_EXPAND = [
        2 => [89],           // account.create → account.import
        5 => [90],           // proxy.create → proxy.import
        83 => [91, 92],      // publish.tasks → warm.tasks, publish.materials
        76 => [97],          // msg comment → + dm
        86 => [94, 95],      // work.pause → + delete, reset
    ];

    /**
     * canPermission / RBAC 别名：检查 $code 时，用户拥有列表中任一 code 即视为通过。
     *
     * @return array<string, list<string>>
     */
    public static function aliases(): array
    {
        return [
            'matrix.publish' => ['publish.tasks'],
            'matrix.mass' => ['publish.mass'],
            'publish.tasks' => ['matrix.publish'],
            'publish.mass' => ['matrix.mass'],
            'warm.tasks' => ['publish.tasks', 'matrix.publish', 'account.create'],
            'publish.materials' => ['publish.tasks', 'matrix.publish'],
            'account.import' => ['account.create'],
            'proxy.import' => ['proxy.create'],
            'account.work' => [
                'account.work.pause',
                'account.work.delete',
                'account.work.reset',
                'account.create',
                'publish.tasks',
                'matrix.publish',
            ],
            'account.work.pause' => ['account.work', 'account.create', 'publish.tasks', 'matrix.publish'],
            'account.work.delete' => ['account.work', 'account.create', 'publish.tasks', 'matrix.publish'],
            'account.work.reset' => ['account.work', 'account.create', 'publish.tasks', 'matrix.publish'],
            'account.msg_template' => ['account.msg_template.comment', 'account.msg_template.dm'],
            'account.msg_template.comment' => ['account.msg_template', 'chat.manage', 'account.create', 'publish.tasks'],
            'account.msg_template.dm' => ['account.msg_template', 'chat.manage', 'account.create', 'publish.tasks'],
            'account.export' => ['account.view', 'account.create'],
            'logs.view' => ['account.view', 'rbac.manage'],
            'agent.view' => ['agent.manage'],
            'system.view' => ['rbac.manage', 'system.versions', 'system.help', 'system.tickets'],
        ];
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
        } catch (\Throwable $e) {
            error_log('[PermissionCatalog] ensureSplitNodes: ' . $e->getMessage());
        }
    }

    private static function upsertNodes(): void
    {
        DB::query("UPDATE admin_permissions SET name='创建账号' WHERE code='account.create'");
        DB::query("UPDATE admin_permissions SET name='添加代理' WHERE code='proxy.create'");
        DB::query("UPDATE admin_permissions SET name='发布任务写入' WHERE code='publish.tasks'");
        DB::query("UPDATE admin_permissions SET module='publish' WHERE module='matrix'");

        DB::query(
            "UPDATE admin_permissions SET code='account.msg_template.comment', name='管理评论模板'
             WHERE id=76 OR code='account.msg_template'"
        );
        DB::query(
            "UPDATE admin_permissions SET code='account.work.pause', name='暂停任务汇总'
             WHERE id=86 OR code='account.work'"
        );

        $nodes = [
            [89, 'account', 'account.import', '导入账号', 2],
            [90, 'proxy', 'proxy.import', '批量导入代理', 11],
            [91, 'publish', 'warm.tasks', '养号任务写入', 21],
            [92, 'publish', 'publish.materials', '素材管理写入', 22],
            [94, 'account', 'account.work.delete', '删除任务汇总', 86],
            [95, 'account', 'account.work.reset', '重置任务汇总', 87],
            [97, 'account', 'account.msg_template.dm', '管理私信模板', 77],
        ];
        foreach ($nodes as [$id, $mod, $code, $name, $sort]) {
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
        $roles = DB::select('SELECT id, permissions FROM admin_roles');
        foreach ($roles as $role) {
            $ids = json_decode((string)($role['permissions'] ?? '[]'), true);
            if (!is_array($ids) || in_array('*', $ids, true)) {
                continue;
            }
            $set = [];
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
            $newIds = array_map('strval', array_keys($set));
            sort($newIds, SORT_NUMERIC);
            $oldJson = json_encode($ids, JSON_UNESCAPED_UNICODE);
            $newJson = json_encode($newIds, JSON_UNESCAPED_UNICODE);
            if ($oldJson !== $newJson) {
                DB::query('UPDATE admin_roles SET permissions=? WHERE id=?', [$newJson, (int)$role['id']]);
            }
        }
    }
}
