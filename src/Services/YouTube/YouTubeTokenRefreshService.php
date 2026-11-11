<?php
declare(strict_types=1);

namespace App\Services\YouTube;

use App\Core\Database\DB;

/**
 * 计划任务：按间隔刷新已授权 YouTube 账号的 access_token。
 *
 * 默认每账号 3 天刷新一次（Google OAuth「测试」应用 refresh_token 约 7 天失效，
 * 3 天比 5 天更有余量；正式发布应用 refresh_token 长期有效，3 天仍可提前发现问题）。
 * 界面手动「刷新 Token」也会写入 token_refreshed_at，从该时刻重新计时。
 */
final class YouTubeTokenRefreshService
{
    public const DEFAULT_INTERVAL_DAYS = 3;

    /**
     * @return array{scanned:int,ok:int,failed:int,skipped:int,intervalDays:int,items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 20, int $intervalDays = self::DEFAULT_INTERVAL_DAYS): array
    {
        YouTubeSchema::ensureSchema();
        YouTubeSchema::ensureAccountTokenRefreshColumn();

        $limit = max(1, min(100, $limit));
        $intervalDays = max(1, min(30, $intervalDays));

        $rows = DB::select(
            "SELECT id, username, nickname, channel_title, token_refreshed_at, last_login_at, token_expires_at
             FROM youtube_accounts
             WHERE TRIM(IFNULL(refresh_token,''))<>''
               AND (
                 token_refreshed_at IS NULL
                 OR token_refreshed_at < DATE_SUB(NOW(), INTERVAL {$intervalDays} DAY)
               )
             ORDER BY COALESCE(token_refreshed_at, '1970-01-01') ASC, id ASC
             LIMIT {$limit}"
        ) ?: [];

        $out = [
            'scanned' => count($rows),
            'ok' => 0,
            'failed' => 0,
            'skipped' => 0,
            'intervalDays' => $intervalDays,
            'items' => [],
        ];

        foreach ($rows as $row) {
            $aid = (int)($row['id'] ?? 0);
            if ($aid <= 0) {
                $out['skipped']++;
                continue;
            }
            $label = (string)($row['channel_title'] ?? $row['nickname'] ?? $row['username'] ?? ('#' . $aid));
            try {
                $acc = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$aid]);
                if (!$acc || trim((string)($acc['refresh_token'] ?? '')) === '') {
                    $out['skipped']++;
                    $out['items'][] = ['id' => $aid, 'ok' => false, 'reason' => 'missing refresh_token'];
                    continue;
                }
                $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
                $svc = new YouTubeApiService($acc);
                $svc->refreshAccessToken();
                // 顺带刷新频道基础信息（失败不影响 token 成功）
                try {
                    $svc->getMineChannel();
                } catch (\Throwable $e) {
                    // ignore channel sync errors
                }
                $fresh = DB::selectOne(
                    'SELECT token_refreshed_at, token_expires_at FROM youtube_accounts WHERE id=?',
                    [$aid]
                );
                $out['ok']++;
                $out['items'][] = [
                    'id' => $aid,
                    'ok' => true,
                    'label' => $label,
                    'tokenRefreshedAt' => (string)($fresh['token_refreshed_at'] ?? ''),
                    'tokenExpiresAt' => (string)($fresh['token_expires_at'] ?? ''),
                ];
            } catch (\Throwable $e) {
                $out['failed']++;
                $out['items'][] = [
                    'id' => $aid,
                    'ok' => false,
                    'label' => $label,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $out;
    }
}
