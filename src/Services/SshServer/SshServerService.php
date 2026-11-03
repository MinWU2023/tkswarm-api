<?php
declare(strict_types=1);

namespace App\Services\SshServer;

use App\Core\Database\DB;
use App\Services\SecretStore;

/**
 * SSH 服务器清单 + linuxbrowse 部署 / 健康检测。
 */
class SshServerService
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `ssh_servers` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(100) NOT NULL DEFAULT '',
              `host` VARCHAR(255) NOT NULL,
              `ssh_port` INT NOT NULL DEFAULT 22,
              `username` VARCHAR(100) NOT NULL DEFAULT 'root',
              `auth_type` VARCHAR(20) NOT NULL DEFAULT 'password',
              `password_enc` TEXT NULL,
              `private_key_enc` MEDIUMTEXT NULL,
              `public_host` VARCHAR(255) NOT NULL DEFAULT '',
              `browser_port` INT NOT NULL DEFAULT 54345,
              `browser_api_url` VARCHAR(500) NOT NULL DEFAULT '',
              `api_base` VARCHAR(500) NOT NULL DEFAULT '',
              `vnc_password_enc` TEXT NULL,
              `git_url` VARCHAR(500) NOT NULL DEFAULT 'https://github.com/MinWU2023/tkswarm-linuxbrowse.git',
              `git_branch` VARCHAR(100) NOT NULL DEFAULT 'main',
              `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
              `health_ok` TINYINT(1) NOT NULL DEFAULT 0,
              `last_health_at` DATETIME NULL,
              `last_deploy_at` DATETIME NULL,
              `last_error` TEXT NULL,
              `deploy_log` MEDIUMTEXT NULL,
              `notes` VARCHAR(500) NOT NULL DEFAULT '',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_ssh_servers_health` (`health_ok`, `status`),
              KEY `idx_ssh_servers_host` (`host`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
        }
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(): array
    {
        self::ensureSchema();
        return DB::select('SELECT * FROM ssh_servers ORDER BY health_ok DESC, id DESC') ?: [];
    }

    /** @return list<array<string,mixed>> */
    public static function listHealthyForNode(): array
    {
        self::ensureSchema();
        return DB::select(
            "SELECT id, name, host, public_host, browser_port, browser_api_url, status, health_ok, last_health_at
             FROM ssh_servers
             WHERE health_ok=1 AND status IN ('ok','healthy')
             ORDER BY id DESC"
        ) ?: [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM ssh_servers WHERE id=?', [$id]);
        return $row ?: null;
    }

    public static function browserUrlFromRow(array $row): string
    {
        $existing = trim((string)($row['browser_api_url'] ?? ''));
        if ($existing !== '') {
            return rtrim($existing, '/');
        }
        $pub = trim((string)($row['public_host'] ?? ''));
        if ($pub === '') {
            $pub = trim((string)($row['host'] ?? ''));
        }
        $port = (int)($row['browser_port'] ?? 54345);
        if ($port <= 0) {
            $port = 54345;
        }
        return 'http://' . $pub . ':' . $port;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{host:string,port:int,username:string,password?:string,privateKey?:string,timeout?:int}
     */
    public static function sshOptsFromRow(array $row, int $timeout = 600): array
    {
        $opts = [
            'host' => (string)$row['host'],
            'port' => (int)($row['ssh_port'] ?? 22),
            'username' => (string)($row['username'] ?? 'root'),
            'timeout' => $timeout,
        ];
        $auth = (string)($row['auth_type'] ?? 'password');
        if ($auth === 'key') {
            $key = SecretStore::decrypt((string)($row['private_key_enc'] ?? ''));
            if ($key !== '') {
                $opts['privateKey'] = $key;
            }
            $pass = SecretStore::decrypt((string)($row['password_enc'] ?? ''));
            if ($pass !== '') {
                $opts['password'] = $pass; // 加密私钥口令 / 备用
            }
        } else {
            $opts['password'] = SecretStore::decrypt((string)($row['password_enc'] ?? ''));
        }
        return $opts;
    }

    /** @return array{ok:bool,message:string,detail?:array<string,mixed>} */
    public static function testSsh(int $id): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'message' => '服务器不存在'];
        }
        $res = SshClient::testConnection(self::sshOptsFromRow($row, 45));
        if (!$res['ok']) {
            self::patch($id, [
                'status' => 'unreachable',
                'health_ok' => 0,
                'last_error' => (string)($res['error'] ?? 'SSH 连接失败'),
            ]);
            return ['ok' => false, 'message' => (string)($res['error'] ?? 'SSH 连接失败'), 'detail' => $res];
        }
        self::patch($id, [
            'last_error' => '',
        ]);
        return [
            'ok' => true,
            'message' => 'SSH 连接成功',
            'detail' => [
                'stdout' => trim((string)$res['stdout']),
                'stderr' => trim((string)$res['stderr']),
            ],
        ];
    }

    /** @return array{ok:bool,message:string,url?:string,body?:mixed} */
    public static function checkHealth(int $id): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'message' => '服务器不存在'];
        }
        $url = self::browserUrlFromRow($row);
        $healthUrl = $url . '/health';
        $ch = curl_init($healthUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $code < 200 || $code >= 300) {
            $msg = $errno ? ('健康检测失败: ' . $err) : ('健康检测 HTTP ' . $code);
            self::patch($id, [
                'health_ok' => 0,
                'status' => ((string)($row['status'] ?? '') === 'deploying') ? 'deploying' : 'failed',
                'last_health_at' => date('Y-m-d H:i:s'),
                'last_error' => $msg,
                'browser_api_url' => $url,
            ]);
            return ['ok' => false, 'message' => $msg, 'url' => $healthUrl];
        }

        $body = json_decode((string)$raw, true);
        $provider = is_array($body)
            ? (string)($body['data']['provider'] ?? $body['provider'] ?? '')
            : '';
        $looksLinux = $provider === '' || stripos($provider, 'linux') !== false || stripos($provider, 'tkswarm') !== false;

        self::patch($id, [
            'health_ok' => $looksLinux ? 1 : 0,
            'status' => $looksLinux ? 'ok' : 'failed',
            'last_health_at' => date('Y-m-d H:i:s'),
            'last_error' => $looksLinux ? '' : ('health provider 异常: ' . $provider),
            'browser_api_url' => $url,
        ]);

        return [
            'ok' => $looksLinux,
            'message' => $looksLinux ? '健康检测通过' : ('health provider 异常: ' . $provider),
            'url' => $healthUrl,
            'body' => $body,
        ];
    }

    /**
     * 在目标机执行 install-ubuntu.sh（耗时长，建议后台 worker 调用）。
     * @return array{ok:bool,message:string,log?:string}
     */
    public static function deploy(int $id, ?callable $onLog = null): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'message' => '服务器不存在'];
        }

        self::patch($id, [
            'status' => 'deploying',
            'health_ok' => 0,
            'last_error' => '',
            'deploy_log' => '',
            'last_deploy_at' => date('Y-m-d H:i:s'),
        ]);

        $apiBase = trim((string)($row['api_base'] ?? ''));
        if ($apiBase === '') {
            self::patch($id, ['status' => 'failed', 'last_error' => '请填写 API Base（须以 /api 结尾）']);
            return ['ok' => false, 'message' => '请填写 API Base（须以 /api 结尾）'];
        }
        $apiBase = rtrim($apiBase, '/');
        if (!str_ends_with($apiBase, '/api')) {
            self::patch($id, ['status' => 'failed', 'last_error' => 'API Base 须以 /api 结尾']);
            return ['ok' => false, 'message' => 'API Base 须以 /api 结尾'];
        }

        $browserPort = (int)($row['browser_port'] ?? 54345);
        if ($browserPort <= 0) {
            $browserPort = 54345;
        }
        $vnc = SecretStore::decrypt((string)($row['vnc_password_enc'] ?? ''));
        $gitUrl = trim((string)($row['git_url'] ?? '')) ?: 'https://github.com/MinWU2023/tkswarm-linuxbrowse.git';
        $gitBranch = trim((string)($row['git_branch'] ?? '')) ?: 'main';
        $hostId = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string)($row['name'] ?: $row['host'])) ?: 'lb';

        $log = '';
        $append = static function (string $chunk) use (&$log, $id, $onLog): void {
            $chunk = str_replace("\0", '', $chunk);
            $log .= $chunk;
            if (strlen($log) > 200000) {
                $log = substr($log, -160000);
            }
            try {
                DB::query('UPDATE ssh_servers SET deploy_log=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$log, $id]);
            } catch (\Throwable $e) {
            }
            if ($onLog) {
                $onLog($chunk);
            }
        };

        $append("[deploy] 开始部署 #" . $id . " " . $row['host'] . "\n");

        // 把安装脚本拉到远端再非交互执行（避免管道里交互 read）
        $remote = <<<'BASH'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export TKSWARM_SKIP_FIREWALL=1
TMP=/tmp/tkswarm-install-ubuntu.sh
curl -fsSL "__GIT_RAW__" -o "$TMP" || wget -qO "$TMP" "__GIT_RAW__"
chmod +x "$TMP"
bash "$TMP" \
  --api-base "__API_BASE__" \
  --port "__PORT__" \
  --git-url "__GIT_URL__" \
  --branch "__GIT_BRANCH__" \
  --host-id "__HOST_ID__" \
  --skip-firewall \
  __VNC_ARG__
BASH;

        $rawUrl = self::rawInstallScriptUrl($gitUrl, $gitBranch);
        $vncArg = $vnc !== '' ? ("--vnc-password " . self::shellSingleQuote($vnc)) : '';
        $remote = str_replace(
            ['__GIT_RAW__', '__API_BASE__', '__PORT__', '__GIT_URL__', '__GIT_BRANCH__', '__HOST_ID__', '__VNC_ARG__'],
            [
                $rawUrl,
                $apiBase,
                (string)$browserPort,
                $gitUrl,
                $gitBranch,
                $hostId,
                $vncArg,
            ],
            $remote
        );

        // 用 bash -lc 包一层，兼容非交互 shell
        $wrapped = 'bash -lc ' . self::shellSingleQuote($remote);
        $res = SshClient::exec(self::sshOptsFromRow($row, 1800), $wrapped);

        $append((string)($res['stdout'] ?? ''));
        if (trim((string)($res['stderr'] ?? '')) !== '') {
            $append("\n[stderr]\n" . (string)$res['stderr'] . "\n");
        }

        if (!$res['ok']) {
            $err = (string)($res['error'] ?? '部署失败');
            $append("\n[deploy] 失败: " . $err . "\n");
            self::patch($id, [
                'status' => 'failed',
                'health_ok' => 0,
                'last_error' => $err,
                'deploy_log' => $log,
                'browser_api_url' => self::browserUrlFromRow($row),
            ]);
            return ['ok' => false, 'message' => $err, 'log' => $log];
        }

        $append("\n[deploy] 安装脚本执行完成，开始健康检测…\n");
        // 服务刚起可能需几秒
        sleep(3);
        $health = self::checkHealth($id);
        $append('[deploy] health: ' . $health['message'] . "\n");

        if (!$health['ok']) {
            // 再等一次
            sleep(5);
            $health = self::checkHealth($id);
            $append('[deploy] health retry: ' . $health['message'] . "\n");
        }

        $row2 = self::find($id) ?: $row;
        self::patch($id, [
            'status' => !empty($health['ok']) ? 'ok' : 'failed',
            'health_ok' => !empty($health['ok']) ? 1 : 0,
            'last_error' => !empty($health['ok']) ? '' : (string)($health['message'] ?? '健康检测失败'),
            'deploy_log' => $log,
            'browser_api_url' => self::browserUrlFromRow($row2),
        ]);

        return [
            'ok' => !empty($health['ok']),
            'message' => !empty($health['ok']) ? '部署成功且健康检测通过' : ('部署完成但健康检测失败：' . ($health['message'] ?? '')),
            'log' => $log,
        ];
    }

    public static function spawnDeployWorker(int $id): bool
    {
        $php = PHP_BINARY ?: 'php';
        $script = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ssh-deploy-worker.php';
        if (!is_file($script)) {
            return false;
        }
        $id = (int)$id;
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start /B "" ' . self::winQuote($php) . ' ' . self::winQuote($script) . ' ' . $id;
            pclose(popen($cmd, 'r'));
            return true;
        }
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $id
            . ' >> ' . escapeshellarg(sys_get_temp_dir() . '/tkswarm-ssh-deploy.log') . ' 2>&1 &';
        exec($cmd);
        return true;
    }

    /** @param array<string,mixed> $fields */
    public static function patch(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $cols = [];
        $params = [];
        foreach ($fields as $k => $v) {
            $cols[] = '`' . str_replace('`', '', (string)$k) . '`=?';
            $params[] = $v;
        }
        $params[] = $id;
        DB::query('UPDATE ssh_servers SET ' . implode(',', $cols) . ', updated_at=CURRENT_TIMESTAMP WHERE id=?', $params);
    }

    private static function rawInstallScriptUrl(string $gitUrl, string $branch): string
    {
        // https://github.com/owner/repo.git → raw.githubusercontent.com/owner/repo/branch/deploy/install-ubuntu.sh
        if (preg_match('#github\.com[:/]([^/]+)/([^/.]+)(?:\.git)?#i', $gitUrl, $m)) {
            return 'https://raw.githubusercontent.com/' . $m[1] . '/' . $m[2] . '/' . rawurlencode($branch) . '/deploy/install-ubuntu.sh';
        }
        return 'https://raw.githubusercontent.com/MinWU2023/tkswarm-linuxbrowse/' . rawurlencode($branch) . '/deploy/install-ubuntu.sh';
    }

    private static function shellSingleQuote(string $s): string
    {
        return "'" . str_replace("'", "'\\''", $s) . "'";
    }

    private static function winQuote(string $value): string
    {
        if ($value === '') {
            return '""';
        }
        if (!preg_match('/[\s"]/', $value)) {
            return $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
