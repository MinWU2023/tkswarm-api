<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 自动增粉 · 主页操作计划：为每条明细固化「看几个视频 / 停留 / 是否点赞评论」。
 */
class AutoFollowHomepagePlan
{
    /**
     * @param array<string,mixed> $taskCfg 任务级配置（已规范化）
     * @param array{targetUsername?:string,targetDisplay?:string} $target
     * @return array<string,mixed>
     */
    public static function build(array $taskCfg, array $target = []): array
    {
        $keyword = trim((string)($taskCfg['searchKeyword'] ?? ''));
        if ($keyword === '') {
            $keyword = ltrim(trim((string)($target['targetUsername'] ?? '')), '@');
        }
        if ($keyword === '') {
            $keyword = trim((string)($target['targetDisplay'] ?? ''));
        }

        $watch = max(0, min(20, (int)($taskCfg['watchVideoCount'] ?? 3)));
        $stayMin = max(1, min(300, (int)($taskCfg['staySecondsMin'] ?? 8)));
        $stayMax = max($stayMin, min(300, (int)($taskCfg['staySecondsMax'] ?? 20)));
        $likeRate = self::clampRate($taskCfg['likeRate'] ?? 40);
        $commentRate = self::clampRate($taskCfg['commentRate'] ?? 20);

        $commentCfg = WarmCommentService::resolveFromPayload([
            'commentMode' => $taskCfg['commentMode'] ?? 'ai',
            'commentAiTemplateId' => $taskCfg['commentAiTemplateId'] ?? 0,
            'commentAiPrompt' => $taskCfg['commentAiPrompt'] ?? '',
            'commentText' => $taskCfg['commentText'] ?? '',
            'commentTemplateIds' => $taskCfg['commentTemplateIds'] ?? [],
            'commentTexts' => $taskCfg['commentTexts'] ?? [],
        ]);

        $videos = [];
        for ($i = 0; $i < $watch; $i++) {
            $doLike = self::roll($likeRate);
            $doComment = self::roll($commentRate);
            $videos[] = [
                'index' => $i,
                'staySeconds' => random_int($stayMin, $stayMax),
                'like' => $doLike,
                'comment' => $doComment,
            ];
        }

        return array_merge($commentCfg, [
            'searchKeyword' => mb_substr($keyword, 0, 255),
            'watchVideoCount' => $watch,
            'staySecondsMin' => $stayMin,
            'staySecondsMax' => $stayMax,
            'likeRate' => $likeRate,
            'commentRate' => $commentRate,
            'videos' => $videos,
            'generatedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 规范化任务表单中的主页浏览配置。
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing 编辑时旧行
     * @return array<string,mixed>
     */
    public static function normalizeTaskConfig(array $input, ?array $existing = null): array
    {
        $ex = $existing ?: [];
        $get = static function (array $keys, $default) use ($input, $ex) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $input)) {
                    return $input[$k];
                }
            }
            foreach ($keys as $k) {
                if (is_array($ex) && array_key_exists($k, $ex)) {
                    return $ex[$k];
                }
            }
            return $default;
        };

        $stayMin = max(1, min(300, (int)$get(['staySecondsMin', 'stay_seconds_min'], 8)));
        $stayMax = max($stayMin, min(300, (int)$get(['staySecondsMax', 'stay_seconds_max'], 20)));
        $watch = max(0, min(20, (int)$get(['watchVideoCount', 'watch_video_count'], 3)));
        $likeRate = self::clampRate($get(['likeRate', 'like_rate'], 40));
        $commentRate = self::clampRate($get(['commentRate', 'comment_rate'], 20));
        $mode = strtolower(trim((string)$get(['commentMode', 'comment_mode'], 'ai')));
        if (!in_array($mode, ['fixed', 'ai'], true)) {
            $mode = 'ai';
        }
        $aiId = (int)$get(['commentAiTemplateId', 'comment_ai_template_id'], 0);
        $aiPrompt = trim((string)$get(['commentAiPrompt', 'comment_ai_prompt'], ''));
        $commentText = trim((string)$get(['commentText', 'comment_text'], ''));
        $keyword = trim((string)$get(['searchKeyword', 'search_keyword'], ''));

        $resolved = WarmCommentService::resolveFromPayload([
            'commentMode' => $mode,
            'commentAiTemplateId' => $aiId,
            'commentAiPrompt' => $aiPrompt,
            'commentText' => $commentText,
            'commentTemplateIds' => $input['commentTemplateIds'] ?? $input['comment_template_ids'] ?? [],
            'commentTexts' => $input['commentTexts'] ?? $input['comment_texts'] ?? [],
        ]);

        return [
            'searchKeyword' => mb_substr($keyword, 0, 255),
            'staySecondsMin' => $stayMin,
            'staySecondsMax' => $stayMax,
            'watchVideoCount' => $watch,
            'likeRate' => $likeRate,
            'commentRate' => $commentRate,
            'commentMode' => $resolved['commentMode'],
            'commentAiTemplateId' => (int)$resolved['commentAiTemplateId'],
            'commentAiPrompt' => (string)$resolved['commentAiPrompt'],
            'commentText' => (string)$resolved['commentText'],
            'commentTemplateIds' => $resolved['commentTemplateIds'],
            'commentTexts' => $resolved['commentTexts'],
        ];
    }

    private static function clampRate(mixed $v): int
    {
        return max(0, min(100, (int)$v));
    }

    private static function roll(int $rate): bool
    {
        if ($rate <= 0) {
            return false;
        }
        if ($rate >= 100) {
            return true;
        }
        return random_int(1, 100) <= $rate;
    }
}
