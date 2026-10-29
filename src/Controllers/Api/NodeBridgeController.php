<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountWorkBatchService;
use App\Services\AccountBanService;
use App\Services\AiLeadExecutionService;
use App\Services\AiLeadFilterService;
use App\Services\CommentReplyService;
use App\Services\DataScope;
use App\Services\PullCommentService;
use App\Services\AutoFollowService;
use App\Services\SecretStore;
use App\Services\WarmCommentService;

/**
 * 供客服端 Node（无本机 MySQL）调用的自动化桥接接口。
 * 比特 CDP 仍在本机 Node；账号/设置/会话等一律经此读写中心库。
 */
class NodeBridgeController extends Controller
{
    /** 自动化所需账号包：账号 + 代理明文密码 + 登录密文解密 */
    public function accountBundle(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $row = DB::selectOne(
            "SELECT a.*,
                    p.protocol AS proxy_protocol, p.host AS proxy_host, p.port AS proxy_port,
                    p.username AS proxy_username, p.password AS proxy_password,
                    p.password_encrypted AS proxy_password_encrypted, p.status AS proxy_status,
                    p.country AS proxy_country
             FROM accounts a
             LEFT JOIN proxies p ON p.id = a.proxy_id
             WHERE a.id=?",
            [(int)$id]
        );
        if ($err = $this->assertNodeAccountRow($row)) {
            return $err;
        }

        $proxyPassword = (string)($row['proxy_password'] ?? '');
        if ($proxyPassword === '' && !empty($row['proxy_password_encrypted'])) {
            $proxyPassword = SecretStore::decrypt((string)$row['proxy_password_encrypted']);
        }

        $secrets = DB::selectOne(
            'SELECT password_encrypted, totp_secret_encrypted FROM account_secrets WHERE account_id=?',
            [(int)$id]
        ) ?: [];
        $session = DB::selectOne(
            'SELECT cookie_encrypted, fingerprint FROM account_sessions WHERE account_id=?',
            [(int)$id]
        ) ?: [];

        $password = '';
        $totp = '';
        $cookie = '';
        $passwordDecryptFailed = false;
        if (!empty($secrets['password_encrypted'])) {
            $password = SecretStore::decrypt((string)$secrets['password_encrypted']);
            if ($password === '') {
                $passwordDecryptFailed = true;
            }
        }
        if (!empty($secrets['totp_secret_encrypted'])) {
            $totp = SecretStore::decrypt((string)$secrets['totp_secret_encrypted']);
        }
        if (!empty($session['cookie_encrypted'])) {
            $cookie = SecretStore::decrypt((string)$session['cookie_encrypted']);
        }

        $typedIds = \App\Services\AccountBrowserEnvService::resolveTypedProfileIds((int)$row['id']);
        $envLists = \App\Services\AccountBrowserEnvService::listProfileIdsByBackend((int)$row['id']);
        $loginUser = AccountWorkBatchService::resolveLoginUsername(
            (int)$row['id'],
            (string)($row['username'] ?? '')
        );

        return $this->success([
            'id' => (int)$row['id'],
            'username' => $row['username'] ?? '',
            'login_username' => (string)($loginUser['login_username'] ?? ($row['username'] ?? '')),
            'loginUsername' => (string)($loginUser['login_username'] ?? ($row['username'] ?? '')),
            'pending_username' => (string)($loginUser['pending_username'] ?? ''),
            'pendingUsername' => (string)($loginUser['pending_username'] ?? ''),
            'nickname' => $row['nickname'] ?? '',
            'profile_bio' => $row['profile_bio'] ?? '',
            'profile_avatar' => $row['profile_avatar'] ?? '',
            'profileBio' => $row['profile_bio'] ?? '',
            'profileAvatar' => $row['profile_avatar'] ?? '',
            'country' => $row['country'] ?? '',
            'group_id' => $row['group_id'] ?? null,
            'proxy_id' => $row['proxy_id'] ?? null,
            'browser_type' => $row['browser_type'] ?? 'bit',
            'browser_profile_id' => $row['browser_profile_id'] ?? '',
            'browser_backend' => $row['browser_backend'] ?? 'bit',
            'tkswarm_browser_profile_id' => $typedIds['tkswarm'] !== ''
                ? $typedIds['tkswarm']
                : ($row['tkswarm_browser_profile_id'] ?? ''),
            'linux_browser_profile_id' => $typedIds['linux'] !== ''
                ? $typedIds['linux']
                : ($row['linux_browser_profile_id'] ?? ''),
            'bit_browser_profile_id' => $typedIds['bit'],
            'env_count' => $typedIds['env_count'],
            'env_profile_ids' => $envLists,
            'owner_id' => (int)($row['owner_id'] ?? 0),
            'login_status' => $row['login_status'] ?? 'offline',
            'chat_status' => $row['chat_status'] ?? 'offline',
            'dm_channel' => $row['dm_channel'] ?? 'auto',
            'has_session' => !empty($row['has_session']) || $cookie !== '',
            'capcut_authorized' => !empty($row['capcut_authorized']),
            'account_status' => $row['account_status'] ?? 'normal',
            'fingerprint' => $row['fingerprint'] ?? '',
            'notes' => $row['notes'] ?? '',
            'proxy_protocol' => $row['proxy_protocol'] ?? null,
            'proxy_host' => $row['proxy_host'] ?? null,
            'proxy_port' => $row['proxy_port'] ?? null,
            'proxy_username' => $row['proxy_username'] ?? null,
            'proxy_password' => $proxyPassword,
            'proxy_status' => $row['proxy_status'] ?? null,
            'proxy_country' => $row['proxy_country'] ?? null,
            'password' => $password,
            'totp_secret' => $totp,
            'cookie' => $cookie,
            'session_fingerprint' => $session['fingerprint'] ?? '',
            'has_password' => $password !== '',
            'password_decrypt_failed' => $passwordDecryptFailed,
        ]);
    }

    public function browserBinding(Request $request, string $id): Response
    {
        try {
            DataScope::ensureColumns();
            \App\Services\AccountBrowserEnvService::ensureSchema();
            $row = DB::selectOne('SELECT * FROM accounts WHERE id=?', [(int)$id]);
            if ($err = $this->assertNodeAccountRow($row)) {
                return $err;
            }
            $profileId = $request->stringInput('profileId', $request->stringInput('browserProfileId', ''));
            $clear = $request->boolInput('clear', false);
            if ($clear || $profileId === '') {
                if (!DataScope::canEditBrowserEnvs()) {
                    return $this->error('当前角色无权操作浏览器环境', 403);
                }
                // clear=true 且带 profileId：只解绑该环境；否则清空该账号全部环境
                $only = $clear && $profileId !== '' ? $profileId : null;
                \App\Services\AccountBrowserEnvService::unbind((int)$id, $only);
                $envs = \App\Services\AccountBrowserEnvService::listByAccount((int)$id);
                return $this->success([
                    'id' => (int)$id,
                    'browser_profile_id' => (string)($envs[0]['browser_profile_id'] ?? ''),
                    'envs' => $envs,
                ], '已解除浏览器绑定');
            }
            if (!DataScope::canCreateBrowserEnvs()) {
                return $this->error('当前角色无权创建/绑定浏览器环境', 403);
            }
            $existing = DB::selectOne(
                'SELECT e.account_id, e.owner_user_id,
                        a.id AS account_pk, a.owner_id, a.company_id
                 FROM account_browser_envs e
                 INNER JOIN accounts a ON a.id = e.account_id
                 WHERE e.browser_profile_id = ? LIMIT 1',
                [$profileId]
            );
            if ($existing) {
                // 环境本机共用：能否操作看「绑定账号是否在可见范围」，不按环境创建者/额度归属拦截
                $accRow = [
                    'id' => (int)($existing['account_pk'] ?? $existing['account_id'] ?? 0),
                    'owner_id' => (int)($existing['owner_id'] ?? 0),
                    'company_id' => (int)($existing['company_id'] ?? 0),
                ];
                if (!DataScope::isAccountRowVisible($accRow)) {
                    return $this->error('无权操作该浏览器环境（账号不在可见范围）', 403);
                }
            } elseif (!DataScope::isSuper() && !DataScope::isOpsAdmin()
                && !DataScope::isEnterpriseCustomer() && !DataScope::isOperator()) {
                return $this->error('无权绑定未分配环境', 403);
            }
            $asPrimary = $request->boolInput('asPrimary', $request->boolInput('as_primary', false));
            $backend = \App\Services\AccountBrowserEnvService::normalizeBackend(
                $request->stringInput('browserBackend', $request->stringInput('browser_backend', 'bit'))
            );
            $ownerOverrideRaw = $request->stringInput(
                'ownerUserId',
                $request->stringInput('owner_user_id', $request->stringInput('ownerId', ''))
            );
            $ownerOverride = $ownerOverrideRaw === '' ? null : (int)$ownerOverrideRaw;
            if ($ownerOverride !== null && $ownerOverride > 0 && !\App\Services\DataScope::isLoginSuper()) {
                return $this->error('仅超级管理员可为环境指定管理用户归属', 403);
            }
            $env = \App\Services\AccountBrowserEnvService::bind(
                (int)$id,
                $profileId,
                $asPrimary,
                '',
                $backend,
                ($ownerOverride !== null && $ownerOverride > 0) ? $ownerOverride : null
            );
            \App\Services\AccountBrowserEnvService::backfillFromAccounts();
            $verify = DB::selectOne(
                'SELECT id FROM account_browser_envs WHERE account_id=? AND browser_profile_id=? LIMIT 1',
                [(int)$id, $profileId]
            );
            if (!$verify) {
                return $this->error('环境绑定写入数据库失败', 500);
            }
            $envs = \App\Services\AccountBrowserEnvService::listByAccount((int)$id);
            $acc = DB::selectOne(
                'SELECT browser_profile_id, browser_backend, tkswarm_browser_profile_id, linux_browser_profile_id FROM accounts WHERE id=?',
                [(int)$id]
            );
            return $this->success([
                'id' => (int)$id,
                'browser_profile_id' => (string)($acc['browser_profile_id'] ?? $env['browser_profile_id'] ?? $profileId),
                'browser_backend' => (string)($acc['browser_backend'] ?? $backend),
                'tkswarm_browser_profile_id' => (string)($acc['tkswarm_browser_profile_id'] ?? ''),
                'linux_browser_profile_id' => (string)($acc['linux_browser_profile_id'] ?? ''),
                'env' => $env,
                'envs' => $envs,
            ], '浏览器环境已绑定');
        } catch (\Throwable $e) {
            $msg = $e->getMessage() !== '' ? $e->getMessage() : '浏览器绑定失败';
            $code = str_contains($msg, '已绑定') ? 409 : 400;
            if (str_contains($msg, 'Array to string')) {
                $msg = '浏览器绑定参数异常（Array to string），请重试或检查 profileId/browserBackend';
            }
            return $this->error($msg, $code);
        }
    }

    public function fingerprint(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertNodeAccountRow($row)) {
            return $err;
        }
        $fp = trim((string)$request->input('fingerprint', ''));
        DB::query(
            "UPDATE accounts SET fingerprint=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [mb_substr($fp, 0, 255), (int)$id]
        );
        if ($fp !== '') {
            DB::query(
                "INSERT INTO account_sessions(account_id, cookie_encrypted, fingerprint, updated_at)
                 VALUES (?,'',?,CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE fingerprint=VALUES(fingerprint), updated_at=CURRENT_TIMESTAMP",
                [(int)$id, mb_substr($fp, 0, 2000)]
            );
        }
        return $this->success(['id' => (int)$id], '指纹已保存');
    }

    public function tiktokSync(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertNodeAccountRow($row)) {
            return $err;
        }
        $aid = (int)$id;
        $profile = $request->input('profile');
        $videos = $request->input('videos');
        $stats = $request->input('stats');
        $error = trim((string)$request->input('error', ''));

        $this->ensureTikTokTables();

        if ($error !== '') {
            DB::query(
                "INSERT INTO tiktok_profiles(account_id, sync_status, sync_error, last_synced_at)
                 VALUES (?,'error',?,CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE sync_status='error', sync_error=VALUES(sync_error), last_synced_at=CURRENT_TIMESTAMP",
                [$aid, mb_substr($error, 0, 1000)]
            );
            return $this->success(['id' => $aid], '同步错误已记录');
        }

        if (is_array($profile)) {
            DB::query(
                "INSERT INTO tiktok_profiles(
                    account_id, handle, display_name, bio, avatar_url, region,
                    followers_count, following_count, likes_count, videos_count,
                    sync_status, sync_error, last_synced_at
                 ) VALUES (?,?,?,?,?,?,?,?,?,?, 'ok', '', CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                    handle=VALUES(handle), display_name=VALUES(display_name), bio=VALUES(bio),
                    avatar_url=VALUES(avatar_url), region=VALUES(region),
                    followers_count=VALUES(followers_count), following_count=VALUES(following_count),
                    likes_count=VALUES(likes_count), videos_count=VALUES(videos_count),
                    sync_status='ok', sync_error='', last_synced_at=CURRENT_TIMESTAMP",
                [
                    $aid,
                    (string)($profile['handle'] ?? $profile['username'] ?? ''),
                    (string)($profile['displayName'] ?? $profile['display_name'] ?? ''),
                    (string)($profile['bio'] ?? ''),
                    (string)($profile['avatarUrl'] ?? $profile['avatar_url'] ?? ''),
                    (string)($profile['region'] ?? ''),
                    (int)($profile['followers'] ?? $profile['followers_count'] ?? 0),
                    (int)($profile['following'] ?? $profile['following_count'] ?? 0),
                    (int)($profile['likes'] ?? $profile['likes_count'] ?? 0),
                    (int)($profile['videos'] ?? $profile['videos_count'] ?? 0),
                ]
            );
            $nick = trim((string)($profile['displayName'] ?? $profile['display_name'] ?? ''));
            if ($nick !== '') {
                DB::query('UPDATE accounts SET nickname=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$nick, $aid]);
            }
        }

        if (is_array($stats)) {
            DB::query(
                'INSERT INTO tiktok_stat_snapshots(account_id, followers_count, following_count, likes_count, videos_count)
                 VALUES (?,?,?,?,?)',
                [
                    $aid,
                    (int)($stats['followers'] ?? $stats['followers_count'] ?? 0),
                    (int)($stats['following'] ?? $stats['following_count'] ?? 0),
                    (int)($stats['likes'] ?? $stats['likes_count'] ?? 0),
                    (int)($stats['videos'] ?? $stats['videos_count'] ?? 0),
                ]
            );
        }

        if (is_array($videos)) {
            foreach ($videos as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $vid = (string)($v['videoId'] ?? $v['video_id'] ?? '');
                if ($vid === '') {
                    continue;
                }
                DB::query(
                    "INSERT INTO tiktok_videos(account_id, video_id, video_url, description, thumbnail_url, last_synced_at)
                     VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE video_url=VALUES(video_url), description=VALUES(description),
                       thumbnail_url=VALUES(thumbnail_url), last_synced_at=CURRENT_TIMESTAMP",
                    [
                        $aid,
                        $vid,
                        (string)($v['videoUrl'] ?? $v['video_url'] ?? ''),
                        (string)($v['description'] ?? ''),
                        (string)($v['thumbnailUrl'] ?? $v['thumbnail_url'] ?? ''),
                    ]
                );
            }
        }

        return $this->success(['id' => $aid], 'TikTok 资料已同步入库');
    }

    /**
     * Studio 拉取数据入库：更新总览 + 删除该账号原 28 天日数据后重写
     */
    public function tiktokAnalyticsSync(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertNodeAccountRow($row)) {
            return $err;
        }
        $aid = (int)$id;
        $this->ensureTikTokTables();

        $profile = $request->input('profile');
        $period = $request->input('period');
        $daily = $request->input('daily');
        $tabs = $request->input('tabs');
        $content = $request->input('content');
        $apiRaw = $request->input('api');
        $meta = $request->input('meta');
        $error = trim((string)$request->input('error', ''));

        if ($error !== '') {
            DB::query(
                "INSERT INTO tiktok_profiles(account_id, sync_status, sync_error, last_synced_at)
                 VALUES (?,'error',?,CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE sync_status='error', sync_error=VALUES(sync_error), last_synced_at=CURRENT_TIMESTAMP",
                [$aid, mb_substr($error, 0, 1000)]
            );
            $metaArr = is_array($meta) ? $meta : [];
            DB::query(
                "INSERT INTO tiktok_studio_sync(account_id, page_url, tab_count, api_count, status, message, meta_json)
                 VALUES (?,?,?,?,'error',?,?)
                 ON DUPLICATE KEY UPDATE page_url=VALUES(page_url), tab_count=VALUES(tab_count), api_count=VALUES(api_count),
                    status='error', message=VALUES(message), meta_json=VALUES(meta_json), captured_at=CURRENT_TIMESTAMP",
                [
                    $aid,
                    mb_substr((string)($metaArr['pageUrl'] ?? ''), 0, 2000),
                    (int)($metaArr['tabCount'] ?? 0),
                    (int)($metaArr['apiCount'] ?? 0),
                    mb_substr($error, 0, 2000),
                    json_encode($metaArr, JSON_UNESCAPED_UNICODE) ?: '',
                ]
            );
            return $this->success(['id' => $aid], '拉取错误已记录');
        }

        if (is_array($profile)) {
            DB::query(
                "INSERT INTO tiktok_profiles(
                    account_id, handle, display_name, bio, avatar_url, region,
                    followers_count, following_count, likes_count, videos_count,
                    comments_count, shares_count,
                    period_video_views, period_profile_views, period_likes, period_comments, period_shares, period_est_rewards,
                    sync_status, sync_error, last_synced_at
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'ok', '', CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                    handle=VALUES(handle),
                    display_name=IF(VALUES(display_name)='', display_name, VALUES(display_name)),
                    followers_count=VALUES(followers_count), following_count=VALUES(following_count),
                    likes_count=VALUES(likes_count), videos_count=VALUES(videos_count),
                    comments_count=VALUES(comments_count), shares_count=VALUES(shares_count),
                    period_video_views=VALUES(period_video_views), period_profile_views=VALUES(period_profile_views),
                    period_likes=VALUES(period_likes), period_comments=VALUES(period_comments),
                    period_shares=VALUES(period_shares), period_est_rewards=VALUES(period_est_rewards),
                    sync_status='ok', sync_error='', last_synced_at=CURRENT_TIMESTAMP",
                [
                    $aid,
                    (string)($profile['handle'] ?? $profile['username'] ?? ''),
                    (string)($profile['displayName'] ?? $profile['display_name'] ?? ''),
                    (string)($profile['bio'] ?? ''),
                    (string)($profile['avatarUrl'] ?? $profile['avatar_url'] ?? ''),
                    (string)($profile['region'] ?? ''),
                    (int)($profile['followers'] ?? $profile['followers_count'] ?? 0),
                    (int)($profile['following'] ?? $profile['following_count'] ?? 0),
                    (int)($profile['likes'] ?? $profile['likes_count'] ?? 0),
                    (int)($profile['videos'] ?? $profile['videos_count'] ?? 0),
                    (int)($profile['comments'] ?? $profile['comments_count'] ?? $profile['periodComments'] ?? 0),
                    (int)($profile['shares'] ?? $profile['shares_count'] ?? $profile['periodShares'] ?? 0),
                    (int)($profile['periodVideoViews'] ?? $profile['period_video_views'] ?? 0),
                    (int)($profile['periodProfileViews'] ?? $profile['period_profile_views'] ?? 0),
                    (int)($profile['periodLikes'] ?? $profile['period_likes'] ?? 0),
                    (int)($profile['periodComments'] ?? $profile['period_comments'] ?? 0),
                    (int)($profile['periodShares'] ?? $profile['period_shares'] ?? 0),
                    (float)($profile['periodEstRewards'] ?? $profile['period_est_rewards'] ?? 0),
                ]
            );

            // 兼容旧快照表
            DB::query(
                'INSERT INTO tiktok_stat_snapshots(account_id, followers_count, following_count, likes_count, videos_count)
                 VALUES (?,?,?,?,?)',
                [
                    $aid,
                    (int)($profile['followers'] ?? 0),
                    (int)($profile['following'] ?? 0),
                    (int)($profile['likes'] ?? 0),
                    (int)($profile['videos'] ?? 0),
                ]
            );
        }

        // 每次拉取：删除该账号全部 28 天日数据后重写
        DB::query('DELETE FROM tiktok_analytics_28d WHERE account_id=?', [$aid]);
        if (is_array($daily)) {
            foreach ($daily as $day) {
                if (!is_array($day)) {
                    continue;
                }
                $metricDate = (string)($day['metricDate'] ?? $day['metric_date'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $metricDate)) {
                    continue;
                }
                DB::query(
                    'INSERT INTO tiktok_analytics_28d(
                        account_id, metric_date, video_views, profile_views,
                        likes_count, comments_count, shares_count, est_rewards
                     ) VALUES (?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        video_views=VALUES(video_views), profile_views=VALUES(profile_views),
                        likes_count=VALUES(likes_count), comments_count=VALUES(comments_count),
                        shares_count=VALUES(shares_count), est_rewards=VALUES(est_rewards)',
                    [
                        $aid,
                        $metricDate,
                        (int)($day['videoViews'] ?? $day['video_views'] ?? 0),
                        (int)($day['profileViews'] ?? $day['profile_views'] ?? 0),
                        (int)($day['likes'] ?? $day['likes_count'] ?? 0),
                        (int)($day['comments'] ?? $day['comments_count'] ?? 0),
                        (int)($day['shares'] ?? $day['shares_count'] ?? 0),
                        (float)($day['estRewards'] ?? $day['est_rewards'] ?? 0),
                    ]
                );
            }
        }

        // Studio 四个页签：整表替换（该账号本次抓到的页签）
        if (is_array($tabs) && $tabs) {
            DB::query('DELETE FROM tiktok_studio_tabs WHERE account_id=?', [$aid]);
            DB::query('DELETE FROM tiktok_studio_metrics WHERE account_id=?', [$aid]);
            foreach ($tabs as $tab) {
                if (!is_array($tab)) {
                    continue;
                }
                $tabKey = mb_substr(trim((string)($tab['key'] ?? '')), 0, 64);
                if ($tabKey === '') {
                    continue;
                }
                $metrics = is_array($tab['metrics'] ?? null) ? $tab['metrics'] : [];
                DB::query(
                    'INSERT INTO tiktok_studio_tabs(account_id, tab_key, tab_label, page_url, metric_count, raw_text)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE tab_label=VALUES(tab_label), page_url=VALUES(page_url),
                        metric_count=VALUES(metric_count), raw_text=VALUES(raw_text), captured_at=CURRENT_TIMESTAMP',
                    [
                        $aid,
                        $tabKey,
                        mb_substr((string)($tab['label'] ?? $tabKey), 0, 191),
                        mb_substr((string)($tab['url'] ?? ''), 0, 2000),
                        (int)count($metrics),
                        mb_substr((string)($tab['text'] ?? ''), 0, 60000),
                    ]
                );
                foreach ($metrics as $metric) {
                    if (!is_array($metric)) {
                        continue;
                    }
                    $mKey = mb_substr(trim((string)($metric['key'] ?? '')), 0, 96);
                    if ($mKey === '') {
                        continue;
                    }
                    DB::query(
                        'INSERT INTO tiktok_studio_metrics(account_id, tab_key, metric_key, metric_label, value_num, value_text)
                         VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE metric_label=VALUES(metric_label), value_num=VALUES(value_num),
                            value_text=VALUES(value_text), captured_at=CURRENT_TIMESTAMP',
                        [
                            $aid,
                            $tabKey,
                            $mKey,
                            mb_substr((string)($metric['label'] ?? $mKey), 0, 191),
                            (int)round((float)($metric['value'] ?? 0)),
                            mb_substr((string)($metric['text'] ?? ''), 0, 64),
                        ]
                    );
                }
            }
        }

        // Content 页签逐条内容
        if (is_array($content)) {
            DB::query('DELETE FROM tiktok_studio_content WHERE account_id=?', [$aid]);
            $rowNo = 0;
            foreach ($content as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowNo += 1;
                DB::query(
                    'INSERT INTO tiktok_studio_content(
                        account_id, row_no, title, views_num, likes_num, comments_num, shares_num, posted_at
                     ) VALUES (?,?,?,?,?,?,?,?)',
                    [
                        $aid,
                        $rowNo,
                        mb_substr((string)($row['title'] ?? ''), 0, 512),
                        (int)($row['views'] ?? 0),
                        (int)($row['likes'] ?? 0),
                        (int)($row['comments'] ?? 0),
                        (int)($row['shares'] ?? 0),
                        mb_substr((string)($row['postedAt'] ?? $row['posted_at'] ?? ''), 0, 64),
                    ]
                );
            }
        }

        // 网络原始 JSON：只保留最近 20 条
        if (is_array($apiRaw) && $apiRaw) {
            DB::query('DELETE FROM tiktok_studio_api WHERE account_id=?', [$aid]);
            foreach (array_slice($apiRaw, 0, 20) as $hit) {
                if (!is_array($hit)) {
                    continue;
                }
                DB::query(
                    'INSERT INTO tiktok_studio_api(account_id, url, payload) VALUES (?,?,?)',
                    [
                        $aid,
                        mb_substr((string)($hit['url'] ?? ''), 0, 2000),
                        mb_substr((string)($hit['body'] ?? ''), 0, 200000),
                    ]
                );
            }
        }

        // 本次拉取现场
        if (is_array($meta) || $error === '') {
            $metaArr = is_array($meta) ? $meta : [];
            DB::query(
                "INSERT INTO tiktok_studio_sync(account_id, page_url, tab_count, api_count, status, message, meta_json)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE page_url=VALUES(page_url), tab_count=VALUES(tab_count), api_count=VALUES(api_count),
                    status=VALUES(status), message=VALUES(message), meta_json=VALUES(meta_json), captured_at=CURRENT_TIMESTAMP",
                [
                    $aid,
                    mb_substr((string)($metaArr['pageUrl'] ?? ''), 0, 2000),
                    (int)($metaArr['tabCount'] ?? 0),
                    (int)($metaArr['apiCount'] ?? 0),
                    $error !== '' ? 'error' : 'ok',
                    mb_substr($error !== '' ? $error : (string)($request->input('message', '')), 0, 2000),
                    json_encode($metaArr, JSON_UNESCAPED_UNICODE) ?: '',
                ]
            );
        }

        $cnt = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM tiktok_analytics_28d WHERE account_id=?', [$aid])['c'] ?? 0);
        $metricCnt = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM tiktok_studio_metrics WHERE account_id=?', [$aid])['c'] ?? 0);
        return $this->success(['id' => $aid, 'dailyCount' => $cnt, 'metricCount' => $metricCnt], 'Studio 数据已替换入库');
    }

    /** Node 抓到的 Studio 系统通知入库（按时间+内容排重），命中违规词则触发 AI 判定 */
    public function notificationsSync(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertNodeAccountRow($row)) {
            return $err;
        }
        $items = $request->input('items', []);
        if (!is_array($items)) {
            $items = [];
        }
        $out = AccountBanService::ingest((int)$id, $items, [
            'source' => (string)$request->input('source', 'studio'),
            'pageUrl' => (string)$request->input('pageUrl', ''),
        ]);
        return $this->success($out, '系统通知已入库');
    }

    /** 计划任务：到解封时间自动恢复账号状态；顺带补跑「有未判定违规通知」的账号 AI 判定 */
    public function maintenanceRestoreBans(Request $request): Response
    {
        $restored = AccountBanService::restoreExpired();
        $assessed = [];
        $limit = (int)$request->input('assessLimit', 3);
        if ($limit > 0) {
            foreach (AccountBanService::pendingAssessAccountIds($limit) as $aid) {
                $r = AccountBanService::assess($aid);
                $assessed[] = [
                    'accountId' => $aid,
                    'is_banned' => (bool)($r['is_banned'] ?? false),
                    'error' => $r['error'] ?? null,
                ];
            }
        }
        return $this->success([
            'restored' => $restored,
            'assessed' => $assessed,
        ], $restored > 0 ? "已自动解封 {$restored} 个账号" : '没有到期封禁');
    }

    public function qrComplete(Request $request): Response
    {
        DataScope::ensureColumns();
        $username = trim((string)$request->input('username', ''));
        $profileId = trim((string)$request->input('profileId', $request->input('browserProfileId', '')));
        $cookie = trim((string)$request->input('cookie', ''));
        $accountId = (int)$request->input('accountId', 0);
        $groupId = (int)$request->input('groupId', 0) ?: null;
        $proxyId = (int)$request->input('proxyId', 0) ?: null;
        if ($username === '' && $accountId < 1) {
            return $this->error('缺少 username 或 accountId', 422);
        }

        $row = null;
        if ($accountId > 0) {
            $row = DB::selectOne('SELECT * FROM accounts WHERE id=?', [$accountId]);
            if ($err = $this->assertNodeAccountRow($row)) {
                return $err;
            }
        } elseif ($username !== '') {
            $row = DB::selectOne('SELECT * FROM accounts WHERE username=?', [$username]);
            if ($row && ($err = $this->assertNodeAccountRow($row))) {
                return $err;
            }
        }

        if ($row) {
            $aid = (int)$row['id'];
            DB::query(
                "UPDATE accounts SET login_status='online', has_session=?, browser_profile_id=COALESCE(NULLIF(?,''), browser_profile_id),
                  group_id=COALESCE(?, group_id), proxy_id=COALESCE(?, proxy_id), updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$cookie !== '' ? 1 : (int)$row['has_session'], $profileId, $groupId, $proxyId, $aid]
            );
        } else {
            $insert = [
                'username' => $username,
                'group_id' => $groupId,
                'proxy_id' => $proxyId,
                'browser_profile_id' => $profileId,
                'has_session' => $cookie !== '' ? 1 : 0,
            ];
            DataScope::applyAccountInsertBindings($insert);
            DB::query(
                "INSERT INTO accounts(username, group_id, proxy_id, browser_type, browser_profile_id, login_status, chat_status, enabled, has_session, owner_id, company_id, industry_id)
                 VALUES (?,?,?,'bit',?,'online','offline',1,?,?,?,?)",
                [
                    $insert['username'],
                    $insert['group_id'],
                    $insert['proxy_id'],
                    $insert['browser_profile_id'],
                    $insert['has_session'],
                    $insert['owner_id'],
                    $insert['company_id'] ?? null,
                    $insert['industry_id'] ?? null,
                ]
            );
            $aid = (int)DB::getPdo()->lastInsertId();
        }

        if ($cookie !== '') {
            DB::query(
                'INSERT INTO account_sessions(account_id, cookie_encrypted, updated_at) VALUES (?,?,CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE cookie_encrypted=VALUES(cookie_encrypted), updated_at=CURRENT_TIMESTAMP',
                [$aid, SecretStore::encrypt($cookie)]
            );
            DB::query('UPDATE accounts SET has_session=1 WHERE id=?', [$aid]);
        }

        return $this->success(['id' => $aid, 'username' => $username ?: ($row['username'] ?? '')], '扫码登录结果已保存');
    }

    public function monitorResult(Request $request): Response
    {
        $payload = $request->input('result', $request->all());
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        DB::query(
            "CREATE TABLE IF NOT EXISTS account_monitor_config (
              id INT PRIMARY KEY DEFAULT 1,
              enabled TINYINT NOT NULL DEFAULT 0,
              interval_minutes INT NOT NULL DEFAULT 60,
              sync_profile TINYINT NOT NULL DEFAULT 1,
              sync_videos TINYINT NOT NULL DEFAULT 0,
              last_run_at DATETIME NULL,
              last_result_json MEDIUMTEXT NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        DB::query(
            "INSERT INTO account_monitor_config(id, last_run_at, last_result_json) VALUES (1, CURRENT_TIMESTAMP, ?)
             ON DUPLICATE KEY UPDATE last_run_at=CURRENT_TIMESTAMP, last_result_json=VALUES(last_result_json)",
            [$json]
        );
        return $this->success(null, '监控结果已保存');
    }

    public function dmIngest(Request $request): Response
    {
        DataScope::ensureColumns();
        $accountId = (int)$request->input('accountId', 0);
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
        if ($err = $this->assertNodeAccountRow($account)) {
            return $err;
        }
        $friends = $request->input('friends', []);
        $messages = $request->input('messages', []);
        $lastError = (string)$request->input('lastError', '');
        $ownerId = $this->writeOwnerId();

        DB::query(
            "INSERT INTO chat_connections(account_id, status, sync_messages, last_error, last_synced_at, updated_at)
             VALUES (?, 'offline', 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE last_error=VALUES(last_error), last_synced_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP",
            [$accountId, $lastError]
        );

        $upsertedFriends = 0;
        if (is_array($friends)) {
            foreach ($friends as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $uid = (string)($f['friendUid'] ?? $f['friend_uid'] ?? $f['uid'] ?? '');
                $uname = (string)($f['username'] ?? '');
                if ($uid === '' && $uname === '') {
                    continue;
                }
                $existing = null;
                if ($uid !== '') {
                    $existing = DB::selectOne('SELECT id FROM chat_users WHERE account_id=? AND friend_uid=?', [$accountId, $uid]);
                }
                if (!$existing && $uname !== '') {
                    $existing = DB::selectOne('SELECT id FROM chat_users WHERE account_id=? AND username=?', [$accountId, $uname]);
                }
                if ($existing) {
                    DB::query(
                        "UPDATE chat_users SET friend_uid=COALESCE(NULLIF(?,''), friend_uid), username=COALESCE(NULLIF(?,''), username),
                          nickname=COALESCE(NULLIF(?,''), nickname), avatar_url=COALESCE(NULLIF(?,''), avatar_url),
                          relation=COALESCE(NULLIF(?,''), relation), last_message=COALESCE(NULLIF(?,''), last_message),
                          last_direction=COALESCE(NULLIF(?,''), last_direction), updated_at=CURRENT_TIMESTAMP WHERE id=?",
                        [
                            $uid, $uname,
                            (string)($f['nickname'] ?? ''),
                            (string)($f['avatarUrl'] ?? $f['avatar_url'] ?? ''),
                            (string)($f['relation'] ?? ''),
                            (string)($f['lastMessage'] ?? $f['last_message'] ?? ''),
                            (string)($f['lastDirection'] ?? $f['last_direction'] ?? ''),
                            (int)$existing['id'],
                        ]
                    );
                } else {
                    DB::query(
                        "INSERT INTO chat_users(account_id, user_type, friend_uid, username, nickname, avatar_url, relation, unread_count, last_message, last_direction, is_new)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                        [
                            $accountId,
                            match ((string)($f['relation'] ?? 'stranger')) {
                                'follower' => 'follower',
                                'following' => 'following',
                                'mutual' => 'mutual',
                                'blocked' => 'blocked',
                                default => 'dm',
                            },
                            $uid, $uname,
                            (string)($f['nickname'] ?? ''),
                            (string)($f['avatarUrl'] ?? $f['avatar_url'] ?? ''),
                            (string)($f['relation'] ?? 'stranger'),
                            (int)($f['unreadCount'] ?? $f['unread_count'] ?? 0),
                            (string)($f['lastMessage'] ?? $f['last_message'] ?? ''),
                            (string)($f['lastDirection'] ?? $f['last_direction'] ?? ''),
                            !empty($f['isNew'] ?? $f['is_new']) ? 1 : 0,
                        ]
                    );
                }
                $upsertedFriends++;
            }
        }

        $insertedMsgs = 0;
        if (is_array($messages)) {
            foreach ($messages as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $ext = (string)($m['externalId'] ?? $m['external_id'] ?? '');
                $friendId = (int)($m['friendId'] ?? $m['friend_id'] ?? 0);
                if ($friendId < 1) {
                    $fuid = (string)($m['friendUid'] ?? $m['friend_uid'] ?? '');
                    $funame = (string)($m['username'] ?? '');
                    $found = null;
                    if ($fuid !== '') {
                        $found = DB::selectOne('SELECT id FROM chat_users WHERE account_id=? AND friend_uid=?', [$accountId, $fuid]);
                    }
                    if (!$found && $funame !== '') {
                        $found = DB::selectOne('SELECT id FROM chat_users WHERE account_id=? AND username=?', [$accountId, $funame]);
                    }
                    $friendId = $found ? (int)$found['id'] : 0;
                }
                $content = (string)($m['content'] ?? '');
                if ($content === '' || $friendId < 1) {
                    continue;
                }
                if ($ext !== '') {
                    $dup = DB::selectOne(
                        'SELECT id FROM chat_records WHERE account_id=? AND external_id=?',
                        [$accountId, $ext]
                    );
                    if ($dup) {
                        continue;
                    }
                }
                DB::query(
                    "INSERT INTO chat_records(account_id, user_id, direction, msg_type, content, translated, status, external_id)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [
                        $accountId,
                        $friendId,
                        (string)($m['direction'] ?? 'in'),
                        (string)($m['msgType'] ?? $m['msg_type'] ?? 'text'),
                        $content,
                        (string)($m['translated'] ?? ''),
                        (string)($m['status'] ?? 'sent'),
                        $ext,
                    ]
                );
                $insertedMsgs++;
            }
        }

        return $this->success([
            'friends' => $upsertedFriends,
            'messages' => $insertedMsgs,
        ], '私信同步结果已入库');
    }

    public function accountByProfile(Request $request, string $profileId): Response
    {
        DataScope::ensureColumns();
        \App\Services\AccountBrowserEnvService::ensureSchema();
        $profileId = trim($profileId);
        if ($profileId === '') {
            return $this->error('缺少 profileId', 422);
        }
        // 环境共用：按 profileId 反查「它绑到哪个账号」同样是全局事实。
        // 若按当前用户的账号可见范围过滤，B 用户打开 A 用户的已绑定环境时这里会返回 null，
        // Node 端就拿不到绑定账号（无法回写登录状态、无法识别归属）。
        $row = DB::selectOne(
            "SELECT a.id, a.username, a.nickname, e.browser_profile_id, a.login_status, e.saturation,
                    e.browser_backend, e.browser_type, e.owner_user_id,
                    a.owner_id AS account_owner_id, a.company_id AS account_company_id,
                    a.industry_id,
                    u.username AS owner_username, u.nickname AS owner_nickname
             FROM account_browser_envs e
             INNER JOIN accounts a ON a.id=e.account_id
             LEFT JOIN admin_users u ON u.id = COALESCE(NULLIF(e.owner_user_id, 0), a.owner_id)
             WHERE e.browser_profile_id = ?
             LIMIT 1",
            [$profileId]
        );
        if (!$row) {
            $row = DB::selectOne(
                "SELECT a.id, a.username, a.nickname, a.browser_profile_id, a.login_status,
                        a.browser_backend, a.owner_id AS owner_user_id, a.owner_id AS account_owner_id,
                        a.company_id AS account_company_id, a.industry_id,
                        u.username AS owner_username, u.nickname AS owner_nickname
                 FROM accounts a
                 LEFT JOIN admin_users u ON u.id = a.owner_id
                 WHERE (TRIM(COALESCE(a.browser_profile_id, '')) = ?
                     OR TRIM(COALESCE(a.tkswarm_browser_profile_id, '')) = ?
                     OR TRIM(COALESCE(a.linux_browser_profile_id, '')) = ?)
                 LIMIT 1",
                [$profileId, $profileId, $profileId]
            );
        }
        if ($row) {
            $row['shared'] = !DataScope::isAccountRowVisible([
                'id' => (int)($row['id'] ?? 0),
                'owner_id' => (int)($row['account_owner_id'] ?? 0),
                'company_id' => (int)($row['account_company_id'] ?? 0),
                'industry_id' => (int)($row['industry_id'] ?? 0),
            ]);
            $be = \App\Services\AccountBrowserEnvService::normalizeBackend((string)($row['browser_backend'] ?? 'bit'));
            $row['browser_backend'] = $be;
            $row['browser_type'] = $be;
            $row['browser_type_label'] = \App\Services\AccountBrowserEnvService::backendLabel($be);
            $row['owner_label'] = trim((string)(($row['owner_nickname'] ?? '') !== '' ? $row['owner_nickname'] : ($row['owner_username'] ?? ''))) ?: '-';
        }
        return $this->success($row ?: null);
    }

    /** @param array<string,array<string,mixed>> $map */
    private function mergeLegacyAccountBrowserBindings(array &$map): void
    {
        // 同样按「全局事实」处理：老版本只在 accounts 表上记了环境 ID 的绑定也要全量返回，
        // 否则 B 用户看不到 A 用户的历史绑定，会被当成孤儿环境。
        $legacy = DB::select(
            "SELECT a.id, a.username, a.nickname, a.owner_id, a.company_id, a.industry_id, a.browser_backend,
                    a.browser_profile_id, a.tkswarm_browser_profile_id, a.linux_browser_profile_id,
                    u.username AS owner_username, u.nickname AS owner_nickname
             FROM accounts a
             LEFT JOIN admin_users u ON u.id = a.owner_id
             WHERE (TRIM(COALESCE(a.browser_profile_id, '')) <> ''
                OR TRIM(COALESCE(a.tkswarm_browser_profile_id, '')) <> ''
                OR TRIM(COALESCE(a.linux_browser_profile_id, '')) <> '')"
        ) ?: [];
        foreach ($legacy as $r) {
            $ownerName = trim((string)(($r['owner_nickname'] ?? '') !== '' ? $r['owner_nickname'] : ($r['owner_username'] ?? '')));
            $ownerId = (int)($r['owner_id'] ?? 0);
            $shared = !DataScope::isAccountRowVisible([
                'id' => (int)($r['id'] ?? 0),
                'owner_id' => $ownerId,
                'company_id' => (int)($r['company_id'] ?? 0),
                'industry_id' => (int)($r['industry_id'] ?? 0),
            ]);
            $pairs = [
                ['profile' => trim((string)($r['browser_profile_id'] ?? '')), 'backend' => (string)($r['browser_backend'] ?? 'bit'), 'primary' => 1],
                ['profile' => trim((string)($r['tkswarm_browser_profile_id'] ?? '')), 'backend' => 'tkswarm', 'primary' => 0],
                ['profile' => trim((string)($r['linux_browser_profile_id'] ?? '')), 'backend' => 'linux', 'primary' => 0],
            ];
            foreach ($pairs as $pair) {
                $pid = $pair['profile'];
                if ($pid === '' || isset($map[$pid])) {
                    continue;
                }
                $be = \App\Services\AccountBrowserEnvService::normalizeBackend($pair['backend']);
                $map[$pid] = [
                    'id' => (int)$r['id'],
                    'username' => $r['username'] ?? '',
                    'nickname' => $r['nickname'] ?? '',
                    'browser_profile_id' => $pid,
                    'saturation' => 'idle',
                    'is_primary' => (int)$pair['primary'],
                    'browser_backend' => $be,
                    'browser_type' => $be,
                    'browser_type_label' => \App\Services\AccountBrowserEnvService::backendLabel($be),
                    'owner_user_id' => $ownerId,
                    'owner_username' => (string)($r['owner_username'] ?? ''),
                    'owner_nickname' => (string)($r['owner_nickname'] ?? ''),
                    'owner_label' => $ownerName !== '' ? $ownerName : '-',
                    'account_owner_id' => $ownerId,
                    'shared' => $shared,
                    'locked' => $shared,
                ];
            }
        }
    }

    public function accountBindings(Request $request): Response
    {
        DataScope::ensureColumns();
        \App\Services\AccountBrowserEnvService::backfillFromAccounts();
        // 环境共用：环境 ↔ 账号的绑定关系是「全局事实」，绝不能按当前用户的账号可见范围过滤。
        // 否则 B 用户登录后查不到 A 用户已绑定的环境，Node 端会把这些环境当孤儿窗
        // （applyBrowserProfileScope 只保留 row.account 的行）直接过滤掉，甚至误判可删。
        // 这里返回全量绑定；账号不在本人可见范围的行用 shared/locked 标记，供前端显示「他人账号」。
        $rows = DB::select(
            "SELECT e.browser_profile_id, e.saturation, e.is_primary, e.account_id AS id,
                    e.browser_backend, e.browser_type, e.owner_user_id,
                    a.username, a.nickname, a.owner_id AS account_owner_id,
                    a.company_id AS account_company_id, a.industry_id,
                    u.username AS owner_username, u.nickname AS owner_nickname
             FROM account_browser_envs e
             INNER JOIN accounts a ON a.id=e.account_id
             LEFT JOIN admin_users u ON u.id = COALESCE(NULLIF(e.owner_user_id, 0), a.owner_id)"
        ) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $pid = trim((string)($r['browser_profile_id'] ?? ''));
            if ($pid === '') {
                continue;
            }
            $be = \App\Services\AccountBrowserEnvService::normalizeBackend((string)($r['browser_backend'] ?? 'bit'));
            $ownerId = (int)($r['owner_user_id'] ?: $r['account_owner_id'] ?: 0);
            $ownerName = trim((string)(($r['owner_nickname'] ?? '') !== '' ? $r['owner_nickname'] : ($r['owner_username'] ?? '')));
            // 账号不在本人可见范围 = 他人账号：仍然返回绑定事实（B 必须知道这个环境被谁占了、不能删），
            // 但打上 shared 标记，前端可显示「他人账号」并提示这是共用环境。
            $shared = !DataScope::isAccountRowVisible([
                'id' => (int)($r['id'] ?? 0),
                'owner_id' => (int)($r['account_owner_id'] ?? 0),
                'company_id' => (int)($r['account_company_id'] ?? 0),
                'industry_id' => (int)($r['industry_id'] ?? 0),
            ]);
            $map[$pid] = [
                'id' => (int)$r['id'],
                'username' => $r['username'] ?? '',
                'nickname' => $r['nickname'] ?? '',
                'browser_profile_id' => $pid,
                'saturation' => $r['saturation'] ?? 'idle',
                'is_primary' => (int)($r['is_primary'] ?? 0),
                'browser_backend' => $be,
                'browser_type' => $be,
                'browser_type_label' => \App\Services\AccountBrowserEnvService::backendLabel($be),
                'owner_user_id' => $ownerId,
                'owner_username' => (string)($r['owner_username'] ?? ''),
                'owner_nickname' => (string)($r['owner_nickname'] ?? ''),
                'owner_label' => $ownerName !== '' ? $ownerName : '-',
                'account_owner_id' => (int)($r['account_owner_id'] ?? 0),
                'shared' => $shared,
                'locked' => $shared,
            ];
        }
        $this->mergeLegacyAccountBrowserBindings($map);
        $excludeOrphanProfileIds = [];
        if (DataScope::isSuper() || DataScope::isOpsAdmin()) {
            $assigned = DB::select(
                'SELECT e.browser_profile_id,
                        COALESCE(NULLIF(e.owner_user_id, 0), NULLIF(a.owner_id, 0), 0) AS eff_owner
                 FROM account_browser_envs e
                 INNER JOIN accounts a ON a.id = e.account_id
                 WHERE e.browser_profile_id IS NOT NULL AND TRIM(e.browser_profile_id) <> \'\''
            ) ?: [];
            foreach ($assigned as $ar) {
                if ((int)($ar['eff_owner'] ?? 0) > 0) {
                    $excludeOrphanProfileIds[] = (string)$ar['browser_profile_id'];
                }
            }
            $excludeOrphanProfileIds = array_values(array_unique($excludeOrphanProfileIds));
        }

        return $this->success([
            'items' => array_values($map),
            'map' => $map,
            'excludeOrphanProfileIds' => $excludeOrphanProfileIds,
            'canSeeOrphanProfiles' => DataScope::isSuper() || DataScope::isOpsAdmin(),
        ]);
    }

    /**
     * 自研浏览器开窗：按环境 profileId 取绑定账号的代理（含解密密码）。
     * 与 Node 同源，避免仅靠 Node 中转写入本地 db 时账密丢失。
     */
    public function profileProxyBundle(Request $request, string $profileId): Response
    {
        DataScope::ensureColumns();
        \App\Services\AccountBrowserEnvService::ensureSchema();
        $profileId = trim($profileId);
        if ($profileId === '') {
            return $this->error('缺少 profileId', 422);
        }

        // 环境共用（关键）：本接口是自研/服务器浏览器「开窗前按 profileId 补全该环境所绑账号的代理账密」，
        // 属于浏览器服务的内部调用。调用方已通过登录鉴权，profileId 是随机 UUID，
        // 返回的也只是「这个环境自己那条代理」，因此绝不能再用「当前登录用户能看见哪些账号」过滤：
        //   · B 用户登录自研浏览器、打开 A 用户创建并绑定的环境 → 查不到账号 → hasProxy:false
        //   · SOCKS 账密缺失后开窗代理认证失败，表现就是「A 的环境 B 用不了」
        $accountId = 0;
        $bound = DB::selectOne(
            'SELECT a.id FROM account_browser_envs e
             INNER JOIN accounts a ON a.id = e.account_id
             WHERE e.browser_profile_id = ? LIMIT 1',
            [$profileId]
        );
        if ($bound) {
            $accountId = (int)($bound['id'] ?? 0);
        }

        if ($accountId < 1) {
            $legacy = DB::selectOne(
                'SELECT a.id FROM accounts a
                 WHERE (TRIM(a.tkswarm_browser_profile_id) = ?
                     OR TRIM(a.browser_profile_id) = ?
                     OR TRIM(a.linux_browser_profile_id) = ?)
                 LIMIT 1',
                [$profileId, $profileId, $profileId]
            );
            if ($legacy) {
                $accountId = (int)($legacy['id'] ?? 0);
            }
        }

        if ($accountId < 1) {
            return $this->success(['hasProxy' => false, 'account_id' => null]);
        }

        $row = DB::selectOne(
            'SELECT a.id AS account_id, a.proxy_id, a.owner_id, a.company_id,
                    p.protocol, p.host, p.port, p.username, p.password, p.password_encrypted
             FROM accounts a
             LEFT JOIN proxies p ON p.id = a.proxy_id
             WHERE a.id = ?',
            [$accountId]
        );
        if (!$row) {
            return $this->success(['hasProxy' => false, 'account_id' => null]);
        }
        if (!$row || trim((string)($row['host'] ?? '')) === '') {
            return $this->success([
                'hasProxy' => false,
                'account_id' => $accountId,
                'proxy_id' => (int)($row['proxy_id'] ?? 0),
            ]);
        }

        $password = (string)($row['password'] ?? '');
        if ($password === '' && !empty($row['password_encrypted'])) {
            $password = SecretStore::decrypt((string)$row['password_encrypted']);
        }

        return $this->success([
            'hasProxy' => true,
            'account_id' => $accountId,
            'proxy_id' => (int)($row['proxy_id'] ?? 0),
            'protocol' => $row['protocol'] ?? 'socks5',
            'host' => $row['host'] ?? '',
            'port' => (int)($row['port'] ?? 0),
            'username' => (string)($row['username'] ?? ''),
            'password' => $password,
            'hasPassword' => $password !== '',
        ]);
    }

    /** Node 探测用：返回含解密密码的代理包（公开 /api/proxies 列表不含密码） */
    public function proxyBundle(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM proxies WHERE id=?', [(int)$id]);
        if ($err = DataScope::assertProxyVisible($row)) {
            return $err;
        }
        $password = (string)($row['password'] ?? '');
        if ($password === '' && !empty($row['password_encrypted'])) {
            $password = SecretStore::decrypt((string)$row['password_encrypted']);
        }
        return $this->success([
            'id' => (int)$row['id'],
            'name' => $row['name'] ?? '',
            'protocol' => $row['protocol'] ?? 'socks5',
            'host' => $row['host'] ?? '',
            'port' => (int)($row['port'] ?? 0),
            'username' => (string)($row['username'] ?? ''),
            'password' => $password,
            'country' => (string)($row['country'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'hasPassword' => $password !== '',
        ]);
    }

    public function proxyProbeResult(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT id, owner_id FROM proxies WHERE id=?', [(int)$id]);
        if ($err = DataScope::assertProxyVisible($row)) {
            return $err;
        }
        $available = filter_var($request->input('available', false), FILTER_VALIDATE_BOOLEAN);
        $latency = $request->input('latencyMs', $request->input('latency_ms'));
        $egressIp = (string)$request->input('egressIp', $request->input('egress_ip', ''));
        $country = trim((string)$request->input('country', ''));
        if ($country !== '') {
            DB::query(
                "UPDATE proxies SET status=?, latency_ms=?, egress_ip=?, country=?, last_checked_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [
                    $available ? 'available' : 'unavailable',
                    $latency !== null && $latency !== '' ? (int)$latency : null,
                    mb_substr($egressIp, 0, 64),
                    mb_substr($country, 0, 64),
                    (int)$id,
                ]
            );
        } else {
            DB::query(
                "UPDATE proxies SET status=?, latency_ms=?, egress_ip=?, last_checked_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [
                    $available ? 'available' : 'unavailable',
                    $latency !== null && $latency !== '' ? (int)$latency : null,
                    mb_substr($egressIp, 0, 64),
                    (int)$id,
                ]
            );
        }
        return $this->success(['id' => (int)$id, 'available' => $available, 'country' => $country, 'egressIp' => $egressIp]);
    }

    /** Node 可读完整设置（含 token，需登录；按当前登录用户隔离） */
    public function settingsForNode(Request $request): Response
    {
        $map = \App\Services\UserSettingsService::getForUser(null, false);
        return $this->success($map);
    }

    public function monitorConfig(Request $request): Response
    {
        DB::query(
            "CREATE TABLE IF NOT EXISTS account_monitor_config (
              id INT PRIMARY KEY DEFAULT 1,
              enabled TINYINT NOT NULL DEFAULT 0,
              interval_minutes INT NOT NULL DEFAULT 60,
              sync_profile TINYINT NOT NULL DEFAULT 1,
              sync_videos TINYINT NOT NULL DEFAULT 0,
              account_ids_json MEDIUMTEXT NULL,
              last_run_at DATETIME NULL,
              last_result_json MEDIUMTEXT NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        DB::query('INSERT IGNORE INTO account_monitor_config(id, enabled, interval_minutes) VALUES (1, 0, 60)');
        $cfg = DB::selectOne('SELECT * FROM account_monitor_config WHERE id=1') ?: [];
        $ids = json_decode((string)($cfg['account_ids_json'] ?? '[]'), true);
        return $this->success([
            'enabled' => !empty($cfg['enabled']),
            'intervalMinutes' => (int)($cfg['interval_minutes'] ?? 60),
            'interval_minutes' => (int)($cfg['interval_minutes'] ?? 60),
            'syncProfile' => !isset($cfg['sync_profile']) || (int)$cfg['sync_profile'] !== 0,
            'sync_profile' => !isset($cfg['sync_profile']) || (int)$cfg['sync_profile'] !== 0,
            'syncVideos' => !empty($cfg['sync_videos']),
            'sync_videos' => !empty($cfg['sync_videos']),
            'accountIds' => is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [],
            'lastRunAt' => $cfg['last_run_at'] ?? null,
            'last_run_at' => $cfg['last_run_at'] ?? null,
        ]);
    }

    public function materialForNode(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT * FROM materials WHERE id=?', [(int)$id]);
        if ($err = $this->assertMaterialOwns($row)) {
            return $err;
        }
        $path = (string)($row['file_path'] ?? $row['path'] ?? '');
        $exists = $path !== '' && is_file($path);
        return $this->success([
            'id' => (int)$row['id'],
            'name' => $row['name'] ?? '',
            'file_path' => $exists ? $path : '',
            'filePath' => $exists ? $path : '',
            'file_name' => $row['file_name'] ?? '',
            'fileName' => $row['file_name'] ?? '',
            'mime_type' => $row['mime_type'] ?? 'video/mp4',
            'mimeType' => $row['mime_type'] ?? 'video/mp4',
            'size_bytes' => (int)($row['size_bytes'] ?? 0),
            'status' => $row['status'] ?? ($exists ? 'ready' : 'missing'),
            'share_title' => (string)($row['share_title'] ?? ''),
            'shareTitle' => (string)($row['share_title'] ?? ''),
            'share_caption' => (string)($row['share_caption'] ?? ''),
            'shareCaption' => (string)($row['share_caption'] ?? ''),
            'platform_video_id' => (string)($row['platform_video_id'] ?? ''),
            'platformVideoId' => (string)($row['platform_video_id'] ?? ''),
            'cover_base64' => (string)($row['cover_base64'] ?? ''),
            'coverBase64' => (string)($row['cover_base64'] ?? ''),
            'downloadUrl' => '/api/materials/' . (int)$id . '/stream',
            'download_url' => '/api/materials/' . (int)$id . '/stream',
        ]);
    }

    /** 发布成功后由 Node 回调：标记素材已发布并关联账号（测试/正式路径均可） */
    public function markMaterialPublished(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureMaterialsPublishColumns();
        $row = DB::selectOne('SELECT id, owner_id FROM materials WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('素材不存在', 404);
        }
        // Node 自动化回调：允许跨 owner 回写发布状态（仍需登录）
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $deleteAfter = (bool)$request->input('deleteAfterPublish', $request->input('delete_after_publish', false));
        $videoId = trim((string)$request->input('videoId', $request->input('video_id', $request->input('platformVideoId', ''))));
        $videoUrl = trim((string)$request->input('videoUrl', $request->input('video_url', $request->input('platformVideoUrl', ''))));
        try {
            $ok = AccountWorkBatchService::markMaterialPublished((int)$id, $accountId, $deleteAfter, $videoId, $videoUrl);
            // 仍无平台链接时再尝试从已同步的 tiktok_videos 回填
            if ($ok && $videoId === '' && $videoUrl === '') {
                AccountWorkBatchService::backfillPlatformVideoFromSyncedVideos((int)$id, $accountId);
            }
        } catch (\Throwable $e) {
            return $this->error('标记发布失败: ' . $e->getMessage(), 500);
        }
        if (!$ok) {
            return $this->error('标记发布失败', 400);
        }
        $fresh = DB::selectOne(
            'SELECT id, publish_status, publish_account_id, publish_account, platform_video_id, platform_video_url FROM materials WHERE id=?',
            [(int)$id]
        );
        return $this->success([
            'id' => (int)$id,
            'publishStatus' => $fresh['publish_status'] ?? 'published',
            'publishAccountId' => isset($fresh['publish_account_id']) ? (int)$fresh['publish_account_id'] : null,
            'publishAccount' => $fresh['publish_account'] ?? '',
            'platformVideoId' => $fresh['platform_video_id'] ?? '',
            'platformVideoUrl' => $fresh['platform_video_url'] ?? '',
        ], '素材已标记为已发布');
    }

    public function deleteTikTokVideo(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertNodeAccountRow($account)) {
            return $err;
        }
        $this->ensureTikTokTables();
        $videoId = trim((string)$request->input('videoId', $request->input('video_id', '')));
        $videoUrl = trim((string)$request->input('videoUrl', $request->input('video_url', '')));
        if ($videoId !== '') {
            DB::query('DELETE FROM tiktok_videos WHERE account_id=? AND video_id=?', [(int)$id, $videoId]);
            AccountWorkBatchService::clearMaterialPublishByPlatformVideo((int)$id, $videoId);
        } elseif ($videoUrl !== '') {
            DB::query('DELETE FROM tiktok_videos WHERE account_id=? AND video_url=?', [(int)$id, $videoUrl]);
            if (preg_match('#/video/(\d+)#', $videoUrl, $m)) {
                AccountWorkBatchService::clearMaterialPublishByPlatformVideo((int)$id, $m[1]);
            }
        }
        return $this->success(['accountId' => (int)$id, 'videoId' => $videoId], '视频记录已删除');
    }

    /** Node 上报诊断截图；每账号仅保留最近 3 条 */
    public function diagnosisResult(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertNodeAccountRow($account)) {
            return $err;
        }
        $this->ensureDiagnosisTable();
        $path = trim((string)$request->input(
            'screenshotPath',
            $request->input('screenshot_path', $request->input('name', ''))
        ));
        $path = basename($path);
        if ($path === '' || !preg_match('/^\d+[-a-zA-Z0-9_.]+\.png$/', $path)) {
            return $this->error('截图文件名无效', 422);
        }
        $label = trim((string)$request->input('label', 'account-check')) ?: 'account-check';
        $source = trim((string)$request->input('source', 'manual')) ?: 'manual';
        DB::query(
            'INSERT INTO account_diagnosis_records(account_id, screenshot_path, label, source)
             VALUES (?,?,?,?)',
            [(int)$id, $path, mb_substr($label, 0, 64), mb_substr($source, 0, 32)]
        );
        $newId = (int)DB::getPdo()->lastInsertId();
        $keep = DB::select(
            'SELECT id FROM account_diagnosis_records WHERE account_id=? ORDER BY created_at DESC, id DESC LIMIT 3',
            [(int)$id]
        ) ?: [];
        $keepIds = array_map(static fn($r) => (int)$r['id'], $keep);
        $deletedPaths = [];
        if ($keepIds) {
            $ph = implode(',', array_fill(0, count($keepIds), '?'));
            $old = DB::select(
                "SELECT screenshot_path FROM account_diagnosis_records
                 WHERE account_id=? AND id NOT IN ({$ph})",
                array_merge([(int)$id], $keepIds)
            ) ?: [];
            foreach ($old as $row) {
                $deletedPaths[] = basename((string)($row['screenshot_path'] ?? ''));
            }
            DB::query(
                "DELETE FROM account_diagnosis_records WHERE account_id=? AND id NOT IN ({$ph})",
                array_merge([(int)$id], $keepIds)
            );
        }
        return $this->success([
            'id' => $newId,
            'accountId' => (int)$id,
            'screenshotPath' => $path,
            'label' => $label,
            'source' => $source,
            'download' => '/api/browser/diagnostics/' . rawurlencode($path),
            'deletedPaths' => array_values(array_filter($deletedPaths)),
        ], '诊断截图已保存');
    }

    public function bitSendContext(Request $request): Response
    {
        DataScope::ensureColumns();
        $friendId = (int)$request->input('friendId', $request->input('friend_id', 0));
        if ($friendId < 1) {
            return $this->error('缺少 friendId', 422);
        }
        $friend = DB::selectOne('SELECT * FROM chat_users WHERE id=?', [$friendId]);
        if (!$friend) {
            return $this->error('好友不存在', 404);
        }
        $account = DB::selectOne('SELECT id, owner_id, username, browser_profile_id FROM accounts WHERE id=?', [(int)$friend['account_id']]);
        if ($err = $this->assertNodeAccountRow($account)) {
            return $err;
        }
        $recipient = (string)($friend['username'] ?? $friend['friend_uid'] ?? '');
        return $this->success([
            'friendId' => $friendId,
            'accountId' => (int)$account['id'],
            'browserProfileId' => (string)($account['browser_profile_id'] ?? ''),
            'username' => $recipient,
            'recipient' => $recipient,
            'nickname' => (string)($friend['nickname'] ?? ''),
        ]);
    }

    public function bitSendResult(Request $request): Response
    {
        DataScope::ensureColumns();
        $friendId = (int)$request->input('friendId', $request->input('friend_id', 0));
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $content = trim((string)$request->input('content', ''));
        if ($friendId < 1 || $content === '') {
            return $this->error('friendId 与 content 必填', 422);
        }
        $friend = DB::selectOne('SELECT * FROM chat_users WHERE id=?', [$friendId]);
        if (!$friend) {
            return $this->error('好友不存在', 404);
        }
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId ?: (int)$friend['account_id']]);
        if ($err = $this->assertNodeAccountRow($account)) {
            return $err;
        }
        $aid = (int)$account['id'];
        $translated = (string)$request->input('translated', '');
        $status = (string)$request->input('status', 'sent');
        DB::query(
            "INSERT INTO chat_records(account_id, user_id, direction, msg_type, content, translated, status)
             VALUES (?,?,'out','text',?,?,?)",
            [$aid, $friendId, $content, $translated, $status]
        );
        $msgId = (int)DB::getPdo()->lastInsertId();
        DB::query(
            "UPDATE chat_users SET last_message=?, last_direction='out', last_message_at=CURRENT_TIMESTAMP, unread_count=0, is_new=0, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [mb_substr($content, 0, 200), $friendId]
        );
        return $this->success([
            'id' => $msgId,
            'account_id' => $aid,
            'friend_id' => $friendId,
            'content' => $content,
            'status' => $status,
        ], '私信已入库');
    }

    public function claimTask(Request $request): Response
    {
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureSchema();
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $task = DB::selectOne(
            "SELECT * FROM tasks WHERE status='queued'
             AND (scheduled_at IS NULL OR scheduled_at<=CURRENT_TIMESTAMP)
             AND type IN ('sync','profile','publish','message','dm_sync','warm','sync_fans')
             AND NOT EXISTS (
               SELECT 1 FROM account_work_items i
               WHERE i.task_ref_id=tasks.id AND i.task_type=tasks.type
             )
             {$ownSql}
             ORDER BY id ASC LIMIT 1",
            $ownParams
        );
        if (!$task) {
            return $this->success(null, '暂无待执行任务');
        }
        DB::query(
            "UPDATE tasks SET status='running', started_at=COALESCE(started_at,CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='queued'",
            [(int)$task['id']]
        );
        $fresh = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$task['id']]);
        if (!$fresh || ($fresh['status'] ?? '') !== 'running') {
            return $this->success(null, '任务已被其他节点领取');
        }
        $payload = json_decode((string)($fresh['payload'] ?? '{}'), true) ?: [];
        $taskBackend = strtolower(trim((string)($payload['browserBackend'] ?? $payload['browser_backend'] ?? $payload['pushChannel'] ?? $payload['massChannel'] ?? 'bit')));
        if ($taskBackend !== 'tkswarm') {
            $taskBackend = 'bit';
        }
        $resolveProfile = static function (array $row) use ($taskBackend): string {
            $tk = trim((string)($row['tkswarm_browser_profile_id'] ?? ''));
            $primary = trim((string)($row['browser_profile_id'] ?? ''));
            return $taskBackend === 'tkswarm' ? ($tk !== '' ? $tk : $primary) : ($primary !== '' ? $primary : $tk);
        };
        $accounts = [];
        $explicitPairs = [];
        if (is_array($payload['pairs'] ?? null)) {
            foreach ($payload['pairs'] as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $aid = (int)($pair['accountId'] ?? $pair['account_id'] ?? 0);
                $mid = (int)($pair['materialId'] ?? $pair['material_id'] ?? 0);
                if ($aid > 0 && $mid > 0) {
                    $explicitPairs[] = ['accountId' => $aid, 'materialId' => $mid];
                }
            }
        }
        if ($explicitPairs) {
            foreach ($explicitPairs as $pair) {
                $row = DB::selectOne(
                    'SELECT id, username, browser_profile_id, browser_backend, tkswarm_browser_profile_id FROM accounts WHERE id=?',
                    [$pair['accountId']]
                );
                if (!$row) {
                    continue;
                }
                $accounts[] = [
                    'id' => (int)$row['id'],
                    'username' => $row['username'] ?? '',
                    'browser_profile_id' => $resolveProfile($row),
                    'browser_backend' => $taskBackend,
                    'tkswarm_browser_profile_id' => (string)($row['tkswarm_browser_profile_id'] ?? ''),
                    'materialId' => $pair['materialId'],
                    'material_id' => $pair['materialId'],
                ];
            }
        } else {
            $accountIds = array_values(array_filter(array_map('intval', $payload['accountIds'] ?? [])));
            if (!$accountIds && !empty($fresh['group_id'])) {
                $accountIds = array_map(
                    'intval',
                    array_column(
                        DB::select('SELECT id FROM accounts WHERE enabled=1 AND group_id=?', [(int)$fresh['group_id']]) ?: [],
                        'id'
                    )
                );
            }
            if (!$accountIds) {
                $accountIds = array_map(
                    'intval',
                    array_column(DB::select('SELECT id FROM accounts WHERE enabled=1 LIMIT 200') ?: [], 'id')
                );
            }
            $materialIds = array_values(array_filter(array_map('intval', $payload['materialIds'] ?? [])));
            $strategy = (string)($payload['publishStrategy'] ?? $payload['publishMode'] ?? 'sequential');
            $matCount = count($materialIds);
            if ($strategy === 'random' && $matCount > 1) {
                shuffle($materialIds);
            }
            foreach ($accountIds as $i => $aid) {
                $row = DB::selectOne(
                    'SELECT id, username, browser_profile_id, browser_backend, tkswarm_browser_profile_id FROM accounts WHERE id=?',
                    [$aid]
                );
                if (!$row) {
                    continue;
                }
                $mid = null;
                if ($matCount) {
                    if (in_array($strategy, ['deduplicate', 'independent'], true)) {
                        $mid = $materialIds[$i] ?? null;
                    } else {
                        $mid = $materialIds[$i % $matCount];
                    }
                }
                $accounts[] = [
                    'id' => (int)$row['id'],
                    'username' => $row['username'] ?? '',
                    'browser_profile_id' => $resolveProfile($row),
                    'browser_backend' => $taskBackend,
                    'tkswarm_browser_profile_id' => (string)($row['tkswarm_browser_profile_id'] ?? ''),
                    'materialId' => $mid,
                    'material_id' => $mid,
                ];
            }
        }
        DB::query('INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)', [
            (int)$fresh['id'], 'info', 'Node 已领取任务',
        ]);
        return $this->success([
            'id' => (int)$fresh['id'],
            'name' => $fresh['name'] ?? '',
            'type' => $fresh['type'] ?? '',
            'payload' => $payload,
            'accounts' => $accounts,
            'total_count' => (int)($fresh['total_count'] ?? count($accounts)),
        ]);
    }

    public function taskAccountResult(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $status = (string)$request->input('status', 'success');
        $errorMessage = (string)$request->input('errorMessage', $request->input('error_message', ''));
        $result = $request->input('result', []);
        if (!is_array($result)) {
            $result = ['raw' => $result];
        }
        DB::query(
            "INSERT INTO task_runs(task_id, account_id, status, error_message, started_at, finished_at)
             VALUES (?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)",
            [(int)$id, $accountId ?: null, $status, $errorMessage]
        );
        DB::query(
            "INSERT INTO task_action_results(task_id, account_id, action_type, status, result_json, error_message)
             VALUES (?,?,?,?,?,?)",
            [
                (int)$id,
                $accountId,
                (string)($task['type'] ?? ''),
                $status,
                json_encode($result, JSON_UNESCAPED_UNICODE),
                $errorMessage,
            ]
        );
        if ($status === 'success') {
            DB::query('UPDATE tasks SET success_count=success_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
        } elseif (in_array($status, ['failed', 'error'], true)) {
            DB::query('UPDATE tasks SET fail_count=fail_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
        }
        return $this->success(['ok' => true]);
    }

    public function finishTask(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $success = (int)$request->input('success', $task['success_count'] ?? 0);
        $fail = (int)$request->input('fail', $task['fail_count'] ?? 0);
        $status = $fail > 0 && $success < 1 ? 'failed' : 'completed';
        DB::query(
            "UPDATE tasks SET status=?, success_count=?, fail_count=?, finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$status, $success, $fail, (int)$id]
        );
        DB::query('INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)', [
            (int)$id, 'info', "任务结束：成功 {$success}，失败 {$fail}",
        ]);
        return $this->success(['id' => (int)$id, 'status' => $status], '任务已结束');
    }

    public function taskItem(Request $request, string $id, string $itemId): Response
    {
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $item = DB::selectOne('SELECT * FROM task_items WHERE id=? AND task_id=?', [(int)$itemId, (int)$id]);
        if (!$item) {
            return $this->error('子任务不存在', 404);
        }
        $item['accountId'] = (int)($item['account_id'] ?? 0);
        $item['materialId'] = (int)($item['material_id'] ?? 0);
        return $this->success($item);
    }

    public function taskItemStatus(Request $request, string $id, string $itemId): Response
    {
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT id, owner_id FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $status = (string)$request->input('status', 'publishing');
        DB::query(
            'UPDATE task_items SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND task_id=?',
            [$status, (int)$itemId, (int)$id]
        );
        return $this->success(['id' => (int)$itemId, 'status' => $status]);
    }

    public function taskItemResult(Request $request, string $id, string $itemId): Response
    {
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $item = DB::selectOne('SELECT * FROM task_items WHERE id=? AND task_id=?', [(int)$itemId, (int)$id]);
        if (!$item) {
            return $this->error('子任务不存在', 404);
        }
        $status = (string)$request->input('status', 'failed');
        $errorMessage = (string)$request->input('errorMessage', $request->input('error_message', ''));
        $result = $request->input('result', []);
        DB::query(
            'UPDATE task_items SET status=?, error_message=?, meta_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $status,
                $errorMessage,
                is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE),
                (int)$itemId,
            ]
        );
        if ($status === 'success') {
            DB::query('UPDATE tasks SET success_count=success_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
            $mid = (int)($item['material_id'] ?? 0);
            if ($mid > 0) {
                $accountId = (int)($item['account_id'] ?? 0);
                $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
                $deleteAfter = !empty($payload['deleteAfterPublish']);
                \App\Services\AccountWorkBatchService::markMaterialPublished($mid, $accountId, $deleteAfter);
            }
        } elseif ($status === 'failed') {
            DB::query('UPDATE tasks SET fail_count=fail_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
        }
        return $this->success(['id' => (int)$itemId, 'status' => $status]);
    }

    public function confirmPublished(Request $request, string $id, string $itemId): Response
    {
        DataScope::ensureColumns();
        $task = DB::selectOne('SELECT * FROM tasks WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($task)) {
            return $err;
        }
        $item = DB::selectOne('SELECT * FROM task_items WHERE id=? AND task_id=?', [(int)$itemId, (int)$id]);
        if (!$item) {
            return $this->error('子任务不存在', 404);
        }
        DB::query(
            "UPDATE task_items SET status='success', error_message='', updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [(int)$itemId]
        );
        DB::query('UPDATE tasks SET success_count=success_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
        $mid = (int)($item['material_id'] ?? 0);
        if ($mid > 0) {
            $accountId = (int)($item['account_id'] ?? 0);
            $payload = json_decode((string)($task['payload'] ?? '{}'), true) ?: [];
            \App\Services\AccountWorkBatchService::markMaterialPublished($mid, $accountId, !empty($payload['deleteAfterPublish']));
        }
        DB::query('INSERT INTO task_events(task_id, level, message) VALUES (?,?,?)', [
            (int)$id, 'info', "子任务 #{$itemId} 已人工确认发布",
        ]);
        return $this->success(['id' => (int)$itemId, 'status' => 'success'], '已确认发布');
    }

    /** 领取账号会话总任务（一次登录串行跑多类分任务） */
    public function claimWorkBatch(Request $request): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $workerId = trim((string)$request->input('workerId', $request->input('worker_id', gethostname() ?: 'node')));
        if ($workerId === '') {
            $workerId = 'node';
        }
        $lease = max(60, (int)$request->input('leaseSeconds', $request->input('lease_seconds', 300)));
        // 优先用 Node 传入的 ownerId（对应当前 WSS 登录身份）；普通用户只能领本人数据
        $forceOwner = (int)$request->input('ownerId', $request->input('owner_id', 0));
        $scopeOwner = DataScope::filterOwnerId();
        if ($scopeOwner !== null) {
            if ($forceOwner > 0 && $forceOwner !== $scopeOwner) {
                return $this->error('无权领取其他用户的任务', 403);
            }
            $ownerFilter = $scopeOwner;
        } else {
            $ownerFilter = $forceOwner > 0 ? $forceOwner : DataScope::writeOwnerId();
        }
        if ($ownerFilter < 1) {
            $ownerFilter = null;
        }
        $batch = AccountWorkBatchService::claimBatch($workerId, $lease, $ownerFilter);
        if (!$batch) {
            return $this->success(null, '暂无待执行账号会话');
        }
        return $this->success($batch);
    }

    /** 定点领取指定 batch（AI拓客启用后立即推送） */
    public function claimSpecificWorkBatch(Request $request): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $batchId = (int)$request->input('batchId', $request->input('batch_id', 0));
        if ($batchId < 1) {
            return $this->error('缺少 batchId', 422);
        }
        $row = DB::selectOne('SELECT * FROM account_work_batches WHERE id=?', [$batchId]);
        if ($err = $this->assertWorkBatchOwns($row)) {
            return $err;
        }
        $workerId = trim((string)$request->input('workerId', $request->input('worker_id', gethostname() ?: 'node')));
        if ($workerId === '') {
            $workerId = 'node';
        }
        $lease = max(60, (int)$request->input('leaseSeconds', $request->input('lease_seconds', 300)));
        $batch = AccountWorkBatchService::claimSpecificBatch($batchId, $workerId, $lease);
        if (!$batch) {
            return $this->error('无法领取该任务：环境忙碌或分项已执行完', 409);
        }
        return $this->success($batch);
    }

    public function workBatchHeartbeat(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        $workerId = trim((string)$request->input('workerId', $request->input('worker_id', '')));
        $lease = max(60, (int)$request->input('leaseSeconds', $request->input('lease_seconds', 300)));
        $ok = AccountWorkBatchService::heartbeat((int)$id, $workerId, $lease);
        if (!$ok) {
            return $this->error('心跳失败：总任务未运行或不属于当前节点', 409);
        }
        return $this->success(['ok' => true]);
    }

    public function claimWorkItem(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        $workerId = trim((string)$request->input('workerId', $request->input('worker_id', '')));
        $item = AccountWorkBatchService::claimNextItem((int)$id, $workerId);
        if (!$item) {
            return $this->success(null, '当前会话暂无待执行分任务');
        }
        if (!empty($item['task_ref_id'])) {
            DB::query(
                "UPDATE tasks SET status='running', started_at=COALESCE(started_at,CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status='queued'",
                [(int)$item['task_ref_id']]
            );
        }
        return $this->success($item);
    }

    public function workItemResult(Request $request, string $id, string $itemId): Response
    {
        AccountWorkBatchService::ensureSchema();
        $workerId = trim((string)$request->input('workerId', $request->input('worker_id', '')));
        $status = (string)$request->input('status', 'failed');
        $errorMessage = (string)$request->input('errorMessage', $request->input('error_message', ''));
        $result = $request->input('result', []);
        if (!is_array($result)) {
            $result = ['raw' => $result];
        }
        try {
            $out = AccountWorkBatchService::finishItem(
                (int)$id,
                (int)$itemId,
                $workerId,
                $status,
                $result,
                $errorMessage
            );
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    /** Node 执行过程实时日志落库 */
    public function appendWorkLog(Request $request): Response
    {
        AccountWorkBatchService::ensureSchema();
        $batchId = (int)$request->input('batchId', $request->input('batch_id', 0));
        $itemId = (int)$request->input('itemId', $request->input('item_id', 0));
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $taskId = (int)$request->input('taskId', $request->input('task_id', 0));
        $level = (string)$request->input('level', 'info');
        $message = trim((string)$request->input('message', ''));
        $meta = $request->input('meta', []);
        if (!is_array($meta)) {
            $meta = [];
        }
        if ($message === '' || ($batchId < 1 && $itemId < 1)) {
            return $this->success(null, 'skipped');
        }
        if ($accountId < 1 && $batchId > 0) {
            $batch = DB::selectOne('SELECT account_id FROM account_work_batches WHERE id=?', [$batchId]);
            $accountId = (int)($batch['account_id'] ?? 0);
        }
        if ($accountId < 1 && $itemId > 0) {
            $item = DB::selectOne('SELECT account_id, batch_id, task_ref_id FROM account_work_items WHERE id=?', [$itemId]);
            $accountId = (int)($item['account_id'] ?? 0);
            if ($batchId < 1) {
                $batchId = (int)($item['batch_id'] ?? 0);
            }
            if ($taskId < 1) {
                $taskId = (int)($item['task_ref_id'] ?? 0);
            }
        }
        AccountWorkBatchService::appendLog($batchId, $itemId, $message, $level, $accountId, $taskId, $meta);
        return $this->success(['ok' => true]);
    }

    /** Node 写一条「修改资料」逐字段执行记录 */
    public function profileChangeLog(Request $request, string $id): Response
    {
        $accountId = (int)$id;
        if ($accountId < 1) {
            return $this->error('账号不存在', 404);
        }
        $fields = $request->input('fields', []);
        if (!is_array($fields)) {
            $fields = [];
        }
        $meta = [
            'batch_id' => (int)$request->input('batchId', $request->input('batch_id', 0)),
            'item_id' => (int)$request->input('itemId', $request->input('item_id', 0)),
            'task_id' => (int)$request->input('taskId', $request->input('task_id', 0)),
            'status' => (string)$request->input('status', 'ok'),
            'message' => trim((string)$request->input('message', '')),
            'screenshot' => basename(trim((string)$request->input('screenshot', ''))),
            'operator_id' => DataScope::effectiveUserId(),
        ];
        try {
            $logId = AccountWorkBatchService::appendProfileChangeLog($accountId, $fields, $meta);
            return $this->success(['id' => $logId], '已记录');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function finishWorkBatch(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        $workerId = trim((string)$request->input('workerId', $request->input('worker_id', '')));
        $force = strtolower(trim((string)$request->input('status', '')));
        $errorMessage = trim((string)$request->input('errorMessage', $request->input('error_message', '')));
        $forceStatus = null;
        if ($force === 'cancelled') {
            $forceStatus = 'cancelled';
        } elseif ($force === 'failed') {
            $forceStatus = 'failed';
        }
        try {
            $out = AccountWorkBatchService::finishBatch(
                (int)$id,
                $workerId,
                $forceStatus,
                $errorMessage
            );
            return $this->success($out, $out['has_more'] ?? false ? '仍有分任务' : '账号会话已结束');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    /** 退回已领取但未执行的总任务（不取消分任务） */
    public function releaseWorkBatch(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        $workerId = trim((string)$request->input('workerId', $request->input('worker_id', '')));
        try {
            $out = AccountWorkBatchService::releaseClaim((int)$id, $workerId);
            return $this->success($out, $out['released'] ?? false ? '已退回排队' : ($out['message'] ?? '未释放'));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    /** 不可重试错误：强制失败收口（不退回排队） */
    public function forceFailWorkBatch(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        $errorMessage = trim((string)$request->input('errorMessage', $request->input('error_message', '')));
        try {
            $out = AccountWorkBatchService::forceFailBatch((int)$id, $errorMessage);
            return $this->success($out, '已强制失败收口');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    /** 标记总任务已进入本机执行（offered → executing） */
    public function markWorkBatchExecuting(Request $request, string $id): Response
    {
        AccountWorkBatchService::ensureSchema();
        $workerId = trim((string)$request->input('workerId', $request->input('worker_id', '')));
        $ok = AccountWorkBatchService::markPushExecuting((int)$id, $workerId);
        if (!$ok) {
            return $this->error('标记执行失败：总任务未运行或不属于当前节点', 409);
        }
        return $this->success(['ok' => true, 'push_status' => 'executing']);
    }

    /** 回收过期租约；并返回本节点仍锁着的可继续执行会话 */
    public function reclaimWorkBatches(Request $request): Response
    {
        AccountWorkBatchService::ensureSchema();
        $workerId = trim((string)$request->input('workerId', $request->input('worker_id', '')));
        AccountWorkBatchService::reclaimExpiredLeases();
        $claimed = $workerId !== ''
            ? AccountWorkBatchService::listClaimedByWorker($workerId, 10)
            : [];
        return $this->success([
            'reclaimed' => true,
            'claimed' => $claimed,
        ]);
    }

    /** 直接向账号会话追加分任务（删视频/回评等可不经 tasks 表） */
    public function enqueueWorkItem(Request $request): Response
    {
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $taskType = (string)$request->input('taskType', $request->input('task_type', ''));
        $taskRefId = (int)$request->input('taskRefId', $request->input('task_ref_id', 0));
        $payload = $request->input('payload', []);
        if (!is_array($payload)) {
            $payload = [];
        }
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
        if ($err = $this->assertNodeAccountRow($account)) {
            return $err;
        }
        try {
            $out = AccountWorkBatchService::enqueueItem(
                $accountId,
                $taskType,
                $payload,
                $taskRefId,
                $this->writeOwnerId()
            );
            return $this->success($out, '已挂入账号会话总任务');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    private function ensureTikTokTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_profiles (
              account_id INT PRIMARY KEY,
              handle VARCHAR(191) NOT NULL DEFAULT '',
              display_name VARCHAR(255) NOT NULL DEFAULT '',
              bio TEXT NULL,
              avatar_url TEXT NULL,
              region VARCHAR(64) NOT NULL DEFAULT '',
              followers_count INT NOT NULL DEFAULT 0,
              following_count INT NOT NULL DEFAULT 0,
              likes_count INT NOT NULL DEFAULT 0,
              videos_count INT NOT NULL DEFAULT 0,
              sync_status VARCHAR(32) NOT NULL DEFAULT '',
              sync_error TEXT NULL,
              last_synced_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // 旧表可能缺少 region 等字段，CREATE IF NOT EXISTS 不会补列
        $cols = array_column(DB::select('SHOW COLUMNS FROM tiktok_profiles') ?: [], 'Field');
        $alters = [
            'region' => "ALTER TABLE tiktok_profiles ADD COLUMN region VARCHAR(64) NOT NULL DEFAULT ''",
            'sync_status' => "ALTER TABLE tiktok_profiles ADD COLUMN sync_status VARCHAR(32) NOT NULL DEFAULT ''",
            'sync_error' => 'ALTER TABLE tiktok_profiles ADD COLUMN sync_error TEXT NULL',
            'last_synced_at' => 'ALTER TABLE tiktok_profiles ADD COLUMN last_synced_at DATETIME NULL',
            'followers_count' => 'ALTER TABLE tiktok_profiles ADD COLUMN followers_count INT NOT NULL DEFAULT 0',
            'following_count' => 'ALTER TABLE tiktok_profiles ADD COLUMN following_count INT NOT NULL DEFAULT 0',
            'likes_count' => 'ALTER TABLE tiktok_profiles ADD COLUMN likes_count INT NOT NULL DEFAULT 0',
            'videos_count' => 'ALTER TABLE tiktok_profiles ADD COLUMN videos_count INT NOT NULL DEFAULT 0',
            'bio' => 'ALTER TABLE tiktok_profiles ADD COLUMN bio TEXT NULL',
            'avatar_url' => 'ALTER TABLE tiktok_profiles ADD COLUMN avatar_url TEXT NULL',
            'display_name' => "ALTER TABLE tiktok_profiles ADD COLUMN display_name VARCHAR(255) NOT NULL DEFAULT ''",
            'handle' => "ALTER TABLE tiktok_profiles ADD COLUMN handle VARCHAR(191) NOT NULL DEFAULT ''",
        ];
        foreach ($alters as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e) {
                }
            }
        }

        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_videos (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              video_id VARCHAR(191) NOT NULL,
              video_url TEXT NULL,
              description TEXT NULL,
              thumbnail_url TEXT NULL,
              last_synced_at DATETIME NULL,
              UNIQUE KEY uk_tt_video (account_id, video_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_stat_snapshots (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              followers_count INT NOT NULL DEFAULT 0,
              following_count INT NOT NULL DEFAULT 0,
              likes_count INT NOT NULL DEFAULT 0,
              videos_count INT NOT NULL DEFAULT 0,
              captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_tt_stat_acc (account_id, captured_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_analytics_28d (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              metric_date DATE NOT NULL,
              video_views BIGINT NOT NULL DEFAULT 0,
              profile_views BIGINT NOT NULL DEFAULT 0,
              likes_count BIGINT NOT NULL DEFAULT 0,
              comments_count BIGINT NOT NULL DEFAULT 0,
              shares_count BIGINT NOT NULL DEFAULT 0,
              est_rewards DECIMAL(14,4) NOT NULL DEFAULT 0,
              pulled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uk_tt_analytics_acc_date (account_id, metric_date),
              KEY idx_tt_analytics_acc (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Studio 四个页签（Overview / Content / Followers / Viewer）
        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_studio_tabs (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              tab_key VARCHAR(64) NOT NULL,
              tab_label VARCHAR(191) NOT NULL DEFAULT '',
              page_url TEXT NULL,
              metric_count INT NOT NULL DEFAULT 0,
              raw_text MEDIUMTEXT NULL,
              captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uk_tt_studio_tab (account_id, tab_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_studio_metrics (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              tab_key VARCHAR(64) NOT NULL,
              metric_key VARCHAR(96) NOT NULL,
              metric_label VARCHAR(191) NOT NULL DEFAULT '',
              value_num BIGINT NOT NULL DEFAULT 0,
              value_text VARCHAR(64) NOT NULL DEFAULT '',
              captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uk_tt_studio_metric (account_id, tab_key, metric_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_studio_content (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              row_no INT NOT NULL DEFAULT 0,
              title VARCHAR(512) NOT NULL DEFAULT '',
              views_num BIGINT NOT NULL DEFAULT 0,
              likes_num BIGINT NOT NULL DEFAULT 0,
              comments_num BIGINT NOT NULL DEFAULT 0,
              shares_num BIGINT NOT NULL DEFAULT 0,
              posted_at VARCHAR(64) NOT NULL DEFAULT '',
              captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_tt_studio_content_acc (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_studio_sync (
              account_id INT PRIMARY KEY,
              page_url TEXT NULL,
              tab_count INT NOT NULL DEFAULT 0,
              api_count INT NOT NULL DEFAULT 0,
              status VARCHAR(32) NOT NULL DEFAULT 'ok',
              message TEXT NULL,
              meta_json MEDIUMTEXT NULL,
              captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_studio_api (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              url TEXT NULL,
              payload MEDIUMTEXT NULL,
              captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_tt_studio_api_acc (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Studio 周期汇总字段（挂在 profile 上）
        $cols = array_column(DB::select('SHOW COLUMNS FROM tiktok_profiles') ?: [], 'Field');
        $extra = [
            'comments_count' => 'ALTER TABLE tiktok_profiles ADD COLUMN comments_count INT NOT NULL DEFAULT 0',
            'shares_count' => 'ALTER TABLE tiktok_profiles ADD COLUMN shares_count INT NOT NULL DEFAULT 0',
            'period_video_views' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_video_views BIGINT NOT NULL DEFAULT 0',
            'period_profile_views' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_profile_views BIGINT NOT NULL DEFAULT 0',
            'period_likes' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_likes BIGINT NOT NULL DEFAULT 0',
            'period_comments' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_comments BIGINT NOT NULL DEFAULT 0',
            'period_shares' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_shares BIGINT NOT NULL DEFAULT 0',
            'period_est_rewards' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_est_rewards DECIMAL(14,4) NOT NULL DEFAULT 0',
        ];
        foreach ($extra as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    /**
     * Node 养号：用提示词 + 视频变量生成英文评论
     */
    public function warmAiComment(Request $request): Response
    {
        $prompt = trim((string)$request->input('prompt', $request->input('aiPrompt', '')));
        $templateId = (int)$request->input('templateId', $request->input('template_id', 0));
        $variables = $request->input('variables', []);
        if (!is_array($variables)) {
            $variables = [];
        }
        if ($prompt === '' && $templateId > 0) {
            $row = DB::selectOne(
                "SELECT content_mode, content, ai_prompt FROM interaction_templates
                 WHERE id=? AND type='comment' AND enabled=1",
                [$templateId]
            );
            $mode = strtolower(trim((string)($row['content_mode'] ?? '')));
            if ($mode === '' || $mode === 'ai') {
                $prompt = trim((string)($row['content'] ?? ''));
                if ($prompt === '') {
                    $prompt = trim((string)($row['ai_prompt'] ?? ''));
                }
            }
        }
        if ($prompt === '') {
            $prompt = WarmCommentService::defaultAiPrompt();
        }
        try {
            $result = WarmCommentService::generateEnglishComment($prompt, $variables);
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('生成评论失败：' . $e->getMessage(), 500);
        }
    }

    /** Node：回复评论 AI 文案 */
    public function commentReplyAi(Request $request): Response
    {
        $prompt = trim((string)$request->input('prompt', $request->input('aiPrompt', $request->input('replyAiPrompt', ''))));
        $templateId = (int)$request->input('templateId', $request->input('template_id', 0));
        $variables = $request->input('variables', []);
        if (!is_array($variables)) {
            $variables = [];
        }
        if ($prompt === '' && $templateId > 0) {
            $row = DB::selectOne(
                "SELECT content_mode, content, ai_prompt FROM interaction_templates
                 WHERE id=? AND type='comment_reply' AND enabled=1",
                [$templateId]
            );
            $mode = strtolower(trim((string)($row['content_mode'] ?? '')));
            if ($mode === '' || $mode === 'ai') {
                $prompt = trim((string)($row['content'] ?? ''));
                if ($prompt === '') {
                    $prompt = trim((string)($row['ai_prompt'] ?? ''));
                }
            }
        }
        if ($prompt === '') {
            $prompt = CommentReplyService::defaultAiPrompt();
        }
        try {
            $result = CommentReplyService::generateReply($prompt, $variables);
            $result['comment'] = $result['reply'] ?? '';
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('生成回复失败：' . $e->getMessage(), 500);
        }
    }

    /** Node AI拓客：入库 TK 用户 */
    public function aiLeadUpsertCreator(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AiLeadExecutionService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }
        try {
            $data = $request->all();
            $data['accountId'] = (int)($data['accountId'] ?? $data['account_id'] ?? 0);
            $out = AiLeadExecutionService::upsertCreator((int)$id, $data);
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Node AI拓客：入库视频 */
    public function aiLeadUpsertVideo(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AiLeadExecutionService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }
        try {
            $out = AiLeadExecutionService::upsertVideo((int)$id, $request->all());
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Node AI拓客：批量入库评论（AI 命中） */
    public function aiLeadSaveComments(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AiLeadExecutionService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }
        $videoId = (int)$request->input('videoId', $request->input('video_id', 0));
        $items = $request->input('comments', $request->input('items', []));
        if (!is_array($items)) {
            $items = [];
        }
        $aiPass = (bool)$request->input('aiPass', $request->input('ai_pass', true));
        try {
            $out = AiLeadExecutionService::saveComments((int)$id, $videoId, $items, $aiPass);
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Node AI拓客：更新执行进度 */
    public function aiLeadProgress(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AiLeadExecutionService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }
        $progress = $request->input('progress', []);
        $stats = $request->input('stats');
        if (!is_array($progress)) {
            $progress = [];
        }
        $probe = AiLeadExecutionService::updateProgress((int)$id, $progress, is_array($stats) ? $stats : null);
        return $this->success([
            'id' => (int)$id,
            'aborted' => !empty($probe['aborted']),
            'reason' => (string)($probe['reason'] ?? ''),
            'status' => (string)($probe['status'] ?? ''),
            'runStatus' => (string)($probe['run_status'] ?? ''),
        ]);
    }

    /** Node AI拓客：执行完成 */
    public function aiLeadFinish(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AiLeadExecutionService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }
        $success = (bool)$request->input('success', true);
        $result = $request->input('result', []);
        if (!is_array($result)) {
            $result = [];
        }
        AiLeadExecutionService::markRunFinished((int)$id, $success, $result);
        return $this->success(['id' => (int)$id]);
    }

    /** Node AI拓客：意向评论私信计划（目标用户 + 账号今日剩余额度） */
    public function aiLeadDmPlan(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AiLeadExecutionService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }
        try {
            $out = AiLeadExecutionService::buildDmPlan((int)$id);
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Node：拉取视频数据任务开始 */
    public function pullCommentStart(Request $request, string $id): Response
    {
        PullCommentService::ensureSchema();
        $task = PullCommentService::getTask((int)$id);
        if (!$task) {
            return $this->error('拉取视频数据任务不存在', 404);
        }
        PullCommentService::markRunning((int)$id);
        return $this->success(PullCommentService::getTask((int)$id));
    }

    /** Node：回写单个视频的互动与评论 */
    public function pullCommentIngest(Request $request, string $id): Response
    {
        PullCommentService::ensureSchema();
        try {
            $out = PullCommentService::ingestVideoResult((int)$id, $request->all());
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Node：拉取视频数据任务结束 */
    public function pullCommentFinish(Request $request, string $id): Response
    {
        PullCommentService::ensureSchema();
        $success = (bool)$request->input('success', true);
        $out = PullCommentService::finishTask((int)$id, $success, $request->all());
        if (!$out) {
            return $this->error('拉取视频数据任务不存在', 404);
        }
        return $this->success($out);
    }

    /** Node：自动增粉明细完成（写关注关系 + 更新计数） */
    public function autoFollowItemFinish(Request $request, string $id): Response
    {
        AutoFollowService::ensureSchema();
        try {
            $out = AutoFollowService::finishItem((int)$id, $request->all());
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Node AI拓客：发送前检查该用户是否已经成功私信过 */
    public function aiLeadDmAlreadySent(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AiLeadExecutionService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }
        $username = (string)$request->input('username', '');
        $uuid = (string)$request->input('uuid', '');
        return $this->success(AiLeadExecutionService::dmAlreadySent($username, $uuid));
    }

    /** Node AI拓客：回写私信结果（成功则计当日次数，上限 3） */
    public function aiLeadDmRecord(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AiLeadExecutionService::ensureSchema();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }
        try {
            $out = AiLeadExecutionService::recordDm((int)$id, $request->all());
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Node AI拓客：AI 筛选评论 */
    public function aiLeadFilterComments(Request $request): Response
    {
        $comments = $request->input('comments', []);
        if (!is_array($comments)) {
            $comments = [];
        }
        $prompt = trim((string)$request->input('prompt', $request->input('filterPrompt', '')));
        try {
            $matched = AiLeadFilterService::filterComments($comments, $prompt !== '' ? $prompt : null);
            return $this->success(['comments' => $matched, 'count' => count($matched)]);
        } catch (\Throwable $e) {
            return $this->error('AI 筛选失败：' . $e->getMessage(), 500);
        }
    }

    /** Node AI拓客：根据评论生成合规私信开场白 */
    public function aiLeadGenerateDm(Request $request): Response
    {
        $target = [
            'username' => (string)$request->input('username', ''),
            'nickname' => (string)$request->input('nickname', ''),
            'comment_text' => (string)$request->input('commentText', $request->input('comment_text', '')),
            'product' => (string)$request->input('product', $request->input('productName', '')),
        ];
        $prompt = trim((string)$request->input('prompt', ''));
        try {
            $out = AiLeadFilterService::generateDmOpener($target, $prompt !== '' ? $prompt : null);
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->error('私信开场白生成失败：' . $e->getMessage(), 500);
        }
    }

    private function ensureDiagnosisTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        DB::query(
            "CREATE TABLE IF NOT EXISTS account_diagnosis_records (
              id INT AUTO_INCREMENT PRIMARY KEY,
              account_id INT NOT NULL,
              screenshot_path VARCHAR(512) NOT NULL,
              label VARCHAR(64) NOT NULL DEFAULT 'account-check',
              source VARCHAR(32) NOT NULL DEFAULT 'manual',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_adr_account_created (account_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function assertAccountRowAccess(?array $row): ?Response
    {
        if (!$row) {
            return $this->error('资源不存在', 404);
        }
        if (!DataScope::isAccountRowVisible($row)) {
            return $this->error('无权访问该资源', 403);
        }
        return null;
    }

    /**
     * 跨机环境包：按 profileId 定位绑定行（与 proxy-bundle 一样按全局事实，不按可见范围挡共用）。
     * @return array{0:?array<string,mixed>,1:?Response}
     */
    private function resolveEnvRowForPack(string $profileId): array
    {
        DataScope::ensureColumns();
        \App\Services\AccountBrowserEnvService::ensureSchema();
        $profileId = trim($profileId);
        if ($profileId === '') {
            return [null, $this->error('缺少 profileId', 422)];
        }
        $env = \App\Services\AccountBrowserEnvService::findByProfileId($profileId);
        if (!$env) {
            return [null, $this->error('环境绑定不存在', 404)];
        }
        return [$env, null];
    }

    /** GET 环境包元数据（是否有包、revision、租约） */
    public function browserProfilePackMeta(Request $request, string $profileId): Response
    {
        [$env, $err] = $this->resolveEnvRowForPack($profileId);
        if ($err) {
            return $err;
        }
        return $this->success(\App\Services\AccountBrowserEnvService::packMeta((string)$env['browser_profile_id']));
    }

    /** GET 下载环境包二进制 */
    public function browserProfilePackDownload(Request $request, string $profileId): Response
    {
        [$env, $err] = $this->resolveEnvRowForPack($profileId);
        if ($err) {
            return $err;
        }
        $meta = \App\Services\AccountBrowserEnvService::packMeta((string)$env['browser_profile_id']);
        if (empty($meta['hasPack'])) {
            return $this->error('服务器尚无该环境包，请先在有数据的机器上打开并关闭一次以完成首传', 404);
        }
        $path = trim((string)($env['pack_path'] ?? ''));
        if ($path === '' || !is_file($path)) {
            return $this->error('环境包文件缺失', 404);
        }
        $name = basename($path) ?: ((string)$env['browser_profile_id'] . '.tgz');
        return Response::file($path, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string)filesize($path),
            'X-Pack-Sha256' => (string)($env['pack_sha256'] ?? ''),
            'X-Pack-Revision' => (string)(int)($env['pack_revision'] ?? 0),
        ]);
    }

    /** POST multipart 上传环境包字段 file */
    public function browserProfilePackUpload(Request $request, string $profileId): Response
    {
        [$env, $err] = $this->resolveEnvRowForPack($profileId);
        if ($err) {
            return $err;
        }
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->error('请以 multipart 字段 file 上传 .tgz 环境包', 422);
        }
        $file = $_FILES['file'];
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp)) {
            return $this->error('上传临时文件无效', 422);
        }
        if ($size > \App\Services\AccountBrowserEnvService::PACK_MAX_BYTES) {
            return $this->error('环境包超过 500MB 上限', 422);
        }
        $hostId = trim((string)$request->input('hostId', $request->input('host_id', '')));
        $hold = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tk-env-pack-' . bin2hex(random_bytes(8)) . '.tgz';
        if (!@move_uploaded_file($tmp, $hold) && !@copy($tmp, $hold)) {
            return $this->error('接收上传失败', 500);
        }
        $saved = \App\Services\AccountBrowserEnvService::saveUploadedPack(
            (string)$env['browser_profile_id'],
            $hold,
            $hostId
        );
        if (empty($saved['ok'])) {
            @unlink($hold);
            return $this->error((string)($saved['message'] ?? '保存失败'), 422);
        }
        return $this->success($saved['meta'] ?? null, '环境包已上传');
    }

    /** POST 占用跨机独占租约 */
    public function browserProfilePackLease(Request $request, string $profileId): Response
    {
        [$env, $err] = $this->resolveEnvRowForPack($profileId);
        if ($err) {
            return $err;
        }
        $hostId = trim((string)$request->input('hostId', $request->input('host_id', '')));
        $seconds = (int)$request->input('leaseSeconds', $request->input('lease_seconds', 7200));
        $out = \App\Services\AccountBrowserEnvService::acquirePackLease(
            (string)$env['browser_profile_id'],
            $hostId,
            $seconds
        );
        if (empty($out['ok'])) {
            return $this->error((string)($out['message'] ?? '租约失败'), 409, $out['meta'] ?? null);
        }
        return $this->success($out['meta'] ?? null, '已占用环境租约');
    }

    /** POST 释放跨机独占租约 */
    public function browserProfilePackLeaseRelease(Request $request, string $profileId): Response
    {
        [$env, $err] = $this->resolveEnvRowForPack($profileId);
        if ($err) {
            return $err;
        }
        $hostId = trim((string)$request->input('hostId', $request->input('host_id', '')));
        $force = (bool)$request->input('force', false);
        $out = \App\Services\AccountBrowserEnvService::releasePackLease(
            (string)$env['browser_profile_id'],
            $hostId,
            $force
        );
        if (empty($out['ok'])) {
            return $this->error((string)($out['message'] ?? '释放失败'), 409, $out['meta'] ?? null);
        }
        return $this->success($out['meta'] ?? null, '已释放环境租约');
    }

    /**
     * 超管：清空中心库全部环境绑定 + 环境包文件 + 账号环境 ID 列。
     * POST body 需 confirm=WIPE-ALL-ENV-DATA
     */
    public function wipeAllBrowserEnvData(Request $request): Response
    {
        if (!DataScope::isLoginSuper() && !DataScope::isSuper()) {
            return $this->error('仅超级管理员可清空全部环境数据', 403);
        }
        $confirm = trim((string)$request->input('confirm', $request->input('Confirm', '')));
        if ($confirm !== 'WIPE-ALL-ENV-DATA') {
            return $this->error('请传 confirm=WIPE-ALL-ENV-DATA 以确认危险操作', 422);
        }
        try {
            $stats = \App\Services\AccountBrowserEnvService::wipeAllCenterEnvData();
            return $this->success($stats, '中心库环境数据已清空（本机浏览器环境需另行删除）');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage() ?: '清空失败', 500);
        }
    }
}
