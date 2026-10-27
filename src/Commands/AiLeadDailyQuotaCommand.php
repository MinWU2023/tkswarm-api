<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\AiLeadVideoWeightService;

/**
 * 按账号视频激活天数刷新当日私信额度。
 *
 * 用法:
 *   php artisan ai-lead:daily-quota
 *
 * crontab 示例（每日 0:05）:
 *   5 0 * * * cd /path/to/tkswarm-api && php artisan ai-lead:daily-quota >> /var/log/tkswarm-ai-lead-quota.log 2>&1
 */
class AiLeadDailyQuotaCommand extends Command
{
    protected string $signature = 'ai-lead:daily-quota';
    protected string $description = '按视频激活天数刷新账号日私信额度';

    public function handle(array $args = []): int
    {
        $this->info('AI拓客日额度刷新');
        $result = AiLeadVideoWeightService::refreshAllDailyQuotas();
        $this->info("date={$result['date']} updated={$result['updated']}");
        return 0;
    }
}
