<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\WarmRecurringService;

/**
 * 常态化自动养号调度（供 crontab 挂载）
 *
 * 用法:
 *   php artisan warm:recurring
 *   php artisan warm:recurring --limit=100
 *
 * crontab 示例（每小时）:
 *   0 * * * * cd /path/to/tkswarm-api && php artisan warm:recurring >> /var/log/tkswarm-warm-recurring.log 2>&1
 */
class WarmRecurringCommand extends Command
{
    protected string $signature = 'warm:recurring';
    protected string $description = '扫描常态化养号任务：已成功/失败且到期则重新入队并挂到账号总任务';

    public function handle(array $args = []): int
    {
        $limit = 50;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
        }

        $this->info("Warm recurring tick (limit={$limit})");
        $result = WarmRecurringService::tick($limit);
        $this->info(
            "scanned={$result['scanned']} reset={$result['reset']} skipped={$result['skipped']}"
        );
        foreach ($result['items'] as $row) {
            if (!empty($row['ok'])) {
                $this->info(
                    "#{$row['id']} → queued, items={$row['items']}, batches={$row['batches']}, cycle={$row['cycle']}"
                );
            } else {
                $this->warn("#{$row['id']} skip: " . ($row['reason'] ?? ''));
            }
        }
        return 0;
    }
}
