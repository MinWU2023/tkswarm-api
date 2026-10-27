<?php
declare(strict_types=1);

namespace App\Services\Ai\Drivers;

use App\Services\Ai\AiHelper;

/** Anthropic Claude Messages API */
class AnthropicDriver implements AiDriverInterface
{
    public function code(): string
    {
        return 'anthropic';
    }

    public function chat(array $config, array $messages, array $options = []): array
    {
        $base = AiHelper::pickString($config, 'base_url', 'https://api.anthropic.com');
        $key = AiHelper::pickString($config, 'api_key');
        if ($key === '') {
            throw new \RuntimeException('未配置 Anthropic API Key');
        }
        $model = AiHelper::pickString($options, 'model', AiHelper::pickString($config, 'model', 'claude-3-5-sonnet-20241022'));
        $system = '';
        $converted = [];
        foreach (AiHelper::normalizeMessages($messages) as $m) {
            if ($m['role'] === 'system') {
                $system .= ($system === '' ? '' : "\n") . $m['content'];
                continue;
            }
            $converted[] = ['role' => $m['role'], 'content' => $m['content']];
        }
        if (!$converted) {
            throw new \RuntimeException('消息不能为空');
        }
        $payload = [
            'model' => $model,
            'max_tokens' => AiHelper::pickInt($options, 'max_tokens', AiHelper::pickInt($config, 'max_tokens', 2048)),
            'messages' => $converted,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }
        $temp = AiHelper::pickFloat($options, 'temperature', AiHelper::pickFloat($config, 'temperature', -1));
        if ($temp >= 0) {
            $payload['temperature'] = $temp;
        }
        $headers = [
            'x-api-key' => $key,
            'anthropic-version' => AiHelper::pickString($config, 'anthropic_version', '2023-06-01'),
        ];
        $resp = AiHelper::httpJson('POST', AiHelper::joinUrl($base, '/v1/messages'), $headers, $payload, (int)($options['timeout'] ?? 60));
        $json = AiHelper::assertOk($resp, 'Anthropic');
        $text = '';
        foreach (($json['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Claude 未返回有效内容');
        }
        return [
            'content' => $text,
            'raw' => $json,
            'usage' => is_array($json['usage'] ?? null) ? $json['usage'] : null,
            'model' => (string)($json['model'] ?? $model),
        ];
    }
}
