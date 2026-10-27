<?php
declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Database\DB;
use App\Services\DataScope;

/**
 * AI 调用记录：平台 / 配置 / 用户 / 操作 / 时间 / 输入输出体积 / 状态 / 内容
 */
class AiCallLogger
{
    public static function ensureTable(): void
    {
        DB::query("CREATE TABLE IF NOT EXISTS `ai_call_logs` (
          `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
          `platform_id` INT NULL,
          `platform_code` VARCHAR(64) NOT NULL DEFAULT '',
          `platform_name` VARCHAR(128) NOT NULL DEFAULT '',
          `config_id` INT NULL,
          `config_name` VARCHAR(128) NOT NULL DEFAULT '',
          `user_id` INT NULL,
          `username` VARCHAR(128) NOT NULL DEFAULT '',
          `operation` VARCHAR(64) NOT NULL DEFAULT 'chat',
          `status` VARCHAR(16) NOT NULL DEFAULT 'success',
          `error_message` VARCHAR(1000) NOT NULL DEFAULT '',
          `model` VARCHAR(128) NOT NULL DEFAULT '',
          `input_bytes` INT NOT NULL DEFAULT 0,
          `output_bytes` INT NOT NULL DEFAULT 0,
          `duration_ms` INT NOT NULL DEFAULT 0,
          `request_content` MEDIUMTEXT NULL,
          `response_content` MEDIUMTEXT NULL,
          `meta_json` TEXT NULL,
          `owner_id` INT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          KEY `idx_ai_log_created` (`created_at`),
          KEY `idx_ai_log_platform` (`platform_id`),
          KEY `idx_ai_log_config` (`config_id`),
          KEY `idx_ai_log_user` (`user_id`),
          KEY `idx_ai_log_status` (`status`),
          KEY `idx_ai_log_owner` (`owner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function record(array $payload): ?int
    {
        try {
            self::ensureTable();
            $request = self::stringify($payload['request_content'] ?? '');
            $response = self::stringify($payload['response_content'] ?? '');
            $inputBytes = isset($payload['input_bytes'])
                ? (int)$payload['input_bytes']
                : self::byteLength($request);
            $outputBytes = isset($payload['output_bytes'])
                ? (int)$payload['output_bytes']
                : self::byteLength($response);

            $user = DataScope::user();
            $userId = (int)($payload['user_id'] ?? ($user['id'] ?? 0));
            $username = (string)($payload['username'] ?? ($user['username'] ?? $user['nickname'] ?? ''));
            if ($username === '' && $userId > 0) {
                $username = 'user#' . $userId;
            }

            $ownerId = null;
            try {
                $ownerId = DataScope::writeOwnerId();
            } catch (\Throwable $e) {
                $ownerId = $userId > 0 ? $userId : null;
            }

            $meta = $payload['meta'] ?? null;
            $row = [
                'platform_id' => isset($payload['platform_id']) ? (int)$payload['platform_id'] : null,
                'platform_code' => mb_substr((string)($payload['platform_code'] ?? ''), 0, 64),
                'platform_name' => mb_substr((string)($payload['platform_name'] ?? ''), 0, 128),
                'config_id' => isset($payload['config_id']) ? (int)$payload['config_id'] : null,
                'config_name' => mb_substr((string)($payload['config_name'] ?? ''), 0, 128),
                'user_id' => $userId > 0 ? $userId : null,
                'username' => mb_substr($username, 0, 128),
                'operation' => mb_substr((string)($payload['operation'] ?? 'chat'), 0, 64),
                'status' => (($payload['status'] ?? 'success') === 'failed') ? 'failed' : 'success',
                'error_message' => mb_substr((string)($payload['error_message'] ?? ''), 0, 1000),
                'model' => mb_substr((string)($payload['model'] ?? ''), 0, 128),
                'input_bytes' => max(0, $inputBytes),
                'output_bytes' => max(0, $outputBytes),
                'duration_ms' => max(0, (int)($payload['duration_ms'] ?? 0)),
                'request_content' => $request !== '' ? $request : null,
                'response_content' => $response !== '' ? $response : null,
                'meta_json' => is_array($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'owner_id' => $ownerId,
            ];
            return (int)DB::table('ai_call_logs')->insert($row);
        } catch (\Throwable $e) {
            // 记录失败不影响主流程
            return null;
        }
    }

    /** @param mixed $value */
    public static function stringify($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }

    public static function byteLength(string $text): int
    {
        return strlen($text);
    }

    public static function bytesToKb(int $bytes): float
    {
        return round($bytes / 1024, 3);
    }

    /** @param list<array{role?:string,content?:mixed}> $messages */
    public static function messagesToText(array $messages): string
    {
        return self::stringify($messages);
    }
}
