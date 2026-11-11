<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Middlewares\CorsMiddleware;
use App\Services\CompanyService;
use App\Services\CustomVideoTypeService;
use App\Services\DataScope;
use App\Services\Github\GithubReleaseCdnService;
use App\Services\VideoDeliveryService;

class VideoDeliveryController extends Controller
{
    /** 企业客户角色不允许使用后台视频交付（其自助入口走企业门户） */
    private function denyEnterpriseCustomer(): ?Response
    {
        if (DataScope::isEnterpriseCustomer() || (DataScope::loginUser() && str_contains(DataScope::roleNameOfUser(DataScope::loginUser()), '企业客户'))) {
            return $this->error('企业客户角色无视频交付操作权限', 403);
        }
        return null;
    }

    public function list(Request $request): Response
    {
        if ($deny = $this->denyEnterpriseCustomer()) {
            return $deny;
        }
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
        $tokenRaw = $request->input('authToken', $request->input('auth_token', null));
        $token = $tokenRaw !== null
            ? trim((string)$tokenRaw)
            : trim((string)($mapped['authToken'] ?? ''));

        if ($authStart !== '' && $authEnd !== '' && $authEnd < $authStart) {
            return $this->error('服务结束日期不能早于开始日期', 422);
        }
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
        $companyId = (int)($mapped['companyId'] ?? 0);
        if ($companyId > 0) {
            try {
                CompanyService::ensureSchema();
                DB::query(
                    'UPDATE companies SET service_version=?, auth_start=?, auth_end=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [
                        $version,
                        $authStart !== '' ? $authStart : null,
                        $authEnd !== '' ? $authEnd : null,
                        $companyId,
                    ]
                );
            } catch (\Throwable $e) {
            }
        }
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
            "UPDATE video_delivery_clients SET
              auth_token=?,
              token_bound=0,
              remote_company_id='',
              remote_company_name='',
              last_token_check_at=NULL,
              last_token_error=NULL,
              updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$token, (int)$mapped['profileId']]
        );
        return $this->success(
            VideoDeliveryService::ensureProfileForAdminUser((int)$id),
            '已生成企业授权 Token，请在守护者后台绑定后点击「检测关联」'
        );
    }

    /**
     * 守护者企业查询：按本地企业名 POST company/search，入库是否创建与关联运营用户。
     * force=1 强制刷新；默认仅在运营用户为空时请求。
     */
    public function syncShouhuzheCompany(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $force = filter_var($request->input('force', $request->get('force', false)), FILTER_VALIDATE_BOOLEAN)
            || (string)$request->input('force', '') === '1';
        $result = VideoDeliveryService::syncShouhuzheCompany((int)$id, $force);
        $client = $result['client'] ?? $mapped;
        if (empty($result['ok'])) {
            return $this->error((string)($result['message'] ?? '守护者企业查询失败'), 422, [
                'client' => $client,
                'match' => $result['match'] ?? null,
            ]);
        }
        return $this->success([
            'client' => $client,
            'match' => $result['match'] ?? null,
            'skipped' => !empty($result['skipped']),
            'message' => (string)($result['message'] ?? '已同步'),
        ], (string)($result['message'] ?? '已同步'));
    }

    public function checkLink(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        // 先保存可能未落库的 token/日期（前端会先 save；此处再兜底读取）
        $token = trim((string)$request->input('authToken', $request->input('auth_token', $mapped['authToken'] ?? '')));
        if ($token !== '' && $token !== (string)($mapped['authToken'] ?? '')) {
            DB::query(
                'UPDATE video_delivery_clients SET auth_token=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$token, (int)$mapped['profileId']]
            );
        }
        $sync = VideoDeliveryService::syncTokenBinding((int)$mapped['profileId']);
        $client = $sync['client'] ?? VideoDeliveryService::ensureProfileForAdminUser((int)$id);
        $dateOk = ($client['authStart'] ?? '') !== '' && ($client['authEnd'] ?? '') !== '';
        $ok = !empty($sync['ok']) && !empty($client['authorized']) && $dateOk;
        $msg = !empty($sync['ok'])
            ? ($ok ? $sync['message'] : ($sync['message'] . '（请检查授权日期）'))
            : (string)($sync['message'] ?? '检测未通过');
        return $this->success([
            'ok' => $ok,
            'authorized' => !empty($client['authorized']),
            'tokenBound' => !empty($client['tokenBound']),
            'authToken' => (string)($client['authToken'] ?? ''),
            'client' => $client,
            'message' => $msg,
        ], $msg);
    }

    /**
     * 充值：对接守护者 video/payment-login，返回充值登录页。
     * 可选 sync=1 同步远程余额；mode=local 时仍支持本地调账（会写积分记录）。
     */
    public function recharge(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $profileId = (int)$mapped['profileId'];
        $mode = trim((string)$request->input('mode', 'payment'));
        $token = trim((string)($mapped['authToken'] ?? ''));

        if ($mode === 'sync' || $request->input('sync')) {
            $sync = VideoDeliveryService::syncTokenBinding($profileId);
            if (empty($sync['ok'])) {
                return $this->error((string)($sync['message'] ?? '同步失败'), 422);
            }
            return $this->success($sync['client'] ?? VideoDeliveryService::ensureProfileForAdminUser((int)$id), $sync['message']);
        }

        if ($mode === 'local') {
            $amount = (int)$request->input('amount', $request->input('points', 0));
            if ($amount === 0) {
                return $this->error('请输入充值积分（可为负数表示扣减）', 422);
            }
            $cur = max(0, (int)($mapped['points'] ?? 0));
            $next = max(0, $cur + $amount);
            DB::query(
                'UPDATE video_delivery_clients SET points=?, points_updated_at=NOW(), updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$next, $profileId]
            );
            VideoDeliveryService::writePointRecord([
                'client_id' => $profileId,
                'order_id' => 0,
                'operator_user_id' => VideoDeliveryService::currentUserId(),
                'operator_name' => VideoDeliveryService::currentUserName(),
                'direction' => $amount > 0 ? 'recharge' : 'deduct',
                'points' => abs($amount),
                'balance_after' => $next,
                'title' => '本地调账',
                'points_note' => (string)$request->input('note', 'Node 本地积分调账'),
                'source' => 'local_adjust',
            ]);
            return $this->success(VideoDeliveryService::ensureProfileForAdminUser((int)$id), '本地积分已更新');
        }

        // 默认：远程充值登录页
        if ($token === '') {
            return $this->error('请先生成并绑定授权 Token 后再充值', 422);
        }
        if (empty($mapped['tokenBound'])) {
            $sync = VideoDeliveryService::syncTokenBinding($profileId);
            if (empty($sync['ok'])) {
                return $this->error('Token 未绑定守护者企业：' . ($sync['message'] ?? ''), 422);
            }
            $mapped = $sync['client'] ?? $mapped;
        }
        $pay = \App\Services\ShouhuzheVideoApiService::paymentLogin($token);
        if (empty($pay['status'])) {
            return $this->error((string)($pay['error_msg'] ?? $pay['message'] ?? '获取充值页面失败'), 422);
        }
        $data = is_array($pay['data'] ?? null) ? $pay['data'] : [];
        $loginUrl = trim((string)($data['login_url'] ?? ''));
        if ($loginUrl === '') {
            return $this->error('充值接口未返回登录地址', 422);
        }
        VideoDeliveryService::applyRemoteBalance($profileId, $data);
        $client = VideoDeliveryService::ensureProfileForAdminUser((int)$id);
        return $this->success([
            'loginUrl' => $loginUrl,
            'client' => $client,
            'authToken' => $token,
        ], '请前往守护者页面完成充值');
    }

    public function pointRecords(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $limit = max(1, min(200, (int)$request->get('limit', 50)));
        return $this->success([
            'clientId' => (int)$mapped['profileId'],
            'items' => VideoDeliveryService::listPointRecords((int)$mapped['profileId'], $limit),
        ]);
    }

    /** 获取企业 Token（本地存储 + 可选远程校验） */
    public function getToken(Request $request, string $id): Response
    {
        $mapped = $this->loadClientMapped((int)$id);
        if ($mapped instanceof Response) {
            return $mapped;
        }
        $syncRemote = (int)$request->get('sync', $request->input('sync', 0)) === 1;
        if ($syncRemote && trim((string)($mapped['authToken'] ?? '')) !== '') {
            $sync = VideoDeliveryService::syncTokenBinding((int)$mapped['profileId']);
            if (!empty($sync['client'])) {
                $mapped = $sync['client'];
            }
        }
        return $this->success([
            'authToken' => (string)($mapped['authToken'] ?? ''),
            'tokenBound' => !empty($mapped['tokenBound']),
            'remoteCompanyId' => (string)($mapped['remoteCompanyId'] ?? ''),
            'remoteCompanyName' => (string)($mapped['remoteCompanyName'] ?? ''),
            'points' => (int)($mapped['points'] ?? 0),
            'client' => $mapped,
        ]);
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

        // 积分在客户确认 / 自动确认时扣除，此处仅记录预估消耗
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
        $orderId = (int)DB::getPdo()->lastInsertId();

        $linked = $this->attachMaterials($adminUserId, $orderId, $materialIds);
        if ($linked instanceof Response) {
            DB::query('DELETE FROM video_delivery_orders WHERE id=?', [$orderId]);
            return $linked;
        }
        VideoDeliveryService::syncMaterialGithubLinks($orderId);

        $uploaded = $this->storeUploadedFiles($adminUserId, $orderId);
        if ($uploaded instanceof Response) {
            DB::query('DELETE FROM video_delivery_orders WHERE id=?', [$orderId]);
            return $uploaded;
        }

        if (!$linked && !$uploaded) {
            DB::query('DELETE FROM video_delivery_orders WHERE id=?', [$orderId]);
            return $this->error('请至少选择一个视频素材或上传文件', 422);
        }

        $order = DB::selectOne('SELECT * FROM video_delivery_orders WHERE id=?', [$orderId]);
        $files = DB::select('SELECT * FROM video_delivery_files WHERE order_id=? ORDER BY id ASC', [$orderId]) ?: [];
        $assets = DB::select('SELECT * FROM video_delivery_assets WHERE order_id=? ORDER BY id ASC', [$orderId]) ?: [];
        return $this->success(
            VideoDeliveryService::mapOrder($order ?: ['id' => $orderId], $files, [], $assets),
            '已上传并创建交付单（积分将在客户确认后扣除）'
        );
    }

    public function showOrder(Request $request, string $id): Response
    {
        $order = $this->loadOrder((int)$id);
        if ($order instanceof Response) {
            return $order;
        }
        $oid = (int)$id;
        // 素材侧已有 GitHub 链接时，回写到交付文件（历史单可能未同步）
        VideoDeliveryService::syncMaterialGithubLinks($oid);
        $files = DB::select('SELECT * FROM video_delivery_files WHERE order_id=? ORDER BY id ASC', [$oid]) ?: [];
        $assets = DB::select('SELECT * FROM video_delivery_assets WHERE order_id=? ORDER BY id ASC', [$oid]) ?: [];
        $notes = DB::select(
            'SELECT * FROM video_delivery_notes WHERE order_id=? ORDER BY id DESC LIMIT 50',
            [$oid]
        ) ?: [];
        $client = null;
        $profileId = (int)($order['client_id'] ?? 0);
        if ($profileId > 0) {
            $profile = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [$profileId]);
            if ($profile) {
                $adminUserId = (int)($profile['admin_user_id'] ?? 0);
                if ($adminUserId > 0) {
                    try {
                        $client = VideoDeliveryService::ensureProfileForAdminUser($adminUserId);
                    } catch (\Throwable $e) {
                        $client = VideoDeliveryService::mapClient($profile);
                        $client['profileId'] = $profileId;
                    }
                } else {
                    $client = VideoDeliveryService::mapClient($profile);
                    $client['profileId'] = $profileId;
                }
            }
        }
        return $this->success([
            'order' => VideoDeliveryService::mapOrder($order, $files, $notes, $assets),
            'client' => $client,
        ]);
    }

    public function updateOrder(Request $request, string $id): Response
    {
        $order = $this->loadOrder((int)$id);
        if ($order instanceof Response) {
            return $order;
        }
        $oid = (int)$id;
        $prevStatus = VideoDeliveryService::normalizeOrderStatus((string)($order['status'] ?? 'pending'));

        $title = trim((string)$request->input('title', $order['title'] ?? ''));
        if ($title === '') {
            return $this->error('请填写视频标题', 422);
        }
        $requirements = array_key_exists('requirements', $request->all() ?: [])
            ? trim((string)$request->input('requirements', ''))
            : (string)($order['requirements'] ?? '');
        $cloudDrive = array_key_exists('cloudDriveInfo', $request->all() ?: [])
            || array_key_exists('cloud_drive_info', $request->all() ?: [])
            ? trim((string)$request->input('cloudDriveInfo', $request->input('cloud_drive_info', '')))
            : (string)($order['cloud_drive_info'] ?? '');

        $settleRaw = trim((string)$request->input('settleType', $request->input('settle_type', $order['settle_type'] ?? '')));
        $settleCode = $settleRaw !== '' ? $settleRaw : (string)($order['settle_type'] ?? '');
        $qty = max(1, (int)$request->input('quantity', $request->input('qty', 1)));
        $manualPtsRaw = $request->input('pointsCost', $request->input('points_cost', null));
        $manualPts = $manualPtsRaw === null || $manualPtsRaw === ''
            ? (int)($order['points_cost'] ?? 0)
            : (int)$manualPtsRaw;

        CustomVideoTypeService::ensureSchema();
        $quote = CustomVideoTypeService::quote($settleCode, $qty, $manualPts);
        if (empty($quote['ok'])) {
            // 兼容历史中文 settle_type：保留原值，积分用手工/原值
            $settleCode = $settleRaw !== '' ? $settleRaw : (string)($order['settle_type'] ?? '');
            $pointsCost = max(0, $manualPts);
        } else {
            $settleCode = (string)$quote['code'];
            $pointsCost = max(0, (int)$quote['pointsCost']);
        }

        $status = VideoDeliveryService::normalizeOrderStatus(
            (string)$request->input('status', $order['status'] ?? 'pending')
        );
        $version = VideoDeliveryService::normalizeVersion(
            (string)$request->input(
                'serviceVersion',
                $request->input('service_version', $order['service_version'] ?? 'standard')
            )
        );

        $deliveredAt = $order['delivered_at'] ?? null;
        if ($status === 'completed' && empty($deliveredAt)) {
            $deliveredAt = date('Y-m-d H:i:s');
        }

        DB::query(
            "UPDATE video_delivery_orders SET
              title=?, requirements=?, cloud_drive_info=?, settle_type=?, points_cost=?,
              status=?, service_version=?, delivered_at=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [
                mb_substr($title, 0, 255),
                $requirements,
                $cloudDrive,
                $settleCode,
                $pointsCost,
                $status,
                $version,
                $deliveredAt,
                $oid,
            ]
        );

        $profileId = (int)($order['client_id'] ?? 0);
        $adminUserId = 0;
        if ($profileId > 0) {
            $profile = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [$profileId]);
            $adminUserId = (int)($profile['admin_user_id'] ?? 0);
        }

        $materialIds = $this->parseMaterialIds($request);
        if ($materialIds && $adminUserId > 0) {
            // 跳过已关联素材，避免重复插入
            $existRows = DB::select(
                'SELECT material_id FROM video_delivery_assets WHERE order_id=? AND material_id>0',
                [$oid]
            ) ?: [];
            $existMap = [];
            foreach ($existRows as $er) {
                $existMap[(int)$er['material_id']] = true;
            }
            $newMaterialIds = array_values(array_filter(
                $materialIds,
                static fn(int $mid) => empty($existMap[$mid])
            ));
            if ($newMaterialIds) {
                $linked = $this->attachMaterials($adminUserId, $oid, $newMaterialIds);
                if ($linked instanceof Response) {
                    return $linked;
                }
            }
        }
        // 已关联素材：把 materials 上最新 GitHub/CDN 链接同步到交付文件
        VideoDeliveryService::syncMaterialGithubLinks($oid);
        if ($adminUserId > 0) {
            $uploaded = $this->storeUploadedFiles($adminUserId, $oid);
            if ($uploaded instanceof Response) {
                return $uploaded;
            }
        }

        $note = mb_substr(trim((string)$request->input('internalNote', $request->input('note', ''))), 0, 1000);
        if ($note !== '') {
            DB::query(
                'INSERT INTO video_delivery_notes (order_id, content, author_id, author_name) VALUES (?,?,?,?)',
                [
                    $oid,
                    $note,
                    VideoDeliveryService::currentUserId(),
                    VideoDeliveryService::currentUserName(),
                ]
            );
        }

        // 积分改在客户确认 / 自动确认时通过守护者接口扣除

        $fresh = DB::selectOne('SELECT * FROM video_delivery_orders WHERE id=?', [$oid]) ?: $order;
        $files = DB::select('SELECT * FROM video_delivery_files WHERE order_id=? ORDER BY id ASC', [$oid]) ?: [];
        $assets = DB::select('SELECT * FROM video_delivery_assets WHERE order_id=? ORDER BY id ASC', [$oid]) ?: [];
        $notes = DB::select(
            'SELECT * FROM video_delivery_notes WHERE order_id=? ORDER BY id DESC LIMIT 50',
            [$oid]
        ) ?: [];
        return $this->success(
            VideoDeliveryService::mapOrder($fresh, $files, $notes, $assets),
            '交付单已更新'
        );
    }

    public function orderFiles(Request $request, string $id): Response
    {
        $order = $this->loadOrder((int)$id);
        if ($order instanceof Response) {
            return $order;
        }
        $oid = (int)$id;
        VideoDeliveryService::syncMaterialGithubLinks($oid);
        $files = DB::select('SELECT * FROM video_delivery_files WHERE order_id=? ORDER BY id ASC', [$oid]) ?: [];
        return $this->success([
            'orderId' => $oid,
            'title' => (string)($order['title'] ?? ''),
            'files' => array_map([VideoDeliveryService::class, 'mapFile'], $files),
        ]);
    }

    public function addNote(Request $request, string $id): Response
    {
        $order = $this->loadOrder((int)$id);
        if ($order instanceof Response) {
            return $order;
        }
        $oid = (int)$id;
        $content = mb_substr(trim((string)$request->input('content', $request->input('note', ''))), 0, 1000);
        if ($content === '') {
            return $this->error('请填写备注内容', 422);
        }
        DB::query(
            'INSERT INTO video_delivery_notes (order_id, content, author_id, author_name) VALUES (?,?,?,?)',
            [
                $oid,
                $content,
                VideoDeliveryService::currentUserId(),
                VideoDeliveryService::currentUserName(),
            ]
        );
        $notes = DB::select(
            'SELECT * FROM video_delivery_notes WHERE order_id=? ORDER BY id DESC LIMIT 50',
            [$oid]
        ) ?: [];
        return $this->success([
            'orderId' => $oid,
            'notes' => array_map([VideoDeliveryService::class, 'mapNote'], $notes),
        ], '备注已添加');
    }

    public function downloadRecords(Request $request, string $id): Response
    {
        $order = $this->loadOrder((int)$id);
        if ($order instanceof Response) {
            return $order;
        }
        $oid = (int)$id;
        $rows = DB::select(
            'SELECT * FROM video_delivery_downloads WHERE order_id=? ORDER BY id DESC LIMIT 100',
            [$oid]
        ) ?: [];
        return $this->success([
            'orderId' => $oid,
            'downloadCount' => (int)($order['download_count'] ?? 0),
            'items' => array_map([VideoDeliveryService::class, 'mapDownload'], $rows),
        ]);
    }

    public function streamFile(Request $request, string $id): Response
    {
        $file = $this->loadFile((int)$id);
        if ($file instanceof Response) {
            return $file;
        }
        VideoDeliveryService::syncMaterialGithubLinks((int)($file['order_id'] ?? 0));
        $fresh = DB::selectOne('SELECT * FROM video_delivery_files WHERE id=?', [(int)$id]);
        if ($fresh) {
            $file = $fresh;
        }
        $remote = VideoDeliveryService::resolveGithubPlayUrl($file);
        if ($remote !== '') {
            $cors = CorsMiddleware::headers($request->getHeader('Origin'));
            return Response::make('', 302, array_merge($cors, ['Location' => $remote]));
        }
        // 预览统一走 GitHub；无链接时不再回退本地路径（API 机通常读不到线下素材）
        return $this->error('暂无 GitHub/CDN 链接，无法在线预览', 422);
        $mime = (string)($file['mime_type'] ?: 'application/octet-stream');
        $cors = CorsMiddleware::headers($request->getHeader('Origin'));
        return Response::file($path, 200, array_merge($cors, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $this->safeFilename((string)$file['file_name']) . '"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]));
    }

    public function downloadFile(Request $request, string $id): Response
    {
        $file = $this->loadFile((int)$id);
        if ($file instanceof Response) {
            return $file;
        }
        $fileId = (int)$id;
        $orderId = (int)($file['order_id'] ?? 0);
        DB::query(
            'INSERT INTO video_delivery_downloads (order_id, file_id, operator_id, operator_name) VALUES (?,?,?,?)',
            [
                $orderId,
                $fileId,
                VideoDeliveryService::currentUserId(),
                VideoDeliveryService::currentUserName(),
            ]
        );
        DB::query('UPDATE video_delivery_orders SET download_count=download_count+1 WHERE id=?', [$orderId]);

        VideoDeliveryService::syncMaterialGithubLinks($orderId);
        $fresh = DB::selectOne('SELECT * FROM video_delivery_files WHERE id=?', [$fileId]);
        if ($fresh) {
            $file = $fresh;
        }
        $remote = VideoDeliveryService::resolveGithubPlayUrl($file);
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
        if ($deny = $this->denyEnterpriseCustomer()) {
            return $deny;
        }
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
            if ($cdnUrl === '' && $githubAssetUrl === '') {
                if ($abs === '') {
                    return $this->error('素材 #' . $mid . ' 无本地文件且未上传 GitHub，请先完成 GitHub 上传', 422);
                }
                try {
                    $up = GithubReleaseCdnService::uploadMaterialFile($abs, $orig !== '' ? $orig : basename($abs), $mid);
                } catch (\Throwable $e) {
                    return $this->error('素材 #' . $mid . ' GitHub 上传失败：' . $e->getMessage(), 500);
                }
                if (!$up) {
                    return $this->error('未配置启用的 GitHub CDN，无法交付视频', 422);
                }
                $cdnUrl = (string)($up['cdnUrl'] ?? '');
                $githubAssetUrl = (string)($up['githubAssetUrl'] ?? '');
                $githubConfigId = (int)($up['configId'] ?? 0);
                if ($cdnUrl === '' && $githubAssetUrl === '') {
                    return $this->error('素材 #' . $mid . ' GitHub 上传未返回有效链接', 500);
                }
                DB::query(
                    'UPDATE materials SET cdn_url=?, github_asset_url=?, github_config_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$cdnUrl, $githubAssetUrl, $githubConfigId, $mid]
                );
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
            $fileId = (int)DB::getPdo()->lastInsertId();
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
            $fileId = (int)DB::getPdo()->lastInsertId();
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
