<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\CompanyService;
use App\Services\DataScope;

class GroupController extends Controller
{
    /** @var bool|null */
    private static ?bool $hasParentCol = null;

    public function list(Request $request): Response
    {
        try {
            $this->ensureSchema();
            DataScope::ensureColumns();
            $type = trim((string)$request->get('type', ''));
            $asTree = filter_var($request->get('tree', false), FILTER_VALIDATE_BOOLEAN);
            $hasParent = $this->hasParentColumn();

            // 先取分组，再补 member/child 计数，避免复杂 CASE 子查询在缺表/缺列时整单 500
            $filters = [];
            $params = [];
            if ($type !== '') {
                $filters[] = 'g.type=?';
                $params[] = $type;
            }
            $forceOwner = $request->get('ownerId', $request->get('owner_id'));
            $creatorId = DataScope::groupCreatorId();
            if ($forceOwner !== null && $forceOwner !== '') {
                $want = (int)$forceOwner;
                $allowed = $creatorId !== null && $want === $creatorId;
                // 超管/运营管理：可按归属用户查看分组（账号侧栏筛选，不切换身份）
                if (!$allowed && $want > 0 && (DataScope::isSuper() || DataScope::isOpsAdmin())) {
                    $allowed = DataScope::isAccountOwnerVisible($want);
                }
                // 有下级：可查看本人或直属下级分组
                if (!$allowed && $want > 0 && !DataScope::isEnterpriseCustomer() && !DataScope::isOperator()) {
                    $loginId = (int)(DataScope::loginUser()['id'] ?? 0);
                    if ($loginId > 0 && ($want === $loginId || in_array($want, DataScope::childIdsOf($loginId), true))) {
                        $allowed = true;
                    }
                }
                if (!$allowed) {
                    return $this->error('无权查看该用户的分组', 403);
                }
                $filters[] = 'g.owner_id = ?';
                $params[] = $want;
            } else {
                DataScope::applyGroupCreatorScope($filters, $params, 'g.owner_id');
            }
            $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
            $sql = "SELECT g.* FROM `groups` g {$where}";
            if ($hasParent) {
                $sql .= ' ORDER BY COALESCE(g.parent_id, 0) ASC, g.id ASC';
            } else {
                $sql .= ' ORDER BY g.id ASC';
            }

            $rows = DB::select($sql, $params);
            $counts = $this->loadMemberCounts($rows, $hasParent);

            foreach ($rows as &$r) {
                $id = (int)($r['id'] ?? 0);
                $r['parent_id'] = $hasParent && isset($r['parent_id']) && $r['parent_id'] !== null && $r['parent_id'] !== ''
                    ? (int)$r['parent_id'] : null;
                $r['member_count'] = (int)($counts[$id]['member_count'] ?? 0);
                $r['child_count'] = (int)($counts[$id]['child_count'] ?? 0);
            }
            unset($r);

            if ($asTree && $type === 'account') {
                return $this->success($this->buildTree($rows));
            }
            return $this->success($rows);
        } catch (\Throwable $e) {
            return $this->error('分组列表加载失败: ' . $e->getMessage(), 500);
        }
    }

    public function create(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $name = trim((string)$request->input('name', ''));
        $type = trim((string)$request->input('type', 'account'));
        $desc = trim((string)$request->input('description', ''));
        $parentId = $request->input('parentId', $request->input('parent_id'));
        $parentId = ($parentId === null || $parentId === '') ? null : (int)$parentId;

        if ($name === '') {
            return $this->error('分组名称不能为空', 422);
        }
        if (!in_array($type, ['account', 'proxy', 'message', 'uid'], true)) {
            return $this->error('无效的分组类型', 422);
        }
        if ($err = $this->assertCanManageProxyGroup(null, $type)) {
            return $err;
        }

        if ($parentId !== null) {
            if ($type !== 'account') {
                return $this->error('目前仅账号分组支持子分组', 422);
            }
            if (!$this->hasParentColumn()) {
                return $this->error('数据库尚未支持子分组，请先执行迁移或刷新后重试', 500);
            }
            $parent = DB::selectOne('SELECT id, type, parent_id, owner_id FROM `groups` WHERE id=?', [$parentId]);
            if ($err = DataScope::assertGroupOwns($parent)) {
                return $err;
            }
            if (!$parent || ($parent['type'] ?? '') !== 'account') {
                return $this->error('上级分组不存在或类型不正确', 422);
            }
            if (!empty($parent['parent_id'])) {
                return $this->error('仅支持两级：不能在子分组下再建子分组', 422);
            }
        }

        if ($this->nameExists($name, $type, $parentId, null)) {
            return $this->error('同级下已存在该分组名称', 409);
        }

        $payload = [
            'name' => $name,
            'type' => $type,
            'description' => $desc,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($this->hasParentColumn()) {
            $payload['parent_id'] = $parentId;
        }
        DataScope::stampGroupOwner($payload);

        $id = DB::table('groups')->insert($payload);
        return $this->success([
            'id' => (int)$id,
            'name' => $name,
            'type' => $type,
            'parentId' => $parentId,
            'description' => $desc,
        ], '分组创建成功');
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::table('groups')->where('id', (int)$id)->first();
        if ($err = DataScope::assertGroupOwns($row)) {
            return $err;
        }
        if ($err = $this->assertCanManageProxyGroup($row)) {
            return $err;
        }
        $name = trim((string)$request->input('name', $row['name'] ?? ''));
        $desc = trim((string)$request->input('description', $row['description'] ?? ''));
        if ($name === '') {
            return $this->error('分组名称不能为空', 422);
        }
        $type = $row['type'] ?? 'account';

        $parentId = array_key_exists('parentId', $request->all()) || array_key_exists('parent_id', $request->all())
            ? $request->input('parentId', $request->input('parent_id'))
            : ($row['parent_id'] ?? null);
        $parentId = ($parentId === null || $parentId === '') ? null : (int)$parentId;

        if ($parentId !== null) {
            if ($type !== 'account') {
                return $this->error('目前仅账号分组支持子分组', 422);
            }
            if ($parentId === (int)$id) {
                return $this->error('不能将分组设为自己的上级', 422);
            }
            if (!$this->hasParentColumn()) {
                return $this->error('数据库尚未支持子分组，请先执行迁移或刷新后重试', 500);
            }
            $parent = DB::selectOne('SELECT id, type, parent_id, owner_id FROM `groups` WHERE id=?', [$parentId]);
            if ($err = DataScope::assertGroupOwns($parent)) {
                return $err;
            }
            if (!$parent || ($parent['type'] ?? '') !== 'account') {
                return $this->error('上级分组不存在或类型不正确', 422);
            }
            if (!empty($parent['parent_id'])) {
                return $this->error('仅支持两级：不能挂到子分组下', 422);
            }
            $childCnt = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM `groups` WHERE parent_id=?', [(int)$id])['c'] ?? 0);
            if ($childCnt > 0) {
                return $this->error('该分组下已有子分组，不能改为子分组', 422);
            }
        }

        if ($this->nameExists($name, $type, $parentId, (int)$id)) {
            return $this->error('同级下已存在该分组名称', 409);
        }

        $update = [
            'name' => $name,
            'description' => $desc,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($this->hasParentColumn()) {
            $update['parent_id'] = $parentId;
        }
        DB::table('groups')->where('id', (int)$id)->update($update);

        return $this->success([
            'id' => (int)$id,
            'name' => $name,
            'description' => $desc,
            'parentId' => $parentId,
        ], '分组已更新');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $gid = (int)$id;
        $row = DB::table('groups')->where('id', $gid)->first();
        if ($err = DataScope::assertGroupOwns($row)) {
            return $err;
        }
        if ($err = $this->assertCanManageProxyGroup($row)) {
            return $err;
        }

        $childCnt = 0;
        if ($this->hasParentColumn()) {
            $childCnt = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM `groups` WHERE parent_id=?', [$gid])['c'] ?? 0);
        }
        if ($childCnt > 0) {
            return $this->error('请先删除或移走其下的公司子分组', 422);
        }

        try {
            DB::query('UPDATE accounts SET group_id=NULL, updated_at=CURRENT_TIMESTAMP WHERE group_id=?', [$gid]);
        } catch (\Throwable $e) {
        }
        try {
            DB::query('UPDATE proxies SET group_id=NULL WHERE group_id=?', [$gid]);
        } catch (\Throwable $e) {
        }
        try {
            DB::query('UPDATE message_templates SET group_id=NULL WHERE group_id=?', [$gid]);
        } catch (\Throwable $e) {
        }

        DB::table('groups')->where('id', $gid)->delete();
        return $this->success(null, '分组已删除');
    }

    /**
     * 将账号分组（含一级下子组）及组内账号一并分配给指定管理用户。
     * 仅登录身份为超管 / 运营管理可操作。
     */
    public function assignOwner(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();

        if (!DataScope::canAssignGroupOwners()) {
            return $this->error('仅超级管理员或运营管理可分配分组用户', 403);
        }

        $gid = (int)$id;
        $row = DB::table('groups')->where('id', $gid)->first();
        if (!$row) {
            return $this->error('分组不存在', 404);
        }
        if (($row['type'] ?? '') !== 'account') {
            return $this->error('仅支持账号分组分配用户', 422);
        }
        // 超管/运营管理可分配任意账号分组（不要求分组当前归属本人）
        // 其它角色本就不会进入此接口（上方 canAssignGroupOwners 已拦截）

        $targetOwnerId = (int)$request->input('ownerId', $request->input('userId', 0));
        $companyId = $request->input('companyId', $request->input('company_id'));
        $companyId = ($companyId === null || $companyId === '') ? null : (int)$companyId;

        [$resolvedOwner, $resolvedCompany, $assignErr] = $this->resolveGroupOwnerAssignment($targetOwnerId, $companyId);
        if ($assignErr) {
            return $assignErr;
        }

        $target = DB::table('admin_users')->where('id', $resolvedOwner)->first();
        $groupIds = self::expandAccountGroupIds($gid);
        $ph = implode(',', array_fill(0, count($groupIds), '?'));

        DB::query(
            "UPDATE `groups` SET owner_id=?, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph})",
            array_merge([$resolvedOwner], $groupIds)
        );

        $accStmt = DB::query(
            "UPDATE accounts SET owner_id=?, company_id=?, updated_at=CURRENT_TIMESTAMP WHERE group_id IN ({$ph})",
            array_merge([$resolvedOwner, $resolvedCompany], $groupIds)
        );

        $ownerName = (string)(($target['nickname'] ?? '') ?: ($target['username'] ?? ''));
        return $this->success([
            'groupId' => $gid,
            'groupIds' => $groupIds,
            'groupsUpdated' => count($groupIds),
            'accountsUpdated' => (int)$accStmt->rowCount(),
            'ownerId' => $resolvedOwner,
            'companyId' => $resolvedCompany,
            'ownerName' => $ownerName,
        ], '分组及组内账号已分配给 ' . $ownerName);
    }

    /**
     * @return array{0:int,1:?int,2:?Response}
     */
    private function resolveGroupOwnerAssignment(int $targetOwnerId, ?int $companyId): array
    {
        if ($targetOwnerId < 1) {
            return [0, null, $this->error('请选择管理用户', 422)];
        }
        $target = DB::table('admin_users')->where('id', $targetOwnerId)->first();
        if (!$target || (int)($target['status'] ?? 0) !== 1) {
            return [0, null, $this->error('目标管理用户不存在或已停用', 404)];
        }

        $roleName = DataScope::roleNameOfUser($target);
        $isEnterprise = str_contains($roleName, '企业客户');
        $isOperator = str_contains($roleName, '运营专员');
        // 超管可分配给任意启用用户；运营管理仍限运营专员/企业客户
        if (!$isEnterprise && !$isOperator) {
            if (DataScope::isLoginSuper()) {
                return [$targetOwnerId, null, null];
            }
            return [0, null, $this->error('只能分配给运营专员或企业客户', 422)];
        }
        if ($isOperator) {
            return [$targetOwnerId, null, null];
        }

        CompanyService::ensureSchema();
        $companyIds = CompanyService::companyIdsOfUser($targetOwnerId);
        if (!$companyIds) {
            return [0, null, $this->error('该企业客户未关联企业，请先在企业数据中绑定', 422)];
        }
        $resolvedCompany = ($companyId !== null && $companyId > 0) ? $companyId : (int)$companyIds[0];
        if (!in_array($resolvedCompany, $companyIds, true)) {
            return [0, null, $this->error('所选企业不属于该企业客户', 422)];
        }
        return [$targetOwnerId, $resolvedCompany, null];
    }

    /** 供 AccountController 复用：一级分组 → 自身 + 全部子组 id */
    public static function expandAccountGroupIds(int $groupId): array
    {
        $ids = [$groupId];
        try {
            $children = DB::select('SELECT id FROM `groups` WHERE parent_id=? AND type=?', [$groupId, 'account']);
            foreach ($children as $c) {
                $ids[] = (int)$c['id'];
            }
        } catch (\Throwable $e) {
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{member_count:int, child_count:int}>
     */
    private function loadMemberCounts(array $rows, bool $hasParent): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = ['member_count' => 0, 'child_count' => 0];
        }
        if (!$rows) {
            return $out;
        }

        $byType = [];
        foreach ($rows as $r) {
            $byType[$r['type'] ?? ''][] = (int)$r['id'];
        }

        $apply = static function (array $pairs, string $key = 'member_count') use (&$out): void {
            foreach ($pairs as $p) {
                $gid = (int)($p['group_id'] ?? 0);
                if (isset($out[$gid])) {
                    $out[$gid][$key] = (int)($p['c'] ?? 0);
                }
            }
        };

        try {
            if (!empty($byType['account'])) {
                $ids = $byType['account'];
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $apply(DB::select(
                    "SELECT group_id, COUNT(*) AS c FROM accounts WHERE group_id IN ({$ph}) GROUP BY group_id",
                    $ids
                ));
                if ($hasParent) {
                    // 一级组：把子组账号数累加到父组
                    $childRows = DB::select(
                        "SELECT c.parent_id AS group_id, COUNT(*) AS c
                         FROM accounts a
                         INNER JOIN `groups` c ON c.id = a.group_id
                         WHERE c.parent_id IN ({$ph})
                         GROUP BY c.parent_id",
                        $ids
                    );
                    foreach ($childRows as $p) {
                        $gid = (int)($p['group_id'] ?? 0);
                        if (isset($out[$gid])) {
                            $out[$gid]['member_count'] += (int)($p['c'] ?? 0);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            if (!empty($byType['proxy'])) {
                $ids = $byType['proxy'];
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $apply(DB::select(
                    "SELECT group_id, COUNT(*) AS c FROM proxies WHERE group_id IN ({$ph}) GROUP BY group_id",
                    $ids
                ));
            }
        } catch (\Throwable $e) {
        }

        try {
            if (!empty($byType['message'])) {
                $ids = $byType['message'];
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $apply(DB::select(
                    "SELECT group_id, COUNT(*) AS c FROM message_templates WHERE group_id IN ({$ph}) GROUP BY group_id",
                    $ids
                ));
            }
        } catch (\Throwable $e) {
        }

        try {
            if (!empty($byType['uid'])) {
                $ids = $byType['uid'];
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $apply(DB::select(
                    "SELECT group_id, COUNT(*) AS c FROM uid_targets WHERE group_id IN ({$ph}) GROUP BY group_id",
                    $ids
                ));
            }
        } catch (\Throwable $e) {
        }

        if ($hasParent) {
            try {
                $ids = array_map(static fn($r) => (int)$r['id'], $rows);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $apply(DB::select(
                    "SELECT parent_id AS group_id, COUNT(*) AS c FROM `groups` WHERE parent_id IN ({$ph}) GROUP BY parent_id",
                    $ids
                ), 'child_count');
            } catch (\Throwable $e) {
            }
        }

        return $out;
    }

    private function buildTree(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $r) {
            $pid = $r['parent_id'] ?? null;
            $key = $pid === null ? 0 : (int)$pid;
            $byParent[$key][] = $r;
        }
        $roots = $byParent[0] ?? [];
        foreach ($roots as &$root) {
            $root['children'] = $byParent[(int)$root['id']] ?? [];
        }
        unset($root);
        return $roots;
    }

    private function nameExists(string $name, string $type, ?int $parentId, ?int $excludeId): bool
    {
        [$ownSql, $ownParams] = DataScope::groupOwnerSql('owner_id');
        if ($this->hasParentColumn()) {
            if ($parentId === null) {
                $sql = 'SELECT id FROM `groups` WHERE name=? AND type=? AND parent_id IS NULL';
                $params = [$name, $type];
            } else {
                $sql = 'SELECT id FROM `groups` WHERE name=? AND type=? AND parent_id=?';
                $params = [$name, $type, $parentId];
            }
            if ($excludeId) {
                $sql .= ' AND id<>?';
                $params[] = $excludeId;
            }
            $sql .= $ownSql;
            $params = array_merge($params, $ownParams);
            return (bool)DB::selectOne($sql, $params);
        }
        $sql = 'SELECT id FROM `groups` WHERE name=? AND type=?';
        $params = [$name, $type];
        if ($excludeId) {
            $sql .= ' AND id<>?';
            $params[] = $excludeId;
        }
        $sql .= $ownSql;
        $params = array_merge($params, $ownParams);
        return (bool)DB::selectOne($sql, $params);
    }

    private function hasParentColumn(): bool
    {
        if (self::$hasParentCol !== null) {
            return self::$hasParentCol;
        }
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM `groups`') ?: [], 'Field');
            self::$hasParentCol = in_array('parent_id', $cols, true);
        } catch (\Throwable $e) {
            self::$hasParentCol = false;
        }
        return self::$hasParentCol;
    }

    /** @param array<string,mixed>|null $row */
    private function assertCanManageProxyGroup(?array $row, ?string $type = null): ?Response
    {
        $groupType = $type ?? (string)($row['type'] ?? '');
        if ($groupType !== 'proxy') {
            return null;
        }
        if (!DataScope::canManageProxyGroups()) {
            return $this->error('当前角色无权管理代理分组', 403);
        }
        return null;
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if ($this->hasParentColumn()) {
            return;
        }

        try {
            DB::query('ALTER TABLE `groups` ADD COLUMN `parent_id` INT NULL DEFAULT NULL');
            self::$hasParentCol = true;
        } catch (\Throwable $e) {
            // 可能已被并发请求加上
            self::$hasParentCol = null;
            if ($this->hasParentColumn()) {
                // ok
            } else {
                self::$hasParentCol = false;
            }
        }

        if ($this->hasParentColumn()) {
            try {
                DB::query('ALTER TABLE `groups` ADD KEY `idx_groups_parent` (`parent_id`, `type`)');
            } catch (\Throwable $e) {
            }
        }
    }
}
