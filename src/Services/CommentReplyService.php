<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiPromptHelper;

/**
 * 回复评论：固定文案池 / AI 提示词（interaction_templates.type = comment_reply）
 */
class CommentReplyService
{
    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   replyMode:string,
     *   replyTemplateIds:list<int>,
     *   replyAiTemplateId:int,
     *   replyTexts:list<string>,
     *   replyAiPrompt:string,
     *   replyText:string,
     *   maxReplies:int
     * }
     */
    public static function resolveFromPayload(array $payload): array
    {
        $mode = strtolower(trim((string)($payload['replyMode'] ?? $payload['commentMode'] ?? 'fixed')));
        if (!in_array($mode, ['fixed', 'ai'], true)) {
            $mode = 'fixed';
        }

        $fixedIds = self::intList(
            $payload['replyTemplateIds']
            ?? $payload['commentTemplateIds']
            ?? $payload['reply_template_ids']
            ?? []
        );
        $aiId = (int)($payload['replyAiTemplateId']
            ?? $payload['commentAiTemplateId']
            ?? $payload['reply_ai_template_id']
            ?? 0);
        $maxReplies = max(1, min(50, (int)($payload['maxReplies'] ?? $payload['max_replies'] ?? 10)));

        $texts = [];
        if (is_array($payload['replyTexts'] ?? null)) {
            foreach ($payload['replyTexts'] as $t) {
                $t = trim((string)$t);
                if ($t !== '') {
                    $texts[] = $t;
                }
            }
        }
        if (!$texts && is_array($payload['commentTexts'] ?? null)) {
            foreach ($payload['commentTexts'] as $t) {
                $t = trim((string)$t);
                if ($t !== '') {
                    $texts[] = $t;
                }
            }
        }
        $aiPrompt = trim((string)($payload['replyAiPrompt'] ?? $payload['commentAiPrompt'] ?? ''));

        if ($mode === 'fixed') {
            if (!$texts && $fixedIds) {
                $texts = self::loadFixedTexts($fixedIds);
            }
            if (!$texts) {
                $legacy = trim((string)($payload['replyText'] ?? $payload['commentText'] ?? ''));
                $texts = [$legacy !== '' ? $legacy : 'Thanks for your comment!'];
            }
            $texts = array_values(array_unique($texts));
            return [
                'replyMode' => 'fixed',
                'replyTemplateIds' => $fixedIds,
                'replyAiTemplateId' => 0,
                'replyTexts' => $texts,
                'replyAiPrompt' => '',
                'replyText' => $texts[0] ?? 'Thanks for your comment!',
                'maxReplies' => $maxReplies,
            ];
        }

        if ($aiPrompt === '' && $aiId > 0) {
            $row = DB::selectOne(
                "SELECT content_mode, content, ai_prompt, fixed_text FROM interaction_templates
                 WHERE id=? AND type='comment_reply' AND enabled=1",
                [$aiId]
            );
            $rowMode = strtolower(trim((string)($row['content_mode'] ?? '')));
            if ($rowMode === 'ai' || $rowMode === '') {
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
            'replyMode' => 'ai',
            'replyTemplateIds' => [],
            'replyAiTemplateId' => $aiId,
            'replyTexts' => [],
            'replyAiPrompt' => $aiPrompt,
            'replyText' => '',
            'maxReplies' => $maxReplies,
        ];
    }

    /**
     * @param array<string,mixed> $variables
     * @return array{reply:string,prompt:string,model:?string}
     */
    public static function generateReply(string $promptTemplate, array $variables = [], array $aiOptions = []): array
    {
        $promptTemplate = trim($promptTemplate);
        if ($promptTemplate === '') {
            $promptTemplate = self::defaultAiPrompt();
        }
        $vars = self::normalizeVars($variables);
        $rendered = AiPromptHelper::render($promptTemplate, $vars, false);
        $system = 'You write short natural TikTok comment replies in English only. '
            . 'Output the reply text alone — no quotes, no explanation. '
            . 'Keep it 6–35 words, friendly and relevant to the original comment.';
        $user = $rendered;
        if (!preg_match('/\benglish\b/i', $rendered)) {
            $user .= "\n\nWrite the reply in English only.";
        }
        $user .= "\n\nOriginal comment: " . ($vars['comment_text'] ?: '(none)')
            . "\nVideo description: " . ($vars['video_desc'] ?: '(none)');

        $result = AiClient::chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            array_merge(['operation' => 'comment_reply', 'timeout' => 45], $aiOptions)
        );
        $reply = self::sanitize((string)($result['content'] ?? ''));
        if ($reply === '') {
            throw new \RuntimeException('AI 未返回有效回复');
        }
        return [
            'reply' => $reply,
            'prompt' => $user,
            'model' => isset($result['model']) ? (string)$result['model'] : null,
        ];
    }

    public static function defaultAiPrompt(): string
    {
        return "Reply to this TikTok comment in a short, friendly English sentence.\n"
            . "Rules:\n"
            . "1. English only, no quotation marks\n"
            . "2. 6–35 words, casual tone, optional 1 emoji\n"
            . "3. No ads, links, or politics\n"
            . "Comment: {{comment_text}}\n"
            . "Video: {{video_desc}}";
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
             WHERE id IN ({$ph}) AND type='comment_reply' AND enabled=1",
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
    private static function normalizeVars(array $variables): array
    {
        $keys = ['comment_text', 'video_desc', 'author', 'video_url', 'video_id', 'nickname'];
        $out = [];
        foreach ($keys as $k) {
            $v = $variables[$k] ?? '';
            $out[$k] = is_scalar($v) ? trim((string)$v) : '';
        }
        if ($out['author'] === '' && $out['nickname'] !== '') {
            $out['author'] = $out['nickname'];
        }
        return $out;
    }

    private static function sanitize(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^["“”\'`]+|["“”\'`]+$/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
