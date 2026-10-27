<?php
declare(strict_types=1);

namespace App\Services\Ai\Drivers;

use App\Services\Ai\AiHelper;

/** 百度文心：API Key + Secret Key 换 token 后调 ERNIE */
class WenxinDriver implements AiDriverInterface
{
    public function code(): string
    {
        return 'wenxin';
    }

    public function chat(array $config, array $messages, array $options = []): array
    {
        $apiKey = AiHelper::pickString($config, 'api_key');
        $secretKey = AiHelper::pickString($config, 'secret_key');
        if ($apiKey === '' || $secretKey === '') {
            throw new \RuntimeException('文心需配置 api_key 与 secret_key');
        }
        $token = $this->accessToken($apiKey, $secretKey);
        $model = AiHelper::pickString($options, 'model', AiHelper::pickString($config, 'model', 'ernie-4.0-8k'));
        $pathMap = [
            'ernie-4.0-8k' => 'completions_pro',
            'ernie-3.5-8k' => 'completions',
            'ernie-speed-8k' => 'ernie_speed',
            'ernie-lite-8k' => 'eb-instant',
        ];
        $path = $pathMap[$model] ?? AiHelper::pickString($config, 'chat_path', 'completions_pro');
        $system = '';
        $converted = [];
        foreach (AiHelper::normalizeMessages($messages) as $m) {
            if ($m['role'] === 'system') {
                $system .= ($system === '' ? '' : "\n") . $m['content'];
                continue;
            }
            $converted[] = [
                'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $m['content'],
            ];
        }
        if (!$converted) {
            throw new \RuntimeException('消息不能为空');
        }
        $payload = [
            'messages' => $converted,
            'temperature' => AiHelper::pickFloat($options, 'temperature', AiHelper::pickFloat($config, 'temperature', 0.7)),
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }
        $url = 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/wenxinworkshop/chat/' . $path . '?access_token=' . rawurlencode($token);
        $resp = AiHelper::httpJson('POST', $url, [], $payload, (int)($options['timeout'] ?? 45));
        $json = AiHelper::assertOk($resp, '文心一言');
        if (!empty($json['error_code'])) {
            throw new \RuntimeException('文心错误 ' . $json['error_code'] . ': ' . ($json['error_msg'] ?? ''));
        }
        $text = trim((string)($json['result'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('文心未返回有效内容');
        }
        return [
            'content' => $text,
            'raw' => $json,
            'usage' => is_array($json['usage'] ?? null) ? $json['usage'] : null,
            'model' => $model,
        ];
    }

    private function accessToken(string $apiKey, string $secretKey): string
    {
        $url = 'https://aip.baidubce.com/oauth/2.0/token?grant_type=client_credentials'
            . '&client_id=' . rawurlencode($apiKey)
            . '&client_secret=' . rawurlencode($secretKey);
        $resp = AiHelper::httpJson('GET', $url, [], null, 20);
        $json = AiHelper::assertOk($resp, '文心Token');
        $token = (string)($json['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('获取文心 access_token 失败');
        }
        return $token;
    }
}
