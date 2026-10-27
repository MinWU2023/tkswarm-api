<?php
declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\SecretStore;

/**
 * AI 辅助函数：HTTP、消息归一、密钥脱敏、字段校验等。
 */
class AiHelper
{
    /** @param list<array{role?:string,content?:mixed}> $messages */
    public static function normalizeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $item) {
            if (!is_array($item)) {
                continue;
            }
            $role = (string)($item['role'] ?? 'user');
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }
            $content = $item['content'] ?? '';
            if (is_array($content)) {
                $content = json_encode($content, JSON_UNESCAPED_UNICODE);
            }
            $content = trim((string)$content);
            if ($content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    public static function pickString(array $cfg, string $key, string $fallback = ''): string
    {
        $v = $cfg[$key] ?? $fallback;
        return is_string($v) || is_numeric($v) ? trim((string)$v) : $fallback;
    }

    public static function pickFloat(array $cfg, string $key, float $fallback): float
    {
        if (!array_key_exists($key, $cfg) || $cfg[$key] === '' || $cfg[$key] === null) {
            return $fallback;
        }
        return (float)$cfg[$key];
    }

    public static function pickInt(array $cfg, string $key, int $fallback): int
    {
        if (!array_key_exists($key, $cfg) || $cfg[$key] === '' || $cfg[$key] === null) {
            return $fallback;
        }
        return (int)$cfg[$key];
    }

    public static function joinUrl(string $base, string $path): string
    {
        $base = rtrim(trim($base), '/');
        $path = '/' . ltrim($path, '/');
        // 若 base 已含完整 chat 路径，避免重复拼接
        if ($path !== '/' && str_ends_with(strtolower($base), strtolower($path))) {
            return $base;
        }
        return $base . $path;
    }

    /**
     * 规范化 OpenAI 兼容 Base URL（百炼/DashScope 常见误配：http、缺 compatible-mode、多写 /chat/completions）
     */
    public static function normalizeOpenAiBaseUrl(string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            return $base;
        }
        // 去掉误填的完整 chat 路径
        $base = preg_replace('#/chat/completions/?$#i', '', $base) ?? $base;
        $base = rtrim($base, '/');

        // DashScope / 百炼：http → https（否则服务端常回 308）
        if (preg_match('#^http://((?:dashscope(?:-intl|-us)?\.aliyuncs\.com)|(?:[\w-]+\.(?:ap-southeast-1|cn-hongkong|ap-northeast-1)\.maas\.aliyuncs\.com))(/.*)?$#i', $base, $m)) {
            $base = 'https://' . $m[1] . ($m[2] ?? '');
            $base = rtrim($base, '/');
        }

        // 仅写了根域或旧 /api/v1 → 兼容模式
        if (preg_match('#^https://dashscope\.aliyuncs\.com$#i', $base)
            || preg_match('#^https://dashscope\.aliyuncs\.com/api(?:/v1)?$#i', $base)) {
            return 'https://dashscope.aliyuncs.com/compatible-mode/v1';
        }
        if (preg_match('#^https://dashscope-intl\.aliyuncs\.com$#i', $base)
            || preg_match('#^https://dashscope-intl\.aliyuncs\.com/api(?:/v1)?$#i', $base)) {
            return 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';
        }
        if (preg_match('#^https://dashscope-us\.aliyuncs\.com$#i', $base)
            || preg_match('#^https://dashscope-us\.aliyuncs\.com/api(?:/v1)?$#i', $base)) {
            return 'https://dashscope-us.aliyuncs.com/compatible-mode/v1';
        }

        return $base;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,json:?array,error:string,url?:string}
     */
    public static function httpJson(string $method, string $url, array $headers, ?array $payload, int $timeout = 45): array
    {
        $method = strtoupper($method);
        $ch = curl_init($url);
        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        if (!isset($headers['Content-Type']) && !isset($headers['content-type'])) {
            $hdr[] = 'Content-Type: application/json';
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            // 百炼等对 http→https 会回 308；需跟随且保持 POST
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ];
        if (defined('CURLOPT_POSTREDIR')) {
            // 301|302|303 也保持 POST（308/307 由 libcurl 默认保持方法）
            $opts[CURLOPT_POSTREDIR] = 7;
        }
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
            }
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['status' => 0, 'body' => '', 'json' => null, 'error' => $err !== '' ? $err : '请求失败', 'url' => $url];
        }
        $json = json_decode((string)$raw, true);
        return [
            'status' => $status,
            'body' => (string)$raw,
            'json' => is_array($json) ? $json : null,
            'error' => '',
            'url' => $finalUrl !== '' ? $finalUrl : $url,
        ];
    }

    public static function assertOk(array $resp, string $label = 'AI'): array
    {
        if ($resp['error'] !== '') {
            throw new \RuntimeException($label . ' 网络错误：' . $resp['error']);
        }
        $status = (int)$resp['status'];
        if ($status < 200 || $status >= 300) {
            $snippet = mb_substr((string)$resp['body'], 0, 240);
            $hint = '';
            if ($status === 301 || $status === 302 || $status === 307 || $status === 308) {
                $hint = '（地址被重定向，请确认 Base URL 使用 https，且为 …/compatible-mode/v1，不要带 /chat/completions）';
            }
            throw new \RuntimeException("{$label} 接口失败 {$status}{$hint}: {$snippet}");
        }
        $json = $resp['json'];
        if (!is_array($json)) {
            throw new \RuntimeException($label . ' 返回非 JSON');
        }
        return $json;
    }

    /** 从 OpenAI 兼容响应提取文本 */
    public static function extractOpenAiContent(array $json): string
    {
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $p) {
                if (is_string($p)) {
                    $parts[] = $p;
                } elseif (is_array($p) && isset($p['text'])) {
                    $parts[] = (string)$p['text'];
                }
            }
            $content = implode('', $parts);
        }
        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            throw new \RuntimeException('模型未返回有效内容');
        }
        return $content;
    }

    /**
     * 按 field_schema 校验并合并配置；密钥字段标记 secret。
     * @param list<array<string,mixed>> $schema
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing 已解密旧配置（编辑时保留 ******）
     * @return array{config:array<string,mixed>,secret_keys:list<string>}
     */
    public static function applySchema(array $schema, array $input, ?array $existing = null): array
    {
        $config = is_array($existing) ? $existing : [];
        $secretKeys = [];
        foreach ($schema as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string)($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $type = (string)($field['type'] ?? 'text');
            $required = !empty($field['required']);
            $isSecret = $type === 'secret' || !empty($field['secret']);
            if ($isSecret) {
                $secretKeys[] = $key;
            }
            if (!array_key_exists($key, $input)) {
                if ($required && !array_key_exists($key, $config)) {
                    $default = $field['default'] ?? null;
                    if ($default !== null && $default !== '') {
                        $config[$key] = $default;
                    } else {
                        throw new \InvalidArgumentException(($field['label'] ?? $key) . ' 不能为空');
                    }
                }
                continue;
            }
            $val = $input[$key];
            if ($isSecret && (string)$val === '******') {
                continue;
            }
            if ($type === 'number') {
                $config[$key] = $val === '' || $val === null ? ($field['default'] ?? null) : (0 + $val);
            } elseif ($type === 'boolean' || $type === 'bool') {
                $config[$key] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
            } else {
                $config[$key] = is_scalar($val) || $val === null ? trim((string)($val ?? '')) : $val;
            }
            if ($required && ($config[$key] === '' || $config[$key] === null)) {
                throw new \InvalidArgumentException(($field['label'] ?? $key) . ' 不能为空');
            }
        }
        return ['config' => $config, 'secret_keys' => array_values(array_unique($secretKeys))];
    }

    /** @param array<string,mixed> $config @param list<string> $secretKeys */
    public static function maskSecrets(array $config, array $secretKeys): array
    {
        foreach ($secretKeys as $k) {
            if (!empty($config[$k])) {
                $config[$k] = '******';
            }
        }
        return $config;
    }

    /** @param array<string,mixed> $config @param list<string> $secretKeys */
    public static function encryptSecrets(array $config, array $secretKeys): array
    {
        foreach ($secretKeys as $k) {
            if (!isset($config[$k]) || $config[$k] === '' || $config[$k] === null) {
                continue;
            }
            $plain = (string)$config[$k];
            if (str_starts_with($plain, 'v1.')) {
                continue;
            }
            $config[$k] = SecretStore::encrypt($plain);
        }
        return $config;
    }

    /** @param array<string,mixed> $config @param list<string> $secretKeys */
    public static function decryptSecrets(array $config, array $secretKeys): array
    {
        foreach ($secretKeys as $k) {
            if (!isset($config[$k]) || $config[$k] === '' || $config[$k] === null) {
                continue;
            }
            $val = (string)$config[$k];
            if (str_starts_with($val, 'v1.')) {
                $config[$k] = SecretStore::decrypt($val);
            }
        }
        return $config;
    }

    /** @param list<array<string,mixed>> $schema */
    public static function secretKeysFromSchema(array $schema): array
    {
        $keys = [];
        foreach ($schema as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string)($field['key'] ?? '');
            $type = (string)($field['type'] ?? 'text');
            if ($key !== '' && ($type === 'secret' || !empty($field['secret']))) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public static function regionLabel(string $region): string
    {
        return match ($region) {
            'cn' => '国内',
            'intl' => '国际',
            'local' => '本地',
            default => $region,
        };
    }
}
