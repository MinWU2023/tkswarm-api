<?php
declare(strict_types=1);

namespace App\Services\Github;

use App\Core\Database\DB;
use App\Services\SecretStore;

/**
 * GitHub Release Assets 上传 + CDN/镜像链接生成。
 */
class GithubReleaseCdnService
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `github_cdn_configs` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(100) NOT NULL DEFAULT '',
              `owner` VARCHAR(100) NOT NULL DEFAULT '',
              `repo` VARCHAR(150) NOT NULL DEFAULT '',
              `token_enc` TEXT NULL,
              `tag_prefix` VARCHAR(64) NOT NULL DEFAULT 'videos',
              `url_template` VARCHAR(500) NOT NULL DEFAULT 'https://github.com/{owner}/{repo}/releases/download/{tag}/{name}',
              `cdn_prefix` VARCHAR(300) NOT NULL DEFAULT '',
              `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
              `notes` VARCHAR(500) NOT NULL DEFAULT '',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_github_cdn_enabled` (`is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
        }
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM materials') ?: [], 'Field');
            $alters = [
                'cdn_url' => "ALTER TABLE materials ADD COLUMN cdn_url VARCHAR(1000) NOT NULL DEFAULT ''",
                'github_asset_url' => "ALTER TABLE materials ADD COLUMN github_asset_url VARCHAR(1000) NOT NULL DEFAULT ''",
                'github_config_id' => 'ALTER TABLE materials ADD COLUMN github_config_id INT NOT NULL DEFAULT 0',
                'cover_file_path' => "ALTER TABLE materials ADD COLUMN cover_file_path VARCHAR(1000) NOT NULL DEFAULT ''",
                'cover_file_name' => "ALTER TABLE materials ADD COLUMN cover_file_name VARCHAR(255) NOT NULL DEFAULT ''",
                'cover_cdn_url' => "ALTER TABLE materials ADD COLUMN cover_cdn_url VARCHAR(1000) NOT NULL DEFAULT ''",
                'cover_github_asset_url' => "ALTER TABLE materials ADD COLUMN cover_github_asset_url VARCHAR(1000) NOT NULL DEFAULT ''",
            ];
            foreach ($alters as $col => $sql) {
                if (!in_array($col, $cols, true)) {
                    try {
                        DB::query($sql);
                    } catch (\Throwable $e) {
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** @return array<string,mixed>|null */
    public static function getEnabledConfig(): ?array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM github_cdn_configs WHERE is_enabled=1 ORDER BY id DESC LIMIT 1');
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function listConfigs(): array
    {
        self::ensureSchema();
        return DB::select('SELECT * FROM github_cdn_configs ORDER BY is_enabled DESC, id DESC') ?: [];
    }

    public static function decryptToken(array $config): string
    {
        $enc = (string)($config['token_enc'] ?? '');
        if ($enc === '') {
            return '';
        }
        return SecretStore::decrypt($enc);
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function buildAssetUrl(array $config, string $tag, string $assetName): string
    {
        $owner = (string)($config['owner'] ?? '');
        $repo = (string)($config['repo'] ?? '');
        $tpl = trim((string)($config['url_template'] ?? ''));
        if ($tpl === '') {
            $tpl = 'https://github.com/{owner}/{repo}/releases/download/{tag}/{name}';
        }
        $url = str_replace(
            ['{owner}', '{repo}', '{tag}', '{name}'],
            [$owner, $repo, $tag, $assetName],
            $tpl
        );
        $prefix = rtrim(trim((string)($config['cdn_prefix'] ?? '')), '/');
        if ($prefix !== '') {
            // 前缀 + 原 URL（常见镜像：prefix/https://github.com/...）
            if (str_contains($prefix, '{url}')) {
                return str_replace('{url}', $url, $prefix);
            }
            return $prefix . '/' . ltrim($url, '/');
        }
        return $url;
    }

    /**
     * 上传本地文件到启用中的 GitHub Release；无启用配置返回 null。
     *
     * @return array{configId:int,tag:string,assetName:string,githubAssetUrl:string,cdnUrl:string}|null
     */
    public static function uploadMaterialFile(string $localPath, string $preferredName = '', int $materialId = 0): ?array
    {
        self::ensureSchema();
        $config = self::getEnabledConfig();
        if (!$config) {
            return null;
        }
        if ($localPath === '' || !is_file($localPath)) {
            throw new \RuntimeException('本地视频文件不存在，无法上传 GitHub');
        }
        $token = self::decryptToken($config);
        if ($token === '') {
            throw new \RuntimeException('GitHub 配置缺少有效 Token');
        }
        $owner = trim((string)($config['owner'] ?? ''));
        $repo = trim((string)($config['repo'] ?? ''));
        if ($owner === '' || $repo === '') {
            throw new \RuntimeException('GitHub 配置缺少 owner/repo');
        }

        $ext = strtolower(pathinfo($preferredName !== '' ? $preferredName : $localPath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'mp4';
        }
        $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($preferredName !== '' ? $preferredName : basename($localPath), PATHINFO_FILENAME)) ?: 'video';
        $safeBase = trim($safeBase, '-._') ?: 'video';
        $assetName = ($materialId > 0 ? ('m' . $materialId . '-') : '') . $safeBase . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;

        $tagPrefix = trim((string)($config['tag_prefix'] ?? 'videos')) ?: 'videos';
        $tagPrefix = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $tagPrefix) ?: 'videos';
        $tag = $tagPrefix . '-' . date('Ymd');

        $release = self::ensureRelease($owner, $repo, $token, $tag);
        $releaseId = (int)($release['id'] ?? 0);
        if ($releaseId <= 0) {
            throw new \RuntimeException('无法创建或获取 GitHub Release');
        }

        $uploadUrl = sprintf(
            'https://uploads.github.com/repos/%s/%s/releases/%d/assets?name=%s',
            rawurlencode($owner),
            rawurlencode($repo),
            $releaseId,
            rawurlencode($assetName)
        );
        $size = filesize($localPath) ?: 0;
        $mime = 'application/octet-stream';
        $fh = fopen($localPath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('无法读取视频文件');
        }
        try {
            $asset = self::httpJson('POST', $uploadUrl, $token, [
                'Content-Type' => $mime,
                'Content-Length' => (string)$size,
            ], $fh, true);
        } finally {
            fclose($fh);
        }
        if (empty($asset['id'])) {
            $msg = (string)($asset['message'] ?? '上传 Release Asset 失败');
            throw new \RuntimeException($msg);
        }

        $githubAssetUrl = sprintf(
            'https://github.com/%s/%s/releases/download/%s/%s',
            $owner,
            $repo,
            $tag,
            $assetName
        );
        $browserUrl = (string)($asset['browser_download_url'] ?? '');
        if ($browserUrl !== '') {
            $githubAssetUrl = $browserUrl;
        }
        $cdnUrl = self::buildAssetUrl($config, $tag, $assetName);
        // 若模板生成失败则回退直链；有 cdn_prefix 时 buildAssetUrl 已处理
        if ($cdnUrl === '') {
            $cdnUrl = $githubAssetUrl;
        }

        return [
            'configId' => (int)($config['id'] ?? 0),
            'tag' => $tag,
            'assetName' => $assetName,
            'githubAssetUrl' => $githubAssetUrl,
            'cdnUrl' => $cdnUrl,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function ensureRelease(string $owner, string $repo, string $token, string $tag): array
    {
        $getUrl = sprintf(
            'https://api.github.com/repos/%s/%s/releases/tags/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($tag)
        );
        try {
            $existing = self::httpJson('GET', $getUrl, $token);
            if (!empty($existing['id'])) {
                return $existing;
            }
        } catch (\Throwable $e) {
            // 404：尚无该 tag 的 Release，继续创建
            if (!str_contains($e->getMessage(), 'HTTP 404') && !str_contains($e->getMessage(), 'Not Found')) {
                throw $e;
            }
        }
        $createUrl = sprintf(
            'https://api.github.com/repos/%s/%s/releases',
            rawurlencode($owner),
            rawurlencode($repo)
        );
        $payload = json_encode([
            'tag_name' => $tag,
            'name' => $tag,
            'body' => 'TkSwarm video CDN release ' . $tag,
            'draft' => false,
            'prerelease' => false,
        ], JSON_UNESCAPED_UNICODE);
        try {
            $created = self::httpJson('POST', $createUrl, $token, ['Content-Type' => 'application/json'], $payload);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // tag 已存在：再取一次
            if (str_contains($msg, 'already_exists') || str_contains($msg, 'Validation Failed')) {
                try {
                    $again = self::httpJson('GET', $getUrl, $token);
                    if (!empty($again['id'])) {
                        return $again;
                    }
                } catch (\Throwable $ignore) {
                }
            }
            // 空仓库无法建 tag/release：自动写 README 初始化后再试
            if (str_contains(strtolower($msg), 'repository is empty') || str_contains($msg, 'Validation Failed')) {
                self::bootstrapEmptyRepo($owner, $repo, $token);
                usleep(1200000);
                $created = self::httpJson('POST', $createUrl, $token, ['Content-Type' => 'application/json'], $payload);
            } else {
                throw $e;
            }
        }
        if (empty($created['id'])) {
            try {
                $again = self::httpJson('GET', $getUrl, $token);
                if (!empty($again['id'])) {
                    return $again;
                }
            } catch (\Throwable $ignore) {
            }
            $msg = (string)($created['message'] ?? '创建 Release 失败');
            throw new \RuntimeException($msg);
        }
        return $created;
    }

    private static function bootstrapEmptyRepo(string $owner, string $repo, string $token): void
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/contents/README.md',
            rawurlencode($owner),
            rawurlencode($repo)
        );
        $content = base64_encode("# {$repo}\n\nTkSwarm video CDN storage. Auto-initialized.\n");
        try {
            self::httpJson('PUT', $url, $token, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'chore: init repo for TkSwarm CDN releases',
                'content' => $content,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // 已有文件等冲突可忽略
            if (!str_contains($e->getMessage(), 'already_exists') && !str_contains($e->getMessage(), '422')) {
                throw new \RuntimeException(
                    'GitHub 仓库可能是空的且自动初始化失败：' . $e->getMessage()
                    . '。请先在仓库创建 README 或推送任意一次提交。'
                );
            }
        }
    }

    /**
     * @param resource|string|null $body
     * @param array<string,string> $extraHeaders
     * @return array<string,mixed>
     */
    private static function httpJson(
        string $method,
        string $url,
        string $token,
        array $extraHeaders = [],
        $body = null,
        bool $rawBody = false
    ): array {
        $headers = array_merge([
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $token,
            'User-Agent' => 'TkSwarm-GithubCDN',
            'X-GitHub-Api-Version' => '2022-11-28',
        ], $extraHeaders);
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                throw new \RuntimeException('GitHub 请求失败：' . ($err ?: 'curl error'));
            }
            $json = json_decode($resp, true);
            if (!is_array($json)) {
                throw new \RuntimeException('GitHub 返回非 JSON（HTTP ' . $code . '）');
            }
            if ($code >= 400 && empty($json['id'])) {
                $msg = (string)($json['message'] ?? ('HTTP ' . $code));
                $details = [];
                if (!empty($json['errors']) && is_array($json['errors'])) {
                    foreach ($json['errors'] as $errItem) {
                        if (!is_array($errItem)) {
                            continue;
                        }
                        if (!empty($errItem['message'])) {
                            $details[] = (string)$errItem['message'];
                            continue;
                        }
                        $parts = array_filter([
                            (string)($errItem['resource'] ?? ''),
                            (string)($errItem['field'] ?? ''),
                            (string)($errItem['code'] ?? ''),
                        ]);
                        if ($parts) {
                            $details[] = implode('.', $parts);
                        }
                    }
                }
                if ($details) {
                    $msg .= '（' . implode('；', $details) . '）';
                }
                if ($code === 404) {
                    $msg .= ' HTTP 404';
                }
                throw new \RuntimeException('GitHub API：' . $msg);
            }
            return $json;
        }

        // 无 curl 时仅支持字符串 body
        if (is_resource($body)) {
            throw new \RuntimeException('服务器未启用 curl，无法上传大文件到 GitHub');
        }
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => is_string($body) ? $body : '',
                'timeout' => 600,
                'ignore_errors' => true,
            ],
        ];
        $resp = @file_get_contents($url, false, stream_context_create($opts));
        if ($resp === false) {
            throw new \RuntimeException('GitHub 请求失败');
        }
        $json = json_decode($resp, true);
        return is_array($json) ? $json : [];
    }

    /**
     * 用启用配置的 Token 拉取远程视频流（供 Node 代理下载）。
     *
     * @return array{ok:bool,path?:string,mime?:string,error?:string,cleanup?:bool}
     */
    public static function downloadToTemp(string $url, int $configId = 0): array
    {
        self::ensureSchema();
        $config = null;
        if ($configId > 0) {
            $config = DB::selectOne('SELECT * FROM github_cdn_configs WHERE id=?', [$configId]);
        }
        if (!$config) {
            $config = self::getEnabledConfig();
        }
        $token = $config ? self::decryptToken($config) : '';
        $tmp = tempnam(sys_get_temp_dir(), 'ghcdn-');
        if ($tmp === false) {
            return ['ok' => false, 'error' => '无法创建临时文件'];
        }
        $out = $tmp . '.bin';
        @unlink($tmp);

        if (!function_exists('curl_init')) {
            @unlink($out);
            return ['ok' => false, 'error' => '服务器未启用 curl'];
        }
        $fh = fopen($out, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'error' => '无法写入临时文件'];
        }
        $headers = [
            'User-Agent: TkSwarm-GithubCDN',
            'Accept: application/octet-stream',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
            $headers[] = 'Accept: application/octet-stream';
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($ok === false || $code >= 400) {
            @unlink($out);
            return ['ok' => false, 'error' => $err !== '' ? $err : ('下载失败 HTTP ' . $code)];
        }
        return ['ok' => true, 'path' => $out, 'mime' => 'application/octet-stream', 'cleanup' => true];
    }
}
