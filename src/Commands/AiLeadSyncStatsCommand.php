<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\AiLeadExecutionService;

/**
 * 刷新拓客任务 stats_json 中的私信进度（待发/已发）。
 *
 * 用法:
 *   php artisan ai-lead:sync-stats
 *   php artisan ai-lead:sync-stats --limit=500
 */
class AiLeadSyncStatsCommand extends Command
{
    protected string $signature = 'ai-lead:sync-stats';
    protected string $description = '刷新 active/completed 拓客任务的私信进度统计';

    public function handle(array $args = []): int
    {
        $limit = 200;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
        }
        $this->info("AI拓客私信进度同步 (limit={$limit})");
        $result = AiLeadExecutionService::syncAllLeadDmStats($limit);
        $this->info("updated={$result['updated']}");
        return 0;
    }
}
