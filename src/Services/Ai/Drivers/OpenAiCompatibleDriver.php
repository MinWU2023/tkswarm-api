<?php
declare(strict_types=1);

namespace App\Services\Ai\Drivers;

use App\Services\Ai\AiHelper;

/** OpenAI / DeepSeek / 通义兼容模式 / Kimi / Groq / OpenRouter / Ollama 等 */
class OpenAiCompatibleDriver implements AiDriverInterface
{
    public function code(): string
    {
        return 'openai_compatible';
    }

    public function chat(array $config, array $messages, array $options = []): array
    {
        $base = AiHelper::normalizeOpenAiBaseUrl(
            AiHelper::pickString($config, 'base_url', 'https://api.openai.com/v1')
        );
        $key = AiHelper::pickString($config, 'api_key');
        if ($key === '') {
            if (preg_match('/127\.0\.0\.1|localhost/i', $base)) {
                $key = 'ollama';
            } else {
                throw new \RuntimeException('未配置 API Key');
            }
        }
        $model = AiHelper::pickString($options, 'model', AiHelper::pickString($config, 'model', 'gpt-4o-mini'));
        $payload = [
            'model' => $model,
            'messages' => AiHelper::normalizeMessages($messages),
            'temperature' => AiHelper::pickFloat($options, 'temperature', AiHelper::pickFloat($config, 'temperature', 0.7)),
        ];
        $maxTokens = AiHelper::pickInt($options, 'max_tokens', AiHelper::pickInt($config, 'max_tokens', 0));
        if ($maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }
        $headers = ['Authorization' => 'Bearer ' . $key];
        $org = AiHelper::pickString($config, 'organization');
        if ($org !== '') {
            $headers['OpenAI-Organization'] = $org;
        }
        $path = AiHelper::pickString($config, 'chat_path', '/chat/completions');
        $url = AiHelper::joinUrl($base, $path);
        $resp = AiHelper::httpJson('POST', $url, $headers, $payload, (int)($options['timeout'] ?? 45));
        $json = AiHelper::assertOk($resp, 'OpenAI兼容');
        return [
            'content' => AiHelper::extractOpenAiContent($json),
            'raw' => $json,
            'usage' => is_array($json['usage'] ?? null) ? $json['usage'] : null,
            'model' => (string)($json['model'] ?? $model),
        ];
    }
}
