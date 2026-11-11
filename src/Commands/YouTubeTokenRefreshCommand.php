<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\YouTube\YouTubeTokenRefreshService;

/**
 * YouTube 账号 Token 定时刷新（计划任务）
 *
 * 用法:
 *   php artisan youtube:token-refresh
 *   php artisan youtube:token-refresh --limit=20 --days=3
 */
class YouTubeTokenRefreshCommand extends Command
{
    protected string $signature = 'youtube:token-refresh';
    protected string $description = '按间隔自动刷新已授权 YouTube 账号 access_token（默认每账号 3 天）';

    public function handle(array $args = []): int
    {
        $limit = 20;
        $days = YouTubeTokenRefreshService::DEFAULT_INTERVAL_DAYS;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
            if (preg_match('/^--days=(\d+)$/', (string)$arg, $m)) {
                $days = (int)$m[1];
            }
        }

        $this->info("YouTube token refresh tick (limit={$limit}, days={$days})");
        $result = YouTubeTokenRefreshService::tick($limit, $days);
        $this->info(
            "scanned={$result['scanned']} ok={$result['ok']} failed={$result['failed']} skipped={$result['skipped']}"
        );
        foreach ($result['items'] as $row) {
            $id = (int)($row['id'] ?? 0);
            if (!empty($row['ok'])) {
                $this->info(
                    "#{$id} ok " . (string)($row['label'] ?? '')
                    . ' refreshed=' . (string)($row['tokenRefreshedAt'] ?? '')
                );
            } else {
                $this->warn("#{$id} fail: " . (string)($row['reason'] ?? ''));
            }
        }
        return 0;
    }
}
