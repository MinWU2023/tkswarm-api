<?php
declare(strict_types=1);

namespace App\Services\Schedule;

/**
 * 代码内登记的计划任务（crontab 语义）；启停状态存 DB。
 */
final class ScheduleRegistry
{
    /**
     * @return list<array{
     *   key:string,title:string,description:string,command:string,
     *   cron:string,args:list<string>,defaultEnabled:bool
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'dm_sync',
                'title' => '私信同步入队',
                'description' => '按账号消息设置间隔入队 dm_sync（Node 执行）',
                'command' => 'dm:sync',
                'cron' => '* * * * *',
                'args' => [],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'auto_follow_dispatch',
                'title' => '增粉到期入队',
                'description' => '扫描到期自动增粉明细并挂到账号会话总任务',
                'command' => 'auto-follow:dispatch',
                'cron' => '* * * * *',
                'args' => ['--limit=50'],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'video_boost_dispatch',
                'title' => '视频增值到期入队',
                'description' => '按明细的具体执行时间，把视频增值任务推送给创建该任务的管理用户的账号会话总任务',
                'command' => 'video-boost:dispatch',
                'cron' => '* * * * *',
                'args' => ['--limit=50'],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'pull_analysis',
                'title' => '拉取数据 AI 分析',
                'description' => '处理拉取数据成功后的 AI 分析与邮件发送',
                'command' => 'plan-task:pull-analysis',
                'cron' => '* * * * *',
                'args' => ['--limit=5'],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'warm_recurring',
                'title' => '常态化养号',
                'description' => '已成功/失败且到期的养号任务重新入队',
                'command' => 'warm:recurring',
                'cron' => '0 * * * *',
                'args' => ['--limit=50'],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'env_pack_heal',
                'title' => '环境包缺文件自愈',
                'description' => '库有绑定但服务器无环境包时，推送关联用户静默上传/重建',
                'command' => 'env-pack:heal',
                'cron' => '*/10 * * * *',
                'args' => ['--limit=30'],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'ai_lead_daily_quota',
                'title' => 'AI拓客日额度刷新',
                'description' => '按账号视频激活天数刷新当日私信额度',
                'command' => 'ai-lead:daily-quota',
                'cron' => '5 0 * * *',
                'args' => [],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'ai_lead_dm_dispatch',
                'title' => 'AI拓客私信入队',
                'description' => '扫描待发私信并按账号间隔/日额度入队',
                'command' => 'ai-lead:dm-dispatch',
                'cron' => '*/5 * * * *',
                'args' => ['--limit=10'],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'nocaptcha_daily_reset',
                'title' => 'NoCaptchaAI日额度重置与超期报废',
                'description' => '每日零点重置未到期 NoCaptchaAI 账号当日 200 次额度，并将超过 30 天的账号永久标记报废（不可再启用）',
                'command' => 'nocaptcha:daily-reset',
                'cron' => '1 0 * * *',
                'args' => [],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'video_delivery_auto_confirm',
                'title' => '视频交付三日自动确认',
                'description' => '交付后超过 3 日客户未确认的视频，自动标记为已确认',
                'command' => 'video-delivery:auto-confirm',
                'cron' => '15 * * * *',
                'args' => ['--days=3'],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'youtube_publish_dispatch',
                'title' => 'Youtube 发布任务上传',
                'description' => '扫描 pending 的 Youtube 发布任务，下载 TikTok 素材并上传到 YouTube',
                'command' => 'youtube:publish-dispatch',
                'cron' => '* * * * *',
                'args' => ['--limit=5'],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'youtube_comment_moderate',
                'title' => 'YouTube 评论自动审核回复',
                'description' => '处理未回复的观众顶层评论：AI 判定恶意低俗辱骂则删除，否则 AI 自动回复',
                'command' => 'youtube:comment-moderate',
                'cron' => '*/5 * * * *',
                'args' => ['--limit=10'],
                'defaultEnabled' => true,
            ],
            [
                'key' => 'youtube_token_refresh',
                'title' => 'YouTube Token 定时刷新',
                'description' => '已授权账号每 3 天自动刷新 access_token（Google 测试应用 refresh_token 约 7 天失效，3 天更稳妥；界面手动刷新也会重置计时）',
                'command' => 'youtube:token-refresh',
                'cron' => '20 * * * *',
                'args' => ['--limit=20', '--days=3'],
                'defaultEnabled' => true,
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function map(): array
    {
        $out = [];
        foreach (self::definitions() as $def) {
            $out[(string)$def['key']] = $def;
        }
        return $out;
    }

    public static function get(string $key): ?array
    {
        return self::map()[$key] ?? null;
    }
}
