<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 基础设置按登录用户隔离；系统级键仍存 settings 表。
 */
class UserSettingsService
{
    /** @var list<string> */
    private const GLOBAL_KEYS = [
        'authEnabled',
        'landingDownloadWinUrl',
        'landingDownloadMacUrl',
        'smtp.host',
        'smtp.port',
        'smtp.encryption',
        'smtp.username',
        'smtp.password',
        'smtp.from_email',
        'smtp.from_name',
    ];

    /** @var array<string,bool> */
    private static array $migratedUsers = [];

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `user_settings` (
              `user_id` INT NOT NULL,
              `key` VARCHAR(100) NOT NULL,
              `value` TEXT NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`user_id`, `key`),
              KEY `idx_user_settings_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
        }
    }

    public static function isGlobalKey(string $key): bool
    {
        return in_array($key, self::GLOBAL_KEYS, true)
            || str_starts_with($key, 'captcha:')
            || str_starts_with($key, 'smtp.');
    }

    /** 设置归属登录用户（不受身份切换影响） */
    public static function settingsUserId(): int
    {
        DataScope::bootFromGlobals();
        return (int)(DataScope::loginUser()['id'] ?? 0);
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'browserType' => 'bit',
            'browserBackend' => 'auto',
            'browserApiUrl' => 'http://127.0.0.1:54345',
            'language' => 'zh-CN',
            'clockTimezone' => 'Asia/Shanghai',
            'clockCountryCode' => 'CN',
            'clockCountryName' => '中国',
            'clocks' => [
                ['name' => '中国 · 上海', 'tz' => 'Asia/Shanghai'],
                ['name' => '美国 · 纽约', 'tz' => 'America/New_York'],
                ['name' => '英国 · 伦敦', 'tz' => 'Europe/London'],
            ],
            'browserWindowMode' => 'visible',
            'enabledBrowsers' => ['bit'],
            'messageSyncInterval' => 10,
            /** 本机自研浏览器任务并发（每登录用户独立；Node 执行器读取） */
            'taskConcurrency' => 6,
            'privateMessageSync' => true,
            'messageSyncMode' => 'api',
            'dmSyncBitFallback' => true,
            'notificationSync' => true,
            'notificationInterval' => 1,
            'notificationSound' => 'chime',
            'translationOnReceive' => false,
            'translationMode' => 'off',
            'translationApiUrl' => '',
            'translationApiKey' => '',
            'translationModelId' => 'gpt-3.5-turbo',
            'translationTarget' => 'zh',
            'translationQuota' => 0,
            'translationUsed' => 0,
            'dmTemplateEnabled' => true,
            'fansSyncMaxPerRun' => 500,
            'authEnabled' => false,
            'landingDownloadWinUrl' => '/lastest.exe',
            'landingDownloadMacUrl' => '/lastest.dmg',
            'smtp.host' => '',
            'smtp.port' => '465',
            'smtp.encryption' => 'ssl',
            'smtp.username' => '',
            'smtp.password' => '',
            'smtp.from_email' => '',
            'smtp.from_name' => 'TkSwarm',
        ];
    }

    /** 官网首页公开配置（无需登录） */
    public static function getPublicSiteConfig(): array
    {
        return [
            'landingDownloadWinUrl' => (string)self::setting('landingDownloadWinUrl', '/lastest.exe'),
            'landingDownloadMacUrl' => (string)self::setting('landingDownloadMacUrl', '/lastest.dmg'),
        ];
    }

    /** @return array<string,mixed> */
    public static function getForUser(?int $userId = null, bool $maskSecrets = true): array
    {
        self::ensureSchema();
        $userId = $userId ?? self::settingsUserId();
        self::maybeMigrateFromGlobal($userId);

        $map = array_merge(self::defaults(), self::loadGlobalSettings(), self::loadUserSettings($userId));
        if ($maskSecrets) {
            if (!empty($map['browserApiToken'])) {
                $map['browserApiToken'] = '******';
            }
            if (!empty($map['tkswarmBrowserApiToken'])) {
                $map['tkswarmBrowserApiToken'] = '******';
            }
            if (!empty($map['translationApiKey'])) {
                $map['translationApiKey'] = '******';
            }
            if (!empty($map['smtp.password'])) {
                $map['smtp.password'] = '******';
            }
        }
        return $map;
    }

    /** @param array<string,mixed> $all */
    public static function saveForUser(array $all, ?int $userId = null): void
    {
        self::ensureSchema();
        $userId = $userId ?? self::settingsUserId();
        foreach ($all as $key => $value) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            if (self::isGlobalKey($key)) {
                self::saveGlobal($key, $value);
                continue;
            }
            self::saveUser($userId, $key, $value);
        }
    }

    public static function setting(string $key, mixed $fallback = null, ?int $userId = null): mixed
    {
        if (self::isGlobalKey($key)) {
            return self::loadGlobalSettings()[$key] ?? $fallback;
        }
        $map = self::getForUser($userId, false);
        return array_key_exists($key, $map) ? $map[$key] : $fallback;
    }

    public static function setSetting(string $key, mixed $value, ?int $userId = null): void
    {
        self::saveForUser([$key => $value], $userId);
    }

    /** @return array<string,mixed> */
    private static function loadGlobalSettings(): array
    {
        $out = [];
        try {
            $rows = DB::select('SELECT `key`, `value` FROM settings') ?: [];
            foreach ($rows as $row) {
                $key = (string)($row['key'] ?? '');
                if ($key === '' || !self::isGlobalKey($key)) {
                    continue;
                }
                $out[$key] = self::decodeValue((string)($row['value'] ?? ''));
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function loadUserSettings(int $userId): array
    {
        $out = [];
        try {
            $rows = DB::select(
                'SELECT `key`, `value` FROM user_settings WHERE user_id=?',
                [$userId]
            ) ?: [];
            foreach ($rows as $row) {
                $key = (string)($row['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $out[$key] = self::decodeValue((string)($row['value'] ?? ''));
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    private static function maybeMigrateFromGlobal(int $userId): void
    {
        if ($userId < 0 || isset(self::$migratedUsers[$userId])) {
            return;
        }
        self::$migratedUsers[$userId] = true;
        try {
            $count = (int)(DB::selectOne(
                'SELECT COUNT(*) AS c FROM user_settings WHERE user_id=?',
                [$userId]
            )['c'] ?? 0);
            if ($count > 0) {
                return;
            }
            $rows = DB::select('SELECT `key`, `value` FROM settings') ?: [];
            if (!$rows) {
                return;
            }
            $now = date('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $key = (string)($row['key'] ?? '');
                if ($key === '' || self::isGlobalKey($key)) {
                    continue;
                }
                DB::query(
                    'INSERT INTO user_settings(user_id, `key`, `value`, created_at, updated_at)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=VALUES(updated_at)',
                    [$userId, $key, (string)($row['value'] ?? ''), $now, $now]
                );
            }
        } catch (\Throwable $e) {
        }
    }

    private static function saveGlobal(string $key, mixed $value): void
    {
        DB::query(
            'INSERT INTO settings(`key`, `value`) VALUES (?,?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=CURRENT_TIMESTAMP',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE)]
        );
    }

    private static function saveUser(int $userId, string $key, mixed $value): void
    {
        DB::query(
            'INSERT INTO user_settings(user_id, `key`, `value`) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=CURRENT_TIMESTAMP',
            [$userId, $key, json_encode($value, JSON_UNESCAPED_UNICODE)]
        );
    }

    private static function decodeValue(string $raw): mixed
    {
        $decoded = json_decode($raw, true);
        return $decoded !== null || $raw === 'null' ? $decoded : $raw;
    }
}
