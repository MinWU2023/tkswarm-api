<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\EnvPackHealService;

/**
 * 中心库缺环境包 → 推送关联用户静默上传/重建补齐
 *
 * 用法:
 *   php artisan env-pack:heal
 *   php artisan env-pack:heal --limit=50
 *
 * crontab 示例（每 10 分钟）:
 *   every-10-min: cd /path/to/tkswarm-api && php artisan env-pack:heal >> /var/log/tkswarm-env-pack-heal.log 2>&1
 */
class EnvPackHealCommand extends Command
{
    protected string $signature = 'env-pack:heal';
    protected string $description = '扫描库中有绑定但服务器无环境包文件的记录，向关联用户推送静默上传/重建任务';

    public function handle(array $args = []): int
    {
        $limit = 30;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
        }

        $this->info("Env pack heal tick (limit={$limit})");
        $result = EnvPackHealService::tick($limit);
        $this->info(
            "scanned={$result['scanned']} enqueued={$result['enqueued']} "
            . "skipped={$result['skipped']} healed_already={$result['healed_already']}"
        );
        foreach ($result['items'] as $row) {
            $id = (int)($row['id'] ?? 0);
            $pid = (string)($row['profileId'] ?? '');
            $short = $pid !== '' ? substr($pid, 0, 8) . '…' : '-';
            if (!empty($row['ok']) && !empty($row['item_id'])) {
                $this->info(
                    "#{$id} {$short} → batch#{$row['batch_id']} item#{$row['item_id']} owner#{$row['ownerId']}"
                );
            } elseif (!empty($row['ok'])) {
                $this->info("#{$id} {$short} · " . ($row['reason'] ?? 'ok'));
            } else {
                $this->warn("#{$id} {$short} skip: " . ($row['reason'] ?? ''));
            }
        }
        return 0;
    }
}
