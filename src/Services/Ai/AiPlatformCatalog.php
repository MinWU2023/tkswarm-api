<?php
declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Database\DB;

/**
 * 国内外常见 AI 平台目录与表结构种子。
 */
class AiPlatformCatalog
{
    public static function ensure(): void
    {
        self::ensureTables();
        self::seedPlatforms();
        self::seedModels();
    }

    public static function ensureTables(): void
    {
        DB::query("CREATE TABLE IF NOT EXISTS `ai_platforms` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `code` VARCHAR(64) NOT NULL,
          `name` VARCHAR(128) NOT NULL,
          `region` VARCHAR(16) NOT NULL DEFAULT 'cn',
          `driver` VARCHAR(64) NOT NULL DEFAULT 'openai_compatible',
          `icon` VARCHAR(64) NOT NULL DEFAULT 'bot',
          `description` VARCHAR(512) NOT NULL DEFAULT '',
          `default_base_url` VARCHAR(512) NOT NULL DEFAULT '',
          `field_schema_json` MEDIUMTEXT NOT NULL,
          `enabled` TINYINT(1) NOT NULL DEFAULT 1,
          `sort_order` INT NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `uk_ai_platform_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::query("CREATE TABLE IF NOT EXISTS `ai_platform_configs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `platform_id` INT NOT NULL,
          `name` VARCHAR(128) NOT NULL,
          `config_json` MEDIUMTEXT NOT NULL,
          `enabled` TINYINT(1) NOT NULL DEFAULT 1,
          `is_active` TINYINT(1) NOT NULL DEFAULT 0,
          `remark` VARCHAR(512) NOT NULL DEFAULT '',
          `owner_id` INT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY `idx_ai_cfg_platform` (`platform_id`),
          KEY `idx_ai_cfg_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::query("CREATE TABLE IF NOT EXISTS `ai_platform_models` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `platform_id` INT NOT NULL,
          `code` VARCHAR(128) NOT NULL,
          `name` VARCHAR(128) NOT NULL DEFAULT '',
          `enabled` TINYINT(1) NOT NULL DEFAULT 1,
          `is_default` TINYINT(1) NOT NULL DEFAULT 0,
          `sort_order` INT NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `uk_ai_plat_model` (`platform_id`, `code`),
          KEY `idx_ai_plat_model_plat` (`platform_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function seedPlatforms(): void
    {
        self::pruneRemovedPlatforms();
        foreach (self::definitions() as $i => $p) {
            $schema = json_encode($p['fields'], JSON_UNESCAPED_UNICODE);
            DB::query(
                "INSERT INTO ai_platforms(code, name, region, driver, icon, description, default_base_url, field_schema_json, enabled, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,1,?)
                 ON DUPLICATE KEY UPDATE
                   name=VALUES(name),
                   region=VALUES(region),
                   driver=VALUES(driver),
                   icon=VALUES(icon),
                   description=VALUES(description),
                   default_base_url=VALUES(default_base_url),
                   field_schema_json=VALUES(field_schema_json),
                   sort_order=VALUES(sort_order)",
                [
                    $p['code'],
                    $p['name'],
                    $p['region'],
                    $p['driver'],
                    $p['icon'],
                    $p['description'],
                    $p['base_url'],
                    $schema,
                    $i + 1,
                ]
            );
        }
    }

    /** 下架已移除的内置平台及其配置 */
    private static function pruneRemovedPlatforms(): void
    {
        $codes = ['hunyuan', 'baidu_wenxin', 'groq', 'mistral', 'ollama'];
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        try {
            DB::query(
                "DELETE c FROM ai_platform_configs c
                 INNER JOIN ai_platforms p ON p.id=c.platform_id
                 WHERE p.code IN ({$placeholders})",
                $codes
            );
            DB::query("DELETE FROM ai_platforms WHERE code IN ({$placeholders})", $codes);
        } catch (\Throwable $e) {
        }
    }

    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        $openaiFields = [
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'secret', 'required' => true],
            ['key' => 'base_url', 'label' => 'API Base URL', 'type' => 'text', 'required' => false, 'placeholder' => '可留空使用平台默认'],
            ['key' => 'model', 'label' => '默认模型', 'type' => 'text', 'required' => true],
            ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7],
            ['key' => 'max_tokens', 'label' => '最大 Tokens', 'type' => 'number', 'required' => false, 'default' => 2048],
        ];

        return [
            [
                'code' => 'dashscope',
                'name' => '通义千问（阿里云）',
                'region' => 'cn',
                'driver' => 'openai_compatible',
                'icon' => 'cloud',
                'description' => '阿里云百炼 / DashScope，OpenAI 兼容；Base 填 https://dashscope.aliyuncs.com/compatible-mode/v1（勿带 /chat/completions，须 https）',
                'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                'fields' => self::withModel(array_map(static function (array $f) {
                    if (($f['key'] ?? '') === 'base_url') {
                        $f['placeholder'] = '默认 https://dashscope.aliyuncs.com/compatible-mode/v1';
                    }
                    return $f;
                }, $openaiFields), 'qwen-plus'),
            ],
            [
                'code' => 'zhipu',
                'name' => '智谱 GLM',
                'region' => 'cn',
                'driver' => 'openai_compatible',
                'icon' => 'cpu',
                'description' => '智谱清言 / GLM，OpenAI 兼容接口',
                'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
                'fields' => self::withModel($openaiFields, 'glm-4-flash'),
            ],
            [
                'code' => 'moonshot',
                'name' => 'Moonshot / Kimi',
                'region' => 'cn',
                'driver' => 'openai_compatible',
                'icon' => 'moon',
                'description' => '月之暗面 Kimi，OpenAI 兼容',
                'base_url' => 'https://api.moonshot.cn/v1',
                'fields' => self::withModel($openaiFields, 'moonshot-v1-8k'),
            ],
            [
                'code' => 'deepseek',
                'name' => 'DeepSeek',
                'region' => 'cn',
                'driver' => 'openai_compatible',
                'icon' => 'zap',
                'description' => '深度求索 DeepSeek，OpenAI 兼容',
                'base_url' => 'https://api.deepseek.com',
                'fields' => self::withModel($openaiFields, 'deepseek-chat'),
            ],
            [
                'code' => 'doubao',
                'name' => '豆包 / 火山方舟',
                'region' => 'cn',
                'driver' => 'openai_compatible',
                'icon' => 'box',
                'description' => '字节跳动火山方舟，OpenAI 兼容（endpoint 填方舟地址）',
                'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
                'fields' => array_merge(self::withModel($openaiFields, 'ep-xxxxxxxx'), [
                    ['key' => 'remark_hint', 'label' => '说明', 'type' => 'text', 'required' => false, 'placeholder' => 'model 填推理接入点 ID'],
                ]),
            ],
            [
                'code' => 'spark',
                'name' => '讯飞星火',
                'region' => 'cn',
                'driver' => 'openai_compatible',
                'icon' => 'activity',
                'description' => '讯飞星火 HTTP 兼容模式（按控制台地址填写 Base）',
                'base_url' => 'https://spark-api-open.xf-yun.com/v1',
                'fields' => self::withModel($openaiFields, 'generalv3.5'),
            ],
            [
                'code' => 'openai',
                'name' => 'OpenAI',
                'region' => 'intl',
                'driver' => 'openai_compatible',
                'icon' => 'bot',
                'description' => 'OpenAI 官方 Chat Completions',
                'base_url' => 'https://api.openai.com/v1',
                'fields' => array_merge(self::withModel($openaiFields, 'gpt-4o-mini'), [
                    ['key' => 'organization', 'label' => 'Organization', 'type' => 'text', 'required' => false],
                ]),
            ],
            [
                'code' => 'azure_openai',
                'name' => 'Azure OpenAI',
                'region' => 'intl',
                'driver' => 'azure_openai',
                'icon' => 'server',
                'description' => '微软 Azure OpenAI 服务',
                'base_url' => '',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'secret', 'required' => true],
                    ['key' => 'endpoint', 'label' => 'Endpoint', 'type' => 'text', 'required' => true, 'placeholder' => 'https://xxx.openai.azure.com'],
                    ['key' => 'deployment', 'label' => 'Deployment', 'type' => 'text', 'required' => true],
                    ['key' => 'api_version', 'label' => 'API Version', 'type' => 'text', 'required' => true, 'default' => '2024-02-15-preview'],
                    ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7],
                    ['key' => 'max_tokens', 'label' => '最大 Tokens', 'type' => 'number', 'required' => false, 'default' => 2048],
                ],
            ],
            [
                'code' => 'anthropic',
                'name' => 'Anthropic Claude',
                'region' => 'intl',
                'driver' => 'anthropic',
                'icon' => 'message-circle',
                'description' => 'Anthropic Claude Messages API',
                'base_url' => 'https://api.anthropic.com',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'secret', 'required' => true],
                    ['key' => 'base_url', 'label' => 'API Base URL', 'type' => 'text', 'required' => false],
                    ['key' => 'model', 'label' => '默认模型', 'type' => 'text', 'required' => true, 'default' => 'claude-3-5-sonnet-20241022'],
                    ['key' => 'max_tokens', 'label' => '最大 Tokens', 'type' => 'number', 'required' => true, 'default' => 2048],
                    ['key' => 'anthropic_version', 'label' => 'API Version', 'type' => 'text', 'required' => false, 'default' => '2023-06-01'],
                    ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7],
                ],
            ],
            [
                'code' => 'gemini',
                'name' => 'Google Gemini',
                'region' => 'intl',
                'driver' => 'gemini',
                'icon' => 'sparkles',
                'description' => 'Google AI Studio / Gemini API',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'secret', 'required' => true],
                    ['key' => 'base_url', 'label' => 'API Base URL', 'type' => 'text', 'required' => false],
                    ['key' => 'model', 'label' => '默认模型', 'type' => 'text', 'required' => true, 'default' => 'gemini-1.5-flash'],
                    ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7],
                    ['key' => 'max_tokens', 'label' => '最大输出 Tokens', 'type' => 'number', 'required' => false, 'default' => 2048],
                ],
            ],
            [
                'code' => 'openrouter',
                'name' => 'OpenRouter',
                'region' => 'intl',
                'driver' => 'openai_compatible',
                'icon' => 'share-2',
                'description' => 'OpenRouter 聚合多家模型',
                'base_url' => 'https://openrouter.ai/api/v1',
                'fields' => self::withModel($openaiFields, 'openai/gpt-4o-mini'),
            ],
        ];
    }

    public static function seedModels(): void
    {
        $byCode = self::defaultModels();
        $plats = DB::select('SELECT id, code FROM ai_platforms') ?: [];
        foreach ($plats as $p) {
            $code = (string)($p['code'] ?? '');
            $list = $byCode[$code] ?? [];
            if (!$list) {
                continue;
            }
            $pid = (int)$p['id'];
            foreach ($list as $i => $m) {
                $modelCode = (string)($m['code'] ?? '');
                if ($modelCode === '') {
                    continue;
                }
                $name = (string)($m['name'] ?? $modelCode);
                $isDefault = !empty($m['default']) ? 1 : 0;
                try {
                    DB::query(
                        "INSERT INTO ai_platform_models(platform_id, code, name, enabled, is_default, sort_order)
                         VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                           name=IF(name='', VALUES(name), name),
                           sort_order=VALUES(sort_order)",
                        [$pid, $modelCode, $name, 1, $isDefault, $i]
                    );
                } catch (\Throwable $e) {
                }
            }
        }
    }

    /** @return array<string,list<array{code:string,name:string,default?:bool}>> */
    public static function defaultModels(): array
    {
        return [
            // 完整免费模型清单见 migrations/040_dashscope_bailian_free_models.sql
            'dashscope' => [
                ['code' => 'qwen-plus', 'name' => '通义千问 Plus', 'default' => true],
                ['code' => 'qwen-turbo', 'name' => '通义千问 Turbo'],
                ['code' => 'qwen-flash', 'name' => '通义千问 Flash'],
                ['code' => 'qwen-max', 'name' => '通义千问 Max'],
                ['code' => 'qwen-long', 'name' => '通义千问 Long'],
                ['code' => 'qwen3.8-flash', 'name' => 'Qwen3.8 Flash'],
                ['code' => 'qwen3.7-plus', 'name' => 'Qwen3.7 Plus'],
                ['code' => 'qwen3.8-max', 'name' => 'Qwen3.8 Max'],
                ['code' => 'qwen3.5-flash', 'name' => 'Qwen3.5 Flash'],
                ['code' => 'deepseek-v3', 'name' => 'DeepSeek V3'],
                ['code' => 'deepseek-r1', 'name' => 'DeepSeek R1'],
                ['code' => 'kimi-k3', 'name' => 'Kimi K3'],
                ['code' => 'glm-5.2', 'name' => 'GLM-5.2'],
            ],
            'zhipu' => [
                ['code' => 'glm-4-flash', 'name' => 'GLM-4 Flash', 'default' => true],
                ['code' => 'glm-4', 'name' => 'GLM-4'],
                ['code' => 'glm-4-plus', 'name' => 'GLM-4 Plus'],
            ],
            'moonshot' => [
                ['code' => 'moonshot-v1-8k', 'name' => 'Kimi 8K', 'default' => true],
                ['code' => 'moonshot-v1-32k', 'name' => 'Kimi 32K'],
                ['code' => 'moonshot-v1-128k', 'name' => 'Kimi 128K'],
            ],
            'deepseek' => [
                ['code' => 'deepseek-chat', 'name' => 'DeepSeek Chat', 'default' => true],
                ['code' => 'deepseek-reasoner', 'name' => 'DeepSeek Reasoner'],
            ],
            'doubao' => [
                ['code' => 'doubao-pro-32k', 'name' => '豆包 Pro 32K', 'default' => true],
                ['code' => 'doubao-lite-32k', 'name' => '豆包 Lite 32K'],
            ],
            'spark' => [
                ['code' => 'generalv3.5', 'name' => '星火 3.5', 'default' => true],
                ['code' => 'generalv3', 'name' => '星火 3.0'],
                ['code' => '4.0Ultra', 'name' => '星火 4.0 Ultra'],
            ],
            'openai' => [
                ['code' => 'gpt-4o-mini', 'name' => 'GPT-4o mini', 'default' => true],
                ['code' => 'gpt-4o', 'name' => 'GPT-4o'],
                ['code' => 'gpt-4.1', 'name' => 'GPT-4.1'],
                ['code' => 'o4-mini', 'name' => 'o4-mini'],
            ],
            'azure_openai' => [
                ['code' => 'gpt-4o-mini', 'name' => 'GPT-4o mini', 'default' => true],
                ['code' => 'gpt-4o', 'name' => 'GPT-4o'],
            ],
            'anthropic' => [
                ['code' => 'claude-3-5-sonnet-20241022', 'name' => 'Claude 3.5 Sonnet', 'default' => true],
                ['code' => 'claude-3-5-haiku-20241022', 'name' => 'Claude 3.5 Haiku'],
                ['code' => 'claude-3-opus-20240229', 'name' => 'Claude 3 Opus'],
            ],
            'gemini' => [
                ['code' => 'gemini-1.5-flash', 'name' => 'Gemini 1.5 Flash', 'default' => true],
                ['code' => 'gemini-1.5-pro', 'name' => 'Gemini 1.5 Pro'],
                ['code' => 'gemini-2.0-flash', 'name' => 'Gemini 2.0 Flash'],
            ],
            'openrouter' => [
                ['code' => 'openai/gpt-4o-mini', 'name' => 'GPT-4o mini', 'default' => true],
                ['code' => 'anthropic/claude-3.5-sonnet', 'name' => 'Claude 3.5 Sonnet'],
                ['code' => 'google/gemini-flash-1.5', 'name' => 'Gemini Flash 1.5'],
            ],
        ];
    }

    /** @param list<array<string,mixed>> $fields @return list<array<string,mixed>> */
    private static function withModel(array $fields, string $model): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (($f['key'] ?? '') === 'model') {
                $f['default'] = $model;
            }
            $out[] = $f;
        }
        return $out;
    }
}
