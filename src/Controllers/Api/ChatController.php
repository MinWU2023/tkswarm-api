<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountWorkBatchService;
use App\Services\AgentChatService;
use App\Services\DataScope;
use App\Services\Translator;

class ChatController extends Controller
{
    public function accounts(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $includeId = (int)$request->get('includeId', $request->get('accountId', 0));

        [$where, $params] = $this->chatAccountListFilters($request);
        $from = 'FROM accounts a
             LEFT JOIN chat_connections c ON c.account_id=a.id
             LEFT JOIN agents ag ON ag.id=c.agent_id';
        $select = "SELECT a.id,a.username,a.nickname,a.login_status,a.chat_status,a.browser_profile_id,a.owner_id,a.enabled,
                COALESCE(c.status,'offline') AS connection_status,
                COALESCE(c.auto_translate,0) AS auto_translate,
                COALESCE(c.sync_messages,1) AS sync_messages,
                COALESCE(c.ai_hosting,0) AS ai_hosting,
                c.agent_id, ag.name AS agent_name, c.connected_at,
                (SELECT COUNT(*) FROM chat_users f WHERE f.account_id=a.id) AS friend_count,
                (SELECT COALESCE(SUM(f.unread_count),0) FROM chat_users f WHERE f.account_id=a.id) AS unread_count";

        $stats = DB::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN a.login_status='online' THEN 1 ELSE 0 END) AS online
             {$from} {$where}",
            $params
        );
        $unreadRow = DB::selectOne(
            "SELECT COALESCE(SUM(f.unread_count),0) AS unread
             FROM chat_users f
             INNER JOIN accounts a ON a.id=f.account_id
             {$where}",
            $params
        );
        $stats['unread'] = (int)($unreadRow['unread'] ?? 0);

        $total = (int)($stats['total'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $order = "ORDER BY CASE a.login_status WHEN 'online' THEN 0 WHEN 'checking' THEN 1 ELSE 2 END, a.id DESC";
        $rows = DB::select(
            "{$select} {$from} {$where} {$order} LIMIT {$pageSize} OFFSET {$offset}",
            $params
        );

        $selected = null;
        if ($includeId > 0) {
            $onPage = false;
            foreach ($rows as $x) {
                if ((int)($x['id'] ?? 0) === $includeId) {
                    $onPage = true;
                    $selected = $x;
                    break;
                }
            }
            if (!$onPage) {
                $oneFilters = ['a.id=?'];
                $oneParams = [$includeId];
                $ownerFilterId = $this->resolveChatAccountOwnerFilterId($request);
                if ($ownerFilterId !== null) {
                    $oneFilters[] = 'a.owner_id = ?';
                    $oneParams[] = $ownerFilterId;
                } else {
                    DataScope::applyAccountListScope('a.owner_id', $oneFilters, $oneParams);
                }
                $oneWhere = 'WHERE ' . implode(' AND ', $oneFilters);
                $selected = DB::selectOne("{$select} {$from} {$oneWhere} LIMIT 1", $oneParams);
            }
        }

        return $this->success([
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => $pageSize,
            'keyword' => $keyword,
            'selected' => $selected,
            'stats' => [
                'total' => $total,
                'online' => (int)($stats['online'] ?? 0),
                'unread' => (int)($stats['unread'] ?? 0),
            ],
        ]);
    }

    /**
     * 与账号列表同一套可见范围（accountListScopeSql / ownerId 筛选），
     * 保证各角色管理用户在客服聚合与账号页看到的账号集合一致。
     *
     * @return array{0:string,1:list<mixed>}
     */
    private function chatAccountListFilters(Request $request): array
    {
        $filters = [];
        $params = [];
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') {
            $filters[] = '(a.username LIKE ? OR a.nickname LIKE ? OR CAST(a.id AS CHAR) LIKE ?)';
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }
        $groupId = $request->get('groupId');
        if ($groupId !== null && $groupId !== '') {
            if ($groupId === 'none' || $groupId === '0') {
                $filters[] = 'a.group_id IS NULL';
            } else {
                $gids = GroupController::expandAccountGroupIds((int)$groupId);
                if (count($gids) === 1) {
                    $filters[] = 'a.group_id=?';
                    $params[] = $gids[0];
                } elseif ($gids) {
                    $ph = implode(',', array_fill(0, count($gids), '?'));
                    $filters[] = "a.group_id IN ({$ph})";
                    array_push($params, ...$gids);
                }
            }
        }
        $status = trim((string)$request->get('status', ''));
        if ($status !== '') {
            $filters[] = 'a.login_status=?';
            $params[] = $status;
        }

        $ownerFilterId = $this->resolveChatAccountOwnerFilterId($request);
        if ($ownerFilterId !== null) {
            $filters[] = 'a.owner_id = ?';
            $params[] = $ownerFilterId;
        } else {
            DataScope::applyAccountListScope('a.owner_id', $filters, $params);
        }

        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        return [$where, $params];
    }

    private function resolveChatAccountOwnerFilterId(Request $request): ?int
    {
        $ownerId = (int)$request->get('ownerId', $request->get('owner_id', 0));
        if ($ownerId <= 0) {
            return null;
        }
        if (DataScope::canUseAccountOwnerFilter() && DataScope::isAccountOwnerVisible($ownerId)) {
            return $ownerId;
        }
        if (!DataScope::isEnterpriseCustomer() && !DataScope::isOperator()) {
            $loginId = (int)(DataScope::loginUser()['id'] ?? 0);
            if ($loginId > 0 && ($ownerId === $loginId || in_array($ownerId, DataScope::childIdsOf($loginId), true))) {
                return $ownerId;
            }
        }
        return null;
    }

    /**
     * 客服坐席读/同步：与客服账号列表（chatAccountListFilters → applyAccountListScope）同一套可见范围。
     * 账号在列表里能看到，就能查看粉丝/关注、刷新、查状态；不再走另一套单行归属判断。
     */
    private function assertChatAccountVisible(?array $account): ?Response
    {
        if (!$account) {
            return $this->error('资源不存在', 404);
        }
        $filters = ['a.id=?'];
        $params = [(int)($account['id'] ?? 0)];
        DataScope::applyAccountListScope('a.owner_id', $filters, $params);
        $row = DB::selectOne('SELECT a.id FROM accounts a WHERE ' . implode(' AND ', $filters), $params);
        if (!$row) {
            return $this->error('无权访问该资源', 403);
        }
        return null;
    }

    /**
     * 本控制器内 assertOwns 的对象都是客服好友行（chat_users 联 accounts）。
     * 好友能否访问，跟其所属账号在客服账号列表里是否可见保持一致，不再要求 owner_id 严格等于当前用户。
     */
    protected function assertOwns(?array $row, string $field = 'owner_id'): ?Response
    {
        if (!$row) {
            return $this->error('资源不存在', 404);
        }
        $accountId = (int)($row['account_id'] ?? 0);
        if ($accountId < 1 && (int)($row['id'] ?? 0) > 0) {
            $f = DB::selectOne('SELECT account_id FROM chat_users WHERE id=?', [(int)$row['id']]);
            $accountId = (int)($f['account_id'] ?? 0);
        }
        if ($accountId < 1) {
            return $this->error('资源不存在', 404);
        }
        return $this->assertChatAccountVisible(['id' => $accountId]);
    }

    public function connect(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id,username,browser_profile_id,login_status,owner_id FROM accounts WHERE id=? AND enabled=1', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        if (($account['browser_profile_id'] ?? '') === '') {
            return $this->error('账号尚未绑定浏览器环境', 422);
        }
        if (($account['login_status'] ?? '') !== 'online') {
            return $this->error('请先完成 TikTok 登录后再登录客服', 422);
        }
        $auto = filter_var($request->input('autoTranslate', false), FILTER_VALIDATE_BOOLEAN);
        $sync = filter_var($request->input('syncMessages', true), FILTER_VALIDATE_BOOLEAN);
        $this->ensureConnection((int)$id);
        DB::query(
            "UPDATE chat_connections SET status='online', auto_translate=?, sync_messages=?, connected_at=CURRENT_TIMESTAMP, last_error='', updated_at=CURRENT_TIMESTAMP WHERE account_id=?",
            [$auto ? 1 : 0, $sync ? 1 : 0, (int)$id]
        );
        DB::query("UPDATE accounts SET chat_status='online', updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$id]);
        return $this->success(['accountId' => (int)$id, 'username' => $account['username'], 'status' => 'online'], '已登录客服聊天');
    }

    public function disconnect(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id,username,owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $this->ensureConnection((int)$id);
        DB::query("UPDATE chat_connections SET status='offline', updated_at=CURRENT_TIMESTAMP WHERE account_id=?", [(int)$id]);
        DB::query("UPDATE accounts SET chat_status='offline', updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$id]);
        return $this->success(['accountId' => (int)$id, 'username' => $account['username'], 'status' => 'offline'], '已退出客服聊天');
    }

    public function batchConnect(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('accountIds', []));
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }
        $auto = filter_var($request->input('autoTranslate', false), FILTER_VALIDATE_BOOLEAN);
        $sync = filter_var($request->input('syncMessages', true), FILTER_VALIDATE_BOOLEAN);
        $connected = 0;
        $errors = [];
        foreach ($ids as $id) {
            $account = DB::selectOne('SELECT id,username,browser_profile_id,login_status,owner_id FROM accounts WHERE id=? AND enabled=1', [$id]);
            if (!$account || !DataScope::accountOwnsRow($account)) {
                $errors[] = ['id' => $id, 'reason' => '账号不存在或无权访问'];
                continue;
            }
            if (($account['browser_profile_id'] ?? '') === '') {
                $errors[] = ['id' => $id, 'reason' => '未绑定浏览器'];
                continue;
            }
            if (($account['login_status'] ?? '') !== 'online') {
                $errors[] = ['id' => $id, 'reason' => '未登录 TikTok'];
                continue;
            }
            $this->ensureConnection($id);
            DB::query(
                "UPDATE chat_connections SET status='online', auto_translate=?, sync_messages=?, connected_at=CURRENT_TIMESTAMP, last_error='', updated_at=CURRENT_TIMESTAMP WHERE account_id=?",
                [$auto ? 1 : 0, $sync ? 1 : 0, $id]
            );
            DB::query("UPDATE accounts SET chat_status='online', updated_at=CURRENT_TIMESTAMP WHERE id=?", [$id]);
            $connected++;
        }
        return $this->success(['connected' => $connected, 'failed' => count($errors), 'errors' => $errors], '批量登录完成');
    }

    public function batchDisconnect(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('accountIds', []));
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }
        $closed = 0;
        foreach ($ids as $id) {
            $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$id]);
            if (!$account || !DataScope::accountOwnsRow($account)) {
                continue;
            }
            $this->ensureConnection($id);
            DB::query("UPDATE chat_connections SET status='offline', updated_at=CURRENT_TIMESTAMP WHERE account_id=?", [$id]);
            DB::query("UPDATE accounts SET chat_status='offline', updated_at=CURRENT_TIMESTAMP WHERE id=?", [$id]);
            $closed++;
        }
        return $this->success(['closed' => $closed], '批量退出完成');
    }

    public function friends(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $global = (string)$request->get('scope', '') === 'global';
        $accountId = (int)$request->get('accountId', 0);
        if (!$global && $accountId < 1) {
            return $this->error('请选择账号', 400);
        }
        if (!$global) {
            $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
            if ($err = $this->assertChatAccountVisible($account)) {
                return $err;
            }
        }
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(200, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;
        $filter = (string)$request->get('filter', 'all');
        $keyword = trim((string)$request->get('keyword', ''));
        $tag = trim((string)$request->get('tag', ''));
        $filters = [];
        $params = [];
        if (!$global) {
            $filters[] = 'f.account_id=?';
            $params[] = $accountId;
        }
        if ($filter === 'unread') {
            $filters[] = 'f.unread_count > 0';
        } elseif ($filter === 'unreplied') {
            $filters[] = "f.last_direction='in'";
        } elseif ($filter === 'waiting' || $filter === 'friendUnreplied') {
            $filters[] = "f.last_direction='out'";
        } elseif ($filter === 'mutual') {
            $filters[] = "f.relation='mutual'";
        } elseif ($filter === 'new') {
            $filters[] = 'f.is_new=1';
        } elseif ($filter === 'stranger') {
            $filters[] = "f.relation='stranger'";
        } elseif ($filter === 'follower') {
            $filters[] = "f.relation IN ('follower','mutual')";
        } elseif ($filter === 'following') {
            $filters[] = "f.relation IN ('following','mutual')";
        } elseif ($filter === 'blocked') {
            $filters[] = "f.relation='blocked'";
        } elseif ($filter === 'pinned') {
            $filters[] = 'f.is_pinned=1';
        }
        if ($keyword !== '') {
            $filters[] = '(f.username LIKE ? OR f.nickname LIKE ? OR f.friend_uid LIKE ? OR a.username LIKE ?)';
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }
        if ($tag !== '') {
            $filters[] = "CONCAT(',', REPLACE(IFNULL(f.tags,''), ' ', ''), ',') LIKE ?";
            $params[] = '%,' . str_replace(' ', '', $tag) . ',%';
        }
        // 与客服账号列表 / 账号页同一套可见范围（勿用旧 applyOwnerFilter）
        DataScope::applyAccountListScope('a.owner_id', $filters, $params);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $from = 'FROM chat_users f JOIN accounts a ON a.id=f.account_id';
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c {$from} {$where}", $params)['c'] ?? 0);
        $items = DB::select(
            "SELECT f.*, a.username AS account_username {$from} {$where} ORDER BY f.is_pinned DESC, f.last_message_at DESC, f.id DESC LIMIT {$pageSize} OFFSET {$offset}",
            $params
        );
        return $this->success([
            'items' => array_map([$this, 'mapFriend'], $items),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /** 兼容旧路径 /conversations */
    public function conversations(Request $request): Response
    {
        if (!$request->get('accountId') && $request->get('account_id')) {
            $_GET['accountId'] = $request->get('account_id');
        }
        return $this->friends($request);
    }

    public function createFriend(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $accountId = (int)$request->input('accountId', 0);
        $friendUid = trim((string)$request->input('friendUid', $request->input('uid', '')));
        $username = trim((string)$request->input('username', ''));
        $nickname = trim((string)$request->input('nickname', ''));
        if ($accountId < 1 || $friendUid === '') {
            return $this->error('请指定账号与好友 UID', 422);
        }
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $ownerId = $this->writeOwnerId();
        $relation = trim((string)$request->input('relation', 'stranger')) ?: 'stranger';
        $userType = trim((string)$request->input('userType', $request->input('user_type', $relation))) ?: 'dm';
        try {
            DB::query(
                'INSERT INTO chat_users(account_id, user_type, friend_uid, username, nickname, relation, is_new, owner_id) VALUES (?,?,?,?,?,?,1,?)',
                [$accountId, $userType, $friendUid, $username ?: $friendUid, $nickname, $relation, $ownerId]
            );
            $id = (int)DB::getPdo()->lastInsertId();
        } catch (\Throwable $e) {
            $row = DB::selectOne('SELECT * FROM chat_users WHERE account_id=? AND friend_uid=?', [$accountId, $friendUid]);
            return $this->success($this->mapFriend($row), '好友已存在');
        }
        $row = DB::selectOne('SELECT * FROM chat_users WHERE id=?', [$id]);
        return $this->success($this->mapFriend($row), '好友已添加');
    }

    public function patchFriend(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $friend = DB::selectOne(
            'SELECT f.*, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=?',
            [(int)$id]
        );
        if ($err = $this->assertOwns($friend)) {
            return $err;
        }
        $clearTranslate = $request->input('clearTranslate', $request->input('clear_translate'));
        if ($clearTranslate !== null && filter_var($clearTranslate, FILTER_VALIDATE_BOOLEAN)) {
            DB::query(
                'UPDATE chat_users SET translate_in=0, translate_out=0, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [(int)$id]
            );
        } else {
            $sets = [];
            $params = [];
            foreach (['nickname', 'relation', 'tags', 'translate_target', 'translate_source'] as $col) {
                $camel = match ($col) {
                    'translate_target' => 'translateTarget',
                    'translate_source' => 'translateSource',
                    default => $col,
                };
                $val = $request->input($camel, $request->input($col));
                if ($val !== null) {
                    $sets[] = "{$col}=?";
                    $params[] = is_array($val) ? implode(',', $val) : (string)$val;
                }
            }
            foreach (['is_pinned' => 'isPinned', 'is_new' => 'isNew', 'translate_in' => 'translateIn', 'translate_out' => 'translateOut'] as $col => $camel) {
                $v = $request->input($camel, $request->input($col));
                if ($v !== null) {
                    $sets[] = "{$col}=?";
                    $params[] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }
            }
            if ($sets) {
                $params[] = (int)$id;
                DB::query('UPDATE chat_users SET ' . implode(',', $sets) . ', updated_at=CURRENT_TIMESTAMP WHERE id=?', $params);
            }
        }
        $row = DB::selectOne('SELECT * FROM chat_users WHERE id=?', [(int)$id]);
        return $this->success($this->mapFriend($row), '好友已更新');
    }

    public function deleteFriend(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $friend = DB::selectOne(
            'SELECT f.id, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=?',
            [(int)$id]
        );
        if ($err = $this->assertOwns($friend)) {
            return $err;
        }
        DB::query('DELETE FROM chat_records WHERE user_id=?', [(int)$id]);
        DB::query('DELETE FROM chat_users WHERE id=?', [(int)$id]);
        return $this->success(['deleted' => true], '会话已删除');
    }

    public function readFriend(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $friend = DB::selectOne(
            'SELECT f.id, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=?',
            [(int)$id]
        );
        if ($err = $this->assertOwns($friend)) {
            return $err;
        }
        DB::query('UPDATE chat_users SET unread_count=0, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
        return $this->success(null, '已标记已读');
    }

    public function readAll(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        DB::query('UPDATE chat_users SET unread_count=0, updated_at=CURRENT_TIMESTAMP WHERE account_id=?', [(int)$id]);
        return $this->success(null, '全部已读');
    }

    public function messages(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $friendId = (int)$request->get('friendId', $request->get('friend_id', 0));
        if ($friendId < 1) {
            return $this->error('请选择好友', 400);
        }
        $friend = DB::selectOne(
            'SELECT f.*, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=?',
            [$friendId]
        );
        if ($err = $this->assertOwns($friend)) {
            return $err;
        }
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(200, max(1, (int)$request->get('pageSize', 50)));
        $offset = ($page - 1) * $pageSize;
        $total = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM chat_records WHERE user_id=?', [$friendId])['c'] ?? 0);
        $items = DB::select(
            "SELECT * FROM chat_records WHERE user_id=? ORDER BY id DESC LIMIT {$pageSize} OFFSET {$offset}",
            [$friendId]
        );
        $items = array_reverse($items);
        return $this->success([
            'friend' => $this->mapFriend($friend),
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    public function send(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $friendId = (int)$request->input('friendId', $request->input('friend_id', 0));
        $content = trim((string)$request->input('content', $request->input('text', '')));
        $msgType = (string)$request->input('msgType', 'text');
        if ($friendId < 1 || $content === '') {
            return $this->error('消息内容不能为空', 422);
        }
        $friend = DB::selectOne(
            'SELECT f.*, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=?',
            [$friendId]
        );
        if ($err = $this->assertOwns($friend)) {
            return $err;
        }
        $conn = DB::selectOne('SELECT status FROM chat_connections WHERE account_id=?', [(int)$friend['account_id']]);
        if (!$conn || ($conn['status'] ?? '') !== 'online') {
            return $this->error('请先登录客服聊天', 422);
        }
        if (($friend['relation'] ?? '') === 'blocked') {
            return $this->error('对方已拉黑，无法发送', 422);
        }
        // 与 Node 对齐：content=原文，translated=译文。
        // 这里只是本地落库（不经 TikTok 投递），翻译失败不该阻断消息记录。
        $translated = '';
        if ($msgType === 'text' && !empty($friend['translate_out'])) {
            $peerLang = strtolower(trim((string)($friend['lang'] ?? '')));
            if (!in_array($peerLang, ['zh', 'en', 'ja', 'ko', 'es'], true)) {
                $peerLang = 'en';
            }
            if ($peerLang !== 'zh') {
                try {
                    $tr = Translator::translate($content, 'zh', $peerLang);
                    $translated = (string)($tr['text'] ?? '');
                } catch (\Throwable $e) {
                    // 翻译未启用/失败时留空，真实投递由 Node 侧实时翻译
                    $translated = '';
                }
            }
        }
        DB::query(
            "INSERT INTO chat_records(account_id, user_id, direction, msg_type, content, translated, status, owner_id)
             VALUES (?,?, 'out', ?, ?, ?, 'sent', ?)",
            [(int)$friend['account_id'], $friendId, $msgType, $content, $translated, $this->writeOwnerId()]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        $preview = mb_substr(($translated !== '' ? $translated : $content), 0, 200);
        DB::query(
            "UPDATE chat_users SET last_message=?, last_direction='out', last_message_at=CURRENT_TIMESTAMP, is_new=0, unread_count=0, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$preview, $friendId]
        );
        $message = DB::selectOne('SELECT * FROM chat_records WHERE id=?', [$id]);
        return $this->success($message, $translated !== '' ? '消息已发送（含译文）' : '消息已发送');
    }

    public function aiReply(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $friendId = (int)$request->input('friendId', $request->input('friend_id', 0));
        $agentId = (int)$request->input('agentId', $request->input('agent_id', 0));
        if ($friendId < 1) {
            return $this->error('请选择好友', 422);
        }
        $friend = DB::selectOne(
            'SELECT f.*, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=?',
            [$friendId]
        );
        if ($err = $this->assertOwns($friend)) {
            return $err;
        }
        if (($friend['relation'] ?? '') === 'blocked') {
            return $this->error('对方已拉黑，无法生成回复', 422);
        }
        $conn = DB::selectOne('SELECT * FROM chat_connections WHERE account_id=?', [(int)$friend['account_id']]);
        if (!$conn || ($conn['status'] ?? '') !== 'online') {
            return $this->error('请先登录客服聊天', 422);
        }
        if ($agentId < 1) {
            $agentId = (int)($conn['agent_id'] ?? 0);
        }
        $agent = null;
        if ($agentId > 0) {
            $agent = DB::selectOne('SELECT * FROM agents WHERE id=? AND enabled=1', [$agentId]);
        }
        if (!$agent) {
            $agent = DB::selectOne('SELECT * FROM agents WHERE enabled=1 ORDER BY id DESC LIMIT 1');
        }
        if (!$agent) {
            return $this->error('请先在 AI智能体页创建并启用智能体', 422);
        }

        $rows = DB::select(
            "SELECT direction, msg_type, content FROM chat_records
             WHERE user_id=? AND msg_type='text'
             ORDER BY id DESC LIMIT 24",
            [$friendId]
        );
        $rows = array_reverse($rows);
        if (!$rows) {
            return $this->error('暂无聊天记录，无法生成回复', 422);
        }

        $history = [];
        $userText = '';
        $lastInboundIdx = null;
        foreach ($rows as $i => $row) {
            if (($row['direction'] ?? '') === 'in') {
                $lastInboundIdx = $i;
            }
        }
        if ($lastInboundIdx === null) {
            return $this->error('暂无对方消息，无法生成回复', 422);
        }
        foreach ($rows as $i => $row) {
            $content = trim((string)($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($i === $lastInboundIdx) {
                $userText = $content;
                continue;
            }
            if ($i > $lastInboundIdx) {
                continue;
            }
            $history[] = [
                'role' => ($row['direction'] ?? '') === 'out' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }
        if ($userText === '') {
            return $this->error('对方最近消息为空，无法生成回复', 422);
        }

        try {
            $reply = AgentChatService::reply($agent, $userText, $history);
        } catch (\Throwable $e) {
            return $this->error('AI 回复失败：' . ($e->getMessage() ?: '未知错误'), 422);
        }
        return $this->success([
            'reply' => $reply,
            'agentId' => (int)$agent['id'],
            'agentName' => (string)($agent['name'] ?? ''),
        ]);
    }

    public function searchUser(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $global = (string)$request->get('scope', '') === 'global';
        $accountId = (int)$request->get('accountId', $request->get('account_id', 0));
        $keyword = trim((string)$request->get('keyword', ''));
        if (!$global && $accountId < 1) {
            return $this->error('请选择账号', 400);
        }
        if ($keyword === '') {
            return $this->error('请输入用户名或 UID', 400);
        }
        if (!$global) {
            $account = DB::selectOne('SELECT id, username, owner_id FROM accounts WHERE id=?', [$accountId]);
            if ($err = $this->assertAccountOwns($account)) {
                return $err;
            }
        }
        $like = '%' . $keyword . '%';
        if ($global) {
            $filters = ['(f.username LIKE ? OR f.nickname LIKE ? OR f.friend_uid LIKE ?)'];
            $params = [$like, $like, $like];
            $this->applyOwnerFilter('a.owner_id', $filters, $params);
            $rows = DB::select(
                'SELECT f.*, a.username AS account_username FROM chat_users f
                 JOIN accounts a ON a.id=f.account_id
                 WHERE ' . implode(' AND ', $filters) . '
                 ORDER BY f.is_pinned DESC, f.last_message_at DESC LIMIT 50',
                $params
            );
            $exactFilters = ['(f.friend_uid=? OR f.username=?)'];
            $exactParams = [$keyword, $keyword];
            $this->applyOwnerFilter('a.owner_id', $exactFilters, $exactParams);
            $exact = DB::selectOne(
                'SELECT f.id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE ' . implode(' AND ', $exactFilters) . ' LIMIT 1',
                $exactParams
            );
        } else {
            $rows = DB::select(
                "SELECT * FROM chat_users WHERE account_id=? AND (
                    username LIKE ? OR nickname LIKE ? OR friend_uid LIKE ?
                ) ORDER BY is_pinned DESC, last_message_at DESC LIMIT 50",
                [$accountId, $like, $like, $like]
            );
            $exact = DB::selectOne(
                'SELECT id FROM chat_users WHERE account_id=? AND (friend_uid=? OR username=?) LIMIT 1',
                [$accountId, $keyword, $keyword]
            );
        }
        $items = array_map(fn($r) => $this->mapFriend($r), $rows);
        return $this->success([
            'keyword' => $keyword,
            'items' => $items,
            'canAdd' => $global ? true : !$exact,
            'candidate' => ($global || !$exact)
                ? ['friendUid' => $keyword, 'username' => $keyword, 'nickname' => '', 'relation' => 'stranger']
                : null,
            'note' => $global
                ? '先查全部账号本地好友。加入时需指定客服账号，不会假装已从 TikTok 搜到。'
                : '先查本地好友。未命中时可一键加入本地会话，不会假装已从 TikTok 搜到该用户。',
        ]);
    }

    public function simulateIn(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $friendId = (int)$request->input('friendId', $request->input('friend_id', 0));
        $content = trim((string)$request->input('content', ''));
        if ($friendId < 1 || $content === '') {
            return $this->error('请选择好友并填写内容', 422);
        }
        $friend = DB::selectOne(
            'SELECT f.*, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=?',
            [$friendId]
        );
        if ($err = $this->assertOwns($friend)) {
            return $err;
        }
        $inboundContent = $content;
        $translated = '';
        $conn = DB::selectOne('SELECT * FROM chat_connections WHERE account_id=?', [(int)$friend['account_id']]);
        $shouldTranslate = !empty($friend['translate_in'])
            || (empty($friend['translate_in']) && empty($friend['translate_out']) && !empty($conn['auto_translate']));
        if ($shouldTranslate) {
            // 接收方向：对方语种 → 中文。对方语种未获取（空）时按英语处理。
            $peerLang = strtolower(trim((string)($friend['lang'] ?? '')));
            if (!in_array($peerLang, ['zh', 'en', 'ja', 'ko', 'es'], true)) {
                $peerLang = 'en';
            }
            try {
                $tr = Translator::translate(
                    $content,
                    $peerLang,
                    'zh'
                );
                $translated = (string)($tr['text'] ?? '');
            } catch (\Throwable $e) {
                $translated = '[失败] ' . $e->getMessage();
            }
        }
        DB::query(
            "INSERT INTO chat_records(account_id, user_id, direction, msg_type, content, translated, status, owner_id)
             VALUES (?,?, 'in', 'text', ?, ?, 'sent', ?)",
            [(int)$friend['account_id'], $friendId, $inboundContent, $translated, $this->writeOwnerId()]
        );
        $msgId = (int)DB::getPdo()->lastInsertId();
        $previewSrc = ($translated !== '' && !str_starts_with($translated, '[失败]')) ? $translated : $inboundContent;
        DB::query(
            "UPDATE chat_users SET last_message=?, last_direction='in', last_message_at=CURRENT_TIMESTAMP, unread_count=unread_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [mb_substr($previewSrc, 0, 200), $friendId]
        );
        $message = DB::selectOne('SELECT * FROM chat_records WHERE id=?', [$msgId]);

        $autoReply = null;
        if (
            !empty($conn['ai_hosting'])
            && !empty($conn['agent_id'])
            && ($conn['status'] ?? '') === 'online'
            && ($friend['relation'] ?? '') !== 'blocked'
        ) {
            try {
                $agent = DB::selectOne('SELECT * FROM agents WHERE id=? AND enabled=1', [(int)$conn['agent_id']]);
                if ($agent) {
                    try {
                        $reply = \App\Services\AgentChatService::reply($agent, $content);
                    } catch (\Throwable $e) {
                        $name = trim((string)($agent['name'] ?? '助手'));
                        $reply = "您好，我是「{$name}」。关于「" . mb_substr($content, 0, 40) . '」已收到。';
                    }
                    DB::query(
                        "INSERT INTO chat_records(account_id, user_id, direction, msg_type, content, status, owner_id)
                         VALUES (?,?, 'out', 'text', ?, 'sent', ?)",
                        [(int)$friend['account_id'], $friendId, $reply, $this->writeOwnerId()]
                    );
                    $outId = (int)DB::getPdo()->lastInsertId();
                    DB::query(
                        "UPDATE chat_users SET last_message=?, last_direction='out', last_message_at=CURRENT_TIMESTAMP, is_new=0, unread_count=0, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                        [mb_substr($reply, 0, 200), $friendId]
                    );
                    $autoReply = DB::selectOne('SELECT * FROM chat_records WHERE id=?', [$outId]);
                }
            } catch (\Throwable $e) {
                // AI 托管失败不影响模拟收信
            }
        }
        return $this->success(['message' => $message, 'autoReply' => $autoReply], '已模拟收到消息');
    }

    public function batchSync(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('accountIds', $request->input('account_ids', [])));
        $syncMessages = $request->input('syncMessages', $request->input('sync_messages'));
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }
        if ($syncMessages === null) {
            return $this->error('请指定 syncMessages', 400);
        }
        $flag = filter_var($syncMessages, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $updated = 0;
        foreach ($ids as $aid) {
            $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$aid]);
            if (!$account || $this->assertAccountOwns($account)) {
                continue;
            }
            $this->ensureConnection($aid);
            DB::query(
                'UPDATE chat_connections SET sync_messages=?, updated_at=CURRENT_TIMESTAMP WHERE account_id=?',
                [$flag, $aid]
            );
            $updated++;
        }
        return $this->success(
            ['updated' => $updated],
            $flag ? '已开启同步消息标记' : '已取消同步消息标记'
        );
    }

    public function refreshFans(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne(
            'SELECT id, username, browser_profile_id, tkswarm_browser_profile_id, linux_browser_profile_id, owner_id FROM accounts WHERE id=?',
            [(int)$id]
        );
        if ($err = $this->assertChatAccountVisible($account)) {
            return $err;
        }
        $hasEnv = trim((string)($account['browser_profile_id'] ?? '')) !== ''
            || trim((string)($account['tkswarm_browser_profile_id'] ?? '')) !== ''
            || trim((string)($account['linux_browser_profile_id'] ?? '')) !== '';
        if (!$hasEnv) {
            return $this->error('账号尚未绑定自研/服务器环境包', 422);
        }
        $maxPerRun = max(50, min(2000, (int)Translator::setting('fansSyncMaxPerRun', 500)));
        // 坐席粉丝同步：直接入队服务器浏览器 cs_seat 分任务
        try {
            $out = AccountWorkBatchService::enqueueItem(
                (int)$id,
                'sync_fans',
                [
                    'browserBackend' => 'linux',
                    'browser_backend' => 'linux',
                    'accountIds' => [(int)$id],
                    'maxPerRun' => $maxPerRun,
                    'listKind' => 'followers',
                    'source' => 'chat.refresh-fans',
                    'seatAction' => 'sync_fans',
                ],
                0,
                $this->writeOwnerId()
            );
            $batchId = (int)($out['batch_id'] ?? 0);
            $itemId = (int)($out['item_id'] ?? 0);
            if ($batchId > 0) {
                AccountWorkBatchService::appendLog(
                    $batchId,
                    $itemId,
                    "客服坐席：账号#{$id} 粉丝同步已入队服务器浏览器（linux / cs_seat）",
                    'info',
                    (int)$id,
                    0,
                    ['seatAction' => 'sync_fans', 'source' => 'chat.refresh-fans']
                );
            }
            return $this->success([
                'taskId' => $itemId,
                'batchId' => $batchId,
                'itemId' => $itemId,
                'status' => 'queued',
                'accountId' => (int)$id,
                'maxPerRun' => $maxPerRun,
                'browserBackend' => 'linux',
                'batchScope' => 'cs_seat',
            ], '已推送粉丝同步到服务器浏览器，请保持 Node + Linux EL 在线');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function refreshFansStatus(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertChatAccountVisible($account)) {
            return $err;
        }
        $taskId = (int)$request->get('taskId', 0);
        $localFollowers = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM chat_users WHERE account_id=? AND relation IN ('follower','mutual')",
            [(int)$id]
        )['c'] ?? 0);
        // 优先查坐席 work item
        if ($taskId > 0) {
            $item = AccountWorkBatchService::getWorkItem($taskId);
            if ($item && (int)($item['account_id'] ?? 0) === (int)$id && ($item['task_type'] ?? '') === 'sync_fans') {
                $result = json_decode((string)($item['result_json'] ?? '{}'), true) ?: [];
                return $this->success([
                    'accountId' => (int)$id,
                    'taskId' => $taskId,
                    'itemId' => $taskId,
                    'batchId' => (int)($item['batch_id'] ?? 0),
                    'status' => (string)($item['status'] ?? 'pending'),
                    'progress' => $result['progress'] ?? null,
                    'localFollowerFriends' => $localFollowers,
                    'error' => (string)($item['error_message'] ?? ''),
                ]);
            }
        }
        $task = $taskId > 0
            ? DB::selectOne('SELECT * FROM tasks WHERE id=? AND type=?', [$taskId, 'sync_fans'])
            : $this->findTaskByAccountId('sync_fans', (int)$id, null, 'chat.refresh-fans');
        if (!$task) {
            return $this->success([
                'accountId' => (int)$id,
                'status' => 'idle',
                'progress' => null,
                'localFollowerFriends' => $localFollowers,
            ]);
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $progress = $payload['fansProgress'] ?? null;
        return $this->success([
            'accountId' => (int)$id,
            'taskId' => (int)$task['id'],
            'status' => $task['status'],
            'successCount' => (int)($task['success_count'] ?? 0),
            'failCount' => (int)($task['fail_count'] ?? 0),
            'progress' => $progress,
            'localFollowerFriends' => $localFollowers,
            'error' => $payload['lastError'] ?? null,
        ]);
    }

    public function refreshFollowing(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne(
            'SELECT id, username, browser_profile_id, tkswarm_browser_profile_id, linux_browser_profile_id, owner_id FROM accounts WHERE id=?',
            [(int)$id]
        );
        if ($err = $this->assertChatAccountVisible($account)) {
            return $err;
        }
        $hasEnv = trim((string)($account['browser_profile_id'] ?? '')) !== ''
            || trim((string)($account['tkswarm_browser_profile_id'] ?? '')) !== ''
            || trim((string)($account['linux_browser_profile_id'] ?? '')) !== '';
        if (!$hasEnv) {
            return $this->error('账号尚未绑定自研/服务器环境包', 422);
        }
        $maxPerRun = max(50, min(2000, (int)Translator::setting('fansSyncMaxPerRun', 500)));
        try {
            $out = AccountWorkBatchService::enqueueItem(
                (int)$id,
                'sync_fans',
                [
                    'browserBackend' => 'linux',
                    'browser_backend' => 'linux',
                    'accountIds' => [(int)$id],
                    'maxPerRun' => $maxPerRun,
                    'listKind' => 'following',
                    'source' => 'chat.refresh-following',
                    'seatAction' => 'sync_following',
                ],
                0,
                $this->writeOwnerId()
            );
            $batchId = (int)($out['batch_id'] ?? 0);
            $itemId = (int)($out['item_id'] ?? 0);
            if ($batchId > 0) {
                AccountWorkBatchService::appendLog(
                    $batchId,
                    $itemId,
                    "客服坐席：账号#{$id} 关注同步已入队服务器浏览器（linux / cs_seat）",
                    'info',
                    (int)$id,
                    0,
                    ['seatAction' => 'sync_following', 'source' => 'chat.refresh-following']
                );
            }
            return $this->success([
                'taskId' => $itemId,
                'batchId' => $batchId,
                'itemId' => $itemId,
                'status' => 'queued',
                'accountId' => (int)$id,
                'maxPerRun' => $maxPerRun,
                'browserBackend' => 'linux',
            ], '已推送关注同步到服务器浏览器，请保持 Node + Linux EL 在线');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function refreshFollowingStatus(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertChatAccountVisible($account)) {
            return $err;
        }
        $taskId = (int)$request->get('taskId', 0);
        $localFollowing = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM chat_users WHERE account_id=? AND relation IN ('following','mutual')",
            [(int)$id]
        )['c'] ?? 0);
        if ($taskId > 0) {
            $item = AccountWorkBatchService::getWorkItem($taskId);
            if ($item && (int)($item['account_id'] ?? 0) === (int)$id && ($item['task_type'] ?? '') === 'sync_fans') {
                $result = json_decode((string)($item['result_json'] ?? '{}'), true) ?: [];
                return $this->success([
                    'accountId' => (int)$id,
                    'taskId' => $taskId,
                    'itemId' => $taskId,
                    'batchId' => (int)($item['batch_id'] ?? 0),
                    'status' => (string)($item['status'] ?? 'pending'),
                    'progress' => $result['progress'] ?? null,
                    'localFollowingFriends' => $localFollowing,
                    'error' => (string)($item['error_message'] ?? ''),
                ]);
            }
        }
        $task = $taskId > 0
            ? DB::selectOne('SELECT * FROM tasks WHERE id=? AND type=?', [$taskId, 'sync_fans'])
            : $this->findTaskByAccountId('sync_fans', (int)$id, null, 'chat.refresh-following');
        if (!$task) {
            return $this->success([
                'accountId' => (int)$id,
                'status' => 'idle',
                'progress' => null,
                'localFollowingFriends' => $localFollowing,
            ]);
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $progress = $payload['followingProgress'] ?? $payload['fansProgress'] ?? null;
        return $this->success([
            'accountId' => (int)$id,
            'taskId' => (int)$task['id'],
            'status' => $task['status'],
            'successCount' => (int)($task['success_count'] ?? 0),
            'failCount' => (int)($task['fail_count'] ?? 0),
            'progress' => $progress,
            'localFollowingFriends' => $localFollowing,
            'error' => $payload['lastError'] ?? null,
        ]);
    }

    public function applyTranslate(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $friend = DB::selectOne(
            'SELECT f.*, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=?',
            [(int)$id]
        );
        if ($err = $this->assertOwns($friend)) {
            return $err;
        }
        $friendIds = array_values(array_filter(array_map('intval', (array)$request->input('friendIds', []))));
        if (!$friendIds) {
            return $this->error('请至少选择一个好友', 422);
        }
        $updated = 0;
        foreach ($friendIds as $fid) {
            $target = DB::selectOne(
                'SELECT f.id, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=? AND f.account_id=?',
                [$fid, (int)$friend['account_id']]
            );
            if (!$target || $this->assertOwns($target)) {
                continue;
            }
            DB::query(
                'UPDATE chat_users SET translate_in=?, translate_out=?, translate_source=?, translate_target=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [
                    (int)$friend['translate_in'],
                    (int)$friend['translate_out'],
                    (string)$friend['translate_source'],
                    (string)$friend['translate_target'],
                    $fid,
                ]
            );
            $updated++;
        }
        return $this->success(['updated' => $updated], "已应用到 {$updated} 个好友");
    }

    public function autoCheckLang(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $friend = DB::selectOne(
            'SELECT f.*, a.owner_id FROM chat_users f JOIN accounts a ON a.id=f.account_id WHERE f.id=?',
            [(int)$id]
        );
        if ($err = $this->assertOwns($friend)) {
            return $err;
        }
        $row = DB::selectOne(
            "SELECT content FROM chat_records WHERE user_id=? AND direction='in' ORDER BY id DESC LIMIT 1",
            [(int)$id]
        );
        $text = (string)($row['content'] ?? '');
        try {
            $detected = Translator::detectLanguage($text);
            DB::query(
                'UPDATE chat_users SET translate_source=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$detected['language'], (int)$id]
            );
            return $this->success($detected, '已识别语言：' . $detected['language']);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function aiHosting(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $enabled = filter_var($request->input('enabled', false), FILTER_VALIDATE_BOOLEAN);
        $agentId = (int)$request->input('agentId', 0);
        $this->ensureConnection((int)$id);
        DB::query(
            'UPDATE chat_connections SET ai_hosting=?, agent_id=?, updated_at=CURRENT_TIMESTAMP WHERE account_id=?',
            [$enabled ? 1 : 0, $enabled && $agentId ? $agentId : null, (int)$id]
        );
        return $this->success(['enabled' => $enabled, 'agentId' => $agentId ?: null], $enabled ? '已开启 AI 托管' : '已关闭 AI 托管');
    }

    public function accountStats(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, username, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $profile = null;
        try {
            $profile = DB::selectOne('SELECT * FROM tiktok_profiles WHERE account_id=?', [(int)$id]);
        } catch (\Throwable $e) {
        }
        $friends = DB::selectOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(unread_count),0) AS unread FROM chat_users WHERE account_id=?",
            [(int)$id]
        );
        return $this->success([
            'username' => $account['username'],
            'profile' => $profile,
            'friends' => [
                'total' => (int)($friends['total'] ?? 0),
                'unread' => (int)($friends['unread'] ?? 0),
            ],
        ]);
    }

    public function tags(Request $request): Response
    {
        $this->ensureSchema();
        $rows = DB::select('SELECT * FROM chat_tags ORDER BY id DESC');
        return $this->success($rows);
    }

    public function createTag(Request $request): Response
    {
        $this->ensureSchema();
        $name = trim((string)$request->input('name', ''));
        if ($name === '') {
            return $this->error('标签名不能为空', 422);
        }
        $color = trim((string)$request->input('color', ''));
        try {
            DB::query('INSERT INTO chat_tags(name, color) VALUES (?,?)', [$name, $color]);
            $id = (int)DB::getPdo()->lastInsertId();
        } catch (\Throwable $e) {
            return $this->error('标签已存在', 409);
        }
        return $this->success(DB::selectOne('SELECT * FROM chat_tags WHERE id=?', [$id]), '标签已创建');
    }

    public function deleteTag(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $tag = DB::selectOne('SELECT id, name FROM chat_tags WHERE id=?', [(int)$id]);
        if (!$tag) {
            return $this->error('标签不存在', 404);
        }
        DB::query('DELETE FROM chat_tags WHERE id=?', [(int)$id]);
        $name = (string)$tag['name'];
        $friends = DB::select('SELECT id, tags FROM chat_users WHERE tags LIKE ?', ['%' . $name . '%']);
        foreach ($friends as $friend) {
            $next = implode(',', array_values(array_filter(
                array_map('trim', explode(',', (string)($friend['tags'] ?? ''))),
                static fn($t) => $t !== '' && $t !== $name
            )));
            DB::query('UPDATE chat_users SET tags=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$next, (int)$friend['id']]);
        }
        return $this->success(['id' => (int)$tag['id']], '标签已删除');
    }

    /** @param list<string>|null $statuses */
    private function findTaskByAccountId(string $type, int $accountId, ?array $statuses = null, ?string $source = null): ?array
    {
        $needle = json_encode([$accountId]);
        $statusSql = '';
        $params = [$type];
        if ($statuses) {
            $ph = implode(',', array_fill(0, count($statuses), '?'));
            $statusSql = " AND status IN ({$ph})";
            $params = array_merge($params, $statuses);
        }
        try {
            $row = DB::selectOne(
                "SELECT * FROM tasks WHERE type=?{$statusSql}
                 AND JSON_CONTAINS(COALESCE(payload,'{}'), ?, '$.accountIds')
                 ORDER BY id DESC LIMIT 1",
                array_merge($params, [$needle])
            );
            if ($row && $this->taskPayloadMatchesSource($row, $source)) {
                return $row;
            }
        } catch (\Throwable $e) {
            // JSON_CONTAINS 不可用或 payload 非 JSON 时走扫描兜底
        }
        $rows = DB::select(
            "SELECT * FROM tasks WHERE type=?{$statusSql} ORDER BY id DESC LIMIT 40",
            $params
        );
        foreach ($rows as $row) {
            $payload = json_decode((string)($row['payload'] ?? '{}'), true);
            $ids = $payload['accountIds'] ?? [];
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $aid) {
                if ((int)$aid === $accountId || (string)$aid === (string)$accountId) {
                    if ($this->taskPayloadMatchesSource($row, $source)) {
                        return $row;
                    }
                    break;
                }
            }
        }
        return null;
    }

    private function taskPayloadMatchesSource(array $task, ?string $source): bool
    {
        if ($source === null) {
            return true;
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $taskSource = (string)($payload['source'] ?? '');
        if ($source === 'chat.refresh-fans') {
            return $taskSource === '' || $taskSource === 'chat.refresh-fans';
        }
        return $taskSource === $source;
    }

    /**
     * 客服坐席：入队服务器浏览器（linux / cs_seat）任务。
     * action: login|logout|detect|send|dm_sync|sync_fans|sync_following
     */
    public function enqueueSeatTask(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureSchema();
        // 先回收过期/无租约的 running 批次：否则同账号上一次卡死的坐席总任务
        // 会一直占着 cs_seat 互斥位，新任务永远领不到、也不打开环境
        try {
            AccountWorkBatchService::reclaimExpiredLeases();
        } catch (\Throwable $e) {
            error_log('[seat_reclaim] ' . $e->getMessage());
        }
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $action = strtolower(trim((string)$request->input('action', '')));
        $account = DB::selectOne(
            'SELECT id, username, browser_profile_id, tkswarm_browser_profile_id, linux_browser_profile_id, owner_id, login_status FROM accounts WHERE id=? AND enabled=1',
            [$accountId]
        );
        if ($err = $this->assertChatAccountVisible($account)) {
            return $err;
        }
        $hasEnv = trim((string)($account['tkswarm_browser_profile_id'] ?? '')) !== ''
            || trim((string)($account['linux_browser_profile_id'] ?? '')) !== ''
            || trim((string)($account['browser_profile_id'] ?? '')) !== '';
        if (!$hasEnv && !in_array($action, ['logout'], true)) {
            return $this->error('账号尚未绑定自研/服务器环境包，请先在桌面自研创建并上传环境包', 422);
        }

        $map = [
            'login' => 'cs_login',
            'logout' => 'cs_logout',
            'detect' => 'cs_detect',
            'send' => 'dm_reply',
            'dm_sync' => 'dm_sync',
            'sync_fans' => 'sync_fans',
            'sync_following' => 'sync_fans',
            'refresh_fans' => 'sync_fans',
            'refresh_following' => 'sync_fans',
            'fetch_lang' => 'fetch_lang',
            'search_users' => 'search_users',
            'pin_friend' => 'pin_friend',
            'block_user' => 'block_user',
            'delete_chat' => 'delete_chat',
        ];
        if (!isset($map[$action])) {
            return $this->error('不支持的坐席动作：' . $action, 422);
        }
        $taskType = $map[$action];
        $payload = [
            'browserBackend' => 'linux',
            'browser_backend' => 'linux',
            'source' => 'chat.seat',
            'seatAction' => $action,
            'autoSend' => true,
        ];
        if ($taskType === 'dm_reply') {
            $friendId = (int)$request->input('friendId', $request->input('friend_id', 0));
            $content = trim((string)$request->input('content', ''));
            if ($friendId < 1 || $content === '') {
                return $this->error('发送私信需要 friendId 与 content', 422);
            }
            $friend = DB::selectOne('SELECT * FROM chat_users WHERE id=? AND account_id=?', [$friendId, $accountId]);
            if (!$friend) {
                return $this->error('好友不存在', 404);
            }
            // 日私信额度：与 AI 拓客共用账号当日额度（ai_lead_account_daily）；已用完则不入队，也不写聊天记录
            try {
                $remaining = \App\Services\AiLeadVideoWeightService::getRemainingToday($accountId);
                // 已在队列里等待/执行中的坐席发信也占额度，避免连点几条一起超限
                $inflight = (int)(DB::selectOne(
                    "SELECT COUNT(*) AS c FROM account_work_items WHERE account_id=? AND task_type='dm_reply' AND status IN ('pending','running')",
                    [$accountId]
                )['c'] ?? 0);
                if ($remaining - $inflight < 1) {
                    return $this->error('今日私信次数已使用完，请明日再试', 422);
                }
            } catch (\Throwable $e) {
                error_log('[seat_dm_quota] check failed: ' . $e->getMessage());
            }
            $payload['friendId'] = $friendId;
            $payload['content'] = $content;
            $payload['recipient'] = (string)($friend['username'] ?: $friend['friend_uid'] ?: '');
            $payload['toUser'] = $payload['recipient'];
            // 发送翻译：这里只决定「目标语种」，真正的翻译交给 Node 本地翻译器实时做
            // （Node 侧已对接翻译接口，不依赖 PHP 系统设置的翻译开关）。
            // content = 中文原文，translated 先留空，Node 翻完再回写译文。
            $peerLang = strtolower(trim((string)($friend['lang'] ?? '')));
            if (!in_array($peerLang, ['zh', 'en', 'ja', 'ko', 'es'], true)) {
                $peerLang = 'en';
            }
            $payload['peerLang'] = $peerLang;
            $payload['sourceLang'] = 'zh';
            // 对方就是中文：无需翻译，直接发原文
            $payload['needTranslate'] = $peerLang !== 'zh';
            $payload['translated'] = '';
            // 先入库成聊天记录，标记「同步中」；任务收尾时（finishItem）改为 synced / failed
            DB::query(
                "INSERT INTO chat_records(account_id, user_id, direction, msg_type, content, translated, status, owner_id)
                 VALUES (?,?, 'out', 'text', ?, '', 'syncing', ?)",
                [$accountId, $friendId, $content, $this->writeOwnerId()]
            );
            $recordId = (int)DB::getPdo()->lastInsertId();
            $payload['recordId'] = $recordId;
            DB::query(
                "UPDATE chat_users SET last_message=?, last_direction='out', last_message_at=CURRENT_TIMESTAMP, unread_count=0, is_new=0, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [mb_substr($content, 0, 200), $friendId]
            );
        }
        if ($taskType === 'dm_sync') {
            // 私信列表同步：拉取 TikTok 收件箱会话用户写入本地会话表，供坐席左侧私信 Tab 展示。
            // 坐席场景必须走服务器浏览器（bit），不能用 api-http 通道，否则仍然依赖本机 CK。
            $payload['maxConversations'] = max(5, min(40, (int)$request->input('maxConversations', 30)));
            // 每个会话只拉最近若干条，坐席列表只需要预览最后一条
            $payload['maxMessagesPerChat'] = max(1, min(50, (int)$request->input('maxMessagesPerChat', 10)));
            $payload['preferBit'] = true;
            $payload['via'] = 'bit';
        }
        if ($taskType === 'search_users') {
            // 搜索词必须大于 5 个字符：太短的关键词在 TikTok 上结果海量且无意义
            $keyword = trim((string)$request->input('keyword', $request->input('q', '')));
            $kwLen = mb_strlen($keyword);
            if ($kwLen <= 5) {
                // 带上收到的原始值：前端传空时能立刻看出是取值问题还是转发丢参数
                $got = $keyword === '' ? '空值' : "「{$keyword}」";
                return $this->error("搜索词必须大于 5 个字符（当前 {$kwLen} 个，收到 {$got}）", 422);
            }
            $payload['keyword'] = $keyword;
            $payload['limit'] = max(5, min(100, (int)$request->input('limit', 40)));
            // 结果只在任务返回里临时可见，不写 chat_users
            $payload['temporary'] = true;
            // 下次搜索即刷新：先清掉该账号上一次的缓存，完成后由 finishItem 写入最新结果
            \App\Services\RedisCache::clearSeatSearch($accountId);
        }
        if ($taskType === 'sync_fans') {
            $listKind = in_array($action, ['sync_following', 'refresh_following'], true) ? 'following' : 'followers';
            $payload['listKind'] = $listKind;
            $payload['maxPerRun'] = max(50, min(2000, (int)$request->input('maxPerRun', 500)));
            $payload['accountIds'] = [$accountId];
        }
        if ($taskType === 'fetch_lang') {
            $friendId = (int)$request->input('friendId', $request->input('friend_id', 0));
            if ($friendId < 1) {
                return $this->error('获取语言需要 friendId', 422);
            }
            $friend = DB::selectOne('SELECT * FROM chat_users WHERE id=? AND account_id=?', [$friendId, $accountId]);
            if (!$friend) {
                return $this->error('好友不存在', 404);
            }
            $recipient = trim((string)($friend['username'] ?: $friend['friend_uid'] ?: ''));
            if ($recipient === '') {
                return $this->error('该好友缺少用户名，无法打开主页判断语种', 422);
            }
            $payload['friendId'] = $friendId;
            $payload['recipient'] = $recipient;
            $payload['toUser'] = $recipient;
        }
        // 会话操作（置顶 / 拉黑 / 删除）：统一走服务器浏览器任务，成功后再回写本地会话状态
        if (in_array($taskType, ['pin_friend', 'block_user', 'delete_chat'], true)) {
            $friendId = (int)$request->input('friendId', $request->input('friend_id', 0));
            if ($friendId < 1) {
                return $this->error('该操作需要 friendId', 422);
            }
            $friend = DB::selectOne('SELECT * FROM chat_users WHERE id=? AND account_id=?', [$friendId, $accountId]);
            if (!$friend) {
                return $this->error('好友不存在', 404);
            }
            $recipient = trim((string)($friend['username'] ?: $friend['friend_uid'] ?: ''));
            if ($recipient === '') {
                return $this->error('该会话缺少用户名，无法在 TikTok 上定位会话', 422);
            }
            $payload['friendId'] = $friendId;
            $payload['recipient'] = $recipient;
            $payload['toUser'] = $recipient;
            if ($taskType === 'pin_friend') {
                $payload['pinned'] = filter_var($request->input('pinned', true), FILTER_VALIDATE_BOOLEAN);
            }
            if ($taskType === 'block_user') {
                $payload['blocked'] = filter_var($request->input('blocked', true), FILTER_VALIDATE_BOOLEAN);
            }
        }

        try {
            $out = AccountWorkBatchService::enqueueItem(
                $accountId,
                $taskType,
                $payload,
                0,
                $this->writeOwnerId()
            );
            $batchId = (int)($out['batch_id'] ?? 0);
            $itemId = (int)($out['item_id'] ?? 0);
            // 记录这条聊天消息对应的坐席分任务：发送失败后前端点「重发」按它重置为待执行
            if ($itemId > 0 && !empty($payload['recordId'])) {
                DB::query(
                    'UPDATE chat_records SET work_item_id=? WHERE id=?',
                    [$itemId, (int)$payload['recordId']]
                );
            }
            if ($batchId > 0) {
                AccountWorkBatchService::appendLog(
                    $batchId,
                    $itemId,
                    "客服坐席：账号#{$accountId} 动作={$action}/{$taskType} 已入队（linux / cs_seat）",
                    'info',
                    $accountId,
                    0,
                    ['seatAction' => $action, 'taskType' => $taskType]
                );
            }
            return $this->success([
                'accountId' => $accountId,
                'action' => $action,
                'taskType' => $taskType,
                'batchId' => $batchId,
                'itemId' => $itemId,
                'browserBackend' => 'linux',
                'batchScope' => 'cs_seat',
            ], '已推送坐席任务到服务器浏览器队列');
        } catch (\Throwable $e) {
            // 入队失败：已先入库的记录标记失败，避免永远停在「同步中」
            if (!empty($payload['recordId'])) {
                DB::query("UPDATE chat_records SET status='failed' WHERE id=?", [(int)$payload['recordId']]);
            }
            return $this->error($e->getMessage(), 422);
        }
    }

    /** 客服坐席：轮询分任务状态 */
    /**
     * 重发失败消息：把它对应的坐席分任务重置为「待执行」，由服务器浏览器队列重新领取。
     * 只重置任务，不改聊天内容；成功后状态回到 syncing。
     */
    public function resendFailedMessage(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureSchema();
        $recordId = (int)$request->input('recordId', $request->input('record_id', 0));
        if ($recordId < 1) {
            return $this->error('recordId 必填', 422);
        }
        $record = DB::selectOne('SELECT * FROM chat_records WHERE id=?', [$recordId]);
        if (!$record) {
            return $this->error('消息不存在', 404);
        }
        if ((string)($record['direction'] ?? '') !== 'out') {
            return $this->error('只有自己发出的消息可以重发', 422);
        }
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$record['account_id']]);
        if ($err = $this->assertChatAccountVisible($account)) {
            return $err;
        }
        $status = (string)($record['status'] ?? '');
        if (!in_array($status, ['failed', 'syncing'], true)) {
            return $this->error("只有发送失败的消息可以重发（当前状态：{$status}）", 422);
        }
        $accountId = (int)$record['account_id'];
        $friendId = (int)$record['user_id'];

        $itemId = (int)($record['work_item_id'] ?? 0);
        // 老记录没有 work_item_id：按 payload 里的 recordId 反查最近一条 dm_reply 分任务
        if ($itemId < 1) {
            $found = DB::selectOne(
                "SELECT id FROM account_work_items
                 WHERE account_id=? AND task_type='dm_reply' AND payload_json LIKE ?
                 ORDER BY id DESC LIMIT 1",
                [$accountId, '%"recordId":' . $recordId . '%']
            );
            $itemId = $found ? (int)$found['id'] : 0;
        }

        if ($itemId > 0) {
            try {
                AccountWorkBatchService::resetItem($itemId);
            } catch (\Throwable $e) {
                return $this->error('重置任务失败：' . $e->getMessage(), 422);
            }
            DB::query("UPDATE chat_records SET status='syncing' WHERE id=?", [$recordId]);
            return $this->success([
                'recordId' => $recordId,
                'itemId' => $itemId,
                'accountId' => $accountId,
                'mode' => 'reset',
            ], '已把该消息的发送任务重置为待执行，服务器浏览器会重新发送');
        }

        // 找不到原任务（可能已清理）：按原内容重新入队一条 dm_reply
        $friend = DB::selectOne('SELECT * FROM chat_users WHERE id=? AND account_id=?', [$friendId, $accountId]);
        if (!$friend) {
            return $this->error('好友不存在，无法重发', 404);
        }
        $content = trim((string)($record['content'] ?? ''));
        if ($content === '') {
            return $this->error('原消息内容为空，无法重发', 422);
        }
        $peerLang = strtolower(trim((string)($friend['lang'] ?? '')));
        if (!in_array($peerLang, ['zh', 'en', 'ja', 'ko', 'es'], true)) {
            $peerLang = 'en';
        }
        $payload = [
            'browserBackend' => 'linux',
            'browser_backend' => 'linux',
            'source' => 'chat.seat',
            'seatAction' => 'send',
            'autoSend' => true,
            'friendId' => $friendId,
            'content' => $content,
            'recipient' => (string)($friend['username'] ?: $friend['friend_uid'] ?: ''),
            'toUser' => (string)($friend['username'] ?: $friend['friend_uid'] ?: ''),
            'peerLang' => $peerLang,
            'sourceLang' => 'zh',
            'needTranslate' => $peerLang !== 'zh',
            'translated' => '',
            'recordId' => $recordId,
        ];
        try {
            $out = AccountWorkBatchService::enqueueItem($accountId, 'dm_reply', $payload, 0, $this->writeOwnerId());
        } catch (\Throwable $e) {
            return $this->error('重新入队失败：' . $e->getMessage(), 422);
        }
        $newItemId = (int)($out['item_id'] ?? 0);
        DB::query(
            "UPDATE chat_records SET status='syncing', work_item_id=? WHERE id=?",
            [$newItemId > 0 ? $newItemId : null, $recordId]
        );
        return $this->success([
            'recordId' => $recordId,
            'itemId' => $newItemId,
            'batchId' => (int)($out['batch_id'] ?? 0),
            'accountId' => $accountId,
            'mode' => 'requeue',
        ], '已重新入队发送任务，服务器浏览器会重新发送');
    }

    public function seatItemStatus(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureSchema();
        $itemId = (int)$id;
        $row = AccountWorkBatchService::getWorkItem($itemId);
        if (!$row) {
            return $this->error('坐席任务不存在', 404);
        }
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)($row['account_id'] ?? 0)]);
        if ($err = $this->assertChatAccountVisible($account)) {
            return $err;
        }
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $result = json_decode((string)($row['result_json'] ?? '{}'), true);
        if (!is_array($result)) {
            $result = [];
        }
        return $this->success([
            'itemId' => $itemId,
            'batchId' => (int)($row['batch_id'] ?? 0),
            'accountId' => (int)($row['account_id'] ?? 0),
            'taskType' => (string)($row['task_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'batchStatus' => (string)($row['batch_status'] ?? ''),
            'errorMessage' => (string)($row['error_message'] ?? $row['batch_last_error'] ?? ''),
            'loginStatus' => (string)($row['login_status'] ?? ''),
            'result' => $result,
            'payload' => [
                'seatAction' => $payload['seatAction'] ?? '',
                'listKind' => $payload['listKind'] ?? '',
            ],
        ]);
    }

    /**
     * 读取某账号上一次的客服搜索结果（Redis，每账号独立，有效期一天；过期即为空）。
     * GET /api/chat/search-cache?accountId=
     */
    public function searchCache(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $accountId = (int)$request->input('accountId', 0);
        if ($accountId < 1) {
            return $this->error('请指定账号', 422);
        }
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
        if ($err = $this->assertChatAccountVisible($account)) {
            return $err;
        }
        $cached = \App\Services\RedisCache::loadSeatSearch($accountId);
        return $this->success([
            'accountId' => $accountId,
            'cached' => $cached !== null,
            'keyword' => (string)($cached['keyword'] ?? ''),
            'items' => is_array($cached['items'] ?? null) ? $cached['items'] : [],
            'total' => (int)($cached['total'] ?? 0),
            'savedAt' => (string)($cached['savedAt'] ?? ''),
            'expiresIn' => $cached['expiresIn'] ?? null,
        ]);
    }

    private function mapFriend(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $row['is_pinned'] = !empty($row['is_pinned']);
        $row['is_new'] = !empty($row['is_new']);
        $row['translate_in'] = !empty($row['translate_in']);
        $row['translate_out'] = !empty($row['translate_out']);
        $row['tags'] = array_values(array_filter(array_map('trim', explode(',', (string)($row['tags'] ?? '')))));
        // 对方语种：空表示还没获取，收发翻译按英语处理
        $row['lang'] = strtolower(trim((string)($row['lang'] ?? '')));
        $row['lang_known'] = $row['lang'] !== '';
        if (empty($row['user_type'])) {
            $row['user_type'] = match ((string)($row['relation'] ?? '')) {
                'follower' => 'follower',
                'following' => 'following',
                'mutual' => 'mutual',
                'blocked' => 'blocked',
                default => 'dm',
            };
        }
        return $row;
    }

    private function ensureConnection(int $accountId): void
    {
        DB::query(
            "INSERT INTO chat_connections(account_id, status) VALUES (?, 'offline')
             ON DUPLICATE KEY UPDATE account_id=account_id",
            [$accountId]
        );
    }

    private function idList(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        return array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 100);
    }

    private function migrateChatLegacyTables(): void
    {
        try {
            $userCount = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM chat_users')['c'] ?? 0);
            if ($userCount === 0) {
                $legacyCount = 0;
                try {
                    $legacyCount = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM chat_friends')['c'] ?? 0);
                } catch (\Throwable $e) {
                    $legacyCount = 0;
                }
                if ($legacyCount > 0) {
                    DB::query(
                        "INSERT INTO chat_users (
                            id, account_id, user_type, friend_uid, username, nickname, avatar_url, relation, tags,
                            unread_count, is_pinned, is_new, last_message, last_direction, last_message_at,
                            translate_in, translate_out, translate_target, translate_source, owner_id, created_at, updated_at
                         )
                         SELECT
                            id, account_id,
                            CASE relation
                                WHEN 'follower' THEN 'follower'
                                WHEN 'following' THEN 'following'
                                WHEN 'mutual' THEN 'mutual'
                                WHEN 'blocked' THEN 'blocked'
                                ELSE 'dm'
                            END,
                            friend_uid, username, nickname, avatar_url, relation, tags,
                            unread_count, is_pinned, is_new, last_message, last_direction, last_message_at,
                            translate_in, translate_out, translate_target, translate_source, owner_id, created_at, updated_at
                         FROM chat_friends"
                    );
                }
            }
        } catch (\Throwable $e) {
        }
        try {
            $recordCount = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM chat_records')['c'] ?? 0);
            if ($recordCount === 0) {
                $legacyCount = 0;
                try {
                    $legacyCount = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM chat_messages')['c'] ?? 0);
                } catch (\Throwable $e) {
                    $legacyCount = 0;
                }
                if ($legacyCount > 0) {
                    DB::query(
                        "INSERT INTO chat_records (
                            id, account_id, user_id, direction, msg_type, content, translated, status, external_id, owner_id, created_at
                         )
                         SELECT
                            id, account_id, friend_id, direction, msg_type, content, translated, status, external_id, owner_id, created_at
                         FROM chat_messages"
                    );
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        DB::query("CREATE TABLE IF NOT EXISTS chat_users (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          user_type VARCHAR(32) NOT NULL DEFAULT 'dm',
          friend_uid VARCHAR(191) NOT NULL DEFAULT '',
          username VARCHAR(255) NOT NULL DEFAULT '',
          nickname VARCHAR(255) NOT NULL DEFAULT '',
          avatar_url TEXT NULL,
          relation VARCHAR(32) NOT NULL DEFAULT 'stranger',
          tags VARCHAR(512) NOT NULL DEFAULT '',
          unread_count INT NOT NULL DEFAULT 0,
          is_pinned TINYINT NOT NULL DEFAULT 0,
          is_new TINYINT NOT NULL DEFAULT 0,
          last_message TEXT NULL,
          last_direction VARCHAR(8) NOT NULL DEFAULT '',
          last_message_at DATETIME NULL,
          translate_in TINYINT NOT NULL DEFAULT 0,
          translate_out TINYINT NOT NULL DEFAULT 0,
          translate_target VARCHAR(16) NOT NULL DEFAULT 'zh',
          translate_source VARCHAR(16) NOT NULL DEFAULT 'auto',
          lang VARCHAR(16) NOT NULL DEFAULT '',
          lang_checked_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_chat_user (account_id, friend_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS chat_records (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          user_id INT NOT NULL,
          direction VARCHAR(8) NOT NULL,
          msg_type VARCHAR(32) NOT NULL DEFAULT 'text',
          content TEXT NOT NULL,
          translated TEXT NULL,
          status VARCHAR(32) NOT NULL DEFAULT 'sent',
          work_item_id INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS chat_connections (
          account_id INT PRIMARY KEY,
          status VARCHAR(32) NOT NULL DEFAULT 'offline',
          auto_translate TINYINT NOT NULL DEFAULT 0,
          sync_messages TINYINT NOT NULL DEFAULT 1,
          ai_hosting TINYINT NOT NULL DEFAULT 0,
          agent_id INT NULL,
          connected_at DATETIME NULL,
          last_error TEXT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS chat_tags (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(191) NOT NULL UNIQUE,
          color VARCHAR(64) NOT NULL DEFAULT '',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'chat_users' => [
                'user_type' => "ALTER TABLE chat_users ADD COLUMN user_type VARCHAR(32) NOT NULL DEFAULT 'dm'",
                'translate_in' => 'ALTER TABLE chat_users ADD COLUMN translate_in TINYINT NOT NULL DEFAULT 0',
                'translate_out' => 'ALTER TABLE chat_users ADD COLUMN translate_out TINYINT NOT NULL DEFAULT 0',
                'translate_target' => "ALTER TABLE chat_users ADD COLUMN translate_target VARCHAR(16) NOT NULL DEFAULT 'zh'",
                'translate_source' => "ALTER TABLE chat_users ADD COLUMN translate_source VARCHAR(16) NOT NULL DEFAULT 'auto'",
                // 对方语种：由「获取语言」坐席任务打开对方主页判断后写入；空=未获取，收发按英语处理
                'lang' => "ALTER TABLE chat_users ADD COLUMN lang VARCHAR(16) NOT NULL DEFAULT ''",
                'lang_checked_at' => 'ALTER TABLE chat_users ADD COLUMN lang_checked_at DATETIME NULL',
                'owner_id' => 'ALTER TABLE chat_users ADD COLUMN owner_id INT NULL',
            ],
            'chat_records' => [
                'translated' => 'ALTER TABLE chat_records ADD COLUMN translated TEXT NULL',
                'external_id' => 'ALTER TABLE chat_records ADD COLUMN external_id VARCHAR(191) NULL',
                'owner_id' => 'ALTER TABLE chat_records ADD COLUMN owner_id INT NULL',
                // 对应坐席分任务：发送失败后点「重发」按这个 id 把任务重置为待执行
                'work_item_id' => 'ALTER TABLE chat_records ADD COLUMN work_item_id INT NULL',
            ],
            'chat_connections' => [
                'ai_hosting' => 'ALTER TABLE chat_connections ADD COLUMN ai_hosting TINYINT NOT NULL DEFAULT 0',
                'agent_id' => 'ALTER TABLE chat_connections ADD COLUMN agent_id INT NULL',
                'last_synced_at' => 'ALTER TABLE chat_connections ADD COLUMN last_synced_at DATETIME NULL',
            ],
        ] as $table => $cols) {
            try {
                $existing = array_column(DB::select("SHOW COLUMNS FROM `{$table}`") ?: [], 'Field');
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($cols as $col => $sql) {
                if (!in_array($col, $existing, true)) {
                    try {
                        DB::query($sql);
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
        try {
            $indexes = DB::select("SHOW INDEX FROM chat_records WHERE Key_name='uk_chat_msg_ext'");
            if (!$indexes) {
                DB::query('ALTER TABLE chat_records ADD UNIQUE KEY uk_chat_msg_ext (account_id, external_id)');
            }
        } catch (\Throwable $e) {
        }
        try {
            $accCols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            if (!in_array('dm_channel', $accCols, true)) {
                DB::query("ALTER TABLE accounts ADD COLUMN dm_channel VARCHAR(16) NOT NULL DEFAULT 'auto'");
            }
        } catch (\Throwable $e) {
        }
        $this->migrateChatLegacyTables();
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_followers_sync (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          friend_uid VARCHAR(191) NOT NULL,
          username VARCHAR(255) NOT NULL DEFAULT '',
          nickname VARCHAR(255) NOT NULL DEFAULT '',
          avatar_url TEXT NULL,
          synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_fans_sync (account_id, friend_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
