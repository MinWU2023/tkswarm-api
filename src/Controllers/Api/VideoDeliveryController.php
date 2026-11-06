<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Middlewares\CorsMiddleware;
use App\Services\DataScope;
use App\Services\VideoDeliveryService;

class VideoDeliveryController extends Controller
{
    public function list(Request $request): Response
    {
        VideoDeliveryService::ensureSchema();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, min(100, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $audit = trim((string)$request->get('auditStatus', $request->get('audit_status', '')));

        $filters = [];
        $params = [];
        if ($keyword !== '') {
            $filters[] = '(account_name LIKE ? OR linkedin_company LIKE ? OR phone LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }
        if ($audit !== '') {
            $filters[] = 'audit_status=?';
            $params[] = VideoDeliveryService::normalizeAudit($audit);
        }
        DataScope::applyOwnerFilter('owner_id', $filters, $params);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM video_delivery_clients {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / max(1, $pageSize)));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;

        $rows = DB::select(
            "SELECT * FROM video_delivery_clients {$where}
             ORDER BY id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        $items = array_map(static fn(array $r) => VideoDeliveryService::mapClient($r), $rows);

        $statFilters = $filters;
        $statParams = $params;
        $statFilters[] = "audit_status='approved'";
        $approved = (int)(DB::selectOne(
            'SELECT COUNT(*) AS c FROM video_delivery_clients WHERE ' . implode(' AND ', $statFilters),
            $statParams
        )['c'] ?? 0);

        return $this->success([
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => $pages,
            'total' => $total,
            'stats' => [
                'total' => $total,
                'approved' => $approved,
            ],
            'meta' => [
                'versions' => VideoDeliveryService::VERSIONS,
                'orderStatuses' => VideoDeliveryService::ORDER_STATUSES,
            ],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $client = $this->loadClient((int)$id);
        if ($client instanceof Response) {
            return $client;
        }
        $mapped = VideoDeliveryService::mapClient($client);
        $orderCount = (int)(DB::selectOne(
            'SELECT COUNT(*) AS c FROM video_delivery_orders WHERE client_id=?',
            [(int)$id]
        )['c'] ?? 0);
        $mapped['orderCount'] = $orderCount;
        return $this->success($mapped);
    }

    public function create(Request $request): Response
    {
        VideoDeliveryService::ensureSchema();
        $accountName = trim((string)$request->input('accountName', $request->input('account_name', '')));
        if ($accountName === '') {
            return $this->error('请填写账户名称', 422);
        }
        $linkedin = trim((string)$request->input('linkedinCompany', $request->input('linkedin_company', '')));
        $phone = trim((string)$request->input('phone', ''));
        $audit = VideoDeliveryService::normalizeAudit(
            (string)$request->input('auditStatus', $request->input('audit_status', 'approved'))
        );
        $notes = mb_substr(trim((string)$request->input('notes', '')), 0, 500);
        $registeredAt = trim((string)$request->input('registeredAt', $request->input('registered_at', '')));
        if ($registeredAt === '') {
            $registeredAt = date('Y-m-d H:i:s');
        }
        $lastLogin = trim((string)$request->input('lastLoginAt', $request->input('last_login_at', '')));
        $ownerId = DataScope::writeOwnerId();

        DB::query(
            "INSERT INTO video_delivery_clients
              (account_name, linkedin_company, phone, audit_status, registered_at, last_login_at, notes, owner_id, points, points_updated_at)
             VALUES (?,?,?,?,?,?,?,?,0,NULL)",
            [
                $accountName,
                $linkedin,
                $phone,
                $audit,
                $registeredAt,
                $lastLogin !== '' ? $lastLogin : null,
                $notes,
                $ownerId,
            ]
        );
        $newId = (int)DB::lastInsertId();
        $row = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [$newId]);
        return $this->success(VideoDeliveryService::mapClient($row ?: ['id' => $newId]), '已创建');
    }

    public function update(Request $request, string $id): Response
    {
        $row = $this->loadClient((int)$id);
        if ($row instanceof Response) {
            return $row;
        }
        $all = $request->all();
        $accountName = array_key_exists('accountName', $all) || array_key_exists('account_name', $all)
            ? trim((string)$request->input('accountName', $request->input('account_name', '')))
            : (string)($row['account_name'] ?? '');
        if ($accountName === '') {
            return $this->error('请填写账户名称', 422);
        }
        $linkedin = array_key_exists('linkedinCompany', $all) || array_key_exists('linkedin_company', $all)
            ? trim((string)$request->input('linkedinCompany', $request->input('linkedin_company', '')))
            : (string)($row['linkedin_company'] ?? '');
        $phone = array_key_exists('phone', $all)
            ? trim((string)$request->input('phone', ''))
            : (string)($row['phone'] ?? '');
        $audit = array_key_exists('auditStatus', $all) || array_key_exists('audit_status', $all)
            ? VideoDeliveryService::normalizeAudit((string)$request->input('auditStatus', $request->input('audit_status', '')))
            : (string)($row['audit_status'] ?? 'approved');
        $notes = array_key_exists('notes', $all)
            ? mb_substr(trim((string)$request->input('notes', '')), 0, 500)
            : (string)($row['notes'] ?? '');
        $registeredAt = array_key_exists('registeredAt', $all) || array_key_exists('registered_at', $all)
            ? trim((string)$request->input('registeredAt', $request->input('registered_at', '')))
            : (string)($row['registered_at'] ?? '');
        $lastLogin = array_key_exists('lastLoginAt', $all) || array_key_exists('last_login_at', $all)
            ? trim((string)$request->input('lastLoginAt', $request->input('last_login_at', '')))
            : (string)($row['last_login_at'] ?? '');

        DB::query(
            "UPDATE video_delivery_clients SET
              account_name=?, linkedin_company=?, phone=?, audit_status=?,
              registered_at=?, last_login_at=?, notes=?,
              updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [
                $accountName,
                $linkedin,
                $phone,
                $audit,
                $registeredAt !== '' ? $registeredAt : null,
                $lastLogin !== '' ? $lastLogin : null,
                $notes,
                (int)$id,
            ]
        );
        $fresh = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [(int)$id]);
        return $this->success(VideoDeliveryService::mapClient($fresh ?: $row), '已更新');
    }

    public function delete(Request $request, string $id): Response
    {
        $row = $this->loadClient((int)$id);
        if ($row instanceof Response) {
            return $row;
        }
        DB::query('DELETE FROM video_delivery_clients WHERE id=?', [(int)$id]);
        return $this->success(null, '已删除');
    }

    public function saveService(Request $request, string $id): Response
    {
        $row = $this->loadClient((int)$id);
        if ($row instanceof Response) {
            return $row;
        }
        $version = VideoDeliveryService::normalizeVersion(
            (string)$request->input('serviceVersion', $request->input('service_version', $row['service_version'] ?? 'standard'))
        );
        $authStart = trim((string)$request->input('authStart', $request->input('auth_start', $row['auth_start'] ?? '')));
        $authEnd = trim((string)$request->input('authEnd', $request->input('auth_end', $row['auth_end'] ?? '')));
        $token = trim((string)$request->input('authToken', $request->input('auth_token', $row['auth_token'] ?? '')));

        DB::query(
            "UPDATE video_delivery_clients SET
              service_version=?, auth_start=?, auth_end=?, auth_token=?,
              updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [
                $version,
                $authStart !== '' ? $authStart : null,
                $authEnd !== '' ? $authEnd : null,
                $token,
                (int)$id,
            ]
        );
        $fresh = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [(int)$id]);
        return $this->success(VideoDeliveryService::mapClient($fresh ?: $row), '服务配置已保存');
    }

    public function generateToken(Request $request, string $id): Response
    {
        $row = $this->loadClient((int)$id);
        if ($row instanceof Response) {
            return $row;
        }
        $token = VideoDeliveryService::generateToken();
        DB::query(
            'UPDATE video_delivery_clients SET auth_token=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$token, (int)$id]
        );
        $fresh = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [(int)$id]);
        return $this->success(VideoDeliveryService::mapClient($fresh ?: $row), '已生成授权 Token');
    }

    public function checkLink(Request $request, string $id): Response
    {
        $row = $this->loadClient((int)$id);
        if ($row instanceof Response) {
            return $row;
        }
        $mapped = VideoDeliveryService::mapClient($row);
        $ok = $mapped['authToken'] !== '' && $mapped['authStart'] !== '' && $mapped['authEnd'] !== '';
        $msg = $ok
            ? ($mapped['authorized'] ? '关联正常，授权有效期内' : '已配置 Token，但授权已过期或未生效')
            : '未完整配置服务版本 / 授权日期 / Token';
        return $this->success([
            'ok' => $ok && $mapped['authorized'],
            'authorized' => $mapped['authorized'],
            'client' => $mapped,
            'message' => $msg,
        ], $msg);
    }

    public function recharge(Request $request, string $id): Response
    {
        $row = $this->loadClient((int)$id);
        if ($row instanceof Response) {
            return $row;
        }
        $amount = (int)$request->input('amount', $request->input('points', 0));
        if ($amount === 0) {
            return $this->error('请输入充值积分（可为负数表示扣减）', 422);
        }
        $next = max(0, (int)($row['points'] ?? 0) + $amount);
        DB::query(
            'UPDATE video_delivery_clients SET points=?, points_updated_at=NOW(), updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$next, (int)$id]
        );
        $fresh = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [(int)$id]);
        return $this->success(VideoDeliveryService::mapClient($fresh ?: $row), '积分已更新');
    }

    public function listOrders(Request $request, string $id): Response
    {
        $client = $this->loadClient((int)$id);
        if ($client instanceof Response) {
            return $client;
        }
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, min(100, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $status = trim((string)$request->get('status', ''));
        $version = trim((string)$request->get('serviceVersion', $request->get('version', '')));

        $filters = ['client_id=?'];
        $params = [(int)$id];
        if ($keyword !== '') {
            $filters[] = '(title LIKE ? OR requirements LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like);
        }
        if ($status !== '') {
            $filters[] = 'status=?';
            $params[] = VideoDeliveryService::normalizeOrderStatus($status);
        }
        if ($version !== '') {
            $filters[] = 'service_version=?';
            $params[] = VideoDeliveryService::normalizeVersion($version);
        }
        $where = 'WHERE ' . implode(' AND ', $filters);

        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM video_delivery_orders {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / max(1, $pageSize)));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;

        $rows = DB::select(
            "SELECT * FROM video_delivery_orders {$where}
             ORDER BY id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        $orderIds = array_map(static fn($r) => (int)$r['id'], $rows);
        $filesByOrder = [];
        $notesByOrder = [];
        if ($orderIds) {
            $in = implode(',', array_fill(0, count($orderIds), '?'));
            $files = DB::select("SELECT * FROM video_delivery_files WHERE order_id IN ({$in}) ORDER BY id ASC", $orderIds) ?: [];
            foreach ($files as $f) {
                $filesByOrder[(int)$f['order_id']][] = $f;
            }
            $notes = DB::select("SELECT * FROM video_delivery_notes WHERE order_id IN ({$in}) ORDER BY id DESC", $orderIds) ?: [];
            foreach ($notes as $n) {
                $notesByOrder[(int)$n['order_id']][] = $n;
            }
        }

        $items = [];
        foreach ($rows as $r) {
            $oid = (int)$r['id'];
            $items[] = VideoDeliveryService::mapOrder(
                $r,
                $filesByOrder[$oid] ?? [],
                array_slice($notesByOrder[$oid] ?? [], 0, 20)
            );
        }

        return $this->success([
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => $pages,
            'total' => $total,
            'client' => VideoDeliveryService::mapClient($client),
            'meta' => [
                'versions' => VideoDeliveryService::VERSIONS,
                'orderStatuses' => VideoDeliveryService::ORDER_STATUSES,
            ],
        ]);
    }

    public function createOrder(Request $request, string $id): Response
    {
        $client = $this->loadClient((int)$id);
        if ($client instanceof Response) {
            return $client;
        }
        $title = trim((string)$request->input('title', ''));
        if ($title === '') {
            $title = '定制视频 ' . date('Ymd-His');
        }
        $requirements = trim((string)$request->input('requirements', ''));
        $settleType = trim((string)$request->input('settleType', $request->input('settle_type', '按次结算')));
        if ($settleType === '') {
            $settleType = '按次结算';
        }
        $pointsCost = max(0, (int)$request->input('pointsCost', $request->input('points_cost', 0)));
        $status = VideoDeliveryService::normalizeOrderStatus(
            (string)$request->input('status', 'completed')
        );
        $version = VideoDeliveryService::normalizeVersion(
            (string)$request->input('serviceVersion', $request->input('service_version', $client['service_version'] ?? 'standard'))
        );
        if ($requirements === '') {
            $authStart = (string)($client['auth_start'] ?? '');
            $authEnd = (string)($client['auth_end'] ?? '');
            $verLabel = VideoDeliveryService::versionLabel($version);
            $requirements = "服务版本：{$verLabel}";
            if ($authStart !== '' || $authEnd !== '') {
                $requirements .= "；服务期：{$authStart} ~ {$authEnd}";
            }
        }

        DB::query(
            "INSERT INTO video_delivery_orders
              (client_id, title, requirements, settle_type, points_cost, status, service_version, owner_id)
             VALUES (?,?,?,?,?,?,?,?)",
            [
                (int)$id,
                $title,
                $requirements,
                $settleType,
                $pointsCost,
                $status,
                $version,
                DataScope::writeOwnerId(),
            ]
        );
        $orderId = (int)DB::lastInsertId();

        $uploaded = $this->storeUploadedFiles((int)$id, $orderId);
        if ($uploaded instanceof Response) {
            DB::query('DELETE FROM video_delivery_orders WHERE id=?', [$orderId]);
            return $uploaded;
        }

        if ($pointsCost > 0) {
            $pts = max(0, (int)($client['points'] ?? 0) - $pointsCost);
            DB::query(
                'UPDATE video_delivery_clients SET points=?, points_updated_at=NOW() WHERE id=?',
                [$pts, (int)$id]
            );
        }

        $order = DB::selectOne('SELECT * FROM video_delivery_orders WHERE id=?', [$orderId]);
        $files = DB::select('SELECT * FROM video_delivery_files WHERE order_id=? ORDER BY id ASC', [$orderId]) ?: [];
        return $this->success(VideoDeliveryService::mapOrder($order ?: ['id' => $orderId], $files), '已上传并创建交付单');
    }

    public function orderFiles(Request $request, string $orderId): Response
    {
        $order = $this->loadOrder((int)$orderId);
        if ($order instanceof Response) {
            return $order;
        }
        $files = DB::select('SELECT * FROM video_delivery_files WHERE order_id=? ORDER BY id ASC', [(int)$orderId]) ?: [];
        return $this->success([
            'orderId' => (int)$orderId,
            'title' => (string)($order['title'] ?? ''),
            'files' => array_map([VideoDeliveryService::class, 'mapFile'], $files),
        ]);
    }

    public function addNote(Request $request, string $orderId): Response
    {
        $order = $this->loadOrder((int)$orderId);
        if ($order instanceof Response) {
            return $order;
        }
        $content = mb_substr(trim((string)$request->input('content', $request->input('note', ''))), 0, 1000);
        if ($content === '') {
            return $this->error('请填写备注内容', 422);
        }
        DB::query(
            'INSERT INTO video_delivery_notes (order_id, content, author_id, author_name) VALUES (?,?,?,?)',
            [
                (int)$orderId,
                $content,
                VideoDeliveryService::currentUserId(),
                VideoDeliveryService::currentUserName(),
            ]
        );
        $notes = DB::select(
            'SELECT * FROM video_delivery_notes WHERE order_id=? ORDER BY id DESC LIMIT 50',
            [(int)$orderId]
        ) ?: [];
        return $this->success([
            'orderId' => (int)$orderId,
            'notes' => array_map([VideoDeliveryService::class, 'mapNote'], $notes),
        ], '备注已添加');
    }

    public function downloadRecords(Request $request, string $orderId): Response
    {
        $order = $this->loadOrder((int)$orderId);
        if ($order instanceof Response) {
            return $order;
        }
        $rows = DB::select(
            'SELECT * FROM video_delivery_downloads WHERE order_id=? ORDER BY id DESC LIMIT 100',
            [(int)$orderId]
        ) ?: [];
        return $this->success([
            'orderId' => (int)$orderId,
            'downloadCount' => (int)($order['download_count'] ?? 0),
            'items' => array_map([VideoDeliveryService::class, 'mapDownload'], $rows),
        ]);
    }

    public function streamFile(Request $request, string $fileId): Response
    {
        $file = $this->loadFile((int)$fileId);
        if ($file instanceof Response) {
            return $file;
        }
        $path = $this->resolveFilePath($file);
        if ($path === '' || !is_file($path)) {
            return $this->error('文件不存在', 404);
        }
        $mime = (string)($file['mime_type'] ?: 'application/octet-stream');
        $cors = CorsMiddleware::headers($request->getHeader('Origin'));
        return Response::file($path, 200, array_merge($cors, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $this->safeFilename((string)$file['file_name']) . '"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]));
    }

    public function downloadFile(Request $request, string $fileId): Response
    {
        $file = $this->loadFile((int)$fileId);
        if ($file instanceof Response) {
            return $file;
        }
        $path = $this->resolveFilePath($file);
        if ($path === '' || !is_file($path)) {
            return $this->error('文件不存在', 404);
        }
        $orderId = (int)($file['order_id'] ?? 0);
        DB::query(
            'INSERT INTO video_delivery_downloads (order_id, file_id, operator_id, operator_name) VALUES (?,?,?,?)',
            [
                $orderId,
                (int)$fileId,
                VideoDeliveryService::currentUserId(),
                VideoDeliveryService::currentUserName(),
            ]
        );
        DB::query('UPDATE video_delivery_orders SET download_count=download_count+1 WHERE id=?', [$orderId]);

        $mime = (string)($file['mime_type'] ?: 'application/octet-stream');
        $cors = CorsMiddleware::headers($request->getHeader('Origin'));
        return Response::file($path, 200, array_merge($cors, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $this->safeFilename((string)$file['file_name']) . '"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]));
    }

    /** @return array|Response */
    private function loadClient(int $id)
    {
        VideoDeliveryService::ensureSchema();
        $row = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [$id]);
        if (!$row) {
            return $this->error('交付客户不存在', 404);
        }
        if ($deny = $this->assertOwns($row, 'owner_id')) {
            return $deny;
        }
        return $row;
    }

    /** @return array|Response */
    private function loadOrder(int $orderId)
    {
        VideoDeliveryService::ensureSchema();
        $order = DB::selectOne('SELECT * FROM video_delivery_orders WHERE id=?', [$orderId]);
        if (!$order) {
            return $this->error('交付单不存在', 404);
        }
        $client = $this->loadClient((int)$order['client_id']);
        if ($client instanceof Response) {
            return $client;
        }
        return $order;
    }

    /** @return array|Response */
    private function loadFile(int $fileId)
    {
        VideoDeliveryService::ensureSchema();
        $file = DB::selectOne('SELECT * FROM video_delivery_files WHERE id=?', [$fileId]);
        if (!$file) {
            return $this->error('交付文件不存在', 404);
        }
        $order = $this->loadOrder((int)$file['order_id']);
        if ($order instanceof Response) {
            return $order;
        }
        return $file;
    }

    private function resolveFilePath(array $file): string
    {
        $path = (string)($file['file_path'] ?? '');
        if ($path === '') {
            return '';
        }
        if (is_file($path)) {
            return $path;
        }
        $base = getenv('DATA_DIR') ?: (dirname(__DIR__, 3) . '/data');
        $cand = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), '/\\');
        return is_file($cand) ? $cand : '';
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^\w.\-\x{4e00}-\x{9fff}]+/u', '_', $name) ?: 'file';
        return $name;
    }

    /** @return list<array>|Response */
    private function storeUploadedFiles(int $clientId, int $orderId)
    {
        $files = $this->collectUploadFiles();
        if (!$files) {
            return [];
        }
        $allowed = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'psd', 'zip', 'rar', '7z'];
        $maxBytes = 500 * 1024 * 1024;
        $dir = VideoDeliveryService::storageDir($clientId, $orderId);
        $saved = [];
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $orig = (string)($file['name'] ?? 'file.bin');
            if (!mb_check_encoding($orig, 'UTF-8')) {
                $orig = mb_convert_encoding($orig, 'UTF-8', 'ISO-8859-1');
            }
            $ext = strtolower(ltrim((string)pathinfo($orig, PATHINFO_EXTENSION), '.'));
            if ($ext !== '' && !in_array($ext, $allowed, true)) {
                return $this->error('不支持的文件类型：' . $ext . '（支持 mp4/jpg/png/psd/zip 等，最大 500MB）', 415);
            }
            $size = (int)($file['size'] ?? 0);
            if ($size > $maxBytes) {
                return $this->error('单文件不能超过 500MB', 413);
            }
            $destName = time() . '-' . bin2hex(random_bytes(4)) . ($ext ? ".{$ext}" : '');
            $dest = $dir . DIRECTORY_SEPARATOR . $destName;
            if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
                return $this->error('保存文件失败');
            }
            $rel = 'video-delivery/' . $clientId . '/' . $orderId . '/' . $destName;
            $mime = (string)($file['type'] ?? '');
            $display = pathinfo($orig, PATHINFO_FILENAME) ?: $destName;
            DB::query(
                'INSERT INTO video_delivery_files (order_id, name, file_name, file_path, mime_type, size_bytes)
                 VALUES (?,?,?,?,?,?)',
                [$orderId, $display, $orig, $rel, $mime, $size]
            );
            $saved[] = [
                'id' => (int)DB::lastInsertId(),
                'name' => $display,
            ];
        }
        return $saved;
    }

    /** @return list<array> */
    private function collectUploadFiles(): array
    {
        $out = [];
        if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
            $f = $_FILES['file'];
            if (is_array($f['name'] ?? null)) {
                $n = count($f['name']);
                for ($i = 0; $i < $n; $i++) {
                    $out[] = [
                        'name' => $f['name'][$i] ?? '',
                        'type' => $f['type'][$i] ?? '',
                        'tmp_name' => $f['tmp_name'][$i] ?? '',
                        'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $f['size'][$i] ?? 0,
                    ];
                }
            } else {
                $out[] = $f;
            }
        }
        if (!empty($_FILES['files']) && is_array($_FILES['files'])) {
            $f = $_FILES['files'];
            if (is_array($f['name'] ?? null)) {
                $n = count($f['name']);
                for ($i = 0; $i < $n; $i++) {
                    $out[] = [
                        'name' => $f['name'][$i] ?? '',
                        'type' => $f['type'][$i] ?? '',
                        'tmp_name' => $f['tmp_name'][$i] ?? '',
                        'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $f['size'][$i] ?? 0,
                    ];
                }
            } else {
                $out[] = $f;
            }
        }
        return $out;
    }
}
