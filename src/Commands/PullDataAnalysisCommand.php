<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\PlanTask\PullDataAnalysisService;

/**
 * 拉取数据 AI 分析计划任务（供 crontab 挂载）
 *
 * 用法:
 *   php artisan plan-task:pull-analysis
 *   php artisan plan-task:pull-analysis --limit=10
 *   php artisan plan-task:pull-analysis --id=12
 *   php artisan plan-task:pull-analysis --id=12 --force
 *
 * crontab 示例（每分钟）:
 *   * * * * * cd /path/to/tkswarm-api && php artisan plan-task:pull-analysis >> /var/log/tkswarm-pull-analysis.log 2>&1
 */
class PullDataAnalysisCommand extends Command
{
    protected string $signature = 'plan-task:pull-analysis';
    protected string $description = '处理拉取数据成功后的 AI 分析与邮件发送计划任务';

    public function handle(array $args = []): int
    {
        $limit = 5;
        $id = 0;
        $force = false;
        foreach ($args as $arg) {
            $arg = (string)$arg;
            if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
                $limit = (int)$m[1];
            } elseif (preg_match('/^--id=(\d+)$/', $arg, $m)) {
                $id = (int)$m[1];
            } elseif ($arg === '--force') {
                $force = true;
            }
        }

        if ($id > 0) {
            $this->info("Process plan_task #{$id}" . ($force ? ' (force)' : ''));
            $result = PullDataAnalysisService::processOne($id, $force);
            if (!empty($result['ok'])) {
                $this->info("#{$id} status=" . ($result['status'] ?? 'ok'));
                foreach ($result['mails'] ?? [] as $mail) {
                    $flag = !empty($mail['ok']) ? 'OK' : 'FAIL';
                    $this->info("  mail {$flag} {$mail['email']}" . (!empty($mail['error']) ? ' · ' . $mail['error'] : ''));
                }
                return 0;
            }
            $this->error("#{$id} failed: " . ($result['error'] ?? 'unknown'));
            return 1;
        }

        $this->info("Pull-data analysis tick (limit={$limit})");
        $result = PullDataAnalysisService::tick($limit);
        $this->info(
            "scanned={$result['scanned']} processed={$result['processed']} "
            . "completed={$result['completed']} failed={$result['failed']} skipped={$result['skipped']}"
        );
        foreach ($result['items'] as $row) {
            $tid = (int)($row['id'] ?? 0);
            if (!empty($row['ok'])) {
                $this->info("#{$tid} " . ($row['status'] ?? 'ok'));
            } else {
                $this->warn("#{$tid} fail: " . ($row['error'] ?? ''));
            }
        }
        return 0;
    }
}
