<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiPromptCatalog;
use App\Services\Ai\AiPromptHelper;

/**
 * 图片发布：根据音乐风格标签 + 作品文案，让 AI 从候选音乐列表里挑一首。
 * 提示词来自开发管理 → AI 提示词模板（code=publish_music_pick），可在后台修改。
 */
class PublishMusicPickService
{
    public const PROMPT_CODE = 'publish_music_pick';
    /** 候选最多交给 AI 的条数 */
    public const MAX_CANDIDATES = 60;

    public static function resolvePrompt(): string
    {
        AiPromptCatalog::ensure();
        $row = DB::selectOne(
            'SELECT content FROM ai_prompt_templates WHERE code=? AND enabled=1 LIMIT 1',
            [self::PROMPT_CODE]
        );
        $content = trim((string)($row['content'] ?? ''));
        if ($content !== '') {
            return $content;
        }
        foreach (AiPromptCatalog::definitions() as $t) {
            if (($t['code'] ?? '') === self::PROMPT_CODE) {
                return (string)$t['content'];
            }
        }
        return '';
    }

    /**
     * @param list<array<string,mixed>|string> $candidates 候选音乐（Node 滚动抓取）
     * @param list<string> $styles 风格标签（最多 5 个）
     * @return array{index:int,name:string,reason:string,source:string}
     */
    public static function pick(array $candidates, array $styles, string $title = '', string $description = '', string $keywords = ''): array
    {
        $list = [];
        foreach ($candidates as $i => $c) {
            if (count($list) >= self::MAX_CANDIDATES) {
                break;
            }
            if (is_string($c)) {
                $c = ['name' => $c];
            }
            if (!is_array($c)) {
                continue;
            }
            $name = trim((string)($c['name'] ?? $c['title'] ?? ''));
            if ($name === '') {
                continue;
            }
            $list[] = [
                'index' => isset($c['index']) ? (int)$c['index'] : (int)$i,
                'name' => mb_substr($name, 0, 120),
                'artist' => mb_substr(trim((string)($c['artist'] ?? $c['author'] ?? '')), 0, 80),
                'duration' => trim((string)($c['duration'] ?? '')),
            ];
        }
        if (!$list) {
            throw new \InvalidArgumentException('候选音乐列表为空');
        }
        $styles = array_values(array_filter(array_map(static fn ($s) => trim((string)$s), $styles)));
        $styles = array_slice($styles, 0, 5);

        $fallback = static fn (string $why): array => [
            'index' => (int)$list[0]['index'],
            'name' => (string)$list[0]['name'],
            'reason' => $why,
            'source' => 'fallback',
        ];

        $prompt = self::resolvePrompt();
        if ($prompt === '') {
            return $fallback('未找到提示词模板，默认选第一首');
        }
        $rendered = AiPromptHelper::render($prompt, [
            'styles' => implode(', ', $styles),
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'candidates_json' => json_encode($list, JSON_UNESCAPED_UNICODE),
        ], false);

        try {
            $result = AiClient::chat(
                [
                    ['role' => 'system', 'content' => 'You are a music supervisor for TikTok photo posts. The chosen track MUST be vocal-free background music (instrumental / BGM only): never pick a track with singing, rapping, spoken vocals or lyrics. Output valid JSON object only, no markdown.'],
                    ['role' => 'user', 'content' => $rendered],
                ],
                ['operation' => 'publish_music_pick', 'timeout' => 60]
            );
        } catch (\Throwable $e) {
            return $fallback('AI 调用失败：' . mb_substr($e->getMessage(), 0, 120));
        }

        $obj = self::parseJsonObject((string)($result['content'] ?? ''));
        if (!$obj || !array_key_exists('index', $obj)) {
            return $fallback('AI 返回无法解析，默认选第一首');
        }
        $want = (int)$obj['index'];
        foreach ($list as $row) {
            if ((int)$row['index'] === $want) {
                return [
                    'index' => $want,
                    'name' => (string)$row['name'],
                    'reason' => trim((string)($obj['reason'] ?? '')),
                    'source' => 'ai',
                ];
            }
        }
        return $fallback('AI 返回的序号不在候选中，默认选第一首');
    }

    /** @return array<string,mixed>|null */
    private static function parseJsonObject(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        }
        return is_array($decoded) ? $decoded : null;
    }
}
