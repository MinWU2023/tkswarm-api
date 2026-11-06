<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;
use App\Services\Github\GithubReleaseCdnService;

/**
 * 视频交付：客户 + 授权/积分 + 定制订单 + 交付文件 + 备注/下载记录
 */
class VideoDeliveryService
{
    public const VERSIONS = [
        'standard' => '标准版',
        'pro' => '专业版',
        'enterprise' => '企业版',
    ];

    public const ORDER_STATUSES = [
        'pending' => '待处理',
        'processing' => '制作中',
        'completed' => '已完成',
        'cancelled' => '已取消',
    ];

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_clients (
              id INT AUTO_INCREMENT PRIMARY KEY,
              admin_user_id INT NOT NULL DEFAULT 0,
              account_name VARCHAR(120) NOT NULL DEFAULT '',
              linkedin_company VARCHAR(255) NOT NULL DEFAULT '',
              phone VARCHAR(64) NOT NULL DEFAULT '',
              audit_status VARCHAR(16) NOT NULL DEFAULT 'approved',
              registered_at DATETIME NULL,
              last_login_at DATETIME NULL,
              video_ids TEXT NULL,
              notes VARCHAR(500) NOT NULL DEFAULT '',
              service_version VARCHAR(32) NOT NULL DEFAULT 'standard',
              auth_start DATE NULL,
              auth_end DATE NULL,
              auth_token VARCHAR(128) NOT NULL DEFAULT '',
              points INT NOT NULL DEFAULT 0,
              points_updated_at DATETIME NULL,
              owner_id INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_vdc_audit (audit_status),
              KEY idx_vdc_owner (owner_id),
              KEY idx_vdc_name (account_name),
              KEY idx_vdc_admin_user (admin_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::ensureClientColumns();

        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_orders (
              id INT AUTO_INCREMENT PRIMARY KEY,
              client_id INT NOT NULL,
              title VARCHAR(255) NOT NULL DEFAULT '',
              requirements TEXT NULL,
              settle_type VARCHAR(64) NOT NULL DEFAULT '按次结算',
              points_cost INT NOT NULL DEFAULT 0,
              status VARCHAR(32) NOT NULL DEFAULT 'completed',
              service_version VARCHAR(32) NOT NULL DEFAULT 'standard',
              download_count INT NOT NULL DEFAULT 0,
              owner_id INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_vdo_client (client_id),
              KEY idx_vdo_status (status),
              KEY idx_vdo_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_files (
              id INT AUTO_INCREMENT PRIMARY KEY,
              order_id INT NOT NULL,
              name VARCHAR(255) NOT NULL DEFAULT '',
              file_name VARCHAR(255) NOT NULL DEFAULT '',
              file_path VARCHAR(500) NOT NULL DEFAULT '',
              mime_type VARCHAR(128) NOT NULL DEFAULT '',
              size_bytes BIGINT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_vdf_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_notes (
              id INT AUTO_INCREMENT PRIMARY KEY,
              order_id INT NOT NULL,
              content VARCHAR(1000) NOT NULL DEFAULT '',
              author_id INT NOT NULL DEFAULT 0,
              author_name VARCHAR(120) NOT NULL DEFAULT '',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_vdn_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_downloads (
              id INT AUTO_INCREMENT PRIMARY KEY,
              order_id INT NOT NULL,
              file_id INT NULL,
              operator_id INT NOT NULL DEFAULT 0,
              operator_name VARCHAR(120) NOT NULL DEFAULT '',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_vdd_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 交付素材关联：本地路径 → GitHub CDN 链接
        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_assets (
              id INT AUTO_INCREMENT PRIMARY KEY,
              order_id INT NOT NULL,
              file_id INT NOT NULL DEFAULT 0,
              material_id INT NOT NULL DEFAULT 0,
              name VARCHAR(255) NOT NULL DEFAULT '',
              file_name VARCHAR(255) NOT NULL DEFAULT '',
              local_path VARCHAR(1000) NOT NULL DEFAULT '',
              cdn_url VARCHAR(1000) NOT NULL DEFAULT '',
              github_asset_url VARCHAR(1000) NOT NULL DEFAULT '',
              github_config_id INT NOT NULL DEFAULT 0,
              size_bytes BIGINT NOT NULL DEFAULT 0,
              mime_type VARCHAR(128) NOT NULL DEFAULT '',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_vda_order (order_id),
              KEY idx_vda_file (file_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::ensureOrderColumns();
        self::ensureFileColumns();
        self::ensurePointRecordsSchema();
        try {
            CustomVideoTypeService::ensureSchema();
        } catch (\Throwable $e) {
        }
        try {
            ShouhuzheVideoApiService::ensureSettingsSchema();
        } catch (\Throwable $e) {
        }
    }

    public static function ensurePointRecordsSchema(): void
    {
        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_point_records (
              id INT AUTO_INCREMENT PRIMARY KEY,
              client_id INT NOT NULL DEFAULT 0,
              order_id INT NOT NULL DEFAULT 0,
              operator_user_id INT NOT NULL DEFAULT 0,
              operator_name VARCHAR(120) NOT NULL DEFAULT '',
              direction VARCHAR(16) NOT NULL DEFAULT 'deduct',
              points INT NOT NULL DEFAULT 0,
              balance_after INT NULL,
              title VARCHAR(255) NOT NULL DEFAULT '',
              settle_type VARCHAR(64) NOT NULL DEFAULT '',
              points_note TEXT NULL,
              remote_company_id VARCHAR(64) NOT NULL DEFAULT '',
              remote_company_name VARCHAR(255) NOT NULL DEFAULT '',
              remote_available_point INT NULL,
              remote_deducted_points INT NULL,
              remote_already_deducted TINYINT NULL,
              remote_response TEXT NULL,
              source VARCHAR(32) NOT NULL DEFAULT 'confirm',
              deducted_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_vdpr_client (client_id, id),
              KEY idx_vdpr_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private static function ensureOrderColumns(): void
    {
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM video_delivery_orders') ?: [], 'Field');
        } catch (\Throwable $e) {
            return;
        }
        $alters = [
            'customer_confirm_status' => "ALTER TABLE video_delivery_orders ADD COLUMN customer_confirm_status VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER download_count",
            'customer_confirmed_at' => 'ALTER TABLE video_delivery_orders ADD COLUMN customer_confirmed_at DATETIME NULL AFTER customer_confirm_status',
            'delivered_at' => 'ALTER TABLE video_delivery_orders ADD COLUMN delivered_at DATETIME NULL AFTER customer_confirmed_at',
            'source' => "ALTER TABLE video_delivery_orders ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'ops' AFTER settle_type",
            'cloud_drive_info' => "ALTER TABLE video_delivery_orders ADD COLUMN cloud_drive_info TEXT NULL AFTER requirements",
            'points_deducted_at' => 'ALTER TABLE video_delivery_orders ADD COLUMN points_deducted_at DATETIME NULL AFTER delivered_at',
            'points_deduct_error' => 'ALTER TABLE video_delivery_orders ADD COLUMN points_deduct_error VARCHAR(500) NULL AFTER points_deducted_at',
        ];
        foreach ($alters as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    private static function ensureFileColumns(): void
    {
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM video_delivery_files') ?: [], 'Field');
        } catch (\Throwable $e) {
            return;
        }
        $alters = [
            'cdn_url' => "ALTER TABLE video_delivery_files ADD COLUMN cdn_url VARCHAR(1000) NOT NULL DEFAULT '' AFTER size_bytes",
            'github_asset_url' => "ALTER TABLE video_delivery_files ADD COLUMN github_asset_url VARCHAR(1000) NOT NULL DEFAULT '' AFTER cdn_url",
            'github_config_id' => 'ALTER TABLE video_delivery_files ADD COLUMN github_config_id INT NOT NULL DEFAULT 0 AFTER github_asset_url',
        ];
        foreach ($alters as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    private static function ensureClientColumns(): void
    {
        $cols = [
            'admin_user_id' => 'ALTER TABLE video_delivery_clients ADD COLUMN admin_user_id INT NOT NULL DEFAULT 0',
            'service_version' => "ALTER TABLE video_delivery_clients ADD COLUMN service_version VARCHAR(32) NOT NULL DEFAULT 'standard'",
            'auth_start' => 'ALTER TABLE video_delivery_clients ADD COLUMN auth_start DATE NULL',
            'auth_end' => 'ALTER TABLE video_delivery_clients ADD COLUMN auth_end DATE NULL',
            'auth_token' => "ALTER TABLE video_delivery_clients ADD COLUMN auth_token VARCHAR(128) NOT NULL DEFAULT ''",
            'points' => 'ALTER TABLE video_delivery_clients ADD COLUMN points INT NOT NULL DEFAULT 0',
            'points_updated_at' => 'ALTER TABLE video_delivery_clients ADD COLUMN points_updated_at DATETIME NULL',
            'token_bound' => 'ALTER TABLE video_delivery_clients ADD COLUMN token_bound TINYINT NOT NULL DEFAULT 0',
            'remote_company_id' => "ALTER TABLE video_delivery_clients ADD COLUMN remote_company_id VARCHAR(64) NOT NULL DEFAULT ''",
            'remote_company_name' => "ALTER TABLE video_delivery_clients ADD COLUMN remote_company_name VARCHAR(255) NOT NULL DEFAULT ''",
            'last_token_check_at' => 'ALTER TABLE video_delivery_clients ADD COLUMN last_token_check_at DATETIME NULL',
            'last_token_error' => 'ALTER TABLE video_delivery_clients ADD COLUMN last_token_error VARCHAR(500) NULL',
        ];
        foreach ($cols as $name => $sql) {
            try {
                $hit = DB::selectOne(
                    "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_delivery_clients' AND COLUMN_NAME=?",
                    [$name]
                );
                if ((int)($hit['c'] ?? 0) === 0) {
                    DB::query($sql);
                }
            } catch (\Throwable $e) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e2) {
                }
            }
        }
        try {
            DB::query('ALTER TABLE video_delivery_clients ADD UNIQUE KEY uk_vdc_admin_user (admin_user_id)');
        } catch (\Throwable $e) {
        }
    }

    /**
     * 列表：仅「企业客户」角色管理用户 + 关联企业。
     * @return array{items:list<array>,total:int,page:int,pageSize:int,pages:int,stats:array}
     */
    public static function listEnterpriseCustomers(int $page, int $pageSize, string $keyword, string $audit): array
    {
        self::ensureSchema();
        CompanyService::ensureSchema();

        $filters = [
            "r.name LIKE ?",
        ];
        $params = ['%企业客户%'];

        if ($keyword !== '') {
            $filters[] = '(u.username LIKE ? OR u.nickname LIKE ? OR c.name LIKE ? OR c.contact_phone LIKE ? OR c.contact_name LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($audit !== '') {
            $norm = self::normalizeAudit($audit);
            if ($norm === 'approved') {
                $filters[] = 'u.status=1';
            } elseif ($norm === 'rejected') {
                $filters[] = 'u.status=0';
            } elseif ($norm === 'pending') {
                $filters[] = 'u.status NOT IN (0,1)';
            }
        }

        // 可见范围：Admin 看全部；运营管理/运营专员看 company_users 关联企业下的企业客户；企业客户无数据
        if (DataScope::isSuper()) {
            // no extra filter
        } elseif (DataScope::isOpsAdmin() || DataScope::isOperator()) {
            $selfId = (int)(DataScope::effectiveUser()['id'] ?? 0);
            $companyIds = $selfId > 0 ? CompanyService::companyIdsOfUser($selfId) : [];
            if (!$companyIds) {
                return self::emptyListPack($page, $pageSize);
            }
            $ph = implode(',', array_fill(0, count($companyIds), '?'));
            $filters[] = "EXISTS (
                SELECT 1 FROM company_users cu2
                WHERE cu2.user_id=u.id AND cu2.company_id IN ({$ph})
            )";
            array_push($params, ...$companyIds);
        } else {
            return self::emptyListPack($page, $pageSize);
        }

        $from = "FROM admin_users u
            INNER JOIN admin_roles r ON r.id = u.role_id
            LEFT JOIN companies c ON c.id = COALESCE(
              (SELECT cu.company_id FROM company_users cu WHERE cu.user_id=u.id ORDER BY cu.is_primary DESC, cu.company_id ASC LIMIT 1),
              NULLIF(u.company_id,0)
            )";
        $where = 'WHERE ' . implode(' AND ', $filters);

        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c {$from} {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / max(1, $pageSize)));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $pageSize;

        $rows = DB::select(
            "SELECT u.id, u.username, u.nickname, u.status, u.created_at, u.updated_at, u.company_id,
                    r.name AS role_name,
                    c.id AS company_id_resolved, c.name AS company_name,
                    c.contact_phone AS company_phone, c.contact_name AS company_contact,
                    c.status AS company_status
             {$from}
             {$where}
             ORDER BY u.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        $userIds = array_map(static fn($r) => (int)$r['id'], $rows);
        $lastLoginMap = self::lastLoginMap($userIds);
        $profileMap = self::profileMapByAdminIds($userIds);

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::mapEnterpriseUserRow($row, $lastLoginMap, $profileMap);
        }

        // 统计「通过」用全量过滤条件再数一遍
        $approvedFilters = $filters;
        $approvedParams = $params;
        $approvedFilters[] = 'u.status=1';
        $approvedTotal = (int)(DB::selectOne(
            'SELECT COUNT(*) AS c ' . $from . ' WHERE ' . implode(' AND ', $approvedFilters),
            $approvedParams
        )['c'] ?? 0);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => $pages,
            'stats' => [
                'total' => $total,
                'approved' => $approvedTotal,
            ],
        ];
    }

    /** @return array{items:list,total:int,page:int,pageSize:int,pages:int,stats:array} */
    private static function emptyListPack(int $page, int $pageSize): array
    {
        return [
            'items' => [],
            'total' => 0,
            'page' => max(1, $page),
            'pageSize' => $pageSize,
            'pages' => 1,
            'stats' => ['total' => 0, 'approved' => 0],
        ];
    }

    /** @param list<int> $userIds @return array<int,string> */
    private static function lastLoginMap(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $out = [];
        try {
            $rows = DB::select(
                "SELECT user_id, MAX(created_at) AS last_at
                 FROM admin_sessions
                 WHERE user_id IN ({$ph})
                 GROUP BY user_id",
                $userIds
            ) ?: [];
            foreach ($rows as $r) {
                $out[(int)$r['user_id']] = (string)($r['last_at'] ?? '');
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** @param list<int> $userIds @return array<int,array> */
    private static function profileMapByAdminIds(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $rows = DB::select(
            "SELECT * FROM video_delivery_clients WHERE admin_user_id IN ({$ph})",
            $userIds
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['admin_user_id']] = $r;
        }
        return $out;
    }

    /**
     * 校验并返回企业客户管理用户；不可见则 null。
     * @return array<string,mixed>|null
     */
    public static function findAccessibleEnterpriseUser(int $adminUserId): ?array
    {
        if ($adminUserId <= 0) {
            return null;
        }
        CompanyService::ensureSchema();
        $row = DB::selectOne(
            "SELECT u.id, u.username, u.nickname, u.status, u.created_at, u.updated_at, u.company_id,
                    r.name AS role_name,
                    c.id AS company_id_resolved, c.name AS company_name,
                    c.contact_phone AS company_phone, c.contact_name AS company_contact,
                    c.status AS company_status
             FROM admin_users u
             INNER JOIN admin_roles r ON r.id = u.role_id
             LEFT JOIN companies c ON c.id = COALESCE(
               (SELECT cu.company_id FROM company_users cu WHERE cu.user_id=u.id ORDER BY cu.is_primary DESC, cu.company_id ASC LIMIT 1),
               NULLIF(u.company_id,0)
             )
             WHERE u.id=? AND r.name LIKE ?
             LIMIT 1",
            [$adminUserId, '%企业客户%']
        );
        if (!$row) {
            return null;
        }
        // 企业客户门户：允许访问本人档案（提交需求 / 查看交付）
        $loginId = (int)(DataScope::loginUser()['id'] ?? 0);
        if ($loginId > 0 && $loginId === $adminUserId) {
            return $row;
        }
        if (DataScope::isSuper()) {
            return $row;
        }
        if (DataScope::isOpsAdmin() || DataScope::isOperator()) {
            $selfId = (int)(DataScope::effectiveUser()['id'] ?? 0);
            $companyIds = $selfId > 0 ? CompanyService::companyIdsOfUser($selfId) : [];
            if (!$companyIds) {
                return null;
            }
            $ph = implode(',', array_fill(0, count($companyIds), '?'));
            $hit = DB::selectOne(
                "SELECT 1 AS ok FROM company_users WHERE user_id=? AND company_id IN ({$ph}) LIMIT 1",
                array_merge([$adminUserId], $companyIds)
            );
            return $hit ? $row : null;
        }
        return null;
    }

    /**
     * 按企业客户管理用户确保交付档案（授权/积分存在本表）。
     * @return array<string,mixed> profile row + display fields
     */
    public static function ensureProfileForAdminUser(int $adminUserId): array
    {
        self::ensureSchema();
        $user = self::findAccessibleEnterpriseUser($adminUserId);
        if (!$user) {
            throw new \RuntimeException('企业客户不存在或无权访问');
        }
        $profile = DB::selectOne('SELECT * FROM video_delivery_clients WHERE admin_user_id=?', [$adminUserId]);
        $accountName = trim((string)($user['nickname'] ?? ''));
        if ($accountName === '') {
            $accountName = (string)($user['username'] ?? '');
        }
        $companyName = (string)($user['company_name'] ?? '');
        $phone = (string)($user['company_phone'] ?? '');
        $audit = ((int)($user['status'] ?? 1) === 1) ? 'approved' : 'rejected';
        $registered = (string)($user['created_at'] ?? date('Y-m-d H:i:s'));
        $lastLoginMap = self::lastLoginMap([$adminUserId]);
        $lastLogin = $lastLoginMap[$adminUserId] ?? '';

        if (!$profile) {
            DB::query(
                "INSERT INTO video_delivery_clients
                  (admin_user_id, account_name, linkedin_company, phone, audit_status, registered_at, last_login_at, owner_id, points)
                 VALUES (?,?,?,?,?,?,?,?,0)",
                [
                    $adminUserId,
                    $accountName,
                    $companyName,
                    $phone,
                    $audit,
                    $registered !== '' ? $registered : null,
                    $lastLogin !== '' ? $lastLogin : null,
                    $adminUserId,
                ]
            );
            $profile = DB::selectOne('SELECT * FROM video_delivery_clients WHERE admin_user_id=?', [$adminUserId]);
        } else {
            // 同步展示字段（以管理用户/企业为准）
            DB::query(
                "UPDATE video_delivery_clients SET
                  account_name=?, linkedin_company=?, phone=?, audit_status=?,
                  registered_at=?, last_login_at=?, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [
                    $accountName,
                    $companyName,
                    $phone,
                    $audit,
                    $registered !== '' ? $registered : null,
                    $lastLogin !== '' ? $lastLogin : null,
                    (int)$profile['id'],
                ]
            );
            $profile = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [(int)$profile['id']]);
        }

        $mapped = self::mapClient($profile ?: []);
        $mapped['id'] = $adminUserId; // 对外统一用管理用户 ID
        $mapped['profileId'] = (int)($profile['id'] ?? 0);
        $mapped['adminUserId'] = $adminUserId;
        $mapped['username'] = (string)($user['username'] ?? '');
        $mapped['nickname'] = (string)($user['nickname'] ?? '');
        $mapped['roleName'] = (string)($user['role_name'] ?? '企业客户');
        $mapped['companyId'] = (int)($user['company_id_resolved'] ?? $user['company_id'] ?? 0);
        $mapped['companyName'] = $companyName;
        $mapped['companyContact'] = (string)($user['company_contact'] ?? '');
        $mapped['accountName'] = $accountName;
        $mapped['linkedinCompany'] = $companyName;
        $mapped['phone'] = $phone;
        $mapped['auditStatus'] = $audit;
        $mapped['registeredAt'] = $registered;
        $mapped['lastLoginAt'] = $lastLogin;
        return $mapped;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $lastLoginMap
     * @param array<int,array> $profileMap
     */
    public static function mapEnterpriseUserRow(array $row, array $lastLoginMap = [], array $profileMap = []): array
    {
        $uid = (int)($row['id'] ?? 0);
        $accountName = trim((string)($row['nickname'] ?? ''));
        if ($accountName === '') {
            $accountName = (string)($row['username'] ?? '');
        }
        $companyName = (string)($row['company_name'] ?? '');
        $phone = (string)($row['company_phone'] ?? '');
        $audit = ((int)($row['status'] ?? 1) === 1) ? 'approved' : 'rejected';
        $profile = $profileMap[$uid] ?? null;
        $authStart = (string)($profile['auth_start'] ?? '');
        $authEnd = (string)($profile['auth_end'] ?? '');
        $version = self::normalizeVersion((string)($profile['service_version'] ?? 'standard'));
        return [
            'id' => $uid,
            'adminUserId' => $uid,
            'profileId' => (int)($profile['id'] ?? 0),
            'accountName' => $accountName,
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
            'linkedinCompany' => $companyName,
            'companyId' => (int)($row['company_id_resolved'] ?? $row['company_id'] ?? 0),
            'companyName' => $companyName,
            'companyContact' => (string)($row['company_contact'] ?? ''),
            'phone' => $phone,
            'auditStatus' => $audit,
            'registeredAt' => (string)($row['created_at'] ?? ''),
            'lastLoginAt' => (string)($lastLoginMap[$uid] ?? ''),
            'roleName' => (string)($row['role_name'] ?? '企业客户'),
            'points' => (int)($profile['points'] ?? 0),
            'pointsUpdatedAt' => (string)($profile['points_updated_at'] ?? ''),
            'authStart' => $authStart,
            'authEnd' => $authEnd,
            'authToken' => (string)($profile['auth_token'] ?? ''),
            'authorized' => $authStart !== '' && $authEnd !== '' && $authEnd >= date('Y-m-d'),
            'serviceVersion' => $version,
            'serviceVersionLabel' => self::versionLabel($version),
            'videoIds' => [],
            'videoCount' => 0,
            'notes' => (string)($profile['notes'] ?? ''),
            'ownerId' => $uid,
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public static function storageDir(int $clientId = 0, int $orderId = 0): string
    {
        $base = getenv('DATA_DIR') ?: (dirname(__DIR__, 2) . '/data');
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'video-delivery';
        if ($clientId > 0) {
            $dir .= DIRECTORY_SEPARATOR . $clientId;
        }
        if ($orderId > 0) {
            $dir .= DIRECTORY_SEPARATOR . $orderId;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /** @param list<int|string>|string|null $raw */
    public static function normalizeVideoIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : (preg_split('/[,\s]+/', $raw) ?: []);
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $n = (int)$v;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        return array_values($ids);
    }

    public static function normalizeAudit(string $status): string
    {
        $s = trim($status);
        return match ($s) {
            '', '通过', 'pass', 'approved' => 'approved',
            '待审核', 'review', 'pending' => 'pending',
            '拒绝', 'deny', 'rejected' => 'rejected',
            default => $s,
        };
    }

    public static function auditLabel(string $status): string
    {
        return match (self::normalizeAudit($status)) {
            'approved' => '通过',
            'pending' => '待审核',
            'rejected' => '拒绝',
            default => $status !== '' ? $status : '通过',
        };
    }

    public static function resolveSettleTypeLabel(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '未指定';
        }
        try {
            $type = CustomVideoTypeService::findByCode($code);
            if ($type) {
                $label = (string)($type['settlementTitle'] ?: $type['title'] ?: '');
                if ($label !== '') {
                    return $label;
                }
            }
        } catch (\Throwable $e) {
        }
        return $code;
    }

    public static function normalizeSource(string $raw): string
    {
        $s = strtolower(trim($raw));
        return $s === 'customer' ? 'customer' : 'ops';
    }

    public static function sourceLabel(string $raw): string
    {
        return self::normalizeSource($raw) === 'customer' ? '客户需求' : '运营交付';
    }

    public static function versionLabel(string $code): string
    {
        return self::VERSIONS[$code] ?? ($code !== '' ? $code : '标准版');
    }

    public static function statusLabel(string $code): string
    {
        return self::ORDER_STATUSES[$code] ?? ($code !== '' ? $code : '已完成');
    }

    public static function normalizeVersion(string $raw): string
    {
        $s = trim($raw);
        if ($s === '' || $s === '标准版') {
            return 'standard';
        }
        if ($s === '专业版') {
            return 'pro';
        }
        if ($s === '企业版') {
            return 'enterprise';
        }
        if (isset(self::VERSIONS[$s])) {
            return $s;
        }
        return 'standard';
    }

    public static function normalizeOrderStatus(string $raw): string
    {
        $s = trim($raw);
        return match ($s) {
            '', '已完成', 'completed', 'done' => 'completed',
            '待处理', 'pending' => 'pending',
            '制作中', 'processing', 'working' => 'processing',
            '已取消', 'cancelled', 'canceled' => 'cancelled',
            default => isset(self::ORDER_STATUSES[$s]) ? $s : 'completed',
        };
    }

    public static function mapClient(array $row): array
    {
        $videoIds = self::normalizeVideoIds($row['video_ids'] ?? []);
        $version = self::normalizeVersion((string)($row['service_version'] ?? 'standard'));
        $authStart = (string)($row['auth_start'] ?? '');
        $authEnd = (string)($row['auth_end'] ?? '');
        $authorized = $authStart !== '' && $authEnd !== '' && $authEnd >= date('Y-m-d');
        return [
            'id' => (int)($row['id'] ?? 0),
            'accountName' => (string)($row['account_name'] ?? ''),
            'linkedinCompany' => (string)($row['linkedin_company'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'auditStatus' => (string)($row['audit_status'] ?? 'approved'),
            'registeredAt' => (string)($row['registered_at'] ?? $row['created_at'] ?? ''),
            'lastLoginAt' => (string)($row['last_login_at'] ?? ''),
            'videoIds' => $videoIds,
            'videoCount' => count($videoIds),
            'notes' => (string)($row['notes'] ?? ''),
            'serviceVersion' => $version,
            'serviceVersionLabel' => self::versionLabel($version),
            'authStart' => $authStart,
            'authEnd' => $authEnd,
            'authToken' => (string)($row['auth_token'] ?? ''),
            'authorized' => $authorized,
            'tokenBound' => !empty($row['token_bound']),
            'remoteCompanyId' => (string)($row['remote_company_id'] ?? ''),
            'remoteCompanyName' => (string)($row['remote_company_name'] ?? ''),
            'lastTokenCheckAt' => (string)($row['last_token_check_at'] ?? ''),
            'lastTokenError' => (string)($row['last_token_error'] ?? ''),
            'points' => (int)($row['points'] ?? 0),
            'pointsUpdatedAt' => (string)($row['points_updated_at'] ?? ''),
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /** @deprecated use mapClient */
    public static function mapRow(array $row): array
    {
        return self::mapClient($row);
    }

    /** 预览专用：仅返回 GitHub/CDN 链接（cdn 优先）。 */
    public static function resolveGithubPlayUrl(array $row): string
    {
        $cdn = trim((string)($row['cdn_url'] ?? $row['cdnUrl'] ?? ''));
        if ($cdn !== '') {
            return $cdn;
        }
        return trim((string)($row['github_asset_url'] ?? $row['githubAssetUrl'] ?? $row['playUrl'] ?? ''));
    }

    /**
     * 将 materials 上的 GitHub/CDN 回写到交付 assets/files。
     */
    public static function syncMaterialGithubLinks(int $orderId): int
    {
        self::ensureSchema();
        GithubReleaseCdnService::ensureSchema();
        $assets = DB::select(
            "SELECT id, file_id, material_id,
                    IFNULL(cdn_url,'') AS cdn_url,
                    IFNULL(github_asset_url,'') AS github_asset_url,
                    IFNULL(github_config_id,0) AS github_config_id
             FROM video_delivery_assets
             WHERE order_id=? AND material_id>0",
            [$orderId]
        ) ?: [];
        if (!$assets) {
            return 0;
        }
        $mids = [];
        foreach ($assets as $a) {
            $mid = (int)($a['material_id'] ?? 0);
            if ($mid > 0) {
                $mids[$mid] = true;
            }
        }
        $mids = array_keys($mids);
        if (!$mids) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($mids), '?'));
        $mats = DB::select(
            "SELECT id,
                    IFNULL(cdn_url,'') AS cdn_url,
                    IFNULL(github_asset_url,'') AS github_asset_url,
                    IFNULL(github_config_id,0) AS github_config_id
             FROM materials WHERE id IN ({$ph})",
            $mids
        ) ?: [];
        $byId = [];
        foreach ($mats as $m) {
            $byId[(int)$m['id']] = $m;
        }
        $updated = 0;
        foreach ($assets as $a) {
            $mid = (int)($a['material_id'] ?? 0);
            $mat = $byId[$mid] ?? null;
            if (!$mat) {
                continue;
            }
            $cdn = trim((string)($mat['cdn_url'] ?? ''));
            $gh = trim((string)($mat['github_asset_url'] ?? ''));
            $cfg = (int)($mat['github_config_id'] ?? 0);
            if ($cdn === '' && $gh === '') {
                continue;
            }
            $curCdn = trim((string)($a['cdn_url'] ?? ''));
            $curGh = trim((string)($a['github_asset_url'] ?? ''));
            $curCfg = (int)($a['github_config_id'] ?? 0);
            if ($curCdn === $cdn && $curGh === $gh && $curCfg === $cfg) {
                continue;
            }
            $aid = (int)($a['id'] ?? 0);
            $fid = (int)($a['file_id'] ?? 0);
            if ($aid > 0) {
                DB::query(
                    'UPDATE video_delivery_assets SET cdn_url=?, github_asset_url=?, github_config_id=? WHERE id=?',
                    [$cdn, $gh, $cfg, $aid]
                );
            }
            if ($fid > 0) {
                DB::query(
                    'UPDATE video_delivery_files SET cdn_url=?, github_asset_url=?, github_config_id=? WHERE id=?',
                    [$cdn, $gh, $cfg, $fid]
                );
            }
            $updated++;
        }
        return $updated;
    }

    public static function mapFile(array $row): array
    {
        $mime = (string)($row['mime_type'] ?? '');
        $name = (string)($row['name'] ?? $row['file_name'] ?? '');
        $ext = strtolower((string)pathinfo((string)($row['file_name'] ?? $name), PATHINFO_EXTENSION));
        $isVideo = str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'], true);
        $cdn = trim((string)($row['cdn_url'] ?? ''));
        $gh = trim((string)($row['github_asset_url'] ?? ''));
        $play = $cdn !== '' ? $cdn : $gh;
        return [
            'id' => (int)($row['id'] ?? 0),
            'orderId' => (int)($row['order_id'] ?? 0),
            'name' => $name !== '' ? $name : ('文件#' . (int)($row['id'] ?? 0)),
            'fileName' => (string)($row['file_name'] ?? ''),
            'mimeType' => $mime,
            'sizeBytes' => (int)($row['size_bytes'] ?? 0),
            'cdnUrl' => $cdn,
            'githubAssetUrl' => $gh,
            'githubConfigId' => (int)($row['github_config_id'] ?? 0),
            'playUrl' => $play,
            'hasGithub' => $play !== '',
            'isVideo' => $isVideo,
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    public static function mapAsset(array $row): array
    {
        $cdn = trim((string)($row['cdn_url'] ?? ''));
        $gh = trim((string)($row['github_asset_url'] ?? ''));
        $play = $cdn !== '' ? $cdn : $gh;
        $mime = (string)($row['mime_type'] ?? '');
        $ext = strtolower((string)pathinfo((string)($row['file_name'] ?? $row['name'] ?? ''), PATHINFO_EXTENSION));
        $isVideo = str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'], true);
        return [
            'id' => (int)($row['id'] ?? 0),
            'orderId' => (int)($row['order_id'] ?? 0),
            'fileId' => (int)($row['file_id'] ?? 0),
            'materialId' => (int)($row['material_id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'fileName' => (string)($row['file_name'] ?? ''),
            'localPath' => (string)($row['local_path'] ?? ''),
            'cdnUrl' => $cdn,
            'githubAssetUrl' => $gh,
            'githubConfigId' => (int)($row['github_config_id'] ?? 0),
            'playUrl' => $play,
            'hasGithub' => $play !== '',
            'sizeBytes' => (int)($row['size_bytes'] ?? 0),
            'mimeType' => $mime,
            'isVideo' => $isVideo,
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    public static function normalizeConfirmStatus(string $raw): string
    {
        $s = trim($raw);
        return match ($s) {
            'confirmed', '已确认', 'confirm' => 'confirmed',
            'auto_confirmed', 'auto', '自动确认' => 'auto_confirmed',
            default => 'pending',
        };
    }

    public static function confirmStatusLabel(string $code): string
    {
        return match (self::normalizeConfirmStatus($code)) {
            'confirmed' => '已确认',
            'auto_confirmed' => '自动确认',
            default => '待确认',
        };
    }

    public static function mapNote(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'orderId' => (int)($row['order_id'] ?? 0),
            'content' => (string)($row['content'] ?? ''),
            'authorId' => (int)($row['author_id'] ?? 0),
            'authorName' => (string)($row['author_name'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    public static function mapDownload(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'orderId' => (int)($row['order_id'] ?? 0),
            'fileId' => isset($row['file_id']) ? (int)$row['file_id'] : null,
            'operatorId' => (int)($row['operator_id'] ?? 0),
            'operatorName' => (string)($row['operator_name'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    public static function mapOrder(array $row, array $files = [], array $notes = [], array $assets = []): array
    {
        $status = self::normalizeOrderStatus((string)($row['status'] ?? 'completed'));
        $version = self::normalizeVersion((string)($row['service_version'] ?? 'standard'));
        $confirm = self::normalizeConfirmStatus((string)($row['customer_confirm_status'] ?? 'pending'));
        return [
            'id' => (int)($row['id'] ?? 0),
            'clientId' => (int)($row['client_id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'requirements' => (string)($row['requirements'] ?? ''),
            'cloudDriveInfo' => (string)($row['cloud_drive_info'] ?? ''),
            'settleType' => self::resolveSettleTypeLabel((string)($row['settle_type'] ?? '')),
            'settleTypeCode' => (string)($row['settle_type'] ?? ''),
            'source' => self::normalizeSource((string)($row['source'] ?? 'ops')),
            'sourceLabel' => self::sourceLabel((string)($row['source'] ?? 'ops')),
            'pointsCost' => (int)($row['points_cost'] ?? 0),
            'status' => $status,
            'statusLabel' => self::statusLabel($status),
            'serviceVersion' => $version,
            'serviceVersionLabel' => self::versionLabel($version),
            'downloadCount' => (int)($row['download_count'] ?? 0),
            'customerConfirmStatus' => $confirm,
            'customerConfirmStatusLabel' => self::confirmStatusLabel($confirm),
            'customerConfirmedAt' => (string)($row['customer_confirmed_at'] ?? ''),
            'deliveredAt' => (string)($row['delivered_at'] ?? $row['created_at'] ?? ''),
            'pointsDeductedAt' => (string)($row['points_deducted_at'] ?? ''),
            'pointsDeductError' => (string)($row['points_deduct_error'] ?? ''),
            'fileCount' => count($files),
            'files' => array_map([self::class, 'mapFile'], $files),
            'assets' => array_map([self::class, 'mapAsset'], $assets),
            'notes' => array_map([self::class, 'mapNote'], $notes),
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /**
     * 三日未确认自动确认，并扣除对应积分。
     * @return array{scanned:int,confirmed:int,deductFailed:int}
     */
    public static function autoConfirmExpired(int $days = 3): array
    {
        self::ensureSchema();
        $days = max(1, $days);
        $rows = DB::select(
            "SELECT * FROM video_delivery_orders
             WHERE customer_confirm_status='pending'
               AND status IN ('completed','processing')
               AND COALESCE(delivered_at, created_at) <= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY id ASC
             LIMIT 200",
            [$days]
        ) ?: [];
        $n = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $res = self::confirmOrderAndDeduct($row, 'auto_confirmed', 'auto_confirm', 0, '系统');
            if (!empty($res['ok'])) {
                $n++;
            } else {
                $failed++;
            }
        }
        return ['scanned' => count($rows), 'confirmed' => $n, 'deductFailed' => $failed];
    }

    /**
     * 客户确认 / 自动确认：更新确认状态并按 points_cost 调用守护者扣积分。
     *
     * @param array $order video_delivery_orders 行
     * @return array{ok:bool,message:string,order?:array,error?:string}
     */
    public static function confirmOrderAndDeduct(
        array $order,
        string $confirmStatus = 'confirmed',
        string $source = 'confirm',
        int $operatorId = 0,
        string $operatorName = ''
    ): array {
        self::ensureSchema();
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0) {
            return ['ok' => false, 'message' => '交付单无效', 'error' => 'invalid_order'];
        }
        $confirmStatus = self::normalizeConfirmStatus($confirmStatus) === 'auto_confirmed'
            ? 'auto_confirmed'
            : 'confirmed';

        $pointsCost = max(0, (int)($order['points_cost'] ?? 0));
        $alreadyDeducted = !empty($order['points_deducted_at']);
        $profileId = (int)($order['client_id'] ?? 0);
        $profile = $profileId > 0
            ? DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [$profileId])
            : null;

        if ($pointsCost > 0 && !$alreadyDeducted) {
            $token = trim((string)($profile['auth_token'] ?? ''));
            if ($token === '') {
                $msg = '企业未配置授权 Token，无法扣除积分';
                DB::query(
                    'UPDATE video_delivery_orders SET points_deduct_error=? WHERE id=?',
                    [mb_substr($msg, 0, 500), $orderId]
                );
                return ['ok' => false, 'message' => $msg, 'error' => 'token_missing'];
            }
            $deduct = ShouhuzheVideoApiService::deduct(
                $token,
                $pointsCost,
                $orderId,
                (string)($order['title'] ?? ('交付#' . $orderId)),
                '视频交付确认扣积分'
            );
            if (empty($deduct['status'])) {
                $msg = (string)($deduct['error_msg'] ?? $deduct['message'] ?? '远程积分扣除失败');
                DB::query(
                    'UPDATE video_delivery_orders SET points_deduct_error=? WHERE id=?',
                    [mb_substr($msg, 0, 500), $orderId]
                );
                return ['ok' => false, 'message' => $msg, 'error' => 'deduct_failed'];
            }
            $remote = is_array($deduct['data'] ?? null) ? $deduct['data'] : [];
            self::applyRemoteBalance($profileId, $remote);
            self::writePointRecord([
                'client_id' => $profileId,
                'order_id' => $orderId,
                'operator_user_id' => $operatorId,
                'operator_name' => $operatorName !== '' ? $operatorName : ($source === 'auto_confirm' ? '系统' : '客户'),
                'direction' => 'deduct',
                'points' => $pointsCost,
                'balance_after' => isset($remote['available_point']) ? (int)$remote['available_point'] : null,
                'title' => (string)($order['title'] ?? ''),
                'settle_type' => (string)($order['settle_type'] ?? ''),
                'points_note' => '确认交付扣积分',
                'remote_company_id' => (string)($remote['company_id'] ?? $profile['remote_company_id'] ?? ''),
                'remote_company_name' => (string)($remote['company_name'] ?? $profile['remote_company_name'] ?? ''),
                'remote_available_point' => isset($remote['available_point']) ? (int)$remote['available_point'] : null,
                'remote_deducted_points' => isset($remote['deducted_points']) ? (int)$remote['deducted_points'] : $pointsCost,
                'remote_already_deducted' => !empty($remote['already_deducted']) ? 1 : 0,
                'remote_response' => json_encode([
                    'status' => $deduct['status'] ?? null,
                    'data' => [
                        'available_point' => $remote['available_point'] ?? null,
                        'deducted_points' => $remote['deducted_points'] ?? null,
                        'already_deducted' => $remote['already_deducted'] ?? null,
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'source' => $source,
            ]);
            DB::query(
                'UPDATE video_delivery_orders SET points_deducted_at=NOW(), points_deduct_error=NULL WHERE id=?',
                [$orderId]
            );
        }

        $status = self::normalizeOrderStatus((string)($order['status'] ?? 'pending'));
        if ($status !== 'completed' && $status !== 'cancelled') {
            DB::query(
                "UPDATE video_delivery_orders
                 SET status='completed',
                     delivered_at=COALESCE(delivered_at, NOW()),
                     customer_confirm_status=?,
                     customer_confirmed_at=NOW()
                 WHERE id=? AND customer_confirm_status='pending'",
                [$confirmStatus, $orderId]
            );
        } else {
            DB::query(
                "UPDATE video_delivery_orders
                 SET customer_confirm_status=?, customer_confirmed_at=NOW()
                 WHERE id=? AND customer_confirm_status='pending'",
                [$confirmStatus, $orderId]
            );
        }

        $fresh = DB::selectOne('SELECT * FROM video_delivery_orders WHERE id=?', [$orderId]) ?: $order;
        return [
            'ok' => true,
            'message' => $confirmStatus === 'auto_confirmed' ? '已自动确认并结算积分' : '已确认并结算积分',
            'order' => $fresh,
        ];
    }

    /** @param array<string,mixed> $remote */
    public static function applyRemoteBalance(int $profileId, array $remote): void
    {
        if ($profileId <= 0) {
            return;
        }
        if (!array_key_exists('available_point', $remote)) {
            return;
        }
        $pts = max(0, (int)$remote['available_point']);
        DB::query(
            'UPDATE video_delivery_clients SET points=?, points_updated_at=NOW(), updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$pts, $profileId]
        );
        $sets = [];
        $params = [];
        if (isset($remote['company_id'])) {
            $sets[] = 'remote_company_id=?';
            $params[] = (string)$remote['company_id'];
        }
        if (isset($remote['company_name'])) {
            $sets[] = 'remote_company_name=?';
            $params[] = (string)$remote['company_name'];
        }
        if ($sets) {
            $params[] = $profileId;
            DB::query(
                'UPDATE video_delivery_clients SET ' . implode(', ', $sets) . ' WHERE id=?',
                $params
            );
        }
    }

    /**
     * 用企业 Token 检测绑定并同步积分（获取企业 token 绑定信息）。
     * @return array{ok:bool,message:string,client?:array}
     */
    public static function syncTokenBinding(int $profileId): array
    {
        self::ensureSchema();
        $profile = DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [$profileId]);
        if (!$profile) {
            return ['ok' => false, 'message' => '交付客户档案不存在'];
        }
        $token = trim((string)($profile['auth_token'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'message' => '请先生成授权 Token'];
        }
        $result = ShouhuzheVideoApiService::checkBinding($token);
        if (empty($result['status'])) {
            $msg = (string)($result['error_msg'] ?? $result['message'] ?? '授权检测失败');
            DB::query(
                "UPDATE video_delivery_clients
                 SET token_bound=0, last_token_check_at=NOW(), last_token_error=?, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [mb_substr($msg, 0, 500), $profileId]
            );
            return ['ok' => false, 'message' => $msg];
        }
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $pts = isset($data['available_point']) ? max(0, (int)$data['available_point']) : (int)($profile['points'] ?? 0);
        DB::query(
            "UPDATE video_delivery_clients SET
              token_bound=1,
              remote_company_id=?,
              remote_company_name=?,
              points=?,
              points_updated_at=NOW(),
              last_token_check_at=NOW(),
              last_token_error=NULL,
              updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [
                (string)($data['company_id'] ?? ''),
                (string)($data['company_name'] ?? ''),
                $pts,
                $profileId,
            ]
        );
        $adminUserId = (int)($profile['admin_user_id'] ?? 0);
        $client = $adminUserId > 0
            ? self::ensureProfileForAdminUser($adminUserId)
            : self::mapClient(DB::selectOne('SELECT * FROM video_delivery_clients WHERE id=?', [$profileId]) ?: $profile);
        return ['ok' => true, 'message' => '授权关联成功，已同步远程积分', 'client' => $client];
    }

    /** @param array<string,mixed> $row */
    public static function writePointRecord(array $row): void
    {
        self::ensurePointRecordsSchema();
        DB::query(
            "INSERT INTO video_delivery_point_records
              (client_id, order_id, operator_user_id, operator_name, direction, points, balance_after,
               title, settle_type, points_note, remote_company_id, remote_company_name,
               remote_available_point, remote_deducted_points, remote_already_deducted,
               remote_response, source, deducted_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
            [
                (int)($row['client_id'] ?? 0),
                (int)($row['order_id'] ?? 0),
                (int)($row['operator_user_id'] ?? 0),
                mb_substr((string)($row['operator_name'] ?? ''), 0, 120),
                (string)($row['direction'] ?? 'deduct'),
                (int)($row['points'] ?? 0),
                $row['balance_after'] ?? null,
                mb_substr((string)($row['title'] ?? ''), 0, 255),
                mb_substr((string)($row['settle_type'] ?? ''), 0, 64),
                $row['points_note'] ?? null,
                mb_substr((string)($row['remote_company_id'] ?? ''), 0, 64),
                mb_substr((string)($row['remote_company_name'] ?? ''), 0, 255),
                $row['remote_available_point'] ?? null,
                $row['remote_deducted_points'] ?? null,
                $row['remote_already_deducted'] ?? null,
                $row['remote_response'] ?? null,
                mb_substr((string)($row['source'] ?? 'confirm'), 0, 32),
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function listPointRecords(int $profileId, int $limit = 50): array
    {
        self::ensurePointRecordsSchema();
        $limit = max(1, min(200, $limit));
        $rows = DB::select(
            "SELECT * FROM video_delivery_point_records WHERE client_id=? ORDER BY id DESC LIMIT {$limit}",
            [$profileId]
        ) ?: [];
        return array_map(static function (array $r): array {
            return [
                'id' => (int)($r['id'] ?? 0),
                'clientId' => (int)($r['client_id'] ?? 0),
                'orderId' => (int)($r['order_id'] ?? 0),
                'operatorUserId' => (int)($r['operator_user_id'] ?? 0),
                'operatorName' => (string)($r['operator_name'] ?? ''),
                'direction' => (string)($r['direction'] ?? 'deduct'),
                'points' => (int)($r['points'] ?? 0),
                'balanceAfter' => isset($r['balance_after']) ? (int)$r['balance_after'] : null,
                'title' => (string)($r['title'] ?? ''),
                'settleType' => (string)($r['settle_type'] ?? ''),
                'pointsNote' => (string)($r['points_note'] ?? ''),
                'remoteCompanyId' => (string)($r['remote_company_id'] ?? ''),
                'remoteCompanyName' => (string)($r['remote_company_name'] ?? ''),
                'remoteAvailablePoint' => isset($r['remote_available_point']) ? (int)$r['remote_available_point'] : null,
                'remoteDeductedPoints' => isset($r['remote_deducted_points']) ? (int)$r['remote_deducted_points'] : null,
                'source' => (string)($r['source'] ?? ''),
                'deductedAt' => (string)($r['deducted_at'] ?? ''),
                'createdAt' => (string)($r['created_at'] ?? ''),
            ];
        }, $rows);
    }

    public static function generateToken(): string
    {
        try {
            // 对齐 dyyweb：64 位随机串作为企业视频授权 Token
            return bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            return sha1(uniqid((string)mt_rand(), true) . microtime(true)) . bin2hex(random_bytes(8));
        }
    }

    public static function currentUserName(): string
    {
        $u = DataScope::loginUser() ?: ($GLOBALS['auth_user'] ?? null);
        if (!is_array($u)) {
            return '系统';
        }
        $name = trim((string)($u['nickname'] ?? ''));
        if ($name === '') {
            $name = trim((string)($u['username'] ?? ''));
        }
        return $name !== '' ? $name : '管理员';
    }

    public static function currentUserId(): int
    {
        $u = DataScope::loginUser() ?: ($GLOBALS['auth_user'] ?? null);
        return is_array($u) ? (int)($u['id'] ?? 0) : 0;
    }
}
