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

        $videoTotal = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             WHERE (m.origin='online' OR (m.publish_status='published' AND TRIM(IFNULL(m.platform_video_id,''))<>''))
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

        return $this->success([
            'items' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                    'loginStatus' => (string)($r['login_status'] ?? ''),
                    'accountStatus' => (string)($r['account_status'] ?? 'normal'),
                    'country' => (string)($r['country'] ?? ''),
                    'groupName' => (string)($r['group_name'] ?? ''),
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
