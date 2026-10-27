<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiPromptHelper;

/**
 * 养号评论：固定文案池 / AI 提示词生成英文评论
 */
class WarmCommentService
{
    /**
     * 从消息模板表解析养号评论配置（保存任务时固化快照）
     *
     * @param array<string,mixed> $payload
     * @return array{
     *   commentMode:string,
     *   commentTemplateIds:list<int>,
     *   commentAiTemplateId:int,
     *   commentTexts:list<string>,
     *   commentAiPrompt:string,
     *   commentText:string
     * }
     */
    public static function resolveFromPayload(array $payload): array
    {
        $mode = strtolower(trim((string)($payload['commentMode'] ?? 'fixed')));
        if (!in_array($mode, ['fixed', 'ai'], true)) {
            $mode = 'fixed';
        }

        $fixedIds = self::intList($payload['commentTemplateIds'] ?? $payload['comment_template_ids'] ?? []);
        $aiId = (int)($payload['commentAiTemplateId'] ?? $payload['comment_ai_template_id'] ?? 0);

        $texts = [];
        if (is_array($payload['commentTexts'] ?? null)) {
            foreach ($payload['commentTexts'] as $t) {
                $t = trim((string)$t);
                if ($t !== '') {
                    $texts[] = $t;
                }
            }
        }
        $aiPrompt = trim((string)($payload['commentAiPrompt'] ?? $payload['comment_ai_prompt'] ?? ''));

        if ($mode === 'fixed') {
            if (!$texts && $fixedIds) {
                $texts = self::loadFixedTexts($fixedIds);
            }
            if (!$texts) {
                $legacy = trim((string)($payload['commentText'] ?? ''));
                $texts = [$legacy !== '' ? $legacy : WarmExecutionPlan::COMMENT_TEXT];
            }
            $texts = array_values(array_unique($texts));
            return [
                'commentMode' => 'fixed',
                'commentTemplateIds' => $fixedIds,
                'commentAiTemplateId' => 0,
                'commentTexts' => $texts,
                'commentAiPrompt' => '',
                'commentText' => $texts[0] ?? WarmExecutionPlan::COMMENT_TEXT,
            ];
        }

        // AI 模式
        if ($aiPrompt === '' && $aiId > 0) {
            $row = DB::selectOne(
                "SELECT content_mode, content, ai_prompt, fixed_text FROM interaction_templates
                 WHERE id=? AND type='comment' AND enabled=1",
                [$aiId]
            );
            $mode = strtolower(trim((string)($row['content_mode'] ?? '')));
            if ($mode === 'ai' || $mode === '') {
                $aiPrompt = trim((string)($row['content'] ?? ''));
                if ($aiPrompt === '') {
                    $aiPrompt = trim((string)($row['ai_prompt'] ?? ''));
                }
            }
        }
        if ($aiPrompt === '') {
            $aiPrompt = self::defaultAiPrompt();
        }
        return [
            'commentMode' => 'ai',
            'commentTemplateIds' => [],
            'commentAiTemplateId' => $aiId,
            'commentTexts' => [],
            'commentAiPrompt' => $aiPrompt,
            'commentText' => WarmExecutionPlan::COMMENT_TEXT,
        ];
    }

    /**
     * 用提示词 + 视频变量生成英文评论
     *
     * @param array<string,mixed> $variables
     * @return array{comment:string,prompt:string,model:?string}
     */
    public static function generateEnglishComment(string $promptTemplate, array $variables = [], array $aiOptions = []): array
    {
        $promptTemplate = trim($promptTemplate);
        if ($promptTemplate === '') {
            $promptTemplate = self::defaultAiPrompt();
        }
        $vars = self::normalizeVideoVars($variables);
        $rendered = AiPromptHelper::render($promptTemplate, $vars, false);
        $system = 'You write short natural TikTok video comments in English only. '
            . 'Output the comment text alone — no quotes, no explanation, no hashtags spam. '
            . 'Keep it 8–40 words, friendly and relevant to the video.';
        $user = $rendered;
        if (!preg_match('/\benglish\b/i', $rendered)) {
            $user .= "\n\nWrite the comment in English only.";
        }
        $user .= "\n\nVideo description: " . ($vars['video_desc'] ?: '(none)')
            . "\nAuthor: " . ($vars['author'] ?: '(unknown)');

        $result = AiClient::chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            array_merge(['operation' => 'warm_comment', 'timeout' => 45], $aiOptions)
        );
        $comment = self::sanitizeComment((string)($result['content'] ?? ''));
        if ($comment === '') {
            throw new \RuntimeException('AI 未返回有效评论');
        }
        return [
            'comment' => $comment,
            'prompt' => $user,
            'model' => isset($result['model']) ? (string)$result['model'] : null,
        ];
    }

    public static function defaultAiPrompt(): string
    {
        return "Based on this TikTok video, write one short natural English comment.\n"
            . "Rules:\n"
            . "1. English only, no quotation marks\n"
            . "2. 8–40 words, casual tone, optional 1 emoji\n"
            . "3. No ads, links, or politics\n"
            . "Video: {{video_desc}}\n"
            . "Author: {{author}}";
    }

    /** @param list<int> $ids @return list<string> */
    private static function loadFixedTexts(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "SELECT id, content_mode, content, fixed_text FROM interaction_templates
             WHERE id IN ({$ph}) AND type='comment' AND enabled=1",
            $ids
        ) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $mode = strtolower(trim((string)($r['content_mode'] ?? 'fixed')));
            if ($mode === 'ai') {
                continue;
            }
            $text = trim((string)($r['content'] ?? ''));
            if ($text === '') {
                $text = trim((string)($r['fixed_text'] ?? ''));
            }
            if ($text !== '') {
                $map[(int)$r['id']] = $text;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if (!empty($map[$id])) {
                $out[] = $map[$id];
            }
        }
        return $out;
    }

    /** @param mixed $raw @return list<int> */
    private static function intList(mixed $raw): array
    {
        if (!is_array($raw)) {
            if ($raw === null || $raw === '') {
                return [];
            }
            $raw = preg_split('/[,，\s]+/', (string)$raw) ?: [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    /** @param array<string,mixed> $variables @return array<string,string> */
    private static function normalizeVideoVars(array $variables): array
    {
        $keys = [
            'video_desc', 'author', 'author_id', 'like_count', 'comment_count',
            'video_url', 'video_id', 'nickname', 'bio', 'comment_text', 'message_text',
        ];
        $out = [];
        foreach ($keys as $k) {
            $v = $variables[$k] ?? $variables[str_replace('_', '', $k)] ?? '';
            $out[$k] = is_scalar($v) ? trim((string)$v) : '';
        }
        // 兼容别名
        if ($out['author'] === '' && $out['nickname'] !== '') {
            $out['author'] = $out['nickname'];
        }
        if ($out['video_desc'] === '' && !empty($variables['desc'])) {
            $out['video_desc'] = trim((string)$variables['desc']);
        }
        return $out;
    }

    private static function sanitizeComment(string $raw): string
    {
        $t = trim($raw);
        $t = preg_replace('/^["\'“”‘’]+|["\'“”‘’]+$/u', '', $t) ?? $t;
        // 去掉常见前缀
        $t = preg_replace('/^(here(?:\'s| is) (?:a |my )?comment[:：]\s*)/i', '', $t) ?? $t;
        $t = trim((string)$t);
        // 只取第一段
        if (preg_match('/^(.+?)(?:\n\n|\r\n\r\n)/u', $t, $m)) {
            $t = trim($m[1]);
        }
        if (mb_strlen($t) > 220) {
            $t = mb_substr($t, 0, 220);
        }
        return trim($t);
    }
}
