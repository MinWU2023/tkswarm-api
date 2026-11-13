<?php

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;
use App\Services\SecretStore;

/**
 * 开发管理 · 测试注册环境：独立数据表 test_register_envs，
 * 直接存放代理字段（协议/地址/端口/账密）与邮箱字段，用于打开环境做注册测试。
 * 不依赖 proxies 表，也不会发布成正式账号，仅超管可用。
 */
class TestRegisterEnvController extends Controller
{
    private const COLS = 'id, name, proxy_protocol, proxy_host, proxy_port, proxy_username,
        (COALESCE(proxy_password_encrypted, \'\') <> \'\') AS has_proxy_password,
        country, email, browser_backend, browser_profile_id, notes, owner_id, created_at, updated_at';

    private function deny(): ?Response
    {
        DataScope::bootFromGlobals();
        if (DataScope::isLoginSuper()) {
            return null;
        }
        return $this->error('仅超级管理员可使用测试注册环境', 403);
    }

    public function list(Request $request): Response
    {
        if ($d = $this->deny()) {
            return $d;
        }
        $this->ensureSchema();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(200, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;
        $where = ' WHERE 1=1';
        $params = [];
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') {
            $where .= ' AND (name LIKE ? OR email LIKE ? OR proxy_host LIKE ? OR browser_profile_id LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $total = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM test_register_envs' . $where, $params)['c'] ?? 0);
        $rows = DB::select(
            'SELECT ' . self::COLS . ' FROM test_register_envs' . $where . ' ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
            $params
        ) ?: [];
        return $this->success(['items' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize]);
    }

    public function show(Request $request, string $id): Response
    {
        if ($d = $this->deny()) {
            return $d;
        }
        $this->ensureSchema();
        $row = DB::selectOne('SELECT ' . self::COLS . ' FROM test_register_envs WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('测试注册环境不存在', 404);
        }
        return $this->success($row);
    }

    public function create(Request $request): Response
    {
        if ($d = $this->deny()) {
            return $d;
        }
        $this->ensureSchema();
        $data = $this->readBody($request, null, $err);
        if ($err) {
            return $err;
        }
        DB::query(
            'INSERT INTO test_register_envs(owner_id, name, proxy_protocol, proxy_host, proxy_port, proxy_username, proxy_password_encrypted,
                country, email, browser_backend, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $this->writeOwnerId(), $data['name'], $data['proxy_protocol'], $data['proxy_host'], $data['proxy_port'],
                $data['proxy_username'], $data['proxy_password_enc'], $data['country'], $data['email'], $data['browser_backend'], $data['notes'],
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        return $this->success(DB::selectOne('SELECT ' . self::COLS . ' FROM test_register_envs WHERE id=?', [$id]), '测试注册环境已创建');
    }

    public function update(Request $request, string $id): Response
    {
        if ($d = $this->deny()) {
            return $d;
        }
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM test_register_envs WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('测试注册环境不存在', 404);
        }
        $data = $this->readBody($request, $row, $err);
        if ($err) {
            return $err;
        }
        DB::query(
            'UPDATE test_register_envs SET name=?, proxy_protocol=?, proxy_host=?, proxy_port=?, proxy_username=?, proxy_password_encrypted=?,
                country=?, email=?, browser_backend=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $data['name'], $data['proxy_protocol'], $data['proxy_host'], $data['proxy_port'], $data['proxy_username'],
                $data['proxy_password_enc'], $data['country'], $data['email'], $data['browser_backend'], $data['notes'], (int)$row['id'],
            ]
        );
        return $this->success(DB::selectOne('SELECT ' . self::COLS . ' FROM test_register_envs WHERE id=?', [(int)$row['id']]), '测试注册环境已更新');
    }

    public function delete(Request $request, string $id): Response
    {
        if ($d = $this->deny()) {
            return $d;
        }
        $this->ensureSchema();
        DB::query('DELETE FROM test_register_envs WHERE id=?', [(int)$id]);
        return $this->success(['id' => (int)$id], '已删除');
    }

    /** Node 打开环境用：返回与注册环境 bundle 同结构的数据（含解密后的代理密码） */
    public function bundle(Request $request, string $id): Response
    {
        if ($d = $this->deny()) {
            return $d;
        }
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM test_register_envs WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('测试注册环境不存在', 404);
        }
        $pw = '';
        if (!empty($row['proxy_password_encrypted'])) {
            $pw = (string)SecretStore::decrypt((string)$row['proxy_password_encrypted']);
        }
        $proxy = null;
        if ((string)$row['proxy_host'] !== '' && (int)$row['proxy_port'] > 0) {
            $proxy = [
                'id' => 0,
                'protocol' => (string)$row['proxy_protocol'],
                'host' => (string)$row['proxy_host'],
                'port' => (int)$row['proxy_port'],
                'username' => (string)$row['proxy_username'],
                'password' => $pw,
                'country' => (string)$row['country'],
            ];
        }
        return $this->success([
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'browser_backend' => (string)$row['browser_backend'],
            'browser_profile_id' => (string)$row['browser_profile_id'],
            'account_username' => (string)$row['name'],
            'account_email' => (string)$row['email'],
            'country' => (string)$row['country'],
            'owner_id' => 0,
            'status' => 'draft',
            'proxy' => $proxy,
        ]);
    }

    /** Node 建好环境后回写环境 ID */
    public function attachProfile(Request $request, string $id): Response
    {
        if ($d = $this->deny()) {
            return $d;
        }
        $this->ensureSchema();
        $row = DB::selectOne('SELECT id FROM test_register_envs WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('测试注册环境不存在', 404);
        }
        $pid = trim((string)$request->input('browserProfileId', $request->input('browser_profile_id', '')));
        DB::query('UPDATE test_register_envs SET browser_profile_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$pid, (int)$row['id']]);
        return $this->success(['id' => (int)$row['id'], 'browser_profile_id' => $pid], '环境已绑定');
    }

    /** @return array<string,mixed> */
    private function readBody(Request $request, ?array $existing, ?Response &$err = null): array
    {
        $err = null;
        $protocol = strtolower(trim((string)$request->input('proxyProtocol', $request->input('proxy_protocol', 'socks5'))));
        if (!in_array($protocol, ['socks5', 'http', 'https'], true)) {
            $protocol = 'socks5';
        }
        $host = trim((string)$request->input('proxyHost', $request->input('proxy_host', '')));
        $port = (int)$request->input('proxyPort', $request->input('proxy_port', 0));
        if ($host === '' || $port < 1 || $port > 65535) {
            $err = $this->error('请填写有效的代理地址和端口', 422);
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
        $password = (string)$request->input('proxyPassword', $request->input('proxy_password', ''));
        $email = mb_substr(trim((string)$request->input('email', '')), 0, 191);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = $this->error('邮箱格式不正确', 422);
            return [];
        }
        $name = trim((string)$request->input('name', ''));
        if ($name === '') {
            $name = '测试 ' . date('mdHis');
        }
        return [
            'name' => mb_substr($name, 0, 100),
            'proxy_protocol' => $protocol,
            'proxy_host' => mb_substr($host, 0, 191),
            'proxy_port' => $port,
            'proxy_username' => mb_substr(trim((string)$request->input('proxyUsername', $request->input('proxy_username', ''))), 0, 191),
            'proxy_password_enc' => $password !== ''
                ? SecretStore::encrypt($password)
                : (string)($existing['proxy_password_encrypted'] ?? ''),
            'country' => mb_substr(trim((string)$request->input('country', '')), 0, 64),
            'email' => $email,
            'browser_backend' => $backend,
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
        DB::query("CREATE TABLE IF NOT EXISTS test_register_envs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          owner_id INT NOT NULL DEFAULT 0,
          name VARCHAR(100) NOT NULL DEFAULT '',
          proxy_protocol VARCHAR(16) NOT NULL DEFAULT 'socks5',
          proxy_host VARCHAR(191) NOT NULL DEFAULT '',
          proxy_port INT NOT NULL DEFAULT 0,
          proxy_username VARCHAR(191) NOT NULL DEFAULT '',
          proxy_password_encrypted TEXT NULL,
          country VARCHAR(64) NOT NULL DEFAULT '',
          email VARCHAR(191) NOT NULL DEFAULT '',
          browser_backend VARCHAR(32) NOT NULL DEFAULT 'tkswarm',
          browser_profile_id VARCHAR(128) NOT NULL DEFAULT '',
          notes TEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
