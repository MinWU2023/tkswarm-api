<?php
declare(strict_types=1);

namespace App\Commands;

use App\Controllers\Api\NocaptchaKeyController;
use App\Core\Schedule\Command;
use App\Services\AccountBanService;

/**
 * 到期封禁自动恢复 + 少量未判定违规通知 AI 复核（与 Node maintenance/restore-bans 同逻辑）
 *
 * 用法:
 *   php artisan ban:restore
 *   php artisan ban:restore --assess-limit=3
 */
class BanRestoreCommand extends Command
{
    protected string $signature = 'ban:restore';
    protected string $description = '到解封时间自动恢复账号；顺带 AI 复核未判定违规通知';

    public function handle(array $args = []): int
    {
        $assessLimit = 3;
        foreach ($args as $arg) {
            if (preg_match('/^--assess-limit=(\d+)$/', (string)$arg, $m)) {
                $assessLimit = max(0, (int)$m[1]);
            }
        }

        try {
            NocaptchaKeyController::ensureDailyState();
        } catch (\Throwable $e) {
            $this->warn('NoCaptcha 日态维护跳过：' . $e->getMessage());
        }

        $restored = AccountBanService::restoreExpired();
        $assessed = 0;
        $banned = 0;
        if ($assessLimit > 0) {
            foreach (AccountBanService::pendingAssessAccountIds($assessLimit) as $aid) {
                $r = AccountBanService::assess((int)$aid);
                $assessed++;
                if (!empty($r['is_banned'])) {
                    $banned++;
                }
            }
        }

        $this->info("restored={$restored} assessed={$assessed} banned={$banned}");
        return 0;
    }
}
