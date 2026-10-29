<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;

class MaterialController extends Controller
{
    public function list(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $filters = [];
        $params = [];
        $this->applyMaterialOwnerFilter('m.owner_id', $filters, $params);
        $keyword = trim((string)$request->input('keyword', ''));
        if ($keyword !== '') {
            $filters[] = '(m.name LIKE ? OR m.file_name LIKE ? OR m.file_ext LIKE ? OR m.tags LIKE ? OR CAST(m.id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $status = trim((string)$request->input('status', ''));
        if ($status !== '' && in_array($status, ['ready', 'disabled', 'missing'], true)) {
            $filters[] = 'm.status=?';
            $params[] = $status;
        }
        $tags = trim((string)$request->input('tags', ''));
        if ($tags !== '') {
            $filters[] = 'm.tags LIKE ?';
            $params[] = '%' . $tags . '%';
        }
        $publishStatus = trim((string)$request->input('publishStatus', $request->input('publish_status', '')));
        if ($publishStatus !== '' && in_array($publishStatus, ['unused', 'publishing', 'published'], true)) {
            $filters[] = 'm.publish_status=?';
            $params[] = $publishStatus;
        }
        $publishAccountId = (int)$request->input('publishAccountId', $request->input('publish_account_id', 0));
        if ($publishAccountId > 0) {
            $filters[] = 'm.publish_account_id=?';
            $params[] = $publishAccountId;
        }
        $publishAccountIds = trim((string)$request->input('publishAccountIds', $request->input('publish_account_ids', '')));
        if ($publishAccountIds !== '') {
            $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', $publishAccountIds) ?: []))));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $filters[] = "m.publish_account_id IN ({$ph})";
                array_push($params, ...$ids);
            }
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $page = (int)$request->input('page', 0);
        $pageSize = (int)$request->input('pageSize', $request->input('page_size', 12));
        // 列表默认不返回 cover_base64（单条约百 KB～数 MB），否则一页/全量会卡死
        $includeCover = filter_var($request->input('includeCover', $request->input('include_cover', false)), FILTER_VALIDATE_BOOLEAN);
        $select = $this->listSelectSql($includeCover);

        if ($page > 0) {
            $pageSize = max(1, min(100, $pageSize > 0 ? $pageSize : 12));
            $totalRow = DB::selectOne("SELECT COUNT(*) AS c FROM materials m {$where}", $params);
            $total = (int)($totalRow['c'] ?? 0);
            $pages = max(1, (int)ceil($total / $pageSize));
            $page = min(max(1, $page), $pages);
            $offset = ($page - 1) * $pageSize;
            $rows = DB::select(
                "{$select}
                 FROM materials m
                 LEFT JOIN admin_users u ON u.id = m.owner_id
                 LEFT JOIN accounts a ON a.id = m.publish_account_id
                 {$where}
                 ORDER BY m.id DESC
                 LIMIT {$offset}, {$pageSize}",
                $params
            );
            return $this->success([
                'items' => array_map(fn ($r) => $this->mapRow($r, $includeCover), $rows ?: []),
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'pages' => $pages,
            ]);
        }

        // 无分页：仍排除封面，并硬顶 500，避免 loadRefs 拖垮
        $rows = DB::select(
            "{$select}
             FROM materials m
             LEFT JOIN admin_users u ON u.id = m.owner_id
             LEFT JOIN accounts a ON a.id = m.publish_account_id
             {$where}
             ORDER BY m.id DESC
             LIMIT 500",
            $params
        );
        return $this->success(array_map(fn ($r) => $this->mapRow($r, $includeCover), $rows ?: []));
    }

    /** 列表字段：默认用 has_cover 标记，不读 LONGTEXT 封面 */
    private function listSelectSql(bool $includeCover): string
    {
        $coverCol = $includeCover
            ? 'm.cover_base64'
            : "CASE WHEN m.cover_base64 IS NOT NULL AND TRIM(m.cover_base64) <> '' THEN 1 ELSE 0 END AS has_cover";
        return "SELECT m.id, m.name, m.file_path, m.file_name, m.file_ext, m.mime_type, m.size_bytes,
                m.description, m.tags, m.status, m.publish_status, m.publish_account_id, m.publish_account,
                m.sha256, m.share_title, m.share_caption, m.owner_id, m.created_at, m.updated_at,
                m.platform_video_id, m.platform_video_url, m.view_count, m.like_count, m.comment_count,
                m.platform_published_at, m.last_comment_pulled_at,
                {$coverCol},
                u.username AS owner_name, a.username AS publish_account_name";
    }

    /** 按文件大小+后缀查询可能重复的素材（当前用户范围） */
    public function checkSizeDuplicates(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $size = (int)$request->input('sizeBytes', $request->input('size_bytes', 0));
        $ext = strtolower(ltrim(trim((string)$request->input('fileExt', $request->input('file_ext', ''))), '.'));
        if ($size <= 0) {
            return $this->error('请提供文件大小', 422);
        }
        [$ownSql, $ownParams] = $this->materialOwnerSql('owner_id');
        $sql = "SELECT * FROM materials WHERE size_bytes=?{$ownSql}";
        $params = array_merge([$size], $ownParams);
        if ($ext !== '') {
            $sql .= ' AND (file_ext=? OR file_name LIKE ?)';
            $params[] = $ext;
            $params[] = '%.' . $ext;
        }
        $sql .= ' ORDER BY id DESC LIMIT 50';
        $rows = DB::select($sql, $params) ?: [];
        return $this->success([
            'sizeBytes' => $size,
            'fileExt' => $ext,
            'count' => count($rows),
            'items' => array_map([$this, 'mapRow'], $rows),
            'hasDuplicates' => count($rows) > 0,
        ]);
    }

    public function rename(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id]);
        if ($err = $this->assertMaterialOwns($old)) {
            return $err;
        }
        $name = trim((string)$request->input('name', ''));
        if ($name === '') {
            return $this->error('文件名不能为空', 422);
        }
        DB::query('UPDATE materials SET name=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$name, (int)$id]);
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id])), '已重命名');
    }

    /** 单独写入/更新封面 base64（data:image/...;base64,...） */
    public function setCover(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id]);
        if ($err = $this->assertMaterialOwns($old)) {
            return $err;
        }
        $cover = $this->normalizeCoverBase64((string)$request->input('coverBase64', $request->input('cover_base64', '')));
        if ($cover === '') {
            return $this->error('封面数据无效', 422);
        }
        DB::query('UPDATE materials SET cover_base64=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$cover, (int)$id]);
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id])), '封面已更新');
    }

    public function create(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            return $this->storeUpload($_FILES['file'], trim((string)$request->input('name', '')), trim((string)$request->input('tags', '')), trim((string)$request->input('description', '')), $request);
        }
        $name = trim((string)$request->input('name', ''));
        if ($name === '') {
            return $this->error('素材名称不能为空', 422);
        }
        $filePath = trim((string)$request->input('filePath', $request->input('file_path', '')));
        $status = (string)$request->input('status', 'ready');
        if (!in_array($status, ['ready', 'disabled', 'missing'], true)) {
            $status = 'ready';
        }
        $fileName = trim((string)$request->input('fileName', ''));
        $ext = strtolower(ltrim((string)pathinfo($fileName !== '' ? $fileName : $name, PATHINFO_EXTENSION), '.'));
        $cover = $this->normalizeCoverBase64((string)$request->input('coverBase64', $request->input('cover_base64', '')));
        if ($cover === '' && $filePath !== '') {
            $cover = $this->extractCoverBase64($filePath);
        }
        DB::query(
            'INSERT INTO materials(name, file_path, file_name, file_ext, mime_type, size_bytes, description, tags, status, publish_status, publish_account_id, publish_account, sha256, cover_base64, owner_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $name,
                $filePath,
                $fileName,
                $ext,
                trim((string)$request->input('mimeType', 'video/mp4')),
                (int)$request->input('sizeBytes', 0),
                trim((string)$request->input('description', '')),
                trim((string)$request->input('tags', '')),
                $status,
                'unused',
                null,
                '',
                '',
                $cover,
                $this->writeMaterialOwnerId(),
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        $row = DB::selectOne('SELECT * FROM materials WHERE id=?', [$id]);
        return $this->success($this->mapRow($row), '素材已创建');
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id]);
        if ($err = $this->assertMaterialOwns($old)) {
            return $err;
        }
        $status = (string)$request->input('status', $old['status'] ?? 'ready');
        if (!in_array($status, ['ready', 'disabled', 'missing'], true)) {
            $status = 'ready';
        }
        $name = trim((string)$request->input('name', $old['name'] ?? ''));
        if ($name === '') {
            return $this->error('素材名称不能为空', 422);
        }
        $shareTitle = trim((string)$request->input(
            'shareTitle',
            $request->input('share_title', $old['share_title'] ?? '')
        ));
        $shareCaption = trim((string)$request->input(
            'shareCaption',
            $request->input('share_caption', $old['share_caption'] ?? '')
        ));
        $publishStatus = trim((string)$request->input(
            'publishStatus',
            $request->input('publish_status', '')
        ));
        $publishAccountId = $request->input('publishAccountId', $request->input('publish_account_id', null));
        $platformVideoId = trim((string)$request->input(
            'platformVideoId',
            $request->input('platform_video_id', $request->input('videoId', ''))
        ));
        $platformVideoUrl = trim((string)$request->input(
            'platformVideoUrl',
            $request->input('platform_video_url', $request->input('videoUrl', ''))
        ));

        // 若带发布回写字段，走统一标记逻辑
        if ($publishStatus === 'published' || $platformVideoId !== '' || $publishAccountId !== null) {
            $aid = $publishAccountId !== null && $publishAccountId !== ''
                ? (int)$publishAccountId
                : (int)($old['publish_account_id'] ?? 0);
            \App\Services\AccountWorkBatchService::markMaterialPublished(
                (int)$id,
                $aid,
                false,
                $platformVideoId,
                $platformVideoUrl
            );
        }

        DB::query(
            'UPDATE materials SET name=?, file_path=?, file_name=?, mime_type=?, size_bytes=?, description=?, tags=?, status=?, share_title=?, share_caption=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $name,
                trim((string)$request->input('filePath', $old['file_path'] ?? '')),
                trim((string)$request->input('fileName', $old['file_name'] ?? '')),
                trim((string)$request->input('mimeType', $old['mime_type'] ?? '')),
                (int)$request->input('sizeBytes', $old['size_bytes'] ?? 0),
                (string)$request->input('description', $old['description'] ?? ''),
                (string)$request->input('tags', $old['tags'] ?? ''),
                $status,
                $shareTitle,
                $shareCaption,
                (int)$id,
            ]
        );
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id])), '素材已更新');
    }

    /** 发布成功回写：状态 + 账号 + 平台 video_id（前端/Node 均可调） */
    public function markPublished(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        \App\Services\AccountWorkBatchService::ensureMaterialsPublishColumns();
        $old = DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id]);
        if ($err = $this->assertMaterialOwns($old)) {
            return $err;
        }
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $deleteAfter = (bool)$request->input('deleteAfterPublish', $request->input('delete_after_publish', false));
        $videoId = trim((string)$request->input('videoId', $request->input('video_id', $request->input('platformVideoId', ''))));
        $videoUrl = trim((string)$request->input('videoUrl', $request->input('video_url', $request->input('platformVideoUrl', ''))));
        try {
            $ok = \App\Services\AccountWorkBatchService::markMaterialPublished(
                (int)$id,
                $accountId,
                $deleteAfter,
                $videoId,
                $videoUrl
            );
        } catch (\Throwable $e) {
            return $this->error('标记发布失败: ' . $e->getMessage(), 500);
        }
        if (!$ok) {
            return $this->error('标记发布失败', 400);
        }
        $row = DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id]);
        return $this->success($this->mapRow($row), '素材已标记为已发布');
    }

    public function check(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne(
            'SELECT id, owner_id, file_path, path, file_name, mime_type FROM materials WHERE id=?',
            [(int)$id]
        );
        // 与 stream 一致：可见即可检测（预览前探测），不要求必须本人可写
        if ($err = $this->assertMaterialVisible($row)) {
            return $err;
        }
        $path = $this->resolveExistingPath($row);
        $exists = $path !== '';
        $status = $exists ? 'ready' : 'missing';
        DB::query('UPDATE materials SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$status, (int)$id]);
        $size = $exists ? (int)(filesize($path) ?: 0) : 0;
        return $this->success(
            [
                'id' => (int)$id,
                'exists' => $exists,
                'status' => $status,
                'sizeBytes' => $size,
                'message' => $exists ? '素材文件存在' : '未找到素材文件',
            ],
            $exists ? '素材文件存在' : '未找到素材文件'
        );
    }

    public function checkFolder(Request $request): Response
    {
        $folder = trim((string)$request->input('folderPath', ''));
        if ($folder === '') {
            return $this->error('请填写文件夹路径', 400);
        }
        $result = $this->listVideos($folder);
        if (!$result['exists']) {
            return $this->error('文件夹不存在', 404);
        }
        $msg = $result['files']
            ? ('文件夹检查通过，找到 ' . count($result['files']) . ' 个视频')
            : '文件夹中没有找到视频文件';
        $files = array_slice(array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size']], $result['files']), 0, 50);
        return $this->success([
            'folderPath' => $result['abs'],
            'count' => count($result['files']),
            'files' => $files,
            'message' => $msg,
        ], $msg);
    }

    public function importFolder(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $folder = trim((string)$request->input('folderPath', ''));
        $copy = filter_var($request->input('copy', false), FILTER_VALIDATE_BOOLEAN);
        $tags = trim((string)$request->input('tags', 'folder'));
        if ($folder === '') {
            return $this->error('请填写文件夹路径', 400);
        }
        $result = $this->listVideos($folder);
        if (!$result['exists']) {
            return $this->error('文件夹不存在', 404);
        }
        if (!$result['files']) {
            return $this->error('文件夹中没有找到视频文件', 422);
        }
        $imported = 0;
        $skipped = 0;
        $ids = [];
        foreach ($result['files'] as $file) {
            [$ownSql, $ownParams] = $this->materialOwnerSql('owner_id');
            $byPath = DB::selectOne("SELECT id FROM materials WHERE file_path=?{$ownSql}", array_merge([$file['path']], $ownParams));
            if ($byPath) {
                $skipped++;
                $ids[] = (int)$byPath['id'];
                continue;
            }
            $target = $file['path'];
            $size = $file['size'];
            if ($copy) {
                $dest = $this->materialDir() . '/' . time() . '-' . bin2hex(random_bytes(4)) . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ? ('.' . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION))) : '');
                if (!@copy($file['path'], $dest)) {
                    $skipped++;
                    continue;
                }
                $target = $dest;
                $size = filesize($dest) ?: $size;
            }
            $hash = hash_file('sha256', $target) ?: '';
            if ($hash !== '') {
                [$ownSql, $ownParams] = $this->materialOwnerSql('owner_id');
                $dup = DB::selectOne("SELECT id FROM materials WHERE sha256=?{$ownSql}", array_merge([$hash], $ownParams));
                if ($dup) {
                    if ($copy && $target !== $file['path']) {
                        @unlink($target);
                    }
                    $skipped++;
                    $ids[] = (int)$dup['id'];
                    continue;
                }
            }
            $ext = strtolower(ltrim((string)pathinfo($file['name'], PATHINFO_EXTENSION), '.'));
            $cover = $this->extractCoverBase64($target);
            DB::query(
                'INSERT INTO materials(name, file_path, file_name, file_ext, mime_type, size_bytes, description, tags, status, publish_status, publish_account_id, publish_account, sha256, cover_base64, owner_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    pathinfo($file['name'], PATHINFO_FILENAME),
                    $target,
                    $file['name'],
                    $ext,
                    $this->mimeOf($file['name']),
                    $size,
                    '来自文件夹 ' . $result['abs'],
                    $tags,
                    'ready',
                    'unused',
                    null,
                    '',
                    $hash,
                    $cover,
                    $this->writeMaterialOwnerId(),
                ]
            );
            $imported++;
            $ids[] = (int)DB::getPdo()->lastInsertId();
        }
        $msg = "导入完成：新增 {$imported}，跳过 {$skipped}";
        return $this->success([
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => count($result['files']),
            'materialIds' => array_values(array_unique($ids)),
            'folderPath' => $result['abs'],
            'message' => $msg,
        ], $msg);
    }

    public function stream(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne(
            'SELECT file_path, path, mime_type, file_name, owner_id, publish_account_id FROM materials WHERE id=?',
            [(int)$id]
        );
        if ($err = $this->assertMaterialVisible($row)) {
            return $err;
        }
        $path = $this->resolveExistingPath($row);
        if ($path === '') {
            $hint = (string)($row['file_name'] ?? basename(str_replace('\\', '/', (string)($row['file_path'] ?? ''))));
            return $this->error('素材文件不存在（服务器上找不到：' . ($hint !== '' ? $hint : '路径为空') . '）', 404);
        }
        $size = filesize($path) ?: 0;
        $mime = (string)($row['mime_type'] ?: '');
        if ($mime === '' || !str_contains($mime, '/')) {
            $mime = 'video/mp4';
        }
        $cors = \App\Middlewares\CorsMiddleware::headers($request->getHeader('Origin'));
        $baseHeaders = array_merge($cors, [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if (is_string($range) && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $start = $m[1] === '' ? 0 : (int)$m[1];
            $end = $m[2] === '' ? max(0, $size - 1) : min((int)$m[2], max(0, $size - 1));
            if ($start > $end) {
                return Response::make('', 416, $baseHeaders);
            }
            return Response::file($path, 206, array_merge($baseHeaders, [
                'Content-Range' => "bytes {$start}-{$end}/{$size}",
                'Content-Length' => (string)($end - $start + 1),
            ]), $start, $end);
        }
        return Response::file($path, 200, array_merge($baseHeaders, [
            'Content-Length' => (string)$size,
        ]));
    }

    /** 素材 ZIP 导入 Excel 模板（.xlsx） */
    public function importTemplate(Request $request): Response
    {
        $bin = $this->buildMaterialImportXlsx([
            ['demo-video-01.mp4', '示例素材1', 'zip-import', '分享标题可选', '分享文案可选', '内部备注可选'],
            ['demo-video-02.mp4', '示例素材2', 'zip-import', '', '', ''],
        ]);
        return Response::make($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="material-import-template.xlsx"',
            'Content-Length' => (string)strlen($bin),
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * ZIP 素材导入：压缩包内含视频 + Excel（文件名列须与视频文件名一致）。
     * 排重：库内/批次内「名称 + 大小」相同则不允许入库。
     */
    public function importZip(Request $request): Response
    {
        @set_time_limit(300);
        $this->ensureSchema();
        DataScope::ensureColumns();
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->error('请上传 ZIP 压缩包');
        }
        $upload = $_FILES['file'];
        $tmp = (string)($upload['tmp_name'] ?? '');
        $orig = (string)($upload['name'] ?? 'materials.zip');
        $ext = strtolower(ltrim((string)pathinfo($orig, PATHINFO_EXTENSION), '.'));
        if ($ext !== 'zip' && !str_contains(strtolower((string)($upload['type'] ?? '')), 'zip')) {
            return $this->error('仅支持 ZIP 压缩包', 415);
        }
        if ($tmp === '' || !is_file($tmp)) {
            return $this->error('上传文件无效');
        }

        $work = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mi-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0777, true) && !is_dir($work)) {
            return $this->error('无法创建临时目录');
        }

        $results = [];
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return $this->error('无法打开 ZIP 文件', 422);
            }
            if (!$zip->extractTo($work)) {
                $zip->close();
                return $this->error('ZIP 解压失败', 422);
            }
            $zip->close();

            $videos = $this->collectImportVideos($work);
            $sheetPath = $this->findImportSheetPath($work);
            if (!$sheetPath) {
                return $this->error('压缩包内未找到 Excel/CSV 清单（支持 .xlsx / .csv）', 422);
            }
            if (!$videos) {
                return $this->error('压缩包内未找到视频文件', 422);
            }

            $rows = $this->parseImportSheet($sheetPath);
            if (!$rows) {
                return $this->error('Excel/CSV 无有效数据行，请使用模板填写「文件名」列', 422);
            }

            $videoMap = [];
            foreach ($videos as $v) {
                $key = $this->normalizeImportFileKey($v['name']);
                if ($key !== '' && !isset($videoMap[$key])) {
                    $videoMap[$key] = $v;
                }
            }

            $batchSeen = []; // name|size
            $defaultTags = trim((string)$request->input('tags', 'zip-import'));

            foreach ($rows as $idx => $row) {
                $lineNo = $idx + 2; // 含表头
                $fileName = trim((string)($row['file_name'] ?? ''));
                if ($fileName === '') {
                    $results[] = ['line' => $lineNo, 'fileName' => '', 'status' => 'failed', 'reason' => '文件名为空'];
                    $failed++;
                    continue;
                }
                $key = $this->normalizeImportFileKey($fileName);
                $video = $videoMap[$key] ?? null;
                if (!$video) {
                    $results[] = [
                        'line' => $lineNo,
                        'fileName' => $fileName,
                        'status' => 'skipped',
                        'reason' => '压缩包中无同名视频，无法匹配',
                    ];
                    $skipped++;
                    continue;
                }
                unset($videoMap[$key]);

                $displayName = trim((string)($row['name'] ?? ''));
                if ($displayName === '') {
                    $base = (string)pathinfo($video['name'], PATHINFO_FILENAME);
                    $displayName = $base !== '' ? $base : $video['name'];
                }
                $size = (int)$video['size'];
                $tags = trim((string)($row['tags'] ?? ''));
                if ($tags === '') {
                    $tags = $defaultTags !== '' ? $defaultTags : 'zip-import';
                }
                $shareTitle = trim((string)($row['share_title'] ?? ''));
                $shareCaption = trim((string)($row['share_caption'] ?? ''));
                $description = trim((string)($row['description'] ?? ''));

                $dupKey = mb_strtolower($displayName) . '|' . $size;
                if (isset($batchSeen[$dupKey])) {
                    $results[] = [
                        'line' => $lineNo,
                        'fileName' => $video['name'],
                        'name' => $displayName,
                        'sizeBytes' => $size,
                        'status' => 'skipped',
                        'reason' => '本批次内名称与大小重复',
                    ];
                    $skipped++;
                    continue;
                }

                [$ownSql, $ownParams] = $this->materialOwnerSql('owner_id');
                $exist = DB::selectOne(
                    "SELECT id, name, size_bytes FROM materials WHERE name=? AND size_bytes=?{$ownSql} LIMIT 1",
                    array_merge([$displayName, $size], $ownParams)
                );
                if ($exist) {
                    $results[] = [
                        'line' => $lineNo,
                        'fileName' => $video['name'],
                        'name' => $displayName,
                        'sizeBytes' => $size,
                        'status' => 'skipped',
                        'reason' => '库内已存在相同名称与大小（#' . (int)$exist['id'] . '）',
                        'existId' => (int)$exist['id'],
                    ];
                    $skipped++;
                    continue;
                }

                $hash = hash_file('sha256', $video['path']) ?: '';
                if ($hash !== '') {
                    $hashDup = DB::selectOne(
                        "SELECT id, name FROM materials WHERE sha256=?{$ownSql} LIMIT 1",
                        array_merge([$hash], $ownParams)
                    );
                    if ($hashDup) {
                        $results[] = [
                            'line' => $lineNo,
                            'fileName' => $video['name'],
                            'name' => $displayName,
                            'sizeBytes' => $size,
                            'status' => 'skipped',
                            'reason' => '内容哈希与库内「' . $hashDup['name'] . '」重复',
                            'existId' => (int)$hashDup['id'],
                        ];
                        $skipped++;
                        continue;
                    }
                }

                $fileExt = strtolower(ltrim((string)pathinfo($video['name'], PATHINFO_EXTENSION), '.'));
                $destName = time() . '-' . bin2hex(random_bytes(4)) . ($fileExt ? ".{$fileExt}" : '');
                $dest = $this->materialDir() . DIRECTORY_SEPARATOR . $destName;
                if (!@copy($video['path'], $dest)) {
                    $results[] = [
                        'line' => $lineNo,
                        'fileName' => $video['name'],
                        'name' => $displayName,
                        'status' => 'failed',
                        'reason' => '复制视频到素材库失败',
                    ];
                    $failed++;
                    continue;
                }

                $cover = $this->extractCoverBase64($dest);
                DB::query(
                    'INSERT INTO materials(name, file_path, file_name, file_ext, mime_type, size_bytes, description, tags, status, publish_status, publish_account_id, publish_account, sha256, cover_base64, share_title, share_caption, owner_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $displayName,
                        $dest,
                        $video['name'],
                        $fileExt,
                        $this->mimeOf($video['name']),
                        $size,
                        $description !== '' ? $description : ('ZIP导入 ' . $orig),
                        $tags,
                        'ready',
                        'unused',
                        null,
                        '',
                        $hash,
                        $cover,
                        $shareTitle,
                        $shareCaption,
                        $this->writeMaterialOwnerId(),
                    ]
                );
                $newId = (int)DB::getPdo()->lastInsertId();
                $batchSeen[$dupKey] = $newId;
                $imported++;
                $results[] = [
                    'line' => $lineNo,
                    'fileName' => $video['name'],
                    'name' => $displayName,
                    'sizeBytes' => $size,
                    'status' => 'imported',
                    'reason' => '入库成功',
                    'materialId' => $newId,
                ];
            }

            // Excel 未列出但仍在包内的视频
            foreach ($videoMap as $left) {
                $results[] = [
                    'line' => 0,
                    'fileName' => $left['name'],
                    'status' => 'skipped',
                    'reason' => 'Excel 中无对应文件名，未匹配导入',
                    'sizeBytes' => (int)$left['size'],
                ];
                $skipped++;
            }

            $msg = "导入完成：成功 {$imported}，跳过 {$skipped}，失败 {$failed}";
            return $this->success([
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'total' => count($results),
                'sheet' => basename($sheetPath),
                'videoCount' => count($videos),
                'results' => $results,
                'message' => $msg,
            ], $msg);
        } finally {
            $this->removeDirRecursive($work);
        }
    }

    public function upload(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->error('未检测到上传文件');
        }
        return $this->storeUpload(
            $_FILES['file'],
            trim((string)$request->input('name', '')),
            trim((string)$request->input('tags', '')),
            trim((string)$request->input('description', '')),
            $request
        );
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $mat = DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id]);
        if ($err = $this->assertMaterialOwns($mat)) {
            return $err;
        }
        $fileResult = $this->unlinkMaterialFile($mat);
        DB::query('DELETE FROM materials WHERE id=?', [(int)$id]);
        $msg = '素材已删除';
        if ($fileResult['removed']) {
            $msg .= '，源文件已清除';
        } elseif ($fileResult['path'] !== '' && !$fileResult['existed']) {
            $msg .= '（源文件不存在或路径无效）';
        } elseif ($fileResult['path'] !== '' && $fileResult['existed'] && !$fileResult['removed']) {
            $msg .= '（源文件删除失败，请检查权限）';
        }
        return $this->success([
            'id' => (int)$id,
            'fileRemoved' => $fileResult['removed'],
            'filePath' => $fileResult['path'],
        ], $msg);
    }

    /**
     * 删除素材库内文件。线下用户目录路径只删库记录，不 unlink 源视频。
     * @return array{path:string,existed:bool,removed:bool}
     */
    private function unlinkMaterialFile(array $mat): array
    {
        $path = $this->resolvePath($mat);
        $result = ['path' => $path, 'existed' => false, 'removed' => false];
        if ($path === '') {
            return $result;
        }
        $dir = $this->materialDir();
        $dirReal = @realpath($dir) ?: $dir;
        $norm = static function (string $p): string {
            return strtolower(str_replace('\\', '/', $p));
        };
        $candidates = [$path];
        $real = @realpath($path);
        if (is_string($real) && $real !== '') {
            $candidates[] = $real;
        }
        $targets = array_values(array_unique($candidates));
        foreach ($targets as $target) {
            if (!is_file($target)) {
                continue;
            }
            $result['existed'] = true;
            $result['path'] = $target;
            $targetNorm = $norm($target);
            $dirNorm = rtrim($norm((string)$dirReal), '/') . '/';
            // 仅删除素材库目录内文件；用户线下目录保留原片
            if (!str_starts_with($targetNorm, $dirNorm)) {
                break;
            }
            if (@unlink($target)) {
                $result['removed'] = true;
                break;
            }
            clearstatcache(true, $target);
            if (is_file($target) && @unlink($target)) {
                $result['removed'] = true;
                break;
            }
        }
        return $result;
    }

    private function storeUpload(array $file, string $name, string $tags, string $description, ?Request $request = null): Response
    {
        $origName = (string)($file['name'] ?? 'video.mp4');
        if (!mb_check_encoding($origName, 'UTF-8')) {
            $origName = mb_convert_encoding($origName, 'UTF-8', 'ISO-8859-1');
        }
        $mime = (string)($file['type'] ?? '');
        $ext = strtolower(ltrim((string)pathinfo($origName, PATHINFO_EXTENSION), '.'));
        if ($mime !== '' && !str_starts_with($mime, 'video/') && !in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'], true)) {
            return $this->error('只能上传视频文件', 415);
        }
        $size = (int)($file['size'] ?? 0);
        $forceUpload = $request ? filter_var($request->input('forceUpload', false), FILTER_VALIDATE_BOOLEAN) : false;

        // 同大小 + 同格式：未强制入库时先返回疑似重复列表供确认
        if (!$forceUpload && $size > 0 && $ext !== '') {
            [$ownSql, $ownParams] = $this->materialOwnerSql('owner_id');
            $sizeDups = DB::select(
                "SELECT * FROM materials WHERE size_bytes=? AND (file_ext=? OR file_name LIKE ?){$ownSql} ORDER BY created_at DESC, id DESC LIMIT 50",
                array_merge([$size, $ext, '%.' . $ext], $ownParams)
            ) ?: [];
            if ($sizeDups) {
                return $this->error('发现同大小同格式视频，请确认是否重复', 409, [
                    'code' => 'SIZE_DUP',
                    'sizeBytes' => $size,
                    'fileExt' => $ext,
                    'matchTotal' => count($sizeDups),
                    'matches' => array_map([$this, 'mapRow'], array_slice($sizeDups, 0, 3)),
                ]);
            }
        }

        $sha = hash_file('sha256', $file['tmp_name']) ?: '';
        if ($sha !== '') {
            [$ownSql, $ownParams] = $this->materialOwnerSql('owner_id');
            $dup = DB::selectOne("SELECT id, name FROM materials WHERE sha256=?{$ownSql}", array_merge([$sha], $ownParams));
            if ($dup) {
                $reuse = $request ? filter_var($request->input('reuseExisting', false), FILTER_VALIDATE_BOOLEAN) : false;
                if ($reuse) {
                    $dupRow = DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$dup['id']]);
                    if ($dupRow) {
                        return $this->success($this->mapRow($dupRow), '素材已存在，已复用');
                    }
                }
                if (!$forceUpload) {
                    $dupRow = DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$dup['id']]);
                    return $this->error('素材与「' . $dup['name'] . '」内容哈希相同', 409, [
                        'code' => 'HASH_DUP',
                        'matches' => $dupRow ? [$this->mapRow($dupRow)] : [],
                    ]);
                }
            }
        }
        $destName = time() . '-' . bin2hex(random_bytes(4)) . ($ext ? ".{$ext}" : '');
        $dest = $this->materialDir() . '/' . $destName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return $this->error('保存文件失败');
        }
        $display = $name !== '' ? $name : pathinfo($origName, PATHINFO_FILENAME);
        $cover = '';
        $shareTitle = '';
        $shareCaption = '';
        if ($request) {
            $cover = $this->normalizeCoverBase64((string)$request->input('coverBase64', $request->input('cover_base64', '')));
            $shareTitle = trim((string)$request->input('shareTitle', $request->input('share_title', '')));
            $shareCaption = trim((string)$request->input('shareCaption', $request->input('share_caption', '')));
        }
        if ($cover === '') {
            $cover = $this->extractCoverBase64($dest);
        }
        DB::query(
            'INSERT INTO materials(name, file_path, file_name, file_ext, mime_type, size_bytes, description, tags, status, publish_status, publish_account_id, publish_account, sha256, cover_base64, share_title, share_caption, owner_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$display, $dest, $origName, $ext, $mime ?: $this->mimeOf($origName), $size, $description, $tags, 'ready', 'unused', null, '', $sha, $cover, $shareTitle, $shareCaption, $this->writeMaterialOwnerId()]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM materials WHERE id=?', [$id])), '素材已上传');
    }

    private function mapRow(?array $row, bool $includeCover = true): ?array
    {
        if (!$row) {
            return null;
        }
        $ext = (string)($row['file_ext'] ?? '');
        if ($ext === '' && !empty($row['file_name'])) {
            $ext = strtolower(ltrim((string)pathinfo((string)$row['file_name'], PATHINFO_EXTENSION), '.'));
        }
        $row['sizeBytes'] = (int)($row['size_bytes'] ?? 0);
        $row['fileName'] = $row['file_name'] ?? '';
        $row['fileExt'] = $ext;
        $row['mimeType'] = $row['mime_type'] ?? '';
        $row['filePath'] = $row['file_path'] ?? ($row['path'] ?? '');
        $row['publishStatus'] = $row['publish_status'] ?? 'unused';
        $row['publishAccountId'] = isset($row['publish_account_id']) ? (int)$row['publish_account_id'] : null;
        $row['publishAccount'] = $row['publish_account'] ?? ($row['publish_account_name'] ?? '');
        $row['platformVideoId'] = (string)($row['platform_video_id'] ?? '');
        $row['platformVideoUrl'] = (string)($row['platform_video_url'] ?? '');
        $row['ownerId'] = isset($row['owner_id']) ? (int)$row['owner_id'] : null;
        $row['ownerName'] = $row['owner_name'] ?? '';
        $row['createdAt'] = $row['created_at'] ?? '';
        $row['updatedAt'] = $row['updated_at'] ?? '';
        if ($includeCover && array_key_exists('cover_base64', $row)) {
            $row['coverBase64'] = (string)($row['cover_base64'] ?? '');
        } else {
            unset($row['cover_base64']);
            $row['coverBase64'] = '';
        }
        // 注意：PHP empty("0")===false，必须用 intval 判断 has_cover
        $row['hasCover'] = $row['coverBase64'] !== '' || (int)($row['has_cover'] ?? 0) === 1;
        $row['shareTitle'] = (string)($row['share_title'] ?? '');
        $row['shareCaption'] = (string)($row['share_caption'] ?? '');
        $row['viewCount'] = (int)($row['view_count'] ?? 0);
        $row['likeCount'] = (int)($row['like_count'] ?? 0);
        $row['commentCount'] = (int)($row['comment_count'] ?? 0);
        $row['platformPublishedAt'] = (string)($row['platform_published_at'] ?? '');
        $row['lastCommentPulledAt'] = (string)($row['last_comment_pulled_at'] ?? '');
        $id = (int)($row['id'] ?? 0);
        // 线下素材：预览走本机 Node；封面走独立接口，避免列表塞 base64
        $row['streamUrl'] = $id > 0 ? '/api/local-materials/' . $id . '/stream' : '';
        $row['playUrl'] = $row['streamUrl'];
        $row['coverUrl'] = ($id > 0 && $row['hasCover']) ? ('/api/materials/' . $id . '/cover') : '';
        return $row;
    }

    /** 列表缩略图：按需读单条封面，不进列表 JSON */
    public function cover(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne(
            'SELECT id, owner_id, publish_account_id, cover_base64 FROM materials WHERE id=?',
            [(int)$id]
        );
        if ($err = $this->assertMaterialVisible($row)) {
            return $err;
        }
        $raw = trim((string)($row['cover_base64'] ?? ''));
        if ($raw === '') {
            return $this->error('无封面', 404);
        }
        $mime = 'image/jpeg';
        $bin = '';
        if (preg_match('#^data:image/([\w+.-]+);base64,(.+)$#is', $raw, $m)) {
            $subtype = strtolower((string)$m[1]);
            if ($subtype === 'jpg') {
                $subtype = 'jpeg';
            }
            $mime = 'image/' . $subtype;
            $bin = (string)base64_decode(preg_replace('/\s+/', '', (string)$m[2]) ?: '', true);
        } else {
            $bin = (string)base64_decode(preg_replace('/\s+/', '', $raw) ?: '', true);
        }
        if ($bin === '') {
            return $this->error('封面损坏', 404);
        }
        $cors = \App\Middlewares\CorsMiddleware::headers($request->getHeader('Origin'));
        return Response::make($bin, 200, array_merge($cors, [
            'Content-Type' => $mime,
            'Content-Length' => (string)strlen($bin),
            'Cache-Control' => 'private, max-age=3600',
        ]));
    }

    private function normalizeCoverBase64(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,#i', $raw)) {
            if (strlen($raw) > 2_500_000) {
                return '';
            }
            return $raw;
        }
        $compact = preg_replace('/\s+/', '', $raw) ?: '';
        if ($compact !== '' && preg_match('#^[A-Za-z0-9+/=]+$#', $compact) && strlen($compact) > 80) {
            if (strlen($compact) > 2_000_000) {
                return '';
            }
            return 'data:image/jpeg;base64,' . $compact;
        }
        return '';
    }

    /** 服务端用 ffmpeg 截取首帧（若环境可用） */
    private function extractCoverBase64(string $videoPath): string
    {
        if ($videoPath === '' || !is_file($videoPath)) {
            return '';
        }
        $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mat-cover-' . bin2hex(random_bytes(4)) . '.jpg';
        $isWin = DIRECTORY_SEPARATOR === '\\';
        $null = $isWin ? 'NUL' : '/dev/null';
        $cmd = sprintf(
            'ffmpeg -y -ss 0.8 -i %s -frames:v 1 -vf "scale=480:-2" -q:v 4 %s 2>%s',
            escapeshellarg($videoPath),
            escapeshellarg($out),
            $null
        );
        @exec($cmd, $lines, $code);
        if (!is_file($out) || (filesize($out) ?: 0) < 64) {
            @unlink($out);
            return '';
        }
        $bin = (string)@file_get_contents($out);
        @unlink($out);
        if ($bin === '') {
            return '';
        }
        return 'data:image/jpeg;base64,' . base64_encode($bin);
    }

    private function resolvePath(array $row): string
    {
        $path = (string)($row['file_path'] ?? '');
        if ($path === '' && !empty($row['path'])) {
            $rel = (string)$row['path'];
            if (str_starts_with($rel, '/uploads/')) {
                $path = dirname(__DIR__, 3) . '/public' . $rel;
            } else {
                $path = $rel;
            }
        }
        return $path;
    }

    /**
     * 解析实际可读的素材文件路径（含 materialDir 按文件名兜底），供 check / stream 共用。
     */
    private function resolveExistingPath(array $row): string
    {
        $path = $this->resolvePath($row);
        if ($path !== '' && is_file($path)) {
            return $path;
        }
        $candidates = [];
        if ($path !== '') {
            $candidates[] = basename(str_replace('\\', '/', $path));
        }
        $fileName = trim((string)($row['file_name'] ?? ''));
        if ($fileName !== '') {
            $candidates[] = basename(str_replace('\\', '/', $fileName));
        }
        $dir = $this->materialDir();
        foreach (array_unique(array_filter($candidates)) as $base) {
            $alt = $dir . DIRECTORY_SEPARATOR . $base;
            if (is_file($alt)) {
                return $alt;
            }
        }
        return '';
    }

    private function materialDir(): string
    {
        // MaterialController 位于 src/Controllers/Api → 上三级为 tkswarm-api 根目录
        $base = getenv('DATA_DIR') ?: (dirname(__DIR__, 3) . '/data');
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'materials';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private function listVideos(string $folder): array
    {
        $abs = $folder;
        if (!is_dir($abs)) {
            return ['abs' => $abs, 'exists' => false, 'files' => []];
        }
        $exts = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'flv'];
        $files = [];
        foreach (scandir($abs) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $exts, true)) {
                continue;
            }
            $full = rtrim($abs, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (!is_file($full)) {
                continue;
            }
            $files[] = ['name' => $name, 'path' => $full, 'size' => filesize($full) ?: 0];
        }
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));
        return ['abs' => $abs, 'exists' => true, 'files' => $files];
    }

    private function mimeOf(string $fileName): string
    {
        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            default => 'video/mp4',
        };
    }

    private function normalizeImportFileKey(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $name = basename($name);
        return mb_strtolower($name);
    }

    /** @return list<array{name:string,path:string,size:int}> */
    private function collectImportVideos(string $root): array
    {
        $exts = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'flv'];
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $exts, true)) {
                continue;
            }
            $out[] = [
                'name' => $file->getFilename(),
                'path' => $file->getPathname(),
                'size' => (int)$file->getSize(),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    private function findImportSheetPath(string $root): ?string
    {
        $candidates = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['xlsx', 'csv'], true)) {
                continue;
            }
            $name = mb_strtolower($file->getFilename());
            $score = 0;
            if (str_contains($name, 'template') || str_contains($name, '模板')) {
                $score += 20;
            }
            if (str_contains($name, 'material') || str_contains($name, '素材') || str_contains($name, 'import') || str_contains($name, '导入')) {
                $score += 10;
            }
            if ($ext === 'xlsx') {
                $score += 5;
            }
            $candidates[] = ['score' => $score, 'path' => $file->getPathname()];
        }
        if (!$candidates) {
            return null;
        }
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        return $candidates[0]['path'];
    }

    /** @return list<array<string,string>> */
    private function parseImportSheet(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return $this->parseImportCsv($path);
        }
        return $this->parseImportXlsx($path);
    }

    /** @return list<array<string,string>> */
    private function parseImportCsv(string $path): array
    {
        $raw = (string)@file_get_contents($path);
        if ($raw === '') {
            return [];
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $rows = [];
        $headers = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if ($headers === null) {
                $headers = array_map(fn($h) => $this->mapImportHeader((string)$h), $cols);
                continue;
            }
            $item = [];
            foreach ($headers as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $item[$key] = trim((string)($cols[$i] ?? ''));
            }
            if (($item['file_name'] ?? '') !== '' || ($item['name'] ?? '') !== '') {
                $rows[] = $item;
            }
        }
        return $rows;
    }

    /** @return list<array<string,string>> */
    private function parseImportXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($ssXml) && $ssXml !== '') {
            $sx = @simplexml_load_string($ssXml);
            if ($sx) {
                foreach ($sx->si as $si) {
                    if (isset($si->t)) {
                        $shared[] = (string)$si->t;
                    } else {
                        $parts = [];
                        foreach ($si->r as $r) {
                            $parts[] = (string)($r->t ?? '');
                        }
                        $shared[] = implode('', $parts);
                    }
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!is_string($sheetXml) || $sheetXml === '') {
            return [];
        }
        $sheet = @simplexml_load_string($sheetXml);
        if (!$sheet || !isset($sheet->sheetData)) {
            return [];
        }
        $matrix = [];
        foreach ($sheet->sheetData->row as $row) {
            $rIdx = ((int)$row['r']) - 1;
            if ($rIdx < 0) {
                continue;
            }
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    continue;
                }
                $col = $this->xlsxColIndex($m[1]);
                $type = (string)($c['t'] ?? '');
                $val = '';
                if ($type === 's') {
                    $si = (int)($c->v ?? -1);
                    $val = $shared[$si] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string)($c->is->t ?? '');
                } else {
                    $val = (string)($c->v ?? '');
                }
                $matrix[$rIdx][$col] = trim($val);
            }
        }
        if (!$matrix) {
            return [];
        }
        ksort($matrix);
        $headerRow = array_shift($matrix);
        if (!is_array($headerRow)) {
            return [];
        }
        ksort($headerRow);
        $headers = [];
        foreach ($headerRow as $col => $text) {
            $headers[$col] = $this->mapImportHeader((string)$text);
        }
        $rows = [];
        foreach ($matrix as $cells) {
            $item = [];
            foreach ($headers as $col => $key) {
                if ($key === '') {
                    continue;
                }
                $item[$key] = trim((string)($cells[$col] ?? ''));
            }
            if (($item['file_name'] ?? '') !== '' || ($item['name'] ?? '') !== '') {
                $rows[] = $item;
            }
        }
        return $rows;
    }

    private function mapImportHeader(string $header): string
    {
        $h = mb_strtolower(trim($header));
        $h = str_replace([' ', '_'], '', $h);
        $map = [
            '文件名' => 'file_name',
            'filename' => 'file_name',
            'file' => 'file_name',
            '视频文件名' => 'file_name',
            '素材名称' => 'name',
            '名称' => 'name',
            'name' => 'name',
            '标签' => 'tags',
            'tags' => 'tags',
            '分享标题' => 'share_title',
            '标题' => 'share_title',
            'sharetitle' => 'share_title',
            '分享文案' => 'share_caption',
            '文案' => 'share_caption',
            'sharecaption' => 'share_caption',
            'caption' => 'share_caption',
            '描述' => 'description',
            '备注' => 'description',
            'description' => 'description',
        ];
        // 去空格后的中文 key
        foreach ($map as $k => $v) {
            $nk = mb_strtolower(str_replace([' ', '_'], '', (string)$k));
            if ($nk === $h) {
                return $v;
            }
        }
        return $map[$h] ?? '';
    }

    private function xlsxColIndex(string $letters): int
    {
        $n = 0;
        $letters = strtoupper($letters);
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $n - 1);
    }

    /** @param list<list<string>> $dataRows */
    private function buildMaterialImportXlsx(array $dataRows): string
    {
        $headers = ['文件名', '素材名称', '标签', '分享标题', '分享文案', '描述'];
        $shared = $headers;
        foreach ($dataRows as $row) {
            foreach ($row as $cell) {
                $shared[] = (string)$cell;
            }
        }
        $unique = [];
        $indexOf = [];
        foreach ($shared as $s) {
            if (!array_key_exists($s, $indexOf)) {
                $indexOf[$s] = count($unique);
                $unique[] = $s;
            }
        }
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $ssParts = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($unique) . '">'];
        foreach ($unique as $s) {
            $ssParts[] = '<si><t>' . $esc($s) . '</t></si>';
        }
        $ssParts[] = '</sst>';
        $sharedXml = implode('', $ssParts);

        $sheetRows = [];
        $rowNum = 1;
        $cols = ['A', 'B', 'C', 'D', 'E', 'F'];
        $writeRow = function (array $values) use (&$sheetRows, &$rowNum, $cols, $indexOf) {
            $cells = '';
            foreach ($values as $i => $val) {
                $ref = ($cols[$i] ?? 'A') . $rowNum;
                $si = $indexOf[(string)$val] ?? 0;
                $cells .= '<c r="' . $ref . '" t="s"><v>' . $si . '</v></c>';
            }
            $sheetRows[] = '<row r="' . $rowNum . '">' . $cells . '</row>';
            $rowNum++;
        };
        $writeRow($headers);
        foreach ($dataRows as $row) {
            $writeRow(array_pad(array_map('strval', $row), 6, ''));
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="素材导入" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
        $zip->close();
        $bin = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    private function removeDirRecursive(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        DB::query("CREATE TABLE IF NOT EXISTS materials (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          file_path TEXT NULL,
          file_name VARCHAR(255) NOT NULL DEFAULT '',
          file_ext VARCHAR(16) NOT NULL DEFAULT '',
          mime_type VARCHAR(128) NOT NULL DEFAULT '',
          size_bytes BIGINT NOT NULL DEFAULT 0,
          sha256 VARCHAR(128) NOT NULL DEFAULT '',
          description TEXT NULL,
          tags VARCHAR(255) NOT NULL DEFAULT '',
          status VARCHAR(32) NOT NULL DEFAULT 'ready',
          publish_status VARCHAR(32) NOT NULL DEFAULT 'unused',
          publish_account_id INT NULL,
          publish_account VARCHAR(191) NOT NULL DEFAULT '',
          platform_video_id VARCHAR(64) NOT NULL DEFAULT '',
          platform_video_url VARCHAR(500) NOT NULL DEFAULT '',
          cover_base64 LONGTEXT NULL,
          share_title VARCHAR(500) NOT NULL DEFAULT '',
          share_caption TEXT NULL,
          owner_id INT NULL,
          path VARCHAR(500) NOT NULL DEFAULT '',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = array_column(DB::select('SHOW COLUMNS FROM materials') ?: [], 'Field');
        $alters = [
            'file_path' => 'ALTER TABLE materials ADD COLUMN file_path TEXT NULL',
            'file_name' => "ALTER TABLE materials ADD COLUMN file_name VARCHAR(255) NOT NULL DEFAULT ''",
            'file_ext' => "ALTER TABLE materials ADD COLUMN file_ext VARCHAR(16) NOT NULL DEFAULT ''",
            'mime_type' => "ALTER TABLE materials ADD COLUMN mime_type VARCHAR(128) NOT NULL DEFAULT ''",
            'size_bytes' => 'ALTER TABLE materials ADD COLUMN size_bytes BIGINT NOT NULL DEFAULT 0',
            'sha256' => "ALTER TABLE materials ADD COLUMN sha256 VARCHAR(128) NOT NULL DEFAULT ''",
            'description' => 'ALTER TABLE materials ADD COLUMN description TEXT NULL',
            'tags' => "ALTER TABLE materials ADD COLUMN tags VARCHAR(255) NOT NULL DEFAULT ''",
            'status' => "ALTER TABLE materials ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'ready'",
            'publish_status' => "ALTER TABLE materials ADD COLUMN publish_status VARCHAR(32) NOT NULL DEFAULT 'unused'",
            'publish_account_id' => 'ALTER TABLE materials ADD COLUMN publish_account_id INT NULL',
            'publish_account' => "ALTER TABLE materials ADD COLUMN publish_account VARCHAR(191) NOT NULL DEFAULT ''",
            'platform_video_id' => "ALTER TABLE materials ADD COLUMN platform_video_id VARCHAR(64) NOT NULL DEFAULT ''",
            'platform_video_url' => "ALTER TABLE materials ADD COLUMN platform_video_url VARCHAR(500) NOT NULL DEFAULT ''",
            'cover_base64' => 'ALTER TABLE materials ADD COLUMN cover_base64 LONGTEXT NULL',
            'share_title' => "ALTER TABLE materials ADD COLUMN share_title VARCHAR(500) NOT NULL DEFAULT ''",
            'share_caption' => 'ALTER TABLE materials ADD COLUMN share_caption TEXT NULL',
            'owner_id' => 'ALTER TABLE materials ADD COLUMN owner_id INT NULL',
        ];
        foreach ($alters as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e) {
                }
            }
        }
        try {
            DB::query("ALTER TABLE materials MODIFY path VARCHAR(500) NOT NULL DEFAULT ''");
            DB::query("ALTER TABLE materials MODIFY type VARCHAR(32) NOT NULL DEFAULT 'video'");
        } catch (\Throwable $e) {
        }
        try {
            DB::query("UPDATE materials SET file_ext=LOWER(SUBSTRING_INDEX(file_name,'.',-1)) WHERE (file_ext IS NULL OR file_ext='') AND file_name LIKE '%.%'");
        } catch (\Throwable $e) {
        }
    }
}
