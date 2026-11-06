<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

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
              KEY idx_vdc_name (account_name)
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
    }

    private static function ensureClientColumns(): void
    {
        $cols = [
            'service_version' => "ALTER TABLE video_delivery_clients ADD COLUMN service_version VARCHAR(32) NOT NULL DEFAULT 'standard'",
            'auth_start' => 'ALTER TABLE video_delivery_clients ADD COLUMN auth_start DATE NULL',
            'auth_end' => 'ALTER TABLE video_delivery_clients ADD COLUMN auth_end DATE NULL',
            'auth_token' => "ALTER TABLE video_delivery_clients ADD COLUMN auth_token VARCHAR(128) NOT NULL DEFAULT ''",
            'points' => 'ALTER TABLE video_delivery_clients ADD COLUMN points INT NOT NULL DEFAULT 0',
            'points_updated_at' => 'ALTER TABLE video_delivery_clients ADD COLUMN points_updated_at DATETIME NULL',
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

    public static function mapFile(array $row): array
    {
        $mime = (string)($row['mime_type'] ?? '');
        $name = (string)($row['name'] ?? $row['file_name'] ?? '');
        $ext = strtolower((string)pathinfo((string)($row['file_name'] ?? $name), PATHINFO_EXTENSION));
        $isVideo = str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'], true);
        return [
            'id' => (int)($row['id'] ?? 0),
            'orderId' => (int)($row['order_id'] ?? 0),
            'name' => $name !== '' ? $name : ('文件#' . (int)($row['id'] ?? 0)),
            'fileName' => (string)($row['file_name'] ?? ''),
            'mimeType' => $mime,
            'sizeBytes' => (int)($row['size_bytes'] ?? 0),
            'isVideo' => $isVideo,
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
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

    public static function mapOrder(array $row, array $files = [], array $notes = []): array
    {
        $status = self::normalizeOrderStatus((string)($row['status'] ?? 'completed'));
        $version = self::normalizeVersion((string)($row['service_version'] ?? 'standard'));
        return [
            'id' => (int)($row['id'] ?? 0),
            'clientId' => (int)($row['client_id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'requirements' => (string)($row['requirements'] ?? ''),
            'settleType' => (string)($row['settle_type'] ?? '按次结算'),
            'pointsCost' => (int)($row['points_cost'] ?? 0),
            'status' => $status,
            'statusLabel' => self::statusLabel($status),
            'serviceVersion' => $version,
            'serviceVersionLabel' => self::versionLabel($version),
            'downloadCount' => (int)($row['download_count'] ?? 0),
            'fileCount' => count($files),
            'files' => array_map([self::class, 'mapFile'], $files),
            'notes' => array_map([self::class, 'mapNote'], $notes),
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public static function generateToken(): string
    {
        try {
            return bin2hex(random_bytes(24));
        } catch (\Throwable $e) {
            return sha1(uniqid((string)mt_rand(), true) . microtime(true));
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
