<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;

/**
 * Node.js / 比特浏览器安装包：按平台(win|mac)维护，供 EXE 拉取直链自动安装
 */
class EnvInstallerController extends Controller
{
    /** 公开：已发布目录 */
    public function catalog(Request $request): Response
    {
        $this->ensureSchema();
        $kind = $this->normKind($request->get('kind', ''));
        $platform = $this->normPlatform($request->get('platform', ''));
        $sql = "SELECT * FROM env_installers WHERE status='published'";
        $params = [];
        if ($kind !== '') {
            $sql .= ' AND kind=?';
            $params[] = $kind;
        }
        if ($platform !== '') {
            $sql .= ' AND platform=?';
            $params[] = $platform;
        }
        $sql .= ' ORDER BY is_recommended DESC, sort_order ASC, id DESC LIMIT 100';
        $rows = DB::select($sql, $params) ?: [];
        return $this->success([
            'items' => array_map([$this, 'mapPublic'], $rows),
            'kind' => $kind ?: null,
            'platform' => $platform ?: null,
        ]);
    }

    /** 公开：推荐项 */
    public function recommended(Request $request): Response
    {
        $this->ensureSchema();
        $kind = $this->normKind($request->get('kind', 'node')) ?: 'node';
        $platform = $this->normPlatform($request->get('platform', 'win')) ?: 'win';
        $row = DB::selectOne(
            "SELECT * FROM env_installers WHERE status='published' AND kind=? AND platform=? AND is_recommended=1 ORDER BY sort_order ASC, id DESC LIMIT 1",
            [$kind, $platform]
        );
        if (!$row) {
            $row = DB::selectOne(
                "SELECT * FROM env_installers WHERE status='published' AND kind=? AND platform=? ORDER BY sort_order ASC, id DESC LIMIT 1",
                [$kind, $platform]
            );
        }
        if (!$row) {
            return $this->error('暂无可用安装包', 404);
        }
        return $this->success($this->mapPublic($row));
    }

    /** 公开：下载（本站上传文件）或 302 到外链 */
    public function download(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id]);
        if (!$row || ($row['status'] ?? '') !== 'published') {
            return $this->error('安装包不可用', 404);
        }
        $path = (string)($row['file_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            DB::query('UPDATE env_installers SET download_count=download_count+1 WHERE id=?', [(int)$id]);
            $name = (string)($row['file_name'] ?: ('installer-' . $row['version']));
            $name = preg_replace('/[^\w.\-]+/', '_', $name) ?: 'installer.bin';
            $mime = 'application/octet-stream';
            if (preg_match('/\.msi$/i', $name)) {
                $mime = 'application/x-msi';
            } elseif (preg_match('/\.pkg$/i', $name)) {
                $mime = 'application/octet-stream';
            } elseif (preg_match('/\.exe$/i', $name)) {
                $mime = 'application/vnd.microsoft.portable-executable';
            } elseif (preg_match('/\.dmg$/i', $name)) {
                $mime = 'application/x-apple-diskimage';
            } elseif (preg_match('/\.zip$/i', $name)) {
                $mime = 'application/zip';
            }
            return Response::file($path, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'Content-Length' => (string)filesize($path),
                'Cache-Control' => 'no-store',
            ]);
        }
        $url = trim((string)($row['download_url'] ?? ''));
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            DB::query('UPDATE env_installers SET download_count=download_count+1 WHERE id=?', [(int)$id]);
            return Response::make('', 302, ['Location' => $url]);
        }
        return $this->error('安装包文件或下载链接未配置', 404);
    }

    // ---- admin ----
    public function list(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $kind = $this->normKind($request->get('kind', ''));
        $platform = $this->normPlatform($request->get('platform', ''));
        $sql = 'SELECT * FROM env_installers WHERE 1=1';
        $params = [];
        if ($kind !== '') {
            $sql .= ' AND kind=?';
            $params[] = $kind;
        }
        if ($platform !== '') {
            $sql .= ' AND platform=?';
            $params[] = $platform;
        }
        $sql .= ' ORDER BY kind ASC, platform ASC, sort_order ASC, id DESC';
        $rows = DB::select($sql, $params) ?: [];
        return $this->success(array_map([$this, 'mapAdmin'], $rows));
    }

    public function create(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $d = $request->json() ?: [];
        $kind = $this->normKind($d['kind'] ?? '');
        $platform = $this->normPlatform($d['platform'] ?? '');
        $version = trim((string)($d['version'] ?? ''));
        $title = trim((string)($d['title'] ?? ''));
        if ($kind === '' || $platform === '' || $version === '') {
            return $this->error('请填写类型、系统与版本号', 422);
        }
        if ($title === '') {
            $title = $version;
        }
        $downloadUrl = trim((string)($d['downloadUrl'] ?? $d['download_url'] ?? ''));
        $status = $this->normStatus($d['status'] ?? 'draft');
        $recommended = !empty($d['isRecommended'] ?? $d['is_recommended'] ?? false) ? 1 : 0;
        $sort = (int)($d['sortOrder'] ?? $d['sort_order'] ?? 0);
        $notes = trim((string)($d['notes'] ?? ''));
        $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;
        try {
            DB::query(
                'INSERT INTO env_installers(kind, platform, version, title, download_url, status, is_recommended, sort_order, notes, owner_id, published_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$kind, $platform, $version, $title, $downloadUrl, $status, $recommended, $sort, $notes, $this->writeOwnerId() ?: null, $publishedAt]
            );
        } catch (\Throwable $e) {
            return $this->error('同类型同系统版本号已存在', 409);
        }
        $id = (int)DB::getPdo()->lastInsertId();
        if ($recommended) {
            $this->clearOtherRecommended($kind, $platform, $id);
        }
        return $this->success($this->mapAdmin(DB::selectOne('SELECT * FROM env_installers WHERE id=?', [$id])), '已创建');
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $d = $request->json() ?: [];
        $kind = $this->normKind($d['kind'] ?? $row['kind']) ?: $row['kind'];
        $platform = $this->normPlatform($d['platform'] ?? $row['platform']) ?: $row['platform'];
        $version = trim((string)($d['version'] ?? $row['version']));
        $title = trim((string)($d['title'] ?? $row['title']));
        $downloadUrl = array_key_exists('downloadUrl', $d) || array_key_exists('download_url', $d)
            ? trim((string)($d['downloadUrl'] ?? $d['download_url'] ?? ''))
            : (string)$row['download_url'];
        $status = $this->normStatus($d['status'] ?? $row['status']);
        $recommended = array_key_exists('isRecommended', $d) || array_key_exists('is_recommended', $d)
            ? (!empty($d['isRecommended'] ?? $d['is_recommended']) ? 1 : 0)
            : (int)$row['is_recommended'];
        $sort = (int)($d['sortOrder'] ?? $d['sort_order'] ?? $row['sort_order']);
        $notes = array_key_exists('notes', $d) ? trim((string)$d['notes']) : (string)$row['notes'];
        $publishedAt = $row['published_at'];
        if ($status === 'published' && empty($publishedAt)) {
            $publishedAt = date('Y-m-d H:i:s');
        }
        try {
            DB::query(
                'UPDATE env_installers SET kind=?, platform=?, version=?, title=?, download_url=?, status=?, is_recommended=?, sort_order=?, notes=?, published_at=? WHERE id=?',
                [$kind, $platform, $version, $title, $downloadUrl, $status, $recommended, $sort, $notes, $publishedAt, (int)$id]
            );
        } catch (\Throwable $e) {
            return $this->error('更新失败或版本号冲突', 409);
        }
        if ($recommended) {
            $this->clearOtherRecommended($kind, $platform, (int)$id);
        }
        return $this->success($this->mapAdmin(DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id])), '已更新');
    }

    public function upload(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->error('请上传安装包文件', 422);
        }
        $file = $_FILES['file'];
        $orig = (string)($file['name'] ?? 'installer.bin');
        $kind = (string)$row['kind'];
        $platform = (string)$row['platform'];
        if ($kind === 'node') {
            if ($platform === 'win' && !preg_match('/\.(msi|exe)$/i', $orig)) {
                return $this->error('Windows Node 请上传 .msi 或 .exe', 422);
            }
            if ($platform === 'mac' && !preg_match('/\.(pkg|dmg)$/i', $orig)) {
                return $this->error('macOS Node 请上传 .pkg 或 .dmg', 422);
            }
        } else {
            if ($platform === 'win' && !preg_match('/\.(exe|msi|zip)$/i', $orig)) {
                return $this->error('Windows 比特请上传 .exe / .msi / .zip', 422);
            }
            if ($platform === 'mac' && !preg_match('/\.(dmg|pkg|zip)$/i', $orig)) {
                return $this->error('macOS 比特请上传 .dmg / .pkg / .zip', 422);
            }
        }
        $dir = $this->storageDir();
        $ext = pathinfo($orig, PATHINFO_EXTENSION) ?: 'bin';
        $destName = $kind . '-' . $platform . '-v' . (int)$id . '-' . time() . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        $dest = $dir . DIRECTORY_SEPARATOR . $destName;
        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            return $this->error('保存文件失败', 500);
        }
        $old = (string)($row['file_path'] ?? '');
        if ($old !== '' && is_file($old)) {
            @unlink($old);
        }
        $size = (int)filesize($dest);
        $sha = hash_file('sha256', $dest) ?: '';
        DB::query(
            'UPDATE env_installers SET file_path=?, file_name=?, file_size=?, sha256=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$dest, $orig, $size, $sha, (int)$id]
        );
        return $this->success($this->mapAdmin(DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id])), '安装包已上传');
    }

    public function publish(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $hasFile = !empty($row['file_path']) && is_file((string)$row['file_path']);
        $hasUrl = trim((string)($row['download_url'] ?? '')) !== '';
        if (!$hasFile && !$hasUrl) {
            return $this->error('请先上传安装包或填写下载直链再发布', 422);
        }
        DB::query(
            "UPDATE env_installers SET status='published', published_at=COALESCE(published_at, CURRENT_TIMESTAMP) WHERE id=?",
            [(int)$id]
        );
        return $this->success($this->mapAdmin(DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id])), '已发布');
    }

    public function setRecommended(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        if (($row['status'] ?? '') !== 'published') {
            return $this->error('仅已发布版本可设为推荐', 422);
        }
        $this->clearOtherRecommended((string)$row['kind'], (string)$row['platform'], (int)$id);
        DB::query('UPDATE env_installers SET is_recommended=1 WHERE id=?', [(int)$id]);
        return $this->success($this->mapAdmin(DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id])), '已设为推荐');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM env_installers WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $path = (string)($row['file_path'] ?? '');
        DB::query('DELETE FROM env_installers WHERE id=?', [(int)$id]);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        return $this->success(true);
    }

    private function clearOtherRecommended(string $kind, string $platform, int $keepId): void
    {
        DB::query(
            'UPDATE env_installers SET is_recommended=0 WHERE kind=? AND platform=? AND id<>?',
            [$kind, $platform, $keepId]
        );
    }

    private function mapPublic(array $r): array
    {
        $id = (int)$r['id'];
        $hasFile = !empty($r['file_path']) && is_file((string)$r['file_path']);
        $extUrl = trim((string)($r['download_url'] ?? ''));
        // EXE 一律优先走本站 download 接口（可 302 到外链或下发文件）
        $downloadUrl = '/api/env-installers/' . $id . '/download';
        return [
            'id' => $id,
            'kind' => (string)$r['kind'],
            'platform' => (string)$r['platform'],
            'version' => (string)$r['version'],
            'title' => (string)($r['title'] ?? ''),
            'label' => (string)(($r['title'] ?? '') !== '' ? $r['title'] : $r['version']),
            'recommended' => !empty($r['is_recommended']),
            'isRecommended' => !empty($r['is_recommended']),
            'hasFile' => $hasFile,
            'hasExternalUrl' => $extUrl !== '',
            'fileSize' => (int)($r['file_size'] ?? 0),
            'downloadUrl' => $downloadUrl,
            'fileName' => (string)($r['file_name'] ?? ''),
            'sortOrder' => (int)($r['sort_order'] ?? 0),
            'notes' => (string)($r['notes'] ?? ''),
        ];
    }

    private function mapAdmin(?array $r): array
    {
        if (!$r) {
            return [];
        }
        $pub = $this->mapPublic($r);
        return array_merge($pub, [
            'downloadUrlRaw' => (string)($r['download_url'] ?? ''),
            'status' => (string)($r['status'] ?? 'draft'),
            'downloadCount' => (int)($r['download_count'] ?? 0),
            'publishedAt' => $r['published_at'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ]);
    }

    private function normKind(mixed $v): string
    {
        $k = strtolower(trim((string)$v));
        return in_array($k, ['node', 'bit', 'self', 'tkswarm'], true) ? $k : '';
    }

    private function normPlatform(mixed $v): string
    {
        $p = strtolower(trim((string)$v));
        if ($p === 'windows' || $p === 'win32') {
            $p = 'win';
        }
        if ($p === 'darwin' || $p === 'macos' || $p === 'osx') {
            $p = 'mac';
        }
        return in_array($p, ['win', 'mac'], true) ? $p : '';
    }

    private function normStatus(mixed $v): string
    {
        $s = strtolower(trim((string)$v));
        return in_array($s, ['draft', 'published', 'archived'], true) ? $s : 'draft';
    }

    private function storageDir(): string
    {
        $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'env-installers';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        DB::query(
            "CREATE TABLE IF NOT EXISTS env_installers (
              id INT AUTO_INCREMENT PRIMARY KEY,
              kind VARCHAR(16) NOT NULL,
              platform VARCHAR(16) NOT NULL,
              version VARCHAR(64) NOT NULL,
              title VARCHAR(191) NOT NULL DEFAULT '',
              download_url VARCHAR(1024) NOT NULL DEFAULT '',
              file_path VARCHAR(512) NOT NULL DEFAULT '',
              file_name VARCHAR(255) NOT NULL DEFAULT '',
              file_size BIGINT NOT NULL DEFAULT 0,
              sha256 VARCHAR(64) NOT NULL DEFAULT '',
              status VARCHAR(32) NOT NULL DEFAULT 'draft',
              is_recommended TINYINT(1) NOT NULL DEFAULT 0,
              sort_order INT NOT NULL DEFAULT 0,
              download_count INT NOT NULL DEFAULT 0,
              notes VARCHAR(500) NOT NULL DEFAULT '',
              owner_id INT NULL,
              published_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uk_env_kind_plat_ver (kind, platform, version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
