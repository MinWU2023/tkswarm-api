<?php
declare(strict_types=1);

namespace App\Services\PlanTask;

use App\Core\Database\DB;
use App\Services\CompanyService;

/**
 * 拉取分析邮件收件人：账号直接绑定管理用户 + 绑定企业下的企业用户邮箱，去重。
 */
class PlanTaskRecipientResolver
{
    public static function ensureAdminEmailColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $cols = array_column(DB::select("SHOW COLUMNS FROM admin_users LIKE 'email'") ?: [], 'Field');
            if (!$cols) {
                DB::query(
                    "ALTER TABLE `admin_users`
                     ADD COLUMN `email` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '通知邮箱' AFTER `nickname`"
                );
                try {
                    DB::query('ALTER TABLE `admin_users` ADD KEY `idx_admin_users_email` (`email`)');
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * @return array{
     *   emails: list<string>,
     *   recipients: list<array{email:string,userId:int,username:string,nickname:string,source:string}>,
     *   account: array<string,mixed>|null,
     *   ownerId: int,
     *   companyId: int
     * }
     */
    public static function resolveForAccount(int $accountId): array
    {
        self::ensureAdminEmailColumn();
        CompanyService::ensureSchema();

        $empty = [
            'emails' => [],
            'recipients' => [],
            'account' => null,
            'ownerId' => 0,
            'companyId' => 0,
        ];
        if ($accountId <= 0) {
            return $empty;
        }

        $account = DB::selectOne(
            'SELECT id, username, nickname, owner_id, company_id FROM accounts WHERE id=? LIMIT 1',
            [$accountId]
        );
        if (!$account) {
            return $empty;
        }

        $ownerId = (int)($account['owner_id'] ?? 0);
        $companyId = (int)($account['company_id'] ?? 0);
        /** @var array<string,array{email:string,userId:int,username:string,nickname:string,source:string}> $map */
        $map = [];

        if ($ownerId > 0) {
            $owner = DB::selectOne(
                'SELECT id, username, nickname, email, status FROM admin_users WHERE id=? LIMIT 1',
                [$ownerId]
            );
            self::pushUser($map, $owner, 'owner');
        }

        if ($companyId > 0) {
            $rows = DB::select(
                "SELECT u.id, u.username, u.nickname, u.email, u.status
                 FROM company_users cu
                 INNER JOIN admin_users u ON u.id = cu.user_id
                 WHERE cu.company_id=? AND u.status=1",
                [$companyId]
            ) ?: [];
            foreach ($rows as $row) {
                self::pushUser($map, $row, 'company_user');
            }
        }

        $recipients = array_values($map);
        $emails = array_values(array_map(static fn ($r) => $r['email'], $recipients));

        return [
            'emails' => $emails,
            'recipients' => $recipients,
            'account' => $account,
            'ownerId' => $ownerId,
            'companyId' => $companyId,
        ];
    }

    /**
     * @param array<string,array{email:string,userId:int,username:string,nickname:string,source:string}> $map
     * @param array<string,mixed>|null $user
     */
    private static function pushUser(array &$map, ?array $user, string $source): void
    {
        if (!$user) {
            return;
        }
        if ((int)($user['status'] ?? 1) !== 1) {
            return;
        }
        $email = strtolower(trim((string)($user['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (isset($map[$email])) {
            // 保留 owner 优先标记
            if ($source === 'owner') {
                $map[$email]['source'] = 'owner';
            }
            return;
        }
        $map[$email] = [
            'email' => $email,
            'userId' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'nickname' => (string)($user['nickname'] ?? ''),
            'source' => $source,
        ];
    }
}
