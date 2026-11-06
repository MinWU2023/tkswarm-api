<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Database\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth\RBAC;
use App\Services\DataScope;
use App\Services\Schedule\ScheduleRunner;

/**
 * 开发管理 · NoCaptchaAI 密钥（需登录账号持有 system.nocaptcha；不受身份切换影响）
 * 规则：
 * 1. 同一时间只允许一条记录处于启用状态（status=1）；
 * 2. 每个账号每天最多允许使用 200 次（quota_limit 默认 200），达到 200 当日不可用（is_exhausted=1, status=0）并自动切换启用下一个可用账号；
 * 3. 计划任务次日重置未报废账号的当日用量（used_count=0, is_exhausted=0）；
 * 4. 免费支持 30 天（valid_days 默认 30），超过 30 天直接标记报废（is_scrapped=1, status=0），永久不可再启用。
 */
class NocaptchaKeyController extends Controller
{
    private const DEFAULT_EMAIL = 'piwwuvnspt@imxwe.com';
    public const QUOTA_LIMIT = 200;
    public const VALID_DAYS = 30;

    private static bool $schemaEnsured = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $tableExisted = false;
        try {
            $hit = DB::select("SHOW TABLES LIKE 'nocaptcha_keys'") ?: [];
            $tableExisted = !empty($hit);
        } catch (\Throwable $e) {
            $tableExisted = false;
        }

        DB::query("CREATE TABLE IF NOT EXISTS `nocaptcha_keys` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL DEFAULT '',
            `username` VARCHAR(100) NOT NULL DEFAULT '',
            `password` VARCHAR(255) NOT NULL DEFAULT '',
            `api_key` VARCHAR(255) NOT NULL DEFAULT '',
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `is_exhausted` TINYINT(1) NOT NULL DEFAULT 0,
            `is_scrapped` TINYINT(1) NOT NULL DEFAULT 0,
            `used_count` INT NOT NULL DEFAULT 0,
            `total_used_count` INT NOT NULL DEFAULT 0,
            `quota_limit` INT NOT NULL DEFAULT 200,
            `valid_days` INT NOT NULL DEFAULT 30,
            `expires_at` DATETIME NULL DEFAULT NULL,
            `last_reset_date` DATE NULL DEFAULT NULL,
            `scrapped_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_nocaptcha_keys_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM `nocaptcha_keys`') ?: [], 'Field');
            if (!in_array('is_exhausted', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `is_exhausted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
            }
            $addedScrapped = false;
            if (!in_array('is_scrapped', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `is_scrapped` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_exhausted`');
                $addedScrapped = true;
            }
            if (!in_array('used_count', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `used_count` INT NOT NULL DEFAULT 0 AFTER `is_scrapped`');
            }
            if (!in_array('total_used_count', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `total_used_count` INT NOT NULL DEFAULT 0 AFTER `used_count`');
                DB::query('UPDATE `nocaptcha_keys` SET `total_used_count` = GREATEST(`total_used_count`, `used_count`)');
            }
            if (!in_array('quota_limit', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `quota_limit` INT NOT NULL DEFAULT 200 AFTER `total_used_count`');
            } elseif ($addedScrapped) {
                // 从旧版 6000 总额度升级到每日 200 额度时，自动将默认上限调整为 200
                DB::query('ALTER TABLE `nocaptcha_keys` MODIFY COLUMN `quota_limit` INT NOT NULL DEFAULT 200');
                DB::query('UPDATE `nocaptcha_keys` SET `quota_limit` = 200 WHERE `quota_limit` = 6000');
            }
            if (!in_array('valid_days', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `valid_days` INT NOT NULL DEFAULT 30 AFTER `quota_limit`');
            }
            if (!in_array('expires_at', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `expires_at` DATETIME NULL DEFAULT NULL AFTER `valid_days`');
            }
            if (!in_array('last_reset_date', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `last_reset_date` DATE NULL DEFAULT NULL AFTER `expires_at`');
            }
            if (!in_array('scrapped_at', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `scrapped_at` DATETIME NULL DEFAULT NULL AFTER `last_reset_date`');
            }

            // 为存量记录回填到期时间与最近重置日
            DB::query('UPDATE `nocaptcha_keys` SET `expires_at` = DATE_ADD(`created_at`, INTERVAL GREATEST(1, `valid_days`) DAY) WHERE `expires_at` IS NULL');
            DB::query('UPDATE `nocaptcha_keys` SET `last_reset_date` = CURDATE() WHERE `last_reset_date` IS NULL');
        } catch (\Throwable $e) {
            // ignore schema alter race
        }

        // 仅在首次建表时写入默认密钥；用户删除后不得因缺省邮箱自动回插（否则 Node 后台「删不掉」）
        if (!$tableExisted) {
            $hasEnabled = DB::selectOne('SELECT id FROM nocaptcha_keys WHERE status=1 LIMIT 1');
            $today = date('Y-m-d');
            $expiresAt = date('Y-m-d H:i:s', time() + self::VALID_DAYS * 86400);
            DB::query(
                'INSERT INTO nocaptcha_keys(email, username, password, api_key, status, is_exhausted, is_scrapped, used_count, total_used_count, quota_limit, valid_days, expires_at, last_reset_date) VALUES (?,?,?,?,?,0,0,0,0,?,?,?,?)',
                [
                    self::DEFAULT_EMAIL,
                    'piwwuvnsp',
                    'piwwuvnspt@imxwe.com',
                    'nocap_XrWSZYi3dyxKzr2zvtUxLzbM',
                    $hasEnabled ? 0 : 1,
                    self::QUOTA_LIMIT,
                    self::VALID_DAYS,
                    $expiresAt,
                    $today,
                ]
            );
        }

        self::$schemaEnsured = true;
    }

    /**
     * 判断某行记录是否已超过 30 天免费期或已被标记报废。
     * @param array<string,mixed> $row
     */
    public static function isRowScrappedOrExpired(array $row): bool
    {
        if ((int)($row['is_scrapped'] ?? 0) === 1) {
            return true;
        }
        $now = time();
        $expiresAt = trim((string)($row['expires_at'] ?? ''));
        if ($expiresAt !== '') {
            $expTs = strtotime($expiresAt);
            if ($expTs !== false && $expTs <= $now) {
                return true;
            }
        }
        $createdAt = trim((string)($row['created_at'] ?? ''));
        $validDays = max(1, (int)($row['valid_days'] ?? self::VALID_DAYS));
        if ($createdAt !== '') {
            $createdTs = strtotime($createdAt);
            if ($createdTs !== false && ($now - $createdTs) >= ($validDays * 86400)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 扫描并永久报废所有超过免费有效期（默认 30 天）的账号，报废后强制停用且不可再启用。
     */
    public static function scrapExpiredKeys(): int
    {
        self::ensureSchema();
        try {
            return DB::execute(
                'UPDATE `nocaptcha_keys`
                 SET `is_scrapped` = 1,
                     `status` = 0,
                     `is_exhausted` = 1,
                     `scrapped_at` = COALESCE(`scrapped_at`, NOW())
                 WHERE `is_scrapped` = 0
                   AND (
                     (`expires_at` IS NOT NULL AND `expires_at` <= NOW())
                     OR TIMESTAMPDIFF(SECOND, `created_at`, NOW()) >= GREATEST(1, `valid_days`) * 86400
                   )'
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 每日计划任务核心逻辑：
     * 1. 超过 30 天的账号直接标记报废（is_scrapped=1, status=0），永久不可再启用；
     * 2. 未报废账号重置当日使用次数（used_count=0, is_exhausted=0, last_reset_date=今天）；
     * 3. 若当前无启用中的可用账号，自动启用任意一条未报废且未用完的账号。
     *
     * @param bool $forceAllDates true=无论 last_reset_date 是否已是今天都重置未报废账号（用于 00:01 计划任务 / 手动立即执行）；
     *                            false=仅重置 last_reset_date < 今天 的跨日未重置记录（用于跨天幂等自检兜底）。
     * @return array{date:string,resetCount:int,scrappedCount:int,activeId:int,activeEmail:string}
     */
    public static function runDailyMaintenance(bool $forceAllDates = false): array
    {
        self::ensureSchema();
        $today = date('Y-m-d');
        $scrappedCount = self::scrapExpiredKeys();

        if ($forceAllDates) {
            $resetCount = DB::execute(
                'UPDATE `nocaptcha_keys`
                 SET `used_count` = 0,
                     `is_exhausted` = 0,
                     `last_reset_date` = ?
                 WHERE `is_scrapped` = 0',
                [$today]
            );
        } else {
            $resetCount = DB::execute(
                'UPDATE `nocaptcha_keys`
                 SET `used_count` = 0,
                     `is_exhausted` = 0,
                     `last_reset_date` = ?
                 WHERE `is_scrapped` = 0
                   AND (`last_reset_date` IS NULL OR `last_reset_date` < ?)',
                [$today, $today]
            );
        }

        // 确保如当前无启用且有效的密钥（例如前一日全部满 200 被停用，或刚因超 30 天报废），自动启用一条可用密钥
        $active = DB::selectOne(
            'SELECT * FROM `nocaptcha_keys`
             WHERE `status` = 1
               AND `is_scrapped` = 0
               AND `is_exhausted` = 0
               AND `used_count` < `quota_limit`
               AND `api_key` <> ""
             ORDER BY `id` DESC LIMIT 1'
        );
        if (!$active && ($forceAllDates || $resetCount > 0 || $scrappedCount > 0)) {
            $next = DB::selectOne(
                'SELECT * FROM `nocaptcha_keys`
                 WHERE `is_scrapped` = 0
                   AND `is_exhausted` = 0
                   AND `used_count` < `quota_limit`
                   AND `api_key` <> ""
                 ORDER BY `id` ASC LIMIT 1'
            );
            if ($next) {
                DB::query('UPDATE `nocaptcha_keys` SET `status` = 0 WHERE `status` = 1');
                DB::query('UPDATE `nocaptcha_keys` SET `status` = 1 WHERE `id` = ?', [(int)$next['id']]);
                $active = DB::selectOne('SELECT * FROM `nocaptcha_keys` WHERE `id` = ?', [(int)$next['id']]) ?: $next;
            }
        }

        return [
            'date' => $today,
            'resetCount' => $resetCount,
            'scrappedCount' => $scrappedCount,
            'activeId' => (int)($active['id'] ?? 0),
            'activeEmail' => (string)($active['email'] ?? ''),
        ];
    }

    /**
     * 轻量跨日维护钩子：
     * - 始终先执行超过 30 天自动报废检查；
     * - 若后台「计划任务 -> NoCaptchaAI 日额度重置与到期报废」处于开启状态且存在跨天未重置记录，自动补跑一次次日重置。
     */
    public static function ensureDailyState(): void
    {
        self::ensureSchema();
        $scrapped = self::scrapExpiredKeys();
        $dailyJobEnabled = true;
        try {
            $dailyJobEnabled = ScheduleRunner::isEnabled('nocaptcha_daily_reset');
        } catch (\Throwable $e) {
            $dailyJobEnabled = true;
        }
        if ($dailyJobEnabled) {
            $needReset = DB::selectOne(
                'SELECT `id` FROM `nocaptcha_keys`
                 WHERE `is_scrapped` = 0
                   AND (`last_reset_date` IS NULL OR `last_reset_date` < CURDATE())
                 LIMIT 1'
            );
            if ($needReset) {
                $stats = self::runDailyMaintenance(false);
                try {
                    DB::query(
                        'UPDATE `scheduled_jobs`
                         SET `last_run_at` = ?, `last_status` = ?, `last_message` = ?, `last_duration_ms` = 1
                         WHERE `job_key` = ?',
                        [
                            date('Y-m-d H:i:s'),
                            'ok',
                            sprintf('auto date=%s reset=%d scrapped=%d', $stats['date'], $stats['resetCount'], $stats['scrappedCount']),
                            'nocaptcha_daily_reset',
                        ]
                    );
                } catch (\Throwable $e) {
                }
                return;
            }
        }
        if ($scrapped > 0) {
            // 若刚有启用中的密钥因超 30 天被报废，自动切换到下一个可用密钥
            $hasValidActive = DB::selectOne(
                'SELECT `id` FROM `nocaptcha_keys`
                 WHERE `status` = 1 AND `is_scrapped` = 0 AND `is_exhausted` = 0 AND `used_count` < `quota_limit`
                 LIMIT 1'
            );
            if (!$hasValidActive) {
                self::exhaustAndSwitchNext(0);
            }
        }
    }

    /**
     * 获取当前启用（status=1、未报废且当日未满 200）的 NoCaptchaAI 密钥记录。
     * 若当前启用记录已超 30 天报废或当日已达 200，自动停用并切换到任意一条可用记录。
     * @return array<string,mixed>|null
     */
    public static function activeRecord(): ?array
    {
        try {
            self::ensureDailyState();
            $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE status=1 ORDER BY id DESC LIMIT 1');
            if ($row) {
                $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
                $used = max(0, (int)($row['used_count'] ?? 0));
                $scrapped = self::isRowScrappedOrExpired($row);
                $exhausted = $scrapped || (int)($row['is_exhausted'] ?? 0) === 1 || $used >= $limit;
                if (!$exhausted) {
                    return $row;
                }
                if ($scrapped) {
                    DB::query(
                        'UPDATE nocaptcha_keys SET is_scrapped=1, is_exhausted=1, status=0, scrapped_at=COALESCE(scrapped_at, NOW()) WHERE id=?',
                        [(int)$row['id']]
                    );
                }
                self::exhaustAndSwitchNext((int)$row['id'], $used);
                $row = DB::selectOne(
                    'SELECT * FROM nocaptcha_keys WHERE status=1 AND is_scrapped=0 AND is_exhausted=0 ORDER BY id DESC LIMIT 1'
                );
                return $row ?: null;
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 将指定记录标记为当日已满（或报废停用），并自动启用下一条未报废且当日未满 200 的记录。
     * @return array<string,mixed>|null 返回新启用的记录（若无可用则返回 null）
     */
    public static function exhaustAndSwitchNext(int $currentId = 0, ?int $forceUsedCount = null, bool $markScrapped = false): ?array
    {
        self::ensureSchema();
        self::scrapExpiredKeys();

        $run = function () use ($currentId, $forceUsedCount, $markScrapped) {
            if ($currentId > 0) {
                if ($markScrapped) {
                    DB::query(
                        'UPDATE nocaptcha_keys SET is_scrapped=1, is_exhausted=1, status=0, scrapped_at=COALESCE(scrapped_at, NOW()) WHERE id=?',
                        [$currentId]
                    );
                } elseif ($forceUsedCount !== null) {
                    DB::query(
                        'UPDATE nocaptcha_keys SET is_exhausted=1, status=0, used_count=GREATEST(used_count, ?) WHERE id=?',
                        [max(0, $forceUsedCount), $currentId]
                    );
                } else {
                    DB::query('UPDATE nocaptcha_keys SET is_exhausted=1, status=0 WHERE id=?', [$currentId]);
                }
            }

            // 同步把所有达到当日额度上限的记录标记为 is_exhausted=1, status=0
            DB::query('UPDATE nocaptcha_keys SET is_exhausted=1, status=0 WHERE is_scrapped=0 AND used_count >= quota_limit AND quota_limit > 0');
            // 同步把所有已报废记录强制 status=0
            DB::query('UPDATE nocaptcha_keys SET status=0 WHERE is_scrapped=1 AND status<>0');

            // 检查是否已有未报废且当日未满的启用记录
            $alreadyActive = DB::selectOne(
                'SELECT * FROM nocaptcha_keys
                 WHERE status=1
                   AND is_scrapped=0
                   AND (expires_at IS NULL OR expires_at > NOW())
                   AND is_exhausted=0
                   AND used_count < quota_limit
                   AND api_key<>""
                 ORDER BY id ASC LIMIT 1'
            );
            if ($alreadyActive) {
                return $alreadyActive;
            }

            // 选择任意一条未报废且当日未满的记录自动启用
            $next = DB::selectOne(
                'SELECT * FROM nocaptcha_keys
                 WHERE is_scrapped=0
                   AND (expires_at IS NULL OR expires_at > NOW())
                   AND is_exhausted=0
                   AND used_count < quota_limit
                   AND api_key<>""
                   AND id<>?
                 ORDER BY id ASC LIMIT 1',
                [$currentId]
            );
            if (!$next) {
                return null;
            }
            DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1');
            DB::query('UPDATE nocaptcha_keys SET status=1 WHERE id=?', [(int)$next['id']]);
            return DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [(int)$next['id']]) ?: null;
        };

        if (DB::getPdo()->inTransaction()) {
            return $run();
        }
        return DB::transaction($run);
    }

    /**
     * 上报一次或多次使用量；当日达到 200（或接口返回额度耗尽 / 超 30 天）自动切换下一个可用账号。
     * @return array<string,mixed>
     */
    public static function recordUsage(
        string $apiKey = '',
        int $id = 0,
        int $increment = 1,
        bool $exhausted = false,
        ?int $remoteUsed = null,
        bool $scrapped = false
    ): array {
        self::ensureDailyState();
        $row = null;
        if ($id > 0) {
            $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=? LIMIT 1', [$id]);
        }
        if (!$row && $apiKey !== '') {
            $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE api_key=? ORDER BY status DESC, id DESC LIMIT 1', [$apiKey]);
        }
        if (!$row) {
            $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE status=1 ORDER BY id DESC LIMIT 1');
        }
        if (!$row) {
            $active = self::activeRecord();
            return [
                'switched' => false,
                'exhausted' => false,
                'scrapped' => false,
                'active' => $active ? self::formatRowStatic($active) : null,
            ];
        }

        $cid = (int)$row['id'];
        $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
        $curUsed = max(0, (int)($row['used_count'] ?? 0));
        $curTotal = max(0, (int)($row['total_used_count'] ?? 0));
        $inc = max(0, $increment);
        $nextUsed = $curUsed + $inc;
        $nextTotal = $curTotal + $inc;
        if ($remoteUsed !== null && $remoteUsed > $nextUsed) {
            $nextUsed = $remoteUsed;
        }

        $nowScrapped = $scrapped || self::isRowScrappedOrExpired($row);
        $nowExhausted = $nowScrapped || $exhausted || ((int)($row['is_exhausted'] ?? 0) === 1) || ($nextUsed >= $limit);

        if ($inc > 0) {
            DB::query(
                'UPDATE nocaptcha_keys SET used_count=?, total_used_count=?, last_reset_date=COALESCE(last_reset_date, CURDATE()) WHERE id=?',
                [$nextUsed, $nextTotal, $cid]
            );
        }

        if ($nowExhausted) {
            $switchedTo = self::exhaustAndSwitchNext($cid, $nextUsed, $nowScrapped);
            return [
                'switched' => $switchedTo !== null && (int)($switchedTo['id'] ?? 0) !== $cid,
                'exhausted' => true,
                'scrapped' => $nowScrapped,
                'previousId' => $cid,
                'previousUsedCount' => $nextUsed,
                'active' => $switchedTo ? self::formatRowStatic($switchedTo) : null,
            ];
        }

        $updated = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]) ?: $row;
        return [
            'switched' => false,
            'exhausted' => false,
            'scrapped' => false,
            'active' => self::formatRowStatic($updated),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function formatRowStatic(array $row): array
    {
        $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
        $used = max(0, (int)($row['used_count'] ?? 0));
        $totalUsed = max($used, (int)($row['total_used_count'] ?? 0));
        $validDays = max(1, (int)($row['valid_days'] ?? self::VALID_DAYS));
        $isScrapped = self::isRowScrappedOrExpired($row) ? 1 : 0;
        $isExhausted = ($isScrapped === 1 || (int)($row['is_exhausted'] ?? 0) === 1 || $used >= $limit) ? 1 : 0;

        $now = time();
        $createdAt = (string)($row['created_at'] ?? '');
        $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
        $expiresAt = (string)($row['expires_at'] ?? '');
        if ($expiresAt === '' && $createdTs !== false) {
            $expiresAt = date('Y-m-d H:i:s', $createdTs + $validDays * 86400);
        }
        $expTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
        $remainingDays = 0;
        if ($isScrapped === 0 && $expTs !== false && $expTs > $now) {
            $remainingDays = (int)ceil(($expTs - $now) / 86400);
        }
        $ageDays = ($createdTs !== false && $now >= $createdTs)
            ? (int)floor(($now - $createdTs) / 86400)
            : 0;

        return [
            'id' => (int)($row['id'] ?? 0),
            'email' => (string)($row['email'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'password' => (string)($row['password'] ?? ''),
            'apiKey' => (string)($row['api_key'] ?? ''),
            'api_key' => (string)($row['api_key'] ?? ''),
            'status' => ($isScrapped === 0 && (int)($row['status'] ?? 0) === 1) ? 1 : 0,
            'isExhausted' => $isExhausted,
            'is_exhausted' => $isExhausted,
            'isScrapped' => $isScrapped,
            'is_scrapped' => $isScrapped,
            'usedCount' => $used,
            'used_count' => $used,
            'totalUsedCount' => $totalUsed,
            'total_used_count' => $totalUsed,
            'quotaLimit' => $limit,
            'quota_limit' => $limit,
            'remainingCount' => $isScrapped === 1 ? 0 : max(0, $limit - $used),
            'validDays' => $validDays,
            'valid_days' => $validDays,
            'expiresAt' => $expiresAt,
            'expires_at' => $expiresAt,
            'remainingDays' => $remainingDays,
            'ageDays' => $ageDays,
            'lastResetDate' => (string)($row['last_reset_date'] ?? ''),
            'scrappedAt' => (string)($row['scrapped_at'] ?? ''),
            'createdAt' => $createdAt,
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public static function activeApiKey(): string
    {
        $row = self::activeRecord();
        return trim((string)($row['api_key'] ?? ''));
    }

    private function denyUnlessNocaptchaPerm(): ?Response
    {
        DataScope::bootFromGlobals();
        if (DataScope::isLoginSuper()) {
            return null;
        }
        $login = DataScope::loginUser();
        $uid = (int)($login['id'] ?? 0);
        if ($uid <= 0) {
            return $this->error('未登录', 401);
        }
        if (RBAC::checkPermission($uid, 'system.nocaptcha')) {
            return null;
        }
        return $this->error('无权管理 NoCaptchaAI 密钥（需要权限节点：system.nocaptcha）', 403);
    }

    public function list(Request $request): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        self::ensureDailyState();
        self::activeRecord();
        $rows = DB::select('SELECT * FROM nocaptcha_keys ORDER BY status DESC, is_scrapped ASC, is_exhausted ASC, id DESC') ?: [];
        return $this->success([
            'items' => array_map([$this, 'mapRow'], $rows),
            'dailyQuotaDefault' => self::QUOTA_LIMIT,
            'validDaysDefault' => self::VALID_DAYS,
        ]);
    }

    public function create(Request $request): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        self::ensureDailyState();
        $d = $this->parseBody($request, true);
        if ($d instanceof Response) {
            return $d;
        }
        $today = date('Y-m-d');
        $expiresAt = date('Y-m-d H:i:s', time() + $d['valid_days'] * 86400);

        $id = DB::transaction(function () use ($d, $today, $expiresAt) {
            $effectiveStatus = ($d['is_scrapped'] === 1 || $d['is_exhausted'] === 1) ? 0 : $d['status'];
            if ($effectiveStatus === 1) {
                DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1');
            }
            DB::query(
                'INSERT INTO nocaptcha_keys(email, username, password, api_key, status, is_exhausted, is_scrapped, used_count, total_used_count, quota_limit, valid_days, expires_at, last_reset_date) VALUES (?,?,?,?,?,?,0,?,?,?,?,?,?)',
                [
                    $d['email'],
                    $d['username'],
                    $d['password'],
                    $d['api_key'],
                    $effectiveStatus,
                    $d['is_exhausted'],
                    $d['used_count'],
                    $d['used_count'],
                    $d['quota_limit'],
                    $d['valid_days'],
                    $expiresAt,
                    $today,
                ]
            );
            return (int)DB::getPdo()->lastInsertId();
        });
        if ($d['is_exhausted'] === 1) {
            self::exhaustAndSwitchNext($id, $d['used_count']);
        }
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$id]);
        return $this->success($this->mapRow($row ?: ['id' => $id]), '已添加');
    }

    public function update(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        self::ensureDailyState();
        $cid = (int)$id;
        $existing = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        if (!$existing) {
            return $this->error('记录不存在', 404);
        }
        $alreadyScrapped = self::isRowScrappedOrExpired($existing);
        $d = $this->parseBody($request, false, $existing);
        if ($d instanceof Response) {
            return $d;
        }
        if ($alreadyScrapped && $d['status'] === 1) {
            return $this->error('该密钥已超过30天免费期或已报废，不可再启用', 422);
        }

        $createdTs = !empty($existing['created_at']) ? strtotime((string)$existing['created_at']) : false;
        $expiresAt = ($createdTs !== false)
            ? date('Y-m-d H:i:s', $createdTs + $d['valid_days'] * 86400)
            : (string)($existing['expires_at'] ?? date('Y-m-d H:i:s', time() + $d['valid_days'] * 86400));

        // 一旦已报废，不可通过编辑解除报废；若调整有效天数后当前已超期，也立即报废
        $isScrapped = ($alreadyScrapped || strtotime($expiresAt) <= time()) ? 1 : 0;
        $effectiveExhausted = ($isScrapped === 1) ? 1 : $d['is_exhausted'];
        $effectiveStatus = ($isScrapped === 1 || $effectiveExhausted === 1) ? 0 : $d['status'];

        DB::transaction(function () use ($d, $cid, $isScrapped, $effectiveExhausted, $effectiveStatus, $expiresAt) {
            if ($effectiveStatus === 1) {
                DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1 AND id<>?', [$cid]);
            }
            DB::query(
                'UPDATE nocaptcha_keys
                 SET email=?, username=?, password=?, api_key=?, status=?, is_exhausted=?, is_scrapped=?, used_count=?, quota_limit=?, valid_days=?, expires_at=?, scrapped_at=IF(?=1, COALESCE(scrapped_at, NOW()), scrapped_at)
                 WHERE id=?',
                [
                    $d['email'],
                    $d['username'],
                    $d['password'],
                    $d['api_key'],
                    $effectiveStatus,
                    $effectiveExhausted,
                    $isScrapped,
                    $d['used_count'],
                    $d['quota_limit'],
                    $d['valid_days'],
                    $expiresAt,
                    $isScrapped,
                    $cid,
                ]
            );
        });

        if ($isScrapped === 1 || $effectiveExhausted === 1) {
            self::exhaustAndSwitchNext($cid, $d['used_count'], $isScrapped === 1);
        }
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        return $this->success($this->mapRow($row ?: ['id' => $cid]), '已保存');
    }

    /** 启用指定记录，其余全部置为不启用（已报废或当日已满 200 的不允许启用） */
    public function enable(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        self::ensureDailyState();
        $cid = (int)$id;
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        if (self::isRowScrappedOrExpired($row)) {
            DB::query('UPDATE nocaptcha_keys SET is_scrapped=1, status=0, is_exhausted=1, scrapped_at=COALESCE(scrapped_at, NOW()) WHERE id=?', [$cid]);
            return $this->error('该密钥已超过30天免费期并标记报废，不可再启用', 422);
        }
        $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
        $used = max(0, (int)($row['used_count'] ?? 0));
        if ((int)($row['is_exhausted'] ?? 0) === 1 || $used >= $limit) {
            return $this->error("该密钥今日额度已达上限（{$used}/{$limit}），请等待次日计划任务重置或切换其他账号", 422);
        }
        DB::transaction(function () use ($cid) {
            DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1');
            DB::query('UPDATE nocaptcha_keys SET status=1 WHERE id=?', [$cid]);
        });
        return $this->success(['id' => $cid, 'status' => 1], '已启用，其他密钥已关闭');
    }

    public function disable(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        self::ensureSchema();
        $cid = (int)$id;
        DB::query('UPDATE nocaptcha_keys SET status=0 WHERE id=?', [$cid]);
        return $this->success(['id' => $cid, 'status' => 0], '已停用');
    }

    /** 标记指定密钥今日额度已用完，并自动启用下一条可用密钥 */
    public function markExhausted(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        self::ensureDailyState();
        $cid = (int)$id;
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $next = self::exhaustAndSwitchNext($cid);
        $msg = $next
            ? sprintf('已标记今日用完，已自动切换启用 #%d（%s）', (int)$next['id'], (string)($next['email'] ?: $next['username'] ?: '备用密钥'))
            : '已标记今日用完（当前暂无其他可用密钥可自动启用）';
        return $this->success([
            'id' => $cid,
            'isExhausted' => 1,
            'switchedTo' => $next ? $this->mapRow($next) : null,
        ], $msg);
    }

    /** 恢复未报废密钥的当日可用状态（已报废密钥不可恢复） */
    public function markAvailable(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        self::ensureDailyState();
        $cid = (int)$id;
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        if (self::isRowScrappedOrExpired($row)) {
            DB::query('UPDATE nocaptcha_keys SET is_scrapped=1, status=0, is_exhausted=1, scrapped_at=COALESCE(scrapped_at, NOW()) WHERE id=?', [$cid]);
            return $this->error('该密钥已超过30天免费期并报废，不可恢复或再次启用', 422);
        }
        $resetUsage = (bool)$request->input('resetUsage', false);
        $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
        $used = max(0, (int)($row['used_count'] ?? 0));
        $newUsed = ($resetUsage || $used >= $limit) ? 0 : $used;
        DB::query(
            'UPDATE nocaptcha_keys SET is_exhausted=0, used_count=?, last_reset_date=CURDATE() WHERE id=? AND is_scrapped=0',
            [$newUsed, $cid]
        );
        // 若当前没有任何启用中的有效密钥，自动把这条恢复可用的启用起来
        $hasActive = DB::selectOne('SELECT id FROM nocaptcha_keys WHERE status=1 AND is_scrapped=0 AND is_exhausted=0 LIMIT 1');
        if (!$hasActive) {
            DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1');
            DB::query('UPDATE nocaptcha_keys SET status=1 WHERE id=?', [$cid]);
        }
        $updated = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]) ?: $row;
        return $this->success($this->mapRow($updated), '已重置今日可用状态');
    }

    /** 手动直接标记某条密钥报废（不可逆，不可再启用），并自动切换到其他可用密钥 */
    public function markScrapped(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        self::ensureDailyState();
        $cid = (int)$id;
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $next = self::exhaustAndSwitchNext($cid, null, true);
        $msg = $next
            ? sprintf('已标记报废（不可再启用），已自动切换启用 #%d（%s）', (int)$next['id'], (string)($next['email'] ?: $next['username'] ?: '备用密钥'))
            : '已标记报废（不可再启用）';
        return $this->success([
            'id' => $cid,
            'isScrapped' => 1,
            'switchedTo' => $next ? $this->mapRow($next) : null,
        ], $msg);
    }

    /** 超管手动一键执行「次日重置 + 超30天报废检测」 */
    public function dailyReset(Request $request): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        $stats = self::runDailyMaintenance(true);
        $msg = sprintf(
            '已重置 %d 个未到期账号今日额度，报废 %d 个超期账号',
            $stats['resetCount'],
            $stats['scrappedCount']
        );
        return $this->success($stats, $msg);
    }

    public function delete(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessNocaptchaPerm()) {
            return $deny;
        }
        self::ensureSchema();
        $cid = (int)$id;
        $wasActive = (bool)DB::selectOne('SELECT id FROM nocaptcha_keys WHERE id=? AND status=1', [$cid]);
        DB::query('DELETE FROM nocaptcha_keys WHERE id=?', [$cid]);
        if ($wasActive) {
            self::exhaustAndSwitchNext(0);
        }
        return $this->success(['id' => $cid], '已删除');
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>|Response
     */
    private function parseBody(Request $request, bool $isCreate = false, ?array $existing = null)
    {
        $email = trim((string)$request->input('email', ''));
        $username = trim((string)$request->input('username', ''));
        $password = trim((string)$request->input('password', ''));
        $apiKey = trim((string)$request->input('apiKey', $request->input('api_key', '')));
        $statusRaw = $request->input('status', 0);
        $status = ($statusRaw === true || $statusRaw === 1 || $statusRaw === '1' || $statusRaw === 'enabled') ? 1 : 0;

        $quotaRaw = $request->input('quotaLimit', $request->input('quota_limit', $existing['quota_limit'] ?? self::QUOTA_LIMIT));
        $quotaLimit = max(1, (int)$quotaRaw ?: self::QUOTA_LIMIT);

        $validDaysRaw = $request->input('validDays', $request->input('valid_days', $existing['valid_days'] ?? self::VALID_DAYS));
        $validDays = max(1, (int)$validDaysRaw ?: self::VALID_DAYS);

        $usedRaw = $request->input('usedCount', $request->input('used_count', $existing['used_count'] ?? 0));
        $usedCount = max(0, (int)$usedRaw);

        // 初始添加时默认「今日可用 / 未使用完」（is_exhausted=0, is_scrapped=0）
        $exhaustedRaw = $request->input('isExhausted', $request->input('is_exhausted', $isCreate ? 0 : ($existing['is_exhausted'] ?? 0)));
        $isExhausted = ($exhaustedRaw === true || $exhaustedRaw === 1 || $exhaustedRaw === '1' || $exhaustedRaw === 'exhausted') ? 1 : 0;
        if ($usedCount >= $quotaLimit) {
            $isExhausted = 1;
        }

        if ($apiKey === '') {
            return $this->error('请填写密钥', 422);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('邮箱格式不正确', 422);
        }
        return [
            'email' => mb_substr($email, 0, 255),
            'username' => mb_substr($username, 0, 100),
            'password' => mb_substr($password, 0, 255),
            'api_key' => mb_substr($apiKey, 0, 255),
            'status' => $status,
            'is_exhausted' => $isExhausted,
            'is_scrapped' => 0,
            'used_count' => $usedCount,
            'quota_limit' => $quotaLimit,
            'valid_days' => $validDays,
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapRow(array $row): array
    {
        return self::formatRowStatic($row);
    }
}
