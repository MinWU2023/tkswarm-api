<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 普通接口翻译 / AI 智能翻译（对齐 Node translator.js）
 */
class Translator
{
    public static function config(?int $userId = null): array
    {
        $mode = self::setting('translationMode', 'off', $userId);
        if ($mode === 'normal') {
            $mode = 'basic';
        }
        return [
            'translationMode' => $mode,
            'translationApiUrl' => (string)self::setting('translationApiUrl', '', $userId),
            'translationApiKey' => (string)self::setting('translationApiKey', '', $userId),
            'translationQuota' => (int)self::setting('translationQuota', 0, $userId),
            'translationUsed' => (int)self::setting('translationUsed', 0, $userId),
            'translationTarget' => (string)self::setting('translationTarget', 'zh', $userId),
            'translationModelId' => (string)self::setting('translationModelId', 'gpt-3.5-turbo', $userId),
        ];
    }

    public static function setting(string $key, mixed $fallback = null, ?int $userId = null): mixed
    {
        return UserSettingsService::setting($key, $fallback, $userId);
    }

    public static function setSetting(string $key, mixed $value, ?int $userId = null): void
    {
        UserSettingsService::setSetting($key, $value, $userId);
    }

    /**
     * @return array{text:string,provider:string,remaining:?int}
     */
    public static function translate(string $text, string $from = 'auto', string $to = 'zh', ?int $userId = null): array
    {
        $cfg = self::config($userId);
        $mode = $cfg['translationMode'] ?: 'off';
        if ($mode === 'off') {
            throw new \RuntimeException('翻译未启用，请在系统设置中开启');
        }
        if ($cfg['translationQuota'] > 0 && $cfg['translationUsed'] >= $cfg['translationQuota']) {
            throw new \RuntimeException('翻译配额已用尽');
        }
        $source = trim($text);
        if ($source === '') {
            throw new \RuntimeException('翻译内容为空');
        }

        if ($mode === 'basic') {
            $result = self::translateBasic($source, $from, $to, $cfg);
            $provider = 'basic';
        } elseif ($mode === 'ai') {
            $result = self::translateAi($source, $to, $cfg);
            $provider = 'ai';
        } else {
            throw new \RuntimeException('未知翻译模式');
        }

        self::setSetting('translationUsed', (int)$cfg['translationUsed'] + 1, $userId);
        $remaining = $cfg['translationQuota'] > 0
            ? max(0, $cfg['translationQuota'] - $cfg['translationUsed'] - 1)
            : null;
        return ['text' => $result, 'provider' => $provider, 'remaining' => $remaining];
    }

    /**
     * @return array{language:string,method:string,heuristic?:?string,note?:string,remaining?:?int}
     */
    public static function detectLanguage(string $text, ?int $userId = null): array
    {
        $cfg = self::config($userId);
        $mode = $cfg['translationMode'] ?: 'off';
        if ($mode === 'off') {
            throw new \RuntimeException('翻译未启用，请先在系统设置开启翻译后再自动检测');
        }
        $source = trim($text);
        if ($source === '') {
            throw new \RuntimeException('没有可检测的文本，请先有对方发来的消息');
        }
        $heuristic = self::heuristicLang($source);

        if ($mode === 'ai') {
            if ($cfg['translationApiUrl'] === '' || $cfg['translationApiKey'] === '') {
                throw new \RuntimeException('智能翻译未配置 API，无法自动检测；请手动选择原文语言');
            }
            if ($cfg['translationQuota'] > 0 && $cfg['translationUsed'] >= $cfg['translationQuota']) {
                throw new \RuntimeException('翻译配额已用尽，无法自动检测');
            }
            $endpoint = rtrim($cfg['translationApiUrl'], '/') . '/chat/completions';
            $payload = [
                'model' => $cfg['translationModelId'] ?: 'gpt-3.5-turbo',
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => 'Detect the language of the user text. Reply with ONLY one code: zh, en, ja, ko, es, or other.'],
                    ['role' => 'user', 'content' => mb_substr($source, 0, 800)],
                ],
            ];
            $data = self::httpJson('POST', $endpoint, $payload, $cfg['translationApiKey']);
            $raw = strtolower(trim((string)($data['choices'][0]['message']['content'] ?? '')));
            $code = null;
            foreach (['zh', 'en', 'ja', 'ko', 'es'] as $c) {
                if (str_contains($raw, $c)) {
                    $code = $c;
                    break;
                }
            }
            if (!$code && $heuristic !== 'auto') {
                $code = $heuristic;
            }
            if (!$code) {
                throw new \RuntimeException('未能识别语言，请手动选择原文语言');
            }
            self::setSetting('translationUsed', (int)$cfg['translationUsed'] + 1, $userId);
            return [
                'language' => $code,
                'method' => 'ai',
                'heuristic' => $heuristic === 'auto' ? null : $heuristic,
                'remaining' => $cfg['translationQuota'] > 0
                    ? max(0, $cfg['translationQuota'] - $cfg['translationUsed'] - 1)
                    : null,
            ];
        }

        if ($heuristic === 'auto') {
            throw new \RuntimeException('普通翻译模式无法可靠检测语言，请手动选择原文语言，或改用智能翻译后再试');
        }
        return [
            'language' => $heuristic,
            'method' => 'local',
            'note' => '根据字符特征本地识别，可在翻译设置中手动调整',
        ];
    }

    private static function translateBasic(string $source, string $from, string $to, array $cfg): string
    {
        if ($cfg['translationApiUrl'] === '') {
            throw new \RuntimeException('普通翻译未配置接口地址');
        }
        $parts = parse_url($cfg['translationApiUrl']);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('普通翻译接口地址无效');
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['q'] = $source;
        $query['langpair'] = ($from === 'auto' ? 'autodetect' : $from) . '|' . $to;
        $url = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '/')
            . '?' . http_build_query($query);
        $headers = [];
        if ($cfg['translationApiKey'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $cfg['translationApiKey'];
        }
        $data = self::httpJson('GET', $url, null, null, $headers);
        $result = $data['responseData']['translatedText']
            ?? $data['translatedText']
            ?? ($data['data']['translations'][0]['translatedText'] ?? null);
        if (!$result) {
            throw new \RuntimeException('翻译接口未返回有效文本');
        }
        return (string)$result;
    }

    private static function translateAi(string $source, string $to, array $cfg): string
    {
        if ($cfg['translationApiUrl'] === '' || $cfg['translationApiKey'] === '') {
            throw new \RuntimeException('智能翻译需配置 API 地址与密钥');
        }
        $endpoint = rtrim($cfg['translationApiUrl'], '/') . '/chat/completions';
        $payload = [
            'model' => $cfg['translationModelId'] ?: 'gpt-3.5-turbo',
            'temperature' => 0.2,
            'messages' => [
                ['role' => 'system', 'content' => "You are a translator. Translate the user text into {$to}. Return only the translation."],
                ['role' => 'user', 'content' => $source],
            ],
        ];
        $data = self::httpJson('POST', $endpoint, $payload, $cfg['translationApiKey']);
        $result = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        if ($result === '') {
            throw new \RuntimeException('智能翻译未返回有效文本');
        }
        return $result;
    }

    private static function httpJson(string $method, string $url, ?array $body = null, ?string $bearer = null, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        $headers = array_merge(['Accept: application/json'], $extraHeaders);
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('翻译请求失败：' . ($err ?: 'network'));
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("翻译接口失败 {$code}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('翻译接口返回非 JSON');
        }
        return $data;
    }

    /**
     * 即时翻译：多免费源自动降级，自动识别原文语言，无需系统翻译配置。
     *
     * @return array{text:string,detected:string,provider:string}
     */
    public static function translateInstant(string $text, string $to = 'zh'): array
    {
        $source = trim($text);
        if ($source === '') {
            throw new \RuntimeException('翻译内容为空');
        }
        $detected = self::heuristicLang($source);
        if ($to === 'zh' && $detected === 'zh') {
            return ['text' => $source, 'detected' => 'zh', 'provider' => 'skip-same-lang'];
        }

        $errors = [];
        foreach (['google-gtx', 'google-at', 'mymemory'] as $provider) {
            try {
                return match ($provider) {
                    'google-gtx' => self::translateInstantGoogle($source, $to, 'gtx'),
                    'google-at' => self::translateInstantGoogle($source, $to, 'at'),
                    default => self::translateInstantMyMemory($source, $to, $detected),
                };
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        throw new \RuntimeException('翻译服务暂不可用，请稍后再试');
    }

    /**
     * @return array{text:string,detected:string,provider:string}
     */
    private static function translateInstantGoogle(string $source, string $to, string $client): array
    {
        $tlMap = ['zh' => 'zh-CN', 'en' => 'en', 'ja' => 'ja', 'ko' => 'ko', 'es' => 'es'];
        $tl = $tlMap[$to] ?? 'zh-CN';
        $url = 'https://translate.googleapis.com/translate_a/single?client=' . rawurlencode($client)
            . '&sl=auto&tl=' . rawurlencode($tl) . '&dt=t&q=' . rawurlencode($source);

        $attempts = 0;
        $lastCode = 0;
        while ($attempts < 2) {
            $attempts++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ]);
            $raw = curl_exec($ch);
            $lastCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new \RuntimeException('翻译请求失败：' . ($err ?: 'network'));
            }
            if ($lastCode === 429 && $attempts < 2) {
                usleep(800000);
                continue;
            }
            if ($lastCode < 200 || $lastCode >= 300) {
                throw new \RuntimeException("翻译接口失败 {$lastCode}");
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException('翻译接口返回无效');
            }
            $result = trim((string)($data[0][0][0] ?? ''));
            if ($result === '') {
                throw new \RuntimeException('翻译接口未返回有效文本');
            }
            return [
                'text' => $result,
                'detected' => (string)($data[2] ?? 'auto'),
                'provider' => 'google-' . $client,
            ];
        }
        throw new \RuntimeException("翻译接口失败 {$lastCode}");
    }

    /**
     * @return array{text:string,detected:string,provider:string}
     */
    private static function translateInstantMyMemory(string $source, string $to, string $detected): array
    {
        $fromMap = ['zh' => 'zh-CN', 'en' => 'en', 'ja' => 'ja', 'ko' => 'ko', 'es' => 'es'];
        $tlMap = ['zh' => 'zh-CN', 'en' => 'en', 'ja' => 'ja', 'ko' => 'ko', 'es' => 'es'];
        $from = $fromMap[$detected] ?? ($detected !== 'auto' ? $detected : 'en');
        $tl = $tlMap[$to] ?? 'zh-CN';
        $url = 'https://api.mymemory.translated.net/get?q=' . rawurlencode($source)
            . '&langpair=' . rawurlencode($from . '|' . $tl);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TkSwarm/1.0)',
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('备用翻译请求失败：' . ($err ?: 'network'));
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("备用翻译接口失败 {$code}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('备用翻译返回无效');
        }
        $result = trim((string)($data['responseData']['translatedText'] ?? ''));
        if ($result === '' || stripos($result, 'QUERY LENGTH LIMIT') !== false) {
            throw new \RuntimeException('备用翻译未返回有效文本');
        }
        return [
            'text' => $result,
            'detected' => $detected !== 'auto' ? $detected : $from,
            'provider' => 'mymemory',
        ];
    }

    public static function heuristicLang(string $text): string
    {
        $s = $text;
        if (trim($s) === '') {
            return 'auto';
        }
        $hangul = preg_match_all('/[\x{AC00}-\x{D7AF}]/u', $s) ?: 0;
        $kana = preg_match_all('/[\x{3040}-\x{30FF}]/u', $s) ?: 0;
        $han = preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $s) ?: 0;
        $latin = preg_match_all('/[A-Za-z]/', $s) ?: 0;
        $total = max(1, $hangul + $kana + $han + $latin);
        if ($hangul / $total > 0.25) {
            return 'ko';
        }
        if ($kana / $total > 0.15) {
            return 'ja';
        }
        if ($han / $total > 0.25) {
            return 'zh';
        }
        if ($latin / $total > 0.4) {
            return 'en';
        }
        return 'auto';
    }
}
