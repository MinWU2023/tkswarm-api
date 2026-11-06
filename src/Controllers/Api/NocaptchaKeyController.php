<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Database\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\DataScope;

/**
 * 开发管理 · NoCaptchaAI 密钥（仅登录超管可访问）
 * 同一时间只允许一条记录处于启用状态；单条免费额度达到 6000（或标记已用完）自动切换到任意未用完密钥。
 */
class NocaptchaKeyController extends Controller
{
    private const DEFAULT_EMAIL = 'piwwuvnspt@imxwe.com';
    public const QUOTA_LIMIT = 6000;

    public static function ensureSchema(): void
    {
        DB::query("CREATE TABLE IF NOT EXISTS `nocaptcha_keys` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL DEFAULT '',
            `username` VARCHAR(100) NOT NULL DEFAULT '',
            `password` VARCHAR(255) NOT NULL DEFAULT '',
            `api_key` VARCHAR(255) NOT NULL DEFAULT '',
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `is_exhausted` TINYINT(1) NOT NULL DEFAULT 0,
            `used_count` INT NOT NULL DEFAULT 0,
            `quota_limit` INT NOT NULL DEFAULT 6000,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_nocaptcha_keys_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM `nocaptcha_keys`') ?: [], 'Field');
            if (!in_array('is_exhausted', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `is_exhausted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
            }
            if (!in_array('used_count', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `used_count` INT NOT NULL DEFAULT 0 AFTER `is_exhausted`');
            }
            if (!in_array('quota_limit', $cols, true)) {
                DB::query('ALTER TABLE `nocaptcha_keys` ADD COLUMN `quota_limit` INT NOT NULL DEFAULT 6000 AFTER `used_count`');
            }
        } catch (\Throwable $e) {
            // ignore schema alter race
        }

        $exists = DB::selectOne('SELECT id FROM nocaptcha_keys WHERE email=? LIMIT 1', [self::DEFAULT_EMAIL]);
        if (!$exists) {
            $hasEnabled = DB::selectOne('SELECT id FROM nocaptcha_keys WHERE status=1 LIMIT 1');
            DB::query(
                'INSERT INTO nocaptcha_keys(email, username, password, api_key, status, is_exhausted, used_count, quota_limit) VALUES (?,?,?,?,?,0,0,?)',
                [self::DEFAULT_EMAIL, 'piwwuvnsp', 'piwwuvnspt@imxwe.com', 'nocap_XrWSZYi3dyxKzr2zvtUxLzbM', $hasEnabled ? 0 : 1, self::QUOTA_LIMIT]
            );
        }
    }

    /**
     * 获取当前启用（status=1 且未用完）的 NoCaptchaAI 密钥记录。
     * 若当前启用记录已达到额度或已标记用完，自动将其停用并启用任意一条未用完的记录。
     * @return array<string,mixed>|null
     */
    public static function activeRecord(): ?array
    {
        try {
            self::ensureSchema();
            $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE status=1 ORDER BY id DESC LIMIT 1');
            if ($row) {
                $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
                $used = max(0, (int)($row['used_count'] ?? 0));
                $exhausted = (int)($row['is_exhausted'] ?? 0) === 1 || $used >= $limit;
                if (!$exhausted) {
                    return $row;
                }
                // 当前启用的已用完，自动标记已用完并切换到下一个未用完的密钥
                self::exhaustAndSwitchNext((int)$row['id'], $used);
                $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE status=1 AND is_exhausted=0 ORDER BY id DESC LIMIT 1');
                return $row ?: null;
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 将指定记录标记为已用完并停用，同时自动启用任意一条尚未用完（is_exhausted=0 且 used_count < quota_limit）的记录。
     * @return array<string,mixed>|null 返回新启用的记录（若无可用则返回 null）
     */
    public static function exhaustAndSwitchNext(int $currentId = 0, ?int $forceUsedCount = null): ?array
    {
        self::ensureSchema();
        return DB::transaction(function () use ($currentId, $forceUsedCount) {
            if ($currentId > 0) {
                if ($forceUsedCount !== null) {
                    DB::query(
                        'UPDATE nocaptcha_keys SET is_exhausted=1, status=0, used_count=GREATEST(used_count, ?) WHERE id=?',
                        [max(0, $forceUsedCount), $currentId]
                    );
                } else {
                    DB::query('UPDATE nocaptcha_keys SET is_exhausted=1, status=0 WHERE id=?', [$currentId]);
                }
            }
            // 同步把所有达到额度的记录标记为 is_exhausted=1, status=0
            DB::query('UPDATE nocaptcha_keys SET is_exhausted=1, status=0 WHERE used_count >= quota_limit AND quota_limit > 0');

            // 检查是否已有未用完的启用记录
            $alreadyActive = DB::selectOne(
                'SELECT * FROM nocaptcha_keys WHERE status=1 AND is_exhausted=0 AND used_count < quota_limit AND api_key<>"" ORDER BY id ASC LIMIT 1'
            );
            if ($alreadyActive) {
                return $alreadyActive;
            }

            // 选择任意一条未使用完的记录自动启用
            $next = DB::selectOne(
                'SELECT * FROM nocaptcha_keys WHERE is_exhausted=0 AND used_count < quota_limit AND api_key<>"" AND id<>? ORDER BY id ASC LIMIT 1',
                [$currentId]
            );
            if (!$next) {
                return null;
            }
            DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1');
            DB::query('UPDATE nocaptcha_keys SET status=1 WHERE id=?', [(int)$next['id']]);
            return DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [(int)$next['id']]) ?: null;
        });
    }

    /**
     * 上报一次或多次使用量，或在接口返回额度耗尽时直接标记用完并切换下一个可用密钥。
     * @return array<string,mixed>
     */
    public static function recordUsage(string $apiKey = '', int $id = 0, int $increment = 1, bool $exhausted = false, ?int $remoteUsed = null): array
    {
        self::ensureSchema();
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
                'active' => $active ? self::formatRowStatic($active) : null,
            ];
        }

        $cid = (int)$row['id'];
        $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
        $curUsed = max(0, (int)($row['used_count'] ?? 0));
        $inc = max(0, $increment);
        $nextUsed = $curUsed + $inc;
        if ($remoteUsed !== null && $remoteUsed > $nextUsed) {
            $nextUsed = $remoteUsed;
        }
        $nowExhausted = $exhausted || ((int)($row['is_exhausted'] ?? 0) === 1) || ($nextUsed >= $limit);

        if ($nowExhausted) {
            $switchedTo = self::exhaustAndSwitchNext($cid, $nextUsed);
            return [
                'switched' => $switchedTo !== null && (int)($switchedTo['id'] ?? 0) !== $cid,
                'exhausted' => true,
                'previousId' => $cid,
                'previousUsedCount' => $nextUsed,
                'active' => $switchedTo ? self::formatRowStatic($switchedTo) : null,
            ];
        }

        DB::query('UPDATE nocaptcha_keys SET used_count=? WHERE id=?', [$nextUsed, $cid]);
        $updated = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]) ?: $row;
        return [
            'switched' => false,
            'exhausted' => false,
            'active' => self::formatRowStatic($updated),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function formatRowStatic(array $row): array
    {
        $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
        $used = max(0, (int)($row['used_count'] ?? 0));
        $isExhausted = ((int)($row['is_exhausted'] ?? 0) === 1 || $used >= $limit) ? 1 : 0;
        return [
            'id' => (int)($row['id'] ?? 0),
            'email' => (string)($row['email'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'password' => (string)($row['password'] ?? ''),
            'apiKey' => (string)($row['api_key'] ?? ''),
            'api_key' => (string)($row['api_key'] ?? ''),
            'status' => (int)($row['status'] ?? 0) === 1 ? 1 : 0,
            'isExhausted' => $isExhausted,
            'is_exhausted' => $isExhausted,
            'usedCount' => $used,
            'used_count' => $used,
            'quotaLimit' => $limit,
            'quota_limit' => $limit,
            'remainingCount' => max(0, $limit - $used),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public static function activeApiKey(): string
    {
        $row = self::activeRecord();
        return trim((string)($row['api_key'] ?? ''));
    }

    private function denyUnlessLoginSuper(): ?Response
    {
        DataScope::bootFromGlobals();
        if (!DataScope::isLoginSuper()) {
            return $this->error('仅超级管理员可管理 NoCaptchaAI 密钥', 403);
        }
        return null;
    }

    public function list(Request $request): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        self::ensureSchema();
        // 先调用一次 activeRecord，若当前启用记录已满 6000 则自动完成状态切换
        self::activeRecord();
        $rows = DB::select('SELECT * FROM nocaptcha_keys ORDER BY status DESC, is_exhausted ASC, id DESC') ?: [];
        return $this->success(['items' => array_map([$this, 'mapRow'], $rows)]);
    }

    public function create(Request $request): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        self::ensureSchema();
        $d = $this->parseBody($request, true);
        if ($d instanceof Response) {
            return $d;
        }
        $id = DB::transaction(function () use ($d) {
            // 若当前表中没有任何启用且未用完的密钥，且新添加的未用完，可按用户选择启用
            if ($d['status'] === 1 && $d['is_exhausted'] === 0) {
                DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1');
            }
            $effectiveStatus = $d['is_exhausted'] === 1 ? 0 : $d['status'];
            DB::query(
                'INSERT INTO nocaptcha_keys(email, username, password, api_key, status, is_exhausted, used_count, quota_limit) VALUES (?,?,?,?,?,?,?,?)',
                [
                    $d['email'],
                    $d['username'],
                    $d['password'],
                    $d['api_key'],
                    $effectiveStatus,
                    $d['is_exhausted'],
                    $d['used_count'],
                    $d['quota_limit'],
                ]
            );
            return (int)DB::getPdo()->lastInsertId();
        });
        // exhaustAndSwitchNext 自带事务，不能嵌套在上面的事务里
        if ($d['is_exhausted'] === 1) {
            self::exhaustAndSwitchNext($id, $d['used_count']);
        }
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$id]);
        return $this->success($this->mapRow($row ?: ['id' => $id]), '已添加');
    }

    public function update(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        self::ensureSchema();
        $cid = (int)$id;
        $existing = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        if (!$existing) {
            return $this->error('记录不存在', 404);
        }
        $d = $this->parseBody($request, false, $existing);
        if ($d instanceof Response) {
            return $d;
        }
        DB::transaction(function () use ($d, $cid) {
            $effectiveStatus = $d['is_exhausted'] === 1 ? 0 : $d['status'];
            if ($effectiveStatus === 1) {
                DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1 AND id<>?', [$cid]);
            }
            DB::query(
                'UPDATE nocaptcha_keys SET email=?, username=?, password=?, api_key=?, status=?, is_exhausted=?, used_count=?, quota_limit=? WHERE id=?',
                [
                    $d['email'],
                    $d['username'],
                    $d['password'],
                    $d['api_key'],
                    $effectiveStatus,
                    $d['is_exhausted'],
                    $d['used_count'],
                    $d['quota_limit'],
                    $cid,
                ]
            );
        });
        // exhaustAndSwitchNext 自带事务，不能嵌套在上面的事务里
        if ($d['is_exhausted'] === 1) {
            self::exhaustAndSwitchNext($cid, $d['used_count']);
        }
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        return $this->success($this->mapRow($row ?: ['id' => $cid]), '已保存');
    }

    /** 启用指定记录，其余全部置为不启用 */
    public function enable(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        self::ensureSchema();
        $cid = (int)$id;
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
        $used = max(0, (int)($row['used_count'] ?? 0));
        if ((int)($row['is_exhausted'] ?? 0) === 1 || $used >= $limit) {
            return $this->error("该密钥额度已使用完（{$used}/{$limit}），请先重置或修改使用状态后再启用", 422);
        }
        DB::transaction(function () use ($cid) {
            DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1');
            DB::query('UPDATE nocaptcha_keys SET status=1 WHERE id=?', [$cid]);
        });
        return $this->success(['id' => $cid, 'status' => 1], '已启用，其他密钥已关闭');
    }

    public function disable(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        self::ensureSchema();
        $cid = (int)$id;
        DB::query('UPDATE nocaptcha_keys SET status=0 WHERE id=?', [$cid]);
        return $this->success(['id' => $cid, 'status' => 0], '已停用');
    }

    /** 标记指定密钥为已使用完，并自动启用任意一条未使用完的密钥 */
    public function markExhausted(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        self::ensureSchema();
        $cid = (int)$id;
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $next = self::exhaustAndSwitchNext($cid);
        $msg = $next
            ? sprintf('已标记为用完，已自动切换启用 #%d（%s）', (int)$next['id'], (string)($next['email'] ?: $next['username'] ?: '备用密钥'))
            : '已标记为用完（当前暂无其他未使用完的密钥可自动启用）';
        return $this->success([
            'id' => $cid,
            'isExhausted' => 1,
            'switchedTo' => $next ? $this->mapRow($next) : null,
        ], $msg);
    }

    /** 标记指定密钥为未使用完（若原已满额则重置已用次数为 0） */
    public function markAvailable(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        self::ensureSchema();
        $cid = (int)$id;
        $row = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $resetUsage = (bool)$request->input('resetUsage', false);
        $limit = max(1, (int)($row['quota_limit'] ?? self::QUOTA_LIMIT));
        $used = max(0, (int)($row['used_count'] ?? 0));
        $newUsed = ($resetUsage || $used >= $limit) ? 0 : $used;
        DB::query('UPDATE nocaptcha_keys SET is_exhausted=0, used_count=? WHERE id=?', [$newUsed, $cid]);
        // 若当前没有任何启用中的密钥，自动把这条恢复可用的启用起来
        $hasActive = DB::selectOne('SELECT id FROM nocaptcha_keys WHERE status=1 AND is_exhausted=0 LIMIT 1');
        if (!$hasActive) {
            DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1');
            DB::query('UPDATE nocaptcha_keys SET status=1 WHERE id=?', [$cid]);
        }
        $updated = DB::selectOne('SELECT * FROM nocaptcha_keys WHERE id=?', [$cid]) ?: $row;
        return $this->success($this->mapRow($updated), '已恢复为未使用完');
    }

    public function delete(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
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

        $usedRaw = $request->input('usedCount', $request->input('used_count', $existing['used_count'] ?? 0));
        $usedCount = max(0, (int)$usedRaw);

        // 初始添加时默认「未使用完」（is_exhausted=0）
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
            'used_count' => $usedCount,
            'quota_limit' => $quotaLimit,
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapRow(array $row): array
    {
        return self::formatRowStatic($row);
    }
}
