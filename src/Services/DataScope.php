<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;
use App\Core\Request;
use App\Core\Response;

/**
 * 管理用户数据壁垒：
 * - 超管：全部账号
 * - 运营管理：运营专员/企业客户已分配账号 + 公海（无归属/无企业/无行业）
 * - 运营专员：本人 + 关联企业下企业客户账号 + 公共池（未绑用户、未绑企业、但已绑行业）
 * - 企业客户：本人 owner_id + 关联企业 company_id 的账号
 * - 切换身份（Acting-As）：按目标角色的可见范围（含公海，企业客户除外）
 * - 账号/代理/环境创建·导入：超管/运营管理不绑定 owner/企业/行业；企业客户绑定本人+企业+行业；运营专员禁止创建/导入
 * - 运营专员可编辑账号，不可创建/导入账号、代理、环境
 * - 代理列表可见：超管/运营管理见未关联；运营专员仅见「自己关联账号已绑定」的代理；企业客户仅见本人归属
 * - 代理可删除：超管/运营管理仅未关联；企业客户仅本人；运营专员不可删
 * - 代理可分配：仅超管/运营管理；运营专员与企业客户不可分配/认领
 * - 代理可导入：运营专员不可；其余可维护代理的角色需具备 proxy.import
 * - 代理分组管理：运营专员不可新建/编辑/删除代理分组
 * - 浏览器环境可见：与账号列表范围一致（绑定账号须在当前用户可见账号内）；编辑/删除仍按代理 owner 规则
 * - 业务分组（账号/代理/消息）：各登录身份仅见/管本人 owner_id 创建的分组
 * - 企业数据：超管/运营管理见全部；运营专员仅见 company_users 关联企业；创建时运营专员自动关联自身，超管/运营管理不关联
 * - 素材库：固定按登录用户 owner_id 隔离（不受身份切换影响）；列表/弹框/Node 取素材均仅本人
 */
class DataScope
{
    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static ?int $actingAsId = null;
    private static bool $booted = false;

    private const OWNER_TABLES = [
        'accounts',
        'groups',
        'proxies',
        'materials',
        'tasks',
        'agents',
        'message_templates',
        'uid_targets',
        'chat_users',
        'chat_records',
        'chat_friends',
        'chat_messages',
        'client_versions',
        'ai_platform_configs',
        'ai_prompt_templates',
        'ai_call_logs',
        'ai_leads',
        'account_work_batches',
        'account_work_items',
    ];

    public static function bootFromGlobals(?Request $request = null): ?Response
    {
        self::$user = $GLOBALS['auth_user'] ?? null;
        self::$actingAsId = null;
        self::$booted = true;
        self::ensureColumns();
        self::ensureChildrenTable();

        if (!$request || !self::$user) {
            return null;
        }

        $raw = $request->getHeader('x-acting-as') ?? $request->input('actingAs') ?? $request->input('acting_as');
        if ($raw === null || $raw === '' || $raw === '0' || $raw === 'self') {
            return null;
        }

        if (self::isScopeSwitchDisabled()) {
            return Response::error('当前角色不支持切换身份', 403);
        }

        $targetId = (int)$raw;
        if ($targetId <= 0) {
            return Response::error('无效的切换身份', 400);
        }

        $selfId = (int)(self::$user['id'] ?? 0);
        if ($targetId === $selfId) {
            return null;
        }

        if (!self::isLoginSuper()) {
            if (self::isLoginOpsAdmin()) {
                if (self::isSwitchBlockedTarget($targetId)) {
                    return Response::error('无权切换到该管理员', 403);
                }
            } elseif (!self::isDirectChild($selfId, $targetId)) {
                return Response::error('无权切换到该子账号', 403);
            }
        }

        $target = DB::table('admin_users')->where('id', $targetId)->first();
        if (!$target || (int)($target['status'] ?? 0) !== 1) {
            return Response::error('目标管理员不存在或已停用', 404);
        }

        self::$actingAsId = $targetId;
        $GLOBALS['acting_as_user'] = $target;
        return null;
    }

    /** 登录账号（不受 Acting-As 影响） */
    public static function user(): ?array
    {
        if (!self::$booted) {
            self::$user = $GLOBALS['auth_user'] ?? null;
            self::$booted = true;
        }
        return self::$user;
    }

    public static function loginUser(): ?array
    {
        return self::user();
    }

    /** 当前有效身份：切换中为目标账号，否则为登录账号 */
    public static function effectiveUser(): ?array
    {
        if (self::isActing()) {
            $acting = $GLOBALS['acting_as_user'] ?? null;
            if (is_array($acting) && (int)($acting['id'] ?? 0) > 0) {
                return $acting;
            }
        }
        return self::user();
    }

    public static function effectiveUserId(): int
    {
        return (int)(self::effectiveUser()['id'] ?? 0);
    }

    public static function isLoginSuper(): bool
    {
        $u = self::loginUser();
        if (!$u) {
            return false;
        }
        if ((int)($u['role_id'] ?? 0) === 1) {
            return true;
        }
        if ((int)($u['id'] ?? 0) === 0 && ($u['username'] ?? '') === 'guest') {
            return true;
        }
        return false;
    }

    public static function isLoginOpsAdmin(): bool
    {
        if (self::isLoginSuper()) {
            return false;
        }
        $u = self::loginUser();
        return $u && str_contains(self::roleNameOfUser($u), '运营管理');
    }

    /** 业务权限/数据范围：按当前有效身份（含切换后） */
    public static function isSuper(): bool
    {
        $u = self::effectiveUser();
        if (!$u) {
            return false;
        }
        if ((int)($u['role_id'] ?? 0) === 1) {
            return true;
        }
        if ((int)($u['id'] ?? 0) === 0 && ($u['username'] ?? '') === 'guest') {
            return true;
        }
        return false;
    }

    public static function roleNameOfUser(?array $user): string
    {
        if (!$user) {
            return '';
        }
        $roleId = (int)($user['role_id'] ?? 0);
        if ($roleId <= 0) {
            return '';
        }
        $role = DB::table('admin_roles')->where('id', $roleId)->first();
        return (string)($role['name'] ?? '');
    }

    /** 运营管理（有效身份） */
    public static function isOpsAdmin(): bool
    {
        if (self::isSuper()) {
            return false;
        }
        $u = self::effectiveUser();
        return $u && str_contains(self::roleNameOfUser($u), '运营管理');
    }

    /** 企业客户 / 运营专员：登录账号不可切换身份 */
    public static function isScopeSwitchDisabled(): bool
    {
        $u = self::loginUser();
        if (!$u) {
            return true;
        }
        if (self::isLoginSuper() || self::isLoginOpsAdmin()) {
            return false;
        }
        $name = self::roleNameOfUser($u);
        return str_contains($name, '企业客户') || str_contains($name, '运营专员');
    }

    /** 运营管理不可切换到的目标（超管、其他运营管理） */
    public static function isSwitchBlockedTarget(int $targetUserId): bool
    {
        $target = DB::table('admin_users')->where('id', $targetUserId)->first();
        if (!$target) {
            return true;
        }
        if ((int)($target['role_id'] ?? 0) === 1) {
            return true;
        }
        $name = self::roleNameOfUser($target);
        return str_contains($name, '超级管理员') || str_contains($name, '运营管理');
    }

    /**
     * 超管 / 运营管理可切换到的用户列表
     *
     * @return list<array{id:int,username:string,nickname:string,role_id:int,role_name:string}>
     */
    public static function switchableUsers(string $keyword = ''): array
    {
        $u = self::loginUser();
        if (!$u) {
            return [];
        }
        $selfId = (int)($u['id'] ?? 0);
        $isSuper = self::isLoginSuper();
        $isOpsAdmin = self::isLoginOpsAdmin();
        if (!$isSuper && !$isOpsAdmin) {
            return [];
        }

        $sql = 'SELECT u.id, u.username, u.nickname, u.role_id, u.status
                FROM admin_users u
                WHERE u.id != ? AND u.status = 1';
        $params = [$selfId];
        if ($keyword !== '') {
            $sql .= ' AND (u.username LIKE ? OR u.nickname LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        $sql .= ' ORDER BY u.id ASC LIMIT 200';
        $rows = DB::select($sql, $params) ?: [];

        $roles = DB::table('admin_roles')->get();
        $roleMap = [];
        foreach ($roles as $r) {
            $roleMap[(int)$r['id']] = (string)($r['name'] ?? '');
        }

        $out = [];
        foreach ($rows as $row) {
            $uid = (int)($row['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if ($isOpsAdmin && !$isSuper && self::isSwitchBlockedTarget($uid)) {
                continue;
            }
            $rid = (int)($row['role_id'] ?? 0);
            $out[] = [
                'id' => $uid,
                'username' => (string)($row['username'] ?? ''),
                'nickname' => (string)($row['nickname'] ?? ''),
                'role_id' => $rid,
                'role_name' => $roleMap[$rid] ?? '',
            ];
        }
        return $out;
    }

    public static function isActing(): bool
    {
        return self::$actingAsId !== null && self::$actingAsId > 0;
    }

    public static function actingAsId(): ?int
    {
        return self::$actingAsId;
    }

    /** 写入归属：本人或切换中的子账号 */
    public static function writeOwnerId(): int
    {
        if (self::isActing()) {
            return (int)self::$actingAsId;
        }
        $u = self::user();
        $id = (int)($u['id'] ?? 0);
        return $id > 0 ? $id : 1;
    }

    /**
     * 列表过滤用的 owner_id；null = 不过滤（仅 auth 关闭等无用户场景）
     */
    public static function filterOwnerId(): ?int
    {
        if (self::isActing()) {
            return (int)self::$actingAsId;
        }
        $u = self::user();
        $id = (int)($u['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @return array{0:string,1:list<mixed>} [sqlFragment starting with AND or empty, params] */
    public static function ownerSql(string $column = 'owner_id'): array
    {
        $oid = self::filterOwnerId();
        if ($oid === null) {
            return ['', []];
        }
        return [" AND {$column} = ?", [$oid]];
    }

    /**
     * 可见归属：切换中 = 仅该身份；否则 = 本人 + 直属下级（环境页「自己或下级」）。
     *
     * @return list<int>
     */
    public static function visibleOwnerIds(): array
    {
        if (self::isActing()) {
            return [(int)self::$actingAsId];
        }
        $u = self::user();
        $selfId = (int)($u['id'] ?? 0);
        if ($selfId <= 0) {
            return [0];
        }
        return array_values(array_unique(array_merge([$selfId], self::childIdsOf($selfId))));
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    public static function visibleOwnerSql(string $column = 'owner_id'): array
    {
        $ids = self::visibleOwnerIds();
        if (count($ids) === 1) {
            return [" AND {$column} = ?", [$ids[0]]];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return [" AND {$column} IN ({$placeholders})", $ids];
    }

    /**
     * 任务汇总列表：batch.owner 或关联账号 owner 在可见范围内即可（超管未切换身份时不限制）。
     *
     * @return array{0:string,1:list<mixed>}
     */
    public static function workBatchListSql(string $batchAlias = 'b'): array
    {
        if (!self::effectiveUser()) {
            return ['', []];
        }
        if (self::isSuper() && !self::isActing()) {
            return ['', []];
        }
        $ids = self::visibleOwnerIds();
        if (!$ids) {
            return [' AND 1=0', []];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return [
            " AND ({$batchAlias}.owner_id IN ({$placeholders}) OR EXISTS (
                SELECT 1 FROM accounts awb WHERE awb.id={$batchAlias}.account_id AND awb.owner_id IN ({$placeholders})
            ))",
            array_merge($ids, $ids),
        ];
    }

    public static function applyOwnerFilter(string $column, array &$filters, array &$params): void
    {
        $oid = self::filterOwnerId();
        if ($oid === null) {
            return;
        }
        $filters[] = "{$column} = ?";
        $params[] = $oid;
    }

    /** 素材库归属：登录用户（不受 Acting-As 影响） */
    public static function materialOwnerId(): int
    {
        $id = (int)(self::loginUser()['id'] ?? 0);
        return $id > 0 ? $id : 0;
    }

    public static function writeMaterialOwnerId(): int
    {
        $id = self::materialOwnerId();
        return $id > 0 ? $id : 1;
    }

    public static function applyMaterialOwnerFilter(string $column, array &$filters, array &$params): void
    {
        $oid = self::materialOwnerId();
        if ($oid <= 0) {
            $filters[] = '1=0';
            return;
        }
        $filters[] = "{$column} = ?";
        $params[] = $oid;
    }

    /** @return array{0:string,1:list<mixed>} */
    public static function materialOwnerSql(string $column = 'owner_id'): array
    {
        $oid = self::materialOwnerId();
        if ($oid <= 0) {
            return [' AND 1=0', []];
        }
        return [" AND {$column} = ?", [$oid]];
    }

    public static function ownsMaterialRow(?array $row, string $field = 'owner_id'): bool
    {
        if (!$row) {
            return false;
        }
        $oid = self::materialOwnerId();
        if ($oid <= 0) {
            return false;
        }
        return (int)($row[$field] ?? 0) === $oid;
    }

    public static function assertMaterialOwns(?array $row, string $field = 'owner_id'): ?Response
    {
        if (!$row) {
            return Response::error('素材不存在', 404);
        }
        if (!self::ownsMaterialRow($row, $field)) {
            return Response::error('无权访问该素材', 403);
        }
        return null;
    }

    /**
     * 素材只读可见：上传者本人 + 有效身份 owner + 发布账号在可见范围内 + 超管未切换。
     *
     * @param array<string,mixed>|null $row
     */
    public static function materialVisibleRow(?array $row): bool
    {
        if (!$row) {
            return false;
        }
        if (!self::effectiveUser()) {
            return true;
        }
        if (self::isSuper() && !self::isActing()) {
            return true;
        }
        if (self::ownsMaterialRow($row)) {
            return true;
        }
        if (self::ownsRow($row)) {
            return true;
        }
        $publishAccountId = (int)($row['publish_account_id'] ?? 0);
        if ($publishAccountId > 0) {
            $acc = DB::selectOne('SELECT id, owner_id, company_id FROM accounts WHERE id=?', [$publishAccountId]);
            if (self::accountOwnsRow($acc)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed>|null $row */
    public static function assertMaterialVisible(?array $row, string $field = 'owner_id'): ?Response
    {
        if (!$row) {
            return Response::error('素材不存在', 404);
        }
        if (!self::materialVisibleRow($row)) {
            return Response::error('无权访问该素材', 403);
        }
        return null;
    }

    /**
     * 素材列表/拉取视频数据选片：本人上传 + 有效身份可见 owner + 发布在「账号列表可见」的账号上。
     * 注意：公海账号 owner_id 为空时，旧逻辑仅判断 ama.owner_id IN (...) 会漏掉已发布/线上视频。
     *
     * @return array{0:string,1:list<mixed>}
     */
    public static function materialListSql(string $materialAlias = 'm'): array
    {
        if (!self::effectiveUser()) {
            return ['', []];
        }
        if (self::isSuper() && !self::isActing()) {
            return ['', []];
        }
        $loginOwner = self::materialOwnerId();
        $visibleOwners = self::visibleOwnerIds();
        if (!$visibleOwners) {
            return [' AND 1=0', []];
        }
        $ph = implode(',', array_fill(0, count($visibleOwners), '?'));
        // 与账号列表同一套范围（运营公海 / 专员公共池 / 企业客户等），避免 owner_id IS NULL 的账号上素材全不可见
        [$accScopeSql, $accScopeParams] = self::accountListScopeSql('ama.owner_id');
        $accExists = "EXISTS (
                SELECT 1 FROM accounts ama
                WHERE ama.id={$materialAlias}.publish_account_id" . $accScopeSql . '
            )';
        return [
            " AND ({$materialAlias}.owner_id = ? OR {$materialAlias}.owner_id IN ({$ph}) OR {$accExists})",
            array_merge([$loginOwner], $visibleOwners, $accScopeParams),
        ];
    }

    /** 分组创建者 id（有效身份本人；各角色仅见/管自己创建的分组） */
    public static function groupCreatorId(): ?int
    {
        $id = (int)(self::effectiveUser()['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @return array{0:string,1:list<mixed>} */
    public static function groupOwnerSql(string $column = 'owner_id'): array
    {
        $cid = self::groupCreatorId();
        if ($cid === null) {
            return [' AND 1=0', []];
        }
        return [" AND {$column} = ?", [$cid]];
    }

    public static function applyGroupCreatorScope(array &$filters, array &$params, string $column = 'owner_id'): void
    {
        $cid = self::groupCreatorId();
        if ($cid === null) {
            $filters[] = '1=0';
            return;
        }
        $filters[] = "{$column} = ?";
        $params[] = $cid;
    }

    public static function stampGroupOwner(array &$row): void
    {
        $cid = self::groupCreatorId();
        if ($cid !== null) {
            $row['owner_id'] = $cid;
        }
    }

    public static function assertGroupOwns(?array $row, string $field = 'owner_id'): ?Response
    {
        if (!$row) {
            return Response::error('资源不存在', 404);
        }
        $cid = self::groupCreatorId();
        if ($cid === null || (int)($row[$field] ?? 0) !== $cid) {
            return Response::error('无权访问该资源', 403);
        }
        return null;
    }

    public static function isEnterpriseCustomer(): bool
    {
        $u = self::effectiveUser();
        return $u && str_contains(self::roleNameOfUser($u), '企业客户');
    }

    public static function isOperator(): bool
    {
        if (self::isSuper() || self::isOpsAdmin()) {
            return false;
        }
        $u = self::effectiveUser();
        return $u && str_contains(self::roleNameOfUser($u), '运营专员');
    }

    public static function canUseAccountAdvancedFilter(): bool
    {
        return !self::isEnterpriseCustomer();
    }

    /** 账号列表/任务选号：运营专员用户筛选（超管、运营管理） */
    public static function canUseAccountOwnerFilter(): bool
    {
        return self::isSuper() || self::isOpsAdmin();
    }

    /** 账号列表/任务选号：四级行业筛选（非企业客户） */
    public static function canUseAccountIndustryFilter(): bool
    {
        return !self::isEnterpriseCustomer();
    }

    /** 企业客户不可编辑账号；运营专员可编辑（不可创建/导入） */
    public static function canEditAccounts(): bool
    {
        return !self::isEnterpriseCustomer();
    }

    /** 运营专员不可创建账号 */
    public static function canCreateAccounts(): bool
    {
        return !self::isOperator();
    }

    /** 运营专员不可导入账号 */
    public static function canImportAccounts(): bool
    {
        return !self::isOperator();
    }

    /** 运营专员不可导出账号 */
    public static function canExportAccounts(): bool
    {
        return !self::isOperator();
    }

    /** 运营专员不可批量修改账号 */
    public static function canBatchModifyAccounts(): bool
    {
        return !self::isOperator() && self::canEditAccounts();
    }

    /** 运营专员不可创建/绑定浏览器环境 */
    public static function canCreateBrowserEnvs(): bool
    {
        if (self::isOperator()) {
            return false;
        }
        return self::isSuper() || self::isOpsAdmin() || self::isEnterpriseCustomer();
    }

    public static function canAssignAccountOwners(): bool
    {
        if (self::isSuper() || self::isOpsAdmin()) {
            return true;
        }
        $u = self::effectiveUser();
        $uid = (int)($u['id'] ?? 0);
        return $uid > 0 && count(self::childIdsOf($uid)) > 0;
    }

    /** 运营专员不可维护代理；超管/运营管理/企业客户可 */
    public static function canEditProxies(): bool
    {
        if (self::isOperator()) {
            return false;
        }
        return self::isSuper() || self::isOpsAdmin() || self::isEnterpriseCustomer();
    }

    /** 仅超管/运营管理可将未关联代理分配给运营专员或企业客户 */
    public static function canAssignProxyOwners(): bool
    {
        return self::isSuper() || self::isOpsAdmin();
    }

    /** 仅登录身份为超管/运营管理可将账号分组（及组内账号）分配给管理用户 */
    public static function canAssignGroupOwners(): bool
    {
        return self::isLoginSuper() || self::isLoginOpsAdmin();
    }

    /** 运营专员不可创建/导入代理 */
    public static function canCreateProxies(): bool
    {
        return self::canEditProxies();
    }

    /** 运营专员不可导入；其余可维护代理的角色在路由层另校验 proxy.import */
    public static function canImportProxies(): bool
    {
        return self::canCreateProxies();
    }

    /** 运营专员不可维护代理分组（新建/编辑/删除） */
    public static function canManageProxyGroups(): bool
    {
        return !self::isOperator();
    }

    /** 新建代理入库 owner_id：当前有效身份为企业客户时写入其 id；其他身份不绑定 */
    public static function proxyInsertOwnerId(): ?int
    {
        if (!self::isEnterpriseCustomer()) {
            return null;
        }
        $id = (int)(self::effectiveUser()['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * 企业客户新建账号时的归属绑定；其他有效身份返回 null（不绑定）。
     * 超管/运营管理切换到企业客户时，绑定目标企业客户 id。
     *
     * @return array{owner_id:int,company_id:?int,industry_id:?int}|null
     */
    public static function accountInsertBindings(): ?array
    {
        if (!self::isEnterpriseCustomer()) {
            return null;
        }
        $user = self::effectiveUser();
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            return null;
        }
        CompanyService::ensureSchema();
        $companyIds = CompanyService::companyIdsOfUser($uid);
        $companyId = $companyIds[0] ?? (int)($user['company_id'] ?? 0);
        return [
            'owner_id' => $uid,
            'company_id' => $companyId > 0 ? $companyId : null,
            'industry_id' => null,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function applyAccountInsertBindings(array &$row): void
    {
        self::ensureAccountBindingColumns();
        $bindings = self::accountInsertBindings();
        if ($bindings !== null) {
            $row['owner_id'] = $bindings['owner_id'];
            $row['company_id'] = $bindings['company_id'];
            $row['industry_id'] = $bindings['industry_id'];
            return;
        }
        $row['owner_id'] = null;
        $row['company_id'] = null;
        $row['industry_id'] = null;
    }

    /** @return list<array{id:int,username:string,nickname:string,roleName:string,roleType:string,companies:list<array{id:int,name:string}>}> */
    public static function proxyAssignTargets(): array
    {
        if (!self::canAssignProxyOwners()) {
            return [];
        }
        if (self::isEnterpriseCustomer()) {
            return [];
        }
        return self::accountAssignTargets();
    }

    /** 代理列表/批量操作的数据范围 SQL（AND 开头） */
    public static function proxyListScopeSql(string $column = 'owner_id'): array
    {
        if (self::isSuper() || self::isOpsAdmin()) {
            return [' AND ' . self::unassignedProxyOwnerExpr($column), []];
        }
        if (self::isOperator()) {
            return self::operatorProxyScopeSql($column);
        }
        if (self::isEnterpriseCustomer()) {
            $id = (int)(self::effectiveUser()['id'] ?? 0);
            return [" AND {$column} = ?", [$id > 0 ? $id : 0]];
        }
        $oid = self::filterOwnerId();
        if ($oid === null) {
            return ['', []];
        }
        return [" AND {$column} = ?", [$oid]];
    }

    public static function applyProxyListScope(string $column, array &$filters, array &$params): void
    {
        [$sql, $scopeParams] = self::proxyListScopeSql($column);
        if ($sql === '') {
            return;
        }
        $expr = ltrim($sql);
        if (str_starts_with($expr, 'AND ')) {
            $expr = substr($expr, 4);
        }
        $filters[] = $expr;
        foreach ($scopeParams as $p) {
            $params[] = $p;
        }
    }

    /**
     * 代理删除类操作的数据范围（高级删除、批量删除等）。
     * 与列表「可见」范围不同：企业客户仅能删本人代理，不能删公共池；无有效范围时 fail-closed。
     *
     * @return array{0:string,1:list<mixed>}
     */
    public static function proxyDeletableScopeSql(string $column = 'owner_id'): array
    {
        if (self::isOperator()) {
            return [' AND 1=0', []];
        }
        if (self::isSuper() || self::isOpsAdmin()) {
            return [' AND ' . self::unassignedProxyOwnerExpr($column), []];
        }
        if (self::isEnterpriseCustomer()) {
            $id = (int)(self::effectiveUser()['id'] ?? 0);
            return [" AND {$column} = ?", [$id > 0 ? $id : 0]];
        }
        $oid = self::filterOwnerId();
        if ($oid === null || $oid <= 0) {
            return [' AND 1=0', []];
        }
        return [" AND {$column} = ?", [$oid]];
    }

    /** 将可删除范围写入 WHERE；返回 false 表示当前身份不可执行删除（避免退化为全库删除）。 */
    public static function applyProxyDeletableScope(string $column, array &$filters, array &$params): bool
    {
        [$sql, $scopeParams] = self::proxyDeletableScopeSql($column);
        if ($sql === '') {
            return false;
        }
        $expr = ltrim($sql);
        if (str_starts_with($expr, 'AND ')) {
            $expr = substr($expr, 4);
        }
        if ($expr === '1=0') {
            return false;
        }
        $filters[] = $expr;
        foreach ($scopeParams as $p) {
            $params[] = $p;
        }
        return true;
    }

    public static function isProxyOwnerVisible(int $ownerId): bool
    {
        if (self::isSuper() || self::isOpsAdmin()) {
            return $ownerId <= 0;
        }
        if (self::isOperator()) {
            // 运营专员列表已改为按「关联账号已绑定代理」过滤；
            // 此处仅用于按 ownerId 筛选时的权限预检（本人或关联企业客户）。
            $selfId = (int)(self::effectiveUser()['id'] ?? 0);
            if ($ownerId > 0 && $ownerId === $selfId) {
                return true;
            }
            if ($ownerId > 0) {
                return self::userHasScopedRole($ownerId, ['企业客户'], self::operatorCompanyIds());
            }
            return false;
        }
        if (self::isEnterpriseCustomer()) {
            return $ownerId === (int)(self::effectiveUser()['id'] ?? 0);
        }
        return $ownerId === (int)(self::effectiveUser()['id'] ?? 0);
    }

    /** 运营专员：代理是否被其关联账号绑定（单行访问校验） */
    public static function isProxyBoundToOperatorAccounts(int $proxyId): bool
    {
        if ($proxyId <= 0 || !self::isOperator()) {
            return false;
        }
        [$sql, $params] = self::operatorBoundProxyIdsSubquery();
        if ($sql === '') {
            return false;
        }
        $row = DB::selectOne("SELECT 1 AS ok FROM ({$sql}) t WHERE t.proxy_id = ? LIMIT 1", array_merge($params, [$proxyId]));
        return (bool)$row;
    }

    /**
     * 代理行是否可见（列表/详情/测速等）。
     * 运营专员按账号绑定关系判断，其余角色仍按 owner_id。
     *
     * @param array<string,mixed> $row
     */
    public static function isProxyRowVisible(array $row): bool
    {
        if (self::isOperator()) {
            return self::isProxyBoundToOperatorAccounts((int)($row['id'] ?? 0));
        }
        return self::isProxyOwnerVisible((int)($row['owner_id'] ?? 0));
    }

    /** 是否可编辑该代理行（运营专员只读） */
    public static function canEditProxyRow(?array $row, string $field = 'owner_id'): bool
    {
        if (!self::canEditProxies() || !$row) {
            return false;
        }
        $ownerId = (int)($row[$field] ?? 0);
        if (self::isSuper() || self::isOpsAdmin()) {
            return $ownerId <= 0;
        }
        if (self::isEnterpriseCustomer()) {
            return $ownerId === (int)(self::effectiveUser()['id'] ?? 0);
        }
        return false;
    }

    /** 浏览器环境有效归属 SQL（与代理 owner_id 语义一致） */
    public static function browserEnvEffectiveOwnerSql(string $eAlias = 'e', string $aAlias = 'a'): string
    {
        return "COALESCE(NULLIF({$eAlias}.owner_user_id, 0), NULLIF({$aAlias}.owner_id, 0), 0)";
    }

    public static function applyBrowserEnvListScope(array &$filters, array &$params, string $eAlias = 'e', string $aAlias = 'a'): void
    {
        self::applyAccountListScope("{$aAlias}.owner_id", $filters, $params);
    }

    /**
     * 绑定环境时写入 owner_user_id：
     * - 企业客户创建：绑定本人
     * - 超管再绑并传入 override：用指定管理用户（见 AccountBrowserEnvService::bind）
     * - 其余默认 0（不占用具体用户额度归属，由本机浏览器 owner_id 另计）
     */
    public static function resolveBrowserEnvOwnerUserId(int $accountOwnerId): int
    {
        if (self::isEnterpriseCustomer()) {
            $uid = (int)(self::effectiveUser()['id'] ?? 0);
            return $uid > 0 ? $uid : 0;
        }
        return 0;
    }

    /**
     * 环境归属是否对当前身份「可见」——按账号归属语义，不套代理「超管只能看未分配」。
     * owner_user_id 仅额度/创建者归属；0 = 未指定（本机共用额度）。
     */
    public static function isBrowserEnvOwnerVisible(int $ownerUserId): bool
    {
        if ($ownerUserId <= 0) {
            return self::canEditBrowserEnvs() || self::isOperator() || self::isSuper() || self::isOpsAdmin();
        }
        return self::isAccountOwnerVisible($ownerUserId);
    }

    public static function canEditBrowserEnvs(): bool
    {
        return self::canCreateBrowserEnvs();
    }

    /**
     * 是否可解绑/绑定/删该浏览器环境行。
     * 环境本机共用：不套用代理「超管只能改未分配」；有创建/编辑环境权限即可管理可见环境。
     */
    public static function canEditBrowserEnvRow(?array $row, string $field = 'owner_user_id'): bool
    {
        if (!self::canEditBrowserEnvs() || !$row) {
            return false;
        }
        if (self::isSuper() || self::isOpsAdmin()) {
            return true;
        }
        if (self::isEnterpriseCustomer()) {
            $ownerId = (int)($row[$field] ?? 0);
            $selfId = (int)(self::effectiveUser()['id'] ?? 0);
            return $ownerId <= 0 || $ownerId === $selfId;
        }
        return false;
    }

    /** 超管/运营管理可删本机未绑定孤儿窗；已分配给他人（有效归属>0）的不可当孤儿删 */
    public static function canDeleteOrphanBrowserProfile(string $profileId): bool
    {
        if (!self::isSuper() && !self::isOpsAdmin()) {
            return false;
        }
        $profileId = trim($profileId);
        if ($profileId === '') {
            return false;
        }
        $row = DB::selectOne(
            'SELECT COALESCE(NULLIF(e.owner_user_id, 0), NULLIF(a.owner_id, 0), 0) AS eff_owner
             FROM account_browser_envs e
             INNER JOIN accounts a ON a.id = e.account_id
             WHERE e.browser_profile_id = ? LIMIT 1',
            [$profileId]
        );
        if (!$row) {
            return true;
        }
        return (int)($row['eff_owner'] ?? 0) <= 0;
    }

    /** 未关联管理用户的代理 */
    private static function unassignedProxyOwnerExpr(string $column): string
    {
        return "({$column} IS NULL OR {$column} = 0)";
    }

    /**
     * 运营专员代理列表：仅「自己关联账号」已绑定的代理（不含公海未绑定池）。
     * $column 形如 p.owner_id / owner_id，用于推导代理主键列 p.id / id。
     *
     * @return array{0:string,1:list<mixed>}
     */
    private static function operatorProxyScopeSql(string $column): array
    {
        $idColumn = preg_replace('/owner_id\b/', 'id', $column) ?: 'id';
        [$subSql, $params] = self::operatorBoundProxyIdsSubquery();
        if ($subSql === '') {
            return [' AND 1=0', []];
        }
        return [" AND {$idColumn} IN ({$subSql})", $params];
    }

    /**
     * 运营专员关联账号（本人 + 关联企业客户，不含公海）已绑定的 proxy_id 子查询。
     *
     * @return array{0:string,1:list<mixed>} SQL 子查询（无外层括号包裹的 SELECT）, 绑定参数
     */
    private static function operatorBoundProxyIdsSubquery(): array
    {
        $selfId = (int)(self::effectiveUser()['id'] ?? 0);
        if ($selfId <= 0) {
            return ['', []];
        }
        $companyIds = self::operatorCompanyIds();
        if (!$companyIds) {
            return [
                'SELECT DISTINCT a.proxy_id FROM accounts a
                 WHERE a.proxy_id IS NOT NULL AND a.proxy_id > 0 AND a.owner_id = ?',
                [$selfId],
            ];
        }
        $ph = implode(',', array_fill(0, count($companyIds), '?'));
        $customerSub = "SELECT u.id FROM admin_users u
            INNER JOIN admin_roles r ON r.id = u.role_id
            INNER JOIN company_users cu ON cu.user_id = u.id
            WHERE u.status = 1 AND r.name LIKE '%企业客户%' AND cu.company_id IN ({$ph})";
        return [
            "SELECT DISTINCT a.proxy_id FROM accounts a
             WHERE a.proxy_id IS NOT NULL AND a.proxy_id > 0
               AND (a.owner_id = ? OR a.owner_id IN ({$customerSub}))",
            array_merge([$selfId], $companyIds),
        ];
    }

    /** @return list<array{id:int,username:string,nickname:string,roleName:string,roleType:string,companies:list<array{id:int,name:string}>}> */
    public static function accountAssignTargets(): array
    {
        if (!self::canAssignAccountOwners()) {
            return [];
        }
        CompanyService::ensureSchema();
        if (self::isSuper() || self::isOpsAdmin()) {
            $rows = self::queryFilterUsers('', ['运营专员', '企业客户'], null);
        } else {
            $childIds = self::childIdsOf((int)(self::effectiveUser()['id'] ?? 0));
            if (!$childIds) {
                return [];
            }
            $rows = array_values(array_filter(
                self::queryFilterUsers('', ['运营专员', '企业客户'], null),
                static fn($r) => in_array((int)($r['id'] ?? 0), $childIds, true)
            ));
        }
        return self::mapAccountAssignTargets($rows);
    }

    /**
     * 分组分配用户候选：超管看全部启用用户；运营管理仍限运营专员/企业客户。
     *
     * @return list<array{id:int,username:string,nickname:string,roleName:string,roleType:string,companies:list<array{id:int,name:string}>}>
     */
    public static function groupAssignTargets(): array
    {
        if (!self::canAssignGroupOwners()) {
            return [];
        }
        CompanyService::ensureSchema();
        if (self::isLoginSuper()) {
            $rows = self::queryFilterUsers('', null, null, 2000);
        } elseif (self::isLoginOpsAdmin()) {
            $rows = self::queryFilterUsers('', ['运营专员', '企业客户'], null);
        } else {
            return [];
        }
        return self::mapAccountAssignTargets($rows);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function mapAccountAssignTargets(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $uid = (int)($row['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $roleName = (string)($row['role_name'] ?? '');
            $isEnterprise = str_contains($roleName, '企业客户');
            $companies = [];
            if ($isEnterprise) {
                $cids = CompanyService::companyIdsOfUser($uid);
                $cmap = CompanyService::mapByIds($cids);
                foreach ($cids as $cid) {
                    if (isset($cmap[$cid])) {
                        $companies[] = [
                            'id' => $cid,
                            'name' => (string)($cmap[$cid]['name'] ?? ''),
                        ];
                    }
                }
            }
            $roleType = 'other';
            if ($isEnterprise) {
                $roleType = 'enterprise';
            } elseif (str_contains($roleName, '运营专员')) {
                $roleType = 'operator';
            }
            $out[] = [
                'id' => $uid,
                'username' => (string)($row['username'] ?? ''),
                'nickname' => (string)($row['nickname'] ?? ''),
                'roleName' => $roleName,
                'roleType' => $roleType,
                'companies' => $companies,
            ];
        }
        return $out;
    }

    /** 账号列表/统计的数据范围 SQL（AND 开头） */
    public static function accountListScopeSql(string $column = 'owner_id'): array
    {
        if (self::isSuper()) {
            return ['', []];
        }
        if (self::isOpsAdmin()) {
            return [' AND ' . self::opsAdminAccountScopeExpr($column), []];
        }
        if (self::isOperator()) {
            return self::operatorAccountScopeSql($column);
        }
        if (self::isEnterpriseCustomer()) {
            return self::enterpriseAccountScopeSql($column);
        }
        $oid = self::filterOwnerId();
        if ($oid === null) {
            return ['', []];
        }
        return [" AND {$column} = ?", [$oid]];
    }

    public static function applyAccountListScope(string $column, array &$filters, array &$params): void
    {
        [$sql, $scopeParams] = self::accountListScopeSql($column);
        if ($sql === '') {
            return;
        }
        $expr = ltrim($sql);
        if (str_starts_with($expr, 'AND ')) {
            $expr = substr($expr, 4);
        }
        $filters[] = $expr;
        foreach ($scopeParams as $p) {
            $params[] = $p;
        }
    }

    /** @param array<string,mixed>|null $account */
    public static function isAccountRowVisible(?array $account): bool
    {
        if (!$account) {
            return false;
        }
        if (self::isOperator()) {
            return self::isOperatorAccountRowVisible($account);
        }
        if (!self::isEnterpriseCustomer()) {
            return self::isAccountOwnerVisible((int)($account['owner_id'] ?? 0));
        }
        $selfId = (int)(self::effectiveUser()['id'] ?? 0);
        $ownerId = (int)($account['owner_id'] ?? 0);
        if ($ownerId === $selfId) {
            return true;
        }
        $companyId = (int)($account['company_id'] ?? 0);
        return $companyId > 0 && in_array($companyId, self::enterpriseCompanyIds(), true);
    }

    public static function isAccountOwnerVisible(int $ownerId): bool
    {
        if ($ownerId <= 0) {
            return self::isSuper() || self::isOpsAdmin() || self::isOperator();
        }
        if (self::isSuper()) {
            return true;
        }
        if (self::isEnterpriseCustomer()) {
            return $ownerId === (int)(self::effectiveUser()['id'] ?? 0);
        }
        if (self::isOpsAdmin()) {
            return self::userHasScopedRole($ownerId, ['运营专员', '企业客户']) || self::isUnscopedOwnerId($ownerId);
        }
        if (self::isOperator()) {
            $selfId = (int)(self::effectiveUser()['id'] ?? 0);
            if ($ownerId === $selfId) {
                return true;
            }
            if ($ownerId <= 0) {
                // 无归属账号需结合 company_id/industry_id 判断，见 isOperatorAccountRowVisible
                return true;
            }
            if (self::isOtherOperatorOwner($ownerId)) {
                return false;
            }
            // 有归属时仅本人 + 关联企业客户；不再把「无企业/无行业用户」当公共池
            return self::userHasScopedRole($ownerId, ['企业客户'], self::operatorCompanyIds());
        }
        return $ownerId === (int)(self::effectiveUser()['id'] ?? 0);
    }

    /** @return list<array{id:int,username:string,nickname:string,role_id:int,role_name:string}> */
    public static function accountFilterUsers(string $keyword = ''): array
    {
        if (!self::canUseAccountAdvancedFilter()) {
            return [];
        }
        CompanyService::ensureSchema();
        if (self::isSuper()) {
            return self::queryFilterUsers($keyword, null, null);
        }
        if (self::isOpsAdmin()) {
            return self::queryFilterUsers($keyword, ['运营专员', '企业客户'], null);
        }
        if (self::isOperator()) {
            $selfId = (int)(self::effectiveUser()['id'] ?? 0);
            $companyIds = self::operatorCompanyIds();
            $users = self::queryFilterUsers($keyword, ['企业客户'], $companyIds);
            $self = DB::table('admin_users')->where('id', $selfId)->first();
            if ($self && $selfId > 0) {
                $roles = DB::table('admin_roles')->get();
                $roleMap = [];
                foreach ($roles as $r) {
                    $roleMap[(int)$r['id']] = (string)($r['name'] ?? '');
                }
                $rid = (int)($self['role_id'] ?? 0);
                $selfRow = [
                    'id' => $selfId,
                    'username' => (string)($self['username'] ?? ''),
                    'nickname' => (string)($self['nickname'] ?? ''),
                    'role_id' => $rid,
                    'role_name' => $roleMap[$rid] ?? '',
                ];
                if (!$keyword || self::userMatchesKeyword($selfRow, $keyword)) {
                    $exists = false;
                    foreach ($users as $u) {
                        if ((int)$u['id'] === $selfId) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        array_unshift($users, $selfRow);
                    }
                }
            }
            return $users;
        }
        return [];
    }

    /** 企业数据列表：超管/运营管理全部；运营专员仅关联企业 */
    public static function applyCompanyListScope(array &$filters, array &$params, string $alias = 'c'): void
    {
        if (self::isSuper() || self::isOpsAdmin()) {
            return;
        }
        if (self::isOperator()) {
            $companyIds = self::operatorCompanyIds();
            if (!$companyIds) {
                $filters[] = '1=0';
                return;
            }
            $ph = implode(',', array_fill(0, count($companyIds), '?'));
            $filters[] = "{$alias}.id IN ({$ph})";
            array_push($params, ...$companyIds);
            return;
        }
        $filters[] = '1=0';
    }

    public static function isCompanyAccessible(int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }
        if (self::isSuper() || self::isOpsAdmin()) {
            return true;
        }
        if (self::isOperator()) {
            return in_array($companyId, self::operatorCompanyIds(), true);
        }
        return false;
    }

    /** 运营专员创建企业后写入 company_users 关联自身 */
    public static function stampCompanyCreator(int $companyId): void
    {
        if (!self::isOperator() || $companyId <= 0) {
            return;
        }
        $selfId = (int)(self::effectiveUser()['id'] ?? 0);
        if ($selfId <= 0) {
            return;
        }
        CompanyService::linkUserToCompany($selfId, $companyId, false);
    }

    /** @return list<array<string,mixed>> */
    public static function accountFilterCompanies(string $keyword = '', int $industryId = 0): array
    {
        if (!self::canUseAccountAdvancedFilter()) {
            return [];
        }
        CompanyService::ensureSchema();
        IndustryDataService::ensureSchema();
        $filters = ['c.status = 1'];
        $params = [];
        if ($keyword !== '') {
            $filters[] = 'c.name LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        if ($industryId > 0) {
            $ids = IndustryDataService::descendantIds($industryId);
            if (!$ids) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $filters[] = "c.industry_id IN ({$ph})";
            array_push($params, ...$ids);
        }
        if (self::isOperator()) {
            $companyIds = self::operatorCompanyIds();
            if (!$companyIds) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($companyIds), '?'));
            $filters[] = "c.id IN ({$ph})";
            array_push($params, ...$companyIds);
        }
        // 运营管理与「企业数据」页口径一致：可见全部启用企业（新增企业不必等关联用户才出现）
        $where = 'WHERE ' . implode(' AND ', $filters);
        $rows = DB::select(
            "SELECT c.id, c.name, c.industry_id FROM companies c {$where} ORDER BY c.id DESC LIMIT 1000",
            $params
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $cid = (int)($row['id'] ?? 0);
            $iid = (int)($row['industry_id'] ?? 0);
            $out[] = [
                'id' => $cid,
                'name' => (string)($row['name'] ?? ''),
                'industryId' => $iid,
                'industryPath' => $iid > 0 ? str_replace(' / ', ' - ', IndustryDataService::pathLabel($iid)) : '',
            ];
        }
        return $out;
    }

    private static function opsAdminAccountScopeExpr(string $column): string
    {
        $scoped = self::scopedRoleOwnerSubquery(['运营专员', '企业客户']);
        $unscoped = self::unscopedOwnerExpr($column);
        return "({$column} IN ({$scoped}) OR {$unscoped})";
    }

    /** @return list<int> */
    private static function enterpriseCompanyIds(): array
    {
        $uid = (int)(self::effectiveUser()['id'] ?? 0);
        if ($uid <= 0) {
            return [];
        }
        CompanyService::ensureSchema();
        $ids = CompanyService::companyIdsOfUser($uid);
        if ($ids) {
            return $ids;
        }
        $user = self::effectiveUser();
        $cid = (int)($user['company_id'] ?? 0);
        return $cid > 0 ? [$cid] : [];
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function enterpriseAccountScopeSql(string $ownerColumn): array
    {
        $selfId = (int)(self::effectiveUser()['id'] ?? 0);
        $companyIds = self::enterpriseCompanyIds();
        if (!$companyIds) {
            return [" AND {$ownerColumn} = ?", [$selfId > 0 ? $selfId : 0]];
        }
        $companyColumn = preg_replace('/owner_id\b/', 'company_id', $ownerColumn);
        $ph = implode(',', array_fill(0, count($companyIds), '?'));
        return [
            " AND ({$ownerColumn} = ? OR {$companyColumn} IN ({$ph}))",
            array_merge([$selfId], $companyIds),
        ];
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function operatorAccountScopeSql(string $column): array
    {
        $selfId = (int)(self::effectiveUser()['id'] ?? 0);
        $companyIds = self::operatorCompanyIds();
        $publicPool = self::operatorAccountPublicPoolExpr($column);
        if (!$companyIds) {
            return [" AND ({$column} = ? OR {$publicPool})", [$selfId]];
        }
        $ph = implode(',', array_fill(0, count($companyIds), '?'));
        $customerSub = "SELECT u.id FROM admin_users u
            INNER JOIN admin_roles r ON r.id = u.role_id
            INNER JOIN company_users cu ON cu.user_id = u.id
            WHERE u.status = 1 AND r.name LIKE '%企业客户%' AND cu.company_id IN ({$ph})";
        return [
            " AND ({$column} = ? OR {$column} IN ({$customerSub}) OR {$publicPool})",
            array_merge([$selfId], $companyIds),
        ];
    }

    /**
     * 运营专员可见公共池：账号未绑定用户、未绑定企业，但已绑定行业。
     * （不再使用「归属用户无企业/无行业」那种宽公海。）
     */
    private static function operatorAccountPublicPoolExpr(string $ownerColumn): string
    {
        $companyColumn = preg_replace('/owner_id\b/', 'company_id', $ownerColumn) ?: 'company_id';
        $industryColumn = preg_replace('/owner_id\b/', 'industry_id', $ownerColumn) ?: 'industry_id';
        return "(
            ({$ownerColumn} IS NULL OR {$ownerColumn} = 0)
            AND ({$companyColumn} IS NULL OR {$companyColumn} = 0)
            AND ({$industryColumn} IS NOT NULL AND {$industryColumn} > 0)
        )";
    }

    /**
     * 运营专员单行可见：本人 / 关联企业客户账号 / 公共池（无用户无企业有行业）。
     *
     * @param array<string,mixed> $account
     */
    private static function isOperatorAccountRowVisible(array $account): bool
    {
        $account = self::hydrateAccountScopeFields($account);
        $selfId = (int)(self::effectiveUser()['id'] ?? 0);
        $ownerId = (int)($account['owner_id'] ?? 0);
        if ($ownerId > 0) {
            if ($ownerId === $selfId) {
                return true;
            }
            if (self::isOtherOperatorOwner($ownerId)) {
                return false;
            }
            return self::userHasScopedRole($ownerId, ['企业客户'], self::operatorCompanyIds());
        }
        $companyId = (int)($account['company_id'] ?? 0);
        $industryId = (int)($account['industry_id'] ?? 0);
        return $companyId <= 0 && $industryId > 0;
    }

    /**
     * 补齐 owner/company/industry，避免详情/写操作只查了 owner_id 时误判公共池。
     *
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    private static function hydrateAccountScopeFields(array $account): array
    {
        $needOwner = !array_key_exists('owner_id', $account);
        $needCompany = !array_key_exists('company_id', $account);
        $needIndustry = !array_key_exists('industry_id', $account);
        if (!$needOwner && !$needCompany && !$needIndustry) {
            return $account;
        }
        $id = (int)($account['id'] ?? 0);
        if ($id <= 0) {
            return $account;
        }
        $row = DB::selectOne(
            'SELECT owner_id, company_id, industry_id FROM accounts WHERE id=?',
            [$id]
        );
        if (!$row) {
            return $account;
        }
        if ($needOwner) {
            $account['owner_id'] = $row['owner_id'] ?? 0;
        }
        if ($needCompany) {
            $account['company_id'] = $row['company_id'] ?? 0;
        }
        if ($needIndustry) {
            $account['industry_id'] = $row['industry_id'] ?? 0;
        }
        return $account;
    }

    private static function isOtherOperatorOwner(int $ownerId): bool
    {
        if ($ownerId <= 0) {
            return false;
        }
        $selfId = (int)(self::effectiveUser()['id'] ?? 0);
        if ($ownerId === $selfId) {
            return false;
        }
        return self::userHasScopedRole($ownerId, ['运营专员']);
    }

    /** 公海账号：无归属，或归属用户无企业/无行业 */
    private static function unscopedOwnerExpr(string $column): string
    {
        return "(
            {$column} IS NULL OR {$column} = 0
            OR NOT EXISTS (SELECT 1 FROM admin_users u0 WHERE u0.id = {$column})
            OR (
                NOT EXISTS (SELECT 1 FROM company_users cu0 WHERE cu0.user_id = {$column})
                AND NOT EXISTS (SELECT 1 FROM admin_users u1 WHERE u1.id = {$column} AND u1.company_id > 0)
            )
            OR EXISTS (
                SELECT 1 FROM admin_users u2
                LEFT JOIN company_users cu2 ON cu2.user_id = u2.id AND cu2.is_primary = 1
                LEFT JOIN companies c2 ON c2.id = COALESCE(cu2.company_id, u2.company_id)
                WHERE u2.id = {$column} AND (c2.id IS NULL OR c2.industry_id IS NULL OR c2.industry_id = 0)
            )
        )";
    }

    /** @param list<string> $rolePatterns */
    private static function scopedRoleOwnerSubquery(array $rolePatterns): string
    {
        $parts = [];
        foreach ($rolePatterns as $p) {
            $escaped = str_replace(["\\", "'"], ["\\\\", "''"], $p);
            $parts[] = "r.name LIKE '%{$escaped}%'";
        }
        $roleSql = implode(' OR ', $parts);
        return "SELECT u.id FROM admin_users u
            INNER JOIN admin_roles r ON r.id = u.role_id
            WHERE u.status = 1 AND ({$roleSql})";
    }

    private static function isUnscopedOwnerId(int $ownerId): bool
    {
        if ($ownerId <= 0) {
            return true;
        }
        $row = DB::selectOne(
            'SELECT u.id, u.company_id,
                (SELECT company_id FROM company_users WHERE user_id=u.id ORDER BY is_primary DESC, company_id ASC LIMIT 1) AS cu_company
             FROM admin_users u WHERE u.id=?',
            [$ownerId]
        );
        if (!$row) {
            return true;
        }
        $cid = (int)($row['cu_company'] ?? 0);
        if ($cid <= 0) {
            $cid = (int)($row['company_id'] ?? 0);
        }
        if ($cid <= 0) {
            return true;
        }
        $company = DB::table('companies')->where('id', $cid)->first();
        if (!$company) {
            return true;
        }
        return (int)($company['industry_id'] ?? 0) <= 0;
    }

    /** @param list<string> $rolePatterns @param list<int>|null $companyIds */
    private static function userHasScopedRole(int $userId, array $rolePatterns, ?array $companyIds = null): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $parts = [];
        $params = [$userId];
        foreach ($rolePatterns as $p) {
            $parts[] = 'r.name LIKE ?';
            $params[] = '%' . $p . '%';
        }
        $sql = 'SELECT 1 AS ok FROM admin_users u INNER JOIN admin_roles r ON r.id = u.role_id WHERE u.id=? AND u.status=1 AND (' . implode(' OR ', $parts) . ')';
        if ($companyIds !== null) {
            if (!$companyIds) {
                return false;
            }
            $ph = implode(',', array_fill(0, count($companyIds), '?'));
            $sql = "SELECT 1 AS ok FROM admin_users u
                INNER JOIN admin_roles r ON r.id = u.role_id
                INNER JOIN company_users cu ON cu.user_id = u.id
                WHERE u.id=? AND u.status=1 AND (" . implode(' OR ', $parts) . ") AND cu.company_id IN ({$ph})";
            array_push($params, ...$companyIds);
        }
        $row = DB::selectOne($sql, $params);
        return (bool)$row;
    }

    /** @return list<int> */
    private static function operatorCompanyIds(): array
    {
        $selfId = (int)(self::effectiveUser()['id'] ?? 0);
        return $selfId > 0 ? CompanyService::companyIdsOfUser($selfId) : [];
    }

    /** @param list<string>|null $rolePatterns @param list<int>|null $companyIds */
    private static function queryFilterUsers(string $keyword, ?array $rolePatterns, ?array $companyIds, int $limit = 200): array
    {
        $sql = 'SELECT u.id, u.username, u.nickname, u.role_id, u.status FROM admin_users u';
        $params = [];
        $joins = [];
        $filters = ['u.status = 1'];
        if ($rolePatterns) {
            $joins[] = 'INNER JOIN admin_roles r ON r.id = u.role_id';
            $rp = [];
            foreach ($rolePatterns as $p) {
                $rp[] = 'r.name LIKE ?';
                $params[] = '%' . $p . '%';
            }
            $filters[] = '(' . implode(' OR ', $rp) . ')';
        }
        if ($companyIds !== null) {
            if (!$companyIds) {
                return [];
            }
            $joins[] = 'INNER JOIN company_users cu ON cu.user_id = u.id';
            $ph = implode(',', array_fill(0, count($companyIds), '?'));
            $filters[] = "cu.company_id IN ({$ph})";
            array_push($params, ...$companyIds);
        }
        if ($keyword !== '') {
            $filters[] = '(u.username LIKE ? OR u.nickname LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        $limit = max(1, min(5000, $limit));
        $sql .= $joins ? ' ' . implode(' ', $joins) : '';
        $sql .= ' WHERE ' . implode(' AND ', $filters) . ' ORDER BY u.id ASC LIMIT ' . $limit;
        $rows = DB::select($sql, $params) ?: [];
        $roles = DB::table('admin_roles')->get();
        $roleMap = [];
        foreach ($roles as $r) {
            $roleMap[(int)$r['id']] = (string)($r['name'] ?? '');
        }
        $out = [];
        foreach ($rows as $row) {
            $rid = (int)($row['role_id'] ?? 0);
            $out[] = [
                'id' => (int)$row['id'],
                'username' => (string)($row['username'] ?? ''),
                'nickname' => (string)($row['nickname'] ?? ''),
                'role_id' => $rid,
                'role_name' => $roleMap[$rid] ?? '',
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $user */
    private static function userMatchesKeyword(array $user, string $keyword): bool
    {
        $q = strtolower($keyword);
        $hay = strtolower((string)($user['username'] ?? '') . ' ' . (string)($user['nickname'] ?? ''));
        return str_contains($hay, $q);
    }

    public static function stamp(array &$row): void
    {
        $row['owner_id'] = self::writeOwnerId();
    }

    public static function ownsRow(?array $row, string $field = 'owner_id'): bool
    {
        if (!$row) {
            return false;
        }
        $oid = self::filterOwnerId();
        if ($oid === null) {
            return true;
        }
        return (int)($row[$field] ?? 0) === $oid;
    }

    public static function assertOwns(?array $row, string $field = 'owner_id'): ?Response
    {
        if (!$row) {
            return Response::error('资源不存在', 404);
        }
        if (!self::ownsRow($row, $field)) {
            return Response::error('无权访问该资源', 403);
        }
        return null;
    }

    /**
     * 账号行「可操作」判定：必须与列表/详情的可见性一致。
     *
     * ownsRow() 要求 owner_id 严格等于当前用户，会把「列表里看得见、一保存就 403」
     * 的情况留给超管 / 运营管理 / 运营专员，以及 owner_id 为 0 的历史账号。
     * 账号是分层可见的数据（见 isAccountOwnerVisible），因此写操作沿用同一套范围。
     */
    public static function accountOwnsRow(?array $account): bool
    {
        if (!$account) {
            return false;
        }
        if (self::isOperator()) {
            return self::isOperatorAccountRowVisible($account);
        }
        if (!array_key_exists('owner_id', $account)) {
            return self::ownsRow($account);
        }
        return self::isAccountOwnerVisible((int)($account['owner_id'] ?? 0));
    }

    /** 账号写操作统一入口（404 不存在 / 403 不在可见范围） */
    public static function assertAccountOwns(?array $account): ?Response
    {
        if (!$account) {
            return Response::error('资源不存在', 404);
        }
        if (!self::accountOwnsRow($account)) {
            return Response::error('无权访问该资源', 403);
        }
        return null;
    }

    /** 代理读操作：与列表可见范围一致（运营专员按关联账号绑定；其余按 owner） */
    public static function assertProxyVisible(?array $row, string $field = 'owner_id'): ?Response
    {
        if (!$row) {
            return Response::error('资源不存在', 404);
        }
        if (!self::isProxyRowVisible($row)) {
            return Response::error('无权访问该资源', 403);
        }
        return null;
    }

    /**
     * Node 自动化调用（/api/node/*）的账号准入。
     *
     * 两种情况：
     *  1) 纯服务调用：既没有登录态也没有 X-Acting-As（执行器/定时任务上下文里跑），
     *     此时请求已由 node token 鉴权，不再按「账号归属」拦——否则后台任务会莫名 403；
     *  2) 带用户身份：沿用列表/详情的可见范围，避免「列表里看得见、一执行就 403」。
     *
     * 注意「环境共用」与「账号包」分工：
     *  - 打开/列表/代理补全按 profileId，不走本方法（见 accountBindings / profileProxyBundle）；
     *  - 账号密文包、任务派发仍按可见范围，防止跨租户拖库。
     *  - 登录态回写在 AccountController::patchStatus 对已绑定环境单独放行。
     */
    public static function assertNodeAccount(?array $account): ?Response
    {
        if (!$account) {
            return Response::error('资源不存在', 404);
        }
        if (!self::effectiveUser()) {
            return null;
        }
        if (!self::accountOwnsRow($account)) {
            return Response::error('无权访问该资源', 403);
        }
        return null;
    }

    /**
     * 账号会话总任务（work batch）操作准入：batch.owner_id 可能与账号归属不一致（公海账号为 0），
     * 有身份时除严格 owner 外，再按关联账号的可见范围判定。
     *
     * @param array<string,mixed>|null $batch
     */
    public static function workBatchOwnsRow(?array $batch): bool
    {
        if (!$batch) {
            return false;
        }
        if (!self::effectiveUser()) {
            return true;
        }
        if (self::ownsRow($batch)) {
            return true;
        }
        $accountId = (int)($batch['account_id'] ?? 0);
        if ($accountId > 0) {
            $acc = DB::selectOne('SELECT id, owner_id, company_id FROM accounts WHERE id=?', [$accountId]);
            if (self::accountOwnsRow($acc)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed>|null $batch */
    public static function assertWorkBatchOwns(?array $batch): ?Response
    {
        if (!$batch) {
            return Response::error('资源不存在', 404);
        }
        if (!self::workBatchOwnsRow($batch)) {
            return Response::error('无权访问该资源', 403);
        }
        return null;
    }

    /**
     * AI 拓客任务：除 owner_id 严格匹配外，关联/查数据账号在可见范围内也可操作。
     *
     * @param array<string,mixed>|null $lead
     */
    public static function aiLeadOwnsRow(?array $lead): bool
    {
        if (!$lead) {
            return false;
        }
        if (!self::effectiveUser()) {
            return true;
        }
        if (self::ownsRow($lead)) {
            return true;
        }
        $leadId = (int)($lead['id'] ?? 0);
        if ($leadId < 1) {
            return false;
        }
        $accountIds = [];
        $scrape = (int)($lead['scrape_account_id'] ?? 0);
        if ($scrape > 0) {
            $accountIds[] = $scrape;
        }
        $linked = DB::select('SELECT account_id FROM ai_lead_accounts WHERE lead_id=?', [$leadId]) ?: [];
        foreach ($linked as $row) {
            $aid = (int)($row['account_id'] ?? 0);
            if ($aid > 0) {
                $accountIds[] = $aid;
            }
        }
        foreach (array_values(array_unique($accountIds)) as $aid) {
            $acc = DB::selectOne('SELECT id, owner_id, company_id FROM accounts WHERE id=?', [$aid]);
            if (self::accountOwnsRow($acc)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed>|null $lead */
    public static function assertAiLeadOwns(?array $lead): ?Response
    {
        if (!$lead) {
            return Response::error('资源不存在', 404);
        }
        if (!self::aiLeadOwnsRow($lead)) {
            return Response::error('无权访问该资源', 403);
        }
        return null;
    }

    /** @return list<array{id:int,username:string,nickname:string}> */
    public static function childrenOf(int $parentId): array
    {
        self::ensureChildrenTable();
        try {
            return DB::select(
                "SELECT u.id, u.username, u.nickname
                 FROM admin_user_children c
                 INNER JOIN admin_users u ON u.id = c.child_id
                 WHERE c.parent_id = ? AND u.status = 1
                 ORDER BY u.id ASC",
                [$parentId]
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function isDirectChild(int $parentId, int $childId): bool
    {
        self::ensureChildrenTable();
        try {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM admin_user_children WHERE parent_id=? AND child_id=? LIMIT 1',
                [$parentId, $childId]
            );
            return (bool)$row;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 祖先链（含自己），用于防环 */
    public static function ancestorIds(int $userId): array
    {
        self::ensureChildrenTable();
        $ids = [$userId];
        $current = $userId;
        for ($i = 0; $i < 32; $i++) {
            try {
                $row = DB::selectOne('SELECT parent_id FROM admin_user_children WHERE child_id=? LIMIT 1', [$current]);
            } catch (\Throwable $e) {
                break;
            }
            if (!$row) {
                break;
            }
            $pid = (int)$row['parent_id'];
            if (in_array($pid, $ids, true)) {
                break;
            }
            $ids[] = $pid;
            $current = $pid;
        }
        return $ids;
    }

    public static function setChildren(int $parentId, array $childIds): ?Response
    {
        self::ensureChildrenTable();
        $childIds = array_values(array_unique(array_filter(array_map('intval', $childIds), static fn($id) => $id > 0)));
        foreach ($childIds as $cid) {
            if ($cid === $parentId) {
                return Response::error('不能将自己设为子级管理员', 422);
            }
            $child = DB::table('admin_users')->where('id', $cid)->first();
            if (!$child) {
                return Response::error("子级管理员 #{$cid} 不存在", 422);
            }
            // 超级管理员（role_id=1）不可成为他人下级
            if ((int)($child['role_id'] ?? 0) === 1) {
                return Response::error('超级管理员不可设为他人下级', 422);
            }
            // 禁止把祖先勾成子（成环）：child 已在 parent 的祖先链上
            if (in_array($cid, self::ancestorIds($parentId), true)) {
                return Response::error('不能将上级管理员设为子级（会形成环）', 422);
            }
        }

        DB::query('DELETE FROM admin_user_children WHERE parent_id=?', [$parentId]);
        foreach ($childIds as $cid) {
            DB::query(
                'INSERT INTO admin_user_children(parent_id, child_id) VALUES (?,?)',
                [$parentId, $cid]
            );
        }
        return null;
    }

    /** @return list<int> */
    public static function childIdsOf(int $parentId): array
    {
        self::ensureChildrenTable();
        try {
            $rows = DB::select('SELECT child_id FROM admin_user_children WHERE parent_id=?', [$parentId]) ?: [];
            return array_map(static fn($r) => (int)$r['child_id'], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function ensureAccountBindingColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        foreach ([
            'company_id' => 'ALTER TABLE accounts ADD COLUMN company_id INT NULL DEFAULT NULL',
            'industry_id' => 'ALTER TABLE accounts ADD COLUMN industry_id INT NULL DEFAULT NULL',
        ] as $col => $sql) {
            try {
                $cols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
                if (!in_array($col, $cols, true)) {
                    DB::query($sql);
                }
            } catch (\Throwable $e) {
            }
        }
    }

    public static function ensureColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        self::ensureAccountBindingColumns();
        foreach (self::OWNER_TABLES as $table) {
            try {
                $cols = array_column(DB::select("SHOW COLUMNS FROM `{$table}`") ?: [], 'Field');
            } catch (\Throwable $e) {
                continue;
            }
            if (!in_array('owner_id', $cols, true)) {
                try {
                    DB::query("ALTER TABLE `{$table}` ADD COLUMN `owner_id` INT NULL DEFAULT NULL");
                } catch (\Throwable $e) {
                }
                try {
                    DB::query("ALTER TABLE `{$table}` ADD KEY `idx_{$table}_owner` (`owner_id`)");
                } catch (\Throwable $e) {
                }
            }
            // 不再把历史 NULL 一律刷成 1；归属仅在 INSERT 时 stamp
        }
    }

    public static function ensureChildrenTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query(
                "CREATE TABLE IF NOT EXISTS `admin_user_children` (
                  `parent_id` INT NOT NULL,
                  `child_id` INT NOT NULL,
                  PRIMARY KEY (`parent_id`, `child_id`),
                  KEY `idx_admin_child` (`child_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
        }
        // 清理历史脏数据：超级管理员不可作为他人下级
        try {
            DB::query(
                'DELETE c FROM admin_user_children c
                 INNER JOIN admin_users u ON u.id = c.child_id
                 WHERE u.role_id = 1'
            );
        } catch (\Throwable $e) {
        }
    }

    public static function countOwnedBusiness(int $ownerId): int
    {
        self::ensureColumns();
        $total = 0;
        foreach (['accounts', 'groups', 'proxies', 'materials', 'tasks', 'agents', 'message_templates', 'uid_targets'] as $table) {
            try {
                $c = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM `{$table}` WHERE owner_id=?", [$ownerId])['c'] ?? 0);
                $total += $c;
            } catch (\Throwable $e) {
            }
        }
        return $total;
    }
}
