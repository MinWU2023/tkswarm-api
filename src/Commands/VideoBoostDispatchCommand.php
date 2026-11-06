<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\VideoBoostService;

/**
 * 视频增值到期入队（计划任务，每分钟）
 *
 * 用法:
 *   php artisan video-boost:dispatch
 *   php artisan video-boost:dispatch --limit=100
 */
class VideoBoostDispatchCommand extends Command
{
    protected string $signature = 'video-boost:dispatch';
    protected string $description = '扫描到期视频增值明细，推送给创建任务的管理用户的账号会话总任务';

    public function handle(array $args = []): int
    {
        $limit = 50;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
        }

        $this->info("Video-boost dispatch tick (limit={$limit})");
        $result = VideoBoostService::tick($limit);
        $this->info(
            "scanned={$result['scanned']} enqueued={$result['enqueued']} failed={$result['failed']} skipped={$result['skipped']}"
        );
        foreach ($result['items'] as $row) {
            if (!empty($row['ok'])) {
                $this->info(
                    "#{$row['id']} task#{$row['taskId']} → owner={$row['owner_id']} work_item={$row['work_item_id']} batch={$row['batch_id']}"
                );
            } else {
                $this->warn("#{$row['id']} skip: " . ($row['reason'] ?? ''));
            }
        }
        return 0;
    }
}
