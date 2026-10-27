<?php
declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Database\DB;
use App\Services\Ai\Drivers\AiDriverInterface;
use App\Services\Ai\Drivers\AnthropicDriver;
use App\Services\Ai\Drivers\AzureOpenAiDriver;
use App\Services\Ai\Drivers\GeminiDriver;
use App\Services\Ai\Drivers\OpenAiCompatibleDriver;
use App\Services\Ai\Drivers\WenxinDriver;

/**
 * AI 总类：按平台驱动分发 chat，读取全局启用配置。
 */
class AiClient
{
    /** @var array<string,AiDriverInterface> */
    private static array $drivers = [];

    public static function registerDriver(AiDriverInterface $driver): void
    {
        self::$drivers[$driver->code()] = $driver;
    }

    public static function driver(string $code): AiDriverInterface
    {
        self::bootDrivers();
        if (!isset(self::$drivers[$code])) {
            throw new \RuntimeException('未知 AI 驱动：' . $code);
        }
        return self::$drivers[$code];
    }

    private static function bootDrivers(): void
    {
        if (self::$drivers) {
            return;
        }
        foreach ([
            new OpenAiCompatibleDriver(),
            new AzureOpenAiDriver(),
            new AnthropicDriver(),
            new GeminiDriver(),
            new WenxinDriver(),
        ] as $d) {
            self::$drivers[$d->code()] = $d;
        }
    }

    /**
     * 使用指定配置或全局启用配置发起对话。
     *
     * @param list<array{role?:string,content?:mixed}> $messages
     * @param array<string,mixed> $options config_id / platform_code / model / temperature / operation / skip_log ...
     * @return array{content:string,raw:array,usage?:?array,model?:string,platform:?array,config_id:?int}
     */
    public static function chat(array $messages, array $options = []): array
    {
        $started = microtime(true);
        $operation = (string)($options['operation'] ?? 'chat');
        $skipLog = !empty($options['skip_log']);
        $bundle = null;
        try {
            $bundle = self::resolveBundle($options);
            $driver = self::driver((string)$bundle['platform']['driver']);
            $result = $driver->chat($bundle['config'], $messages, $options);
            $result['platform'] = [
                'id' => (int)$bundle['platform']['id'],
                'code' => $bundle['platform']['code'],
                'name' => $bundle['platform']['name'],
                'driver' => $bundle['platform']['driver'],
            ];
            $result['config_id'] = (int)$bundle['config_row']['id'];
            if (!$skipLog) {
                AiCallLogger::record([
                    'platform_id' => (int)$bundle['platform']['id'],
                    'platform_code' => (string)$bundle['platform']['code'],
                    'platform_name' => (string)$bundle['platform']['name'],
                    'config_id' => (int)$bundle['config_row']['id'],
                    'config_name' => (string)($bundle['config_row']['name'] ?? ''),
                    'operation' => $operation,
                    'status' => 'success',
                    'model' => (string)($result['model'] ?? ($bundle['config']['model'] ?? '')),
                    'request_content' => $messages,
                    'response_content' => (string)($result['content'] ?? ''),
                    'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                    'meta' => [
                        'usage' => $result['usage'] ?? null,
                        'driver' => $bundle['platform']['driver'] ?? null,
                    ],
                ]);
            }
            return $result;
        } catch (\Throwable $e) {
            if (!$skipLog) {
                AiCallLogger::record([
                    'platform_id' => $bundle ? (int)$bundle['platform']['id'] : (isset($options['config_id']) ? null : null),
                    'platform_code' => $bundle ? (string)$bundle['platform']['code'] : (string)($options['platform_code'] ?? ''),
                    'platform_name' => $bundle ? (string)$bundle['platform']['name'] : '',
                    'config_id' => $bundle ? (int)$bundle['config_row']['id'] : (isset($options['config_id']) ? (int)$options['config_id'] : null),
                    'config_name' => $bundle ? (string)($bundle['config_row']['name'] ?? '') : '',
                    'operation' => $operation,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'model' => $bundle ? (string)($bundle['config']['model'] ?? '') : '',
                    'request_content' => $messages,
                    'response_content' => '',
                    'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                ]);
            }
            throw $e;
        }
    }

    public static function chatText(string $prompt, array $options = [], string $system = ''): string
    {
        $messages = [];
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];
        return (string)self::chat($messages, $options)['content'];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{platform:array,config_row:array,config:array<string,mixed>,schema:list}
     */
    public static function resolveBundle(array $options = []): array
    {
        AiPlatformCatalog::ensure();
        $configId = isset($options['config_id']) ? (int)$options['config_id'] : 0;
        $row = null;
        if ($configId > 0) {
            $row = DB::selectOne(
                'SELECT c.*, p.code AS platform_code, p.name AS platform_name, p.driver, p.field_schema_json, p.default_base_url, p.enabled AS platform_enabled
                 FROM ai_platform_configs c
                 INNER JOIN ai_platforms p ON p.id=c.platform_id
                 WHERE c.id=?',
                [$configId]
            );
        } elseif (!empty($options['platform_id'])) {
            $pid = (int)$options['platform_id'];
            $row = DB::selectOne(
                'SELECT c.*, p.code AS platform_code, p.name AS platform_name, p.driver, p.field_schema_json, p.default_base_url, p.enabled AS platform_enabled
                 FROM ai_platform_configs c
                 INNER JOIN ai_platforms p ON p.id=c.platform_id
                 WHERE p.id=? AND c.enabled=1 AND p.enabled=1
                 ORDER BY c.is_active DESC, c.id DESC LIMIT 1',
                [$pid]
            );
        } elseif (!empty($options['platform_code'])) {
            $row = DB::selectOne(
                'SELECT c.*, p.code AS platform_code, p.name AS platform_name, p.driver, p.field_schema_json, p.default_base_url, p.enabled AS platform_enabled
                 FROM ai_platform_configs c
                 INNER JOIN ai_platforms p ON p.id=c.platform_id
                 WHERE p.code=? AND c.is_active=1 AND c.enabled=1
                 ORDER BY c.id DESC LIMIT 1',
                [(string)$options['platform_code']]
            );
            if (!$row) {
                $row = DB::selectOne(
                    'SELECT c.*, p.code AS platform_code, p.name AS platform_name, p.driver, p.field_schema_json, p.default_base_url, p.enabled AS platform_enabled
                     FROM ai_platform_configs c
                     INNER JOIN ai_platforms p ON p.id=c.platform_id
                     WHERE p.code=? AND c.enabled=1
                     ORDER BY c.is_active DESC, c.id DESC LIMIT 1',
                    [(string)$options['platform_code']]
                );
            }
        } else {
            $row = DB::selectOne(
                'SELECT c.*, p.code AS platform_code, p.name AS platform_name, p.driver, p.field_schema_json, p.default_base_url, p.enabled AS platform_enabled
                 FROM ai_platform_configs c
                 INNER JOIN ai_platforms p ON p.id=c.platform_id
                 WHERE c.is_active=1 AND c.enabled=1 AND p.enabled=1
                 ORDER BY c.id DESC LIMIT 1'
            );
        }
        if (!$row) {
            throw new \RuntimeException('请先在「AI开放平台」启用一套开发配置');
        }
        if (empty($row['enabled']) || empty($row['platform_enabled'])) {
            throw new \RuntimeException('所选平台或配置已禁用');
        }
        $schema = json_decode((string)($row['field_schema_json'] ?? '[]'), true);
        if (!is_array($schema)) {
            $schema = [];
        }
        $config = json_decode((string)($row['config_json'] ?? '{}'), true);
        if (!is_array($config)) {
            $config = [];
        }
        $secretKeys = AiHelper::secretKeysFromSchema($schema);
        $config = AiHelper::decryptSecrets($config, $secretKeys);
        if (empty($config['base_url']) && !empty($row['default_base_url'])) {
            $config['base_url'] = $row['default_base_url'];
        }
        return [
            'platform' => [
                'id' => (int)$row['platform_id'],
                'code' => $row['platform_code'],
                'name' => $row['platform_name'],
                'driver' => $row['driver'],
            ],
            'config_row' => $row,
            'config' => $config,
            'schema' => $schema,
        ];
    }
}
