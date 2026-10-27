<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;
use App\Services\Ai\AiClient;

/**
 * 精简智能体：按开放平台 + 模型 + 语言风格生成回复
 */
class AgentChatService
{
    public const TONES = [
        'friendly' => '亲切友好',
        'professional' => '专业稳重',
        'humor' => '幽默风趣',
        'casual' => '口语轻松',
        'concise' => '简洁克制',
    ];

    public const LANGUAGES = [
        'zh' => '中文',
        'en' => '英语',
        'ja' => '日语',
        'ko' => '韩语',
        'es' => '西班牙语',
        'fr' => '法语',
        'de' => '德语',
        'pt' => '葡萄牙语',
        'id' => '印尼语',
        'th' => '泰语',
        'vi' => '越南语',
        'ar' => '阿拉伯语',
        'ru' => '俄语',
    ];

    /**
     * @param array<string,mixed> $agent agents 表行或 mapAgent 结果
     * @param list<array{role?:string,content?:string}> $history
     */
    public static function reply(array $agent, string $userText, array $history = []): string
    {
        $platformId = (int)($agent['platform_id'] ?? $agent['platformId'] ?? 0);
        $model = trim((string)($agent['model_id'] ?? $agent['modelId'] ?? ''));
        if ($platformId < 1 || $model === '') {
            throw new \RuntimeException('请先为智能体选择 AI 开放平台和模型');
        }
        $tone = trim((string)($agent['reply_tone'] ?? $agent['replyTone'] ?? 'friendly'));
        $lang = trim((string)($agent['reply_language'] ?? $agent['replyLanguage'] ?? 'zh'));
        $system = self::systemPrompt($tone, $lang);
        $messages = [['role' => 'system', 'content' => $system]];
        foreach (array_slice($history, -12) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $role = (($item['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $content = trim((string)($item['content'] ?? ''));
            if ($content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userText];
        $result = AiClient::chat($messages, [
            'platform_id' => $platformId,
            'model' => $model,
            'operation' => 'agent_chat',
            'timeout' => 45,
        ]);
        $reply = trim((string)($result['content'] ?? ''));
        if ($reply === '') {
            throw new \RuntimeException('模型未返回有效内容');
        }
        return $reply;
    }

    public static function systemPrompt(string $tone, string $lang): string
    {
        $toneLabel = self::TONES[$tone] ?? ($tone !== '' ? $tone : '亲切友好');
        $langLabel = self::LANGUAGES[$lang] ?? ($lang !== '' && $lang !== 'auto' ? $lang : '中文');
        return "你是客服助手。用「{$toneLabel}」的语气回复。"
            . "只用{$langLabel}书写，不要夹杂其它语言（专有名词除外）。"
            . "直接输出回复正文，不要解释自己是 AI。";
    }

    public static function assertPlatformModel(int $platformId, string $modelCode): void
    {
        $plat = DB::selectOne('SELECT id, enabled FROM ai_platforms WHERE id=?', [$platformId]);
        if (!$plat) {
            throw new \InvalidArgumentException('所选 AI 开放平台不存在');
        }
        if (empty($plat['enabled'])) {
            throw new \InvalidArgumentException('所选 AI 开放平台已停用');
        }
        $model = DB::selectOne(
            'SELECT id, enabled FROM ai_platform_models WHERE platform_id=? AND code=?',
            [$platformId, $modelCode]
        );
        if (!$model) {
            throw new \InvalidArgumentException('所选模型不属于该平台');
        }
        if (empty($model['enabled'])) {
            throw new \InvalidArgumentException('所选模型已停用');
        }
    }
}
