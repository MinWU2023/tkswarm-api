<?php
declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Database\DB;
use App\Services\PermissionCatalog;

class RBAC
{
    public static function checkPermission(int $userId, string $permissionCode): bool
    {
        return self::matchesHeldCode($permissionCode, self::userPermissionCodes($userId));
    }

    /** @return list<string> */
    public static function userPermissionCodes(int $userId): array
    {
        $user = DB::table('admin_users')->where('id', $userId)->first();
        if (!$user || (int)($user['status'] ?? 0) !== 1) {
            return [];
        }
        if ((int)($user['role_id'] ?? 0) === 1) {
            return ['*'];
        }

        $role = DB::table('admin_roles')->where('id', (int)$user['role_id'])->first();
        if (!$role || (int)($role['status'] ?? 0) !== 1) {
            return [];
        }

        $permIds = json_decode((string)($role['permissions'] ?? '[]'), true) ?: [];
        if (in_array('*', $permIds, true)) {
            return ['*'];
        }
        if (!$permIds) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($permIds), '?'));
        $rows = DB::select(
            "SELECT code FROM admin_permissions WHERE id IN ({$ph})",
            array_map('intval', $permIds)
        );

        return array_values(array_filter(array_map(
            fn($r) => (string)($r['code'] ?? ''),
            $rows
        )));
    }

    /** @param list<string> $held */
    public static function matchesHeldCode(string $required, array $held): bool
    {
        if (in_array('*', $held, true) || in_array('admin', $held, true)) {
            return true;
        }
        return PermissionCatalog::matchesCode($required, $held);
    }

    public static function logOperation(
        int $userId,
        string $action,
        string $module,
        string $ip = '',
        string $userAgent = '',
        string $path = ''
    ): void {
        self::ensurePathColumn();
        DB::table('admin_logs')->insert([
            'user_id' => $userId,
            'module' => $module,
            'action' => mb_substr($action, 0, 255),
            'path' => mb_substr($path, 0, 255),
            'ip' => $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            'user_agent' => $userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private static function ensurePathColumn(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        try {
            $cols = DB::select("SHOW COLUMNS FROM `admin_logs` LIKE 'path'");
            if (empty($cols)) {
                DB::execute("ALTER TABLE `admin_logs` ADD COLUMN `path` VARCHAR(255) NOT NULL DEFAULT '' AFTER `action`");
            }
            $ready = true;
        } catch (\Throwable $e) {
            // 下次再试；插入若仍失败由调用方吞掉
            error_log('[RBAC] ensurePathColumn: ' . $e->getMessage());
        }
    }
}
