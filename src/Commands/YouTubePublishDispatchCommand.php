<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\YouTube\YouTubePublishDispatchService;

/**
 * Youtube 发布任务派发（计划任务）
 *
 * 用法:
 *   php artisan youtube:publish-dispatch
 *   php artisan youtube:publish-dispatch --limit=10
 */
class YouTubePublishDispatchCommand extends Command
{
    protected string $signature = 'youtube:publish-dispatch';
    protected string $description = '扫描 pending 的 Youtube 发布任务并上传（TikTok 素材 → YouTube）';

    public function handle(array $args = []): int
    {
        $limit = 5;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
        }

        $this->info("YouTube publish dispatch tick (limit={$limit})");
        $result = YouTubePublishDispatchService::tick($limit);
        $this->info(
            "scanned={$result['scanned']} ok={$result['ok']} failed={$result['failed']} skipped={$result['skipped']}"
        );
        foreach ($result['items'] as $row) {
            if (!empty($row['ok'])) {
                $this->info("#{$row['id']} ok youtube=" . ($row['youtubeVideoId'] ?? ''));
            } else {
                $this->warn("#{$row['id']} skip/fail: " . ($row['reason'] ?? ''));
            }
        }
        return 0;
    }
}
