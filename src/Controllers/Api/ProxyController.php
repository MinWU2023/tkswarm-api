<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;
use App\Services\SecretStore;
use App\Services\Translator;
use PDOException;

class ProxyController extends Controller
{
    /** 与 AccountController::PROXY_BIND_LIMIT 一致：单代理最多绑定账号数 */
    private const PROXY_BIND_LIMIT = 3;

    public function list(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(500, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;
        $filters = [];
        $params = [];
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') {
            $filters[] = '(p.name LIKE ? OR p.host LIKE ?)';
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }
        $status = trim((string)$request->get('status', ''));
        if ($status !== '') {
            $filters[] = 'p.status=?';
            $params[] = $status;
        }
        $groupId = $request->get('groupId');
        if ($groupId === 'none') {
            $filters[] = 'p.group_id IS NULL';
        } elseif ($groupId !== null && $groupId !== '') {
            $filters[] = 'p.group_id=?';
            $params[] = (int)$groupId;
        }
        // ownerId：按指定归属用户过滤（创建账号用当前用户；编辑账号用被编辑账号的 owner）
        $ownerIdRaw = $request->get('ownerId', $request->get('owner_id'));
        if ($ownerIdRaw !== null && $ownerIdRaw !== '') {
            $targetOwnerId = (int)$ownerIdRaw;
            if ($targetOwnerId < 1) {
                return $this->error('无效的归属用户', 400);
            }
            if (!$this->canListProxiesOfOwner($targetOwnerId)) {
                return $this->error('无权查看该用户的代理', 403);
            }
            $filters[] = 'p.owner_id=?';
            $params[] = $targetOwnerId;
            // 运营专员即使指定 ownerId，也必须落在「关联账号已绑定代理」范围内
            if (DataScope::isOperator()) {
                DataScope::applyProxyListScope('p.owner_id', $filters, $params);
            }
        } else {
            DataScope::applyProxyListScope('p.owner_id', $filters, $params);
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM proxies p {$where}", $params)['c'] ?? 0);
        $rows = DB::select(
            "SELECT p.*, g.name AS group_name FROM proxies p LEFT JOIN `groups` g ON g.id=p.group_id {$where} ORDER BY p.id DESC LIMIT {$pageSize} OFFSET {$offset}",
            $params
        );
        $rows = $this->enrichOwnerMeta($rows);
        $rows = $this->enrichAccountBindCounts($rows);
        $items = array_map([$this, 'publicRow'], $rows);
        $pages = max(1, (int)ceil($total / $pageSize));
        return $this->success([
            'items' => $items,
            'total' => $total,
            'page' => min($page, $pages),
            'pages' => $pages,
            'pageSize' => $pageSize,
        ]);
    }

    public function create(Request $request): Response
    {
        if ($err = $this->assertCanCreateProxies()) {
            return $err;
        }
        $this->ensureSchema();
        DataScope::ensureColumns();
        $body = $this->body($request);
        if ($body['host'] === '' || $body['port'] < 1) {
            return $this->error('请输入合法的代理服务器主机与端口');
        }
        $ownerId = DataScope::proxyInsertOwnerId();
        try {
            DB::query(
                "INSERT INTO proxies(name,protocol,host,port,username,password,password_encrypted,country,group_id,status,owner_id)
                 VALUES (?,?,?,?,?,'',?,?,?,'unchecked',?)",
                [
                    $body['name'], $body['protocol'], $body['host'], $body['port'], $body['username'],
                    SecretStore::encrypt($body['password']), $body['country'], $body['group_id'],
                    $ownerId,
                ]
            );
            return $this->success(['id' => (int)DB::getPdo()->lastInsertId()], '代理已创建');
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return $this->error('相同代理已存在', 409);
            }
            throw $e;
        }
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $existing = DB::selectOne('SELECT id, owner_id, password_encrypted, password FROM proxies WHERE id=?', [(int)$id]);
        if ($err = $this->assertCanEditProxyRow($existing)) {
            return $err;
        }
        $body = $this->body($request);
        $keep = trim((string)$request->input('password', '')) === '';
        $enc = $keep
            ? (string)($existing['password_encrypted'] ?: ($existing['password'] ? SecretStore::encrypt((string)$existing['password']) : ''))
            : SecretStore::encrypt($body['password']);
        try {
            DB::query(
                "UPDATE proxies SET name=?, protocol=?, host=?, port=?, username=?, password='', password_encrypted=?, country=?, group_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$body['name'], $body['protocol'], $body['host'], $body['port'], $body['username'], $enc, $body['country'], $body['group_id'], (int)$id]
            );
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return $this->error('相同代理已存在', 409);
            }
            throw $e;
        }
        return $this->success(null, '代理已更新');
    }

    public function delete(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $existing = DB::selectOne('SELECT id, owner_id FROM proxies WHERE id=?', [(int)$id]);
        if ($err = $this->assertCanEditProxyRow($existing)) {
            return $err;
        }
        DB::query('DELETE FROM proxies WHERE id=?', [(int)$id]);
        return $this->success(null, '代理已删除');
    }

    /** 查看代理已绑定的账号明细 */
    public function accounts(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $pid = (int)$id;
        $proxy = DB::selectOne('SELECT id, name, host, port, protocol, owner_id FROM proxies WHERE id=?', [$pid]);
        if ($err = $this->assertProxyRowAccess($proxy)) {
            return $err;
        }
        $rows = DB::select(
            'SELECT a.id, a.username, a.nickname, a.login_status, a.enabled, a.owner_id, a.group_id,
                    g.name AS group_name
             FROM accounts a
             LEFT JOIN `groups` g ON g.id = a.group_id
             WHERE a.proxy_id=?
             ORDER BY a.id ASC',
            [$pid]
        ) ?: [];
        $items = array_map(static function (array $r) {
            return [
                'id' => (int)$r['id'],
                'username' => (string)($r['username'] ?? ''),
                'nickname' => (string)($r['nickname'] ?? ''),
                'loginStatus' => (string)($r['login_status'] ?? ''),
                'enabled' => (int)($r['enabled'] ?? 0),
                'ownerId' => (int)($r['owner_id'] ?? 0),
                'groupId' => (int)($r['group_id'] ?? 0),
                'groupName' => (string)($r['group_name'] ?? ''),
            ];
        }, $rows);
        $canEdit = DataScope::canEditProxyRow($proxy);
        return $this->success([
            'proxy' => [
                'id' => $pid,
                'name' => (string)($proxy['name'] ?? ''),
                'host' => (string)($proxy['host'] ?? ''),
                'port' => (int)($proxy['port'] ?? 0),
                'protocol' => (string)($proxy['protocol'] ?? ''),
                'ownerId' => (int)($proxy['owner_id'] ?? 0),
            ],
            'items' => $items,
            'bound' => count($items),
            'limit' => self::PROXY_BIND_LIMIT,
            'remaining' => max(0, self::PROXY_BIND_LIMIT - count($items)),
            'canEdit' => $canEdit,
        ]);
    }

    /** 解绑代理上的账号 */
    public function unbindAccounts(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $pid = (int)$id;
        $proxy = DB::selectOne('SELECT id, owner_id FROM proxies WHERE id=?', [$pid]);
        if ($err = $this->assertCanEditProxyRow($proxy)) {
            return $err;
        }
        $ids = $this->idList($request->input('accountIds', []), 100);
        if (!$ids) {
            return $this->error('请选择要解绑的账号', 400);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->accountScopeSql('owner_id');
        $existing = array_map('intval', array_column(
            DB::select(
                "SELECT id FROM accounts WHERE proxy_id=? AND id IN ({$ph}){$ownSql}",
                array_merge([$pid], $ids, $ownParams)
            ) ?: [],
            'id'
        ));
        if (!$existing) {
            return $this->error('没有可解绑的账号（可能不在当前数据范围内）', 404);
        }
        $ph2 = implode(',', array_fill(0, count($existing), '?'));
        $stmt = DB::query(
            "UPDATE accounts SET proxy_id=NULL, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph2})",
            $existing
        );
        return $this->success([
            'proxyId' => $pid,
            'unbound' => $stmt->rowCount(),
            'accountIds' => $existing,
        ], '已解绑 ' . $stmt->rowCount() . ' 个账号');
    }

    /** 为代理绑定账号（单代理最多 PROXY_BIND_LIMIT 个） */
    public function bindAccounts(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $pid = (int)$id;
        $proxy = DB::selectOne('SELECT id, owner_id FROM proxies WHERE id=?', [$pid]);
        if ($err = $this->assertCanEditProxyRow($proxy)) {
            return $err;
        }
        $ids = $this->idList($request->input('accountIds', []), 100);
        if (!$ids) {
            return $this->error('请选择要绑定的账号', 400);
        }
        $current = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM accounts WHERE proxy_id=?', [$pid])['c'] ?? 0);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $already = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM accounts WHERE proxy_id=? AND id IN ({$ph})",
            array_merge([$pid], $ids)
        )['c'] ?? 0);
        $need = count($ids) - $already;
        if ($current - $already + count($ids) > self::PROXY_BIND_LIMIT) {
            $limit = self::PROXY_BIND_LIMIT;
            $remain = max(0, $limit - $current);
            return $this->error(
                "该代理已绑定 {$current} 个账号，最多 {$limit} 个；还可绑定 {$remain} 个",
                422
            );
        }
        if ($need < 1 && $already === count($ids)) {
            return $this->success([
                'proxyId' => $pid,
                'bound' => 0,
                'already' => $already,
                'current' => $current,
                'limit' => self::PROXY_BIND_LIMIT,
            ], '所选账号均已绑定该代理');
        }

        [$ownSql, $ownParams] = $this->accountScopeSql('owner_id');
        $accounts = DB::select(
            "SELECT id, proxy_id FROM accounts WHERE id IN ({$ph}){$ownSql}",
            array_merge($ids, $ownParams)
        ) ?: [];
        if (!$accounts) {
            return $this->error('没有可绑定的账号（可能不在当前数据范围内）', 404);
        }

        $bound = 0;
        foreach ($accounts as $acc) {
            $aid = (int)$acc['id'];
            if ((int)($acc['proxy_id'] ?? 0) === $pid) {
                continue;
            }
            DB::query(
                'UPDATE accounts SET proxy_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$pid, $aid]
            );
            DB::query(
                'UPDATE proxies SET use_count=use_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$pid]
            );
            $bound++;
        }
        $after = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM accounts WHERE proxy_id=?', [$pid])['c'] ?? 0);
        return $this->success([
            'proxyId' => $pid,
            'bound' => $bound,
            'current' => $after,
            'limit' => self::PROXY_BIND_LIMIT,
            'remaining' => max(0, self::PROXY_BIND_LIMIT - $after),
        ], $bound ? "已绑定 {$bound} 个账号" : '没有新账号被绑定');
    }

    public function batchImport(Request $request): Response
    {
        if ($err = $this->assertCanImportProxies()) {
            return $err;
        }
        $this->ensureSchema();
        DataScope::ensureColumns();
        $content = (string)$request->input('content', '');
        $protocol = (string)$request->input('defaultProtocol', 'http');
        $groupId = $request->input('groupId');
        $groupId = ($groupId === null || $groupId === '') ? null : (int)$groupId;
        $country = trim((string)$request->input('country', ''));
        $ownerId = DataScope::proxyInsertOwnerId();
        $lines = preg_split('/\r?\n/', $content) ?: [];
        if (count($lines) > 10000) {
            return $this->error('单次最多导入 10000 行', 422);
        }
        $result = ['total' => 0, 'imported' => 0, 'duplicates' => 0, 'ignored' => 0, 'errors' => []];
        foreach ($lines as $index => $source) {
            $trim = trim($source);
            if ($trim === '' || str_starts_with($trim, '#')) {
                $result['ignored']++;
                continue;
            }
            $result['total']++;
            try {
                $proxy = self::parseLine($trim, $protocol);
                DB::query(
                    "INSERT INTO proxies(name,protocol,host,port,username,password,password_encrypted,country,group_id,status,owner_id)
                     VALUES (?,?,?,?,?,'',?,?,?,'unchecked',?)",
                    [
                        substr($proxy['host'] . ':' . $proxy['port'], 0, 100),
                        $proxy['protocol'], $proxy['host'], $proxy['port'], $proxy['username'],
                        SecretStore::encrypt($proxy['password']), $country, $groupId, $ownerId,
                    ]
                );
                $result['imported']++;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $result['duplicates']++;
                } elseif (count($result['errors']) < 100) {
                    $result['errors'][] = ['line' => $index + 1, 'reason' => '写入失败'];
                }
            } catch (\Throwable $e) {
                if (count($result['errors']) < 100) {
                    $result['errors'][] = ['line' => $index + 1, 'reason' => $e->getMessage()];
                }
            }
        }
        return $this->success($result, "导入完成：成功 {$result['imported']}，重复 {$result['duplicates']}，错误 " . count($result['errors']));
    }

    public function batchMove(Request $request): Response
    {
        if ($err = $this->assertCanEditProxies()) {
            return $err;
        }
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('proxyIds', []), 1000);
        if (!$ids) {
            return $this->error('请选择代理', 400);
        }
        $groupId = $request->input('groupId');
        $groupId = ($groupId === null || $groupId === '') ? null : (int)$groupId;
        if ($groupId) {
            $g = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='proxy'", [$groupId]);
            if (!$g) {
                return $this->error('目标代理分组不存在', 422);
            }
            if ($err = DataScope::assertGroupOwns($g)) {
                return $err;
            }
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->proxyScopeSql('owner_id');
        $stmt = DB::query(
            "UPDATE proxies SET group_id=?, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph}){$ownSql}",
            array_merge([$groupId], $ids, $ownParams)
        );
        return $this->success(['requested' => count($ids), 'moved' => $stmt->rowCount()], '代理分组已更新');
    }

    public function batchDelete(Request $request): Response
    {
        if ($err = $this->assertCanEditProxies()) {
            return $err;
        }
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('proxyIds', []), 1000);
        if (!$ids) {
            return $this->error('请选择代理', 400);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = DataScope::proxyDeletableScopeSql('owner_id');
        $existing = array_map('intval', array_column(
            DB::select("SELECT id FROM proxies WHERE id IN ({$ph}){$ownSql}", array_merge($ids, $ownParams)),
            'id'
        ));
        if (!$existing) {
            return $this->error('没有找到要删除的代理', 404);
        }
        $ph2 = implode(',', array_fill(0, count($existing), '?'));
        $unbound = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM accounts WHERE proxy_id IN ({$ph2})", $existing)['c'] ?? 0);
        DB::query("DELETE FROM proxies WHERE id IN ({$ph2})", $existing);
        return $this->success([
            'requested' => count($ids),
            'deleted' => count($existing),
            'notFound' => count($ids) - count($existing),
            'unboundAccounts' => $unbound,
        ], '批量删除完成');
    }

    /** 将未关联代理分配给运营专员或企业客户（仅超管/运营管理） */
    public function batchAssignOwner(Request $request): Response
    {
        if (!DataScope::canAssignProxyOwners()) {
            return $this->error('当前角色无权分配代理', 403);
        }
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('proxyIds', []), 1000);
        $targetOwnerId = (int)$request->input('ownerId', $request->input('userId', 0));
        if (!$ids) {
            return $this->error('请选择代理', 400);
        }
        if ($targetOwnerId < 1) {
            return $this->error('请选择目标管理用户', 422);
        }

        $targets = DataScope::proxyAssignTargets();
        $allowedIds = array_map(static fn($t) => (int)($t['id'] ?? 0), $targets);
        if (!in_array($targetOwnerId, $allowedIds, true)) {
            return $this->error('只能分配给运营专员或企业客户', 422);
        }

        $target = DB::table('admin_users')->where('id', $targetOwnerId)->first();
        if (!$target || (int)($target['status'] ?? 0) !== 1) {
            return $this->error('目标管理用户不存在或已停用', 404);
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->proxyScopeSql('owner_id');
        $existing = array_map('intval', array_column(
            DB::select("SELECT id, owner_id FROM proxies WHERE id IN ({$ph}){$ownSql}", array_merge($ids, $ownParams)),
            'id'
        ));
        if (!$existing) {
            return $this->error('没有可分配的代理（可能不在当前数据范围内）', 404);
        }

        $ph2 = implode(',', array_fill(0, count($existing), '?'));
        $rows = DB::select("SELECT id, owner_id FROM proxies WHERE id IN ({$ph2})", $existing);
        $candidateIds = [];
        foreach ($rows as $row) {
            $oid = (int)($row['owner_id'] ?? 0);
            if ($oid <= 0) {
                $candidateIds[] = (int)$row['id'];
            }
        }
        if (!$candidateIds) {
            return $this->error('所选代理均已关联用户，仅可分配未关联的代理', 422);
        }

        $bindLimit = self::PROXY_BIND_LIMIT;
        $phBind = implode(',', array_fill(0, count($candidateIds), '?'));
        $bindRows = DB::select(
            "SELECT proxy_id, COUNT(*) AS account_bind_count FROM accounts WHERE proxy_id IN ({$phBind}) GROUP BY proxy_id",
            $candidateIds
        ) ?: [];
        $bindMap = [];
        foreach ($bindRows as $br) {
            $bindMap[(int)($br['proxy_id'] ?? 0)] = (int)($br['account_bind_count'] ?? 0);
        }
        $assignable = [];
        foreach ($candidateIds as $pid) {
            if (($bindMap[$pid] ?? 0) < $bindLimit) {
                $assignable[] = $pid;
            }
        }
        if (!$assignable) {
            return $this->error(
                '所选代理均已关联用户或已绑定满额账号，仅可分配未关联且绑定账号少于' . $bindLimit . '个的代理',
                422
            );
        }

        $ph3 = implode(',', array_fill(0, count($assignable), '?'));
        $stmt = DB::query(
            "UPDATE proxies SET owner_id=?, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph3})",
            array_merge([$targetOwnerId], $assignable)
        );

        return $this->success([
            'requested' => count($ids),
            'updated' => $stmt->rowCount(),
            'ownerId' => $targetOwnerId,
            'ownerName' => $target['nickname'] ?: $target['username'],
        ], '代理已分配给 ' . ($target['nickname'] ?: $target['username']));
    }

    /**
     * 清空代理关联用户（owner_id → NULL），恢复为公共池。
     * 仅登录身份为超管/运营管理可执行；不受 Acting-As 数据范围限制。
     */
    public function batchUnassignOwner(Request $request): Response
    {
        if (!DataScope::isLoginSuper() && !DataScope::isLoginOpsAdmin()) {
            return $this->error('仅超级管理员或运营管理可清空代理关联用户', 403);
        }
        if (!filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOLEAN)) {
            return $this->error('请确认操作（confirm=true）', 422);
        }
        $this->ensureSchema();
        DataScope::ensureColumns();

        $ids = $this->idList($request->input('proxyIds', []), 10000);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = DB::query(
                "UPDATE proxies SET owner_id=NULL, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph}) AND owner_id IS NOT NULL AND owner_id > 0",
                $ids
            );
        } else {
            $stmt = DB::query(
                'UPDATE proxies SET owner_id=NULL, updated_at=CURRENT_TIMESTAMP WHERE owner_id IS NOT NULL AND owner_id > 0',
                []
            );
        }
        $cleared = (int)$stmt->rowCount();

        return $this->success(['cleared' => $cleared], "已清空 {$cleared} 条代理的关联用户");
    }

    public function advancedDelete(Request $request): Response
    {
        if ($err = $this->assertCanEditProxies()) {
            return $err;
        }
        DataScope::ensureColumns();
        $filters = [];
        $params = [];
        $status = trim((string)$request->input('status', ''));
        if (in_array($status, ['unchecked', 'available', 'unavailable'], true)) {
            $filters[] = 'status=?';
            $params[] = $status;
        }
        $country = trim((string)$request->input('country', ''));
        if ($country !== '') {
            $filters[] = 'LOWER(country)=LOWER(?)';
            $params[] = $country;
        }
        $minUse = (int)$request->input('minUse', 0);
        if ($minUse > 0) {
            $filters[] = 'use_count>=?';
            $params[] = $minUse;
        }
        if (!$filters) {
            return $this->error('请至少选择一个删除条件', 400);
        }
        if (!DataScope::applyProxyDeletableScope('owner_id', $filters, $params)) {
            return $this->error('当前身份无权执行高级删除', 403);
        }
        $stmt = DB::query('DELETE FROM proxies WHERE ' . implode(' AND ', $filters), $params);
        return $this->success(['deleted' => $stmt->rowCount()], '已删除 ' . $stmt->rowCount() . ' 条代理');
    }

    public function exportStrings(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('proxyIds', []), 5000);
        $all = filter_var($request->input('all', false), FILTER_VALIDATE_BOOLEAN);
        if (!$ids && !$all) {
            return $this->error('请先勾选要复制的代理，或显式确认导出全部', 400);
        }
        [$ownSql, $ownParams] = $this->proxyScopeSql('owner_id');
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select(
                "SELECT id, host, port, username, password, password_encrypted FROM proxies WHERE id IN ({$ph}){$ownSql}",
                array_merge($ids, $ownParams)
            );
        } else {
            $rows = DB::select(
                "SELECT id, host, port, username, password, password_encrypted FROM proxies WHERE 1=1{$ownSql} ORDER BY id DESC",
                $ownParams
            );
        }
        $items = [];
        foreach ($rows as $row) {
            $password = $row['password_encrypted'] ? SecretStore::decrypt((string)$row['password_encrypted']) : (string)($row['password'] ?? '');
            $text = $row['host'] . ':' . $row['port'];
            if (($row['username'] ?? '') !== '' && $password !== '') {
                $text .= ':' . $row['username'] . ':' . $password;
            } elseif (($row['username'] ?? '') !== '') {
                $text .= ':' . $row['username'] . ':';
            }
            $items[] = ['id' => (int)$row['id'], 'text' => $text];
        }
        return $this->success([
            'items' => $items,
            'text' => implode("\n", array_column($items, 'text')),
            'count' => count($items),
        ]);
    }

    /** 自定义字段导出 CSV */
    public function exportCsv(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $filters = [];
        $params = [];
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') {
            $filters[] = '(p.name LIKE ? OR p.host LIKE ? OR p.country LIKE ?)';
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }
        $status = trim((string)$request->get('status', ''));
        if ($status !== '') {
            $filters[] = 'p.status=?';
            $params[] = $status;
        }
        $country = trim((string)$request->get('country', ''));
        if ($country !== '') {
            $filters[] = '(p.country=? OR p.region=?)';
            $params[] = $country;
            $params[] = $country;
        }
        $protocol = trim((string)$request->get('protocol', ''));
        if ($protocol !== '' && in_array($protocol, ['http', 'https', 'socks5'], true)) {
            $filters[] = 'p.protocol=?';
            $params[] = $protocol;
        }
        $groupId = $request->get('groupId');
        if ($groupId === 'none') {
            $filters[] = 'p.group_id IS NULL';
        } elseif ($groupId !== null && $groupId !== '') {
            $filters[] = 'p.group_id=?';
            $params[] = (int)$groupId;
        }
        $idMin = (int)$request->get('idMin', $request->get('id_min', 0));
        $idMax = (int)$request->get('idMax', $request->get('id_max', 0));
        if ($idMin > 0) {
            $filters[] = 'p.id >= ?';
            $params[] = $idMin;
        }
        if ($idMax > 0) {
            $filters[] = 'p.id <= ?';
            $params[] = $idMax;
        }
        $idsRaw = trim((string)$request->get('ids', ''));
        if ($idsRaw !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $filters[] = "p.id IN ({$ph})";
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
        }
        DataScope::applyProxyListScope('p.owner_id', $filters, $params);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $rows = DB::select(
            "SELECT p.*, g.name AS group_name FROM proxies p LEFT JOIN `groups` g ON g.id=p.group_id {$where} ORDER BY p.id DESC LIMIT 5000",
            $params
        );

        $fieldDefs = $this->exportFieldDefs();
        $fieldsRaw = trim((string)$request->get('fields', ''));
        $headersRaw = trim((string)$request->get('headers', ''));
        $selected = $fieldsRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $fieldsRaw))))
            : array_keys($fieldDefs);
        $customHeaders = [];
        if ($headersRaw !== '') {
            $decoded = json_decode($headersRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $customHeaders[(string)$k] = trim((string)$v);
                }
            } else {
                $parts = array_map('trim', explode(',', $headersRaw));
                foreach ($selected as $i => $f) {
                    if (isset($parts[$i]) && $parts[$i] !== '') {
                        $customHeaders[$f] = $parts[$i];
                    }
                }
            }
        }
        $cols = [];
        foreach ($selected as $f) {
            if (!isset($fieldDefs[$f])) {
                continue;
            }
            $cols[] = [
                'key' => $f,
                'label' => (isset($customHeaders[$f]) && $customHeaders[$f] !== '')
                    ? $customHeaders[$f]
                    : $fieldDefs[$f],
            ];
        }
        if (!$cols) {
            foreach ($fieldDefs as $k => $label) {
                $cols[] = ['key' => $k, 'label' => $label];
            }
        }

        $esc = static fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"';
        $lines = [implode(',', array_map(static fn($c) => '"' . str_replace('"', '""', $c['label']) . '"', $cols))];
        foreach ($rows as $r) {
            $mapped = $this->mapExportRow($r);
            $line = [];
            foreach ($cols as $c) {
                $line[] = $esc($mapped[$c['key']] ?? '');
            }
            $lines[] = implode(',', $line);
        }
        return Response::make("\xEF\xBB\xBF" . implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="tkswarm-proxies.csv"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** 导入模板 CSV */
    public function importTemplate(Request $request): Response
    {
        $lines = [
            'protocol,host,port,username,password,name,country,group_name',
            'http,1.2.3.4,8080,user,pass,示例代理,US,',
            'socks5,5.6.7.8,1080,,,,示例SOCKS,',
        ];
        return Response::make("\xEF\xBB\xBF" . implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="proxy-import-template.csv"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * CSV 模板导入：protocol,host,port,username,password,name,country,group_name
     * body: { content }
     */
    public function import(Request $request): Response
    {
        if ($err = $this->assertCanImportProxies()) {
            return $err;
        }
        $this->ensureSchema();
        DataScope::ensureColumns();
        $content = (string)$request->input('content', '');
        if (trim($content) === '') {
            return $this->error('请粘贴或上传 CSV 内容', 422);
        }
        $defaultProtocol = (string)$request->input('defaultProtocol', 'http');
        if (!in_array($defaultProtocol, ['http', 'https', 'socks5'], true)) {
            $defaultProtocol = 'http';
        }
        $defaultGroupId = $request->input('groupId');
        $defaultGroupId = ($defaultGroupId === null || $defaultGroupId === '') ? null : (int)$defaultGroupId;
        $defaultCountry = trim((string)$request->input('country', ''));
        $ownerId = DataScope::proxyInsertOwnerId();

        $lines = preg_split('/\r?\n/', $content) ?: [];
        $header = null;
        $map = [];
        $result = ['total' => 0, 'imported' => 0, 'duplicates' => 0, 'ignored' => 0, 'errors' => []];
        $groupCache = [];

        foreach ($lines as $index => $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, "\xEF\xBB\xBF")) {
                $line = substr($line, 3);
            }
            $cols = str_getcsv($line);
            if ($header === null) {
                $header = array_map(static fn($h) => strtolower(trim((string)$h)), $cols);
                // 兼容中文表头
                $alias = [
                    '协议' => 'protocol', '主机' => 'host', '地址' => 'host', '端口' => 'port',
                    '用户名' => 'username', '密码' => 'password', '名称' => 'name', '国家' => 'country',
                    '分组' => 'group_name', '分组名' => 'group_name',
                ];
                foreach ($header as $i => $h) {
                    if (isset($alias[$h])) {
                        $header[$i] = $alias[$h];
                    }
                }
                foreach ($header as $i => $h) {
                    $map[$h] = $i;
                }
                if (!isset($map['host']) && !isset($map['proxy_string']) && !isset($map['text'])) {
                    return $this->error('CSV 需包含 host 列，或使用下载的导入模板', 422);
                }
                continue;
            }

            $get = static function (array $cols, array $map, string $key) {
                if (!isset($map[$key])) {
                    return '';
                }
                return trim((string)($cols[$map[$key]] ?? ''));
            };

            $result['total']++;
            try {
                $proxyString = $get($cols, $map, 'proxy_string') ?: $get($cols, $map, 'text');
                if ($proxyString !== '') {
                    $parsed = self::parseLine($proxyString, $defaultProtocol);
                } else {
                    $host = $get($cols, $map, 'host');
                    $port = (int)$get($cols, $map, 'port');
                    if ($host === '' || $port < 1) {
                        throw new \InvalidArgumentException('host/port 无效');
                    }
                    $protocol = strtolower($get($cols, $map, 'protocol') ?: $defaultProtocol);
                    if (!in_array($protocol, ['http', 'https', 'socks5'], true)) {
                        $protocol = $defaultProtocol;
                    }
                    $parsed = [
                        'protocol' => $protocol,
                        'host' => $host,
                        'port' => $port,
                        'username' => $get($cols, $map, 'username'),
                        'password' => $get($cols, $map, 'password'),
                    ];
                }
                $name = $get($cols, $map, 'name');
                if ($name === '') {
                    $name = $parsed['host'] . ':' . $parsed['port'];
                }
                $country = $get($cols, $map, 'country') ?: $defaultCountry;
                $groupName = $get($cols, $map, 'group_name');
                $groupId = $defaultGroupId;
                if ($groupName !== '') {
                    if (!isset($groupCache[$groupName])) {
                        $g = DB::selectOne("SELECT id FROM `groups` WHERE type='proxy' AND name=? LIMIT 1", [$groupName]);
                        $groupCache[$groupName] = $g ? (int)$g['id'] : null;
                    }
                    if ($groupCache[$groupName]) {
                        $groupId = $groupCache[$groupName];
                    }
                }

                DB::query(
                    "INSERT INTO proxies(name,protocol,host,port,username,password,password_encrypted,country,group_id,status,owner_id)
                     VALUES (?,?,?,?,?,'',?,?,?,'unchecked',?)",
                    [
                        $name,
                        $parsed['protocol'],
                        $parsed['host'],
                        $parsed['port'],
                        $parsed['username'] ?? '',
                        SecretStore::encrypt((string)($parsed['password'] ?? '')),
                        $country,
                        $groupId,
                        $ownerId,
                    ]
                );
                $result['imported']++;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $result['duplicates']++;
                } else {
                    $result['errors'][] = '第 ' . ($index + 1) . ' 行：' . $e->getMessage();
                }
            } catch (\Throwable $e) {
                $result['errors'][] = '第 ' . ($index + 1) . ' 行：' . $e->getMessage();
            }
            if (count($result['errors']) > 50) {
                $result['errors'][] = '错误过多，已截断…';
                break;
            }
        }

        return $this->success($result, "导入完成：成功 {$result['imported']}，重复 {$result['duplicates']}，错误 " . count($result['errors']));
    }

    /** @return array<string,string> */
    private function exportFieldDefs(): array
    {
        return [
            'id' => 'ID',
            'name' => '名称',
            'protocol' => '协议',
            'host' => '主机',
            'port' => '端口',
            'username' => '用户名',
            'password' => '密码',
            'proxy_string' => '代理文本',
            'country' => '国家',
            'group_name' => '分组',
            'status' => '状态',
            'latency_ms' => '延迟ms',
            'use_count' => '使用次数',
            'egress_ip' => '出口IP',
            'created_at' => '创建时间',
            'last_checked_at' => '检测时间',
            'owner_id' => '关联用户ID',
            'owner_label' => '关联用户',
        ];
    }

    /** @param array<string,mixed> $r */
    private function mapExportRow(array $r): array
    {
        $password = '';
        if (!empty($r['password_encrypted'])) {
            $password = (string)SecretStore::decrypt((string)$r['password_encrypted']);
        } elseif (!empty($r['password'])) {
            $password = (string)$r['password'];
        }
        $proxyString = ($r['host'] ?? '') . ':' . ($r['port'] ?? '');
        if (($r['username'] ?? '') !== '' && $password !== '') {
            $proxyString .= ':' . $r['username'] . ':' . $password;
        } elseif (($r['username'] ?? '') !== '') {
            $proxyString .= ':' . $r['username'] . ':';
        }
        return [
            'id' => $r['id'] ?? '',
            'name' => $r['name'] ?? '',
            'protocol' => $r['protocol'] ?? '',
            'host' => $r['host'] ?? '',
            'port' => $r['port'] ?? '',
            'username' => $r['username'] ?? '',
            'password' => $password,
            'proxy_string' => $proxyString,
            'country' => $r['country'] ?? ($r['region'] ?? ''),
            'group_name' => $r['group_name'] ?? '',
            'status' => $r['status'] ?? '',
            'latency_ms' => $r['latency_ms'] ?? '',
            'use_count' => $r['use_count'] ?? 0,
            'egress_ip' => $r['egress_ip'] ?? '',
            'created_at' => $r['created_at'] ?? '',
            'last_checked_at' => $r['last_checked_at'] ?? '',
            'owner_id' => (int)($r['owner_id'] ?? 0) ?: '',
            'owner_label' => (string)($r['owner_label'] ?? ''),
        ];
    }

    public function test(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $proxy = DB::selectOne('SELECT * FROM proxies WHERE id=?', [(int)$id]);
        if ($err = $this->assertProxyRowAccess($proxy)) {
            return $err;
        }
        $result = $this->tcpProbe($proxy);
        DB::query(
            'UPDATE proxies SET status=?, latency_ms=?, last_checked_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$result['available'] ? 'available' : 'unavailable', $result['latencyMs'], (int)$id]
        );
        return $this->success($result, $result['available'] ? $result['detail'] : '代理不可用');
    }

    public function batchTest(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('proxyIds', []), 80);
        [$ownSql, $ownParams] = $this->proxyScopeSql('owner_id');
        if (!$ids) {
            $ids = array_map('intval', array_column(
                DB::select("SELECT id FROM proxies WHERE 1=1{$ownSql} ORDER BY id DESC LIMIT 30", $ownParams),
                'id'
            ));
        }
        $items = [];
        $available = 0;
        foreach ($ids as $id) {
            $proxy = DB::selectOne('SELECT * FROM proxies WHERE id=?', [$id]);
            if ($err = $this->assertProxyRowAccess($proxy)) {
                $items[] = ['id' => $id, 'available' => false, 'detail' => '代理不存在或无权访问'];
                continue;
            }
            $result = $this->tcpProbe($proxy);
            DB::query(
                'UPDATE proxies SET status=?, latency_ms=?, last_checked_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$result['available'] ? 'available' : 'unavailable', $result['latencyMs'], $id]
            );
            if ($result['available']) {
                $available++;
            }
            $items[] = array_merge(['id' => $id], $result);
        }
        $unavailable = count($items) - $available;
        return $this->success([
            'total' => count($items),
            'available' => $available,
            'unavailable' => $unavailable,
            'items' => $items,
        ], "批量检测完成：可用 {$available}，不可用 {$unavailable}");
    }

    public function fetch(Request $request): Response
    {
        if ($err = $this->assertCanEditProxies()) {
            return $err;
        }
        $this->ensureSchema();
        DataScope::ensureColumns();
        $count = min(50, max(1, (int)$request->input('count', 5)));
        $groupId = $request->input('groupId');
        $groupId = ($groupId === null || $groupId === '') ? null : (int)$groupId;
        $country = trim((string)$request->input('country', ''));
        $rotate = (int)$request->input('rotateMinutes', 0);

        $cloudUrl = trim((string)Translator::setting('cloudProxyApiUrl', ''));
        if ($cloudUrl !== '') {
            try {
                $cloud = $this->fetchCloudProxies($cloudUrl, [
                    'count' => $count,
                    'groupId' => $groupId,
                    'country' => $country,
                    'rotateMinutes' => $rotate,
                ]);
                if (!empty($cloud['items'])) {
                    return $this->success($cloud, $cloud['message'] ?? '已从云端获取代理');
                }
            } catch (\Throwable $e) {
                // 云端失败则回落本地池
                $fallback = $this->fetchLocalPool($count, $groupId, $country, $rotate);
                if ($fallback) {
                    $fallback['cloudError'] = $e->getMessage();
                    return $this->success($fallback, '云端失败，已从本地可用池分配：' . $e->getMessage());
                }
                return $this->error('云代理失败且本地池无可用：' . $e->getMessage(), 422);
            }
        }

        $local = $this->fetchLocalPool($count, $groupId, $country, $rotate);
        if (!$local) {
            return $this->error(
                $cloudUrl === ''
                    ? '本地代理池没有符合条件的代理，请先导入或检测；也可在设置中配置云代理 API'
                    : '云端无新增且本地池无可用代理',
                422
            );
        }
        return $this->success($local, $cloudUrl === '' ? '未配置云代理 API，已从本地可用池分配' : '云端无新增，已从本地可用池分配');
    }

    /** @return array{items:list<array>,count:int,source:string,message?:string}|null */
    private function fetchLocalPool(int $count, ?int $groupId, string $country, int $rotate): ?array
    {
        $filters = ["status IN ('available','unchecked')"];
        $params = [];
        if ($groupId) {
            $filters[] = 'group_id=?';
            $params[] = $groupId;
        }
        if ($country !== '') {
            $filters[] = '(country=? OR region=?)';
            $params[] = $country;
            $params[] = $country;
        }
        DataScope::applyProxyListScope('owner_id', $filters, $params);
        $params[] = $count;
        $rows = DB::select(
            'SELECT * FROM proxies WHERE ' . implode(' AND ', $filters) . ' ORDER BY use_count ASC, id ASC LIMIT ?',
            $params
        );
        if (!$rows) {
            return null;
        }
        foreach ($rows as $row) {
            DB::query('UPDATE proxies SET use_count=use_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$row['id']]);
        }
        $items = array_map(fn($row) => [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'protocol' => $row['protocol'],
            'host' => $row['host'],
            'port' => (int)$row['port'],
            'country' => $row['country'] ?? '',
            'source' => 'local-pool',
            'rotateMinutes' => $rotate,
        ], $rows);
        return [
            'items' => $items,
            'count' => count($items),
            'source' => 'local-pool',
        ];
    }

    /** @param array{count:int,groupId:?int,country:string,rotateMinutes:int} $options */
    private function fetchCloudProxies(string $apiUrl, array $options): array
    {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($options, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException($err !== '' ? $err : '云代理无响应');
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("云代理接口失败 {$code}");
        }
        $data = json_decode($raw, true);
        $list = $data['data'] ?? $data['items'] ?? $data;
        if (!is_array($list) || !$list) {
            throw new \RuntimeException('云代理未返回可用 IP');
        }
        $saved = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $host = trim((string)($item['host'] ?? ''));
            $port = (int)($item['port'] ?? 0);
            if ($host === '' || $port < 1) {
                continue;
            }
            $name = trim((string)($item['name'] ?? "{$host}:{$port}"));
            $protocol = trim((string)($item['protocol'] ?? 'http')) ?: 'http';
            $username = trim((string)($item['username'] ?? ''));
            $password = trim((string)($item['password'] ?? ''));
            $country = trim((string)($item['country'] ?? $options['country'] ?? ''));
            $region = trim((string)($item['region'] ?? ''));
            $city = trim((string)($item['city'] ?? ''));
            $groupId = $options['groupId'];
            $rotate = (int)($options['rotateMinutes'] ?? 0);
            $ownerId = DataScope::proxyInsertOwnerId();
            try {
                DB::query(
                    "INSERT INTO proxies(name,protocol,host,port,username,password,country,group_id,status,rotate_minutes,region,city,owner_id)
                     VALUES (?,?,?,?,?,?,?,?,'unchecked',?,?,?,?)",
                    [$name, $protocol, $host, $port, $username, $password, $country, $groupId, $rotate, $region, $city, $ownerId]
                );
                $id = (int)DB::getPdo()->lastInsertId();
                if ($id > 0) {
                    $saved[] = [
                        'id' => $id,
                        'name' => $name,
                        'protocol' => $protocol,
                        'host' => $host,
                        'port' => $port,
                        'country' => $country,
                        'source' => 'cloud',
                        'rotateMinutes' => $rotate,
                    ];
                }
            } catch (\Throwable $e) {
                // 重复忽略
            }
        }
        if ($saved) {
            return [
                'items' => $saved,
                'count' => count($saved),
                'source' => 'cloud',
                'message' => '已从云端获取 ' . count($saved) . ' 个代理',
            ];
        }
        return ['items' => [], 'count' => 0, 'source' => 'cloud'];
    }

    public function geo(Request $request): Response
    {
        DataScope::ensureColumns();
        [$ownSql, $ownParams] = $this->proxyScopeSql('owner_id');
        $countries = DB::select("SELECT DISTINCT country AS name FROM proxies WHERE country<>''{$ownSql} ORDER BY country", $ownParams);
        $regions = [];
        $cities = [];
        try {
            $regions = DB::select("SELECT DISTINCT region AS name FROM proxies WHERE region<>''{$ownSql} ORDER BY region", $ownParams);
            $cities = DB::select("SELECT DISTINCT city AS name FROM proxies WHERE city<>''{$ownSql} ORDER BY city", $ownParams);
        } catch (\Throwable $e) {
        }
        return $this->success(['countries' => $countries, 'regions' => $regions, 'cities' => $cities]);
    }

    public static function parseLine(string $source, string $defaultProtocol = 'http'): array
    {
        $line = trim($source);
        if ($line === '' || str_starts_with($line, '#')) {
            throw new \InvalidArgumentException('空行');
        }
        $protocol = $defaultProtocol;
        $host = '';
        $port = 0;
        $username = '';
        $password = '';
        if (str_contains($line, '://')) {
            $url = parse_url($line);
            if (!$url || empty($url['host'])) {
                throw new \InvalidArgumentException('URL 格式不正确');
            }
            $protocol = strtolower((string)($url['scheme'] ?? $defaultProtocol));
            if (!in_array($protocol, ['http', 'https', 'socks5'], true)) {
                throw new \InvalidArgumentException('不支持的代理协议');
            }
            $host = trim((string)$url['host'], '[]');
            $port = (int)($url['port'] ?? 0);
            $username = urldecode((string)($url['user'] ?? ''));
            $password = urldecode((string)($url['pass'] ?? ''));
        } elseif (str_contains($line, '@')) {
            $at = strrpos($line, '@');
            $auth = explode(':', substr($line, 0, $at));
            $address = explode(':', substr($line, $at + 1));
            if (count($auth) < 2 || count($address) !== 2) {
                throw new \InvalidArgumentException('认证代理格式应为 用户名:密码@主机:端口');
            }
            $username = array_shift($auth);
            $password = implode(':', $auth);
            $host = $address[0];
            $port = (int)$address[1];
        } else {
            $sep = str_contains($line, '|') ? '|' : (str_contains($line, ',') ? ',' : ':');
            $parts = array_values(array_filter(array_map('trim', explode($sep, $line)), fn($v) => $v !== ''));
            if (count($parts) === 2) {
                [$host, $port] = [$parts[0], (int)$parts[1]];
            } elseif (count($parts) === 4) {
                [$host, $port, $username, $password] = [$parts[0], (int)$parts[1], $parts[2], $parts[3]];
            } elseif (count($parts) === 5 && in_array(strtolower($parts[0]), ['http', 'https', 'socks5'], true)) {
                $protocol = strtolower($parts[0]);
                $host = $parts[1];
                $port = (int)$parts[2];
                $username = $parts[3];
                $password = $parts[4];
            } else {
                throw new \InvalidArgumentException('支持 主机:端口 或 主机:端口:用户名:密码');
            }
        }
        $host = trim($host);
        if ($host === '') {
            throw new \InvalidArgumentException('主机不能为空');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('端口必须在 1-65535 之间');
        }
        return compact('protocol', 'host', 'port', 'username', 'password');
    }

    private function tcpProbe(array $proxy): array
    {
        $start = microtime(true);
        $errno = 0;
        $err = '';
        $fp = @fsockopen((string)$proxy['host'], (int)$proxy['port'], $errno, $err, 3.0);
        if (is_resource($fp)) {
            fclose($fp);
            $ms = (int)round((microtime(true) - $start) * 1000);
            return ['available' => true, 'latencyMs' => $ms, 'egressIp' => '', 'detail' => '端口可连接'];
        }
        return ['available' => false, 'latencyMs' => null, 'egressIp' => '', 'detail' => $err !== '' ? $err : '连接失败'];
    }

    private function body(Request $request): array
    {
        $host = trim((string)$request->input('host', ''));
        $port = (int)$request->input('port', 0);
        $protocol = (string)$request->input('protocol', 'http');
        if (!in_array($protocol, ['http', 'https', 'socks5'], true)) {
            $protocol = 'http';
        }
        $groupId = $request->input('groupId', $request->input('group_id'));
        return [
            'name' => trim((string)$request->input('name', $host . ':' . $port)) ?: ($host . ':' . $port),
            'protocol' => $protocol,
            'host' => $host,
            'port' => $port,
            'username' => trim((string)$request->input('username', '')),
            'password' => (string)$request->input('password', ''),
            'country' => trim((string)$request->input('country', '')),
            'group_id' => ($groupId === null || $groupId === '') ? null : (int)$groupId,
        ];
    }

    private function publicRow(array $row): array
    {
        $has = !empty($row['password_encrypted']) || !empty($row['password']);
        unset($row['password'], $row['password_encrypted']);
        $row['hasPassword'] = $has;
        return $row;
    }

    private function idList(mixed $ids, int $max): array
    {
        if (!is_array($ids)) {
            return [];
        }
        return array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, $max);
    }

    /** 是否可按指定归属用户列举代理（账号编辑下拉等） */
    private function canListProxiesOfOwner(int $targetOwnerId): bool
    {
        if ($targetOwnerId < 1) {
            return false;
        }
        if (DataScope::isSuper() || DataScope::isOpsAdmin()) {
            return true;
        }
        return DataScope::isProxyOwnerVisible($targetOwnerId);
    }

    private function assertCanEditProxies(): ?Response
    {
        if (!DataScope::canEditProxies()) {
            return $this->error('当前角色无权维护代理', 403);
        }
        return null;
    }

    private function assertCanCreateProxies(): ?Response
    {
        if (!DataScope::canCreateProxies()) {
            return $this->error('当前角色无权创建代理', 403);
        }
        return null;
    }

    private function assertCanImportProxies(): ?Response
    {
        if (!DataScope::canCreateProxies()) {
            return $this->error('当前角色无权导入代理', 403);
        }
        if (!DataScope::canImportProxies()) {
            return $this->error('当前角色无权导入代理', 403);
        }
        return null;
    }

    private function assertProxyRowAccess(?array $row, string $field = 'owner_id'): ?Response
    {
        if (!$row) {
            return $this->error('资源不存在', 404);
        }
        if (!DataScope::isProxyRowVisible($row)) {
            return $this->error('无权访问该资源', 403);
        }
        return null;
    }

    private function assertCanEditProxyRow(?array $row, string $field = 'owner_id'): ?Response
    {
        if ($err = $this->assertProxyRowAccess($row, $field)) {
            return $err;
        }
        if (!DataScope::canEditProxyRow($row, $field)) {
            return $this->error('当前角色无权编辑该代理', 403);
        }
        return null;
    }

    /** @param list<array<string,mixed>> $rows */
    private function enrichOwnerMeta(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $ownerIds = [];
        foreach ($rows as $r) {
            $oid = (int)($r['owner_id'] ?? 0);
            if ($oid > 0) {
                $ownerIds[] = $oid;
            }
        }
        if (!$ownerIds) {
            return $rows;
        }
        $ph = implode(',', array_fill(0, count($ownerIds), '?'));
        $users = DB::select(
            "SELECT id, username, nickname FROM admin_users WHERE id IN ({$ph})",
            array_values(array_unique($ownerIds))
        ) ?: [];
        $userMap = [];
        foreach ($users as $u) {
            $userMap[(int)$u['id']] = $u;
        }
        foreach ($rows as &$row) {
            $oid = (int)($row['owner_id'] ?? 0);
            $u = $userMap[$oid] ?? null;
            if ($u) {
                $row['owner_username'] = (string)($u['username'] ?? '');
                $row['owner_label'] = (string)(($u['nickname'] ?? '') !== '' ? $u['nickname'] : ($u['username'] ?? ''));
            } else {
                $row['owner_username'] = '';
                $row['owner_label'] = '';
            }
        }
        unset($row);
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    private function enrichAccountBindCounts(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $ids = [];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            return $rows;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $counts = DB::select(
            "SELECT proxy_id, COUNT(*) AS account_bind_count FROM accounts WHERE proxy_id IN ({$ph}) GROUP BY proxy_id",
            $ids
        ) ?: [];
        $map = [];
        foreach ($counts as $c) {
            $map[(int)($c['proxy_id'] ?? 0)] = (int)($c['account_bind_count'] ?? 0);
        }
        foreach ($rows as &$row) {
            $row['account_bind_count'] = $map[(int)($row['id'] ?? 0)] ?? 0;
        }
        unset($row);
        return $rows;
    }

    /**
     * 浏览器/脚本审计：列出没有有效密码的代理（表 proxies）。
     * GET /api/debug/proxies-missing-password
     * format=html（默认）| json
     */
    public function missingPasswordAudit(Request $request): Response
    {
        $this->ensureSchema();

        $rows = DB::select(
            'SELECT p.id, p.name, p.protocol, p.host, p.port, p.username, p.password, p.password_encrypted,'
            . ' p.country, p.status, p.owner_id, p.created_at, p.updated_at,'
            . ' (SELECT COUNT(*) FROM accounts a WHERE a.proxy_id = p.id) AS bound_accounts'
            . ' FROM proxies p ORDER BY p.id ASC'
        ) ?: [];

        $missing = [];
        $ok = [];
        foreach ($rows as $row) {
            $effective = $this->effectiveProxyPassword($row);
            $hasUser = trim((string)($row['username'] ?? '')) !== '';
            $item = [
                'id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? ''),
                'protocol' => (string)($row['protocol'] ?? ''),
                'host' => (string)($row['host'] ?? ''),
                'port' => (int)($row['port'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'country' => (string)($row['country'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'ownerId' => isset($row['owner_id']) ? (int)$row['owner_id'] : null,
                'boundAccounts' => (int)($row['bound_accounts'] ?? 0),
                'hasUsername' => $hasUser,
                'hasPassword' => $effective !== '',
                'issue' => '',
            ];
            if ($effective !== '') {
                $ok[] = $item;
                continue;
            }
            if ($hasUser) {
                $item['issue'] = '有用户名但无密码（SOCKS 极易握手失败）';
            } elseif (trim((string)($row['password_encrypted'] ?? '')) !== '') {
                $item['issue'] = 'password_encrypted 存在但解密为空（密钥不匹配或数据损坏）';
            } elseif (trim((string)($row['password'] ?? '')) !== '') {
                $item['issue'] = '明文 password 字段异常为空';
            } else {
                $item['issue'] = '完全未配置密码';
            }
            $missing[] = $item;
        }

        $summary = [
            'table' => 'proxies',
            'passwordColumns' => ['password', 'password_encrypted'],
            'total' => count($rows),
            'withPassword' => count($ok),
            'missingPassword' => count($missing),
            'missingWithUsername' => count(array_filter($missing, static fn ($x) => $x['hasUsername'])),
            'missingBoundAccounts' => array_sum(array_column($missing, 'boundAccounts')),
            'generatedAt' => date('c'),
        ];

        if (strtolower((string)$request->get('format', 'html')) === 'json') {
            return $this->success([
                'summary' => $summary,
                'missing' => $missing,
                'okSample' => array_slice($ok, 0, 5),
            ], '代理密码审计');
        }

        return Response::make(
            $this->renderMissingPasswordHtml($summary, $missing),
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    private function effectiveProxyPassword(array $row): string
    {
        $plain = trim((string)($row['password'] ?? ''));
        if ($plain !== '') {
            return $plain;
        }
        $enc = trim((string)($row['password_encrypted'] ?? ''));
        if ($enc === '') {
            return '';
        }
        return trim(SecretStore::decrypt($enc));
    }

    private function renderMissingPasswordHtml(array $summary, array $missing): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($missing as $item) {
            $rows .= '<tr>'
                . '<td>' . $esc((string)$item['id']) . '</td>'
                . '<td>' . $esc((string)$item['name']) . '</td>'
                . '<td>' . $esc((string)$item['protocol']) . '</td>'
                . '<td>' . $esc((string)$item['host']) . ':' . $esc((string)$item['port']) . '</td>'
                . '<td>' . $esc((string)$item['username']) . '</td>'
                . '<td>' . $esc((string)$item['country']) . '</td>'
                . '<td>' . $esc((string)$item['status']) . '</td>'
                . '<td>' . $esc((string)$item['boundAccounts']) . '</td>'
                . '<td>' . $esc((string)$item['issue']) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="9" style="text-align:center;color:#16a34a">全部代理均有有效密码</td></tr>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>代理密码审计</title>'
            . '<style>'
            . 'body{font-family:system-ui,"Microsoft YaHei",sans-serif;margin:0;padding:24px;background:#f8fafc;color:#0f172a}'
            . 'h1{margin:0 0 8px;font-size:22px}.sub{color:#64748b;margin:0 0 20px}'
            . '.cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px}'
            . '.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;min-width:140px}'
            . '.card b{display:block;font-size:22px;margin-top:4px}'
            . 'table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}'
            . 'th,td{padding:10px 12px;border-bottom:1px solid #f1f5f9;text-align:left;font-size:13px}'
            . 'th{background:#f1f5f9;font-weight:600}tr:hover td{background:#fafafa}'
            . 'code{background:#f1f5f9;padding:2px 6px;border-radius:4px}'
            . '</style></head><body>'
            . '<h1>代理密码审计</h1>'
            . '<p class="sub">数据表 <code>proxies</code> · 密码列 <code>password</code> / <code>password_encrypted</code> · '
            . $esc($summary['generatedAt']) . '</p>'
            . '<div class="cards">'
            . '<div class="card">总数<b>' . $esc((string)$summary['total']) . '</b></div>'
            . '<div class="card">有密码<b style="color:#16a34a">' . $esc((string)$summary['withPassword']) . '</b></div>'
            . '<div class="card">缺密码<b style="color:#dc2626">' . $esc((string)$summary['missingPassword']) . '</b></div>'
            . '<div class="card">缺密码且有用户名<b style="color:#ea580c">' . $esc((string)$summary['missingWithUsername']) . '</b></div>'
            . '<div class="card">缺密码代理绑定账号数<b>' . $esc((string)$summary['missingBoundAccounts']) . '</b></div>'
            . '</div>'
            . '<table><thead><tr>'
            . '<th>ID</th><th>名称</th><th>协议</th><th>地址</th><th>用户名</th><th>国家</th><th>状态</th><th>绑定账号</th><th>问题</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="sub" style="margin-top:16px">JSON：<code>?format=json</code></p>'
            . '</body></html>';
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $cols = array_column(DB::select('SHOW COLUMNS FROM proxies') ?: [], 'Field');
        $alters = [
            'use_count' => 'ALTER TABLE proxies ADD COLUMN use_count INT NOT NULL DEFAULT 0',
            'password_encrypted' => 'ALTER TABLE proxies ADD COLUMN password_encrypted TEXT NULL',
            'egress_ip' => "ALTER TABLE proxies ADD COLUMN egress_ip VARCHAR(64) NOT NULL DEFAULT ''",
            'latency_ms' => 'ALTER TABLE proxies ADD COLUMN latency_ms INT NULL',
            'group_id' => 'ALTER TABLE proxies ADD COLUMN group_id INT NULL',
            'rotate_minutes' => 'ALTER TABLE proxies ADD COLUMN rotate_minutes INT NOT NULL DEFAULT 0',
            'region' => "ALTER TABLE proxies ADD COLUMN region VARCHAR(64) NOT NULL DEFAULT ''",
            'city' => "ALTER TABLE proxies ADD COLUMN city VARCHAR(64) NOT NULL DEFAULT ''",
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
}
