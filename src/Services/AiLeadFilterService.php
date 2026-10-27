<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiPromptCatalog;
use App\Services\Ai\AiPromptHelper;

/**
 * AI拓客：评论 JSON 筛选（询价 / 问产品信息）
 */
class AiLeadFilterService
{
    public static function defaultPrompt(): string
    {
        return "你是 B2B 拓客助手。以下是从 TikTok 视频评论中提取的用户列表（JSON 数组）：\n"
            . "{{comments_json}}\n\n"
            . "目标：只保留「买家意愿」评论（含明确求购文案，以及表示喜欢/心动的颜文字、emoji）。\n"
            . "保留（买家信号）：\n"
            . "1) 文案：how much / what's the price / MOQ / quote / 多少钱 / 询价 / 要采购 / where to buy / "
            . "do you ship / I need / looking to buy / can I get a catalog 等求购、询价、问交付；\n"
            . "2) 颜文字/emoji 表喜欢：❤️ 🔥 😍 🥰 👀 ✨ (◕‿◕) ♡ 等表达喜欢、心动、想要（非嘲讽）。\n"
            . "必须排除（卖家/广告）：factory/manufacturer/supplier/wholesale、sold directly、we can offer、"
            . "DM me for price（推销自己）、affiliate、招代理、代运营/独立站推销、欢迎合作（服务商获客）。\n"
            . "也排除：无关闲聊、嘲讽/负面、创作者自己回复。\n"
            . "判断原则：主语是「我要买/问价/喜欢想要」才保留；主语是「我有货/我能供」一律丢弃。\n\n"
            . "对每条保留评论打分 intent_score（整数 1～10）：\n"
            . "- 明确询价/求购/问 MOQ/交付的文案：7～10（文案分必须明显高于纯颜文字）；\n"
            . "- 文案含兴趣但仍含糊：5～7；\n"
            . "- 仅颜文字/emoji 表喜欢：3～5（不得超过同场景下明确文案的分数）；\n"
            . "- 不要给卖家广告打分（直接丢弃）。\n\n"
            . "对每条保留评论还必须提供：\n"
            . "- comment_text_zh：评论的简体中文译文（emoji/颜文字可保留；若原文已是中文可复述或略写）；\n"
            . "- reason：必须用简体中文说明为何判定为买家意向。\n\n"
            . "仅返回 JSON 数组，每项字段：username, nickname, profile_url, comment_text, comment_text_zh, reason, intent_score\n"
            . "reason 与 comment_text_zh 必须为简体中文；intent_score 为 1～10 整数。若无符合项，返回 []。";
    }

    public static function resolvePrompt(?int $templateId = null): string
    {
        AiPromptCatalog::ensure();
        if ($templateId !== null && $templateId > 0) {
            $row = DB::selectOne(
                'SELECT content FROM ai_prompt_templates WHERE id=? AND enabled=1',
                [$templateId]
            );
            $content = trim((string)($row['content'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        }
        $builtin = DB::selectOne(
            "SELECT content FROM ai_prompt_templates WHERE code='lead_comment_filter' AND enabled=1 LIMIT 1"
        );
        $content = trim((string)($builtin['content'] ?? ''));
        return $content !== '' ? $content : self::defaultPrompt();
    }

    /**
     * @param list<array<string,mixed>> $comments
     * @return list<array<string,mixed>>
     */
    public static function filterComments(array $comments, ?string $promptTemplate = null, array $aiOptions = []): array
    {
        if (!$comments) {
            return [];
        }
        $promptTemplate = trim((string)($promptTemplate ?? ''));
        if ($promptTemplate === '') {
            $promptTemplate = self::resolvePrompt();
        }
        $normalized = [];
        foreach ($comments as $c) {
            if (!is_array($c)) {
                continue;
            }
            $username = trim((string)($c['username'] ?? ''));
            $text = trim((string)($c['comment_text'] ?? $c['commentText'] ?? $c['text'] ?? ''));
            if ($username === '' && $text === '') {
                continue;
            }
            $normalized[] = [
                'username' => $username,
                'nickname' => trim((string)($c['nickname'] ?? '')),
                'profile_url' => trim((string)($c['profile_url'] ?? $c['profileUrl'] ?? '')),
                'comment_text' => $text,
            ];
        }
        if (!$normalized) {
            return [];
        }

        // 本地先丢掉明显卖家广告，减少 AI 误判与 token
        $forAi = [];
        foreach ($normalized as $row) {
            if (self::looksLikeSellerAd((string)$row['comment_text'])) {
                continue;
            }
            $forAi[] = $row;
        }
        if (!$forAi) {
            return [];
        }

        $json = json_encode($forAi, JSON_UNESCAPED_UNICODE);
        $rendered = AiPromptHelper::render($promptTemplate, ['comments_json' => $json], false);
        $system = 'You analyze TikTok comments for B2B lead generation. '
            . 'Keep buyer-intent comments: purchase/inquire text OR positive liking emojis/kaomoji. '
            . 'Reject seller/supplier/factory ads. '
            . 'Each item MUST include intent_score integer 1-10; text inquiry scores MUST be higher than emoji-only. '
            . 'Each item MUST include comment_text_zh (Simplified Chinese translation of comment_text) '
            . 'and reason (Simplified Chinese explanation of buyer intent). '
            . 'Output valid JSON array only — no markdown, no explanation outside JSON.';
        $result = AiClient::chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $rendered],
            ],
            array_merge(['operation' => 'lead_comment_filter', 'timeout' => 60], $aiOptions)
        );
        $raw = trim((string)($result['content'] ?? ''));
        $matched = self::parseJsonArray($raw);
        // AI 结果再挡一层卖家广告，并规范化分数
        $out = [];
        foreach ($matched as $row) {
            $text = (string)($row['comment_text'] ?? '');
            if (self::looksLikeSellerAd($text)) {
                continue;
            }
            $score = self::normalizeIntentScore($row['intent_score'] ?? $row['intentScore'] ?? 0);
            if ($score < 1) {
                $score = self::guessIntentScore($text);
            }
            // 纯颜文字上限 5，避免压过明确询价文案
            if (self::isMostlyEmoticon($text) && $score > 5) {
                $score = 5;
            }
            $row['intent_score'] = $score;
            $row['comment_text_zh'] = trim((string)(
                $row['comment_text_zh'] ?? $row['commentTextZh'] ?? $row['comment_text_zh_cn'] ?? ''
            ));
            $row['reason'] = trim((string)($row['reason'] ?? ''));
            $out[] = $row;
        }
        return $out;
    }

    public static function normalizeIntentScore(mixed $value): int
    {
        if (is_string($value) && preg_match('/(\d+(?:\.\d+)?)/', $value, $m)) {
            $value = $m[1];
        }
        $n = (int)round((float)$value);
        return max(0, min(10, $n));
    }

    /** 评论是否以颜文字/emoji 为主（几乎无实质询价词） */
    public static function isMostlyEmoticon(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }
        // 去掉空白与常见标点后，若几乎全是符号/emoji，或含表情且几乎无拉丁/汉字词
        $stripped = preg_replace('/[\s\p{P}\p{S}]+/u', '', $t) ?? '';
        $hasEmoji = (bool)preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}♡♥❤🔥✨⭐]/u', $t)
            || (bool)preg_match('/[◕☆★＞＜。・´｀＾^\-_;:\/\\\\=]/u', $t);
        if (!$hasEmoji && !preg_match('/[\(\)（）\[\]【】]/u', $t)) {
            return false;
        }
        if (preg_match('/\b(how\s+much|moq|quote|price|buy|ship|catalog|询价|多少钱|采购|怎么买)\b/iu', $t)) {
            return false;
        }
        // 实质字母数字汉字很少
        $letters = preg_replace('/[^\p{L}\p{N}\p{Han}]+/u', '', $t) ?? '';
        return mb_strlen($letters) <= 2 || (mb_strlen($stripped) <= 6 && $hasEmoji);
    }

    public static function guessIntentScore(string $text): int
    {
        $t = trim($text);
        if ($t === '') {
            return 0;
        }
        if (self::isMostlyEmoticon($t)) {
            return 4;
        }
        if (preg_match('/\b(how\s+much|moq|quote|quotation|询价|多少钱|采购|where\s+to\s+buy|looking\s+to\s+buy)\b/iu', $t)) {
            return 8;
        }
        if (preg_match('/\b(price|buy|need|interested|想要|喜欢)\b/iu', $t)) {
            return 6;
        }
        return 5;
    }

    /**
     * 明显卖家/引流广告（本地规则，优先于 AI）。
     */
    public static function looksLikeSellerAd(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }
        // 强卖家主语：有货/能供，即使夹杂 price 也视为广告
        $sellerPatterns = [
            '/\b(factory|manufacturer|supplier|wholesaler?|oem|odm)\b/u',
            '/\b(we\s+(can\s+)?(offer|supply|provide|sell)|sold\s+directly)\b/u',
            '/\b(part\s+supply|auto\s*parts?\s+supply)\b/u',
            '/\b(affiliate|drop\s?ship(ping)?)\b/u',
            '/\b(dm\s*me\s+for|contact\s+me\s+for|message\s+me\s+for)\b/u',
            '/\b(our\s+factory|we\s+are\s+(a\s+)?(factory|supplier|manufacturer|wholesaler?))\b/u',
            '/(厂家|工厂直销|源头工厂|批发供货|招代理|欢迎合作|代运营|独立站|有现货批发)/u',
        ];
        foreach ($sellerPatterns as $re) {
            if (preg_match($re, $t)) {
                return true;
            }
        }
        return false;
    }

    public static function resolveDmOpenerPrompt(?int $templateId = null): string
    {
        AiPromptCatalog::ensure();
        if ($templateId !== null && $templateId > 0) {
            $row = DB::selectOne(
                'SELECT content FROM ai_prompt_templates WHERE id=? AND enabled=1',
                [$templateId]
            );
            $content = trim((string)($row['content'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        }
        $builtin = DB::selectOne(
            "SELECT content FROM ai_prompt_templates WHERE code='lead_dm_opener' AND enabled=1 LIMIT 1"
        );
        $content = trim((string)($builtin['content'] ?? ''));
        return $content !== '' ? $content : self::defaultDmOpenerPrompt();
    }

    public static function defaultDmOpenerPrompt(): string
    {
        return "你是 B2B 外贸拓客助手，需要给 TikTok 用户写一条私信开场白。\n"
            . "对方用户名：{{username}}\n昵称：{{nickname}}\n对方评论：{{comment_text}}\n产品/业务：{{product}}\n\n"
            . "要求：结合评论自然回应；英语为主；1～3 句且≤220 字符；遵守 TikTok 私信规范，"
            . "禁止色情暴力仇恨骚扰、站外引流敏感词（WhatsApp/Telegram/微信/加我等）、暴利承诺；"
            . "只输出正文纯文本。";
    }

    /**
     * 根据意向评论生成合规私信开场白。
     * @param array<string,mixed> $target
     * @return array{message:string,source:string}
     */
    public static function generateDmOpener(array $target, ?string $promptTemplate = null, array $aiOptions = []): array
    {
        $username = trim((string)($target['username'] ?? ''));
        $nickname = trim((string)($target['nickname'] ?? $username));
        $comment = trim((string)($target['comment_text'] ?? $target['commentText'] ?? $target['comment'] ?? ''));
        $product = trim((string)($target['product'] ?? $target['productName'] ?? 'our products'));
        if ($product === '') {
            $product = 'our products';
        }
        if ($username === '' && $comment === '') {
            throw new \InvalidArgumentException('缺少用户名或评论内容');
        }

        $promptTemplate = trim((string)($promptTemplate ?? ''));
        if ($promptTemplate === '') {
            $promptTemplate = self::resolveDmOpenerPrompt();
        }
        $rendered = AiPromptHelper::render($promptTemplate, [
            'username' => $username !== '' ? $username : 'friend',
            'nickname' => $nickname !== '' ? $nickname : ($username !== '' ? $username : 'friend'),
            'comment_text' => $comment !== '' ? $comment : '(interested in the product)',
            'product' => $product,
        ], false);

        $system = 'You write short, compliant TikTok DM openers for B2B outreach. '
            . 'Output plain message text only — no quotes, no markdown, no explanation.';
        try {
            $result = AiClient::chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $rendered],
                ],
                array_merge(['operation' => 'lead_dm_opener', 'timeout' => 45], $aiOptions)
            );
            $raw = trim((string)($result['content'] ?? ''));
            $raw = preg_replace('/^["“]|["”]$/u', '', $raw) ?? $raw;
            $raw = trim(preg_replace('/^```(?:\w+)?\s*|\s*```$/u', '', $raw) ?? $raw);
            $raw = self::sanitizeDmMessage($raw);
            if ($raw !== '') {
                return ['message' => $raw, 'source' => 'ai'];
            }
        } catch (\Throwable $e) {
            // fall through to safe template
            $fallback = self::fallbackDmMessage($nickname !== '' ? $nickname : $username, $comment);
            return [
                'message' => $fallback,
                'source' => 'fallback',
                'error' => $e->getMessage(),
            ];
        }
        return [
            'message' => self::fallbackDmMessage($nickname !== '' ? $nickname : $username, $comment),
            'source' => 'fallback',
        ];
    }

    public static function sanitizeDmMessage(string $raw): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($s === '') {
            return '';
        }
        // 去掉常见敏感引流词（保守替换，避免触犯平台）
        $blocked = [
            'whatsapp', 'telegram', 'wechat', '微信', '加我', '加V', '加v',
            '点击链接', '免费送', '包邮到付', '保证赚钱', '日入过万',
        ];
        foreach ($blocked as $w) {
            $s = str_ireplace($w, '', $s);
        }
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if (mb_strlen($s) > 220) {
            $s = mb_substr($s, 0, 220);
        }
        return $s;
    }

    public static function fallbackDmMessage(string $name, string $comment): string
    {
        $who = trim($name) !== '' ? trim($name) : 'there';
        $hint = trim($comment);
        if ($hint !== '') {
            $snippet = mb_substr($hint, 0, 48);
            return "Hi {$who}, thanks for your comment about \"{$snippet}\". Happy to share more details if helpful.";
        }
        return "Hi {$who}, thanks for your interest. Happy to share more product details if you need.";
    }

    /** @return list<array<string,mixed>> */
    private static function parseJsonArray(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $start = strpos($raw, '[');
            $end = strrpos($raw, ']');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $username = trim((string)($item['username'] ?? ''));
            $text = trim((string)($item['comment_text'] ?? $item['commentText'] ?? ''));
            if ($username === '' && $text === '') {
                continue;
            }
            $score = self::normalizeIntentScore($item['intent_score'] ?? $item['intentScore'] ?? $item['score'] ?? 0);
            $out[] = [
                'username' => $username,
                'nickname' => trim((string)($item['nickname'] ?? '')),
                'profile_url' => trim((string)($item['profile_url'] ?? $item['profileUrl'] ?? '')),
                'comment_text' => $text,
                'comment_text_zh' => trim((string)(
                    $item['comment_text_zh'] ?? $item['commentTextZh'] ?? $item['comment_text_zh_cn'] ?? ''
                )),
                'reason' => trim((string)($item['reason'] ?? '')),
                'intent_score' => $score,
            ];
        }
        return $out;
    }
}
