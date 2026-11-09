<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 管理用户「自研浏览器环境额度」（桌面 tkswarm + 服务器 linux，按 owner_user_id 计）。
 */
class BrowserEnvQuotaService
{
    public const DEFAULT_QUOTA = 300;

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM admin_users') ?: [], 'Field');
            if (!in_array('browser_env_quota', $cols, true)) {
                DB::query(
                    'ALTER TABLE admin_users ADD COLUMN browser_env_quota INT NOT NULL DEFAULT '
                    . self::DEFAULT_QUOTA
                    . ' AFTER status'
                );
            }
        } catch (\Throwable $e) {
        }
    }

    public static function getQuota(int $userId): int
    {
        self::ensureSchema();
        if ($userId <= 0) {
            return self::DEFAULT_QUOTA;
        }
        $row = DB::selectOne('SELECT browser_env_quota FROM admin_users WHERE id=?', [$userId]);
        if (!$row || !array_key_exists('browser_env_quota', $row) || $row['browser_env_quota'] === null) {
            return self::DEFAULT_QUOTA;
        }
        $q = (int)$row['browser_env_quota'];
        return $q >= 0 ? $q : self::DEFAULT_QUOTA;
    }

    public static function setQuota(int $userId, int $quota): void
    {
        self::ensureSchema();
        if ($userId <= 0) {
            throw new \InvalidArgumentException('用户无效');
        }
        if ($quota < 0) {
            throw new \InvalidArgumentException('额度不能为负数');
        }
        if ($quota > 100000) {
            throw new \InvalidArgumentException('额度过大（上限 100000）');
        }
        $exist = DB::selectOne('SELECT id FROM admin_users WHERE id=?', [$userId]);
        if (!$exist) {
            throw new \InvalidArgumentException('用户不存在');
        }
        DB::query(
            'UPDATE admin_users SET browser_env_quota=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$quota, $userId]
        );
    }

    /** 中心库已占用：自研桌面 + Linux（不含比特），包含已绑账号环境 + 待发布注册环境 */
    public static function countUsed(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $map = self::mapForUsers([$userId]);
        return (int)($map[$userId]['used'] ?? 0);
    }

    /** @return array{userId:int,quota:int,used:int,remaining:int} */
    public static function snapshot(int $userId): array
    {
        $quota = self::getQuota($userId);
        $used = self::countUsed($userId);
        return [
            'userId' => $userId,
            'quota' => $quota,
            'used' => $used,
            'remaining' => max(0, $quota - $used),
        ];
    }

    /**
     * 新建自研/Linux 环境绑定前校验额度（比特不计入）。
     * @throws \RuntimeException
     */
    public static function assertCanAllocate(int $ownerUserId, string $browserBackend): void
    {
        $backend = strtolower(trim($browserBackend));
        if ($backend !== 'tkswarm' && $backend !== 'linux') {
            return;
        }
        if ($ownerUserId <= 0) {
            return;
        }
        $snap = self::snapshot($ownerUserId);
        if ($snap['used'] >= $snap['quota']) {
            throw new \RuntimeException(
                "管理用户 #{$ownerUserId} 自研浏览器环境额度已满（{$snap['used']}/{$snap['quota']}）。请联系超级管理员调整额度"
            );
        }
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array{quota:int,used:int,remaining:int}>
     */
    public static function mapForUsers(array $userIds): array
    {
        self::ensureSchema();
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($x) => $x > 0)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $quotaRows = DB::select("SELECT id, browser_env_quota FROM admin_users WHERE id IN ({$ph})", $ids) ?: [];
        $quotaMap = [];
        foreach ($quotaRows as $r) {
            $raw = $r['browser_env_quota'] ?? null;
            $q = $raw === null ? self::DEFAULT_QUOTA : (int)$raw;
            $quotaMap[(int)$r['id']] = $q >= 0 ? $q : self::DEFAULT_QUOTA;
        }
        AccountBrowserEnvService::ensureSchema();
        AccountBrowserEnvService::backfillFromAccounts();

        // 按有效归属（owner_user_id > 0 优先，否则回退到关联账号 a.owner_id）统计已绑定的自研/Linux 唯一环境
        $profileSets = [];
        $envRows = DB::select(
            "SELECT COALESCE(NULLIF(e.owner_user_id, 0), NULLIF(a.owner_id, 0), 0) AS uid,
                    TRIM(e.browser_profile_id) AS pid
             FROM account_browser_envs e
             LEFT JOIN accounts a ON a.id = e.account_id
             WHERE e.browser_backend IN ('tkswarm','linux')
               AND e.browser_profile_id IS NOT NULL
               AND TRIM(e.browser_profile_id) <> ''
               AND COALESCE(NULLIF(e.owner_user_id, 0), NULLIF(a.owner_id, 0), 0) IN ({$ph})",
            $ids
        ) ?: [];
        foreach ($envRows as $r) {
            $uid = (int)($r['uid'] ?? 0);
            $pid = (string)($r['pid'] ?? '');
            if ($uid > 0 && $pid !== '') {
                $profileSets[$uid][$pid] = true;
            }
        }

        // 同时统计 register_envs（注册环境）中已创建但尚未删除的自研/Linux 环境（与已发布绑定按 profile_id 自动去重）
        try {
            $regRows = DB::select(
                "SELECT owner_id AS uid, TRIM(browser_profile_id) AS pid
                 FROM register_envs
                 WHERE owner_id IN ({$ph})
                   AND browser_backend IN ('tkswarm','linux')
                   AND browser_profile_id IS NOT NULL
                   AND TRIM(browser_profile_id) <> ''",
                $ids
            ) ?: [];
            foreach ($regRows as $r) {
                $uid = (int)($r['uid'] ?? 0);
                $pid = (string)($r['pid'] ?? '');
                if ($uid > 0 && $pid !== '') {
                    $profileSets[$uid][$pid] = true;
                }
            }
        } catch (\Throwable $e) {
            // register_envs 表尚未初始化时忽略
        }

        $out = [];
        foreach ($ids as $id) {
            $quota = $quotaMap[$id] ?? self::DEFAULT_QUOTA;
            $used = isset($profileSets[$id]) ? count($profileSets[$id]) : 0;
            $out[$id] = [
                'quota' => $quota,
                'used' => $used,
                'remaining' => max(0, $quota - $used),
            ];
        }
        return $out;
    }
}
