<?php
declare(strict_types=1);

namespace App\Services\Ai\Drivers;

use App\Services\Ai\AiHelper;

/** Google Gemini generateContent */
class GeminiDriver implements AiDriverInterface
{
    public function code(): string
    {
        return 'gemini';
    }

    public function chat(array $config, array $messages, array $options = []): array
    {
        $key = AiHelper::pickString($config, 'api_key');
        if ($key === '') {
            throw new \RuntimeException('未配置 Gemini API Key');
        }
        $model = AiHelper::pickString($options, 'model', AiHelper::pickString($config, 'model', 'gemini-1.5-flash'));
        $base = AiHelper::pickString($config, 'base_url', 'https://generativelanguage.googleapis.com/v1beta');
        $system = '';
        $contents = [];
        foreach (AiHelper::normalizeMessages($messages) as $m) {
            if ($m['role'] === 'system') {
                $system .= ($system === '' ? '' : "\n") . $m['content'];
                continue;
            }
            $contents[] = [
                'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ];
        }
        if (!$contents) {
            throw new \RuntimeException('消息不能为空');
        }
        $payload = ['contents' => $contents];
        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        $gen = [];
        $temp = AiHelper::pickFloat($options, 'temperature', AiHelper::pickFloat($config, 'temperature', -1));
        if ($temp >= 0) {
            $gen['temperature'] = $temp;
        }
        $maxTokens = AiHelper::pickInt($options, 'max_tokens', AiHelper::pickInt($config, 'max_tokens', 0));
        if ($maxTokens > 0) {
            $gen['maxOutputTokens'] = $maxTokens;
        }
        if ($gen) {
            $payload['generationConfig'] = $gen;
        }
        $url = AiHelper::joinUrl($base, '/models/' . rawurlencode($model) . ':generateContent') . '?key=' . rawurlencode($key);
        $resp = AiHelper::httpJson('POST', $url, [], $payload, (int)($options['timeout'] ?? 45));
        $json = AiHelper::assertOk($resp, 'Gemini');
        $text = (string)($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Gemini 未返回有效内容');
        }
        return [
            'content' => $text,
            'raw' => $json,
            'usage' => is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : null,
            'model' => $model,
        ];
    }
}
