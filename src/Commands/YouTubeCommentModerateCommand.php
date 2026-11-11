<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Services\YouTube\YouTubeCommentModerateService;

/**
 * YouTube 未回复评论自动审核 + AI 回复（计划任务）
 *
 * 用法:
 *   php artisan youtube:comment-moderate
 *   php artisan youtube:comment-moderate --limit=20
 */
class YouTubeCommentModerateCommand extends Command
{
    protected string $signature = 'youtube:comment-moderate';
    protected string $description = '扫描未回复的观众顶层评论：AI 判定恶意则删除，否则 AI 自动回复';

    public function handle(array $args = []): int
    {
        $limit = 10;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
                $limit = (int)$m[1];
            }
        }

        $this->info("YouTube comment moderate tick (limit={$limit})");
        $result = YouTubeCommentModerateService::tick($limit);
        $this->info(
            "scanned={$result['scanned']} deleted={$result['deleted']} replied={$result['replied']} "
            . "failed={$result['failed']} skipped={$result['skipped']}"
        );
        foreach ($result['items'] as $row) {
            $id = (int)($row['id'] ?? 0);
            $action = (string)($row['action'] ?? '');
            if (!empty($row['ok'])) {
                $extra = $action === 'replied'
                    ? (' reply=' . mb_substr((string)($row['reply'] ?? ''), 0, 60))
                    : (' reason=' . mb_substr((string)($row['reason'] ?? ''), 0, 80));
                $this->info("#{$id} {$action}{$extra}");
            } else {
                $this->warn("#{$id} {$action}: " . (string)($row['reason'] ?? ''));
            }
        }
        return 0;
    }
}
