<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

class CountryDataService
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `country_data` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `code` CHAR(2) NOT NULL,
              `name_zh` VARCHAR(128) NOT NULL DEFAULT '',
              `name_en` VARCHAR(128) NOT NULL DEFAULT '',
              `continent` VARCHAR(32) NOT NULL DEFAULT '',
              `sort_order` INT NOT NULL DEFAULT 0,
              `enabled` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_country_code` (`code`),
              KEY `idx_country_enabled` (`enabled`),
              KEY `idx_country_name_zh` (`name_zh`),
              KEY `idx_country_name_en` (`name_en`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
        }
    }

    public static function seedIfEmpty(): int
    {
        self::ensureSchema();
        $count = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM country_data')['c'] ?? 0);
        if ($count > 0) {
            return 0;
        }
        return self::importRows(CountryDataSeed::rows());
    }

    /** @param list<array{code:string,nameZh:string,nameEn:string,sortOrder?:int,continent?:string}> $rows */
    public static function importRows(array $rows): int
    {
        self::ensureSchema();
        $inserted = 0;
        foreach ($rows as $row) {
            $code = strtoupper(trim((string)($row['code'] ?? '')));
            $nameZh = trim((string)($row['nameZh'] ?? ''));
            $nameEn = trim((string)($row['nameEn'] ?? ''));
            if ($code === '' || ($nameZh === '' && $nameEn === '')) {
                continue;
            }
            $exist = DB::selectOne('SELECT id FROM country_data WHERE code=?', [$code]);
            if ($exist) {
                continue;
            }
            DB::table('country_data')->insert([
                'code' => mb_substr($code, 0, 2),
                'name_zh' => mb_substr($nameZh !== '' ? $nameZh : $nameEn, 0, 128),
                'name_en' => mb_substr($nameEn !== '' ? $nameEn : $nameZh, 0, 128),
                'continent' => mb_substr((string)($row['continent'] ?? ''), 0, 32),
                'sort_order' => (int)($row['sortOrder'] ?? 0),
                'enabled' => 1,
            ]);
            $inserted++;
        }
        return $inserted;
    }

    /** @param array<string,mixed> $row */
    public static function mapRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'code' => strtoupper((string)($row['code'] ?? '')),
            'nameZh' => (string)($row['name_zh'] ?? ''),
            'nameEn' => (string)($row['name_en'] ?? ''),
            'continent' => (string)($row['continent'] ?? ''),
            'sortOrder' => (int)($row['sort_order'] ?? 0),
            'enabled' => !empty($row['enabled']),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * 展示用中文国名：库内存 ISO/英文仍可解析；查不到则原样返回。
     * @param list<string> $values
     * @return array<string,string> 原始值 => 展示文案
     */
    public static function displayLabels(array $values): array
    {
        self::ensureSchema();
        self::seedIfEmpty();

        $out = [];
        $needLookup = [];
        foreach ($values as $raw) {
            $v = trim((string)$raw);
            if ($v === '') {
                $out[$v] = '';
                continue;
            }
            if (preg_match('/[\x{4e00}-\x{9fff}]/u', $v)) {
                $out[$v] = $v;
                continue;
            }
            $needLookup[$v] = true;
        }
        if ($needLookup === []) {
            return $out;
        }

        $rows = [];
        try {
            $rows = DB::select(
                'SELECT code, name_zh, name_en FROM country_data WHERE enabled=1'
            ) ?: [];
        } catch (\Throwable $e) {
            $rows = [];
        }

        $byCode = [];
        $byEn = [];
        $byZh = [];
        foreach ($rows as $row) {
            $code = strtoupper(trim((string)($row['code'] ?? '')));
            $zh = trim((string)($row['name_zh'] ?? ''));
            $en = trim((string)($row['name_en'] ?? ''));
            if ($code !== '' && $zh !== '') {
                $byCode[$code] = $zh;
            }
            if ($en !== '' && $zh !== '') {
                $byEn[strtolower($en)] = $zh;
            }
            if ($zh !== '') {
                $byZh[strtolower($zh)] = $zh;
            }
        }

        foreach (array_keys($needLookup) as $v) {
            $code = strtoupper($v);
            if (isset($byCode[$code])) {
                $out[$v] = $byCode[$code];
                continue;
            }
            $lower = strtolower($v);
            if (isset($byEn[$lower])) {
                $out[$v] = $byEn[$lower];
                continue;
            }
            if (isset($byZh[$lower])) {
                $out[$v] = $byZh[$lower];
                continue;
            }
            $out[$v] = $v;
        }
        return $out;
    }

    public static function displayLabel(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }
        $map = self::displayLabels([$v]);
        return $map[$v] ?? $v;
    }
}
