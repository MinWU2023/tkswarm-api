<?php
declare(strict_types=1);

namespace App\Services\YouTube;

use App\Core\Database\DB;

/**
 * YouTube Data API v3 官方接口封装（无页面）。
 *
 * 覆盖：
 * - OAuth2 access_token 刷新
 * - videos.insert（可恢复上传）/ videos.delete / videos.list / videos.update
 * - commentThreads.list（拉评论）
 * - comments.list（拉回复）/ comments.insert（回复评论）
 *
 * 文档：https://developers.google.com/youtube/v3/docs
 */
class YouTubeApiService
{
    public const API_BASE = 'https://www.googleapis.com/youtube/v3';
    public const UPLOAD_BASE = 'https://www.googleapis.com/upload/youtube/v3';
    public const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const DEFAULT_SCOPES = [
        'https://www.googleapis.com/auth/youtube',
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.force-ssl',
    ];

    /** @var array<string,mixed> */
    private array $account;

    /**
     * @param array<string,mixed>|int $accountOrId youtube_accounts 行或 id
     */
    public function __construct(array|int $accountOrId)
    {
        YouTubeSchema::ensureSchema();
        if (is_int($accountOrId)) {
            $row = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$accountOrId]);
            if (!$row) {
                throw new \InvalidArgumentException('YouTube 账号不存在: #' . $accountOrId);
            }
            $this->account = $row;
        } else {
            $this->account = $accountOrId;
        }
    }

    public static function forAccountId(int $accountId): self
    {
        return new self($accountId);
    }

    /** @return array<string,mixed> */
    public function account(): array
    {
        return $this->account;
    }

    public function accountId(): int
    {
        return (int)($this->account['id'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // OAuth2
    // -------------------------------------------------------------------------

    /**
     * 用 refresh_token 换取新的 access_token，并写回 youtube_accounts。
     * @return array{access_token:string,expires_in?:int,token_type?:string}
     */
    public function refreshAccessToken(): array
    {
        $refresh = trim((string)($this->account['refresh_token'] ?? ''));
        $clientId = trim((string)($this->account['oauth_client_id'] ?? ''));
        $clientSecret = trim((string)($this->account['oauth_client_secret'] ?? ''));
        if ($refresh === '' || $clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('缺少 OAuth client_id / client_secret / refresh_token');
        }

        $res = $this->httpForm(self::OAUTH_TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        if (empty($res['access_token'])) {
            $msg = (string)($res['error_description'] ?? $res['error'] ?? 'refresh_token 失败');
            $this->markAccountError($msg);
            throw new \RuntimeException($msg);
        }

        $expiresIn = max(60, (int)($res['expires_in'] ?? 3600));
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 60);
        YouTubeSchema::ensureAccountTokenRefreshColumn();
        $refreshedAt = date('Y-m-d H:i:s');
        DB::query(
            "UPDATE youtube_accounts
             SET access_token=?, token_type=?, token_expires_at=?, token_refreshed_at=?, last_login_at=NOW(),
                 status='enabled', last_error='', updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [
                (string)$res['access_token'],
                (string)($res['token_type'] ?? 'Bearer'),
                $expiresAt,
                $refreshedAt,
                $this->accountId(),
            ]
        );
        $this->account['access_token'] = (string)$res['access_token'];
        $this->account['token_type'] = (string)($res['token_type'] ?? 'Bearer');
        $this->account['token_expires_at'] = $expiresAt;
        $this->account['token_refreshed_at'] = $refreshedAt;
        $this->account['status'] = 'enabled';
        return $res;
    }

    /** 确保 access_token 可用（过期则刷新） */
    public function ensureAccessToken(): string
    {
        $token = trim((string)($this->account['access_token'] ?? ''));
        $expiresAt = trim((string)($this->account['token_expires_at'] ?? ''));
        $needRefresh = $token === '';
        if (!$needRefresh && $expiresAt !== '') {
            $needRefresh = strtotime($expiresAt) <= (time() + 30);
        }
        if ($needRefresh) {
            $this->refreshAccessToken();
            $token = trim((string)($this->account['access_token'] ?? ''));
        }
        if ($token === '') {
            throw new \RuntimeException('无法获取 YouTube access_token');
        }
        return $token;
    }

    /**
     * 用 authorization_code 换 token（首次授权回调时使用）。
     * @return array<string,mixed>
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        $clientId = trim((string)($this->account['oauth_client_id'] ?? ''));
        $clientSecret = trim((string)($this->account['oauth_client_secret'] ?? ''));
        $redirect = trim((string)($this->account['oauth_redirect_uri'] ?? ''));
        if ($clientId === '' || $clientSecret === '' || $redirect === '') {
            throw new \RuntimeException('缺少 OAuth client 配置或 redirect_uri');
        }
        $res = $this->httpForm(self::OAUTH_TOKEN_URL, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ]);
        if (empty($res['access_token'])) {
            throw new \RuntimeException((string)($res['error_description'] ?? $res['error'] ?? '换取 token 失败'));
        }
        $expiresIn = max(60, (int)($res['expires_in'] ?? 3600));
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 60);
        $refresh = (string)($res['refresh_token'] ?? $this->account['refresh_token'] ?? '');
        YouTubeSchema::ensureAccountTokenRefreshColumn();
        DB::query(
            "UPDATE youtube_accounts
             SET access_token=?, refresh_token=IF(?='', refresh_token, ?), token_type=?,
                 token_expires_at=?, token_refreshed_at=NOW(), last_login_at=NOW(), status='enabled', last_error='',
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [
                (string)$res['access_token'],
                $refresh,
                $refresh,
                (string)($res['token_type'] ?? 'Bearer'),
                $expiresAt,
                $this->accountId(),
            ]
        );
        $this->account = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$this->accountId()]) ?: $this->account;
        return $res;
    }

    /** 构建 Google OAuth 授权 URL */
    public function buildAuthUrl(array $extraScopes = [], string $state = ''): string
    {
        $clientId = trim((string)($this->account['oauth_client_id'] ?? ''));
        $redirect = trim((string)($this->account['oauth_redirect_uri'] ?? ''));
        if ($clientId === '' || $redirect === '') {
            throw new \RuntimeException('缺少 oauth_client_id / oauth_redirect_uri');
        }
        $scopes = trim((string)($this->account['oauth_scopes'] ?? ''));
        $scopeList = $scopes !== ''
            ? (preg_split('/[\s,]+/', $scopes) ?: self::DEFAULT_SCOPES)
            : self::DEFAULT_SCOPES;
        $scopeList = array_values(array_unique(array_filter(array_merge($scopeList, $extraScopes))));
        $q = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'scope' => implode(' ', $scopeList),
            'state' => $state !== '' ? $state : ('yt_acc_' . $this->accountId()),
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $q;
    }

    // -------------------------------------------------------------------------
    // Channels / Videos
    // -------------------------------------------------------------------------

    /**
     * 拉取当前授权账号的频道信息。
     * 先 mine=true（个人频道），再 managedByMe=true（Brand Account 等托管频道）。
     * @return array<string,mixed>
     */
    public function getMineChannel(string $part = 'snippet,contentDetails,statistics'): array
    {
        $item = null;
        foreach (['mine' => 'true', 'managedByMe' => 'true'] as $key => $val) {
            try {
                $pack = $this->apiGet('/channels', [
                    'part' => $part,
                    $key => $val,
                ]);
            } catch (\Throwable $e) {
                // managedByMe 在无权限时可能 403，继续尝试/汇总
                continue;
            }
            $candidate = $pack['items'][0] ?? null;
            if (is_array($candidate)) {
                $item = $candidate;
                break;
            }
        }
        if (!is_array($item)) {
            throw new \RuntimeException(
                '该 Google 账号下没有可用的 YouTube 频道。请用同一账号打开 https://www.youtube.com/create_channel 创建频道后，再回 TkSwarm 重新点「授权登录」'
            );
        }
        $saved = YouTubeSchema::applyChannelStatsToAccount($this->accountId(), $item);
        foreach ($saved as $k => $v) {
            $this->account[$k] = $v;
        }
        return $item;
    }

    /**
     * 拉取并落库频道基础数据（订阅/浏览/视频数/头像等）。
     * @return array<string,mixed>
     */
    public function pullChannelStats(): array
    {
        $this->getMineChannel('snippet,contentDetails,statistics');
        return [
            'channel_id' => (string)($this->account['channel_id'] ?? ''),
            'channel_title' => (string)($this->account['channel_title'] ?? ''),
            'channel_custom_url' => (string)($this->account['channel_custom_url'] ?? ''),
            'subscriber_count' => (int)($this->account['subscriber_count'] ?? 0),
            'channel_view_count' => (int)($this->account['channel_view_count'] ?? 0),
            'channel_video_count' => (int)($this->account['channel_video_count'] ?? 0),
            'hidden_subscriber_count' => (int)($this->account['hidden_subscriber_count'] ?? 0),
            'channel_thumb_url' => (string)($this->account['channel_thumb_url'] ?? ''),
            'channel_description' => (string)($this->account['channel_description'] ?? ''),
            'channel_country' => (string)($this->account['channel_country'] ?? ''),
            'channel_published_at' => (string)($this->account['channel_published_at'] ?? ''),
            'channel_stats_synced_at' => (string)($this->account['channel_stats_synced_at'] ?? ''),
        ];
    }

    /**
     * 拉取视频详情 videos.list
     * @param list<string>|string $videoIds
     * @return array<string,mixed>
     */
    public function getVideos(array|string $videoIds, string $part = 'snippet,contentDetails,statistics,status'): array
    {
        $ids = is_array($videoIds) ? $videoIds : (preg_split('/[,\s]+/', $videoIds) ?: []);
        $ids = array_values(array_filter(array_map('strval', $ids)));
        if (!$ids) {
            throw new \InvalidArgumentException('videoIds 不能为空');
        }
        return $this->apiGet('/videos', [
            'part' => $part,
            'id' => implode(',', array_slice($ids, 0, 50)),
        ]);
    }

    /**
     * 拉取单个视频并可选同步到 youtube_videos。
     * @return array<string,mixed>|null
     */
    public function pullVideoInfo(string $youtubeVideoId, bool $persist = true): ?array
    {
        $pack = $this->getVideos($youtubeVideoId);
        $item = $pack['items'][0] ?? null;
        if (!is_array($item)) {
            return null;
        }
        if ($persist) {
            YouTubeSchema::upsertVideoFromApi($this->accountId(), (int)($this->account['owner_id'] ?? 0), $item);
        }
        return $item;
    }

    /**
     * 上传并发布视频（videos.insert + resumable upload）。
     *
     * @param array{
     *   title:string,description?:string,tags?:list<string>,categoryId?:string,
     *   privacyStatus?:string,publishAt?:?string,madeForKids?:bool,
     *   filePath:string,mimeType?:string
     * } $meta
     * @return array{video:array<string,mixed>,youtubeVideoId:string,raw:array<string,mixed>}
     */
    public function publishVideo(array $meta, ?callable $onProgress = null): array
    {
        $filePath = (string)($meta['filePath'] ?? '');
        if ($filePath === '' || !is_file($filePath)) {
            throw new \InvalidArgumentException('视频文件不存在: ' . $filePath);
        }
        $title = self::sanitizeVideoTitle((string)($meta['title'] ?? ''), (string)($meta['fallbackTitle'] ?? 'Untitled Video'));
        if ($title === '') {
            throw new \InvalidArgumentException('title 不能为空');
        }
        $mime = (string)($meta['mimeType'] ?? '');
        if ($mime === '') {
            $mime = (string)(mime_content_type($filePath) ?: 'video/*');
        }
        $size = (int)filesize($filePath);
        $privacy = (string)($meta['privacyStatus'] ?? 'private');
        if (!in_array($privacy, ['public', 'unlisted', 'private'], true)) {
            $privacy = 'private';
        }
        $snippet = [
            'title' => $title,
            'description' => (string)($meta['description'] ?? ''),
            'categoryId' => (string)($meta['categoryId'] ?? '22'),
        ];
        if (!empty($meta['tags']) && is_array($meta['tags'])) {
            $snippet['tags'] = array_values(array_map('strval', $meta['tags']));
        }
        $status = [
            'privacyStatus' => $privacy,
            'selfDeclaredMadeForKids' => !empty($meta['madeForKids']),
        ];
        if (!empty($meta['publishAt']) && $privacy === 'private') {
            // 定时公开：需 privacyStatus=private + publishAt
            $status['publishAt'] = gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$meta['publishAt']) ?: time());
        }
        $body = json_encode(['snippet' => $snippet, 'status' => $status], JSON_UNESCAPED_UNICODE);

        $initHeaders = [
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Length: ' . $size,
            'X-Upload-Content-Type: ' . $mime,
        ];
        $init = $this->apiRequest(
            'POST',
            self::UPLOAD_BASE . '/videos?uploadType=resumable&part=snippet,status',
            $body,
            $initHeaders,
            true
        );
        $sessionUrl = (string)($init['_headers']['location'] ?? $init['_headers']['Location'] ?? '');
        if ($sessionUrl === '') {
            throw new \RuntimeException('未拿到可恢复上传会话 URL: ' . json_encode($init, JSON_UNESCAPED_UNICODE));
        }

        $uploaded = $this->resumableUpload($sessionUrl, $filePath, $mime, $size, $onProgress);
        $youtubeVideoId = (string)($uploaded['id'] ?? '');
        if ($youtubeVideoId === '') {
            throw new \RuntimeException('上传完成但未返回 video id');
        }
        $ownerId = (int)($this->account['owner_id'] ?? 0);
        $localId = YouTubeSchema::upsertVideoFromApi($this->accountId(), $ownerId, $uploaded, [
            'local_file_path' => $filePath,
            'mime_type' => $mime,
            'file_size' => $size,
            'status' => 'processing',
        ]);

        return [
            'video' => $uploaded,
            'youtubeVideoId' => $youtubeVideoId,
            'localVideoId' => $localId,
            'raw' => $uploaded,
        ];
    }

    /**
     * 按发布任务执行上传（写回 youtube_publish_tasks / youtube_videos）。
     * @return array<string,mixed>
     */
    public function runPublishTask(int $taskId): array
    {
        $task = DB::selectOne('SELECT * FROM youtube_publish_tasks WHERE id=?', [$taskId]);
        if (!$task) {
            throw new \InvalidArgumentException('发布任务不存在');
        }
        if ((int)($task['account_id'] ?? 0) !== $this->accountId()) {
            throw new \RuntimeException('任务与账号不匹配');
        }
        DB::query(
            "UPDATE youtube_publish_tasks SET status='running', started_at=NOW(), error_message='', updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$taskId]
        );
        $tempFile = '';
        try {
            $tags = json_decode((string)($task['tags_json'] ?? '[]'), true);
            if (!is_array($tags)) {
                $tags = [];
            }
            $filePath = trim((string)($task['local_file_path'] ?? ''));
            $mime = (string)($task['mime_type'] ?? 'video/*');
            if ($filePath === '' || !is_file($filePath)) {
                $resolved = $this->resolvePublishTaskFile($task);
                $filePath = (string)($resolved['filePath'] ?? '');
                $tempFile = (string)($resolved['tempFile'] ?? '');
                if (!empty($resolved['mimeType'])) {
                    $mime = (string)$resolved['mimeType'];
                }
                if ($filePath !== '' && is_file($filePath)) {
                    DB::query(
                        'UPDATE youtube_publish_tasks SET local_file_path=?, mime_type=?, file_size=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                        [$filePath, $mime, (int)@filesize($filePath), $taskId]
                    );
                }
            }
            $rawTitle = trim((string)($task['title'] ?? ''));
            $fallback = trim((string)($task['name'] ?? ''));
            if ($fallback === '') {
                $fallback = 'Youtube #' . $taskId;
            }
            $safeTitle = self::sanitizeVideoTitle($rawTitle, $fallback);
            // 回写清洗后的标题，避免下次仍带非法字符/超长
            if ($safeTitle !== $rawTitle) {
                DB::query(
                    'UPDATE youtube_publish_tasks SET title=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$safeTitle, $taskId]
                );
            }
            $result = $this->publishVideo([
                'title' => $safeTitle,
                'fallbackTitle' => $fallback,
                'description' => self::sanitizeVideoDescription((string)($task['description'] ?? '')),
                'tags' => $tags,
                'categoryId' => (string)($task['category_id'] ?? '22'),
                'privacyStatus' => (string)($task['privacy_status'] ?? 'private'),
                'publishAt' => $task['publish_at'] ?? null,
                'madeForKids' => !empty($task['made_for_kids']),
                'filePath' => $filePath,
                'mimeType' => $mime,
            ], function (int $pct) use ($taskId) {
                DB::query(
                    'UPDATE youtube_publish_tasks SET progress_percent=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$pct, $taskId]
                );
            });
            DB::query(
                "UPDATE youtube_publish_tasks
                 SET status='completed', progress_percent=100, youtube_video_id=?, video_id=?,
                     response_json=?, finished_at=NOW(), updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [
                    $result['youtubeVideoId'],
                    (int)($result['localVideoId'] ?? 0) ?: null,
                    json_encode($result['raw'], JSON_UNESCAPED_UNICODE),
                    $taskId,
                ]
            );
            return $result;
        } catch (\Throwable $e) {
            DB::query(
                "UPDATE youtube_publish_tasks
                 SET status='failed', error_message=?, finished_at=NOW(), updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [mb_substr($e->getMessage(), 0, 1000), $taskId]
            );
            throw $e;
        } finally {
            if ($tempFile !== '' && is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * 解析发布任务视频文件：本地路径 → 素材表 → CDN 下载到临时文件。
     * @param array<string,mixed> $task
     * @return array{filePath:string,tempFile?:string,mimeType?:string}
     */
    private function resolvePublishTaskFile(array $task): array
    {
        $filePath = trim((string)($task['local_file_path'] ?? ''));
        if ($filePath !== '' && is_file($filePath)) {
            return ['filePath' => $filePath];
        }
        $cdnUrl = trim((string)($task['source_cdn_url'] ?? ''));
        $mime = trim((string)($task['mime_type'] ?? ''));
        $materialId = (int)($task['material_id'] ?? 0);
        if ($materialId > 0) {
            $mat = DB::selectOne(
                'SELECT id, file_path, mime_type, file_name, cdn_url, github_asset_url FROM materials WHERE id=?',
                [$materialId]
            );
            if ($mat) {
                $local = trim((string)($mat['file_path'] ?? ''));
                if ($local !== '' && is_file($local)) {
                    return [
                        'filePath' => $local,
                        'mimeType' => (string)($mat['mime_type'] ?? $mime),
                    ];
                }
                if ($cdnUrl === '') {
                    $cdnUrl = trim((string)($mat['cdn_url'] ?? ''));
                }
                if ($cdnUrl === '') {
                    $cdnUrl = trim((string)($mat['github_asset_url'] ?? ''));
                }
                if ($mime === '') {
                    $mime = (string)($mat['mime_type'] ?? '');
                }
            }
        }
        if ($cdnUrl === '') {
            $req = json_decode((string)($task['request_json'] ?? '{}'), true);
            if (is_array($req)) {
                $cdnUrl = trim((string)($req['sourceCdnUrl'] ?? $req['cdnUrl'] ?? ''));
            }
        }
        if ($cdnUrl === '') {
            throw new \RuntimeException('发布任务缺少本地视频文件，且无法从 CDN/素材解析下载地址');
        }
        $ext = pathinfo(parse_url($cdnUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'mp4';
        $ext = preg_replace('/[^a-zA-Z0-9]+/', '', (string)$ext) ?: 'mp4';
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tkswarm-yt-upload';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tempFile = $dir . DIRECTORY_SEPARATOR . 'task_' . (int)($task['id'] ?? 0) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $this->downloadUrlToFile($cdnUrl, $tempFile);
        return [
            'filePath' => $tempFile,
            'tempFile' => $tempFile,
            'mimeType' => $mime !== '' ? $mime : 'video/' . ($ext === 'mov' ? 'quicktime' : $ext),
        ];
    }

    private function downloadUrlToFile(string $url, string $dest): void
    {
        $fp = fopen($dest, 'wb');
        if (!$fp) {
            throw new \RuntimeException('无法创建临时文件: ' . $dest);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'TkSwarm-YouTubeSync/1.0',
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok === false || $code >= 400 || !is_file($dest) || filesize($dest) < 1) {
            @unlink($dest);
            throw new \RuntimeException('下载视频失败 HTTP ' . $code . ($err !== '' ? (': ' . $err) : ''));
        }
    }

    /** videos.delete */
    public function deleteVideo(string $youtubeVideoId): bool
    {
        $youtubeVideoId = trim($youtubeVideoId);
        if ($youtubeVideoId === '') {
            throw new \InvalidArgumentException('youtubeVideoId 不能为空');
        }
        $this->apiRequest('DELETE', self::API_BASE . '/videos?' . http_build_query([
            'id' => $youtubeVideoId,
        ]), null, [], false, [204, 200]);
        DB::query(
            "UPDATE youtube_videos SET status='deleted', upload_status='deleted', updated_at=CURRENT_TIMESTAMP
             WHERE account_id=? AND youtube_video_id=?",
            [$this->accountId(), $youtubeVideoId]
        );
        return true;
    }

    /**
     * 拉取当前频道 uploads 播放列表中的视频并落库。
     * @return array{pulled:int,pages:int,channelId:string,uploadsPlaylistId:string}
     */
    public function pullChannelVideos(int $maxPages = 10, int $pageSize = 50): array
    {
        $channel = $this->getMineChannel('snippet,contentDetails,statistics');
        $channelId = (string)($channel['id'] ?? $this->account['channel_id'] ?? '');
        $uploads = (string)($channel['contentDetails']['relatedPlaylists']['uploads'] ?? '');
        if ($uploads === '') {
            throw new \RuntimeException('未找到频道 uploads 播放列表，请确认频道已创建');
        }
        $ownerId = (int)($this->account['owner_id'] ?? 0);
        $pulled = 0;
        $pages = 0;
        $pageToken = '';
        $maxPages = max(1, min(40, $maxPages));
        $pageSize = max(1, min(50, $pageSize));
        for ($i = 0; $i < $maxPages; $i++) {
            $params = [
                'part' => 'snippet,contentDetails,status',
                'playlistId' => $uploads,
                'maxResults' => $pageSize,
            ];
            if ($pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }
            $pack = $this->apiGet('/playlistItems', $params);
            $pages++;
            $items = is_array($pack['items'] ?? null) ? $pack['items'] : [];
            $videoIds = [];
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $vid = (string)($it['contentDetails']['videoId']
                    ?? $it['snippet']['resourceId']['videoId']
                    ?? '');
                if ($vid !== '') {
                    $videoIds[] = $vid;
                }
            }
            $videoIds = array_values(array_unique($videoIds));
            if ($videoIds) {
                foreach (array_chunk($videoIds, 50) as $chunk) {
                    $detail = $this->getVideos($chunk);
                    foreach (($detail['items'] ?? []) as $video) {
                        if (!is_array($video)) {
                            continue;
                        }
                        $id = YouTubeSchema::upsertVideoFromApi($this->accountId(), $ownerId, $video);
                        if ($id > 0) {
                            $pulled++;
                        }
                    }
                }
            }
            $pageToken = (string)($pack['nextPageToken'] ?? '');
            if ($pageToken === '' || !$items) {
                break;
            }
        }
        return [
            'pulled' => $pulled,
            'pages' => $pages,
            'channelId' => $channelId,
            'uploadsPlaylistId' => $uploads,
        ];
    }

    /**
     * videos.update（更新标题/描述/标签/隐私等）
     * @param array<string,mixed> $patch snippet/status 字段
     * @return array<string,mixed>
     */
    public function updateVideo(string $youtubeVideoId, array $patch, string $part = 'snippet,status'): array
    {
        $body = array_merge(['id' => $youtubeVideoId], $patch);
        $res = $this->apiRequest(
            'PUT',
            self::API_BASE . '/videos?' . http_build_query(['part' => $part]),
            json_encode($body, JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json; charset=UTF-8']
        );
        YouTubeSchema::upsertVideoFromApi($this->accountId(), (int)($this->account['owner_id'] ?? 0), $res);
        return $res;
    }

    // -------------------------------------------------------------------------
    // Comments
    // -------------------------------------------------------------------------

    /**
     * 拉取视频评论线程 commentThreads.list
     * @return array{items:list<array<string,mixed>>,nextPageToken?:string,pageInfo?:array}
     */
    public function listCommentThreads(
        string $youtubeVideoId,
        string $pageToken = '',
        int $maxResults = 100,
        string $order = 'time',
        string $part = 'snippet,replies'
    ): array {
        $params = [
            'part' => $part,
            'videoId' => $youtubeVideoId,
            'maxResults' => max(1, min(100, $maxResults)),
            'textFormat' => 'plainText',
            'order' => in_array($order, ['time', 'relevance'], true) ? $order : 'time',
        ];
        if ($pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }
        return $this->apiGet('/commentThreads', $params);
    }

    /**
     * 拉取某条顶层评论的全部回复 comments.list
     * @return array<string,mixed>
     */
    public function listCommentReplies(string $parentCommentId, string $pageToken = '', int $maxResults = 100): array
    {
        $params = [
            'part' => 'snippet',
            'parentId' => $parentCommentId,
            'maxResults' => max(1, min(100, $maxResults)),
            'textFormat' => 'plainText',
        ];
        if ($pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }
        return $this->apiGet('/comments', $params);
    }

    /**
     * 拉取视频评论并落库 youtube_comments（含首屏 replies）。
     * @return array{threads:int,comments:int,replies:int}
     */
    public function pullComments(string $youtubeVideoId, int $maxPages = 5, bool $fetchAllReplies = false): array
    {
        $youtubeVideoId = trim($youtubeVideoId);
        if ($youtubeVideoId === '') {
            throw new \InvalidArgumentException('youtubeVideoId 不能为空');
        }
        $localVideo = DB::selectOne(
            'SELECT id FROM youtube_videos WHERE account_id=? AND youtube_video_id=? LIMIT 1',
            [$this->accountId(), $youtubeVideoId]
        );
        $localVideoId = (int)($localVideo['id'] ?? 0);
        $ownerId = (int)($this->account['owner_id'] ?? 0);

        $threads = 0;
        $comments = 0;
        $replies = 0;
        $pageToken = '';
        for ($page = 0; $page < max(1, $maxPages); $page++) {
            $pack = $this->listCommentThreads($youtubeVideoId, $pageToken);
            $items = $pack['items'] ?? [];
            if (!is_array($items) || !$items) {
                break;
            }
            foreach ($items as $thread) {
                if (!is_array($thread)) {
                    continue;
                }
                $threads++;
                $top = $thread['snippet']['topLevelComment'] ?? null;
                if (is_array($top)) {
                    $parentLocalId = YouTubeSchema::upsertCommentFromApi(
                        $this->accountId(),
                        $ownerId,
                        $localVideoId,
                        $youtubeVideoId,
                        $top,
                        0,
                        $thread
                    );
                    $comments++;
                    $nested = $thread['replies']['comments'] ?? [];
                    if (is_array($nested)) {
                        foreach ($nested as $rep) {
                            if (!is_array($rep)) {
                                continue;
                            }
                            YouTubeSchema::upsertCommentFromApi(
                                $this->accountId(),
                                $ownerId,
                                $localVideoId,
                                $youtubeVideoId,
                                $rep,
                                $parentLocalId,
                                null
                            );
                            $replies++;
                        }
                    }
                    $parentYtId = (string)($top['id'] ?? '');
                    $totalReply = (int)($thread['snippet']['totalReplyCount'] ?? 0);
                    if ($fetchAllReplies && $parentYtId !== '' && $totalReply > count(is_array($nested) ? $nested : [])) {
                        $replyToken = '';
                        do {
                            $rpack = $this->listCommentReplies($parentYtId, $replyToken);
                            foreach (($rpack['items'] ?? []) as $rep) {
                                if (!is_array($rep)) {
                                    continue;
                                }
                                YouTubeSchema::upsertCommentFromApi(
                                    $this->accountId(),
                                    $ownerId,
                                    $localVideoId,
                                    $youtubeVideoId,
                                    $rep,
                                    $parentLocalId,
                                    null
                                );
                                $replies++;
                            }
                            $replyToken = (string)($rpack['nextPageToken'] ?? '');
                        } while ($replyToken !== '');
                    }
                }
            }
            $pageToken = (string)($pack['nextPageToken'] ?? '');
            if ($pageToken === '') {
                break;
            }
        }
        if ($localVideoId > 0) {
            $cnt = (int)(DB::selectOne(
                'SELECT COUNT(*) AS c FROM youtube_comments WHERE video_id=? AND parent_id=0',
                [$localVideoId]
            )['c'] ?? 0);
            DB::query(
                'UPDATE youtube_videos SET comment_count=?, last_synced_at=NOW(), updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$cnt, $localVideoId]
            );
        }
        $creatorChannelId = trim((string)($this->account['channel_id'] ?? ''));
        if ($creatorChannelId === '') {
            try {
                $this->getMineChannel('snippet');
                $creatorChannelId = trim((string)($this->account['channel_id'] ?? ''));
            } catch (\Throwable $e) {
                // 无频道信息时跳过标记
            }
        }
        if ($creatorChannelId !== '') {
            YouTubeSchema::refreshCreatorCommentFlags(
                $this->accountId(),
                $localVideoId,
                $youtubeVideoId,
                $creatorChannelId
            );
        }
        return ['threads' => $threads, 'comments' => $comments, 'replies' => $replies];
    }

    /**
     * 回复评论 comments.insert
     * @return array<string,mixed> 新建的 comment 资源
     */
    public function replyToComment(string $parentYoutubeCommentId, string $text): array
    {
        $parentYoutubeCommentId = trim($parentYoutubeCommentId);
        $text = trim($text);
        if ($parentYoutubeCommentId === '' || $text === '') {
            throw new \InvalidArgumentException('parentId / text 不能为空');
        }
        $body = [
            'snippet' => [
                'parentId' => $parentYoutubeCommentId,
                'textOriginal' => $text,
            ],
        ];
        $res = $this->apiRequest(
            'POST',
            self::API_BASE . '/comments?' . http_build_query(['part' => 'snippet']),
            json_encode($body, JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json; charset=UTF-8']
        );
        $parent = DB::selectOne(
            'SELECT id, video_id, youtube_video_id, is_creator_comment FROM youtube_comments WHERE youtube_comment_id=? LIMIT 1',
            [$parentYoutubeCommentId]
        );
        YouTubeSchema::upsertCommentFromApi(
            $this->accountId(),
            (int)($this->account['owner_id'] ?? 0),
            (int)($parent['video_id'] ?? 0),
            (string)($parent['youtube_video_id'] ?? ''),
            $res,
            (int)($parent['id'] ?? 0),
            null
        );
        // 创作者回复后，标记非创作者顶层评论为已回复
        $creatorChannelId = trim((string)($this->account['channel_id'] ?? ''));
        $parentLocalId = (int)($parent['id'] ?? 0);
        if ($parentLocalId > 0 && empty($parent['is_creator_comment']) && $creatorChannelId !== '') {
            YouTubeSchema::ensureCommentCreatorColumns();
            DB::query(
                'UPDATE youtube_comments SET creator_replied=1, updated_at=CURRENT_TIMESTAMP WHERE id=? AND parent_id=0 AND is_creator_comment=0',
                [$parentLocalId]
            );
        }
        return $res;
    }

    /**
     * 执行评论回复任务并写回 youtube_comment_reply_tasks。
     * @return array<string,mixed>
     */
    public function runCommentReplyTask(int $taskId): array
    {
        $task = DB::selectOne('SELECT * FROM youtube_comment_reply_tasks WHERE id=?', [$taskId]);
        if (!$task) {
            throw new \InvalidArgumentException('回复任务不存在');
        }
        if ((int)($task['account_id'] ?? 0) !== $this->accountId()) {
            throw new \RuntimeException('任务与账号不匹配');
        }
        $parentId = trim((string)($task['parent_youtube_comment_id'] ?? ''));
        $text = (string)($task['reply_text'] ?? '');
        DB::query(
            "UPDATE youtube_comment_reply_tasks SET status='running', started_at=NOW(), error_message='', updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$taskId]
        );
        try {
            $req = [
                'snippet' => [
                    'parentId' => $parentId,
                    'textOriginal' => $text,
                ],
            ];
            $res = $this->replyToComment($parentId, $text);
            DB::query(
                "UPDATE youtube_comment_reply_tasks
                 SET status='completed', youtube_reply_comment_id=?, request_json=?, response_json=?,
                     finished_at=NOW(), updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [
                    (string)($res['id'] ?? ''),
                    json_encode($req, JSON_UNESCAPED_UNICODE),
                    json_encode($res, JSON_UNESCAPED_UNICODE),
                    $taskId,
                ]
            );
            return $res;
        } catch (\Throwable $e) {
            DB::query(
                "UPDATE youtube_comment_reply_tasks
                 SET status='failed', error_message=?, finished_at=NOW(), updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [mb_substr($e->getMessage(), 0, 1000), $taskId]
            );
            throw $e;
        }
    }

    /**
     * 删除评论：优先 comments.delete；失败则按频道主设为 rejected（隐藏）。
     * @return array{mode:string,youtubeCommentId:string}
     */
    public function deleteComment(string $youtubeCommentId): array
    {
        $youtubeCommentId = trim($youtubeCommentId);
        if ($youtubeCommentId === '') {
            throw new \InvalidArgumentException('youtubeCommentId 不能为空');
        }
        $mode = 'delete';
        try {
            $this->apiRequest(
                'DELETE',
                self::API_BASE . '/comments?' . http_build_query(['id' => $youtubeCommentId]),
                null,
                [],
                false,
                [204, 200]
            );
        } catch (\Throwable $e) {
            // 他人评论通常无法 delete，改用 moderationStatus=rejected
            $this->apiRequest(
                'POST',
                self::API_BASE . '/comments/setModerationStatus?' . http_build_query([
                    'id' => $youtubeCommentId,
                    'moderationStatus' => 'rejected',
                    'banAuthor' => 'false',
                ]),
                null,
                [],
                false,
                [204, 200]
            );
            $mode = 'rejected';
        }
        DB::query(
            "UPDATE youtube_comments
             SET moderation_status=?, text_display=CONCAT('[已删除] ', IFNULL(text_display,'')),
                 updated_at=CURRENT_TIMESTAMP
             WHERE youtube_comment_id=? AND account_id=?",
            [$mode === 'delete' ? 'deleted' : 'rejected', $youtubeCommentId, $this->accountId()]
        );
        if ($mode === 'delete') {
            DB::query(
                'DELETE FROM youtube_comments WHERE youtube_comment_id=? AND account_id=?',
                [$youtubeCommentId, $this->accountId()]
            );
        }
        return ['mode' => $mode, 'youtubeCommentId' => $youtubeCommentId];
    }

    /**
     * 发表顶层评论 commentThreads.insert（可选能力）。
     * @return array<string,mixed>
     */
    public function insertTopLevelComment(string $youtubeVideoId, string $text, ?string $channelId = null): array
    {
        $channelId = trim((string)($channelId ?? $this->account['channel_id'] ?? ''));
        if ($channelId === '') {
            $this->getMineChannel();
            $channelId = trim((string)($this->account['channel_id'] ?? ''));
        }
        $body = [
            'snippet' => [
                'channelId' => $channelId,
                'videoId' => $youtubeVideoId,
                'topLevelComment' => [
                    'snippet' => [
                        'textOriginal' => $text,
                    ],
                ],
            ],
        ];
        return $this->apiRequest(
            'POST',
            self::API_BASE . '/commentThreads?' . http_build_query(['part' => 'snippet']),
            json_encode($body, JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json; charset=UTF-8']
        );
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * YouTube 标题规则：最长 100；禁止 <>；不可为空。
     * @see https://developers.google.com/youtube/v3/docs/videos#snippet.title
     */
    public static function sanitizeVideoTitle(string $title, string $fallback = 'Untitled Video'): string
    {
        $t = trim($title);
        // 去掉控制字符与 YouTube 禁止的 <>
        $t = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t) ?? $t;
        $t = str_replace(['<', '>'], ['', ''], $t);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t);
        if ($t === '') {
            $t = trim($fallback);
            $t = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t) ?? $t;
            $t = str_replace(['<', '>'], ['', ''], $t);
            $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
            $t = trim($t);
        }
        if ($t === '') {
            $t = 'Untitled Video';
        }
        if (function_exists('mb_substr')) {
            $t = mb_substr($t, 0, 100);
        } else {
            $t = substr($t, 0, 100);
        }
        return trim($t) !== '' ? trim($t) : 'Untitled Video';
    }

    /** 描述最长约 5000；去掉控制字符与 <> */
    public static function sanitizeVideoDescription(string $description): string
    {
        $d = str_replace(["\r\n", "\r"], "\n", $description);
        $d = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $d) ?? $d;
        $d = str_replace(['<', '>'], ['', ''], $d);
        if (function_exists('mb_substr')) {
            return mb_substr($d, 0, 5000);
        }
        return substr($d, 0, 5000);
    }

    /** @param array<string,scalar|null> $query */
    private function apiGet(string $path, array $query): array
    {
        $url = self::API_BASE . $path . '?' . http_build_query($query);
        return $this->apiRequest('GET', $url);
    }

    /**
     * @param list<string> $extraHeaders
     * @param list<int> $okStatuses
     * @return array<string,mixed>
     */
    private function apiRequest(
        string $method,
        string $url,
        ?string $body = null,
        array $extraHeaders = [],
        bool $captureHeaders = false,
        array $okStatuses = [200, 201]
    ): array {
        $token = $this->ensureAccessToken();
        $headers = array_merge([
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $headerLines = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headerLines) {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headerLines[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);
        if ($body !== null && strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) {
            throw new \RuntimeException('YouTube API 网络错误: ' . $err);
        }
        $json = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $json = is_array($decoded) ? $decoded : ['_raw' => $raw];
        }
        if ($code === 401) {
            // 尝试刷新一次
            $this->refreshAccessToken();
            return $this->apiRequest($method, $url, $body, $extraHeaders, $captureHeaders, $okStatuses);
        }
        if (!in_array($code, $okStatuses, true)) {
            $msg = (string)($json['error']['message'] ?? ('HTTP ' . $code));
            if (!empty($json['error']['errors'][0]['reason'])) {
                $msg .= ' (' . $json['error']['errors'][0]['reason'] . ')';
            }
            throw new \RuntimeException('YouTube API 错误: ' . $msg);
        }
        if ($captureHeaders) {
            $json['_headers'] = $headerLines;
            $json['_http_code'] = $code;
        }
        return $json;
    }

    /** @param array<string,string> $fields */
    private function httpForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            throw new \RuntimeException('OAuth 请求失败: ' . $err);
        }
        $json = json_decode((string)$raw, true);
        return is_array($json) ? $json : [];
    }

    /**
     * 可恢复上传：整文件 PUT 到 session URL。
     * @return array<string,mixed>
     */
    private function resumableUpload(
        string $sessionUrl,
        string $filePath,
        string $mime,
        int $size,
        ?callable $onProgress = null
    ): array {
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('无法读取视频文件');
        }
        $ch = curl_init($sessionUrl);
        if ($ch === false) {
            fclose($fh);
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . $mime,
                'Content-Length: ' . $size,
                'Authorization: Bearer ' . $this->ensureAccessToken(),
            ],
            CURLOPT_TIMEOUT => 0,
            CURLOPT_NOPROGRESS => $onProgress === null,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($onProgress, $size) {
                if ($onProgress && $size > 0) {
                    $pct = (int)min(99, floor(($ulNow / $size) * 100));
                    $onProgress($pct);
                }
                return 0;
            },
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);
        if ($errno) {
            throw new \RuntimeException('视频上传失败: ' . $err);
        }
        $json = json_decode((string)$raw, true);
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            throw new \RuntimeException('视频上传失败 HTTP ' . $code . ': ' . mb_substr((string)$raw, 0, 500));
        }
        if ($onProgress) {
            $onProgress(100);
        }
        return $json;
    }

    private function markAccountError(string $message): void
    {
        try {
            DB::query(
                "UPDATE youtube_accounts SET status='error', last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [mb_substr($message, 0, 500), $this->accountId()]
            );
        } catch (\Throwable $e) {
        }
    }
}
