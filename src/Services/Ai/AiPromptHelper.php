<?php
declare(strict_types=1);

namespace App\Services\Ai;

/**
 * AI 提示词：变量提取、渲染、校验。
 * 占位符格式：{{var_name}}（也兼容 {var_name}）
 */
class AiPromptHelper
{
    public const PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}|\{([a-zA-Z_][a-zA-Z0-9_]*)\}/u';

    /** @return list<string> */
    public static function extractKeys(string $content): array
    {
        if ($content === '') {
            return [];
        }
        preg_match_all(self::PLACEHOLDER_PATTERN, $content, $m);
        $keys = [];
        foreach (array_merge($m[1] ?? [], $m[2] ?? []) as $k) {
            $k = trim((string)$k);
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
        return array_keys($keys);
    }

    /**
     * @param list<array<string,mixed>>|string|null $raw
     * @return list<array{key:string,label:string,required:bool,default:string,example:string,type:string}>
     */
    public static function normalizeVariables($raw, string $content = ''): array
    {
        $list = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                // 逗号分隔简易写法
                $raw = array_map(static fn ($k) => ['key' => trim($k)], preg_split('/[,，\s]+/', $raw) ?: []);
            }
        }
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_string($item)) {
                    $item = ['key' => $item];
                }
                if (!is_array($item)) {
                    continue;
                }
                $key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($item['key'] ?? '')) ?? '';
                if ($key === '') {
                    continue;
                }
                $type = strtolower(trim((string)($item['type'] ?? 'text')));
                if (!in_array($type, ['text', 'textarea', 'number'], true)) {
                    $type = 'text';
                }
                $list[$key] = [
                    'key' => $key,
                    'label' => trim((string)($item['label'] ?? $key)) ?: $key,
                    'required' => !empty($item['required']),
                    'default' => (string)($item['default'] ?? ''),
                    'example' => (string)($item['example'] ?? ''),
                    'type' => $type,
                ];
            }
        }
        // 正文中出现但未声明的变量自动补全
        foreach (self::extractKeys($content) as $key) {
            if (!isset($list[$key])) {
                $list[$key] = [
                    'key' => $key,
                    'label' => $key,
                    'required' => true,
                    'default' => '',
                    'example' => '',
                    'type' => 'text',
                ];
            }
        }
        return array_values($list);
    }

    /**
     * @param list<array<string,mixed>> $variables
     * @param array<string,mixed> $values
     * @return array{ok:bool,missing:list<string>,values:array<string,string>}
     */
    public static function resolveValues(array $variables, array $values): array
    {
        $out = [];
        $missing = [];
        foreach ($variables as $v) {
            $key = (string)($v['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $val = $values[$key] ?? null;
            if ($val === null || $val === '') {
                $val = $v['default'] ?? '';
            }
            $val = is_scalar($val) ? trim((string)$val) : '';
            if ($val === '' && !empty($v['required'])) {
                $missing[] = (string)($v['label'] ?? $key);
            }
            $out[$key] = $val;
        }
        return ['ok' => $missing === [], 'missing' => $missing, 'values' => $out];
    }

    /**
     * @param array<string,scalar|null> $values
     */
    public static function render(string $content, array $values, bool $keepUnknown = false): string
    {
        return (string)preg_replace_callback(self::PLACEHOLDER_PATTERN, static function ($m) use ($values, $keepUnknown) {
            $key = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            if ($key === '') {
                return $m[0];
            }
            if (array_key_exists($key, $values) && $values[$key] !== null && $values[$key] !== '') {
                return (string)$values[$key];
            }
            return $keepUnknown ? $m[0] : '';
        }, $content);
    }

    /** @param list<array<string,mixed>> $variables */
    public static function variablesSummary(array $variables): string
    {
        if (!$variables) {
            return '';
        }
        return implode(', ', array_map(static fn ($v) => '{{' . ($v['key'] ?? '') . '}}', $variables));
    }
}
