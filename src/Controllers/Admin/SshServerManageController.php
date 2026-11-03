<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\SecretStore;
use App\Services\SshServer\SshServerService;

/**
 * 运营控制台：SSH 服务器列表 + 在线部署 linuxbrowse
 */
class SshServerManageController extends Controller
{
    public function list(Request $request): Response
    {
        SshServerService::ensureSchema();
        $rows = SshServerService::listAll();
        return $this->success([
            'items' => array_map([$this, 'mapRow'], $rows),
        ]);
    }

    public function create(Request $request): Response
    {
        SshServerService::ensureSchema();
        $data = $this->parseBody($request, true);
        if ($data instanceof Response) {
            return $data;
        }
        DB::query(
            'INSERT INTO ssh_servers(
                name, host, ssh_port, username, auth_type, password_enc, private_key_enc,
                public_host, browser_port, browser_api_url, api_base, vnc_password_enc,
                git_url, git_branch, status, notes
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $data['name'],
                $data['host'],
                $data['ssh_port'],
                $data['username'],
                $data['auth_type'],
                $data['password_enc'],
                $data['private_key_enc'],
                $data['public_host'],
                $data['browser_port'],
                $data['browser_api_url'],
                $data['api_base'],
                $data['vnc_password_enc'],
                $data['git_url'],
                $data['git_branch'],
                'pending',
                $data['notes'],
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        $row = SshServerService::find($id);
        return $this->success($this->mapRow($row ?: ['id' => $id]), '已创建');
    }

    public function update(Request $request, string $id): Response
    {
        SshServerService::ensureSchema();
        $sid = (int)$id;
        $old = SshServerService::find($sid);
        if (!$old) {
            return $this->error('服务器不存在', 404);
        }
        $data = $this->parseBody($request, false, $old);
        if ($data instanceof Response) {
            return $data;
        }
        DB::query(
            'UPDATE ssh_servers SET
                name=?, host=?, ssh_port=?, username=?, auth_type=?, password_enc=?, private_key_enc=?,
                public_host=?, browser_port=?, browser_api_url=?, api_base=?, vnc_password_enc=?,
                git_url=?, git_branch=?, notes=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?',
            [
                $data['name'],
                $data['host'],
                $data['ssh_port'],
                $data['username'],
                $data['auth_type'],
                $data['password_enc'],
                $data['private_key_enc'],
                $data['public_host'],
                $data['browser_port'],
                $data['browser_api_url'],
                $data['api_base'],
                $data['vnc_password_enc'],
                $data['git_url'],
                $data['git_branch'],
                $data['notes'],
                $sid,
            ]
        );
        $row = SshServerService::find($sid);
        return $this->success($this->mapRow($row ?: $old), '已保存');
    }

    public function delete(Request $request, string $id): Response
    {
        SshServerService::ensureSchema();
        $sid = (int)$id;
        DB::query('DELETE FROM ssh_servers WHERE id=?', [$sid]);
        return $this->success(['id' => $sid], '已删除');
    }

    public function test(Request $request, string $id): Response
    {
        SshServerService::ensureSchema();
        @set_time_limit(90);
        $res = SshServerService::testSsh((int)$id);
        if (!$res['ok']) {
            return $this->error($res['message'], 422, $res['detail'] ?? null);
        }
        return $this->success($res['detail'] ?? null, $res['message']);
    }

    public function health(Request $request, string $id): Response
    {
        SshServerService::ensureSchema();
        @set_time_limit(60);
        $res = SshServerService::checkHealth((int)$id);
        $row = SshServerService::find((int)$id);
        if (!$res['ok']) {
            return $this->error($res['message'], 422, [
                'url' => $res['url'] ?? null,
                'server' => $row ? $this->mapRow($row) : null,
            ]);
        }
        return $this->success([
            'url' => $res['url'] ?? null,
            'body' => $res['body'] ?? null,
            'server' => $row ? $this->mapRow($row) : null,
        ], $res['message']);
    }

    public function deploy(Request $request, string $id): Response
    {
        SshServerService::ensureSchema();
        $sid = (int)$id;
        $row = SshServerService::find($sid);
        if (!$row) {
            return $this->error('服务器不存在', 404);
        }
        if ((string)($row['status'] ?? '') === 'deploying') {
            return $this->error('正在部署中，请稍候刷新查看日志', 409);
        }
        $sync = filter_var($request->input('sync', false), FILTER_VALIDATE_BOOLEAN);
        if ($sync) {
            @set_time_limit(0);
            ignore_user_abort(true);
            $res = SshServerService::deploy($sid);
            $row2 = SshServerService::find($sid);
            if (!$res['ok']) {
                return $this->error($res['message'], 422, [
                    'log' => $res['log'] ?? '',
                    'server' => $row2 ? $this->mapRow($row2) : null,
                ]);
            }
            return $this->success([
                'log' => $res['log'] ?? '',
                'server' => $row2 ? $this->mapRow($row2) : null,
            ], $res['message']);
        }

        SshServerService::patch($sid, [
            'status' => 'deploying',
            'health_ok' => 0,
            'last_error' => '',
            'deploy_log' => "[deploy] 排队启动后台任务…\n",
            'last_deploy_at' => date('Y-m-d H:i:s'),
        ]);
        $spawned = SshServerService::spawnDeployWorker($sid);
        if (!$spawned) {
            // 回退同步（短超时环境可能被网关掐断）
            @set_time_limit(0);
            ignore_user_abort(true);
            $res = SshServerService::deploy($sid);
            $row2 = SshServerService::find($sid);
            return $res['ok']
                ? $this->success(['server' => $row2 ? $this->mapRow($row2) : null, 'log' => $res['log'] ?? ''], $res['message'])
                : $this->error($res['message'], 422, ['server' => $row2 ? $this->mapRow($row2) : null, 'log' => $res['log'] ?? '']);
        }
        $row2 = SshServerService::find($sid);
        return $this->success([
            'async' => true,
            'server' => $row2 ? $this->mapRow($row2) : null,
        ], '已开始后台部署，请刷新查看日志与状态');
    }

    public function deployLog(Request $request, string $id): Response
    {
        SshServerService::ensureSchema();
        $row = SshServerService::find((int)$id);
        if (!$row) {
            return $this->error('服务器不存在', 404);
        }
        return $this->success([
            'id' => (int)$row['id'],
            'status' => (string)($row['status'] ?? ''),
            'healthOk' => (int)($row['health_ok'] ?? 0) === 1,
            'lastError' => (string)($row['last_error'] ?? ''),
            'deployLog' => (string)($row['deploy_log'] ?? ''),
            'server' => $this->mapRow($row),
        ]);
    }

    /**
     * @param array<string,mixed>|null $old
     * @return array<string,mixed>|Response
     */
    private function parseBody(Request $request, bool $requireSecret, ?array $old = null)
    {
        $name = trim((string)$request->input('name', ''));
        $host = trim((string)$request->input('host', ''));
        $sshPort = (int)$request->input('sshPort', $request->input('ssh_port', 22));
        $username = trim((string)$request->input('username', 'root'));
        $authType = trim((string)$request->input('authType', $request->input('auth_type', 'password')));
        if ($authType !== 'key') {
            $authType = 'password';
        }
        $password = (string)$request->input('password', '');
        $privateKey = (string)$request->input('privateKey', $request->input('private_key', ''));
        $publicHost = trim((string)$request->input('publicHost', $request->input('public_host', '')));
        $browserPort = (int)$request->input('browserPort', $request->input('browser_port', 54345));
        $apiBase = trim((string)$request->input('apiBase', $request->input('api_base', '')));
        $vncPassword = (string)$request->input('vncPassword', $request->input('vnc_password', ''));
        $gitUrl = trim((string)$request->input('gitUrl', $request->input('git_url', 'https://github.com/MinWU2023/tkswarm-linuxbrowse.git')));
        $gitBranch = trim((string)$request->input('gitBranch', $request->input('git_branch', 'main')));
        $notes = trim((string)$request->input('notes', ''));

        if ($host === '') {
            return $this->error('请填写 SSH 主机', 422);
        }
        if ($username === '') {
            $username = 'root';
        }
        if ($sshPort <= 0) {
            $sshPort = 22;
        }
        if ($browserPort <= 0) {
            $browserPort = 54345;
        }
        if ($name === '') {
            $name = $host;
        }
        if ($apiBase !== '') {
            $apiBase = rtrim($apiBase, '/');
            if (!str_ends_with($apiBase, '/api')) {
                return $this->error('API Base 须以 /api 结尾，例如 https://api.example.com/api', 422);
            }
        }
        if ($gitUrl === '') {
            $gitUrl = 'https://github.com/MinWU2023/tkswarm-linuxbrowse.git';
        }
        if ($gitBranch === '') {
            $gitBranch = 'main';
        }

        $passwordEnc = (string)($old['password_enc'] ?? '');
        $keyEnc = (string)($old['private_key_enc'] ?? '');
        $vncEnc = (string)($old['vnc_password_enc'] ?? '');

        if ($password !== '' && $password !== '******') {
            $passwordEnc = SecretStore::encrypt($password);
        }
        if ($privateKey !== '' && $privateKey !== '******') {
            $keyEnc = SecretStore::encrypt($privateKey);
        }
        if ($vncPassword !== '' && $vncPassword !== '******') {
            $vncEnc = SecretStore::encrypt($vncPassword);
        }

        if ($requireSecret) {
            if ($authType === 'key' && $keyEnc === '') {
                return $this->error('请填写 SSH 私钥', 422);
            }
            if ($authType === 'password' && $passwordEnc === '') {
                return $this->error('请填写 SSH 密码', 422);
            }
        }

        $pub = $publicHost !== '' ? $publicHost : $host;
        $browserApiUrl = 'http://' . $pub . ':' . $browserPort;

        return [
            'name' => mb_substr($name, 0, 100),
            'host' => mb_substr($host, 0, 255),
            'ssh_port' => $sshPort,
            'username' => mb_substr($username, 0, 100),
            'auth_type' => $authType,
            'password_enc' => $passwordEnc,
            'private_key_enc' => $keyEnc,
            'public_host' => mb_substr($publicHost, 0, 255),
            'browser_port' => $browserPort,
            'browser_api_url' => mb_substr($browserApiUrl, 0, 500),
            'api_base' => mb_substr($apiBase, 0, 500),
            'vnc_password_enc' => $vncEnc,
            'git_url' => mb_substr($gitUrl, 0, 500),
            'git_branch' => mb_substr($gitBranch, 0, 100),
            'notes' => mb_substr($notes, 0, 500),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapRow(array $row): array
    {
        $hasPassword = trim((string)($row['password_enc'] ?? '')) !== '';
        $hasKey = trim((string)($row['private_key_enc'] ?? '')) !== '';
        $hasVnc = trim((string)($row['vnc_password_enc'] ?? '')) !== '';
        $url = SshServerService::browserUrlFromRow($row);
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'host' => (string)($row['host'] ?? ''),
            'sshPort' => (int)($row['ssh_port'] ?? 22),
            'username' => (string)($row['username'] ?? 'root'),
            'authType' => (string)($row['auth_type'] ?? 'password'),
            'hasPassword' => $hasPassword,
            'hasPrivateKey' => $hasKey,
            'password' => $hasPassword ? '******' : '',
            'privateKey' => $hasKey ? '******' : '',
            'publicHost' => (string)($row['public_host'] ?? ''),
            'browserPort' => (int)($row['browser_port'] ?? 54345),
            'browserApiUrl' => $url,
            'apiBase' => (string)($row['api_base'] ?? ''),
            'hasVncPassword' => $hasVnc,
            'vncPassword' => $hasVnc ? '******' : '',
            'gitUrl' => (string)($row['git_url'] ?? ''),
            'gitBranch' => (string)($row['git_branch'] ?? 'main'),
            'status' => (string)($row['status'] ?? 'pending'),
            'healthOk' => (int)($row['health_ok'] ?? 0) === 1,
            'lastHealthAt' => (string)($row['last_health_at'] ?? ''),
            'lastDeployAt' => (string)($row['last_deploy_at'] ?? ''),
            'lastError' => (string)($row['last_error'] ?? ''),
            'deployLog' => (string)($row['deploy_log'] ?? ''),
            'notes' => (string)($row['notes'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }
}
