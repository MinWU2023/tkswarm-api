<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 账号 ↔ 浏览器环境多绑定 + 饱和态租约。
 * 类型：bit（比特）/ tkswarm（自研桌面）/ linux（Linux Browser）。
 * 登录用户：tkswarm-api admin_users（两浏览器通用）。
 * idle=待使用 / busy=执行中（总任务占用）。
 */
class AccountBrowserEnvService
{
    /** @return 'bit'|'tkswarm'|'linux' */
    public static function normalizeBackend(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === 'linux' || $v === 'tkswarm-linux' || $v === 'tkswarm-linuxbrowse') {
            return 'linux';
        }
        if ($v === 'tkswarm' || $v === 'tkswarm-browses' || $v === 'desktop') {
            return 'tkswarm';
        }
        return 'bit';
    }

    public static function backendLabel(string $backend): string
    {
        return match (self::normalizeBackend($backend)) {
            'linux' => 'Linux Browser',
            'tkswarm' => '第一页自研浏览器',
            default => '自研浏览器',
        };
    }

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        DB::query(
            "CREATE TABLE IF NOT EXISTS account_browser_envs (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              owner_user_id INT NOT NULL DEFAULT 0,
              browser_type VARCHAR(32) NOT NULL DEFAULT 'bit',
              browser_backend VARCHAR(16) NOT NULL DEFAULT 'bit',
              browser_profile_id VARCHAR(128) NOT NULL,
              saturation VARCHAR(16) NOT NULL DEFAULT 'idle',
              locked_by VARCHAR(128) NOT NULL DEFAULT '',
              batch_id INT NULL,
              lease_until DATETIME NULL,
              label VARCHAR(64) NOT NULL DEFAULT '',
              is_primary TINYINT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uk_abe_profile (browser_profile_id),
              KEY idx_abe_account_sat (account_id, saturation),
              KEY idx_abe_lease (saturation, lease_until),
              KEY idx_abe_owner (owner_user_id),
              KEY idx_abe_backend (browser_backend)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM account_work_batches') ?: [], 'Field');
            if (!in_array('browser_env_id', $cols, true)) {
                DB::query('ALTER TABLE account_work_batches ADD COLUMN browser_env_id INT NULL AFTER account_id');
            }
        } catch (\Throwable $e) {
        }

        // 增量列：env / accounts
        try {
            $envCols = array_column(DB::select('SHOW COLUMNS FROM account_browser_envs') ?: [], 'Field');
            if (!in_array('browser_backend', $envCols, true)) {
                DB::query("ALTER TABLE account_browser_envs ADD COLUMN browser_backend VARCHAR(16) NOT NULL DEFAULT 'bit' AFTER browser_type");
            }
            if (!in_array('owner_user_id', $envCols, true)) {
                DB::query("ALTER TABLE account_browser_envs ADD COLUMN owner_user_id INT NOT NULL DEFAULT 0 AFTER account_id");
            }
            // 跨机环境包元数据
            $packCols = [
                'pack_path' => "ALTER TABLE account_browser_envs ADD COLUMN pack_path VARCHAR(512) NOT NULL DEFAULT '' AFTER label",
                'pack_sha256' => "ALTER TABLE account_browser_envs ADD COLUMN pack_sha256 VARCHAR(64) NOT NULL DEFAULT '' AFTER pack_path",
                'pack_bytes' => "ALTER TABLE account_browser_envs ADD COLUMN pack_bytes BIGINT NOT NULL DEFAULT 0 AFTER pack_sha256",
                'pack_revision' => "ALTER TABLE account_browser_envs ADD COLUMN pack_revision INT NOT NULL DEFAULT 0 AFTER pack_bytes",
                'pack_updated_at' => "ALTER TABLE account_browser_envs ADD COLUMN pack_updated_at DATETIME NULL AFTER pack_revision",
                'pack_host_id' => "ALTER TABLE account_browser_envs ADD COLUMN pack_host_id VARCHAR(128) NOT NULL DEFAULT '' AFTER pack_updated_at",
            ];
            foreach ($packCols as $col => $sql) {
                if (!in_array($col, $envCols, true)) {
                    try {
                        DB::query($sql);
                        $envCols[] = $col;
                    } catch (\Throwable $e) {
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        try {
            $accCols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            if (!in_array('browser_backend', $accCols, true)) {
                DB::query("ALTER TABLE accounts ADD COLUMN browser_backend VARCHAR(16) NOT NULL DEFAULT 'bit' AFTER browser_profile_id");
            }
            if (!in_array('tkswarm_browser_profile_id', $accCols, true)) {
                DB::query("ALTER TABLE accounts ADD COLUMN tkswarm_browser_profile_id VARCHAR(128) NOT NULL DEFAULT '' AFTER browser_backend");
            }
            if (!in_array('linux_browser_profile_id', $accCols, true)) {
                DB::query("ALTER TABLE accounts ADD COLUMN linux_browser_profile_id VARCHAR(128) NOT NULL DEFAULT '' AFTER tkswarm_browser_profile_id");
            }
        } catch (\Throwable $e) {
        }

        self::backfillFromAccounts();
    }

    /** 从 accounts 旧列同步到 account_browser_envs（每次列表前可安全调用） */
    public static function backfillFromAccounts(): void
    {
        self::ensureSchema();
        try {
            DB::query(
                "INSERT IGNORE INTO account_browser_envs (account_id, owner_user_id, browser_profile_id, is_primary, saturation, browser_backend, browser_type)
                 SELECT id, COALESCE(owner_id, 0), browser_profile_id, 1, 'idle',
                        COALESCE(NULLIF(browser_backend,''), 'bit'),
                        COALESCE(NULLIF(browser_backend,''), 'bit')
                 FROM accounts
                 WHERE browser_profile_id IS NOT NULL AND TRIM(browser_profile_id) <> ''"
            );
        } catch (\Throwable $e) {
        }
        try {
            $accCols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            if (in_array('tkswarm_browser_profile_id', $accCols, true)) {
                DB::query(
                    "INSERT IGNORE INTO account_browser_envs (account_id, owner_user_id, browser_profile_id, is_primary, saturation, browser_backend, browser_type)
                     SELECT id, COALESCE(owner_id, 0), tkswarm_browser_profile_id, 0, 'idle', 'tkswarm', 'tkswarm'
                     FROM accounts
                     WHERE tkswarm_browser_profile_id IS NOT NULL AND TRIM(tkswarm_browser_profile_id) <> ''"
                );
            }
            if (in_array('linux_browser_profile_id', $accCols, true)) {
                DB::query(
                    "INSERT IGNORE INTO account_browser_envs (account_id, owner_user_id, browser_profile_id, is_primary, saturation, browser_backend, browser_type)
                     SELECT id, COALESCE(owner_id, 0), linux_browser_profile_id, 0, 'idle', 'linux', 'linux'
                     FROM accounts
                     WHERE linux_browser_profile_id IS NOT NULL AND TRIM(linux_browser_profile_id) <> ''"
                );
            }
        } catch (\Throwable $e) {
        }
        try {
            DB::query(
                "UPDATE account_browser_envs e
                 INNER JOIN accounts a ON a.id = e.account_id
                 SET e.owner_user_id = COALESCE(a.owner_id, 0)
                 WHERE e.owner_user_id = 0 AND a.owner_id IS NOT NULL AND a.owner_id > 0"
            );
        } catch (\Throwable $e) {
        }
    }

    /** @return list<array<string,mixed>> */
    public static function listByAccount(int $accountId): array
    {
        self::ensureSchema();
        return DB::select(
            'SELECT * FROM account_browser_envs WHERE account_id=? ORDER BY is_primary DESC, id ASC',
            [$accountId]
        ) ?: [];
    }

    /**
     * 各后端已绑环境 ID 列表（新 → 旧），供 Node 在旧环境已删时自动换用再绑的新环境。
     *
     * @return array{bit:list<string>,tkswarm:list<string>,linux:list<string>}
     */
    public static function listProfileIdsByBackend(int $accountId): array
    {
        self::ensureSchema();
        $out = ['bit' => [], 'tkswarm' => [], 'linux' => []];
        $rows = DB::select(
            'SELECT browser_backend, browser_profile_id FROM account_browser_envs WHERE account_id=? ORDER BY id DESC',
            [$accountId]
        ) ?: [];
        foreach ($rows as $row) {
            $b = self::normalizeBackend((string)($row['browser_backend'] ?? ''));
            $pid = trim((string)($row['browser_profile_id'] ?? ''));
            if ($pid === '' || !isset($out[$b])) {
                continue;
            }
            $out[$b][] = $pid;
        }

        return $out;
    }

    /**
     * 从 account_browser_envs + accounts 分类型列汇总各后端「当前默认」环境 ID。
     * 再绑追加时优先用该后端最新一条（id 最大），避免仍指向已删的旧环境。
     *
     * @return array{bit:string,tkswarm:string,linux:string,env_count:int}
     */
    public static function resolveTypedProfileIds(int $accountId): array
    {
        self::ensureSchema();
        $acc = DB::selectOne(
            'SELECT browser_profile_id, browser_backend, tkswarm_browser_profile_id, linux_browser_profile_id FROM accounts WHERE id=?',
            [$accountId]
        ) ?: [];
        $lists = self::listProfileIdsByBackend($accountId);
        $out = [
            'bit' => $lists['bit'][0] ?? '',
            'tkswarm' => $lists['tkswarm'][0] ?? '',
            'linux' => $lists['linux'][0] ?? '',
        ];
        // 账号主绑行：仅在该后端尚无 env 行时回退主列
        $be = self::normalizeBackend((string)($acc['browser_backend'] ?? ''));
        $primary = trim((string)($acc['browser_profile_id'] ?? ''));
        if ($out['bit'] === '' && $be === 'bit' && $primary !== '') {
            $out['bit'] = $primary;
        }
        if ($out['tkswarm'] === '') {
            $out['tkswarm'] = trim((string)($acc['tkswarm_browser_profile_id'] ?? ''));
        }
        if ($out['linux'] === '') {
            $out['linux'] = trim((string)($acc['linux_browser_profile_id'] ?? ''));
        }
        $envCount = (int)(DB::selectOne(
            'SELECT COUNT(*) AS c FROM account_browser_envs WHERE account_id=?',
            [$accountId]
        )['c'] ?? 0);

        return [
            'bit' => $out['bit'],
            'tkswarm' => $out['tkswarm'],
            'linux' => $out['linux'],
            'env_count' => $envCount,
        ];
    }

    /**
     * 绑定环境到账号（同一 profile 全局唯一；账号可绑多个）。
     * @param string $browserBackend bit|tkswarm|linux
     * @param int|null $ownerUserIdOverride 超管再绑时可指定耗用额度的管理用户
     * @return array<string,mixed>
     */
    public static function bind(
        int $accountId,
        string $profileId,
        bool $asPrimary = false,
        string $label = '',
        string $browserBackend = 'bit',
        ?int $ownerUserIdOverride = null
    ): array {
        self::ensureSchema();
        $profileId = trim($profileId);
        $backend = self::normalizeBackend($browserBackend);
        if ($accountId < 1 || $profileId === '') {
            throw new \InvalidArgumentException('账号或环境 ID 无效');
        }
        $acc = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
        if (!$acc) {
            throw new \InvalidArgumentException('账号不存在');
        }
        if ($ownerUserIdOverride !== null && $ownerUserIdOverride > 0) {
            if (!DataScope::isLoginSuper()) {
                throw new \RuntimeException('仅超级管理员可为环境指定管理用户归属');
            }
            $ownerUserId = $ownerUserIdOverride;
        } else {
            $ownerUserId = DataScope::resolveBrowserEnvOwnerUserId((int)($acc['owner_id'] ?? 0));
        }

        $taken = DB::selectOne(
            'SELECT id, account_id FROM account_browser_envs WHERE browser_profile_id=? LIMIT 1',
            [$profileId]
        );
        if ($taken && (int)$taken['account_id'] !== $accountId) {
            throw new \RuntimeException('该环境已绑定其他账号 #' . (int)$taken['account_id']);
        }
        if ($taken && (int)$taken['account_id'] === $accountId) {
            DB::query(
                'UPDATE account_browser_envs SET browser_backend=?, browser_type=?, owner_user_id=?, label=COALESCE(NULLIF(?, ""), label), updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$backend, $backend, $ownerUserId, $label, (int)$taken['id']]
            );
            if ($asPrimary) {
                DB::query('UPDATE account_browser_envs SET is_primary=0 WHERE account_id=?', [$accountId]);
                DB::query('UPDATE account_browser_envs SET is_primary=1 WHERE id=?', [(int)$taken['id']]);
                self::syncAccountPrimaryColumns($accountId, $profileId, $backend);
            } else {
                self::syncTypedProfileColumn($accountId, $profileId, $backend);
            }
            return DB::selectOne('SELECT * FROM account_browser_envs WHERE id=?', [(int)$taken['id']]) ?: $taken;
        }

        $count = (int)(DB::selectOne(
            'SELECT COUNT(*) AS c FROM account_browser_envs WHERE account_id=?',
            [$accountId]
        )['c'] ?? 0);
        $isPrimary = $asPrimary || $count === 0 ? 1 : 0;
        if ($isPrimary) {
            DB::query('UPDATE account_browser_envs SET is_primary=0 WHERE account_id=?', [$accountId]);
        }

        DB::query(
            "INSERT INTO account_browser_envs
             (account_id, owner_user_id, browser_type, browser_backend, browser_profile_id, saturation, label, is_primary)
             VALUES (?, ?, ?, ?, ?, 'idle', ?, ?)",
            [$accountId, $ownerUserId, $backend, $backend, $profileId, $label, $isPrimary]
        );
        $id = (int)DB::getPdo()->lastInsertId();

        if ($isPrimary) {
            self::syncAccountPrimaryColumns($accountId, $profileId, $backend);
        } else {
            self::syncTypedProfileColumn($accountId, $profileId, $backend);
        }

        return DB::selectOne('SELECT * FROM account_browser_envs WHERE id=?', [$id]) ?: [
            'id' => $id,
            'account_id' => $accountId,
            'owner_user_id' => $ownerUserId,
            'browser_profile_id' => $profileId,
            'browser_backend' => $backend,
            'browser_type' => $backend,
        ];
    }

    /** 非主绑定时更新分类型列：指向该后端当前使用的环境（account_browser_envs 仍保留全部历史行） */
    private static function syncTypedProfileColumn(int $accountId, string $profileId, string $backend): void
    {
        $col = self::typedColumn($backend);
        if ($col === '') {
            return;
        }
        DB::query(
            "UPDATE accounts SET {$col}=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$profileId, $accountId]
        );
    }

    /** 主环境同步到 accounts 旧列 + 分类型列 */
    private static function syncAccountPrimaryColumns(int $accountId, string $profileId, string $backend): void
    {
        $backend = self::normalizeBackend($backend);
        $hasBit = (bool)DB::selectOne(
            "SELECT 1 AS ok FROM account_browser_envs WHERE account_id=? AND browser_backend='bit' LIMIT 1",
            [$accountId]
        );
        if ($backend === 'tkswarm') {
            if ($hasBit) {
                // 已有比特环境：只切执行后端到自研，不覆盖 browser_profile_id（比特 ID 仍保留）
                DB::query(
                    "UPDATE accounts SET browser_type='tkswarm', browser_backend='tkswarm',
                     tkswarm_browser_profile_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                    [$profileId, $accountId]
                );
                return;
            }
            DB::query(
                "UPDATE accounts SET browser_type='tkswarm', browser_backend='tkswarm',
                 browser_profile_id=?, tkswarm_browser_profile_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$profileId, $profileId, $accountId]
            );
            return;
        }
        if ($backend === 'linux') {
            $hasOther = (bool)DB::selectOne(
                "SELECT 1 AS ok FROM account_browser_envs WHERE account_id=? AND browser_backend IN ('bit','tkswarm') LIMIT 1",
                [$accountId]
            );
            if ($hasOther) {
                DB::query(
                    "UPDATE accounts SET browser_type='linux', browser_backend='linux',
                     linux_browser_profile_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                    [$profileId, $accountId]
                );
                return;
            }
            DB::query(
                "UPDATE accounts SET browser_type='linux', browser_backend='linux',
                 browser_profile_id=?, linux_browser_profile_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$profileId, $profileId, $accountId]
            );
            return;
        }
        DB::query(
            "UPDATE accounts SET browser_type='bit', browser_backend='bit',
             browser_profile_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$profileId, $accountId]
        );
    }

    /** 后端对应的分类型环境列 */
    private static function typedColumn(string $backend): string
    {
        return match (self::normalizeBackend($backend)) {
            'tkswarm' => 'tkswarm_browser_profile_id',
            'linux' => 'linux_browser_profile_id',
            default => '',
        };
    }

    /**
     * 编辑账号时按所选后端落位环境绑定：
     * - accounts：browser_type / browser_backend / browser_profile_id + 该后端分类型列
     * - account_browser_envs：该后端的主环境记录（其他后端不受影响）
     * 不校验环境是否真实存在于对应客户端（前端选择 + Node 侧创建/绑定保证）。
     *
     * @return array{browser_backend:string,browser_profile_id:string,typed_column:string}
     */
    public static function applyAccountBackend(int $accountId, string $backend, string $profileId): array
    {
        self::ensureSchema();
        $backend = self::normalizeBackend($backend);
        $profileId = trim($profileId);
        $acc = $accountId > 0 ? DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]) : null;
        if (!$acc) {
            throw new \InvalidArgumentException('账号不存在');
        }
        $col = self::typedColumn($backend);

        $taken = $profileId === '' ? null : DB::selectOne(
            'SELECT id, account_id FROM account_browser_envs WHERE browser_profile_id=? LIMIT 1',
            [$profileId]
        );
        if ($taken && (int)$taken['account_id'] !== $accountId) {
            throw new \RuntimeException('环境 ' . $profileId . ' 已绑定账号 #' . (int)$taken['account_id']);
        }

        // 1) accounts 主列按后端落位
        $sets = ['browser_type=?', 'browser_backend=?', 'browser_profile_id=?'];
        $args = [$backend, $backend, $profileId];
        if ($col !== '') {
            $sets[] = $col . '=?';
            $args[] = $profileId;
        }
        $args[] = $accountId;
        DB::query(
            'UPDATE accounts SET ' . implode(', ', $sets) . ', updated_at=CURRENT_TIMESTAMP WHERE id=?',
            $args
        );

        // 2) 只有「清空环境 ID」时才移除该后端记录；指定环境时保留该后端其他环境（支持一账号多环境）
        if ($profileId === '') {
            DB::query(
                'DELETE FROM account_browser_envs WHERE account_id=? AND browser_backend=?',
                [$accountId, $backend]
            );
        }

        if ($profileId !== '') {
            $ownerUserId = DataScope::resolveBrowserEnvOwnerUserId((int)($acc['owner_id'] ?? 0));
            DB::query('UPDATE account_browser_envs SET is_primary=0 WHERE account_id=?', [$accountId]);
            if ($taken) {
                DB::query(
                    'UPDATE account_browser_envs
                     SET browser_backend=?, browser_type=?, owner_user_id=?, is_primary=1, updated_at=CURRENT_TIMESTAMP
                     WHERE id=?',
                    [$backend, $backend, $ownerUserId, (int)$taken['id']]
                );
            } else {
                DB::query(
                    "INSERT INTO account_browser_envs
                     (account_id, owner_user_id, browser_type, browser_backend, browser_profile_id, saturation, label, is_primary)
                     VALUES (?, ?, ?, ?, ?, 'idle', '', 1)",
                    [$accountId, $ownerUserId, $backend, $backend, $profileId]
                );
            }
        } else {
            // 该后端已无环境：主环境让给仍然存在的其他后端环境
            $first = DB::selectOne(
                'SELECT id FROM account_browser_envs WHERE account_id=? ORDER BY is_primary DESC, id ASC LIMIT 1',
                [$accountId]
            );
            if ($first) {
                DB::query('UPDATE account_browser_envs SET is_primary=1 WHERE id=?', [(int)$first['id']]);
            }
        }

        return ['browser_backend' => $backend, 'browser_profile_id' => $profileId, 'typed_column' => $col];
    }

    /** 删除环境行对应的中心库环境包文件（忽略失败） */
    private static function unlinkPackFilesForRows(array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            $path = trim((string)($row['pack_path'] ?? ''));
            if ($path === '' || !is_file($path)) {
                continue;
            }
            if (@unlink($path)) {
                $n += 1;
            }
        }
        return $n;
    }

    /**
     * 超管维护：清空中心库全部环境绑定 + 环境包文件 + 账号上的环境 ID 列。
     * 不删本机浏览器实体（本机需 Node /browser/profiles/batch-delete）。
     *
     * @return array{envRows:int,packFiles:int,accountsCleared:int,packDirCleared:int}
     */
    public static function wipeAllCenterEnvData(): array
    {
        self::ensureSchema();
        $rows = DB::select('SELECT id, pack_path FROM account_browser_envs') ?: [];
        $packFiles = self::unlinkPackFilesForRows($rows);
        $envRows = count($rows);
        DB::query('DELETE FROM account_browser_envs');

        // 清掉账号上残留的环境 ID，防止 backfill 再写回
        $accountsCleared = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM accounts
             WHERE TRIM(COALESCE(browser_profile_id,''))<>''
                OR TRIM(COALESCE(tkswarm_browser_profile_id,''))<>''
                OR TRIM(COALESCE(linux_browser_profile_id,''))<>''
                OR COALESCE(browser_backend,'') NOT IN ('','bit')"
        )['c'] ?? 0);
        DB::query(
            "UPDATE accounts SET browser_profile_id='', browser_backend='bit',
             tkswarm_browser_profile_id='', linux_browser_profile_id='',
             updated_at=CURRENT_TIMESTAMP
             WHERE TRIM(COALESCE(browser_profile_id,''))<>''
                OR TRIM(COALESCE(tkswarm_browser_profile_id,''))<>''
                OR TRIM(COALESCE(linux_browser_profile_id,''))<>''
                OR COALESCE(browser_backend,'') NOT IN ('','bit')"
        );

        // 再扫一遍包目录，删掉孤儿 .tgz
        $packDirCleared = 0;
        $dir = self::packStorageDir();
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($full) && preg_match('/\.(tgz|tar\.gz|tkpack)$/i', $name)) {
                    if (@unlink($full)) {
                        $packDirCleared += 1;
                    }
                }
            }
        }

        return [
            'envRows' => $envRows,
            'packFiles' => $packFiles,
            'accountsCleared' => $accountsCleared,
            'packDirCleared' => $packDirCleared,
        ];
    }

    /** 解绑：可按 profile 或清空该账号全部 */
    public static function unbind(int $accountId, ?string $profileId = null): void
    {
        self::ensureSchema();
        if ($profileId !== null && trim($profileId) !== '') {
            $doomed = DB::select(
                'SELECT pack_path FROM account_browser_envs WHERE account_id=? AND browser_profile_id=?',
                [$accountId, trim($profileId)]
            ) ?: [];
            self::unlinkPackFilesForRows($doomed);
            DB::query(
                'DELETE FROM account_browser_envs WHERE account_id=? AND browser_profile_id=?',
                [$accountId, trim($profileId)]
            );
        } else {
            $doomed = DB::select(
                'SELECT pack_path FROM account_browser_envs WHERE account_id=?',
                [$accountId]
            ) ?: [];
            self::unlinkPackFilesForRows($doomed);
            DB::query('DELETE FROM account_browser_envs WHERE account_id=?', [$accountId]);
        }

        $primary = DB::selectOne(
            "SELECT browser_profile_id, browser_backend FROM account_browser_envs
             WHERE account_id=? ORDER BY is_primary DESC, id ASC LIMIT 1",
            [$accountId]
        );
        $primaryBackend = self::normalizeBackend((string)($primary['browser_backend'] ?? 'bit'));
        $primaryId = (string)($primary['browser_profile_id'] ?? '');
        if ($primaryId !== '') {
            self::syncAccountPrimaryColumns($accountId, $primaryId, $primaryBackend);
            DB::query(
                'UPDATE account_browser_envs SET is_primary=1 WHERE account_id=? AND browser_profile_id=?',
                [$accountId, $primaryId]
            );
        } else {
            // 全部解绑：主列 + 分类型列一并清空，避免 backfill 再从旧列恢复绑定
            DB::query(
                "UPDATE accounts SET browser_profile_id='', browser_backend='bit',
                 tkswarm_browser_profile_id='', linux_browser_profile_id='',
                 updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$accountId]
            );
        }
        // 刷新分类型列：解绑后保留仍存在的该类型环境
        if ($profileId !== null && trim($profileId) !== '') {
            foreach (['tkswarm' => 'tkswarm_browser_profile_id', 'linux' => 'linux_browser_profile_id'] as $be => $col) {
                $still = DB::selectOne(
                    "SELECT browser_profile_id FROM account_browser_envs
                     WHERE account_id=? AND browser_backend=?
                     ORDER BY is_primary DESC, id ASC LIMIT 1",
                    [$accountId, $be]
                );
                DB::query(
                    "UPDATE accounts SET {$col}=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                    [(string)($still['browser_profile_id'] ?? ''), $accountId]
                );
            }
        }
    }

    /**
     * 领取一个空闲环境；无空闲返回 null。
     * @param string|null $preferredBackend bit|tkswarm|linux
     * @return array<string,mixed>|null
     */
    public static function leaseEnv(
        int $accountId,
        int $batchId,
        string $workerId,
        int $leaseSeconds = 300,
        ?string $preferredBackend = null
    ): ?array {
        self::ensureSchema();
        self::reclaimExpiredEnvLeases();

        // 同机仅占了 packhost 租约时，让给本机工作批（避免「本机开过窗 → 任务领不到 idle」）
        $sameHost = self::hostIdFromWorkerOrLock($workerId);
        if ($sameHost !== '') {
            DB::query(
                "UPDATE account_browser_envs
                 SET saturation='idle', locked_by='', batch_id=NULL, lease_until=NULL, updated_at=CURRENT_TIMESTAMP
                 WHERE account_id=? AND saturation='busy' AND locked_by=?",
                [$accountId, self::PACK_LEASE_PREFIX . $sameHost]
            );
        }

        $prefer = strtolower(trim((string)$preferredBackend));
        if ($prefer === 'linux' || $prefer === 'tkswarm' || $prefer === 'bit') {
            $prefer = self::normalizeBackend($prefer);
        } else {
            $prefer = '';
        }

        if ($prefer !== '') {
            $env = DB::selectOne(
                "SELECT * FROM account_browser_envs
                 WHERE account_id=? AND saturation='idle' AND browser_backend=?
                 ORDER BY is_primary DESC, id ASC
                 LIMIT 1",
                [$accountId, $prefer]
            );
            if (!$env) {
                // 任务指定了后端但无对应空闲环境：不回落到另一后端，避免错开浏览器
                return null;
            }
        } else {
            $env = DB::selectOne(
                "SELECT * FROM account_browser_envs
                 WHERE account_id=? AND saturation='idle'
                 ORDER BY is_primary DESC, id ASC
                 LIMIT 1",
                [$accountId]
            );
        }
        if (!$env) {
            return null;
        }

        $leaseUntil = date('Y-m-d H:i:s', time() + max(60, $leaseSeconds));
        DB::query(
            "UPDATE account_browser_envs
             SET saturation='busy',
                 locked_by=?,
                 batch_id=?,
                 lease_until=?,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND saturation='idle'",
            [$workerId, $batchId, $leaseUntil, (int)$env['id']]
        );
        $fresh = DB::selectOne('SELECT * FROM account_browser_envs WHERE id=?', [(int)$env['id']]);
        if (!$fresh || ($fresh['saturation'] ?? '') !== 'busy' || ($fresh['locked_by'] ?? '') !== $workerId) {
            return null;
        }

        DB::query(
            'UPDATE account_work_batches SET browser_env_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [(int)$fresh['id'], $batchId]
        );

        // 会话期内把旧列指到本次租用环境，便于 Node 现有读 bundle 逻辑
        DB::query(
            "UPDATE accounts SET browser_profile_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [(string)$fresh['browser_profile_id'], $accountId]
        );

        return $fresh;
    }

    public static function renewEnvLease(int $batchId, string $workerId, int $leaseSeconds = 300): void
    {
        self::ensureSchema();
        $leaseUntil = date('Y-m-d H:i:s', time() + max(60, $leaseSeconds));
        DB::query(
            "UPDATE account_browser_envs
             SET lease_until=?, updated_at=CURRENT_TIMESTAMP
             WHERE batch_id=? AND saturation='busy' AND locked_by=?",
            [$leaseUntil, $batchId, $workerId]
        );
    }

    public static function releaseEnvByBatch(int $batchId, ?string $workerId = null): void
    {
        self::ensureSchema();
        if ($workerId) {
            DB::query(
                "UPDATE account_browser_envs
                 SET saturation='idle', locked_by='', batch_id=NULL, lease_until=NULL, updated_at=CURRENT_TIMESTAMP
                 WHERE batch_id=? AND (locked_by=? OR locked_by='')",
                [$batchId, $workerId]
            );
        } else {
            DB::query(
                "UPDATE account_browser_envs
                 SET saturation='idle', locked_by='', batch_id=NULL, lease_until=NULL, updated_at=CURRENT_TIMESTAMP
                 WHERE batch_id=?",
                [$batchId]
            );
        }
        DB::query(
            'UPDATE account_work_batches SET browser_env_id=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$batchId]
        );
    }

    /**
     * 批量释放多个总任务占用的环境租用（任务汇总「勾选清除 / 全部清除」一次性回收）。
     * @param list<int> $batchIds
     */
    public static function releaseEnvByBatches(array $batchIds): void
    {
        $batchIds = array_values(array_unique(array_filter(
            array_map('intval', $batchIds),
            static fn($v) => $v > 0
        )));
        if (!$batchIds) {
            return;
        }
        self::ensureSchema();
        foreach (array_chunk($batchIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            DB::query(
                "UPDATE account_browser_envs
                 SET saturation='idle', locked_by='', batch_id=NULL, lease_until=NULL, updated_at=CURRENT_TIMESTAMP
                 WHERE batch_id IN ({$ph})",
                $chunk
            );
        }
    }

    public static function reclaimExpiredEnvLeases(): void
    {
        self::ensureSchema();
        DB::query(
            "UPDATE account_browser_envs
             SET saturation='idle', locked_by='', batch_id=NULL, lease_until=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE saturation='busy' AND lease_until IS NOT NULL AND lease_until < CURRENT_TIMESTAMP"
        );
    }

    public static function accountHasIdleEnv(int $accountId): bool
    {
        self::ensureSchema();
        $row = DB::selectOne(
            "SELECT id FROM account_browser_envs WHERE account_id=? AND saturation='idle' LIMIT 1",
            [$accountId]
        );
        return (bool)$row;
    }

    /**
     * 强制释放账号下全部 busy 环境（重新推送 / 终止重开）。
     * @return array{released:int,profiles:list<string>,batch_ids:list<int>}
     */
    public static function forceReleaseAccount(int $accountId): array
    {
        self::ensureSchema();
        if ($accountId < 1) {
            return ['released' => 0, 'profiles' => [], 'batch_ids' => []];
        }
        $rows = DB::select(
            "SELECT id, browser_profile_id, batch_id FROM account_browser_envs
             WHERE account_id=? AND (saturation='busy' OR batch_id IS NOT NULL)",
            [$accountId]
        ) ?: [];
        $profiles = [];
        $batchIds = [];
        foreach ($rows as $r) {
            $pid = trim((string)($r['browser_profile_id'] ?? ''));
            if ($pid !== '') {
                $profiles[] = $pid;
            }
            $bid = (int)($r['batch_id'] ?? 0);
            if ($bid > 0) {
                $batchIds[] = $bid;
            }
        }
        DB::query(
            "UPDATE account_browser_envs
             SET saturation='idle', locked_by='', batch_id=NULL, lease_until=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE account_id=?",
            [$accountId]
        );
        foreach (array_unique($batchIds) as $bid) {
            DB::query(
                'UPDATE account_work_batches SET browser_env_id=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [(int)$bid]
            );
        }
        return [
            'released' => count($rows),
            'profiles' => array_values(array_unique($profiles)),
            'batch_ids' => array_values(array_unique($batchIds)),
        ];
    }

    public const PACK_MAX_BYTES = 524288000; // 500MB
    public const PACK_LEASE_PREFIX = 'packhost:';

    /**
     * Node 工作批 workerId 形如 node-{hostname}；跨机包租约为 packhost:{hostname}。
     * 二者共用 saturation/locked_by，必须识别「同一台机器」以免自相冲突。
     */
    public static function hostIdFromWorkerOrLock(string $workerOrLock): string
    {
        $v = trim($workerOrLock);
        if ($v === '') {
            return '';
        }
        if (str_starts_with($v, self::PACK_LEASE_PREFIX)) {
            return substr($v, strlen(self::PACK_LEASE_PREFIX));
        }
        if (str_starts_with($v, 'node-')) {
            return substr($v, strlen('node-'));
        }
        return $v;
    }

    /** 当前 locked_by 是否表示本机已占用（工作批或包租约） */
    public static function isSameHostLock(string $lockedBy, string $hostId): bool
    {
        $hostId = trim($hostId);
        $lockedBy = trim($lockedBy);
        if ($hostId === '' || $lockedBy === '') {
            return false;
        }
        if ($lockedBy === self::PACK_LEASE_PREFIX . $hostId) {
            return true;
        }
        if ($lockedBy === 'node-' . $hostId) {
            return true;
        }
        if ($lockedBy === $hostId) {
            return true;
        }
        return self::hostIdFromWorkerOrLock($lockedBy) === $hostId;
    }

    public static function packStorageDir(): string
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'browser-env-packs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** @return array<string,mixed>|null */
    public static function findByProfileId(string $profileId): ?array
    {
        self::ensureSchema();
        $profileId = trim($profileId);
        if ($profileId === '') {
            return null;
        }
        $row = DB::selectOne(
            'SELECT * FROM account_browser_envs WHERE browser_profile_id=? LIMIT 1',
            [$profileId]
        );
        return $row ?: null;
    }

    /** @return array<string,mixed> */
    public static function packMeta(string $profileId): array
    {
        $env = self::findByProfileId($profileId);
        if (!$env) {
            return [
                'exists' => false,
                'hasPack' => false,
                'profileId' => $profileId,
            ];
        }
        $path = trim((string)($env['pack_path'] ?? ''));
        $hasFile = $path !== '' && is_file($path);
        $leaseUntil = (string)($env['lease_until'] ?? '');
        $lockedBy = (string)($env['locked_by'] ?? '');
        $leaseActive = ($env['saturation'] ?? '') === 'busy'
            && $leaseUntil !== ''
            && strtotime($leaseUntil) > time();
        return [
            'exists' => true,
            'hasPack' => $hasFile && (int)($env['pack_revision'] ?? 0) > 0,
            'profileId' => (string)$env['browser_profile_id'],
            'accountId' => (int)($env['account_id'] ?? 0),
            'envId' => (int)($env['id'] ?? 0),
            'packSha256' => (string)($env['pack_sha256'] ?? ''),
            'packBytes' => (int)($env['pack_bytes'] ?? 0),
            'packRevision' => (int)($env['pack_revision'] ?? 0),
            'packUpdatedAt' => $env['pack_updated_at'] ?? null,
            'packHostId' => (string)($env['pack_host_id'] ?? ''),
            'saturation' => (string)($env['saturation'] ?? 'idle'),
            'lockedBy' => $lockedBy,
            'leaseUntil' => $leaseUntil ?: null,
            'leaseActive' => $leaseActive,
            'leaseHolder' => $leaseActive ? $lockedBy : '',
        ];
    }

    /**
     * 独占租约：打开/拉取前占用；locked_by = packhost:{hostId}
     * 注意：与工作批租约共用 saturation/locked_by。若本机工作批已占用（node-{host}），
     * 视为同机兼容，直接放行且不改写 locked_by，否则会破坏批心跳/释放。
     * @return array{ok:bool,message?:string,meta?:array<string,mixed>}
     */
    public static function acquirePackLease(string $profileId, string $hostId, int $leaseSeconds = 7200): array
    {
        self::ensureSchema();
        self::reclaimExpiredEnvLeases();
        $hostId = trim($hostId);
        if ($hostId === '') {
            return ['ok' => false, 'message' => '缺少 hostId'];
        }
        $env = self::findByProfileId($profileId);
        if (!$env) {
            return ['ok' => false, 'message' => '环境绑定不存在'];
        }
        $ourLock = self::PACK_LEASE_PREFIX . $hostId;
        $lockedBy = (string)($env['locked_by'] ?? '');
        $leaseUntil = (string)($env['lease_until'] ?? '');
        $busy = ($env['saturation'] ?? '') === 'busy'
            && $leaseUntil !== ''
            && strtotime($leaseUntil) > time();

        if ($busy && $lockedBy !== '' && $lockedBy !== $ourLock) {
            // 本机 Node 工作批已占用该环境：打开/拉包是同机操作，不应报「其它主机」
            if (self::isSameHostLock($lockedBy, $hostId)) {
                return [
                    'ok' => true,
                    'meta' => self::packMeta($profileId),
                    'coexistWithBatch' => true,
                ];
            }
            $holderHost = self::hostIdFromWorkerOrLock($lockedBy);
            $hint = $holderHost !== '' ? $holderHost : $lockedBy;
            return [
                'ok' => false,
                'message' => '该环境正在其它主机使用（' . $hint . '），请稍后再试或等对方关闭后上传',
                'meta' => self::packMeta($profileId),
            ];
        }

        $until = date('Y-m-d H:i:s', time() + max(120, $leaseSeconds));
        DB::query(
            "UPDATE account_browser_envs
             SET saturation='busy', locked_by=?, lease_until=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$ourLock, $until, (int)$env['id']]
        );
        return ['ok' => true, 'meta' => self::packMeta($profileId)];
    }

    /** @return array{ok:bool,message?:string,meta?:array<string,mixed>} */
    public static function releasePackLease(string $profileId, string $hostId, bool $force = false): array
    {
        self::ensureSchema();
        $env = self::findByProfileId($profileId);
        if (!$env) {
            return ['ok' => false, 'message' => '环境绑定不存在'];
        }
        $hostId = trim($hostId);
        $ourLock = self::PACK_LEASE_PREFIX . $hostId;
        $lockedBy = (string)($env['locked_by'] ?? '');
        if (!$force && $lockedBy !== '' && $lockedBy !== $ourLock && str_starts_with($lockedBy, self::PACK_LEASE_PREFIX)) {
            return ['ok' => false, 'message' => '租约属于其它主机，无法释放'];
        }
        // 本机工作批占用中：pack 释放不能清掉批锁
        if (!$force && $lockedBy !== '' && !str_starts_with($lockedBy, self::PACK_LEASE_PREFIX)
            && self::isSameHostLock($lockedBy, $hostId)) {
            return ['ok' => true, 'meta' => self::packMeta($profileId), 'skippedBatchLock' => true];
        }
        // 仅释放 pack 租约；工作批任务租约留给原逻辑
        if ($lockedBy === $ourLock || $force || str_starts_with($lockedBy, self::PACK_LEASE_PREFIX) || $lockedBy === '') {
            DB::query(
                "UPDATE account_browser_envs
                 SET saturation='idle', locked_by='', lease_until=NULL, updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND (locked_by=? OR locked_by='' OR ?=1)",
                [(int)$env['id'], $ourLock, $force ? 1 : 0]
            );
        }
        return ['ok' => true, 'meta' => self::packMeta($profileId)];
    }

    /**
     * 保存上传的环境包到磁盘并更新元数据。
     * @return array{ok:bool,message?:string,meta?:array<string,mixed>}
     */
    public static function saveUploadedPack(string $profileId, string $tmpFile, string $hostId = ''): array
    {
        self::ensureSchema();
        $env = self::findByProfileId($profileId);
        if (!$env) {
            return ['ok' => false, 'message' => '环境绑定不存在，无法保存包'];
        }
        if (!is_file($tmpFile)) {
            return ['ok' => false, 'message' => '上传临时文件不存在'];
        }
        $bytes = (int)filesize($tmpFile);
        if ($bytes < 64) {
            return ['ok' => false, 'message' => '环境包过小或损坏'];
        }
        if ($bytes > self::PACK_MAX_BYTES) {
            return ['ok' => false, 'message' => '环境包超过 500MB 上限'];
        }
        $sha = hash_file('sha256', $tmpFile) ?: '';
        $dir = self::packStorageDir();
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $profileId) ?: ('env' . (int)$env['id']);
        $dest = $dir . DIRECTORY_SEPARATOR . $safeId . '.tgz';
        $old = trim((string)($env['pack_path'] ?? ''));
        if (!@rename($tmpFile, $dest) && !@copy($tmpFile, $dest)) {
            return ['ok' => false, 'message' => '保存环境包失败'];
        }
        @unlink($tmpFile);
        if ($old !== '' && $old !== $dest && is_file($old)) {
            @unlink($old);
        }
        $rev = (int)($env['pack_revision'] ?? 0) + 1;
        $hostId = mb_substr(trim($hostId), 0, 128);
        DB::query(
            "UPDATE account_browser_envs
             SET pack_path=?, pack_sha256=?, pack_bytes=?, pack_revision=?,
                 pack_updated_at=CURRENT_TIMESTAMP, pack_host_id=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$dest, $sha, $bytes, $rev, $hostId, (int)$env['id']]
        );
        // 上传成功 = 本机已交出最新包；清掉本机 packhost 租约（不动工作批 node-* 锁）
        if ($hostId !== '') {
            DB::query(
                "UPDATE account_browser_envs
                 SET saturation='idle', locked_by='', lease_until=NULL, updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND locked_by=?",
                [(int)$env['id'], self::PACK_LEASE_PREFIX . $hostId]
            );
        }
        return ['ok' => true, 'meta' => self::packMeta($profileId)];
    }
}
