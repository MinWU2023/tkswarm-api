<?php
declare(strict_types=1);

namespace App\Services\Ai\Drivers;

use App\Services\Ai\AiHelper;

/** Azure OpenAI：endpoint + deployment + api-version */
class AzureOpenAiDriver implements AiDriverInterface
{
    public function code(): string
    {
        return 'azure_openai';
    }

    public function chat(array $config, array $messages, array $options = []): array
    {
        $endpoint = rtrim(AiHelper::pickString($config, 'endpoint'), '/');
        $deployment = AiHelper::pickString($config, 'deployment');
        $apiVersion = AiHelper::pickString($config, 'api_version', '2024-02-15-preview');
        $key = AiHelper::pickString($config, 'api_key');
        if ($endpoint === '' || $deployment === '' || $key === '') {
            throw new \RuntimeException('Azure 需配置 endpoint、deployment、api_key');
        }
        $url = $endpoint . '/openai/deployments/' . rawurlencode($deployment) . '/chat/completions?api-version=' . rawurlencode($apiVersion);
        $payload = [
            'messages' => AiHelper::normalizeMessages($messages),
            'temperature' => AiHelper::pickFloat($options, 'temperature', AiHelper::pickFloat($config, 'temperature', 0.7)),
        ];
        $maxTokens = AiHelper::pickInt($options, 'max_tokens', AiHelper::pickInt($config, 'max_tokens', 0));
        if ($maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }
        $resp = AiHelper::httpJson('POST', $url, ['api-key' => $key], $payload, (int)($options['timeout'] ?? 45));
        $json = AiHelper::assertOk($resp, 'Azure OpenAI');
        return [
            'content' => AiHelper::extractOpenAiContent($json),
            'raw' => $json,
            'usage' => is_array($json['usage'] ?? null) ? $json['usage'] : null,
            'model' => $deployment,
        ];
    }
}
