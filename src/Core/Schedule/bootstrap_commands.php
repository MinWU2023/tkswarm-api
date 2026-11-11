<?php
declare(strict_types=1);

use App\Core\Schedule\Scheduler;

/**
 * 注册全部 artisan / schedule 命令（artisan 与 schedule.php 共用）
 */
function tkswarm_register_commands(Scheduler $scheduler): Scheduler
{
    $scheduler->register(new App\Commands\TaskRunnerCommand());
    $scheduler->register(new App\Commands\ProxyProbeCommand());
    $scheduler->register(new App\Commands\DmSyncCommand());
    $scheduler->register(new App\Commands\WarmRecurringCommand());
    $scheduler->register(new App\Commands\AutoFollowDispatchCommand());
    $scheduler->register(new App\Commands\VideoBoostDispatchCommand());
    $scheduler->register(new App\Commands\AiLeadDailyQuotaCommand());
    $scheduler->register(new App\Commands\AiLeadDmDispatchCommand());
    $scheduler->register(new App\Commands\AiLeadBackfillCommand());
    $scheduler->register(new App\Commands\AiLeadSyncStatsCommand());
    $scheduler->register(new App\Commands\PullDataAnalysisCommand());
    $scheduler->register(new App\Commands\EnvPackHealCommand());
    $scheduler->register(new App\Commands\NocaptchaDailyResetCommand());
    $scheduler->register(new App\Commands\VideoDeliveryAutoConfirmCommand());
    $scheduler->register(new App\Commands\YouTubePublishDispatchCommand());
    $scheduler->register(new App\Commands\YouTubeCommentModerateCommand());
    $scheduler->register(new App\Commands\YouTubeTokenRefreshCommand());
    $scheduler->register(new App\Commands\ScheduleRunCommand());
    return $scheduler;
}
