<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Database\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\DataScope;
use App\Services\YouTube\YouTubeApiService;
use App\Services\YouTube\YouTubeOAuthConfigService;
use App\Services\YouTube\YouTubeSchema;

/**
 * YouTube：全局 OAuth 配置、多频道账号授权、TikTok 素材同步发布。
 */
class YouTubeController extends Controller
{
    private function denyUnlessCanManage(): ?Response
    {
        DataScope::bootFromGlobals();
        if (DataScope::isLoginSuper() || DataScope::isLoginOpsAdmin()) {
            return null;
        }
        $perms = $GLOBALS['auth_permissions'] ?? [];
        if (is_array($perms) && (in_array('*', $perms, true) || in_array('admin', $perms, true)
            || in_array('system.youtube', $perms, true) || in_array('youtube.manage', $perms, true))) {
            return null;
        }
        return $this->error('无权管理 YouTube', 403);
    }

    // -------------------------------------------------------------------------
    // OAuth 全局配置
    // -------------------------------------------------------------------------

    public function getOAuthConfig(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanManage()) {
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
        if ($deny = $this->denyUnlessCanManage()) {
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
        if ($deny = $this->denyUnlessCanManage()) {
            return $deny;
        }
        YouTubeSchema::ensureSchema();
        $keyword = trim((string)$request->get('keyword', ''));
        $filters = ['1=1'];
        $params = [];
        if ($keyword !== '') {
            $filters[] = '(username LIKE ? OR nickname LIKE ? OR channel_title LIKE ? OR channel_id LIKE ? OR CAST(id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $where = 'WHERE ' . implode(' AND ', $filters);
        $rows = DB::select(
            "SELECT * FROM youtube_accounts {$where} ORDER BY id DESC LIMIT 500",
            $params
        ) ?: [];
        return $this->success([
            'items' => array_map([$this, 'mapAccount'], $rows),
            'oauthConfigured' => YouTubeOAuthConfigService::isConfigured(),
        ]);
    }

    public function createAccount(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanManage()) {
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
        $ownerId = DataScope::writeOwnerId();
        $extra = json_encode(['notes' => $notes], JSON_UNESCAPED_UNICODE);
        DB::query(
            "INSERT INTO youtube_accounts
             (owner_id, username, nickname, google_cloud_project_id, oauth_client_id, oauth_client_secret,
              oauth_redirect_uri, oauth_scopes, status, extra_json)
             VALUES (?,?,?,?,?,?,?,?, 'pending', ?)",
            [
                $ownerId,
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
        $row = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$id]);
        return $this->success($this->mapAccount($row ?: ['id' => $id]), '账号草稿已创建，请点击授权登录');
    }

    public function deleteAccount(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanManage()) {
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

    /** 开始 OAuth：返回 Google 授权 URL */
    public function startAuth(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanManage()) {
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
            DB::query(
                "UPDATE youtube_accounts SET status='error', last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [mb_substr($e->getMessage(), 0, 500), $aid]
            );
            return $this->oauthHtml(false, '换取 token 失败：' . $e->getMessage());
        }
    }

    public function refreshAccountToken(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessCanManage()) {
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
    // TikTok → YouTube 同步
    // -------------------------------------------------------------------------

    /** 有已发布可上传素材的 TikTok 账号列表（创建任务第一步） */
    public function listTikTokPublishAccounts(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanManage()) {
            return $deny;
        }
        $keyword = trim((string)$request->get('keyword', ''));
        $filters = [
            "m.publish_status='published'",
            'm.publish_account_id IS NOT NULL',
            'm.publish_account_id > 0',
            "(TRIM(IFNULL(m.file_path,''))<>'' OR TRIM(IFNULL(m.cdn_url,''))<>'' OR TRIM(IFNULL(m.github_asset_url,''))<>'')",
        ];
        $params = [];
        DataScope::applyOwnerFilter('a.owner_id', $filters, $params);
        if ($keyword !== '') {
            $filters[] = '(a.username LIKE ? OR a.nickname LIKE ? OR CAST(a.id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }
        $where = 'WHERE ' . implode(' AND ', $filters);
        $rows = DB::select(
            "SELECT a.id, a.username, a.nickname, a.status,
                    COUNT(DISTINCT m.id) AS video_count
             FROM materials m
             INNER JOIN accounts a ON a.id = m.publish_account_id
             {$where}
             GROUP BY a.id, a.username, a.nickname, a.status
             ORDER BY video_count DESC, a.id DESC
             LIMIT 500",
            $params
        ) ?: [];
        return $this->success([
            'items' => array_map(static function (array $r): array {
                return [
                    'id' => (int)$r['id'],
                    'username' => (string)($r['username'] ?? ''),
                    'nickname' => (string)($r['nickname'] ?? ''),
                    'status' => (string)($r['status'] ?? ''),
                    'videoCount' => (int)($r['video_count'] ?? 0),
                    'label' => trim((string)(($r['nickname'] ?? '') !== '' ? $r['nickname'] : ($r['username'] ?? '')))
                        ?: ('账号 #' . (int)$r['id']),
                ];
            }, $rows),
        ]);
    }

    /** 可选已发布素材（含本地/CDN）；可按 TikTok 发布账号过滤 */
    public function listSyncMaterials(Request $request): Response
    {
        if ($deny = $this->denyUnlessCanManage()) {
            return $deny;
        }
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $publishAccountId = (int)$request->get('publishAccountId', $request->get('tiktokAccountId', 0));
        \App\Services\Github\GithubReleaseCdnService::ensureSchema();
        $filters = ["m.publish_status='published'"];
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
        DataScope::applyOwnerFilter('m.owner_id', $filters, $params);
        $where = 'WHERE ' . implode(' AND ', $filters);
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM materials m {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT m.id, m.name, m.file_name, m.file_path, m.mime_type, m.size_bytes,
                    m.share_title, m.share_caption, m.publish_status, m.publish_account_id,
                    IFNULL(m.cdn_url,'') AS cdn_url, IFNULL(m.github_asset_url,'') AS github_asset_url,
                    IFNULL(m.platform_video_id,'') AS platform_video_id,
                    m.publish_account, m.created_at, m.platform_published_at
             FROM materials m
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
        if ($deny = $this->denyUnlessCanManage()) {
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
            $title = trim((string)(($mat['share_title'] ?? '') !== '' ? $mat['share_title'] : ($mat['name'] ?? '')));
            if ($title === '') {
                $title = 'TikTok #' . $mid;
            }
            $desc = trim((string)($mat['share_caption'] ?? ''));
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
                        mb_substr($title, 0, 255),
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
        if ($deny = $this->denyUnlessCanManage()) {
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
        if ($deny = $this->denyUnlessCanManage()) {
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

    // -------------------------------------------------------------------------
    // mappers
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function mapAccount(array $row): array
    {
        $hasRefresh = trim((string)($row['refresh_token'] ?? '')) !== '';
        $expiresAt = (string)($row['token_expires_at'] ?? '');
        return [
            'id' => (int)($row['id'] ?? 0),
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
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
            . '<p style="color:#64748b;font-size:13px">可关闭此窗口，返回 TkSwarm Node 刷新账号列表。</p>'
            . '</body></html>';
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
