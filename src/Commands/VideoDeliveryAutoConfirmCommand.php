<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\VideoDeliveryService;

/**
 * 交付视频超过 3 日未确认则自动确认。
 *
 * 用法:
 *   php artisan video-delivery:auto-confirm
 *
 * crontab 示例（每小时）:
 *   15 * * * * cd /path/to/tkswarm-api && php artisan video-delivery:auto-confirm >> /var/log/tkswarm-vd-auto-confirm.log 2>&1
 */
class VideoDeliveryAutoConfirmCommand extends Command
{
    protected string $signature = 'video-delivery:auto-confirm';
    protected string $description = '视频交付：三日未确认自动确认';

    public function handle(array $args = []): int
    {
        $days = 3;
        foreach ($args as $a) {
            if (str_starts_with((string)$a, '--days=')) {
                $days = max(1, (int)substr((string)$a, 7));
            }
        }
        $this->info('视频交付自动确认（>' . $days . ' 日未确认）');
        $res = VideoDeliveryService::autoConfirmExpired($days);
        $this->info(sprintf('scanned=%d confirmed=%d', $res['scanned'], $res['confirmed']));
        return 0;
    }
}
