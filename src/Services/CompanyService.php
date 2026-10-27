<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

class CompanyService
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `companies` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '公司名称',
              `contact_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '联系人',
              `contact_phone` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '联系电话',
              `contact_email` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '联系邮箱',
              `industry_id` INT NOT NULL DEFAULT 0 COMMENT '行业节点 industry_data.id',
              `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=正常 0=停用',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_companies_industry` (`industry_id`),
              KEY `idx_companies_status` (`status`),
              KEY `idx_companies_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            DB::query("CREATE TABLE IF NOT EXISTS `company_users` (
              `company_id` INT NOT NULL,
              `user_id` INT NOT NULL,
              `is_primary` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=主账号',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`company_id`, `user_id`),
              KEY `idx_company_users_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $cols = DB::select("SHOW COLUMNS FROM admin_users LIKE 'company_id'");
            if (!$cols) {
                DB::query("ALTER TABLE `admin_users`
                  ADD COLUMN `company_id` INT NULL DEFAULT NULL COMMENT '所属企业 companies.id' AFTER `role_id`");
                DB::query("ALTER TABLE `admin_users` ADD KEY `idx_admin_users_company` (`company_id`)");
            }
        } catch (\Throwable $e) {
        }
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        self::ensureSchema();
        if ($id <= 0) {
            return null;
        }
        $row = DB::table('companies')->where('id', $id)->first();
        return $row ? self::mapRow($row) : null;
    }

    /** @param array<string,mixed> $row */
    public static function mapRow(array $row): array
    {
        $industryId = (int)($row['industry_id'] ?? 0);
        $industryName = '';
        $industryPath = '';
        if ($industryId > 0) {
            try {
                IndustryDataService::ensureSchema();
                $ind = DB::table('industry_data')->where('id', $industryId)->first();
                $industryName = (string)($ind['name'] ?? '');
                $industryPath = IndustryDataService::pathLabel($industryId);
            } catch (\Throwable $e) {
            }
        }
        return [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'contactName' => (string)($row['contact_name'] ?? ''),
            'contactPhone' => (string)($row['contact_phone'] ?? ''),
            'contactEmail' => (string)($row['contact_email'] ?? ''),
            'industryId' => $industryId,
            'industryName' => $industryName,
            'industryPath' => $industryPath,
            'status' => (int)($row['status'] ?? 1),
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $fallback
     * @return array{name:string,contact_name:string,contact_phone:string,contact_email:string,industry_id:int,status:int}
     */
    private static function normalizePayload(array $data, ?array $fallback = null): array
    {
        IndustryDataService::ensureSchema();
        $fb = $fallback ?? [];
        $name = trim((string)($data['name'] ?? $fb['name'] ?? ''));
        $contactName = trim((string)($data['contact_name'] ?? $fb['contact_name'] ?? ''));
        $contactPhone = trim((string)($data['contact_phone'] ?? $fb['contact_phone'] ?? ''));
        $contactEmail = trim((string)($data['contact_email'] ?? $fb['contact_email'] ?? ''));
        $industryId = isset($data['industry_id']) ? (int)$data['industry_id'] : (int)($fb['industry_id'] ?? 0);
        $status = isset($data['status']) ? (int)$data['status'] : (int)($fb['status'] ?? 1);

        if ($name === '') {
            throw new \InvalidArgumentException('公司名称不能为空');
        }
        if ($industryId <= 0) {
            throw new \InvalidArgumentException('请选择行业分类');
        }
        $industry = DB::table('industry_data')->where('id', $industryId)->first();
        if (!$industry) {
            throw new \InvalidArgumentException('所选行业不存在');
        }

        return [
            'name' => mb_substr($name, 0, 128),
            'contact_name' => mb_substr($contactName, 0, 64),
            'contact_phone' => mb_substr($contactPhone, 0, 32),
            'contact_email' => mb_substr($contactEmail, 0, 128),
            'industry_id' => $industryId,
            'status' => $status ? 1 : 0,
        ];
    }

    /**
     * 仅创建企业（不创建管理用户）
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        self::ensureSchema();
        $payload = self::normalizePayload($data);
        $now = date('Y-m-d H:i:s');
        return (int)DB::table('companies')->insert([
            'name' => $payload['name'],
            'contact_name' => $payload['contact_name'],
            'contact_phone' => $payload['contact_phone'],
            'contact_email' => $payload['contact_email'],
            'industry_id' => $payload['industry_id'],
            'status' => $payload['status'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        self::ensureSchema();
        if ($id <= 0) {
            throw new \InvalidArgumentException('无效的企业 ID');
        }
        $exist = DB::table('companies')->where('id', $id)->first();
        if (!$exist) {
            throw new \RuntimeException('企业不存在');
        }

        $payload = self::normalizePayload($data, $exist);
        DB::table('companies')->where('id', $id)->update([
            'name' => $payload['name'],
            'contact_name' => $payload['contact_name'],
            'contact_phone' => $payload['contact_phone'],
            'contact_email' => $payload['contact_email'],
            'industry_id' => $payload['industry_id'],
            'status' => $payload['status'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function delete(int $id): void
    {
        self::ensureSchema();
        if ($id <= 0) {
            throw new \InvalidArgumentException('无效的企业 ID');
        }
        $exist = DB::table('companies')->where('id', $id)->first();
        if (!$exist) {
            throw new \RuntimeException('企业不存在');
        }
        $userCount = (int)(DB::selectOne(
            'SELECT COUNT(*) AS c FROM company_users WHERE company_id=?',
            [$id]
        )['c'] ?? 0);
        if ($userCount > 0) {
            throw new \InvalidArgumentException("该企业仍绑定 {$userCount} 个管理用户，请先解除绑定或删除用户后再删除企业");
        }
        DB::table('companies')->where('id', $id)->delete();
    }

    /**
     * 创建企业 + 管理用户 + 企业用户绑定（三表写入，事务）
     *
     * @param array<string,mixed> $userData username, password_hash, nickname, role_id, status
     * @param array<string,mixed> $companyData name, contact_name, contact_phone, contact_email, industry_id
     * @return array{userId:int,companyId:int}
     */
    public static function createWithUser(array $userData, array $companyData): array
    {
        self::ensureSchema();
        IndustryDataService::ensureSchema();

        $payload = self::normalizePayload($companyData);

        return DB::transaction(function () use ($userData, $payload) {
            $now = date('Y-m-d H:i:s');
            $companyId = (int)DB::table('companies')->insert([
                'name' => $payload['name'],
                'contact_name' => $payload['contact_name'],
                'contact_phone' => $payload['contact_phone'],
                'contact_email' => $payload['contact_email'],
                'industry_id' => $payload['industry_id'],
                'status' => $payload['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $userId = (int)DB::table('admin_users')->insert([
                'username' => $userData['username'],
                'password_hash' => $userData['password_hash'],
                'nickname' => $userData['nickname'],
                'role_id' => (int)$userData['role_id'],
                'company_id' => $companyId,
                'status' => (int)$userData['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('company_users')->insert([
                'company_id' => $companyId,
                'user_id' => $userId,
                'is_primary' => 1,
                'created_at' => $now,
            ]);

            return ['userId' => $userId, 'companyId' => $companyId];
        });
    }

    public static function detachUser(int $userId): void
    {
        self::bindCompanies($userId, []);
    }

    /** @return list<int> */
    public static function companyIdsOfUser(int $userId): array
    {
        self::ensureSchema();
        if ($userId <= 0) {
            return [];
        }
        try {
            $rows = DB::select(
                'SELECT company_id FROM company_users WHERE user_id=? ORDER BY is_primary DESC, company_id ASC',
                [$userId]
            ) ?: [];
            return array_values(array_filter(array_map(static fn($r) => (int)($r['company_id'] ?? 0), $rows)));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @param list<int> $userIds @return array<int,list<int>> */
    public static function companyIdsMapForUsers(array $userIds): array
    {
        self::ensureSchema();
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $rows = DB::select(
            "SELECT user_id, company_id, is_primary FROM company_users WHERE user_id IN ({$ph}) ORDER BY is_primary DESC, company_id ASC",
            $userIds
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            $cid = (int)($row['company_id'] ?? 0);
            if ($uid <= 0 || $cid <= 0) {
                continue;
            }
            if (!isset($out[$uid])) {
                $out[$uid] = [];
            }
            $out[$uid][] = $cid;
        }
        return $out;
    }

    /** 绑定用户与多个企业（首个为主企业，同步 admin_users.company_id） */
    public static function bindCompanies(int $userId, array $companyIds): void
    {
        self::ensureSchema();
        if ($userId <= 0) {
            return;
        }

        $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));
        try {
            DB::query('DELETE FROM company_users WHERE user_id=?', [$userId]);
        } catch (\Throwable $e) {
        }

        if (!$companyIds) {
            try {
                DB::table('admin_users')->where('id', $userId)->update([
                    'company_id' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
            }
            return;
        }

        foreach ($companyIds as $companyId) {
            $company = DB::table('companies')->where('id', $companyId)->first();
            if (!$company) {
                throw new \InvalidArgumentException('所选企业不存在：#' . $companyId);
            }
        }

        $now = date('Y-m-d H:i:s');
        $primaryId = $companyIds[0];
        DB::table('admin_users')->where('id', $userId)->update([
            'company_id' => $primaryId,
            'updated_at' => $now,
        ]);
        foreach ($companyIds as $i => $companyId) {
            DB::table('company_users')->insert([
                'company_id' => $companyId,
                'user_id' => $userId,
                'is_primary' => $i === 0 ? 1 : 0,
                'created_at' => $now,
            ]);
        }
    }

    /** 追加用户与企业关联（不覆盖已有绑定） */
    public static function linkUserToCompany(int $userId, int $companyId, bool $isPrimary = false): void
    {
        self::ensureSchema();
        if ($userId <= 0 || $companyId <= 0) {
            return;
        }
        $company = DB::table('companies')->where('id', $companyId)->first();
        if (!$company) {
            throw new \InvalidArgumentException('所选企业不存在：#' . $companyId);
        }
        $exist = DB::selectOne(
            'SELECT 1 FROM company_users WHERE company_id=? AND user_id=? LIMIT 1',
            [$companyId, $userId]
        );
        if ($exist) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        DB::table('company_users')->insert([
            'company_id' => $companyId,
            'user_id' => $userId,
            'is_primary' => $isPrimary ? 1 : 0,
            'created_at' => $now,
        ]);
        $user = DB::table('admin_users')->where('id', $userId)->first();
        if ($user && empty($user['company_id'])) {
            DB::table('admin_users')->where('id', $userId)->update([
                'company_id' => $companyId,
                'updated_at' => $now,
            ]);
        }
    }

    /** 绑定/解绑用户与单个企业（兼容旧逻辑） */
    public static function bindUser(int $userId, int $companyId, bool $isPrimary = true): void
    {
        if ($companyId <= 0) {
            self::bindCompanies($userId, []);
            return;
        }
        self::bindCompanies($userId, [$companyId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function mapByIds(array $ids): array
    {
        self::ensureSchema();
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select("SELECT * FROM companies WHERE id IN ({$ph})", $ids);
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['id']] = self::mapRow($row);
        }
        return $out;
    }
}
