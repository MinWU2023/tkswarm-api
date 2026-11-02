<?php
declare(strict_types=1);

namespace App\Controllers\Company;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountWorkBatchService;
use App\Services\AiLeadExecutionService;
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

        $ownerId = DataScope::filterOwnerId();
        $clueWhere = '';
        $clueBind = [];
        if ($ownerId !== null) {
            $clueWhere = ' AND l.owner_id=?';
            $clueBind = [$ownerId];
        }
        $clueTotal = 0;
        try {
            $clueTotal = (int)(DB::selectOne(
                "SELECT COUNT(*) AS c
                 FROM ai_lead_clue_items x
                 INNER JOIN ai_leads l ON l.id = x.lead_id
                 WHERE 1=1{$clueWhere}",
                $clueBind
            )['c'] ?? 0);
        } catch (\Throwable $e) {
            $clueTotal = 0;
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
        $ownerId = DataScope::filterOwnerId();
        try {
            $clueWhere = $ownerId !== null ? ' AND l.owner_id=?' : '';
            $clueBind = $ownerId !== null ? [$ownerId] : [];
            $clueNew = (int)(DB::selectOne(
                "SELECT COUNT(*) AS c FROM ai_lead_clue_items x
                 INNER JOIN ai_leads l ON l.id = x.lead_id
                 WHERE x.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY){$clueWhere}",
                $clueBind
            )['c'] ?? 0);
        } catch (\Throwable $e) {
            $clueNew = 0;
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

        $daily28 = [];
        $tz = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $end = new \DateTimeImmutable('today', $tz);
        for ($i = 27; $i >= 0; $i--) {
            $day = $end->modify("-{$i} days")->format('Y-m-d');
            $row = $dailyMap[$day] ?? null;
            $daily28[] = [
                'metricDate' => $day,
                'videoViews' => (int)($row['video_views'] ?? 0),
                'profileViews' => (int)($row['profile_views'] ?? 0),
                'likes' => (int)($row['likes_count'] ?? 0),
                'comments' => (int)($row['comments_count'] ?? 0),
                'shares' => (int)($row['shares_count'] ?? 0),
                'estRewards' => (float)($row['est_rewards'] ?? 0),
            ];
        }

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

        $ownerId = DataScope::filterOwnerId();
        $where = '1=1';
        $bind = [];
        if ($ownerId !== null) {
            $where .= ' AND l.owner_id=?';
            $bind[] = $ownerId;
        }
        if ($keyword !== '') {
            $where .= ' AND (x.nickname LIKE ? OR x.username LIKE ? OR x.comment_text LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($bind, $like, $like, $like);
        }

        $total = 0;
        $rows = [];
        try {
            $total = (int)(DB::selectOne(
                "SELECT COUNT(*) AS c FROM ai_lead_clue_items x
                 INNER JOIN ai_leads l ON l.id = x.lead_id
                 WHERE {$where}",
                $bind
            )['c'] ?? 0);
            $rows = DB::select(
                "SELECT x.id, x.lead_id, x.source_type, x.username, x.nickname, x.comment_text,
                        x.intent_score, x.dm_status, x.profile_url, x.created_at,
                        l.name AS lead_name, l.keywords
                 FROM ai_lead_clue_items x
                 INNER JOIN ai_leads l ON l.id = x.lead_id
                 WHERE {$where}
                 ORDER BY x.id DESC
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
                'message' => '暂无线索数据表或无权限',
            ]);
        }

        return $this->success([
            'items' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'leadId' => (int)($r['lead_id'] ?? 0),
                    'leadName' => (string)($r['lead_name'] ?? ''),
                    'sourceType' => (string)($r['source_type'] ?? ''),
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                    'commentText' => (string)($r['comment_text'] ?? ''),
                    'intentScore' => (int)($r['intent_score'] ?? 0),
                    'dmStatus' => (string)($r['dm_status'] ?? ''),
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
}
