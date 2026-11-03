<?php
declare(strict_types=1);

namespace App\Controllers\Company;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountWorkBatchService;
use App\Services\AiLeadExecutionService;
use App\Services\CompanyService;
use App\Services\DataScope;
use App\Services\PullCommentService;
use App\Services\TikTokVideoScreenshotService;

/**
 * 企业客户门户业务 API（数据范围由 DataScope 企业客户规则过滤）
 */
class PortalApiController extends Controller
{
    private function gate(Request $request): ?Response
    {
        [, $err] = AuthController::requireEnterprise($request);
        return $err;
    }

    public function dashboard(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();
        PullCommentService::ensureSchema();
        AiLeadExecutionService::ensureSchema();

        [$accSql, $accParams] = DataScope::accountListScopeSql('a.owner_id');
        $accountTotal = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM accounts a WHERE 1=1{$accSql}",
            $accParams
        )['c'] ?? 0);
        $onlineTotal = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM accounts a WHERE a.login_status='online'{$accSql}",
            $accParams
        )['c'] ?? 0);

        $videoWhere = "(m.origin='online' OR (m.publish_status='published' AND TRIM(IFNULL(m.platform_video_id,''))<>''))";
        $videoTotal = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE {$videoWhere}
             {$accSql}",
            $accParams
        )['c'] ?? 0);
        $viewTotal = (int)(DB::selectOne(
            "SELECT COALESCE(SUM(m.view_count),0) AS c FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE {$videoWhere}
             {$accSql}",
            $accParams
        )['c'] ?? 0);
        $commentTotal = (int)(DB::selectOne(
            "SELECT COALESCE(SUM(m.comment_count),0) AS c FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE {$videoWhere}
             {$accSql}",
            $accParams
        )['c'] ?? 0);

        $clueTotal = 0;
        $userId = (int)(($GLOBALS['auth_user']['id'] ?? 0));
        if ($userId > 0) {
            try {
                $commentCnt = (int)(DB::selectOne(
                    "SELECT COUNT(*) AS c
                     FROM ai_lead_comments cm
                     INNER JOIN ai_lead_comment_shared_users su ON su.comment_id = cm.id AND su.user_id=?
                     WHERE cm.ai_pass=1",
                    [$userId]
                )['c'] ?? 0);
                $creatorCnt = (int)(DB::selectOne(
                    "SELECT COUNT(*) AS c
                     FROM ai_lead_creators c
                     INNER JOIN ai_lead_creator_shared_users su ON su.creator_id = c.id AND su.user_id=?",
                    [$userId]
                )['c'] ?? 0);
                $clueTotal = $commentCnt + $creatorCnt;
            } catch (\Throwable $e) {
                $clueTotal = 0;
            }
        }

        $recentAccounts = DB::select(
            "SELECT a.id, a.username, a.nickname, a.login_status, a.updated_at
             FROM accounts a WHERE 1=1{$accSql}
             ORDER BY a.updated_at DESC, a.id DESC LIMIT 8",
            $accParams
        ) ?: [];

        $user = $GLOBALS['auth_user'] ?? [];
        return $this->success([
            'stats' => [
                'accounts' => $accountTotal,
                'online' => $onlineTotal,
                'videos' => $videoTotal,
                'views' => $viewTotal,
                'comments' => $commentTotal,
                'clues' => $clueTotal,
            ],
            'recentAccounts' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                    'loginStatus' => (string)($r['login_status'] ?? ''),
                    'updatedAt' => (string)($r['updated_at'] ?? ''),
                ];
            }, $recentAccounts),
            'welcome' => (string)($user['nickname'] ?? $user['username'] ?? '企业客户'),
        ]);
    }

    /**
     * 控制面板：企业关联账号的发布视频记录（成功 / 进行中）
     */
    public function dashboardPublishes(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();

        $tab = strtolower(trim((string)$request->get('tab', 'success')));
        if (!in_array($tab, ['success', 'progress'], true)) {
            $tab = 'success';
        }
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(48, max(1, (int)$request->get('pageSize', 12)));
        $accountId = (int)$request->get('accountId', $request->get('account_id', 0));
        $offset = ($page - 1) * $pageSize;

        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $filters = ['m.publish_account_id IS NOT NULL', 'm.publish_account_id > 0'];
        $params = [];

        if ($tab === 'progress') {
            $filters[] = "m.publish_status='publishing'";
        } else {
            $filters[] = "(m.publish_status='published' OR (m.origin='online' AND TRIM(IFNULL(m.platform_video_id,''))<>''))";
        }
        if ($accountId > 0) {
            $filters[] = 'm.publish_account_id=?';
            $params[] = $accountId;
        }

        $where = implode(' AND ', $filters) . $scopeSql;
        $bind = array_merge($params, $scopeParams);

        $total = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE {$where}",
            $bind
        )['c'] ?? 0);

        $rows = DB::select(
            "SELECT m.id, m.name, m.share_title, m.platform_video_id, m.platform_video_url,
                    m.view_count, m.like_count, m.comment_count, m.cover_base64, m.origin,
                    m.publish_status, m.platform_published_at, m.updated_at, m.created_at,
                    a.id AS account_id, a.username, a.nickname
             FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE {$where}
             ORDER BY COALESCE(m.platform_published_at, m.updated_at, m.created_at) DESC, m.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $bind
        ) ?: [];

        $countBind = $scopeParams;
        $countAccountSql = '';
        if ($accountId > 0) {
            $countAccountSql = ' AND m.publish_account_id=?';
            $countBind = array_merge([$accountId], $scopeParams);
        }
        $successCount = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE m.publish_account_id > 0
               AND (m.publish_status='published' OR (m.origin='online' AND TRIM(IFNULL(m.platform_video_id,''))<>''))
               {$countAccountSql}{$scopeSql}",
            $countBind
        )['c'] ?? 0);
        $progressCount = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE m.publish_account_id > 0
               AND m.publish_status='publishing'
               {$countAccountSql}{$scopeSql}",
            $countBind
        )['c'] ?? 0);

        $accountOptions = DB::select(
            "SELECT a.id, a.username, a.nickname FROM accounts a
             WHERE 1=1{$scopeSql}
             ORDER BY a.username ASC, a.id ASC
             LIMIT 300",
            $scopeParams
        ) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $cover = trim((string)($r['cover_base64'] ?? ''));
            $coverUrl = '';
            if ($cover !== '' && (str_starts_with($cover, 'http') || str_starts_with($cover, 'data:'))) {
                $coverUrl = $cover;
            } elseif ((int)$r['id'] > 0) {
                $coverUrl = '/api/materials/' . (int)$r['id'] . '/cover';
            }
            $title = trim((string)($r['share_title'] ?? '')) ?: trim((string)($r['name'] ?? '')) ?: ('素材 #' . (int)$r['id']);
            $status = (string)($r['publish_status'] ?? '');
            if ($status === '' && (string)($r['origin'] ?? '') === 'online') {
                $status = 'published';
            }
            $items[] = [
                'materialId' => (int)$r['id'],
                'videoId' => trim((string)($r['platform_video_id'] ?? '')),
                'videoUrl' => (string)($r['platform_video_url'] ?? ''),
                'title' => $title,
                'coverUrl' => $coverUrl,
                'views' => (int)($r['view_count'] ?? 0),
                'likes' => (int)($r['like_count'] ?? 0),
                'comments' => (int)($r['comment_count'] ?? 0),
                'publishStatus' => $status,
                'origin' => (string)($r['origin'] ?? ''),
                'postedAt' => (string)($r['platform_published_at'] ?? ''),
                'updatedAt' => (string)($r['updated_at'] ?? ''),
                'createdAt' => (string)($r['created_at'] ?? ''),
                'accountId' => (int)($r['account_id'] ?? 0),
                'accountUsername' => (string)($r['username'] ?? ''),
                'accountNickname' => (string)($r['nickname'] ?? ''),
            ];
        }

        return $this->success([
            'tab' => $tab,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => max(1, (int)ceil($total / max(1, $pageSize))),
            'counts' => [
                'success' => $successCount,
                'progress' => $progressCount,
            ],
            'accountOptions' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                ];
            }, $accountOptions),
        ]);
    }

    /**
     * 企业门户消息通知（基于账号状态与发布进度的运营提醒）
     */
    public function notifications(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();

        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $items = [];

        $offline = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM accounts a
             WHERE COALESCE(a.login_status,'')<>'online'{$scopeSql}",
            $scopeParams
        )['c'] ?? 0);
        if ($offline > 0) {
            $items[] = [
                'id' => 'acct-offline',
                'title' => '账号离线提醒',
                'body' => "当前有 {$offline} 个账号未在线，可能影响发布与数据拉取。",
                'level' => 'warn',
                'createdAt' => date('Y-m-d H:i:s'),
            ];
        }

        $publishing = 0;
        try {
            $publishing = (int)(DB::selectOne(
                "SELECT COUNT(*) AS c FROM materials m
                 INNER JOIN accounts a ON a.id = m.publish_account_id
                 WHERE m.publish_status='publishing'{$scopeSql}",
                $scopeParams
            )['c'] ?? 0);
        } catch (\Throwable $e) {
            $publishing = 0;
        }
        if ($publishing > 0) {
            $items[] = [
                'id' => 'pub-progress',
                'title' => '发布进行中',
                'body' => "有 {$publishing} 条视频仍在发布中，可在控制面板查看进度。",
                'level' => 'info',
                'createdAt' => date('Y-m-d H:i:s'),
            ];
        }

        $clueNew = 0;
        $userId = (int)(($GLOBALS['auth_user']['id'] ?? 0));
        if ($userId > 0) {
            try {
                $commentNew = (int)(DB::selectOne(
                    "SELECT COUNT(*) AS c
                     FROM ai_lead_comments cm
                     INNER JOIN ai_lead_comment_shared_users su ON su.comment_id = cm.id AND su.user_id=?
                     WHERE cm.ai_pass=1
                       AND cm.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                    [$userId]
                )['c'] ?? 0);
                $creatorNew = (int)(DB::selectOne(
                    "SELECT COUNT(*) AS c
                     FROM ai_lead_creators c
                     INNER JOIN ai_lead_creator_shared_users su ON su.creator_id = c.id AND su.user_id=?
                     WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                    [$userId]
                )['c'] ?? 0);
                $clueNew = $commentNew + $creatorNew;
            } catch (\Throwable $e) {
                $clueNew = 0;
            }
        }
        if ($clueNew > 0) {
            $items[] = [
                'id' => 'clue-week',
                'title' => '拓客线索更新',
                'body' => "近 7 天新增 {$clueNew} 条拓客线索，可前往「拓客线索」查看。",
                'level' => 'success',
                'createdAt' => date('Y-m-d H:i:s'),
            ];
        }

        if (!$items) {
            $items[] = [
                'id' => 'all-clear',
                'title' => '暂无新消息',
                'body' => '当前没有需要处理的提醒，账号与发布状态正常。',
                'level' => 'info',
                'createdAt' => date('Y-m-d H:i:s'),
            ];
        }

        return $this->success([
            'items' => $items,
            'unread' => count(array_filter($items, static fn($x) => ($x['id'] ?? '') !== 'all-clear')),
        ]);
    }

    /**
     * 控制面板：近 28 天数据 + 历史快照（按账号；无数据时 28 天补 0）
     */
    public function dashboardCharts(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();

        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $accountOptions = DB::select(
            "SELECT a.id, a.username, a.nickname FROM accounts a
             WHERE 1=1{$scopeSql}
             ORDER BY a.username ASC, a.id ASC
             LIMIT 300",
            $scopeParams
        ) ?: [];

        $accountId = (int)$request->get('accountId', $request->get('account_id', 0));
        if ($accountId < 1 && $accountOptions) {
            $accountId = (int)$accountOptions[0]['id'];
        }

        $account = null;
        if ($accountId > 0) {
            $account = DB::selectOne(
                "SELECT a.id, a.username, a.nickname FROM accounts a WHERE a.id=?{$scopeSql} LIMIT 1",
                array_merge([$accountId], $scopeParams)
            );
            if (!$account) {
                return $this->error('账号不存在或无权访问', 403);
            }
        }

        $dailyMap = [];
        $snapshots = [];
        if ($accountId > 0) {
            try {
                $dailyRows = DB::select(
                    'SELECT metric_date, video_views, profile_views, likes_count, comments_count, shares_count, est_rewards
                     FROM tiktok_analytics_28d WHERE account_id=? ORDER BY metric_date ASC',
                    [$accountId]
                ) ?: [];
                foreach ($dailyRows as $row) {
                    $d = substr((string)($row['metric_date'] ?? ''), 0, 10);
                    if ($d === '') {
                        continue;
                    }
                    $dailyMap[$d] = $row;
                }
            } catch (\Throwable $e) {
                $dailyMap = [];
            }
            try {
                $snapshots = DB::select(
                    'SELECT captured_at, followers_count, following_count, likes_count, videos_count
                     FROM tiktok_stat_snapshots WHERE account_id=? ORDER BY captured_at ASC LIMIT 60',
                    [$accountId]
                ) ?: [];
            } catch (\Throwable $e) {
                $snapshots = [];
            }
        }

        $daily28 = $this->buildDaily28Series($dailyMap);

        return $this->success([
            'accountId' => $accountId,
            'account' => $account ? [
                'id' => (int)$account['id'],
                'username' => (string)($account['username'] ?? ''),
                'nickname' => (string)($account['nickname'] ?? ''),
            ] : null,
            'accountOptions' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                ];
            }, $accountOptions),
            'daily28' => $daily28,
            'hasDailyData' => count($dailyMap) > 0,
            'snapshots' => array_map(static function ($r) {
                return [
                    'capturedAt' => (string)($r['captured_at'] ?? ''),
                    'followers' => (int)($r['followers_count'] ?? 0),
                    'following' => (int)($r['following_count'] ?? 0),
                    'likes' => (int)($r['likes_count'] ?? 0),
                    'videos' => (int)($r['videos_count'] ?? 0),
                ];
            }, $snapshots),
            'hasSnapshots' => count($snapshots) > 0,
        ]);
    }

    public function accounts(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $offset = ($page - 1) * $pageSize;

        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $filters = ['1=1'];
        $params = [];
        if ($keyword !== '') {
            $filters[] = '(a.username LIKE ? OR a.nickname LIKE ? OR CAST(a.id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }
        $where = implode(' AND ', $filters) . $scopeSql;
        $bind = array_merge($params, $scopeParams);

        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM accounts a WHERE {$where}", $bind)['c'] ?? 0);
        $rows = [];
        try {
            $rows = DB::select(
                "SELECT a.id, a.username, a.nickname, a.login_status, a.account_status, a.country,
                        a.group_id, g.name AS group_name, a.updated_at, a.created_at,
                        a.profile_avatar, a.notes,
                        COALESCE(p.followers_count, 0) AS followers_count,
                        COALESCE(p.following_count, 0) AS following_count,
                        COALESCE(p.likes_count, 0) AS likes_count,
                        COALESCE(p.avatar_url, '') AS profile_avatar_url
                 FROM accounts a
                 LEFT JOIN `groups` g ON g.id = a.group_id
                 LEFT JOIN tiktok_profiles p ON p.account_id = a.id
                 WHERE {$where}
                 ORDER BY a.id DESC
                 LIMIT {$pageSize} OFFSET {$offset}",
                $bind
            ) ?: [];
        } catch (\Throwable $e) {
            $rows = DB::select(
                "SELECT a.id, a.username, a.nickname, a.login_status, a.account_status, a.country,
                        a.group_id, g.name AS group_name, a.updated_at, a.created_at
                 FROM accounts a
                 LEFT JOIN `groups` g ON g.id = a.group_id
                 WHERE {$where}
                 ORDER BY a.id DESC
                 LIMIT {$pageSize} OFFSET {$offset}",
                $bind
            ) ?: [];
        }

        return $this->success([
            'items' => array_map(static function ($r) {
                $avatar = trim((string)($r['profile_avatar'] ?? ''));
                if ($avatar === '') {
                    $avatar = trim((string)($r['profile_avatar_url'] ?? ''));
                }
                return [
                    'id' => (int)$r['id'],
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                    'loginStatus' => (string)($r['login_status'] ?? ''),
                    'accountStatus' => (string)($r['account_status'] ?? 'normal'),
                    'country' => (string)($r['country'] ?? ''),
                    'groupName' => (string)($r['group_name'] ?? ''),
                    'avatarUrl' => $avatar,
                    'notes' => (string)($r['notes'] ?? ''),
                    'followers' => (int)($r['followers_count'] ?? 0),
                    'following' => (int)($r['following_count'] ?? 0),
                    'likes' => (int)($r['likes_count'] ?? 0),
                    'updatedAt' => (string)($r['updated_at'] ?? ''),
                    'createdAt' => (string)($r['created_at'] ?? ''),
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => max(1, (int)ceil($total / $pageSize)),
        ]);
    }

    public function videos(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();
        PullCommentService::ensureSchema();
        TikTokVideoScreenshotService::ensureSchema();

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(60, max(1, (int)$request->get('pageSize', 24)));
        $keyword = trim((string)$request->get('keyword', ''));
        $accountId = (int)$request->get('accountId', $request->get('account_id', 0));
        $offset = ($page - 1) * $pageSize;

        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $filters = [
            "(m.origin='online' OR (m.publish_status='published' AND TRIM(IFNULL(m.platform_video_id,''))<>''))",
        ];
        $params = [];
        if ($accountId > 0) {
            $filters[] = 'm.publish_account_id=?';
            $params[] = $accountId;
        }
        if ($keyword !== '') {
            $filters[] = '(m.name LIKE ? OR m.share_title LIKE ? OR m.platform_video_id LIKE ? OR a.username LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $where = implode(' AND ', $filters) . $scopeSql;
        $bind = array_merge($params, $scopeParams);

        $total = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE {$where}",
            $bind
        )['c'] ?? 0);

        $rows = DB::select(
            "SELECT m.id, m.name, m.share_title, m.platform_video_id, m.platform_video_url,
                    m.view_count, m.like_count, m.comment_count, m.cover_base64, m.origin,
                    m.platform_published_at, m.updated_at,
                    a.id AS account_id, a.username, a.nickname
             FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE {$where}
             ORDER BY COALESCE(m.platform_published_at, m.updated_at, m.created_at) DESC, m.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $bind
        ) ?: [];

        $videoIds = array_values(array_filter(array_map(
            static fn($r) => trim((string)($r['platform_video_id'] ?? '')),
            $rows
        )));
        // screenshot counts per account+video would need account id; aggregate by video_id only for display hint
        $shotMap = [];
        if ($videoIds) {
            $ph = implode(',', array_fill(0, count($videoIds), '?'));
            $shotRows = DB::select(
                "SELECT video_id, COUNT(*) AS cnt FROM tiktok_video_screenshots
                 WHERE video_id IN ({$ph}) GROUP BY video_id",
                $videoIds
            ) ?: [];
            foreach ($shotRows as $s) {
                $shotMap[(string)$s['video_id']] = (int)$s['cnt'];
            }
        }

        $items = [];
        foreach ($rows as $r) {
            $vid = trim((string)($r['platform_video_id'] ?? ''));
            $cover = trim((string)($r['cover_base64'] ?? ''));
            $coverUrl = '';
            if ($cover !== '' && (str_starts_with($cover, 'http') || str_starts_with($cover, 'data:'))) {
                $coverUrl = $cover;
            } elseif ((int)$r['id'] > 0) {
                $coverUrl = '/api/materials/' . (int)$r['id'] . '/cover';
            }
            $title = trim((string)($r['share_title'] ?? '')) ?: trim((string)($r['name'] ?? '')) ?: ('视频 ' . $vid);
            $items[] = [
                'materialId' => (int)$r['id'],
                'videoId' => $vid,
                'videoUrl' => (string)($r['platform_video_url'] ?? ''),
                'title' => $title,
                'coverUrl' => $coverUrl,
                'views' => (int)($r['view_count'] ?? 0),
                'likes' => (int)($r['like_count'] ?? 0),
                'comments' => (int)($r['comment_count'] ?? 0),
                'origin' => (string)($r['origin'] ?? ''),
                'postedAt' => (string)($r['platform_published_at'] ?? ''),
                'updatedAt' => (string)($r['updated_at'] ?? ''),
                'accountId' => (int)($r['account_id'] ?? 0),
                'accountUsername' => (string)($r['username'] ?? ''),
                'screenshotCount' => (int)($shotMap[$vid] ?? 0),
            ];
        }

        return $this->success([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => max(1, (int)ceil($total / $pageSize)),
        ]);
    }

    public function clues(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        AiLeadExecutionService::ensureSchema();
        DataScope::ensureColumns();

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $offset = ($page - 1) * $pageSize;

        // 企业客户只看「批量抄送」到自己的评论/用户线索（与 /api/ai-lead-clue-data 一致）
        $userId = (int)(($GLOBALS['auth_user']['id'] ?? 0));
        if ($userId <= 0) {
            return $this->success([
                'items' => [],
                'total' => 0,
                'page' => 1,
                'pageSize' => $pageSize,
                'pages' => 1,
            ]);
        }

        $cmFilters = ['su.user_id=?', 'cm.ai_pass=1'];
        $cmBind = [$userId];
        $crFilters = ['su.user_id=?'];
        $crBind = [$userId];
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $cmFilters[] = '(cm.nickname LIKE ? OR cm.username LIKE ? OR cm.comment_text LIKE ? OR l.keywords LIKE ?)';
            array_push($cmBind, $like, $like, $like, $like);
            $crFilters[] = '(c.nickname LIKE ? OR c.username LIKE ? OR l.keywords LIKE ?)';
            array_push($crBind, $like, $like, $like);
        }
        $cmWhere = 'WHERE ' . implode(' AND ', $cmFilters);
        $crWhere = 'WHERE ' . implode(' AND ', $crFilters);
        $unionSql = "(
            SELECT 'comment' AS source_type, cm.id, cm.lead_id, cm.username, cm.nickname, cm.comment_text,
                   cm.intent_score, cm.profile_url, cm.created_at, l.name AS lead_name, l.keywords
            FROM ai_lead_comments cm
            INNER JOIN ai_lead_comment_shared_users su ON su.comment_id = cm.id
            INNER JOIN ai_leads l ON l.id = cm.lead_id
            {$cmWhere}
          ) UNION ALL (
            SELECT 'creator' AS source_type, c.id, c.lead_id, c.username, c.nickname, '' AS comment_text,
                   c.intent_score, c.profile_url, c.created_at, l.name AS lead_name, l.keywords
            FROM ai_lead_creators c
            INNER JOIN ai_lead_creator_shared_users su ON su.creator_id = c.id
            INNER JOIN ai_leads l ON l.id = c.lead_id
            {$crWhere}
          )";
        $bind = array_merge($cmBind, $crBind);

        $total = 0;
        $rows = [];
        try {
            $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM ({$unionSql}) x", $bind)['c'] ?? 0);
            $rows = DB::select(
                "SELECT * FROM ({$unionSql}) x
                 ORDER BY x.intent_score DESC, x.created_at DESC, x.id DESC
                 LIMIT {$pageSize} OFFSET {$offset}",
                $bind
            ) ?: [];
        } catch (\Throwable $e) {
            return $this->success([
                'items' => [],
                'total' => 0,
                'page' => 1,
                'pageSize' => $pageSize,
                'pages' => 1,
                'message' => '暂无线索数据或无权限',
            ]);
        }

        return $this->success([
            'items' => array_map(static function ($r) {
                $sourceType = (string)($r['source_type'] ?? 'comment');
                return [
                    'id' => (int)$r['id'],
                    'leadId' => (int)($r['lead_id'] ?? 0),
                    'leadName' => (string)($r['lead_name'] ?? ''),
                    'sourceType' => $sourceType,
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                    'commentText' => (string)($r['comment_text'] ?? ''),
                    'intentScore' => (int)($r['intent_score'] ?? 0),
                    'dmStatus' => '',
                    'profileUrl' => (string)($r['profile_url'] ?? ''),
                    'keywords' => (string)($r['keywords'] ?? ''),
                    'createdAt' => (string)($r['created_at'] ?? ''),
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => max(1, (int)ceil($total / max(1, $pageSize))),
        ]);
    }

    public function analytics(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();
        PullCommentService::ensureSchema();

        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');

        $byLogin = DB::select(
            "SELECT COALESCE(NULLIF(a.login_status,''),'unknown') AS k, COUNT(*) AS c
             FROM accounts a WHERE 1=1{$scopeSql}
             GROUP BY COALESCE(NULLIF(a.login_status,''),'unknown')",
            $scopeParams
        ) ?: [];

        $topVideos = DB::select(
            "SELECT m.id, m.share_title, m.name, m.view_count, m.like_count, m.platform_video_id,
                    a.username
             FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE (m.origin='online' OR m.publish_status='published'){$scopeSql}
             ORDER BY m.view_count DESC, m.like_count DESC
             LIMIT 10",
            $scopeParams
        ) ?: [];

        $dailyViews = [];
        try {
            $dailyViews = DB::select(
                "SELECT DATE(COALESCE(m.platform_published_at, m.created_at)) AS d,
                        SUM(m.view_count) AS views, COUNT(*) AS posts
                 FROM materials m
                 INNER JOIN accounts a ON a.id = m.publish_account_id
                 WHERE (m.origin='online' OR m.publish_status='published'){$scopeSql}
                   AND COALESCE(m.platform_published_at, m.created_at) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                 GROUP BY DATE(COALESCE(m.platform_published_at, m.created_at))
                 ORDER BY d ASC",
                $scopeParams
            ) ?: [];
        } catch (\Throwable $e) {
            $dailyViews = [];
        }

        return $this->success([
            'loginBreakdown' => array_map(static fn($r) => [
                'key' => (string)$r['k'],
                'count' => (int)$r['c'],
            ], $byLogin),
            'topVideos' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'title' => trim((string)($r['share_title'] ?? '')) ?: (string)($r['name'] ?? ''),
                'views' => (int)($r['view_count'] ?? 0),
                'likes' => (int)($r['like_count'] ?? 0),
                'videoId' => (string)($r['platform_video_id'] ?? ''),
                'username' => (string)($r['username'] ?? ''),
            ], $topVideos),
            'daily' => array_map(static fn($r) => [
                'date' => (string)$r['d'],
                'views' => (int)($r['views'] ?? 0),
                'posts' => (int)($r['posts'] ?? 0),
            ], $dailyViews),
        ]);
    }

    /**
     * 企业门户账号详情（基本信息 + 画像指标 + 近期视频 + 趋势）
     */
    public function accountDetail(Request $request, string $id): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();
        PullCommentService::ensureSchema();

        $aid = (int)$id;
        if ($aid < 1) {
            return $this->error('账号无效', 422);
        }
        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $account = DB::selectOne(
            "SELECT a.id, a.username, a.nickname, a.login_status, a.account_status, a.country,
                    a.group_id, g.name AS group_name, a.notes, a.profile_avatar, a.profile_bio,
                    a.created_at, a.updated_at, a.enabled
             FROM accounts a
             LEFT JOIN `groups` g ON g.id = a.group_id
             WHERE a.id=?{$scopeSql}
             LIMIT 1",
            array_merge([$aid], $scopeParams)
        );
        if (!$account) {
            return $this->error('账号不存在或无权访问', 403);
        }

        $profile = null;
        try {
            $profile = DB::selectOne('SELECT * FROM tiktok_profiles WHERE account_id=? LIMIT 1', [$aid]);
        } catch (\Throwable $e) {
            $profile = null;
        }

        $avatar = trim((string)($account['profile_avatar'] ?? ''));
        if ($avatar === '' && $profile) {
            $avatar = trim((string)($profile['avatar_url'] ?? ''));
        }

        $recentVideos = [];
        try {
            $vrows = DB::select(
                "SELECT m.id, m.name, m.share_title, m.platform_video_id, m.platform_video_url,
                        m.view_count, m.like_count, m.comment_count, m.cover_base64, m.origin,
                        m.publish_status, m.platform_published_at, m.updated_at
                 FROM materials m
                 WHERE m.publish_account_id=?
                   AND (m.origin='online' OR (m.publish_status='published' AND TRIM(IFNULL(m.platform_video_id,''))<>''))
                 ORDER BY COALESCE(m.platform_published_at, m.updated_at, m.created_at) DESC, m.id DESC
                 LIMIT 12",
                [$aid]
            ) ?: [];
            foreach ($vrows as $r) {
                $cover = trim((string)($r['cover_base64'] ?? ''));
                $coverUrl = '';
                if ($cover !== '' && (str_starts_with($cover, 'http') || str_starts_with($cover, 'data:'))) {
                    $coverUrl = $cover;
                } elseif ((int)$r['id'] > 0) {
                    $coverUrl = '/api/materials/' . (int)$r['id'] . '/cover';
                }
                $title = trim((string)($r['share_title'] ?? '')) ?: trim((string)($r['name'] ?? '')) ?: ('视频 ' . (string)($r['platform_video_id'] ?? ''));
                $recentVideos[] = [
                    'materialId' => (int)$r['id'],
                    'videoId' => trim((string)($r['platform_video_id'] ?? '')),
                    'videoUrl' => (string)($r['platform_video_url'] ?? ''),
                    'title' => $title,
                    'coverUrl' => $coverUrl,
                    'views' => (int)($r['view_count'] ?? 0),
                    'likes' => (int)($r['like_count'] ?? 0),
                    'comments' => (int)($r['comment_count'] ?? 0),
                    'origin' => (string)($r['origin'] ?? ''),
                    'publishStatus' => (string)($r['publish_status'] ?? ''),
                    'postedAt' => (string)($r['platform_published_at'] ?? ''),
                    'updatedAt' => (string)($r['updated_at'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            $recentVideos = [];
        }

        $videoTotal = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM materials m
             WHERE m.publish_account_id=?
               AND (m.origin='online' OR (m.publish_status='published' AND TRIM(IFNULL(m.platform_video_id,''))<>''))",
            [$aid]
        )['c'] ?? 0);

        $dailyMap = [];
        try {
            $dailyRows = DB::select(
                'SELECT metric_date, video_views, profile_views, likes_count, comments_count, shares_count, est_rewards
                 FROM tiktok_analytics_28d WHERE account_id=? ORDER BY metric_date ASC',
                [$aid]
            ) ?: [];
            foreach ($dailyRows as $row) {
                $d = substr((string)($row['metric_date'] ?? ''), 0, 10);
                if ($d !== '') {
                    $dailyMap[$d] = $row;
                }
            }
        } catch (\Throwable $e) {
            $dailyMap = [];
        }
        $daily28 = $this->buildDaily28Series($dailyMap);

        $snapshots = [];
        try {
            $snapshots = DB::select(
                'SELECT captured_at, followers_count, following_count, likes_count, videos_count
                 FROM tiktok_stat_snapshots WHERE account_id=? ORDER BY captured_at DESC LIMIT 30',
                [$aid]
            ) ?: [];
        } catch (\Throwable $e) {
            $snapshots = [];
        }

        return $this->success([
            'account' => [
                'id' => (int)$account['id'],
                'username' => (string)($account['username'] ?? ''),
                'nickname' => (string)($account['nickname'] ?? ''),
                'loginStatus' => (string)($account['login_status'] ?? ''),
                'accountStatus' => (string)($account['account_status'] ?? 'normal'),
                'country' => (string)($account['country'] ?? ''),
                'groupName' => (string)($account['group_name'] ?? ''),
                'notes' => (string)($account['notes'] ?? ''),
                'bio' => (string)($account['profile_bio'] ?? ($profile['bio'] ?? '')),
                'avatarUrl' => $avatar,
                'enabled' => (int)($account['enabled'] ?? 1) === 1,
                'createdAt' => (string)($account['created_at'] ?? ''),
                'updatedAt' => (string)($account['updated_at'] ?? ''),
            ],
            'stats' => [
                'followers' => (int)($profile['followers_count'] ?? 0),
                'following' => (int)($profile['following_count'] ?? 0),
                'likes' => (int)($profile['likes_count'] ?? 0),
                'videos' => $videoTotal,
                'periodVideoViews' => (int)($profile['period_video_views'] ?? 0),
                'periodProfileViews' => (int)($profile['period_profile_views'] ?? 0),
                'periodLikes' => (int)($profile['period_likes'] ?? 0),
                'periodComments' => (int)($profile['period_comments'] ?? 0),
                'periodShares' => (int)($profile['period_shares'] ?? ($profile['shares_count'] ?? 0)),
                'periodEstRewards' => (float)($profile['period_est_rewards'] ?? 0),
            ],
            'recentVideos' => $recentVideos,
            'daily28' => $daily28,
            'hasDailyData' => count($dailyMap) > 0,
            'snapshots' => array_map(static function ($r) {
                return [
                    'capturedAt' => (string)($r['captured_at'] ?? ''),
                    'followers' => (int)($r['followers_count'] ?? 0),
                    'following' => (int)($r['following_count'] ?? 0),
                    'likes' => (int)($r['likes_count'] ?? 0),
                    'videos' => (int)($r['videos_count'] ?? 0),
                ];
            }, array_reverse($snapshots)),
            'hasSnapshots' => count($snapshots) > 0,
        ]);
    }

    /**
     * 企业门户视频详情（素材信息 + 账号 + 截图）
     */
    public function videoDetail(Request $request, string $id): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();
        PullCommentService::ensureSchema();
        TikTokVideoScreenshotService::ensureSchema();

        $mid = (int)$id;
        if ($mid < 1) {
            return $this->error('视频无效', 422);
        }
        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $row = DB::selectOne(
            "SELECT m.id, m.name, m.share_title, m.share_caption, m.platform_video_id, m.platform_video_url,
                    m.view_count, m.like_count, m.comment_count, m.cover_base64, m.origin,
                    m.publish_status, m.platform_published_at, m.updated_at, m.created_at, m.file_name,
                    a.id AS account_id, a.username, a.nickname, a.profile_avatar, a.login_status
             FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE m.id=?{$scopeSql}
             LIMIT 1",
            array_merge([$mid], $scopeParams)
        );
        if (!$row) {
            return $this->error('视频不存在或无权访问', 403);
        }

        $cover = trim((string)($row['cover_base64'] ?? ''));
        $coverUrl = '';
        if ($cover !== '' && (str_starts_with($cover, 'http') || str_starts_with($cover, 'data:'))) {
            $coverUrl = $cover;
        } else {
            $coverUrl = '/api/materials/' . $mid . '/cover';
        }
        $vid = trim((string)($row['platform_video_id'] ?? ''));
        $accountId = (int)($row['account_id'] ?? 0);
        $title = trim((string)($row['share_title'] ?? '')) ?: trim((string)($row['name'] ?? '')) ?: ('视频 ' . $vid);

        $shots = [];
        if ($accountId > 0 && $vid !== '') {
            try {
                $shots = TikTokVideoScreenshotService::listLatest($accountId, $vid, 5, true);
            } catch (\Throwable $e) {
                $shots = [];
            }
        }

        $avatar = trim((string)($row['profile_avatar'] ?? ''));

        return $this->success([
            'video' => [
                'materialId' => $mid,
                'videoId' => $vid,
                'videoUrl' => (string)($row['platform_video_url'] ?? ''),
                'title' => $title,
                'caption' => (string)($row['share_caption'] ?? ''),
                'fileName' => (string)($row['file_name'] ?? ''),
                'coverUrl' => $coverUrl,
                'views' => (int)($row['view_count'] ?? 0),
                'likes' => (int)($row['like_count'] ?? 0),
                'comments' => (int)($row['comment_count'] ?? 0),
                'origin' => (string)($row['origin'] ?? ''),
                'publishStatus' => (string)($row['publish_status'] ?? ''),
                'postedAt' => (string)($row['platform_published_at'] ?? ''),
                'createdAt' => (string)($row['created_at'] ?? ''),
                'updatedAt' => (string)($row['updated_at'] ?? ''),
            ],
            'account' => [
                'id' => $accountId,
                'username' => (string)($row['username'] ?? ''),
                'nickname' => (string)($row['nickname'] ?? ''),
                'avatarUrl' => $avatar,
                'loginStatus' => (string)($row['login_status'] ?? ''),
            ],
            'screenshots' => $shots,
        ]);
    }

    /** 发布视频可选账号（企业可见范围） */
    public function publishAccounts(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $rows = DB::select(
            "SELECT a.id, a.username, a.nickname, a.login_status
             FROM accounts a
             WHERE 1=1{$scopeSql}
             ORDER BY a.updated_at DESC, a.id DESC
             LIMIT 500",
            $scopeParams
        ) ?: [];
        return $this->success([
            'items' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                    'loginStatus' => (string)($r['login_status'] ?? ''),
                    'label' => trim((string)(($r['nickname'] ?? '') !== '' ? $r['nickname'] : ($r['username'] ?? '')))
                        ?: ('#' . (int)$r['id']),
                ];
            }, $rows),
        ]);
    }

    /** 本企业客户提交的委托发布任务 */
    public function publishTasks(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        $userId = (int)(($GLOBALS['auth_user']['id'] ?? 0));
        if ($userId <= 0) {
            return $this->success(['items' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'pages' => 1]);
        }
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(50, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;

        $filters = [
            't.owner_id=?',
            't.type=?',
            "JSON_UNQUOTE(JSON_EXTRACT(t.payload, '$.source'))='company_entrust'",
        ];
        $params = [$userId, 'publish'];
        $where = 'WHERE ' . implode(' AND ', $filters);
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM tasks t {$where}", $params)['c'] ?? 0);
        $rows = DB::select(
            "SELECT t.* FROM tasks t {$where} ORDER BY t.id DESC LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        return $this->success([
            'items' => array_map([$this, 'mapCompanyPublishTask'], $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => max(1, (int)ceil($total / max(1, $pageSize))),
        ]);
    }

    /**
     * 企业委托发布：选账号 + 上传视频 → draft 任务（运营专员启动）
     */
    public function createPublishTask(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();

        $user = $GLOBALS['auth_user'] ?? [];
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->error('未登录', 401);
        }

        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        if ($accountId <= 0) {
            return $this->error('请选择发布账号', 422);
        }

        $account = DB::selectOne('SELECT id, username, nickname, owner_id, company_id FROM accounts WHERE id=?', [$accountId]);
        if (!$account) {
            return $this->error('账号不存在', 404);
        }
        if ($err = DataScope::assertAccountOwns($account)) {
            return $err;
        }

        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->error('请上传视频文件', 422);
        }

        $title = trim((string)$request->input('title', $request->input('publishTitle', '')));
        $caption = trim((string)$request->input('caption', $request->input('publishCaption', '')));
        $taskName = trim((string)$request->input('name', ''));
        if ($taskName === '') {
            $accLabel = (string)(($account['nickname'] ?? '') !== '' ? $account['nickname'] : ($account['username'] ?? ''));
            $taskName = '企业委托发布 · ' . ($accLabel !== '' ? $accLabel : ('#' . $accountId));
        }

        $mat = $this->storeCompanyMaterial($_FILES['file'], $title !== '' ? $title : '', $userId);
        if ($mat instanceof Response) {
            return $mat;
        }
        $materialId = (int)($mat['id'] ?? 0);
        if ($materialId <= 0) {
            return $this->error('素材入库失败', 500);
        }

        CompanyService::ensureSchema();
        $companyIds = CompanyService::companyIdsOfUser($userId);
        $payload = [
            'source' => 'company_entrust',
            'publishMode' => 'independent',
            'publishStrategy' => 'deduplicate',
            'accountIds' => [$accountId],
            'materialIds' => [$materialId],
            'publishTitle' => $title,
            'publishCaption' => $caption,
            'pushChannel' => 'tkswarm',
            'hashtags' => '',
            'entrustCompanyIds' => $companyIds,
            'entrustBy' => [
                'userId' => $userId,
                'username' => (string)($user['username'] ?? ''),
                'nickname' => (string)($user['nickname'] ?? ''),
            ],
            'entrustAccount' => [
                'id' => $accountId,
                'username' => (string)($account['username'] ?? ''),
                'nickname' => (string)($account['nickname'] ?? ''),
            ],
            'entrustMaterial' => [
                'id' => $materialId,
                'name' => (string)($mat['name'] ?? ''),
                'fileName' => (string)($mat['file_name'] ?? ''),
            ],
        ];

        DB::query(
            'INSERT INTO tasks(name,type,group_id,total_count,scheduled_at,payload,status,owner_id) VALUES (?,?,?,?,?,?,"draft",?)',
            [
                mb_substr($taskName, 0, 120),
                'publish',
                null,
                1,
                null,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $userId,
            ]
        );
        $taskId = (int)DB::getPdo()->lastInsertId();
        $row = DB::selectOne('SELECT * FROM tasks WHERE id=?', [$taskId]);

        return $this->success([
            'task' => $this->mapCompanyPublishTask($row ?: ['id' => $taskId, 'type' => 'publish', 'payload' => json_encode($payload), 'status' => 'draft', 'name' => $taskName]),
            'materialId' => $materialId,
            'accountId' => $accountId,
        ], '委托任务已创建，等待运营处理');
    }

    /** 回复评论模板（固定/AI，含内置） */
    public function commentReplyTemplates(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        $userId = (int)(($GLOBALS['auth_user']['id'] ?? 0));
        $mode = strtolower(trim((string)$request->get('contentMode', $request->get('content_mode', ''))));
        $filters = ["type='comment_reply'", 'enabled=1', '(owner_id=? OR owner_id IS NULL OR is_builtin=1)'];
        $params = [$userId];
        if (in_array($mode, ['fixed', 'ai'], true)) {
            $filters[] = 'content_mode=?';
            $params[] = $mode;
        }
        $where = 'WHERE ' . implode(' AND ', $filters);
        $rows = [];
        try {
            $rows = DB::select(
                "SELECT id, name, content_mode, content, fixed_text, ai_prompt, is_builtin
                 FROM interaction_templates {$where}
                 ORDER BY FIELD(content_mode,'fixed','ai'), sort_order ASC, id DESC
                 LIMIT 200",
                $params
            ) ?: [];
        } catch (\Throwable $e) {
            $rows = [];
        }
        return $this->success([
            'items' => array_map(static function ($r) {
                $cm = (string)($r['content_mode'] ?? 'fixed');
                $text = $cm === 'ai'
                    ? (string)($r['ai_prompt'] ?? $r['content'] ?? '')
                    : (string)($r['fixed_text'] ?? $r['content'] ?? '');
                return [
                    'id' => (int)($r['id'] ?? 0),
                    'name' => (string)($r['name'] ?? ''),
                    'contentMode' => $cm,
                    'text' => $text,
                    'isBuiltin' => !empty($r['is_builtin']),
                ];
            }, $rows),
        ]);
    }

    /** 企业委托：回复评论 → draft */
    public function createCommentReplyTask(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        $user = $GLOBALS['auth_user'] ?? [];
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->error('未登录', 401);
        }
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        if ($accountId <= 0) {
            return $this->error('请选择账号', 422);
        }
        $account = DB::selectOne('SELECT id, username, nickname FROM accounts WHERE id=?', [$accountId]);
        if (!$account) {
            return $this->error('账号不存在', 404);
        }
        if ($err = DataScope::assertAccountOwns($account)) {
            return $err;
        }

        $replyMode = strtolower(trim((string)$request->input('replyMode', $request->input('reply_mode', 'fixed'))));
        if (!in_array($replyMode, ['fixed', 'ai'], true)) {
            $replyMode = 'fixed';
        }
        $templateIds = $request->input('replyTemplateIds', $request->input('reply_template_ids', []));
        if (!is_array($templateIds)) {
            $templateIds = preg_split('/[,\s]+/', (string)$templateIds) ?: [];
        }
        $templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds))));
        $replyTexts = $request->input('replyTexts', $request->input('reply_texts', []));
        if (!is_array($replyTexts)) {
            $replyTexts = preg_split('/\r?\n/', (string)$replyTexts) ?: [];
        }
        $replyTexts = array_values(array_filter(array_map(static fn ($t) => trim((string)$t), $replyTexts)));
        $aiTemplateId = (int)$request->input('replyAiTemplateId', $request->input('reply_ai_template_id', 0));
        $aiPrompt = trim((string)$request->input('replyAiPrompt', $request->input('reply_ai_prompt', '')));
        $maxReplies = max(1, min(50, (int)$request->input('maxReplies', $request->input('max_replies', 10))));

        if ($replyMode === 'fixed' && !$templateIds && !$replyTexts) {
            return $this->error('请至少选择一条固定回复模板或填写回复内容', 422);
        }
        if ($replyMode === 'ai' && $aiTemplateId < 1 && $aiPrompt === '') {
            return $this->error('请选择 AI 回复模板或填写提示词', 422);
        }

        CompanyService::ensureSchema();
        $companyIds = CompanyService::companyIdsOfUser($userId);
        $accLabel = (string)(($account['nickname'] ?? '') !== '' ? $account['nickname'] : ($account['username'] ?? ''));
        $taskName = trim((string)$request->input('name', ''));
        if ($taskName === '') {
            $taskName = '企业委托回复评论 · ' . ($accLabel !== '' ? $accLabel : ('#' . $accountId));
        }
        $payload = [
            'source' => 'company_entrust',
            'entrustKind' => 'comment_reply',
            'accountIds' => [$accountId],
            'accountId' => $accountId,
            'replyMode' => $replyMode,
            'replyTemplateIds' => $replyMode === 'fixed' ? $templateIds : [],
            'replyTexts' => $replyMode === 'fixed' ? $replyTexts : [],
            'replyAiTemplateId' => $replyMode === 'ai' ? $aiTemplateId : 0,
            'replyAiPrompt' => $replyMode === 'ai' ? $aiPrompt : '',
            'maxReplies' => $maxReplies,
            'saveTraffic' => true,
            'entrustCompanyIds' => $companyIds,
            'entrustBy' => [
                'userId' => $userId,
                'username' => (string)($user['username'] ?? ''),
                'nickname' => (string)($user['nickname'] ?? ''),
            ],
            'entrustAccount' => [
                'id' => $accountId,
                'username' => (string)($account['username'] ?? ''),
                'nickname' => (string)($account['nickname'] ?? ''),
            ],
        ];
        return $this->insertCompanyEntrustTask($taskName, 'comment_reply', $payload, $userId, 1);
    }

    /** 企业委托：拉取视频列表 → draft */
    public function createPullVideoListTask(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        $user = $GLOBALS['auth_user'] ?? [];
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->error('未登录', 401);
        }
        $accountIds = $request->input('accountIds', $request->input('account_ids', []));
        if (!is_array($accountIds)) {
            $accountIds = [(int)$request->input('accountId', $request->input('account_id', 0))];
        }
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if (!$accountIds) {
            return $this->error('请选择至少一个账号', 422);
        }
        $accounts = [];
        foreach ($accountIds as $aid) {
            $account = DB::selectOne('SELECT id, username, nickname FROM accounts WHERE id=?', [$aid]);
            if (!$account) {
                return $this->error('账号不存在: #' . $aid, 404);
            }
            if ($err = DataScope::assertAccountOwns($account)) {
                return $err;
            }
            $accounts[] = $account;
        }
        $backend = strtolower(trim((string)$request->input('browserBackend', $request->input('browser_backend', 'tkswarm'))));
        if (!in_array($backend, ['bit', 'tkswarm'], true)) {
            $backend = 'tkswarm';
        }
        CompanyService::ensureSchema();
        $companyIds = CompanyService::companyIdsOfUser($userId);
        $first = $accounts[0];
        $accLabel = (string)(($first['nickname'] ?? '') !== '' ? $first['nickname'] : ($first['username'] ?? ''));
        $taskName = trim((string)$request->input('name', ''));
        if ($taskName === '') {
            $suffix = count($accounts) > 1 ? ($accLabel . ' 等' . count($accounts) . '个账号') : $accLabel;
            $taskName = '企业委托拉取视频列表 · ' . ($suffix !== '' ? $suffix : ('#' . (int)$first['id']));
        }
        $payload = [
            'source' => 'company_entrust',
            'entrustKind' => 'pull_video_list',
            'accountIds' => $accountIds,
            'browserBackend' => $backend,
            'entrustCompanyIds' => $companyIds,
            'entrustBy' => [
                'userId' => $userId,
                'username' => (string)($user['username'] ?? ''),
                'nickname' => (string)($user['nickname'] ?? ''),
            ],
            'entrustAccount' => [
                'id' => (int)$first['id'],
                'username' => (string)($first['username'] ?? ''),
                'nickname' => (string)($first['nickname'] ?? ''),
            ],
            'entrustAccountCount' => count($accountIds),
        ];
        return $this->insertCompanyEntrustTask($taskName, 'pull_video_list', $payload, $userId, count($accountIds));
    }

    /** 已发布素材（拉取视频详情用） */
    public function publishedMaterials(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();
        PullCommentService::ensureSchema();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;
        $filters = [
            "m.publish_status='published'",
            'm.publish_account_id>0',
            "(m.platform_video_url<>'' OR m.platform_video_id<>'')",
        ];
        $params = [];
        [$matSql, $matParams] = DataScope::materialListSql('m');
        $params = array_merge($params, $matParams);
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') {
            $filters[] = '(m.name LIKE ? OR m.file_name LIKE ? OR m.platform_video_id LIKE ? OR m.share_title LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $where = 'WHERE ' . implode(' AND ', $filters) . $matSql;
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM materials m {$where}", $params)['c'] ?? 0);
        $rows = DB::select(
            "SELECT m.id, m.name, m.file_name, m.platform_video_id, m.platform_video_url, m.publish_account_id, m.publish_account, m.origin
             FROM materials m
             {$where}
             ORDER BY m.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];
        return $this->success([
            'items' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'name' => (string)($r['name'] ?? ''),
                    'fileName' => (string)($r['file_name'] ?? ''),
                    'platformVideoId' => (string)($r['platform_video_id'] ?? ''),
                    'platformVideoUrl' => (string)($r['platform_video_url'] ?? ''),
                    'publishAccountId' => (int)($r['publish_account_id'] ?? 0),
                    'publishAccount' => (string)($r['publish_account'] ?? ''),
                    'origin' => (string)($r['origin'] ?? ''),
                    'label' => trim((string)($r['name'] ?? '')) !== ''
                        ? (string)$r['name']
                        : ((string)($r['file_name'] ?? '') ?: ('#' . (int)$r['id'])),
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => max(1, (int)ceil($total / max(1, $pageSize))),
        ]);
    }

    /** 企业委托：拉取视频详情 → draft */
    public function createPullCommentsTask(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();
        $user = $GLOBALS['auth_user'] ?? [];
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->error('未登录', 401);
        }
        $materialIds = $request->input('materialIds', $request->input('material_ids', []));
        if (!is_array($materialIds)) {
            $materialIds = preg_split('/[,\s]+/', (string)$materialIds) ?: [];
        }
        $materialIds = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        if (!$materialIds) {
            return $this->error('请至少选择一个已发布视频', 422);
        }
        $ph = implode(',', array_fill(0, count($materialIds), '?'));
        [$matSql, $matParams] = DataScope::materialListSql('m');
        $rows = DB::select(
            "SELECT m.id, m.name, m.publish_account_id, m.publish_account, m.platform_video_id, m.platform_video_url
             FROM materials m
             WHERE m.id IN ({$ph}) AND m.publish_status='published' AND m.publish_account_id>0
               AND (m.platform_video_url<>'' OR m.platform_video_id<>''){$matSql}",
            array_merge($materialIds, $matParams)
        ) ?: [];
        if (!$rows) {
            return $this->error('未找到可见的已发布视频素材', 422);
        }
        $okIds = array_map(static fn ($r) => (int)$r['id'], $rows);
        $first = $rows[0];
        $accountId = (int)($first['publish_account_id'] ?? 0);
        $account = $accountId > 0
            ? DB::selectOne('SELECT id, username, nickname FROM accounts WHERE id=?', [$accountId])
            : null;
        $backend = strtolower(trim((string)$request->input('browserBackend', $request->input('browser_backend', 'tkswarm'))));
        if (!in_array($backend, ['bit', 'tkswarm'], true)) {
            $backend = 'tkswarm';
        }
        CompanyService::ensureSchema();
        $companyIds = CompanyService::companyIdsOfUser($userId);
        $taskName = trim((string)$request->input('name', ''));
        if ($taskName === '') {
            $taskName = '企业委托拉取视频详情 · ' . count($okIds) . ' 个视频';
        }
        $payload = [
            'source' => 'company_entrust',
            'entrustKind' => 'pull_comments',
            'materialIds' => $okIds,
            'accountIds' => $accountId > 0 ? [$accountId] : [],
            'browserBackend' => $backend,
            'entrustCompanyIds' => $companyIds,
            'entrustBy' => [
                'userId' => $userId,
                'username' => (string)($user['username'] ?? ''),
                'nickname' => (string)($user['nickname'] ?? ''),
            ],
            'entrustAccount' => [
                'id' => $accountId,
                'username' => (string)($account['username'] ?? $first['publish_account'] ?? ''),
                'nickname' => (string)($account['nickname'] ?? ''),
            ],
            'entrustMaterial' => [
                'id' => (int)$first['id'],
                'name' => (string)($first['name'] ?? ''),
                'count' => count($okIds),
            ],
        ];
        return $this->insertCompanyEntrustTask($taskName, 'pull_comments', $payload, $userId, count($okIds));
    }

    /** 本企业客户委托任务列表（按 type 过滤） */
    public function entrustTasks(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        $userId = (int)(($GLOBALS['auth_user']['id'] ?? 0));
        if ($userId <= 0) {
            return $this->success(['items' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'pages' => 1]);
        }
        $type = trim((string)$request->get('type', 'publish'));
        $allowed = ['publish', 'comment_reply', 'pull_video_list', 'pull_comments'];
        if (!in_array($type, $allowed, true)) {
            $type = 'publish';
        }
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(50, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;
        $filters = [
            't.owner_id=?',
            't.type=?',
            "JSON_UNQUOTE(JSON_EXTRACT(t.payload, '$.source'))='company_entrust'",
        ];
        $params = [$userId, $type];
        $where = 'WHERE ' . implode(' AND ', $filters);
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM tasks t {$where}", $params)['c'] ?? 0);
        $rows = DB::select(
            "SELECT t.* FROM tasks t {$where} ORDER BY t.id DESC LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];
        return $this->success([
            'items' => array_map([$this, 'mapCompanyPublishTask'], $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => max(1, (int)ceil($total / max(1, $pageSize))),
        ]);
    }

    /**
     * 企业客服坐席：账号列表（只读，企业权限范围）
     */
    public function chatAccounts(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $includeId = (int)$request->get('includeId', $request->get('accountId', 0));

        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $filters = ['1=1'];
        $params = [];
        if ($keyword !== '') {
            $filters[] = '(a.username LIKE ? OR a.nickname LIKE ? OR CAST(a.id AS CHAR) LIKE ?)';
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }
        $where = 'WHERE ' . implode(' AND ', $filters) . $scopeSql;
        $params = array_merge($params, $scopeParams);

        $from = 'FROM accounts a
             LEFT JOIN chat_connections c ON c.account_id=a.id';
        $select = "SELECT a.id, a.username, a.nickname, a.login_status, a.chat_status, a.enabled,
                COALESCE(c.status,'offline') AS connection_status,
                (SELECT COUNT(*) FROM chat_users f WHERE f.account_id=a.id) AS friend_count,
                (SELECT COALESCE(SUM(f.unread_count),0) FROM chat_users f WHERE f.account_id=a.id) AS unread_count";

        $stats = DB::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN a.login_status='online' THEN 1 ELSE 0 END) AS online
             {$from} {$where}",
            $params
        ) ?: ['total' => 0, 'online' => 0];
        $unreadRow = DB::selectOne(
            "SELECT COALESCE(SUM(f.unread_count),0) AS unread
             FROM chat_users f
             INNER JOIN accounts a ON a.id=f.account_id
             {$where}",
            $params
        );
        $total = (int)($stats['total'] ?? 0);
        $pages = max(1, (int)ceil($total / max(1, $pageSize)));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "{$select} {$from} {$where}
             ORDER BY CASE a.login_status WHEN 'online' THEN 0 WHEN 'checking' THEN 1 ELSE 2 END, a.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        $selected = null;
        if ($includeId > 0) {
            foreach ($rows as $x) {
                if ((int)($x['id'] ?? 0) === $includeId) {
                    $selected = $x;
                    break;
                }
            }
            if (!$selected) {
                $selected = DB::selectOne(
                    "{$select} {$from} WHERE a.id=?{$scopeSql} LIMIT 1",
                    array_merge([$includeId], $scopeParams)
                );
            }
        }

        $mapAccount = static function (?array $r): ?array {
            if (!$r) {
                return null;
            }
            return [
                'id' => (int)$r['id'],
                'username' => (string)($r['username'] ?? ''),
                'nickname' => (string)($r['nickname'] ?? ''),
                'loginStatus' => (string)($r['login_status'] ?? ''),
                'chatStatus' => (string)($r['chat_status'] ?? ''),
                'enabled' => (int)($r['enabled'] ?? 1) === 1,
                'connectionStatus' => (string)($r['connection_status'] ?? 'offline'),
                'friendCount' => (int)($r['friend_count'] ?? 0),
                'unreadCount' => (int)($r['unread_count'] ?? 0),
            ];
        };

        return $this->success([
            'items' => array_values(array_filter(array_map($mapAccount, $rows))),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => $pageSize,
            'keyword' => $keyword,
            'selected' => $mapAccount($selected),
            'stats' => [
                'total' => $total,
                'online' => (int)($stats['online'] ?? 0),
                'unread' => (int)($unreadRow['unread'] ?? 0),
            ],
            'capabilities' => [
                'pullDm' => false,
                'analyze' => false,
                'pullFollow' => false,
                'searchUser' => false,
                'send' => false,
            ],
        ]);
    }

    /**
     * 企业客服坐席：好友/粉丝/关注列表（只读，可看类型用户，不可拉取）
     */
    public function chatFriends(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();

        $accountId = (int)$request->get('accountId', $request->get('account_id', 0));
        if ($accountId < 1) {
            return $this->error('请选择账号', 400);
        }
        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $account = DB::selectOne(
            "SELECT a.id FROM accounts a WHERE a.id=?{$scopeSql} LIMIT 1",
            array_merge([$accountId], $scopeParams)
        );
        if (!$account) {
            return $this->error('账号不存在或无权访问', 403);
        }

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(200, max(1, (int)$request->get('pageSize', 100)));
        $offset = ($page - 1) * $pageSize;
        $filter = (string)$request->get('filter', 'all');
        $keyword = trim((string)$request->get('keyword', ''));

        $filters = ['f.account_id=?'];
        $params = [$accountId];
        if ($filter === 'follower') {
            $filters[] = "f.relation IN ('follower','mutual')";
        } elseif ($filter === 'following') {
            $filters[] = "f.relation IN ('following','mutual')";
        } elseif ($filter === 'dm') {
            // 私信会话：有过消息，或标记为 dm
            $filters[] = "(f.user_type='dm' OR IFNULL(f.last_message,'')<>'')";
        }
        if ($keyword !== '') {
            $filters[] = '(f.username LIKE ? OR f.nickname LIKE ? OR f.friend_uid LIKE ?)';
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }
        DataScope::applyAccountListScope('a.owner_id', $filters, $params);
        $where = 'WHERE ' . implode(' AND ', $filters);
        $from = 'FROM chat_users f JOIN accounts a ON a.id=f.account_id';
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c {$from} {$where}", $params)['c'] ?? 0);
        $items = DB::select(
            "SELECT f.*, a.username AS account_username
             {$from} {$where}
             ORDER BY f.is_pinned DESC, f.last_message_at DESC, f.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        $counts = [
            'dm' => (int)(DB::selectOne(
                "SELECT COUNT(*) AS c FROM chat_users f JOIN accounts a ON a.id=f.account_id
                 WHERE f.account_id=? AND (f.user_type='dm' OR IFNULL(f.last_message,'')<>''){$scopeSql}",
                array_merge([$accountId], $scopeParams)
            )['c'] ?? 0),
            'follower' => (int)(DB::selectOne(
                "SELECT COUNT(*) AS c FROM chat_users f JOIN accounts a ON a.id=f.account_id
                 WHERE f.account_id=? AND f.relation IN ('follower','mutual'){$scopeSql}",
                array_merge([$accountId], $scopeParams)
            )['c'] ?? 0),
            'following' => (int)(DB::selectOne(
                "SELECT COUNT(*) AS c FROM chat_users f JOIN accounts a ON a.id=f.account_id
                 WHERE f.account_id=? AND f.relation IN ('following','mutual'){$scopeSql}",
                array_merge([$accountId], $scopeParams)
            )['c'] ?? 0),
        ];

        return $this->success([
            'items' => array_map([$this, 'mapCompanyChatFriend'], $items),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'counts' => $counts,
            'capabilities' => [
                'pullDm' => false,
                'pullFans' => false,
                'pullFollowing' => false,
                'searchUser' => false,
            ],
        ]);
    }

    /**
     * 企业客服坐席：会话消息（只读）
     */
    public function chatMessages(Request $request): Response
    {
        if ($err = $this->gate($request)) {
            return $err;
        }
        DataScope::ensureColumns();

        $friendId = (int)$request->get('friendId', $request->get('friend_id', 0));
        if ($friendId < 1) {
            return $this->error('请选择会话', 400);
        }
        [$scopeSql, $scopeParams] = DataScope::accountListScopeSql('a.owner_id');
        $friend = DB::selectOne(
            "SELECT f.*, a.username AS account_username, a.owner_id
             FROM chat_users f
             JOIN accounts a ON a.id=f.account_id
             WHERE f.id=?{$scopeSql}
             LIMIT 1",
            array_merge([$friendId], $scopeParams)
        );
        if (!$friend) {
            return $this->error('会话不存在或无权访问', 403);
        }

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(200, max(1, (int)$request->get('pageSize', 100)));
        $offset = ($page - 1) * $pageSize;
        $total = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM chat_records WHERE user_id=?', [$friendId])['c'] ?? 0);
        $items = DB::select(
            "SELECT id, user_id, direction, msg_type, content, created_at
             FROM chat_records WHERE user_id=?
             ORDER BY id DESC LIMIT {$pageSize} OFFSET {$offset}",
            [$friendId]
        ) ?: [];
        $items = array_reverse($items);

        // 打开会话时本地已读（不触发任何拉取）
        if ((int)($friend['unread_count'] ?? 0) > 0) {
            try {
                DB::query('UPDATE chat_users SET unread_count=0, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$friendId]);
                $friend['unread_count'] = 0;
            } catch (\Throwable $e) {
            }
        }

        return $this->success([
            'friend' => $this->mapCompanyChatFriend($friend),
            'items' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'friendId' => (int)($r['user_id'] ?? 0),
                    'direction' => (string)($r['direction'] ?? 'in'),
                    'msgType' => (string)($r['msg_type'] ?? 'text'),
                    'content' => (string)($r['content'] ?? ''),
                    'createdAt' => (string)($r['created_at'] ?? ''),
                ];
            }, $items),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'capabilities' => [
                'send' => false,
                'pullDm' => false,
                'analyze' => false,
            ],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function mapCompanyChatFriend(array $row): array
    {
        $relation = (string)($row['relation'] ?? '');
        $userType = (string)($row['user_type'] ?? '');
        if ($userType === '') {
            $userType = match ($relation) {
                'follower' => 'follower',
                'following' => 'following',
                'mutual' => 'mutual',
                'blocked' => 'blocked',
                default => 'dm',
            };
        }
        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($row['tags'] ?? '')))));
        return [
            'id' => (int)$row['id'],
            'accountId' => (int)($row['account_id'] ?? 0),
            'accountUsername' => (string)($row['account_username'] ?? ''),
            'friendUid' => (string)($row['friend_uid'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
            'avatarUrl' => (string)($row['avatar_url'] ?? ''),
            'relation' => $relation,
            'userType' => $userType,
            'tags' => $tags,
            'unreadCount' => (int)($row['unread_count'] ?? 0),
            'isPinned' => !empty($row['is_pinned']),
            'isNew' => !empty($row['is_new']),
            'lastMessage' => (string)($row['last_message'] ?? ''),
            'lastDirection' => (string)($row['last_direction'] ?? ''),
            'lastMessageAt' => (string)($row['last_message_at'] ?? ''),
        ];
    }

    /**
     * 近 28 天日历横轴：有数据填真实值，缺天补 0。
     *
     * @param array<string,array<string,mixed>> $dailyMap key=Y-m-d
     * @return list<array<string,mixed>>
     */
    private function buildDaily28Series(array $dailyMap): array
    {
        $tzName = date_default_timezone_get() ?: 'Asia/Shanghai';
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('Asia/Shanghai');
        }
        $end = new \DateTimeImmutable('today', $tz);
        $out = [];
        for ($i = 27; $i >= 0; $i--) {
            $day = $end->modify("-{$i} days")->format('Y-m-d');
            $row = $dailyMap[$day] ?? null;
            $out[] = [
                'metricDate' => $day,
                'videoViews' => (int)($row['video_views'] ?? $row['videoViews'] ?? 0),
                'profileViews' => (int)($row['profile_views'] ?? $row['profileViews'] ?? 0),
                'likes' => (int)($row['likes_count'] ?? $row['likes'] ?? 0),
                'comments' => (int)($row['comments_count'] ?? $row['comments'] ?? 0),
                'shares' => (int)($row['shares_count'] ?? $row['shares'] ?? 0),
                'estRewards' => (float)($row['est_rewards'] ?? $row['estRewards'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function insertCompanyEntrustTask(string $taskName, string $type, array $payload, int $userId, int $totalCount): Response
    {
        DB::query(
            'INSERT INTO tasks(name,type,group_id,total_count,scheduled_at,payload,status,owner_id) VALUES (?,?,?,?,?,?,"draft",?)',
            [
                mb_substr($taskName, 0, 120),
                $type,
                null,
                max(1, $totalCount),
                null,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $userId,
            ]
        );
        $taskId = (int)DB::getPdo()->lastInsertId();
        $row = DB::selectOne('SELECT * FROM tasks WHERE id=?', [$taskId]);
        return $this->success([
            'task' => $this->mapCompanyPublishTask($row ?: [
                'id' => $taskId,
                'type' => $type,
                'payload' => json_encode($payload),
                'status' => 'draft',
                'name' => $taskName,
                'total_count' => $totalCount,
            ]),
        ], '委托任务已创建，等待运营处理');
    }

    /** @param array<string,mixed> $row */
    private function mapCompanyPublishTask(array $row): array
    {
        $payload = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
        $accountIds = $payload['accountIds'] ?? [];
        $materialIds = $payload['materialIds'] ?? [];
        $accountId = (int)($payload['entrustAccount']['id'] ?? (is_array($accountIds) ? ($accountIds[0] ?? 0) : 0));
        $materialId = (int)($payload['entrustMaterial']['id'] ?? (is_array($materialIds) ? ($materialIds[0] ?? 0) : 0));
        $type = (string)($row['type'] ?? '');
        $kindLabels = [
            'publish' => '发布视频',
            'comment_reply' => '回复评论',
            'pull_video_list' => '拉取视频列表',
            'pull_comments' => '拉取视频详情',
        ];
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'type' => $type,
            'typeLabel' => $kindLabels[$type] ?? $type,
            'status' => (string)($row['status'] ?? ''),
            'accountId' => $accountId,
            'accountUsername' => (string)($payload['entrustAccount']['username'] ?? ''),
            'accountNickname' => (string)($payload['entrustAccount']['nickname'] ?? ''),
            'materialId' => $materialId,
            'materialName' => (string)($payload['entrustMaterial']['name'] ?? ''),
            'materialCount' => (int)($payload['entrustMaterial']['count'] ?? (is_array($materialIds) ? count($materialIds) : 0)),
            'accountCount' => (int)($payload['entrustAccountCount'] ?? (is_array($accountIds) ? count($accountIds) : ($accountId > 0 ? 1 : 0))),
            'replyMode' => (string)($payload['replyMode'] ?? ''),
            'maxReplies' => (int)($payload['maxReplies'] ?? 0),
            'publishTitle' => (string)($payload['publishTitle'] ?? ''),
            'publishCaption' => (string)($payload['publishCaption'] ?? ''),
            'successCount' => (int)($row['success_count'] ?? 0),
            'failCount' => (int)($row['fail_count'] ?? 0),
            'totalCount' => (int)($row['total_count'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'finishedAt' => (string)($row['finished_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>|Response
     */
    private function storeCompanyMaterial(array $file, string $name, int $ownerId)
    {
        $origName = (string)($file['name'] ?? 'video.mp4');
        if (!mb_check_encoding($origName, 'UTF-8')) {
            $origName = mb_convert_encoding($origName, 'UTF-8', 'ISO-8859-1');
        }
        $mime = (string)($file['type'] ?? '');
        $ext = strtolower(ltrim((string)pathinfo($origName, PATHINFO_EXTENSION), '.'));
        if ($mime !== '' && !str_starts_with($mime, 'video/') && !in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'], true)) {
            return $this->error('只能上传视频文件', 415);
        }
        $size = (int)($file['size'] ?? 0);
        $base = getenv('DATA_DIR') ?: (dirname(__DIR__, 3) . '/data');
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'materials';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $destName = time() . '-' . bin2hex(random_bytes(4)) . ($ext ? ".{$ext}" : '');
        $dest = $dir . DIRECTORY_SEPARATOR . $destName;
        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            return $this->error('保存文件失败');
        }
        $sha = hash_file('sha256', $dest) ?: '';
        $display = $name !== '' ? $name : pathinfo($origName, PATHINFO_FILENAME);
        $mimeFinal = $mime !== '' ? $mime : match ($ext) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            default => 'video/mp4',
        };
        DB::query(
            'INSERT INTO materials(name, file_path, file_name, file_ext, mime_type, size_bytes, description, tags, status, publish_status, publish_account_id, publish_account, sha256, cover_base64, share_title, share_caption, owner_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $display, $dest, $origName, $ext, $mimeFinal, $size, 'company_entrust', 'company',
                'ready', 'unused', null, '', $sha, '', $name, '', $ownerId,
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        return DB::selectOne('SELECT * FROM materials WHERE id=?', [$id]) ?: ['id' => $id, 'name' => $display, 'file_name' => $origName];
    }
}
