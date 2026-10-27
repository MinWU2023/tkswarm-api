<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 养号执行计划：保存/挂载时按概率固化具体动作，供 Node 比特脚本按表执行。
 *
 * 规则：
 * - videoTotal ∈ [min, max]
 * - 按关键词均分视频数，余数分给靠前的关键词（各 +1）
 * - 每个关键词内按 like/comment/... 概率算出次数，再随机落到具体视频序号
 * - 每条视频独立随机停留秒数
 */
class WarmExecutionPlan
{
    /** 固定评论文案 */
    public const COMMENT_TEXT = '很棒的视频，点赞';

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function build(array $payload): array
    {
        $terms = self::splitTerms($payload['searchTerms'] ?? '');
        $commentCfg = WarmCommentService::resolveFromPayload($payload);
        if (!$terms) {
            return array_merge([
                'videoTotal' => 0,
                'keywords' => [],
            ], $commentCfg);
        }

        $minV = max(1, min(100, (int)($payload['videoCountMin'] ?? 10)));
        $maxV = max($minV, min(100, (int)($payload['videoCountMax'] ?? $minV)));
        $stayMin = max(1, min(300, (int)($payload['staySecondsMin'] ?? 10)));
        $stayMax = max($stayMin, min(300, (int)($payload['staySecondsMax'] ?? 20)));

        $likeRate = self::clampRate($payload['likeRate'] ?? 0);
        // 评论 = 发评论；优先 commentRate，兼容旧字段 viewCommentRate
        $commentRate = self::clampRate($payload['commentRate'] ?? $payload['viewCommentRate'] ?? 0);
        $followRate = self::clampRate($payload['followRate'] ?? 0);
        $favoriteRate = self::clampRate($payload['favoriteRate'] ?? 0);
        $viewCommentRate = self::clampRate($payload['viewCommentRate'] ?? 0);
        $commentLikeRate = self::clampRate($payload['commentLikeRate'] ?? 0);

        $videoTotal = random_int($minV, $maxV);
        $n = count($terms);
        $base = intdiv($videoTotal, $n);
        $rem = $videoTotal % $n;

        $keywords = [];
        foreach ($terms as $i => $term) {
            $count = $base + ($i < $rem ? 1 : 0);
            $videos = [];
            if ($count > 0) {
                $likeIdx = self::pickIndices($count, self::countByRate($count, $likeRate));
                $commentIdx = self::pickIndices($count, self::countByRate($count, $commentRate));
                $followIdx = self::pickIndices($count, self::countByRate($count, $followRate));
                $favoriteIdx = self::pickIndices($count, self::countByRate($count, $favoriteRate));
                $viewCommentIdx = self::pickIndices($count, self::countByRate($count, $viewCommentRate));
                $commentLikeIdx = self::pickIndices($count, self::countByRate($count, $commentLikeRate));

                for ($j = 0; $j < $count; $j++) {
                    $doComment = isset($commentIdx[$j]);
                    $videos[] = [
                        'index' => $j,
                        'staySeconds' => random_int($stayMin, $stayMax),
                        'like' => isset($likeIdx[$j]),
                        'comment' => $doComment,
                        'follow' => isset($followIdx[$j]),
                        'favorite' => isset($favoriteIdx[$j]),
                        // 发评论时必然打开评论区
                        'viewComments' => $doComment || isset($viewCommentIdx[$j]),
                        'commentLike' => isset($commentLikeIdx[$j]),
                    ];
                }
            }
            // 强制连续下标，避免 JSON 编成对象导致前端/Node 只读到部分条
            $videos = array_values($videos);
            $keywords[] = [
                'term' => $term,
                'videoCount' => count($videos),
                'likeCount' => count(array_filter($videos, static fn($v) => !empty($v['like']))),
                'commentCount' => count(array_filter($videos, static fn($v) => !empty($v['comment']))),
                'followCount' => count(array_filter($videos, static fn($v) => !empty($v['follow']))),
                'videos' => $videos,
            ];
        }

        return array_merge($commentCfg, [
            'videoTotal' => array_sum(array_map(static fn($k) => (int)($k['videoCount'] ?? 0), $keywords)),
            'generatedAt' => date('c'),
            'keywords' => array_values($keywords),
        ]);
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    public static function splitTerms(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                static fn($x) => trim((string)$x),
                $raw
            )));
        }
        $parts = preg_split('/[\r\n,，;；]+/', (string)$raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts)));
    }

    private static function clampRate(mixed $v): int
    {
        return max(0, min(100, (int)$v));
    }

    private static function countByRate(int $total, int $rate): int
    {
        if ($total < 1 || $rate < 1) {
            return 0;
        }
        return (int)floor($total * $rate / 100);
    }

    /**
     * @return array<int,true> index => true
     */
    private static function pickIndices(int $total, int $need): array
    {
        if ($need < 1 || $total < 1) {
            return [];
        }
        $need = min($need, $total);
        $all = range(0, $total - 1);
        shuffle($all);
        $picked = array_slice($all, 0, $need);
        $map = [];
        foreach ($picked as $idx) {
            $map[(int)$idx] = true;
        }
        return $map;
    }
}
