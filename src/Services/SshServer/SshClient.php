<?php
declare(strict_types=1);

namespace App\Services\SshServer;

/**
 * 通过本机 OpenSSH 客户端执行远程命令（密码 AskPass 或私钥）。
 */
class SshClient
{
    /**
     * @param array{
     *   host:string,
     *   port?:int,
     *   username?:string,
     *   password?:string,
     *   privateKey?:string,
     *   timeout?:int
     * } $opts
     * @return array{ok:bool,exitCode:int,stdout:string,stderr:string,error?:string}
     */
    public static function exec(array $opts, string $remoteCommand): array
    {
        $host = trim((string)($opts['host'] ?? ''));
        $user = trim((string)($opts['username'] ?? 'root'));
        $port = (int)($opts['port'] ?? 22);
        $password = (string)($opts['password'] ?? '');
        $privateKey = trim((string)($opts['privateKey'] ?? ''));
        $timeout = max(30, (int)($opts['timeout'] ?? 600));

        if ($host === '' || $user === '') {
            return ['ok' => false, 'exitCode' => 1, 'stdout' => '', 'stderr' => '', 'error' => '缺少 host/username'];
        }
        if ($privateKey === '' && $password === '') {
            return ['ok' => false, 'exitCode' => 1, 'stdout' => '', 'stderr' => '', 'error' => '请提供密码或私钥'];
        }

        $sshBin = self::findSshBinary();
        if ($sshBin === null) {
            return ['ok' => false, 'exitCode' => 1, 'stdout' => '', 'stderr' => '', 'error' => '本机未找到 ssh 可执行文件（需安装 OpenSSH 客户端）'];
        }

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tkswarm-ssh-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            return ['ok' => false, 'exitCode' => 1, 'stdout' => '', 'stderr' => '', 'error' => '无法创建临时目录'];
        }

        $keyFile = null;
        $askPass = null;
        $stdoutFile = $tmpDir . DIRECTORY_SEPARATOR . 'out.txt';
        $stderrFile = $tmpDir . DIRECTORY_SEPARATOR . 'err.txt';

        try {
            $args = [
                $sshBin,
                '-p', (string)max(1, $port),
                '-o', 'BatchMode=' . ($privateKey !== '' && $password === '' ? 'yes' : 'no'),
                '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'),
                '-o', 'ConnectTimeout=20',
                '-o', 'ServerAliveInterval=15',
                '-o', 'ServerAliveCountMax=40',
                '-o', 'PreferredAuthentications=' . ($privateKey !== '' ? 'publickey,password' : 'password,keyboard-interactive'),
            ];

            if ($privateKey !== '') {
                $keyFile = $tmpDir . DIRECTORY_SEPARATOR . 'id_key';
                $keyBody = str_replace(["\r\n", "\r"], "\n", $privateKey);
                if (!str_ends_with($keyBody, "\n")) {
                    $keyBody .= "\n";
                }
                file_put_contents($keyFile, $keyBody);
                if (PHP_OS_FAMILY !== 'Windows') {
                    @chmod($keyFile, 0600);
                }
                $args[] = '-i';
                $args[] = $keyFile;
            }

            $target = $user . '@' . $host;
            $args[] = $target;
            $args[] = $remoteCommand;

            $env = self::baseEnv();
            if ($password !== '') {
                $askPass = self::writeAskPass($tmpDir, $password);
                $env['DISPLAY'] = $env['DISPLAY'] ?? '127.0.0.1:0';
                $env['SSH_ASKPASS'] = $askPass;
                $env['SSH_ASKPASS_REQUIRE'] = 'force';
                // 无 TTY 时强制走 AskPass
                $env['SSH_ASKPASS_REQUIRE'] = 'force';
            }

            $cmdLine = self::buildCommandLine($args);
            $desc = [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ];
            $proc = proc_open($cmdLine, $desc, $pipes, null, $env, [
                'bypass_shell' => PHP_OS_FAMILY !== 'Windows',
            ]);
            if (!is_resource($proc)) {
                return ['ok' => false, 'exitCode' => 1, 'stdout' => '', 'stderr' => '', 'error' => '无法启动 ssh 进程'];
            }
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            $start = time();
            $exitCode = -1;
            while (true) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    $exitCode = (int)$status['exitcode'];
                    break;
                }
                if ((time() - $start) >= $timeout) {
                    proc_terminate($proc, 9);
                    proc_close($proc);
                    return [
                        'ok' => false,
                        'exitCode' => 124,
                        'stdout' => (string)@file_get_contents($stdoutFile),
                        'stderr' => (string)@file_get_contents($stderrFile),
                        'error' => 'SSH 执行超时（' . $timeout . 's）',
                    ];
                }
                usleep(200000);
            }
            proc_close($proc);

            $stdout = (string)@file_get_contents($stdoutFile);
            $stderr = (string)@file_get_contents($stderrFile);
            $ok = $exitCode === 0;
            return [
                'ok' => $ok,
                'exitCode' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'error' => $ok ? null : self::summarizeError($stderr, $stdout, $exitCode),
            ];
        } finally {
            self::cleanupDir($tmpDir);
        }
    }

    /** @return array{ok:bool,exitCode:int,stdout:string,stderr:string,error?:string} */
    public static function testConnection(array $opts): array
    {
        $opts['timeout'] = min(60, (int)($opts['timeout'] ?? 30));
        return self::exec($opts, 'echo TKSWARM_SSH_OK; uname -a; whoami');
    }

    private static function findSshBinary(): ?string
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                getenv('SystemRoot') ? (rtrim((string)getenv('SystemRoot'), '\\/') . '\\System32\\OpenSSH\\ssh.exe') : '',
                'C:\\Windows\\System32\\OpenSSH\\ssh.exe',
                'ssh.exe',
                'ssh',
            ]
            : ['/usr/bin/ssh', '/bin/ssh', 'ssh'];
        foreach ($candidates as $bin) {
            if ($bin === '') {
                continue;
            }
            if (is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }
        $which = PHP_OS_FAMILY === 'Windows' ? 'where ssh 2>NUL' : 'command -v ssh 2>/dev/null';
        $out = trim((string)@shell_exec($which));
        if ($out !== '') {
            $first = preg_split('/\r\n|\n/', $out)[0] ?? '';
            $first = trim($first);
            if ($first !== '') {
                return $first;
            }
        }
        return null;
    }

    private static function writeAskPass(string $tmpDir, string $password): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = $tmpDir . DIRECTORY_SEPARATOR . 'askpass.cmd';
            // cmd 回显密码；避免特殊字符破坏脚本：用环境变量传递
            file_put_contents($path, "@echo off\r\necho %TKSWARM_SSH_PASS%\r\n");
            putenv('TKSWARM_SSH_PASS=' . $password);
            return $path;
        }
        $path = $tmpDir . DIRECTORY_SEPARATOR . 'askpass.sh';
        $escaped = str_replace(["\\", "'"], ["\\\\", "'\\''"], $password);
        file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' '" . $escaped . "'\n");
        @chmod($path, 0700);
        return $path;
    }

    /** @param list<string> $args */
    private static function buildCommandLine(array $args): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $parts = [];
            foreach ($args as $a) {
                $parts[] = self::winQuote($a);
            }
            return implode(' ', $parts);
        }
        $parts = [];
        foreach ($args as $a) {
            $parts[] = escapeshellarg($a);
        }
        return implode(' ', $parts);
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

    /** @return array<string,string> */
    private static function baseEnv(): array
    {
        $env = [];
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $env[$k] = (string)$v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v)) && !isset($env[$k])) {
                $env[$k] = (string)$v;
            }
        }
        // 去掉可能影响 AskPass 的 SSH_AUTH_SOCK 以外的交互设置
        unset($env['SSH_TTY']);
        return $env;
    }

    private static function summarizeError(string $stderr, string $stdout, int $exitCode): string
    {
        $text = trim($stderr !== '' ? $stderr : $stdout);
        $text = preg_replace('/\s+/', ' ', $text) ?: '';
        if ($text === '') {
            return 'SSH 失败，退出码 ' . $exitCode;
        }
        return mb_substr($text, 0, 500);
    }

    private static function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            @unlink($dir . DIRECTORY_SEPARATOR . $f);
        }
        @rmdir($dir);
        putenv('TKSWARM_SSH_PASS');
    }
}
