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
 * 同一时间只允许一条记录处于启用状态。
 */
class NocaptchaKeyController extends Controller
{
    private const DEFAULT_EMAIL = 'piwwuvnspt@imxwe.com';

    public static function ensureSchema(): void
    {
        DB::query("CREATE TABLE IF NOT EXISTS `nocaptcha_keys` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL DEFAULT '',
            `username` VARCHAR(100) NOT NULL DEFAULT '',
            `password` VARCHAR(255) NOT NULL DEFAULT '',
            `api_key` VARCHAR(255) NOT NULL DEFAULT '',
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_nocaptcha_keys_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $exists = DB::selectOne('SELECT id FROM nocaptcha_keys WHERE email=? LIMIT 1', [self::DEFAULT_EMAIL]);
        if (!$exists) {
            $hasEnabled = DB::selectOne('SELECT id FROM nocaptcha_keys WHERE status=1 LIMIT 1');
            DB::query(
                'INSERT INTO nocaptcha_keys(email, username, password, api_key, status) VALUES (?,?,?,?,?)',
                [self::DEFAULT_EMAIL, 'piwwuvnsp', 'piwwuvnspt@imxwe.com', 'nocap_XrWSZYi3dyxKzr2zvtUxLzbM', $hasEnabled ? 0 : 1]
            );
        }
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
        $rows = DB::select('SELECT * FROM nocaptcha_keys ORDER BY status DESC, id DESC') ?: [];
        return $this->success(['items' => array_map([$this, 'mapRow'], $rows)]);
    }

    public function create(Request $request): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        self::ensureSchema();
        $d = $this->parseBody($request);
        if ($d instanceof Response) {
            return $d;
        }
        $id = DB::transaction(function () use ($d) {
            if ($d['status'] === 1) {
                DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1');
            }
            DB::query(
                'INSERT INTO nocaptcha_keys(email, username, password, api_key, status) VALUES (?,?,?,?,?)',
                [$d['email'], $d['username'], $d['password'], $d['api_key'], $d['status']]
            );
            return (int)DB::getPdo()->lastInsertId();
        });
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
        if (!DB::selectOne('SELECT id FROM nocaptcha_keys WHERE id=?', [$cid])) {
            return $this->error('记录不存在', 404);
        }
        $d = $this->parseBody($request);
        if ($d instanceof Response) {
            return $d;
        }
        DB::transaction(function () use ($d, $cid) {
            if ($d['status'] === 1) {
                DB::query('UPDATE nocaptcha_keys SET status=0 WHERE status=1 AND id<>?', [$cid]);
            }
            DB::query(
                'UPDATE nocaptcha_keys SET email=?, username=?, password=?, api_key=?, status=? WHERE id=?',
                [$d['email'], $d['username'], $d['password'], $d['api_key'], $d['status'], $cid]
            );
        });
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
        if (!DB::selectOne('SELECT id FROM nocaptcha_keys WHERE id=?', [$cid])) {
            return $this->error('记录不存在', 404);
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

    public function delete(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        self::ensureSchema();
        $cid = (int)$id;
        DB::query('DELETE FROM nocaptcha_keys WHERE id=?', [$cid]);
        return $this->success(['id' => $cid], '已删除');
    }

    /** @return array<string,mixed>|Response */
    private function parseBody(Request $request)
    {
        $email = trim((string)$request->input('email', ''));
        $username = trim((string)$request->input('username', ''));
        $password = trim((string)$request->input('password', ''));
        $apiKey = trim((string)$request->input('apiKey', $request->input('api_key', '')));
        $statusRaw = $request->input('status', 0);
        $status = ($statusRaw === true || $statusRaw === 1 || $statusRaw === '1' || $statusRaw === 'enabled') ? 1 : 0;

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
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'email' => (string)($row['email'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'password' => (string)($row['password'] ?? ''),
            'apiKey' => (string)($row['api_key'] ?? ''),
            'status' => (int)($row['status'] ?? 0) === 1 ? 1 : 0,
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }
}
