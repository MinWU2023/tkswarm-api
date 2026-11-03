<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\Schedule\ScheduleRunner;

/**
 * 统一计划任务调度（与根目录 schedule.php 等价）
 *
 * 用法:
 *   php artisan schedule:run
 *   php artisan schedule:run --job=auto_follow_dispatch
 *   php artisan schedule:run --job=auto_follow_dispatch --force
 *
 * crontab（推荐只挂这一条）:
 *   * * * * * cd /path/to/tkswarm-api && php schedule.php >> /var/log/tkswarm-schedule.log 2>&1
 */
class ScheduleRunCommand extends Command
{
    protected string $signature = 'schedule:run';
    protected string $description = '按 cron 表达式执行已开启的计划任务（统一调度入口）';

    public function handle(array $args = []): int
    {
        $job = '';
        $force = false;
        foreach ($args as $arg) {
            $arg = (string)$arg;
            if (preg_match('/^--job=(.+)$/', $arg, $m)) {
                $job = trim($m[1]);
            } elseif ($arg === '--force') {
                $force = true;
            }
        }

        if ($job !== '') {
            $this->info("schedule run one: {$job}" . ($force ? ' (force)' : ''));
            $result = ScheduleRunner::runOne($job, $force);
            $this->info(
                "{$result['key']} status={$result['status']} ms={$result['durationMs']} msg={$result['message']}"
            );
            return !empty($result['ok']) ? 0 : 1;
        }

        $this->info('schedule tick');
        $pack = ScheduleRunner::tick();
        foreach ($pack['ran'] as $row) {
            $this->info(
                "RAN {$row['key']} status={$row['status']} ms={$row['durationMs']} msg={$row['message']}"
            );
        }
        $skippedDue = 0;
        $skippedOff = 0;
        foreach ($pack['skipped'] as $row) {
            if (($row['reason'] ?? '') === 'disabled') {
                $skippedOff++;
            } else {
                $skippedDue++;
            }
        }
        $this->info(
            'done ran=' . count($pack['ran'])
            . " skipped_not_due={$skippedDue} skipped_disabled={$skippedOff}"
        );
        return 0;
    }
}
