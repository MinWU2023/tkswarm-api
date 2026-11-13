<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\AutoFollowDispatchService;

/**
 * 自动增粉到期入队（供 crontab 挂载）
 *
 * 用法:
 *   php artisan auto-follow:dispatch
 *   php artisan auto-follow:dispatch --limit=100
 *
 * crontab 示例（每分钟）:
 *   * * * * * cd /path/to/tkswarm-api && php artisan auto-follow:dispatch >> /var/log/tkswarm-auto-follow.log 2>&1
 */
class AutoFollowDispatchCommand extends Command
{
    protected string $signature = 'auto-follow:dispatch';
    protected string $description = '扫描到期自动增粉明细并挂到账号会话总任务';

    public function handle(array $args = []): int
    {
        $limit = 50;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
        }

        $this->info("Auto-follow dispatch tick (limit={$limit})");
        $this->info(
            '说明：本命令只把到期明细挂到矩阵(account_work)；真正开浏览器推送由本机 Node 领取执行。'
        );
        $result = AutoFollowDispatchService::tick($limit);
        $this->info(
            "scanned={$result['scanned']} enqueued={$result['enqueued']} failed={$result['failed']} skipped={$result['skipped']}"
        );
        foreach ($result['items'] as $row) {
            if (!empty($row['ok'])) {
                $this->info(
                    "#{$row['id']} task#{$row['taskId']} → work_item={$row['work_item_id']} batch={$row['batch_id']}"
                );
            } else {
                $this->warn("#{$row['id']} skip: " . ($row['reason'] ?? ''));
            }
        }
        $stuck = $result['stuck'] ?? [];
        if ($stuck) {
            $this->warn('已入队未完成明细 ' . count($stuck) . ' 条（需 Node 领取；若无 idle 环境请先释放浏览器）：');
            foreach ($stuck as $s) {
                $this->warn(
                    "  item#{$s['id']} task#{$s['taskId']} acc#{$s['accountId']} "
                    . "work={$s['workStatus']} batch#{$s['batchId']}/{$s['batchStatus']} "
                    . "idle={$s['idleEnvs']} → {$s['hint']}"
                );
            }
        }
        return 0;
    }
}
