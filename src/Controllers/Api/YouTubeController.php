<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Database\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth\RBAC;
use App\Services\CompanyService;
use App\Services\DataScope;
use App\Services\PermissionCatalog;
use App\Services\YouTube\YouTubeApiService;
use App\Services\YouTube\YouTubeOAuthConfigService;
use App\Services\YouTube\YouTubeSchema;

/**
 * YouTube：全局 OAuth 配置、多频道账号授权、TikTok 素材同步发布。
 */
class YouTubeController extends Controller
{
    /** @return list<string> */
    private function heldPermissionCodes(): array
    {
        $user = DataScope::loginUser() ?: ($GLOBALS['auth_user'] ?? null);
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            return [];
        }
        try {
            return RBAC::userPermissionCodes($uid);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** YouTube 开发配置：超管 / 运营管理 / system.youtube */
    private function denyUnlessCanConfig(): ?Response
    {
        DataScope::bootFromGlobals();
        if (DataScope::isLoginSuper() || DataScope::isLoginOpsAdmin()) {
            return null;
        }
        $perms = $this->heldPermissionCodes();
        if (RBAC::matchesHeldCode('system.youtube', $perms)
            || in_array('*', $perms, true)
            || in_array('admin', $perms, true)
            || in_array('menu.780', $perms, true)) {
            return null;
        }
        return $this->error('无权管理 YouTube 开发配置', 403);
    }

    /** YouTube 账号与发布 / 数据面板：超管 / 运营管理 / 运营专员 / 企业客户 / 权限节点 */
    private function denyUnlessCanUse(): ?Response
    {
        DataScope::bootFromGlobals();
        if (DataScope::isLoginSuper() || DataScope::isLoginOpsAdmin()
            || DataScope::isOperator() || DataScope::isEnterpriseCustomer()) {
            return null;
        }
        $perms = $this->heldPermissionCodes();
        foreach (['system.youtube.use', 'system.youtube.panel', 'system.youtube', 'youtube.manage', 'menu.781', 'menu.782', 'menu.783'] as $code) {
            if (RBAC::matchesHeldCode($code, $perms) || PermissionCatalog::matchesCode($code, $perms)) {
                return null;
            }
        }
        return $this->error('无权使用 YouTube 账号与发布', 403);
    }

    // -------------------------------------------------------------------------
    // OAuth 全局配置
    // -------------------------------------------------------------------------

    public function getOAuthConfig(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanConfig()) {
            return $deny;
        }
        return $this->success([
            'config' => YouTubeOAuthConfigService::getSettingsMap(true),
            'configured' => YouTubeOAuthConfigService::isConfigured(),
            'defaultScopes' => YouTubeApiService::DEFAULT_SCOPES,
            'callbackPath' => '/api/youtube/oauth/callback',
        ]);
    }

    public function saveOAuthConfig(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanConfig()) {
            return $deny;
        }
        $all = $request->all();
        $cfg = YouTubeOAuthConfigService::save($all);
        if ($cfg['clientId'] === '' || $cfg['redirectUri'] === '') {
            return $this->error('请填写 clientId 与 redirectUri', 422);
        }
        if (!YouTubeOAuthConfigService::isConfigured()) {
            return $this->error('请填写 clientSecret（首次保存必填）', 422);
        }
        return $this->success([
            'config' => YouTubeOAuthConfigService::getSettingsMap(true),
            'configured' => true,
        ], 'YouTube OAuth 配置已保存');
    }

    // -------------------------------------------------------------------------
    // 账号 CRUD + 授权
    // -------------------------------------------------------------------------

    public function listAccounts(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $keyword = trim((string)$request->get('keyword', ''));
        $companyId = (int)$request->get('companyId', $request->get('company_id', 0));
        $filters = ['1=1'];
        $params = [];
        if ($companyId > 0) {
            $filters[] = 'y.company_id=?';
            $params[] = $companyId;
        }
        if ($keyword !== '') {
            $filters[] = '(y.username LIKE ? OR y.nickname LIKE ? OR y.channel_title LIKE ? OR y.channel_id LIKE ? OR CAST(y.id AS CHAR) LIKE ? OR IFNULL(c.name,\'\') LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $where = 'WHERE ' . implode(' AND ', $filters);
        $rows = DB::select(
            "SELECT y.*, c.name AS company_name
             FROM youtube_accounts y
             LEFT JOIN companies c ON c.id = y.company_id
             {$where}
             ORDER BY y.id DESC
             LIMIT 500",
            $params
        ) ?: [];
        return $this->success([
            'items' => array_map([$this, 'mapAccount'], $rows),
            'oauthConfigured' => YouTubeOAuthConfigService::isConfigured(),
        ]);
    }

    public function createAccount(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        if (!YouTubeOAuthConfigService::isConfigured()) {
            return $this->error('请先在「YouTube 开发配置」中填写 OAuth 客户端', 422);
        }
        $cfg = YouTubeOAuthConfigService::get();
        $username = trim((string)$request->input('username', $request->input('label', '')));
        $nickname = trim((string)$request->input('nickname', ''));
        $notes = trim((string)$request->input('notes', ''));
        [$companyId, $companyErr] = $this->resolveCompanyIdInput($request->all());
        if ($companyErr) {
            return $companyErr;
        }
        $ownerId = DataScope::writeOwnerId();
        $extra = json_encode(['notes' => $notes], JSON_UNESCAPED_UNICODE);
        DB::query(
            "INSERT INTO youtube_accounts
             (owner_id, company_id, username, nickname, google_cloud_project_id, oauth_client_id, oauth_client_secret,
              oauth_redirect_uri, oauth_scopes, status, extra_json)
             VALUES (?,?,?,?,?,?,?,?,?, 'pending', ?)",
            [
                $ownerId,
                $companyId,
                $username,
                $nickname,
                '',
                $cfg['clientId'],
                $cfg['clientSecret'],
                $cfg['redirectUri'],
                $cfg['scopes'],
                $extra,
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        $row = DB::selectOne(
            'SELECT y.*, c.name AS company_name FROM youtube_accounts y LEFT JOIN companies c ON c.id=y.company_id WHERE y.id=?',
            [$id]
        );
        return $this->success($this->mapAccount($row ?: ['id' => $id]), '账号草稿已创建，请点击授权登录');
    }

    /** 更新备注/企业绑定等 */
    public function updateAccount(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $aid = (int)$id;
        $row = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$aid]);
        if (!$row) {
            return $this->error('账号不存在', 404);
        }
        $all = $request->all();
        $username = array_key_exists('username', $all)
            ? trim((string)$all['username'])
            : (string)($row['username'] ?? '');
        $nickname = array_key_exists('nickname', $all)
            ? trim((string)$all['nickname'])
            : (string)($row['nickname'] ?? '');
        $extra = json_decode((string)($row['extra_json'] ?? '{}'), true);
        if (!is_array($extra)) {
            $extra = [];
        }
        if (array_key_exists('notes', $all)) {
            $extra['notes'] = trim((string)$all['notes']);
        }
        $companyId = (int)($row['company_id'] ?? 0) ?: null;
        if (array_key_exists('companyId', $all) || array_key_exists('company_id', $all)) {
            [$companyId, $companyErr] = $this->resolveCompanyIdInput($all);
            if ($companyErr) {
                return $companyErr;
            }
        }
        DB::query(
            'UPDATE youtube_accounts SET username=?, nickname=?, company_id=?, extra_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $username,
                $nickname,
                $companyId,
                json_encode($extra, JSON_UNESCAPED_UNICODE),
                $aid,
            ]
        );
        $fresh = DB::selectOne(
            'SELECT y.*, c.name AS company_name FROM youtube_accounts y LEFT JOIN companies c ON c.id=y.company_id WHERE y.id=?',
            [$aid]
        );
        return $this->success($this->mapAccount($fresh ?: $row), '已保存');
    }

    public function deleteAccount(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $aid = (int)$id;
        $row = DB::selectOne('SELECT id FROM youtube_accounts WHERE id=?', [$aid]);
        if (!$row) {
            return $this->error('账号不存在', 404);
        }
        DB::query('DELETE FROM youtube_accounts WHERE id=?', [$aid]);
        return $this->success(['id' => $aid], '已删除');
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:?int,1:?Response}
     */
    private function resolveCompanyIdInput(array $input): array
    {
        $raw = $input['companyId'] ?? $input['company_id'] ?? null;
        if ($raw === null || $raw === '' || $raw === '__clear__' || $raw === '0') {
            return [null, null];
        }
        $companyId = (int)$raw;
        if ($companyId <= 0) {
            return [null, null];
        }
        try {
            CompanyService::ensureSchema();
            if (!CompanyService::find($companyId)) {
                return [null, $this->error('企业不存在 #' . $companyId, 422)];
            }
        } catch (\Throwable $e) {
            return [null, $this->error('校验企业失败：' . $e->getMessage(), 500)];
        }
        return [$companyId, null];
    }

    /** 开始 OAuth：返回 Google 授权 URL */
    public function startAuth(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        if (!YouTubeOAuthConfigService::isConfigured()) {
            return $this->error('请先配置 YouTube OAuth 客户端', 422);
        }
        $aid = (int)$id;
        $row = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$aid]);
        if (!$row) {
            return $this->error('账号不存在', 404);
        }
        $row = YouTubeOAuthConfigService::applyToAccountRow($row);
        DB::query(
            'UPDATE youtube_accounts SET oauth_client_id=?, oauth_client_secret=?, oauth_redirect_uri=?, oauth_scopes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $row['oauth_client_id'],
                $row['oauth_client_secret'],
                $row['oauth_redirect_uri'],
                $row['oauth_scopes'],
                $aid,
            ]
        );
        $nonce = bin2hex(random_bytes(8));
        $state = 'yt_acc_' . $aid . '.' . $nonce;
        $extra = json_decode((string)($row['extra_json'] ?? '{}'), true);
        if (!is_array($extra)) {
            $extra = [];
        }
        $extra['oauthState'] = $nonce;
        $extra['oauthStartedAt'] = date('Y-m-d H:i:s');
        DB::query(
            "UPDATE youtube_accounts SET extra_json=?, status='authorizing', last_error='', updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [json_encode($extra, JSON_UNESCAPED_UNICODE), $aid]
        );
        $svc = new YouTubeApiService($row);
        $authUrl = $svc->buildAuthUrl([], $state);
        return $this->success([
            'accountId' => $aid,
            'authUrl' => $authUrl,
            'state' => $state,
        ], '请在浏览器完成 Google 授权');
    }

    /**
     * Google OAuth 回调（公开路由，无登录）。
     * 成功后回写 refresh_token + 频道信息，并输出简易 HTML。
     */
    public function oauthCallback(Request $request): Response
    {
        YouTubeSchema::ensureSchema();
        $error = trim((string)$request->get('error', ''));
        $code = trim((string)$request->get('code', ''));
        $state = trim((string)$request->get('state', ''));
        if ($error !== '') {
            return $this->oauthHtml(false, '授权失败：' . $error);
        }
        if ($code === '' || $state === '') {
            return $this->oauthHtml(false, '缺少 code 或 state');
        }
        if (!preg_match('/^yt_acc_(\d+)\.([a-f0-9]+)$/', $state, $m)) {
            return $this->oauthHtml(false, 'state 无效');
        }
        $aid = (int)$m[1];
        $nonce = $m[2];
        $row = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$aid]);
        if (!$row) {
            return $this->oauthHtml(false, '账号不存在 #' . $aid);
        }
        $extra = json_decode((string)($row['extra_json'] ?? '{}'), true);
        if (!is_array($extra) || (string)($extra['oauthState'] ?? '') !== $nonce) {
            return $this->oauthHtml(false, 'state 校验失败，请重新点击授权登录');
        }
        try {
            $row = YouTubeOAuthConfigService::applyToAccountRow($row);
            $svc = new YouTubeApiService($row);
            $svc->exchangeAuthorizationCode($code);
        } catch (\Throwable $e) {
            DB::query(
                "UPDATE youtube_accounts SET status='error', last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [mb_substr($e->getMessage(), 0, 500), $aid]
            );
            return $this->oauthHtml(false, '换取 token 失败：' . $e->getMessage());
        }

        // token 已落库；频道拉取失败时不要抹掉 token，便于创建频道后点「刷新」补齐
        try {
            $channel = $svc->getMineChannel();
            $title = (string)($channel['snippet']['title'] ?? '');
            $channelId = (string)($channel['id'] ?? '');
            unset($extra['oauthState']);
            $extra['oauthCompletedAt'] = date('Y-m-d H:i:s');
            DB::query(
                "UPDATE youtube_accounts
                 SET nickname=IF(nickname='', ?, nickname),
                     username=IF(username='', ?, username),
                     status='enabled', last_error='', extra_json=?, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [
                    $title,
                    $channelId !== '' ? $channelId : $title,
                    json_encode($extra, JSON_UNESCAPED_UNICODE),
                    $aid,
                ]
            );
            return $this->oauthHtml(true, '授权成功：' . ($title !== '' ? $title : ('账号 #' . $aid)) . '。可关闭本页返回 Node。');
        } catch (\Throwable $e) {
            unset($extra['oauthState']);
            $extra['oauthTokenAt'] = date('Y-m-d H:i:s');
            $extra['pendingChannel'] = true;
            DB::query(
                "UPDATE youtube_accounts
                 SET status='authorizing', last_error=?, extra_json=?, updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [
                    mb_substr($e->getMessage(), 0, 500),
                    json_encode($extra, JSON_UNESCAPED_UNICODE),
                    $aid,
                ]
            );
            return $this->oauthHtml(false, $e->getMessage());
        }
    }

    public function refreshAccountToken(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $aid = (int)$id;
        $row = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$aid]);
        if (!$row) {
            return $this->error('账号不存在', 404);
        }
        try {
            $row = YouTubeOAuthConfigService::applyToAccountRow($row);
            $svc = new YouTubeApiService($row);
            $svc->refreshAccessToken();
            $svc->getMineChannel();
            $fresh = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$aid]);
            return $this->success($this->mapAccount($fresh ?: $row), '已刷新 token 与频道信息');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // -------------------------------------------------------------------------
    // 频道内容 / 评论（数据面板）
    // -------------------------------------------------------------------------

    /** @return array{0:?array<string,mixed>,1:?Response} */
    private function requireAccount(int $aid): array
    {
        if ($aid <= 0) {
            return [null, $this->error('账号无效', 422)];
        }
        YouTubeSchema::ensureSchema();
        $row = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$aid]);
        if (!$row) {
            return [null, $this->error('账号不存在', 404)];
        }
        return [$row, null];
    }

    public function listAccountVideos(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        [$acc, $err] = $this->requireAccount((int)$id);
        if ($err) {
            return $err;
        }
        $aid = (int)$acc['id'];
        $keyword = trim((string)$request->get('keyword', ''));
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, min(100, (int)$request->get('pageSize', 20)));
        $filters = ['account_id=?', "status<>'deleted'"];
        $params = [$aid];
        if ($keyword !== '') {
            $filters[] = '(title LIKE ? OR youtube_video_id LIKE ? OR description LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }
        $where = implode(' AND ', $filters);
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM youtube_videos WHERE {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT * FROM youtube_videos WHERE {$where}
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];
        return $this->success([
            'account' => $this->mapAccount($acc),
            'items' => array_map([$this, 'mapVideo'], $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => $pages,
        ]);
    }

    public function pullAccountVideos(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        [$acc, $err] = $this->requireAccount((int)$id);
        if ($err) {
            return $err;
        }
        if (trim((string)($acc['refresh_token'] ?? '')) === '') {
            return $this->error('请先完成授权登录', 422);
        }
        try {
            $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
            $svc = new YouTubeApiService($acc);
            $maxPages = max(1, min(40, (int)$request->input('maxPages', 10)));
            $result = $svc->pullChannelVideos($maxPages);
            return $this->success($result, '已拉取 ' . (int)($result['pulled'] ?? 0) . ' 条内容');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function getAccountVideo(Request $request, string $id, string $videoId): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        [$acc, $err] = $this->requireAccount((int)$id);
        if ($err) {
            return $err;
        }
        $row = $this->findAccountVideo((int)$acc['id'], $videoId);
        if (!$row) {
            return $this->error('内容不存在', 404);
        }
        $refresh = (string)$request->get('refresh', '') === '1'
            || (string)$request->get('sync', '') === '1';
        if ($refresh && trim((string)($acc['refresh_token'] ?? '')) !== '') {
            try {
                $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
                $svc = new YouTubeApiService($acc);
                $svc->pullVideoInfo((string)$row['youtube_video_id'], true);
                $row = $this->findAccountVideo((int)$acc['id'], $videoId) ?: $row;
            } catch (\Throwable $e) {
                // 详情仍返回本地数据
            }
        }
        return $this->success([
            'account' => $this->mapAccount($acc),
            'video' => $this->mapVideo($row),
        ]);
    }

    public function deleteAccountVideo(Request $request, string $id, string $videoId): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        [$acc, $err] = $this->requireAccount((int)$id);
        if ($err) {
            return $err;
        }
        $row = $this->findAccountVideo((int)$acc['id'], $videoId);
        if (!$row) {
            return $this->error('内容不存在', 404);
        }
        $ytVid = trim((string)($row['youtube_video_id'] ?? ''));
        if ($ytVid === '') {
            return $this->error('缺少 YouTube 视频 ID', 422);
        }
        if (trim((string)($acc['refresh_token'] ?? '')) === '') {
            return $this->error('请先完成授权登录', 422);
        }
        try {
            $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
            $svc = new YouTubeApiService($acc);
            $svc->deleteVideo($ytVid);
            return $this->success(['id' => (int)$row['id'], 'youtubeVideoId' => $ytVid], '已从 YouTube 删除');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function listVideoComments(Request $request, string $id, string $videoId): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        [$acc, $err] = $this->requireAccount((int)$id);
        if ($err) {
            return $err;
        }
        $video = $this->findAccountVideo((int)$acc['id'], $videoId);
        if (!$video) {
            return $this->error('内容不存在', 404);
        }
        $localVid = (int)$video['id'];
        $ytVid = (string)($video['youtube_video_id'] ?? '');
        $rows = DB::select(
            "SELECT * FROM youtube_comments
             WHERE account_id=? AND (video_id=? OR youtube_video_id=?)
               AND IFNULL(moderation_status,'') NOT IN ('deleted')
             ORDER BY parent_id ASC, COALESCE(published_at, created_at) DESC, id DESC
             LIMIT 1000",
            [(int)$acc['id'], $localVid, $ytVid]
        ) ?: [];
        $tops = [];
        $replies = [];
        foreach ($rows as $r) {
            $mapped = $this->mapComment($r);
            if ((int)($r['parent_id'] ?? 0) > 0) {
                $pid = (int)$r['parent_id'];
                if (!isset($replies[$pid])) {
                    $replies[$pid] = [];
                }
                $replies[$pid][] = $mapped;
            } else {
                $tops[] = $mapped;
            }
        }
        foreach ($tops as &$t) {
            $t['replies'] = $replies[(int)$t['id']] ?? [];
        }
        unset($t);
        return $this->success([
            'items' => $tops,
            'total' => count($tops),
            'video' => $this->mapVideo($video),
        ]);
    }

    public function pullVideoComments(Request $request, string $id, string $videoId): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        [$acc, $err] = $this->requireAccount((int)$id);
        if ($err) {
            return $err;
        }
        $video = $this->findAccountVideo((int)$acc['id'], $videoId);
        if (!$video) {
            return $this->error('内容不存在', 404);
        }
        if (trim((string)($acc['refresh_token'] ?? '')) === '') {
            return $this->error('请先完成授权登录', 422);
        }
        try {
            $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
            $svc = new YouTubeApiService($acc);
            $maxPages = max(1, min(20, (int)$request->input('maxPages', 5)));
            $fetchAll = !empty($request->input('fetchAllReplies', true));
            $result = $svc->pullComments((string)$video['youtube_video_id'], $maxPages, (bool)$fetchAll);
            return $this->success($result, '评论已同步');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function replyVideoComment(Request $request, string $id, string $commentId): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        [$acc, $err] = $this->requireAccount((int)$id);
        if ($err) {
            return $err;
        }
        $cid = (int)$commentId;
        $comment = DB::selectOne(
            'SELECT * FROM youtube_comments WHERE id=? AND account_id=? LIMIT 1',
            [$cid, (int)$acc['id']]
        );
        if (!$comment) {
            // 也允许传 youtube_comment_id
            $comment = DB::selectOne(
                'SELECT * FROM youtube_comments WHERE youtube_comment_id=? AND account_id=? LIMIT 1',
                [trim($commentId), (int)$acc['id']]
            );
        }
        if (!$comment) {
            return $this->error('评论不存在', 404);
        }
        $text = trim((string)$request->input('text', $request->input('replyText', '')));
        if ($text === '') {
            return $this->error('回复内容不能为空', 422);
        }
        if (trim((string)($acc['refresh_token'] ?? '')) === '') {
            return $this->error('请先完成授权登录', 422);
        }
        $parentYt = trim((string)($comment['youtube_comment_id'] ?? ''));
        // 若是回复的回复，仍挂到顶层 youtube_comment_id（API parentId 需顶层或该评论自身）
        if ((int)($comment['parent_id'] ?? 0) > 0 && trim((string)($comment['parent_youtube_comment_id'] ?? '')) !== '') {
            $parentYt = trim((string)$comment['parent_youtube_comment_id']);
        }
        try {
            $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
            $svc = new YouTubeApiService($acc);
            $res = $svc->replyToComment($parentYt, $text);
            return $this->success([
                'reply' => $res,
                'parentId' => (int)$comment['id'],
            ], '已回复');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function deleteVideoComment(Request $request, string $id, string $commentId): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        [$acc, $err] = $this->requireAccount((int)$id);
        if ($err) {
            return $err;
        }
        $cid = (int)$commentId;
        $comment = DB::selectOne(
            'SELECT * FROM youtube_comments WHERE id=? AND account_id=? LIMIT 1',
            [$cid, (int)$acc['id']]
        );
        if (!$comment) {
            $comment = DB::selectOne(
                'SELECT * FROM youtube_comments WHERE youtube_comment_id=? AND account_id=? LIMIT 1',
                [trim($commentId), (int)$acc['id']]
            );
        }
        if (!$comment) {
            return $this->error('评论不存在', 404);
        }
        $ytCid = trim((string)($comment['youtube_comment_id'] ?? ''));
        if ($ytCid === '') {
            return $this->error('缺少评论平台 ID', 422);
        }
        if (trim((string)($acc['refresh_token'] ?? '')) === '') {
            return $this->error('请先完成授权登录', 422);
        }
        try {
            $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
            $svc = new YouTubeApiService($acc);
            $result = $svc->deleteComment($ytCid);
            return $this->success($result, '已删除评论');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** @return array<string,mixed>|null */
    private function findAccountVideo(int $accountId, string $videoId): ?array
    {
        $videoId = trim($videoId);
        if ($videoId === '') {
            return null;
        }
        if (ctype_digit($videoId)) {
            $row = DB::selectOne(
                'SELECT * FROM youtube_videos WHERE account_id=? AND id=? LIMIT 1',
                [$accountId, (int)$videoId]
            );
            if ($row) {
                return $row;
            }
        }
        return DB::selectOne(
            'SELECT * FROM youtube_videos WHERE account_id=? AND youtube_video_id=? LIMIT 1',
            [$accountId, $videoId]
        ) ?: null;
    }

    /** @param array<string,mixed> $row */
    private function mapVideo(array $row): array
    {
        $tags = json_decode((string)($row['tags_json'] ?? '[]'), true);
        if (!is_array($tags)) {
            $tags = [];
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'accountId' => (int)($row['account_id'] ?? 0),
            'youtubeVideoId' => (string)($row['youtube_video_id'] ?? ''),
            'channelId' => (string)($row['channel_id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'tags' => array_values(array_map('strval', $tags)),
            'categoryId' => (string)($row['category_id'] ?? ''),
            'privacyStatus' => (string)($row['privacy_status'] ?? $row['privacy_status_api'] ?? ''),
            'thumbnailUrl' => (string)($row['thumbnail_url'] ?? ''),
            'durationIso' => (string)($row['duration_iso'] ?? ''),
            'durationSeconds' => (int)($row['duration_seconds'] ?? 0),
            'viewCount' => (int)($row['view_count'] ?? 0),
            'likeCount' => (int)($row['like_count'] ?? 0),
            'commentCount' => (int)($row['comment_count'] ?? 0),
            'favoriteCount' => (int)($row['favorite_count'] ?? 0),
            'uploadStatus' => (string)($row['upload_status'] ?? ''),
            'embeddable' => !empty($row['embeddable']),
            'madeForKids' => !empty($row['made_for_kids']),
            'status' => (string)($row['status'] ?? ''),
            'publishedAt' => (string)($row['published_at'] ?? ''),
            'publishAt' => (string)($row['publish_at'] ?? ''),
            'lastSyncedAt' => (string)($row['last_synced_at'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'watchUrl' => (($yt = (string)($row['youtube_video_id'] ?? '')) !== '')
                ? ('https://www.youtube.com/watch?v=' . $yt)
                : '',
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapComment(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'accountId' => (int)($row['account_id'] ?? 0),
            'videoId' => (int)($row['video_id'] ?? 0),
            'youtubeVideoId' => (string)($row['youtube_video_id'] ?? ''),
            'youtubeCommentId' => (string)($row['youtube_comment_id'] ?? ''),
            'parentId' => (int)($row['parent_id'] ?? 0),
            'parentYoutubeCommentId' => (string)($row['parent_youtube_comment_id'] ?? ''),
            'authorChannelId' => (string)($row['author_channel_id'] ?? ''),
            'authorDisplayName' => (string)($row['author_display_name'] ?? ''),
            'authorProfileImageUrl' => (string)($row['author_profile_image_url'] ?? ''),
            'authorChannelUrl' => (string)($row['author_channel_url'] ?? ''),
            'textDisplay' => (string)($row['text_display'] ?? ''),
            'textOriginal' => (string)($row['text_original'] ?? ''),
            'likeCount' => (int)($row['like_count'] ?? 0),
            'totalReplyCount' => (int)($row['total_reply_count'] ?? 0),
            'isPublic' => !empty($row['is_public']),
            'canReply' => !isset($row['can_reply']) || !empty($row['can_reply']),
            'moderationStatus' => (string)($row['moderation_status'] ?? ''),
            'publishedAt' => (string)($row['published_at'] ?? ''),
            'platformUpdatedAt' => (string)($row['platform_updated_at'] ?? ''),
            'pulledAt' => (string)($row['pulled_at'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'replies' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // TikTok → YouTube 同步
    // -------------------------------------------------------------------------

    /** 有已发布可上传素材的 TikTok 账号列表（创建任务第一步） */
    public function listTikTokPublishAccounts(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        $keyword = trim((string)$request->get('keyword', ''));
        $filters = [
            "m.publish_status='published'",
            'm.publish_account_id IS NOT NULL',
            'm.publish_account_id > 0',
        ];
        $params = [];
        // 与账号列表同范围（超管/运营管理可见范围内账号），勿用 applyOwnerFilter 误伤
        DataScope::applyAccountListScope('a.owner_id', $filters, $params);
        if ($keyword !== '') {
            $filters[] = '(a.username LIKE ? OR a.nickname LIKE ? OR CAST(a.id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }
        $where = 'WHERE ' . implode(' AND ', $filters);
        $uploadableExpr = "(TRIM(IFNULL(m.file_path,''))<>'' OR TRIM(IFNULL(m.cdn_url,''))<>'' OR TRIM(IFNULL(m.github_asset_url,''))<>'')";
        $rows = DB::select(
            "SELECT a.id, a.username, a.nickname, a.status, a.owner_id, a.company_id, a.industry_id,
                    COUNT(DISTINCT m.id) AS video_count,
                    COUNT(DISTINCT CASE WHEN {$uploadableExpr} THEN m.id END) AS uploadable_count
             FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             {$where}
             GROUP BY a.id, a.username, a.nickname, a.status, a.owner_id, a.company_id, a.industry_id
             HAVING video_count > 0
             ORDER BY uploadable_count DESC, video_count DESC, a.id DESC
             LIMIT 500",
            $params
        ) ?: [];
        return $this->success([
            'items' => array_map(static function (array $r): array {
                $label = trim((string)(($r['nickname'] ?? '') !== '' ? $r['nickname'] : ($r['username'] ?? '')))
                    ?: ('账号 #' . (int)$r['id']);
                $uploadable = (int)($r['uploadable_count'] ?? 0);
                $total = (int)($r['video_count'] ?? 0);
                return [
                    'id' => (int)$r['id'],
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                    'status' => (string)($r['status'] ?? ''),
                    'videoCount' => $total,
                    'uploadableCount' => $uploadable,
                    'label' => $label,
                    'optionLabel' => $label . ' · 可上传 ' . $uploadable . '/' . $total,
                ];
            }, $rows),
        ]);
    }

    /** 可选已发布素材（含本地/CDN）；可按 TikTok 发布账号过滤 */
    public function listSyncMaterials(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $publishAccountId = (int)$request->get('publishAccountId', $request->get('tiktokAccountId', 0));
        \App\Services\Github\GithubReleaseCdnService::ensureSchema();
        $filters = [
            "m.publish_status='published'",
            'm.publish_account_id IS NOT NULL',
            'm.publish_account_id > 0',
        ];
        $params = [];
        if ($publishAccountId > 0) {
            $filters[] = 'm.publish_account_id=?';
            $params[] = $publishAccountId;
        }
        if ($keyword !== '') {
            $filters[] = '(m.name LIKE ? OR m.share_title LIKE ? OR m.file_name LIKE ? OR m.platform_video_id LIKE ? OR CAST(m.id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        // 按发布账号可见范围过滤（勿按素材 owner_id，否则运营看不见企业客户素材）
        DataScope::applyAccountListScope('a.owner_id', $filters, $params);
        $where = 'WHERE ' . implode(' AND ', $filters);
        $from = 'FROM materials m INNER JOIN accounts a ON a.id = m.publish_account_id';
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c {$from} {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT m.id, m.name, m.file_name, m.file_path, m.mime_type, m.size_bytes,
                    m.share_title, m.share_caption, m.publish_status, m.publish_account_id,
                    IFNULL(m.cdn_url,'') AS cdn_url, IFNULL(m.github_asset_url,'') AS github_asset_url,
                    IFNULL(m.platform_video_id,'') AS platform_video_id,
                    m.publish_account, m.created_at, m.platform_published_at
             {$from}
             {$where}
             ORDER BY COALESCE(m.platform_published_at, m.created_at) DESC, m.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];
        return $this->success([
            'items' => array_map(static function (array $r): array {
                $cdn = trim((string)($r['cdn_url'] ?? ''));
                if ($cdn === '') {
                    $cdn = trim((string)($r['github_asset_url'] ?? ''));
                }
                $local = trim((string)($r['file_path'] ?? ''));
                $hasLocal = $local !== '' && is_file($local);
                return [
                    'id' => (int)$r['id'],
                    'name' => (string)($r['name'] ?? ''),
                    'fileName' => (string)($r['file_name'] ?? ''),
                    'title' => (string)(($r['share_title'] ?? '') !== '' ? $r['share_title'] : ($r['name'] ?? '')),
                    'caption' => (string)($r['share_caption'] ?? ''),
                    'cdnUrl' => $cdn,
                    'hasLocalFile' => $hasLocal,
                    'hasCdn' => $cdn !== '',
                    'uploadable' => $hasLocal || $cdn !== '',
                    'publishStatus' => (string)($r['publish_status'] ?? ''),
                    'platformVideoId' => (string)($r['platform_video_id'] ?? ''),
                    'publishAccountId' => (int)($r['publish_account_id'] ?? 0),
                    'publishAccount' => (string)($r['publish_account'] ?? ''),
                    'sizeBytes' => (int)($r['size_bytes'] ?? 0),
                    'createdAt' => (string)($r['created_at'] ?? ''),
                    'postedAt' => (string)($r['platform_published_at'] ?? ''),
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => $pageSize,
            'publishAccountId' => $publishAccountId,
        ]);
    }

    /**
     * 创建任务：素材 × YouTube 账号 → youtube_publish_tasks（默认仅入队，由计划任务上传）。
     */
    public function enqueueSync(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $materialIds = $request->input('materialIds', $request->input('material_ids', []));
        $accountIds = $request->input('youtubeAccountIds', $request->input('accountIds', []));
        if (!is_array($materialIds)) {
            $materialIds = [];
        }
        if (!is_array($accountIds)) {
            $accountIds = [];
        }
        $materialIds = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if (!$materialIds || !$accountIds) {
            return $this->error('请选择 TikTok 视频与 YouTube 账号', 422);
        }
        $tiktokAccountId = (int)$request->input('tiktokAccountId', $request->input('publishAccountId', 0));
        $privacy = strtolower(trim((string)$request->input('privacyStatus', $request->input('privacy_status', 'unlisted'))));
        if (!in_array($privacy, ['public', 'unlisted', 'private'], true)) {
            $privacy = 'unlisted';
        }
        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);
        // 默认不立即上传：交给 php artisan youtube:publish-dispatch
        $runNow = filter_var($request->input('runNow', false), FILTER_VALIDATE_BOOLEAN);
        $ownerId = DataScope::writeOwnerId();
        $taskName = trim((string)$request->input('name', $request->input('taskName', '')));

        if ($tiktokAccountId > 0) {
            $ttAcc = DB::selectOne('SELECT * FROM accounts WHERE id=?', [$tiktokAccountId]);
            if (!$ttAcc || !DataScope::isAccountRowVisible($ttAcc)) {
                return $this->error('无权使用该 TikTok 账号', 403);
            }
        }

        $created = [];
        $skipped = [];
        $errors = [];

        foreach ($materialIds as $mid) {
            $mat = DB::selectOne(
                'SELECT id, name, file_path, file_name, mime_type, size_bytes, share_title, share_caption,
                        publish_account_id, IFNULL(cdn_url,\'\') AS cdn_url, IFNULL(github_asset_url,\'\') AS github_asset_url
                 FROM materials WHERE id=?',
                [$mid]
            );
            if (!$mat) {
                $errors[] = ['materialId' => $mid, 'message' => '素材不存在'];
                continue;
            }
            $matTikTokId = (int)($mat['publish_account_id'] ?? 0);
            if ($tiktokAccountId > 0 && $matTikTokId > 0 && $matTikTokId !== $tiktokAccountId) {
                $errors[] = ['materialId' => $mid, 'message' => '素材不属于所选 TikTok 账号'];
                continue;
            }
            $useTikTokId = $tiktokAccountId > 0 ? $tiktokAccountId : $matTikTokId;
            if ($useTikTokId > 0) {
                $ttRow = DB::selectOne('SELECT * FROM accounts WHERE id=?', [$useTikTokId]);
                if (!$ttRow || !DataScope::isAccountRowVisible($ttRow)) {
                    $errors[] = ['materialId' => $mid, 'message' => '无权使用该素材所属 TikTok 账号'];
                    continue;
                }
            }
            $rawTitle = trim((string)(($mat['share_title'] ?? '') !== '' ? $mat['share_title'] : ($mat['name'] ?? '')));
            $title = YouTubeApiService::sanitizeVideoTitle($rawTitle, 'TikTok #' . $mid);
            $desc = YouTubeApiService::sanitizeVideoDescription(trim((string)($mat['share_caption'] ?? '')));
            $local = trim((string)($mat['file_path'] ?? ''));
            $cdn = trim((string)($mat['cdn_url'] ?? ''));
            if ($cdn === '') {
                $cdn = trim((string)($mat['github_asset_url'] ?? ''));
            }
            if (($local === '' || !is_file($local)) && $cdn === '') {
                $errors[] = ['materialId' => $mid, 'message' => '素材无本地文件且无 CDN，无法上传'];
                continue;
            }

            foreach ($accountIds as $aid) {
                $acc = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$aid]);
                if (!$acc) {
                    $errors[] = ['materialId' => $mid, 'accountId' => $aid, 'message' => 'YouTube 账号不存在'];
                    continue;
                }
                if (trim((string)($acc['refresh_token'] ?? '')) === '') {
                    $errors[] = ['materialId' => $mid, 'accountId' => $aid, 'message' => '账号未完成 OAuth 授权'];
                    continue;
                }
                if (!$force) {
                    $dup = DB::selectOne(
                        "SELECT id FROM youtube_publish_tasks
                         WHERE account_id=? AND material_id=? AND status IN ('completed','running','pending','draft')
                         ORDER BY id DESC LIMIT 1",
                        [$aid, $mid]
                    );
                    if ($dup) {
                        $skipped[] = ['materialId' => $mid, 'accountId' => $aid, 'taskId' => (int)$dup['id']];
                        continue;
                    }
                }
                $name = $taskName !== ''
                    ? mb_substr($taskName . ' · ' . $title, 0, 255)
                    : mb_substr('Youtube发布 · ' . $title . ' → #' . $aid, 0, 255);
                $reqJson = json_encode([
                    'source' => 'tiktok_sync',
                    'materialId' => $mid,
                    'tiktokAccountId' => $useTikTokId,
                    'sourceCdnUrl' => $cdn,
                ], JSON_UNESCAPED_UNICODE);
                DB::query(
                    "INSERT INTO youtube_publish_tasks
                     (owner_id, account_id, material_id, tiktok_account_id, name, status, title, description, tags_json, category_id,
                      privacy_status, made_for_kids, local_file_path, source_cdn_url, mime_type, file_size, request_json)
                     VALUES (?,?,?,?,?, 'pending', ?, ?, '[]', '22', ?, 0, ?, ?, ?, ?, ?)",
                    [
                        $ownerId,
                        $aid,
                        $mid,
                        $useTikTokId > 0 ? $useTikTokId : null,
                        $name,
                        $title,
                        $desc,
                        $privacy,
                        $local,
                        $cdn,
                        (string)($mat['mime_type'] ?? 'video/mp4'),
                        (int)($mat['size_bytes'] ?? 0),
                        $reqJson,
                    ]
                );
                $taskId = (int)DB::getPdo()->lastInsertId();
                $item = ['materialId' => $mid, 'accountId' => $aid, 'taskId' => $taskId, 'status' => 'pending'];
                if ($runNow) {
                    try {
                        $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
                        $svc = new YouTubeApiService($acc);
                        $svc->runPublishTask($taskId);
                        $item['status'] = 'completed';
                    } catch (\Throwable $e) {
                        $item['status'] = 'failed';
                        $item['error'] = $e->getMessage();
                        $errors[] = ['materialId' => $mid, 'accountId' => $aid, 'taskId' => $taskId, 'message' => $e->getMessage()];
                    }
                }
                $created[] = $item;
            }
        }

        return $this->success([
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
            'createdCount' => count($created),
            'skippedCount' => count($skipped),
            'errorCount' => count($errors),
            'runNow' => $runNow,
        ], $runNow ? '同步任务已处理' : '已创建发布任务，等待计划任务上传');
    }

    public function listPublishTasks(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $status = trim((string)$request->get('status', ''));
        $accountId = (int)$request->get('accountId', 0);
        $filters = ['1=1'];
        $params = [];
        if ($status !== '') {
            $filters[] = 't.status=?';
            $params[] = $status;
        }
        if ($accountId > 0) {
            $filters[] = 't.account_id=?';
            $params[] = $accountId;
        }
        $where = 'WHERE ' . implode(' AND ', $filters);
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM youtube_publish_tasks t {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT t.*, a.channel_title, a.channel_id, a.nickname AS account_nickname,
                    ta.username AS tiktok_username, ta.nickname AS tiktok_nickname
             FROM youtube_publish_tasks t
             LEFT JOIN youtube_accounts a ON a.id=t.account_id
             LEFT JOIN accounts ta ON ta.id=t.tiktok_account_id
             {$where}
             ORDER BY t.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];
        return $this->success([
            'items' => array_map([$this, 'mapPublishTask'], $rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => $pageSize,
        ]);
    }

    public function runPublishTask(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $taskId = (int)$id;
        $task = DB::selectOne('SELECT * FROM youtube_publish_tasks WHERE id=?', [$taskId]);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        $acc = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [(int)$task['account_id']]);
        if (!$acc) {
            return $this->error('YouTube 账号不存在', 404);
        }
        try {
            $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
            $svc = new YouTubeApiService($acc);
            $result = $svc->runPublishTask($taskId);
            $fresh = DB::selectOne(
                "SELECT t.*, a.channel_title, a.channel_id, a.nickname AS account_nickname
                 FROM youtube_publish_tasks t
                 LEFT JOIN youtube_accounts a ON a.id=t.account_id
                 WHERE t.id=?",
                [$taskId]
            );
            return $this->success([
                'task' => $this->mapPublishTask($fresh ?: $task),
                'youtubeVideoId' => $result['youtubeVideoId'] ?? '',
            ], '上传完成');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** 编辑发布任务（名称/标题/描述/可见性）；上传中不可改 */
    public function updatePublishTask(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $taskId = (int)$id;
        $task = DB::selectOne('SELECT * FROM youtube_publish_tasks WHERE id=?', [$taskId]);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        $status = (string)($task['status'] ?? '');
        if ($status === 'running') {
            return $this->error('上传中的任务不可编辑', 422);
        }
        $all = $request->all();
        $name = array_key_exists('name', $all) ? trim((string)$all['name']) : (string)($task['name'] ?? '');
        $rawTitle = array_key_exists('title', $all) ? trim((string)$all['title']) : (string)($task['title'] ?? '');
        $title = YouTubeApiService::sanitizeVideoTitle($rawTitle, $name !== '' ? $name : ('Task #' . $taskId));
        $description = YouTubeApiService::sanitizeVideoDescription(
            array_key_exists('description', $all)
                ? (string)$all['description']
                : (string)($task['description'] ?? '')
        );
        $privacy = array_key_exists('privacyStatus', $all)
            ? strtolower(trim((string)$all['privacyStatus']))
            : (array_key_exists('privacy_status', $all)
                ? strtolower(trim((string)$all['privacy_status']))
                : (string)($task['privacy_status'] ?? 'unlisted'));
        if (!in_array($privacy, ['public', 'unlisted', 'private'], true)) {
            $privacy = (string)($task['privacy_status'] ?? 'unlisted');
        }
        if ($title === '') {
            return $this->error('标题不能为空', 422);
        }
        $newStatus = $status;
        $clearError = false;
        // 失败/草稿编辑后回到待执行，便于计划任务或手动重跑
        if (in_array($status, ['failed', 'error', 'draft'], true)) {
            $newStatus = 'pending';
            $clearError = true;
        }
        DB::query(
            "UPDATE youtube_publish_tasks
             SET name=?, title=?, description=?, privacy_status=?, status=?,
                 error_message=IF(?, '', error_message),
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [
                mb_substr($name !== '' ? $name : $title, 0, 255),
                $title,
                $description,
                $privacy,
                $newStatus,
                $clearError ? 1 : 0,
                $taskId,
            ]
        );
        $fresh = DB::selectOne(
            "SELECT t.*, a.channel_title, a.channel_id, a.nickname AS account_nickname,
                    ta.username AS tiktok_username, ta.nickname AS tiktok_nickname
             FROM youtube_publish_tasks t
             LEFT JOIN youtube_accounts a ON a.id=t.account_id
             LEFT JOIN accounts ta ON ta.id=t.tiktok_account_id
             WHERE t.id=?",
            [$taskId]
        );
        return $this->success(['task' => $this->mapPublishTask($fresh ?: $task)], '任务已更新');
    }

    /** 删除发布任务；上传中不可删 */
    public function deletePublishTask(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanUse()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $taskId = (int)$id;
        $task = DB::selectOne('SELECT id, status FROM youtube_publish_tasks WHERE id=?', [$taskId]);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ((string)($task['status'] ?? '') === 'running') {
            return $this->error('上传中的任务不可删除', 422);
        }
        DB::query('DELETE FROM youtube_publish_tasks WHERE id=?', [$taskId]);
        return $this->success(['id' => $taskId], '已删除');
    }

    // -------------------------------------------------------------------------
    // mappers
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function mapAccount(array $row): array
    {
        $hasRefresh = trim((string)($row['refresh_token'] ?? '')) !== '';
        $expiresAt = (string)($row['token_expires_at'] ?? '');
        $extra = json_decode((string)($row['extra_json'] ?? '{}'), true);
        if (!is_array($extra)) {
            $extra = [];
        }
        $companyId = (int)($row['company_id'] ?? 0);
        $companyName = trim((string)($row['company_name'] ?? ''));
        if ($companyName === '' && $companyId > 0) {
            try {
                $c = CompanyService::find($companyId);
                $companyName = trim((string)($c['name'] ?? ''));
            } catch (\Throwable $e) {
            }
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'companyId' => $companyId > 0 ? $companyId : null,
            'companyName' => $companyName,
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
            'notes' => (string)($extra['notes'] ?? ''),
            'channelId' => (string)($row['channel_id'] ?? ''),
            'channelTitle' => (string)($row['channel_title'] ?? ''),
            'channelCustomUrl' => (string)($row['channel_custom_url'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'lastError' => (string)($row['last_error'] ?? ''),
            'lastLoginAt' => (string)($row['last_login_at'] ?? ''),
            'tokenExpiresAt' => $expiresAt,
            'authorized' => $hasRefresh,
            'tokenValid' => $hasRefresh && ($expiresAt === '' || strtotime($expiresAt) > time()),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapPublishTask(array $row): array
    {
        $tikTokNick = trim((string)($row['tiktok_nickname'] ?? ''));
        $tikTokUser = trim((string)($row['tiktok_username'] ?? ''));
        return [
            'id' => (int)($row['id'] ?? 0),
            'accountId' => (int)($row['account_id'] ?? 0),
            'materialId' => (int)($row['material_id'] ?? 0),
            'tiktokAccountId' => (int)($row['tiktok_account_id'] ?? 0),
            'tiktokAccountLabel' => $tikTokNick !== '' ? $tikTokNick : ($tikTokUser !== '' ? $tikTokUser : ''),
            'name' => (string)($row['name'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'privacyStatus' => (string)($row['privacy_status'] ?? ''),
            'progressPercent' => (int)($row['progress_percent'] ?? 0),
            'youtubeVideoId' => (string)($row['youtube_video_id'] ?? ''),
            'errorMessage' => (string)($row['error_message'] ?? ''),
            'channelTitle' => (string)($row['channel_title'] ?? $row['account_nickname'] ?? ''),
            'channelId' => (string)($row['channel_id'] ?? ''),
            'startedAt' => (string)($row['started_at'] ?? ''),
            'finishedAt' => (string)($row['finished_at'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function oauthHtml(bool $ok, string $message): Response
    {
        $title = $ok ? 'YouTube 授权成功' : 'YouTube 授权失败';
        $color = $ok ? '#16a34a' : '#dc2626';
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</title></head><body style="font-family:system-ui,sans-serif;padding:48px;max-width:560px;margin:0 auto">'
            . '<h1 style="color:' . $color . ';font-size:22px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p style="line-height:1.6;color:#334155">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="color:#64748b;font-size:13px">可关闭此窗口，返回企业门户或 Node 后台刷新账号列表。</p>'
            . '</body></html>';
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
