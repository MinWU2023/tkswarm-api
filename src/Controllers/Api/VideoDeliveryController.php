<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Middlewares\CorsMiddleware;
use App\Services\CustomVideoTypeService;
use App\Services\DataScope;
use App\Services\Github\GithubReleaseCdnService;
use App\Services\VideoDeliveryService;

class VideoDeliveryController extends Controller
{
    public function list(Request $request): Response
    {
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, min(100, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $audit = trim((string)$request->get('auditStatus', $request->get('audit_status', '')));

        $pack = VideoDeliveryService::listEnterpriseCustomers($page, $pageSize, $keyword, $audit);
        $pack['meta'] = [
            'versions' => VideoDeliveryService::VERSIONS,
            'orderStatuses' => VideoDeliveryService::ORDER_STATUSES,
            'source' => 'enterprise_customers',
        ];
        return $this->success($pack);
    }

    public function show(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $profileId = (int)($mapped['profileId'] ?? 0);
        $orderCount = $profileId > 0
            ? (int)(DB::selectOne('SELECT COUNT(*) AS c FROM video_delivery_orders WHERE client_id=?', [$profileId])['c'] ?? 0)
            : 0;
        $mapped['orderCount'] = $orderCount;
        return $this->success($mapped);
    }

    public function create(Request $request): Response
    {
        return $this->error('视频交付客户来自「企业客户」角色管理用户及其关联企业，请在权限管理中维护', 422);
    }

    public function update(Request $request, string $id): Response
    {
        return $this->error('客户账户/企业信息请在权限管理与企业数据中维护；本页仅维护授权、积分与交付单', 422);
    }

    public function delete(Request $request, string $id): Response
    {
        return $this->error('不可在此删除企业客户，请到权限管理操作', 422);
    }

    public function saveService(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $profileId = (int)$mapped['profileId'];
        $version = VideoDeliveryService::normalizeVersion(
            (string)$request->input('serviceVersion', $request->input('service_version', $mapped['serviceVersion'] ?? 'standard'))
        );
        $authStart = trim((string)$request->input('authStart', $request->input('auth_start', $mapped['authStart'] ?? '')));
        $authEnd = trim((string)$request->input('authEnd', $request->input('auth_end', $mapped['authEnd'] ?? '')));
        $token = trim((string)$request->input('authToken', $request->input('auth_token', $mapped['authToken'] ?? '')));

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
                $profileId,
            ]
        );
        return $this->success(VideoDeliveryService::ensureProfileForAdminUser((int)$id), '服务配置已保存');
    }

    public function generateToken(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $token = VideoDeliveryService::generateToken();
        DB::query(
            'UPDATE video_delivery_clients SET auth_token=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$token, (int)$mapped['profileId']]
        );
        return $this->success(VideoDeliveryService::ensureProfileForAdminUser((int)$id), '已生成授权 Token');
    }

    public function checkLink(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $ok = ($mapped['authToken'] ?? '') !== '' && ($mapped['authStart'] ?? '') !== '' && ($mapped['authEnd'] ?? '') !== '';
        $msg = $ok
            ? (!empty($mapped['authorized']) ? '关联正常，授权有效期内' : '已配置 Token，但授权已过期或未生效')
            : '未完整配置服务版本 / 授权日期 / Token';
        return $this->success([
            'ok' => $ok && !empty($mapped['authorized']),
            'authorized' => !empty($mapped['authorized']),
            'client' => $mapped,
            'message' => $msg,
        ], $msg);
    }

    public function recharge(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $amount = (int)$request->input('amount', $request->input('points', 0));
        if ($amount === 0) {
            return $this->error('请输入充值积分（可为负数表示扣减）', 422);
        }
        $next = max(0, (int)($mapped['points'] ?? 0) + $amount);
        DB::query(
            'UPDATE video_delivery_clients SET points=?, points_updated_at=NOW(), updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$next, (int)$mapped['profileId']]
        );
        return $this->success(VideoDeliveryService::ensureProfileForAdminUser((int)$id), '积分已更新');
    }

    public function listOrders(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $profileId = (int)$mapped['profileId'];
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, min(100, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $status = trim((string)$request->get('status', ''));
        $version = trim((string)$request->get('serviceVersion', $request->get('version', '')));

        $filters = ['client_id=?'];
        $params = [$profileId];
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
            'client' => $mapped,
            'meta' => [
                'versions' => VideoDeliveryService::VERSIONS,
                'orderStatuses' => VideoDeliveryService::ORDER_STATUSES,
            ],
        ]);
    }

    public function createOrder(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $profileId = (int)$mapped['profileId'];
        $adminUserId = (int)$id;
        $title = trim((string)$request->input('title', ''));
        if ($title === '') {
            $title = '定制视频 ' . date('Ymd-His');
        }
        $requirements = trim((string)$request->input('requirements', ''));
        $settleType = trim((string)$request->input('settleType', $request->input('settle_type', '')));
        if ($settleType === '') {
            return $this->error('请选择结算视频类型', 422);
        }

        $materialIds = $this->parseMaterialIds($request);
        $qtyHint = (int)$request->input('quantity', $request->input('qty', 0));
        if ($qtyHint <= 0) {
            $qtyHint = max(1, count($materialIds));
        }
        $manualPtsRaw = $request->input('pointsCost', $request->input('points_cost', null));
        $manualPts = $manualPtsRaw === null || $manualPtsRaw === '' ? null : (int)$manualPtsRaw;

        CustomVideoTypeService::ensureSchema();
        $quote = CustomVideoTypeService::quote($settleType, $qtyHint, $manualPts);
        if (empty($quote['ok'])) {
            return $this->error((string)($quote['message'] ?? '计价失败'), 422);
        }
        $pointsCost = max(0, (int)$quote['pointsCost']);
        $settleTypeCode = (string)$quote['code'];
        // 落库存英文 code，列表展示时再解析标题
        $settleType = $settleTypeCode;

        $balance = max(0, (int)($mapped['points'] ?? 0));
        if ($pointsCost > $balance) {
            return $this->error(
                "积分不足：本次需 {$pointsCost}，当前余额 {$balance}",
                422
            );
        }

        $status = VideoDeliveryService::normalizeOrderStatus(
            (string)$request->input('status', 'completed')
        );
        $version = VideoDeliveryService::normalizeVersion(
            (string)$request->input('serviceVersion', $request->input('service_version', $mapped['serviceVersion'] ?? 'standard'))
        );
        if ($requirements === '') {
            $authStart = (string)($mapped['authStart'] ?? '');
            $authEnd = (string)($mapped['authEnd'] ?? '');
            $verLabel = VideoDeliveryService::versionLabel($version);
            $requirements = "服务版本：{$verLabel}";
            if ($authStart !== '' || $authEnd !== '') {
                $requirements .= "；服务期：{$authStart} ~ {$authEnd}";
            }
        }
        if (!empty($quote['title'])) {
            $requirements = trim($requirements . "\n结算类型：" . $quote['title']
                . (empty($quote['manual']) && !empty($quote['unitPrice'])
                    ? "（单价 {$quote['unitPrice']} × {$quote['quantity']}）"
                    : ''));
        }

        $deliveredAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
        DB::query(
            "INSERT INTO video_delivery_orders
              (client_id, title, requirements, settle_type, points_cost, status, service_version, owner_id,
               customer_confirm_status, delivered_at)
             VALUES (?,?,?,?,?,?,?,?, 'pending', ?)",
            [
                $profileId,
                $title,
                $requirements,
                $settleType,
                $pointsCost,
                $status,
                $version,
                DataScope::writeOwnerId(),
                $deliveredAt,
            ]
        );
        $orderId = (int)DB::lastInsertId();

        $linked = $this->attachMaterials($adminUserId, $orderId, $materialIds);
        if ($linked instanceof Response) {
            DB::query('DELETE FROM video_delivery_orders WHERE id=?', [$orderId]);
            return $linked;
        }

        $uploaded = $this->storeUploadedFiles($adminUserId, $orderId);
        if ($uploaded instanceof Response) {
            DB::query('DELETE FROM video_delivery_orders WHERE id=?', [$orderId]);
            return $uploaded;
        }

        if (!$linked && !$uploaded) {
            DB::query('DELETE FROM video_delivery_orders WHERE id=?', [$orderId]);
            return $this->error('请至少选择一个视频素材或上传文件', 422);
        }

        if ($pointsCost > 0) {
            try {
                $ok = DB::transaction(static function () use ($profileId, $pointsCost) {
                    $fresh = DB::selectOne(
                        'SELECT points FROM video_delivery_clients WHERE id=? FOR UPDATE',
                        [$profileId]
                    );
                    $cur = max(0, (int)($fresh['points'] ?? 0));
                    if ($pointsCost > $cur) {
                        return false;
                    }
                    DB::query(
                        'UPDATE video_delivery_clients SET points=?, points_updated_at=NOW() WHERE id=?',
                        [$cur - $pointsCost, $profileId]
                    );
                    return true;
                });
            } catch (\Throwable $e) {
                $ok = false;
            }
            if (!$ok) {
                DB::query('DELETE FROM video_delivery_orders WHERE id=?', [$orderId]);
                $cur = max(0, (int)(DB::selectOne(
                    'SELECT points FROM video_delivery_clients WHERE id=?',
                    [$profileId]
                )['points'] ?? 0));
                return $this->error("积分不足：本次需 {$pointsCost}，当前余额 {$cur}", 422);
            }
        }

        $order = DB::selectOne('SELECT * FROM video_delivery_orders WHERE id=?', [$orderId]);
        $files = DB::select('SELECT * FROM video_delivery_files WHERE order_id=? ORDER BY id ASC', [$orderId]) ?: [];
        $assets = DB::select('SELECT * FROM video_delivery_assets WHERE order_id=? ORDER BY id ASC', [$orderId]) ?: [];
        return $this->success(
            VideoDeliveryService::mapOrder($order ?: ['id' => $orderId], $files, [], $assets),
            '已上传并创建交付单'
        );
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
        $remote = trim((string)($file['cdn_url'] ?? ''));
        if ($remote === '') {
            $remote = trim((string)($file['github_asset_url'] ?? ''));
        }
        if ($remote !== '') {
            $cors = CorsMiddleware::headers($request->getHeader('Origin'));
            return Response::make('', 302, array_merge($cors, ['Location' => $remote]));
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

        $remote = trim((string)($file['cdn_url'] ?? ''));
        if ($remote === '') {
            $remote = trim((string)($file['github_asset_url'] ?? ''));
        }
        if ($remote !== '') {
            $cors = CorsMiddleware::headers($request->getHeader('Origin'));
            return Response::make('', 302, array_merge($cors, ['Location' => $remote]));
        }
        $path = $this->resolveFilePath($file);
        if ($path === '' || !is_file($path)) {
            return $this->error('文件不存在', 404);
        }
        $mime = (string)($file['mime_type'] ?: 'application/octet-stream');
        $cors = CorsMiddleware::headers($request->getHeader('Origin'));
        return Response::file($path, 200, array_merge($cors, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $this->safeFilename((string)$file['file_name']) . '"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]));
    }

    /**
     * 路径中的 id = 企业客户管理用户 ID。
     * @return array|Response
     */
    private function loadClientMapped(int $adminUserId)
    {
        try {
            return VideoDeliveryService::ensureProfileForAdminUser($adminUserId);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->error('加载企业客户失败：' . $e->getMessage(), 500);
        }
    }

    /** @return array|Response */
    private function loadOrder(int $orderId)
    {
        VideoDeliveryService::ensureSchema();
        $order = DB::selectOne('SELECT * FROM video_delivery_orders WHERE id=?', [$orderId]);
        if (!$order) {
            return $this->error('交付单不存在', 404);
        }
        $profile = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [(int)$order['client_id']]);
        if (!$profile) {
            return $this->error('交付客户档案不存在', 404);
        }
        $adminUserId = (int)($profile['admin_user_id'] ?? 0);
        if ($adminUserId <= 0) {
            return $this->error('交付单未关联企业客户', 404);
        }
        $mapped = $this->loadClientMapped($adminUserId);
        if ($mapped instanceof Response) {
            return $mapped;
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

    /** @return list<int> */
    private function parseMaterialIds(Request $request): array
    {
        $raw = $request->input('materialIds', $request->input('material_ids', []));
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * 将素材库视频关联到交付单（复用素材 CDN；无 CDN 时尝试上传 GitHub）。
     * @param list<int> $materialIds
     * @return list<array>|Response
     */
    private function attachMaterials(int $clientId, int $orderId, array $materialIds)
    {
        if (!$materialIds) {
            return [];
        }
        GithubReleaseCdnService::ensureSchema();
        $ph = implode(',', array_fill(0, count($materialIds), '?'));
        $rows = DB::select(
            "SELECT id, name, file_path, file_name, mime_type, size_bytes,
                    IFNULL(cdn_url,'') AS cdn_url,
                    IFNULL(github_asset_url,'') AS github_asset_url,
                    IFNULL(github_config_id,0) AS github_config_id
             FROM materials WHERE id IN ({$ph})",
            $materialIds
        ) ?: [];
        if (!$rows) {
            return $this->error('所选视频素材不存在', 404);
        }
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int)$r['id']] = $r;
        }
        $saved = [];
        foreach ($materialIds as $mid) {
            $mat = $byId[$mid] ?? null;
            if (!$mat) {
                return $this->error('素材不存在：#' . $mid, 404);
            }
            $orig = (string)($mat['file_name'] ?? '');
            $display = trim((string)($mat['name'] ?? ''));
            if ($display === '') {
                $display = pathinfo($orig !== '' ? $orig : ('material-' . $mid), PATHINFO_FILENAME) ?: ('素材#' . $mid);
            }
            $path = (string)($mat['file_path'] ?? '');
            $abs = '';
            if ($path !== '') {
                if (is_file($path)) {
                    $abs = $path;
                } else {
                    $base = getenv('DATA_DIR') ?: (dirname(__DIR__, 3) . '/data');
                    $cand = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), '/\\');
                    if (is_file($cand)) {
                        $abs = $cand;
                    }
                }
            }
            $cdnUrl = trim((string)($mat['cdn_url'] ?? ''));
            $githubAssetUrl = trim((string)($mat['github_asset_url'] ?? ''));
            $githubConfigId = (int)($mat['github_config_id'] ?? 0);
            if ($cdnUrl === '' && $githubAssetUrl === '' && $abs !== '') {
                try {
                    $up = GithubReleaseCdnService::uploadMaterialFile($abs, $orig !== '' ? $orig : basename($abs), $mid);
                    if ($up) {
                        $cdnUrl = (string)($up['cdnUrl'] ?? '');
                        $githubAssetUrl = (string)($up['githubAssetUrl'] ?? '');
                        $githubConfigId = (int)($up['configId'] ?? 0);
                        if ($cdnUrl !== '' || $githubAssetUrl !== '') {
                            DB::query(
                                'UPDATE materials SET cdn_url=?, github_asset_url=?, github_config_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                                [$cdnUrl, $githubAssetUrl, $githubConfigId, $mid]
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    // CDN 失败不阻断交付关联
                }
            }
            $mime = (string)($mat['mime_type'] ?? '');
            $size = (int)($mat['size_bytes'] ?? 0);
            $rel = $path !== '' ? $path : ('material:' . $mid);
            DB::query(
                'INSERT INTO video_delivery_files
                  (order_id, name, file_name, file_path, mime_type, size_bytes, cdn_url, github_asset_url, github_config_id)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$orderId, $display, $orig !== '' ? $orig : $display, $rel, $mime, $size, $cdnUrl, $githubAssetUrl, $githubConfigId]
            );
            $fileId = (int)DB::lastInsertId();
            DB::query(
                'INSERT INTO video_delivery_assets
                  (order_id, file_id, material_id, name, file_name, local_path, cdn_url, github_asset_url, github_config_id, size_bytes, mime_type)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$orderId, $fileId, $mid, $display, $orig !== '' ? $orig : $display, $rel, $cdnUrl, $githubAssetUrl, $githubConfigId, $size, $mime]
            );
            $saved[] = [
                'id' => $fileId,
                'materialId' => $mid,
                'name' => $display,
                'cdnUrl' => $cdnUrl,
                'githubAssetUrl' => $githubAssetUrl,
            ];
        }
        return $saved;
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
            $cdnUrl = '';
            $githubAssetUrl = '';
            $githubConfigId = 0;
            try {
                GithubReleaseCdnService::ensureSchema();
                $up = GithubReleaseCdnService::uploadMaterialFile($dest, $orig, 0);
                if ($up) {
                    $cdnUrl = (string)($up['cdnUrl'] ?? '');
                    $githubAssetUrl = (string)($up['githubAssetUrl'] ?? '');
                    $githubConfigId = (int)($up['configId'] ?? 0);
                }
            } catch (\Throwable $e) {
                // CDN 失败不阻断本机入库；关联表仍记录本地路径
            }
            DB::query(
                'INSERT INTO video_delivery_files
                  (order_id, name, file_name, file_path, mime_type, size_bytes, cdn_url, github_asset_url, github_config_id)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$orderId, $display, $orig, $rel, $mime, $size, $cdnUrl, $githubAssetUrl, $githubConfigId]
            );
            $fileId = (int)DB::lastInsertId();
            DB::query(
                'INSERT INTO video_delivery_assets
                  (order_id, file_id, name, file_name, local_path, cdn_url, github_asset_url, github_config_id, size_bytes, mime_type)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$orderId, $fileId, $display, $orig, $rel, $cdnUrl, $githubAssetUrl, $githubConfigId, $size, $mime]
            );
            $saved[] = [
                'id' => $fileId,
                'name' => $display,
                'cdnUrl' => $cdnUrl,
                'githubAssetUrl' => $githubAssetUrl,
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
