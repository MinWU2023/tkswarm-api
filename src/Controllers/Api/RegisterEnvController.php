<?php

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;
use App\Services\SecretStore;

/**
 * 注册环境：先选代理建环境、打开环境填写账号信息，发布时再生成正式账号并绑定环境与代理。
 * 本表独立存放「环境 + 代理 + 待注册账号信息」，发布前不进 accounts。
 */
class RegisterEnvController extends Controller
{
    private const SELECT = "SELECT e.id, e.name, e.proxy_id, e.browser_backend, e.browser_profile_id,
        e.account_username, e.account_nickname, e.country, e.group_id, e.notes, e.status, e.account_id,
        e.owner_id, e.created_at, e.updated_at,
        (COALESCE(e.account_password_encrypted, '') <> '') AS has_password,
        (COALESCE(e.account_totp_encrypted, '') <> '') AS has_totp,
        p.name AS proxy_name, p.host AS proxy_host, p.port AS proxy_port,
        p.protocol AS proxy_protocol, p.country AS proxy_country
        FROM register_envs e LEFT JOIN proxies p ON p.id = e.proxy_id";

    public function list(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(200, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;
        $where = ' WHERE 1=1';
        $params = [];
        [$ownerSql, $ownerParams] = $this->ownerSql('e.owner_id');
        $where .= $ownerSql;
        $params = array_merge($params, $ownerParams);
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') {
            $where .= ' AND (e.name LIKE ? OR e.account_username LIKE ? OR e.browser_profile_id LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }
        $status = trim((string)$request->get('status', ''));
        if (in_array($status, ['draft', 'published'], true)) {
            $where .= ' AND e.status = ?';
            $params[] = $status;
        }
        $total = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM register_envs e' . $where, $params)['c'] ?? 0);
        $rows = DB::select(
            self::SELECT . $where . ' ORDER BY e.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
            $params
        ) ?: [];
        return $this->success(['items' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize]);
    }

    public function show(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = $this->loadVisible((int)$id, $err);
        if ($err) {
            return $err;
        }
        return $this->success(DB::selectOne(self::SELECT . ' WHERE e.id=?', [(int)$row['id']]));
    }

    public function create(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $data = $this->readBody($request, null, $err);
        if ($err) {
            return $err;
        }
        DB::query(
            "INSERT INTO register_envs(owner_id, name, proxy_id, browser_backend, account_username, account_nickname,
                account_password_encrypted, account_totp_encrypted, country, group_id, notes, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, 'draft')",
            [
                $this->writeOwnerId(), $data['name'], $data['proxy_id'], $data['browser_backend'],
                $data['account_username'], $data['account_nickname'], $data['password_enc'], $data['totp_enc'],
                $data['country'], $data['group_id'], $data['notes'],
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        return $this->success(DB::selectOne(self::SELECT . ' WHERE e.id=?', [$id]), '注册环境已创建');
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = $this->loadVisible((int)$id, $err);
        if ($err) {
            return $err;
        }
        if (($row['status'] ?? '') === 'published') {
            return $this->error('已发布的注册环境不能再编辑，请到账号管理里修改', 422);
        }
        $data = $this->readBody($request, $row, $err);
        if ($err) {
            return $err;
        }
        DB::query(
            "UPDATE register_envs SET name=?, proxy_id=?, browser_backend=?, account_username=?, account_nickname=?,
                account_password_encrypted=?, account_totp_encrypted=?, country=?, group_id=?, notes=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [
                $data['name'], $data['proxy_id'], $data['browser_backend'], $data['account_username'],
                $data['account_nickname'], $data['password_enc'], $data['totp_enc'], $data['country'],
                $data['group_id'], $data['notes'], (int)$row['id'],
            ]
        );
        return $this->success(DB::selectOne(self::SELECT . ' WHERE e.id=?', [(int)$row['id']]), '注册环境已更新');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = $this->loadVisible((int)$id, $err);
        if ($err) {
            return $err;
        }
        DB::query('DELETE FROM register_envs WHERE id=?', [(int)$row['id']]);
        return $this->success(null, '注册环境已删除');
    }

    /** Node 用：含解密后的账号密码 / 2FA 与代理明文，仅限可见范围内的记录 */
    public function bundle(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = $this->loadVisible((int)$id, $err);
        if ($err) {
            return $err;
        }
        $out = DB::selectOne(self::SELECT . ' WHERE e.id=?', [(int)$row['id']]) ?: [];
        $out['account_password'] = !empty($row['account_password_encrypted']) ? SecretStore::decrypt((string)$row['account_password_encrypted']) : '';
        $out['account_totp'] = !empty($row['account_totp_encrypted']) ? SecretStore::decrypt((string)$row['account_totp_encrypted']) : '';
        $proxy = null;
        if (!empty($row['proxy_id'])) {
            $p = DB::selectOne('SELECT * FROM proxies WHERE id=?', [(int)$row['proxy_id']]);
            if ($p) {
                $pw = (string)($p['password'] ?? '');
                if ($pw === '' && !empty($p['password_encrypted'])) {
                    $pw = SecretStore::decrypt((string)$p['password_encrypted']);
                }
                $proxy = [
                    'id' => (int)$p['id'],
                    'protocol' => (string)($p['protocol'] ?? 'socks5'),
                    'host' => (string)($p['host'] ?? ''),
                    'port' => (int)($p['port'] ?? 0),
                    'username' => (string)($p['username'] ?? ''),
                    'password' => $pw,
                    'country' => (string)($p['country'] ?? ''),
                ];
            }
        }
        $out['proxy'] = $proxy;
        return $this->success($out);
    }

    /** Node 建好环境后回写环境 ID */
    public function attachProfile(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = $this->loadVisible((int)$id, $err);
        if ($err) {
            return $err;
        }
        $pid = trim((string)$request->input('browserProfileId', $request->input('browser_profile_id', '')));
        DB::query('UPDATE register_envs SET browser_profile_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$pid, (int)$row['id']]);
        return $this->success(['id' => (int)$row['id'], 'browser_profile_id' => $pid], '环境已绑定到注册记录');
    }

    /** Node 发布建号成功后标记 */
    public function markPublished(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = $this->loadVisible((int)$id, $err);
        if ($err) {
            return $err;
        }
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        if ($accountId < 1) {
            return $this->error('accountId 必填', 422);
        }
        DB::query(
            "UPDATE register_envs SET status='published', account_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$accountId, (int)$row['id']]
        );
        return $this->success(['id' => (int)$row['id'], 'status' => 'published', 'account_id' => $accountId], '已发布为正式账号');
    }

    /** @param-out Response|null $err */
    private function loadVisible(int $id, ?Response &$err = null): ?array
    {
        $err = null;
        $row = DB::selectOne('SELECT * FROM register_envs WHERE id=?', [$id]);
        if ($e = $this->assertOwns($row)) {
            $err = $e;
            return null;
        }
        return $row;
    }

    /**
     * 解析并校验请求体；$existing 非空时表示编辑（密码 / 2FA 留空 = 不修改）。
     *
     * @return array<string,mixed>
     */
    private function readBody(Request $request, ?array $existing, ?Response &$err = null): array
    {
        $err = null;
        $proxyId = (int)$request->input('proxyId', $request->input('proxy_id', 0));
        if ($proxyId < 1) {
            $err = $this->error('请选择代理', 422);
            return [];
        }
        $proxy = DB::selectOne('SELECT * FROM proxies WHERE id=?', [$proxyId]);
        if ($e = DataScope::assertProxyVisible($proxy)) {
            $err = $e;
            return [];
        }
        $backend = strtolower(trim((string)$request->input('browserBackend', $request->input('browser_backend', 'tkswarm'))));
        if (!in_array($backend, ['bit', 'tkswarm'], true)) {
            $backend = 'tkswarm';
        }
        if ($existing && trim((string)($existing['browser_profile_id'] ?? '')) !== '' && $backend !== (string)$existing['browser_backend']) {
            $err = $this->error('环境已创建，不能再切换浏览器后端；请删除后重建', 422);
            return [];
        }
        $username = trim((string)$request->input('username', $request->input('accountUsername', '')));
        $password = (string)$request->input('password', '');
        $totp = strtoupper(preg_replace('/\s+/', '', (string)$request->input('totpSecret', '')) ?? '');
        if ($totp !== '' && (!preg_match('/^[A-Z2-7]+=*$/', $totp) || strlen($totp) < 16)) {
            $err = $this->error('2FA 密钥不是有效的 Base32 格式', 422);
            return [];
        }
        $groupId = $request->input('groupId', $request->input('group_id'));
        $name = trim((string)$request->input('name', ''));
        if ($name === '') {
            $name = $username !== '' ? $username : ('注册环境 ' . date('mdHis'));
        }
        return [
            'name' => mb_substr($name, 0, 100),
            'proxy_id' => $proxyId,
            'browser_backend' => $backend,
            'account_username' => $username,
            'account_nickname' => trim((string)$request->input('nickname', '')),
            'password_enc' => $password !== ''
                ? SecretStore::encrypt($password)
                : (string)($existing['account_password_encrypted'] ?? ''),
            'totp_enc' => $totp !== ''
                ? SecretStore::encrypt($totp)
                : (string)($existing['account_totp_encrypted'] ?? ''),
            'country' => trim((string)$request->input('country', (string)($proxy['country'] ?? ''))),
            'group_id' => ($groupId === null || $groupId === '') ? null : (int)$groupId,
            'notes' => trim((string)$request->input('notes', '')),
        ];
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        DB::query("CREATE TABLE IF NOT EXISTS register_envs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          owner_id INT NOT NULL DEFAULT 0,
          name VARCHAR(191) NOT NULL DEFAULT '',
          proxy_id INT NULL,
          browser_backend VARCHAR(32) NOT NULL DEFAULT 'tkswarm',
          browser_profile_id VARCHAR(128) NOT NULL DEFAULT '',
          account_username VARCHAR(191) NOT NULL DEFAULT '',
          account_nickname VARCHAR(191) NOT NULL DEFAULT '',
          account_password_encrypted TEXT NULL,
          account_totp_encrypted TEXT NULL,
          country VARCHAR(64) NOT NULL DEFAULT '',
          group_id INT NULL,
          notes TEXT NULL,
          status VARCHAR(16) NOT NULL DEFAULT 'draft',
          account_id INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_register_envs_owner (owner_id),
          KEY idx_register_envs_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
