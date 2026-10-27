<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;

/**
 * 客户端代码版本包：后台 CRUD + 公开目录/下载（供 tkswarm-exe）
 */
class ClientVersionController extends Controller
{
    public function list(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $rows = DB::select(
            "SELECT * FROM client_versions WHERE 1=1{$ownSql} ORDER BY is_latest DESC, id DESC",
            $ownParams
        );
        return $this->success(array_map([$this, 'mapRow'], $rows ?: []));
    }

    /** 公开：已发布版本目录 */
    public function catalog(Request $request): Response
    {
        $this->ensureSchema();
        $channel = trim((string)$request->get('channel', ''));
        $sql = "SELECT id, version, title, channel, status, description, file_size, download_count, is_latest, published_at, updated_at
                FROM client_versions WHERE status='published' AND (file_path<>'' OR download_url<>'')";
        $params = [];
        if ($channel !== '') {
            $sql .= ' AND channel=?';
            $params[] = $channel;
        }
        $sql .= ' ORDER BY is_latest DESC, id DESC LIMIT 50';
        $rows = DB::select($sql, $params);
        return $this->success([
            'items' => array_map([$this, 'mapPublic'], $rows ?: []),
            'apiBaseHint' => '',
        ]);
    }

    public function latest(Request $request): Response
    {
        $this->ensureSchema();
        $channel = trim((string)$request->get('channel', 'stable')) ?: 'stable';
        $row = DB::selectOne(
            "SELECT * FROM client_versions WHERE status='published' AND channel=? AND is_latest=1 AND (file_path<>'' OR download_url<>'') LIMIT 1",
            [$channel]
        );
        if (!$row) {
            $row = DB::selectOne(
                "SELECT * FROM client_versions WHERE status='published' AND channel=? AND (file_path<>'' OR download_url<>'') ORDER BY id DESC LIMIT 1",
                [$channel]
            );
        }
        if (!$row) {
            return $this->error('暂无已发布版本', 404);
        }
        return $this->success($this->mapPublic($row));
    }

    public function detail(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('版本不存在', 404);
        }
        // 公开详情仅已发布；管理端走 list 已鉴权
        if (($row['status'] ?? '') !== 'published') {
            return $this->error('版本未发布', 404);
        }
        return $this->success($this->mapPublic($row));
    }

    public function create(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $version = trim((string)$request->input('version', ''));
        $title = trim((string)$request->input('title', ''));
        if ($version === '' || $title === '') {
            return $this->error('版本号与标题不能为空', 422);
        }
        $channel = $this->normChannel($request->input('channel', 'stable'));
        $status = $this->normStatus($request->input('status', 'draft'));
        $description = trim((string)$request->input('description', ''));
        $downloadUrl = trim((string)$request->input('downloadUrl', $request->input('download_url', '')));
        try {
            DB::query(
                'INSERT INTO client_versions(version, title, channel, status, description, download_url, owner_id)
                 VALUES (?,?,?,?,?,?,?)',
                [$version, $title, $channel, $status, $description, $downloadUrl, $this->writeOwnerId()]
            );
        } catch (\Throwable $e) {
            return $this->error('版本号在该通道已存在', 409);
        }
        $id = (int)DB::getPdo()->lastInsertId();
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM client_versions WHERE id=?', [$id])), '版本已创建');
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($row)) {
            return $err;
        }
        $version = trim((string)$request->input('version', $row['version']));
        $title = trim((string)$request->input('title', $row['title']));
        if ($version === '' || $title === '') {
            return $this->error('版本号与标题不能为空', 422);
        }
        $channel = $this->normChannel($request->input('channel', $row['channel']));
        $status = $this->normStatus($request->input('status', $row['status']));
        $description = trim((string)$request->input('description', $row['description'] ?? ''));
        $downloadUrl = array_key_exists('downloadUrl', $request->all()) || array_key_exists('download_url', $request->all())
            ? trim((string)$request->input('downloadUrl', $request->input('download_url', '')))
            : (string)($row['download_url'] ?? '');
        try {
            DB::query(
                'UPDATE client_versions SET version=?, title=?, channel=?, status=?, description=?, download_url=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$version, $title, $channel, $status, $description, $downloadUrl, (int)$id]
            );
        } catch (\Throwable $e) {
            return $this->error('版本号冲突或更新失败', 409);
        }
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id])), '版本已更新');
    }

    public function upload(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($row)) {
            return $err;
        }
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->error('请上传 zip 代码包', 422);
        }
        $file = $_FILES['file'];
        $orig = (string)($file['name'] ?? 'package.zip');
        if (!preg_match('/\.zip$/i', $orig)) {
            return $this->error('仅支持 .zip 文件', 422);
        }
        $dir = $this->storageDir();
        $destName = 'v' . (int)$id . '-' . time() . '-' . bin2hex(random_bytes(3)) . '.zip';
        $dest = $dir . DIRECTORY_SEPARATOR . $destName;
        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            return $this->error('保存文件失败', 500);
        }
        $old = (string)($row['file_path'] ?? '');
        if ($old !== '' && is_file($old) && str_starts_with(realpath($old) ?: '', realpath($dir) ?: '###')) {
            @unlink($old);
        }
        $size = (int)filesize($dest);
        $sha = hash_file('sha256', $dest) ?: '';
        DB::query(
            'UPDATE client_versions SET file_path=?, file_name=?, file_size=?, sha256=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$dest, $orig, $size, $sha, (int)$id]
        );
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id])), '代码包已上传');
    }

    public function publish(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($row)) {
            return $err;
        }
        $hasFile = !empty($row['file_path']) && is_file((string)$row['file_path']);
        $hasUrl = trim((string)($row['download_url'] ?? '')) !== '';
        if (!$hasFile && !$hasUrl) {
            return $this->error('请先上传代码包或填写包下载链接再发布', 422);
        }
        DB::query(
            "UPDATE client_versions SET status='published', published_at=COALESCE(published_at, CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [(int)$id]
        );
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id])), '版本已发布');
    }

    public function setLatest(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($row)) {
            return $err;
        }
        if (($row['status'] ?? '') !== 'published') {
            return $this->error('仅已发布版本可设为最新', 422);
        }
        $channel = (string)$row['channel'];
        DB::query('UPDATE client_versions SET is_latest=0 WHERE channel=?', [$channel]);
        DB::query('UPDATE client_versions SET is_latest=1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id])), '已设为最新');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($row)) {
            return $err;
        }
        $path = (string)($row['file_path'] ?? '');
        DB::query('DELETE FROM client_versions WHERE id=?', [(int)$id]);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        return $this->success(['id' => (int)$id], '版本已删除');
    }

    public function download(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM client_versions WHERE id=?', [(int)$id]);
        if (!$row || ($row['status'] ?? '') !== 'published') {
            return $this->error('版本不可下载', 404);
        }
        $path = (string)($row['file_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            DB::query('UPDATE client_versions SET download_count=download_count+1 WHERE id=?', [(int)$id]);
            $name = (string)($row['file_name'] ?: ('tkswarm-' . $row['version'] . '.zip'));
            $name = preg_replace('/[^\w.\-]+/', '_', $name) ?: 'package.zip';
            return Response::file($path, 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'Content-Length' => (string)filesize($path),
                'Cache-Control' => 'no-store',
            ]);
        }
        $url = trim((string)($row['download_url'] ?? ''));
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            DB::query('UPDATE client_versions SET download_count=download_count+1 WHERE id=?', [(int)$id]);
            return Response::make('', 302, ['Location' => $url]);
        }
        return $this->error('代码包文件或下载链接缺失', 404);
    }

    private function mapRow(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $hasFile = !empty($row['file_path']) && is_file((string)$row['file_path']);
        $extUrl = trim((string)($row['download_url'] ?? ''));
        $row['has_file'] = $hasFile;
        $row['hasFile'] = $hasFile;
        $row['hasUrl'] = $extUrl !== '';
        $row['downloadUrlRaw'] = $extUrl;
        $row['download_url'] = $extUrl;
        $row['file_size'] = (int)($row['file_size'] ?? 0);
        $row['download_count'] = (int)($row['download_count'] ?? 0);
        $row['is_latest'] = !empty($row['is_latest']);
        unset($row['file_path'], $row['sha256']);
        return $row;
    }

    private function mapPublic(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'version' => $row['version'],
            'title' => $row['title'] ?? '',
            'channel' => $row['channel'] ?? 'stable',
            'status' => $row['status'] ?? '',
            'description' => $row['description'] ?? '',
            'fileSize' => (int)($row['file_size'] ?? 0),
            'downloadCount' => (int)($row['download_count'] ?? 0),
            'isLatest' => !empty($row['is_latest']),
            'publishedAt' => $row['published_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
            'downloadUrl' => '/api/client-versions/' . (int)$row['id'] . '/download',
        ];
    }

    private function normChannel(mixed $v): string
    {
        $c = strtolower(trim((string)$v));
        return in_array($c, ['stable', 'beta'], true) ? $c : 'stable';
    }

    private function normStatus(mixed $v): string
    {
        $s = strtolower(trim((string)$v));
        return in_array($s, ['draft', 'published', 'archived'], true) ? $s : 'draft';
    }

    private function storageDir(): string
    {
        $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'client-versions';
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
            "CREATE TABLE IF NOT EXISTS client_versions (
              id INT AUTO_INCREMENT PRIMARY KEY,
              version VARCHAR(64) NOT NULL,
              title VARCHAR(191) NOT NULL DEFAULT '',
              channel VARCHAR(32) NOT NULL DEFAULT 'stable',
              status VARCHAR(32) NOT NULL DEFAULT 'draft',
              description TEXT NULL,
              download_url VARCHAR(1024) NOT NULL DEFAULT '',
              file_path VARCHAR(512) NOT NULL DEFAULT '',
              file_name VARCHAR(255) NOT NULL DEFAULT '',
              file_size BIGINT NOT NULL DEFAULT 0,
              sha256 VARCHAR(64) NOT NULL DEFAULT '',
              download_count INT NOT NULL DEFAULT 0,
              is_latest TINYINT NOT NULL DEFAULT 0,
              owner_id INT NULL,
              published_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uk_client_version (version, channel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        try {
            $col = DB::selectOne("SHOW COLUMNS FROM client_versions LIKE 'download_url'");
            if (!$col) {
                DB::query("ALTER TABLE client_versions ADD COLUMN download_url VARCHAR(1024) NOT NULL DEFAULT '' AFTER description");
            }
        } catch (\Throwable $e) {
        }
    }
}
