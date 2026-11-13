<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Database\DB;
use App\Core\Schedule\Command;

/**
 * 修复历史上按 UTC 写入的 DATETIME（相对东八区慢 8 小时）。
 *
 * 用法（先 dry-run）:
 *   php artisan datetime:repair-offset
 *   php artisan datetime:repair-offset --apply
 *   php artisan datetime:repair-offset --apply --before="2026-11-13 17:00:00"
 */
class RepairDatetimeOffsetCommand extends Command
{
    protected string $signature = 'datetime:repair-offset';
    protected string $description = '将 materials/tasks 等表中偏 UTC 的创建/更新时间 +8 小时对齐东八区';

    public function handle(array $args = []): int
    {
        $apply = in_array('--apply', $args, true);
        $before = DB::now();
        foreach ($args as $a) {
            if (str_starts_with($a, '--before=')) {
                $before = trim(substr($a, 9), " \t\"'");
            }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $before)) {
            $this->error('无效 --before，期望 YYYY-MM-DD[ HH:mm:ss]');
            return 1;
        }
        if (strlen($before) === 10) {
            $before .= ' 23:59:59';
        } elseif (strlen($before) === 16) {
            $before .= ':00';
        }
        $before = str_replace('T', ' ', $before);

        $tables = [
            'materials' => ['created_at', 'updated_at'],
            'tasks' => ['created_at', 'updated_at'],
        ];

        $this->info(($apply ? 'APPLY' : 'DRY-RUN') . " before <= {$before}  (+8h)");
        $tz = DB::selectOne('SELECT @@session.time_zone AS sess, NOW() AS db_now, UTC_TIMESTAMP() AS utc_now');
        $this->info('session.time_zone=' . ($tz['sess'] ?? '?') . ' NOW=' . ($tz['db_now'] ?? '?') . ' UTC=' . ($tz['utc_now'] ?? '?'));

        foreach ($tables as $table => $cols) {
            foreach ($cols as $col) {
                $cntRow = DB::selectOne(
                    "SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` <= ? AND `{$col}` > '1970-01-01'",
                    [$before]
                );
                $cnt = (int)($cntRow['c'] ?? 0);
                $this->info("{$table}.{$col}: {$cnt} rows");
                if (!$apply || $cnt <= 0) {
                    continue;
                }
                DB::query(
                    "UPDATE `{$table}` SET `{$col}` = DATE_ADD(`{$col}`, INTERVAL 8 HOUR)
                     WHERE `{$col}` IS NOT NULL AND `{$col}` <= ? AND `{$col}` > '1970-01-01'",
                    [$before]
                );
                $this->info("  updated {$table}.{$col}");
            }
        }

        if (!$apply) {
            $this->info('未写入。确认后加上 --apply 执行。');
        }
        return 0;
    }
}
