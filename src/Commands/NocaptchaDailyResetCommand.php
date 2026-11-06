<?php
declare(strict_types=1);

namespace App\Commands;

use App\Controllers\Api\NocaptchaKeyController;
use App\Core\Schedule\Command;

/**
 * 每日重置 NoCaptchaAI 账号当日 200 次免费额度，并将超过 30 天的账号直接标记报废（永久不可再启用）。
 *
 * 用法:
 *   php artisan nocaptcha:daily-reset
 *
 * crontab 示例（每日 0:01）:
 *   1 0 * * * cd /path/to/tkswarm-api && php artisan nocaptcha:daily-reset >> /var/log/tkswarm-nocaptcha-daily-reset.log 2>&1
 */
class NocaptchaDailyResetCommand extends Command
{
    protected string $signature = 'nocaptcha:daily-reset';
    protected string $description = '重置 NoCaptchaAI 每日 200 次额度并报废超过 30 天账号';

    public function handle(array $args = []): int
    {
        $this->info('NoCaptchaAI 每日额度重置与 30 天报废检查');
        $res = NocaptchaKeyController::runDailyMaintenance(true);
        $this->info(sprintf(
            'date=%s reset=%d scrapped=%d active_id=%d',
            $res['date'],
            $res['resetCount'],
            $res['scrappedCount'],
            $res['activeId']
        ));
        return 0;
    }
}
