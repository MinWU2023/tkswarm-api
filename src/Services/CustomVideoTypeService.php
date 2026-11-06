<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 视频交付类型（数据表）：配置分 × points_multiplier = 实际积分。
 */
class CustomVideoTypeService
{
    /** @var list<string> */
    private const PER_ITEM_UNITS = ['积分 / 张', '积分 / 条', '积分 / 次', '积分/10秒'];

    public static function ensureSchema(): void
    {
        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_type_settings (
              id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
              points_multiplier INT NOT NULL DEFAULT 50,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_types (
              id INT AUTO_INCREMENT PRIMARY KEY,
              code VARCHAR(64) NOT NULL,
              title VARCHAR(255) NOT NULL DEFAULT '',
              settlement_title VARCHAR(255) NOT NULL DEFAULT '',
              description TEXT NULL,
              points INT NULL,
              unit VARCHAR(64) NOT NULL DEFAULT '',
              guide_group VARCHAR(64) NULL,
              sort_order INT NOT NULL DEFAULT 0,
              enabled TINYINT NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uk_vdt_code (code),
              KEY idx_vdt_enabled_sort (enabled, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::seedIfEmpty();
    }

    private static function seedIfEmpty(): void
    {
        $settings = DB::selectOne('SELECT id FROM video_delivery_type_settings WHERE id=1');
        if (!$settings) {
            $mul = 50;
            try {
                $cfg = self::loadPhpConfig();
                $mul = max(1, (int)($cfg['points_multiplier'] ?? 50));
            } catch (\Throwable $e) {
            }
            DB::query(
                'INSERT INTO video_delivery_type_settings (id, points_multiplier) VALUES (1, ?)',
                [$mul]
            );
        }

        $count = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM video_delivery_types')['c'] ?? 0);
        if ($count > 0) {
            return;
        }
        $cfg = self::loadPhpConfig();
        $types = is_array($cfg['types'] ?? null) ? $cfg['types'] : [];
        $sort = 0;
        foreach ($types as $code => $row) {
            if (!is_array($row)) {
                continue;
            }
            $sort++;
            $points = array_key_exists('points', $row) && $row['points'] !== null && $row['points'] !== ''
                ? (int)$row['points']
                : null;
            DB::query(
                'INSERT INTO video_delivery_types
                  (code, title, settlement_title, description, points, unit, guide_group, sort_order, enabled)
                 VALUES (?,?,?,?,?,?,?,?,1)',
                [
                    (string)$code,
                    (string)($row['title'] ?? $code),
                    (string)($row['settlement_title'] ?? $row['title'] ?? $code),
                    (string)($row['description'] ?? ''),
                    $points,
                    (string)($row['unit'] ?? ''),
                    isset($row['guide_group']) && $row['guide_group'] !== null && $row['guide_group'] !== ''
                        ? (string)$row['guide_group']
                        : null,
                    $sort,
                ]
            );
        }
    }

    /** @return array<string,mixed> */
    private static function loadPhpConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/config/custom_video.php';
        if (!is_file($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
    }

    public static function pointsMultiplier(): int
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT points_multiplier FROM video_delivery_type_settings WHERE id=1');
        return max(1, (int)($row['points_multiplier'] ?? 50));
    }

    public static function savePointsMultiplier(int $multiplier): int
    {
        self::ensureSchema();
        $mul = max(1, $multiplier);
        DB::query(
            'INSERT INTO video_delivery_type_settings (id, points_multiplier) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE points_multiplier=VALUES(points_multiplier)',
            [$mul]
        );
        return $mul;
    }

    /** @return list<array<string,mixed>> */
    public static function listTypes(bool $enabledOnly = false): array
    {
        self::ensureSchema();
        $sql = 'SELECT * FROM video_delivery_types';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled=1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $rows = DB::select($sql) ?: [];
        $mul = self::pointsMultiplier();
        return array_map(static fn($r) => self::mapType($r, $mul), $rows);
    }

    public static function findByCode(string $code): ?array
    {
        self::ensureSchema();
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $row = DB::selectOne('SELECT * FROM video_delivery_types WHERE code=? LIMIT 1', [$code]);
        return $row ? self::mapType($row, self::pointsMultiplier()) : null;
    }

    /** @param array<string,mixed> $row */
    public static function mapType(array $row, ?int $multiplier = null): array
    {
        $mul = $multiplier ?? self::pointsMultiplier();
        $base = array_key_exists('points', $row) && $row['points'] !== null && $row['points'] !== ''
            ? (int)$row['points']
            : null;
        $unitPrice = $base !== null ? $base * $mul : null;
        $unit = (string)($row['unit'] ?? '');
        return [
            'id' => (int)($row['id'] ?? 0),
            'code' => (string)($row['code'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'settlementTitle' => (string)($row['settlement_title'] ?? $row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'points' => $base,
            'unit' => $unit,
            'guideGroup' => isset($row['guide_group']) && $row['guide_group'] !== null && $row['guide_group'] !== ''
                ? (string)$row['guide_group']
                : null,
            'sortOrder' => (int)($row['sort_order'] ?? 0),
            'enabled' => (int)($row['enabled'] ?? 1) === 1,
            'priced' => $unitPrice !== null,
            'unitPrice' => $unitPrice,
            'perItem' => in_array($unit, self::PER_ITEM_UNITS, true),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public static function resolveQuantity(array $type, int $qty): int
    {
        if (empty($type['perItem'])) {
            return 1;
        }
        return max(1, $qty);
    }

    /**
     * @return array{ok:bool,code:string,title:string,pointsCost:int,unitPrice:?int,quantity:int,manual:bool,message?:string}
     */
    public static function quote(string $code, int $quantity = 1, ?int $manualPoints = null): array
    {
        $type = self::findByCode($code);
        if (!$type) {
            return [
                'ok' => false,
                'code' => $code,
                'title' => $code,
                'pointsCost' => 0,
                'unitPrice' => null,
                'quantity' => 1,
                'manual' => false,
                'message' => '未知的视频交付类型：' . $code,
            ];
        }
        if (!$type['enabled']) {
            return [
                'ok' => false,
                'code' => $type['code'],
                'title' => $type['title'],
                'pointsCost' => 0,
                'unitPrice' => null,
                'quantity' => 1,
                'manual' => false,
                'message' => '该交付类型已停用：' . $type['title'],
            ];
        }
        $qty = self::resolveQuantity($type, $quantity);
        if ($type['unitPrice'] === null) {
            $cost = max(0, (int)($manualPoints ?? 0));
            return [
                'ok' => true,
                'code' => $type['code'],
                'title' => $type['title'],
                'pointsCost' => $cost,
                'unitPrice' => null,
                'quantity' => $qty,
                'manual' => true,
            ];
        }
        $cost = (int)$type['unitPrice'] * $qty;
        return [
            'ok' => true,
            'code' => $type['code'],
            'title' => $type['title'],
            'pointsCost' => $cost,
            'unitPrice' => (int)$type['unitPrice'],
            'quantity' => $qty,
            'manual' => false,
        ];
    }

    /** @param array<string,mixed> $input */
    public static function create(array $input): array
    {
        self::ensureSchema();
        $code = trim((string)($input['code'] ?? ''));
        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{1,62}$/', $code)) {
            throw new \InvalidArgumentException('类型编码须为小写字母开头的英文/数字/下划线（2～63）');
        }
        if (DB::selectOne('SELECT id FROM video_delivery_types WHERE code=?', [$code])) {
            throw new \InvalidArgumentException('类型编码已存在');
        }
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('请填写类型标题');
        }
        $settlement = trim((string)($input['settlementTitle'] ?? $input['settlement_title'] ?? $title));
        $desc = trim((string)($input['description'] ?? ''));
        $points = self::normalizePoints($input['points'] ?? null);
        $unit = trim((string)($input['unit'] ?? ''));
        $group = self::normalizeGroup($input['guideGroup'] ?? $input['guide_group'] ?? null);
        $sort = (int)($input['sortOrder'] ?? $input['sort_order'] ?? 0);
        $enabled = !empty($input['enabled']) || !array_key_exists('enabled', $input) ? 1 : 0;
        if ($sort <= 0) {
            $sort = (int)(DB::selectOne('SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM video_delivery_types')['n'] ?? 1);
        }
        DB::query(
            'INSERT INTO video_delivery_types
              (code, title, settlement_title, description, points, unit, guide_group, sort_order, enabled)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$code, $title, $settlement, $desc, $points, $unit, $group, $sort, $enabled]
        );
        return self::findByCode($code) ?: [];
    }

    /** @param array<string,mixed> $input */
    public static function update(int $id, array $input): array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM video_delivery_types WHERE id=?', [$id]);
        if (!$row) {
            throw new \RuntimeException('类型不存在');
        }
        $title = trim((string)($input['title'] ?? $row['title']));
        if ($title === '') {
            throw new \InvalidArgumentException('请填写类型标题');
        }
        $settlement = trim((string)($input['settlementTitle'] ?? $input['settlement_title'] ?? $row['settlement_title'] ?? $title));
        $desc = array_key_exists('description', $input)
            ? trim((string)$input['description'])
            : (string)($row['description'] ?? '');
        $points = array_key_exists('points', $input)
            ? self::normalizePoints($input['points'])
            : (isset($row['points']) ? (int)$row['points'] : null);
        $unit = array_key_exists('unit', $input)
            ? trim((string)$input['unit'])
            : (string)($row['unit'] ?? '');
        $group = array_key_exists('guideGroup', $input) || array_key_exists('guide_group', $input)
            ? self::normalizeGroup($input['guideGroup'] ?? $input['guide_group'] ?? null)
            : ($row['guide_group'] ?? null);
        $sort = (int)($input['sortOrder'] ?? $input['sort_order'] ?? $row['sort_order'] ?? 0);
        $enabled = array_key_exists('enabled', $input)
            ? (!empty($input['enabled']) ? 1 : 0)
            : (int)($row['enabled'] ?? 1);
        DB::query(
            'UPDATE video_delivery_types SET
              title=?, settlement_title=?, description=?, points=?, unit=?, guide_group=?,
              sort_order=?, enabled=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?',
            [$title, $settlement, $desc, $points, $unit, $group, $sort, $enabled, $id]
        );
        return self::findByCode((string)$row['code']) ?: [];
    }

    public static function delete(int $id): void
    {
        self::ensureSchema();
        DB::query('DELETE FROM video_delivery_types WHERE id=?', [$id]);
    }

    /** @return list<array{code:string,label:string}> */
    public static function guideGroupOptions(): array
    {
        $cfg = self::loadPhpConfig();
        $groups = is_array($cfg['guide_groups'] ?? null) ? $cfg['guide_groups'] : [
            'basic' => '基础视频制作',
            'revision' => '视频修改类',
            'creative' => '创意与批量生成',
        ];
        $out = [];
        foreach ($groups as $code => $label) {
            $out[] = ['code' => (string)$code, 'label' => (string)$label];
        }
        return $out;
    }

    private static function normalizePoints(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        return max(0, (int)$raw);
    }

    private static function normalizeGroup(mixed $raw): ?string
    {
        $g = trim((string)($raw ?? ''));
        return $g !== '' ? $g : null;
    }
}
