<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\AiLeadDmDispatchService;

/**
 * AI拓客私信常态化入队（与线索抓取解耦）。
 *
 * 用法:
 *   php artisan ai-lead:dm-dispatch
 *   php artisan ai-lead:dm-dispatch --limit=50
 *
 * crontab 示例（每 5 分钟；勿再挂每分钟，避免空扫过频）:
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * cd /path/to/tkswarm-api && php artisan ai-lead:dm-dispatch >> /var/log/tkswarm-ai-lead-dm.log 2>&1
 */
class AiLeadDmDispatchCommand extends Command
{
    protected string $signature = 'ai-lead:dm-dispatch';
    protected string $description = '扫描待发私信并按账号间隔/日额度入队';

    public function handle(array $args = []): int
    {
        $limit = 10;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
        }

        $this->info("AI拓客私信 dispatch tick (limit={$limit})");
        $result = AiLeadDmDispatchService::tick($limit);
        $this->info(
            "scanned={$result['scanned']} enqueued={$result['enqueued']} skipped={$result['skipped']} failed={$result['failed']}"
        );
        foreach ($result['items'] as $row) {
            if (!empty($row['ok'])) {
                $this->info(
                    "account#{$row['accountId']} lead#{$row['leadId']} @{$row['username']} → item={$row['itemId']}"
                );
            } else {
                $this->warn("account#{$row['accountId']} skip: " . ($row['reason'] ?? ''));
            }
        }
        return 0;
    }
}
