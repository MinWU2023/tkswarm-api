<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\AiLeadVideoWeightService;

/**
 * 从已发布素材回填账号首次发视频时间。
 *
 * 用法:
 *   php artisan ai-lead:backfill-video-activation
 *   php artisan ai-lead:backfill-video-activation --limit=1000
 */
class AiLeadBackfillCommand extends Command
{
    protected string $signature = 'ai-lead:backfill-video-activation';
    protected string $description = '从 materials 回填 accounts.first_video_published_at';

    public function handle(array $args = []): int
    {
        $limit = 500;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
        }
        $this->info("AI拓客首次发视频时间回填 (limit={$limit})");
        $result = AiLeadVideoWeightService::backfillFirstVideoPublished($limit);
        $this->info("scanned={$result['scanned']} updated={$result['updated']}");
        return 0;
    }
}
