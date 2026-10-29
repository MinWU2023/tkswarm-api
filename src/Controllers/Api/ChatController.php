<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
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
            if ($err = $this->assertAccountOwns($account)) {
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
        // 与 Node 对齐：content=原文，translated=译文
        $translated = '';
        if ($msgType === 'text' && !empty($friend['translate_out'])) {
            try {
                $tr = Translator::translate(
                    $content,
                    (string)($friend['translate_source'] ?: 'auto'),
                    (string)($friend['translate_target'] ?: 'zh')
                );
                $translated = (string)($tr['text'] ?? '');
            } catch (\Throwable $e) {
                return $this->error('发送翻译失败：' . $e->getMessage(), 422);
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
            try {
                $tr = Translator::translate(
                    $content,
                    (string)($friend['translate_source'] ?: 'auto'),
                    (string)($friend['translate_target'] ?: 'zh')
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
        $account = DB::selectOne('SELECT id, username, browser_profile_id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        if (empty($account['browser_profile_id'])) {
            return $this->error('账号尚未绑定浏览器环境', 422);
        }
        $existing = $this->findTaskByAccountId('sync_fans', (int)$id, ['queued', 'running'], 'chat.refresh-fans');
        if ($existing) {
            return $this->success([
                'taskId' => (int)$existing['id'],
                'status' => $existing['status'],
                'accountId' => (int)$id,
            ], '粉丝同步任务已在进行中');
        }
        $maxPerRun = max(50, min(2000, (int)Translator::setting('fansSyncMaxPerRun', 500)));
        $payload = json_encode([
            'accountIds' => [(int)$id],
            'maxPerRun' => $maxPerRun,
            'listKind' => 'followers',
            'source' => 'chat.refresh-fans',
        ], JSON_UNESCAPED_UNICODE);
        $name = '同步粉丝 #' . $id . ' ' . ($account['username'] ?? '');
        try {
            DB::query(
                "INSERT INTO tasks(name, type, status, total_count, payload, owner_id, created_at, updated_at)
                 VALUES (?,?, 'queued', 1, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [$name, 'sync_fans', $payload, $this->writeOwnerId()]
            );
        } catch (\Throwable $e) {
            DB::query(
                "INSERT INTO tasks(name, type, status, total_count, payload, created_at, updated_at)
                 VALUES (?,?, 'queued', 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [$name, 'sync_fans', $payload]
            );
        }
        $taskId = (int)DB::getPdo()->lastInsertId();
        return $this->success([
            'taskId' => $taskId,
            'status' => 'queued',
            'accountId' => (int)$id,
            'maxPerRun' => $maxPerRun,
        ], '已创建粉丝列表同步任务，请保持本机 Node/比特在线');
    }

    public function refreshFansStatus(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $taskId = (int)$request->get('taskId', 0);
        if ($taskId > 0) {
            $task = DB::selectOne('SELECT * FROM tasks WHERE id=? AND type=?', [$taskId, 'sync_fans']);
        } else {
            $task = $this->findTaskByAccountId('sync_fans', (int)$id, null, 'chat.refresh-fans');
        }
        if (!$task) {
            return $this->success([
                'accountId' => (int)$id,
                'status' => 'idle',
                'progress' => null,
            ]);
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $progress = $payload['fansProgress'] ?? null;
        $localFollowers = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM chat_users WHERE account_id=? AND relation IN ('follower','mutual')",
            [(int)$id]
        )['c'] ?? 0);
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
        $account = DB::selectOne('SELECT id, username, browser_profile_id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        if (empty($account['browser_profile_id'])) {
            return $this->error('账号尚未绑定浏览器环境', 422);
        }
        $existing = $this->findTaskByAccountId('sync_fans', (int)$id, ['queued', 'running'], 'chat.refresh-following');
        if ($existing) {
            return $this->success([
                'taskId' => (int)$existing['id'],
                'status' => $existing['status'],
                'accountId' => (int)$id,
            ], '关注同步任务已在进行中');
        }
        $maxPerRun = max(50, min(2000, (int)Translator::setting('fansSyncMaxPerRun', 500)));
        $payload = json_encode([
            'accountIds' => [(int)$id],
            'maxPerRun' => $maxPerRun,
            'listKind' => 'following',
            'source' => 'chat.refresh-following',
        ], JSON_UNESCAPED_UNICODE);
        $name = '同步关注 #' . $id . ' ' . ($account['username'] ?? '');
        try {
            DB::query(
                "INSERT INTO tasks(name, type, status, total_count, payload, owner_id, created_at, updated_at)
                 VALUES (?,?, 'queued', 1, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [$name, 'sync_fans', $payload, $this->writeOwnerId()]
            );
        } catch (\Throwable $e) {
            DB::query(
                "INSERT INTO tasks(name, type, status, total_count, payload, created_at, updated_at)
                 VALUES (?,?, 'queued', 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [$name, 'sync_fans', $payload]
            );
        }
        $taskId = (int)DB::getPdo()->lastInsertId();
        return $this->success([
            'taskId' => $taskId,
            'status' => 'queued',
            'accountId' => (int)$id,
            'maxPerRun' => $maxPerRun,
        ], '已创建关注列表同步任务，请保持本机 Node/比特在线');
    }

    public function refreshFollowingStatus(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $taskId = (int)$request->get('taskId', 0);
        if ($taskId > 0) {
            $task = DB::selectOne('SELECT * FROM tasks WHERE id=? AND type=?', [$taskId, 'sync_fans']);
        } else {
            $task = $this->findTaskByAccountId('sync_fans', (int)$id, null, 'chat.refresh-following');
        }
        if (!$task) {
            return $this->success([
                'accountId' => (int)$id,
                'status' => 'idle',
                'progress' => null,
            ]);
        }
        $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
        $progress = $payload['followingProgress'] ?? $payload['fansProgress'] ?? null;
        $localFollowing = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM chat_users WHERE account_id=? AND relation IN ('following','mutual')",
            [(int)$id]
        )['c'] ?? 0);
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
                'owner_id' => 'ALTER TABLE chat_users ADD COLUMN owner_id INT NULL',
            ],
            'chat_records' => [
                'translated' => 'ALTER TABLE chat_records ADD COLUMN translated TEXT NULL',
                'external_id' => 'ALTER TABLE chat_records ADD COLUMN external_id VARCHAR(191) NULL',
                'owner_id' => 'ALTER TABLE chat_records ADD COLUMN owner_id INT NULL',
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
