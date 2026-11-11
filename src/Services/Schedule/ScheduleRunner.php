<?php
declare(strict_types=1);

namespace App\Services\Schedule;

use App\Core\Database\DB;
use App\Core\Schedule\Scheduler;

final class ScheduleRunner
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query(
                "CREATE TABLE IF NOT EXISTS `scheduled_jobs` (
                  `job_key` VARCHAR(64) NOT NULL,
                  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                  `last_run_at` DATETIME NULL,
                  `last_status` VARCHAR(32) NULL,
                  `last_message` VARCHAR(500) NULL,
                  `last_duration_ms` INT NULL,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`job_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
        }
        self::syncRegistryRows();
    }

    /** 将代码登记项落入 DB（仅插入缺失行） */
    public static function syncRegistryRows(): void
    {
        foreach (ScheduleRegistry::definitions() as $def) {
            $key = (string)$def['key'];
            $exists = DB::selectOne('SELECT job_key FROM scheduled_jobs WHERE job_key=?', [$key]);
            if ($exists) {
                continue;
            }
            $enabled = !empty($def['defaultEnabled']) ? 1 : 0;
            try {
                DB::query(
                    'INSERT INTO scheduled_jobs(job_key, enabled) VALUES (?,?)',
                    [$key, $enabled]
                );
            } catch (\Throwable $e) {
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public static function listJobs(): array
    {
        self::ensureSchema();
        $rows = DB::select('SELECT * FROM scheduled_jobs') ?: [];
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[(string)($r['job_key'] ?? '')] = $r;
        }
        $out = [];
        foreach (ScheduleRegistry::definitions() as $def) {
            $key = (string)$def['key'];
            $row = $byKey[$key] ?? [];
            $out[] = [
                'key' => $key,
                'title' => (string)$def['title'],
                'description' => (string)$def['description'],
                'command' => (string)$def['command'],
                'cron' => (string)$def['cron'],
                'args' => $def['args'] ?? [],
                'group' => (string)($def['group'] ?? '其他'),
                'enabled' => isset($row['enabled']) ? ((int)$row['enabled'] === 1) : (bool)$def['defaultEnabled'],
                'lastRunAt' => $row['last_run_at'] ?? null,
                'lastStatus' => $row['last_status'] ?? null,
                'lastMessage' => $row['last_message'] ?? null,
                'lastDurationMs' => isset($row['last_duration_ms']) ? (int)$row['last_duration_ms'] : null,
                'dueNow' => CronExpression::isDue((string)$def['cron']),
                'crontabLine' => self::crontabLine($def),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $def */
    public static function crontabLine(array $def): string
    {
        $cmd = 'php artisan ' . ($def['command'] ?? '');
        $args = $def['args'] ?? [];
        if (is_array($args) && $args) {
            $cmd .= ' ' . implode(' ', $args);
        }
        return trim((string)($def['cron'] ?? '* * * * *')) . '  cd /path/to/tkswarm-api && ' . $cmd
            . ' >> /var/log/tkswarm-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string)($def['key'] ?? 'job')) . '.log 2>&1';
    }

    public static function setEnabled(string $key, bool $enabled): bool
    {
        self::ensureSchema();
        if (!ScheduleRegistry::get($key)) {
            return false;
        }
        $row = DB::selectOne('SELECT job_key FROM scheduled_jobs WHERE job_key=?', [$key]);
        if ($row) {
            DB::query('UPDATE scheduled_jobs SET enabled=? WHERE job_key=?', [$enabled ? 1 : 0, $key]);
        } else {
            DB::query(
                'INSERT INTO scheduled_jobs(job_key, enabled) VALUES (?,?)',
                [$key, $enabled ? 1 : 0]
            );
        }
        // 兼容旧双开关：dm:sync 命令仍读 settings.dmSyncDispatchEnabled
        if ($key === 'dm_sync') {
            try {
                \App\Services\UserSettingsService::setSetting(
                    'dmSyncDispatchEnabled',
                    $enabled ? true : false
                );
            } catch (\Throwable $e) {
            }
        }
        return true;
    }

    public static function isEnabled(string $key): bool
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT enabled FROM scheduled_jobs WHERE job_key=?', [$key]);
        if ($row) {
            return (int)($row['enabled'] ?? 0) === 1;
        }
        $def = ScheduleRegistry::get($key);
        return $def ? !empty($def['defaultEnabled']) : false;
    }

    /**
     * 执行到期且已开启的任务（供 schedule.php / schedule:run）
     * @return array{ran:list<array<string,mixed>>,skipped:list<array<string,mixed>>}
     */
    public static function tick(?int $ts = null, bool $ignoreCron = false): array
    {
        self::ensureSchema();
        $ts = $ts ?? time();
        $ran = [];
        $skipped = [];
        foreach (ScheduleRegistry::definitions() as $def) {
            $key = (string)$def['key'];
            if (!self::isEnabled($key)) {
                $skipped[] = ['key' => $key, 'reason' => 'disabled'];
                continue;
            }
            if (!$ignoreCron && !CronExpression::isDue((string)$def['cron'], $ts)) {
                $skipped[] = ['key' => $key, 'reason' => 'not_due'];
                continue;
            }
            $ran[] = self::runOne($key, false);
        }
        return ['ran' => $ran, 'skipped' => $skipped];
    }

    /**
     * @return array{key:string,ok:bool,status:string,message:string,durationMs:int,skipped?:bool}
     */
    public static function runOne(string $key, bool $force = false): array
    {
        self::ensureSchema();
        $def = ScheduleRegistry::get($key);
        if (!$def) {
            return [
                'key' => $key,
                'ok' => false,
                'status' => 'error',
                'message' => 'unknown job',
                'durationMs' => 0,
            ];
        }
        if (!$force && !self::isEnabled($key)) {
            return [
                'key' => $key,
                'ok' => true,
                'status' => 'skipped',
                'message' => 'disabled',
                'durationMs' => 0,
                'skipped' => true,
            ];
        }

        $lock = self::acquireLock($key);
        if ($lock === null) {
            $result = [
                'key' => $key,
                'ok' => true,
                'status' => 'skipped',
                'message' => 'locked (previous run still active)',
                'durationMs' => 0,
                'skipped' => true,
            ];
            self::persistResult($key, $result);
            return $result;
        }

        $started = microtime(true);
        $status = 'ok';
        $message = 'done';
        $ok = true;
        try {
            $exit = self::invokeCommand((string)$def['command'], $def['args'] ?? []);
            if ($exit !== 0) {
                $ok = false;
                $status = 'error';
                $message = 'exit code ' . $exit;
            }
        } catch (\Throwable $e) {
            $ok = false;
            $status = 'error';
            $message = mb_substr($e->getMessage(), 0, 480);
        } finally {
            self::releaseLock($lock);
        }
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $result = [
            'key' => $key,
            'ok' => $ok,
            'status' => $status,
            'message' => $message,
            'durationMs' => $durationMs,
        ];
        self::persistResult($key, $result);
        return $result;
    }

    /** @param list<string> $args */
    private static function invokeCommand(string $signature, array $args): int
    {
        require_once dirname(__DIR__, 2) . '/Core/Schedule/bootstrap_commands.php';
        $scheduler = tkswarm_register_commands(new Scheduler());
        $argv = array_merge(['artisan', $signature], array_map('strval', $args));
        return $scheduler->run($argv);
    }

    /** @param array{status:string,message:string,durationMs:int} $result */
    private static function persistResult(string $key, array $result): void
    {
        try {
            $row = DB::selectOne('SELECT job_key FROM scheduled_jobs WHERE job_key=?', [$key]);
            if ($row) {
                DB::query(
                    'UPDATE scheduled_jobs SET last_run_at=?, last_status=?, last_message=?, last_duration_ms=? WHERE job_key=?',
                    [
                        date('Y-m-d H:i:s'),
                        (string)$result['status'],
                        mb_substr((string)$result['message'], 0, 500),
                        (int)$result['durationMs'],
                        $key,
                    ]
                );
            } else {
                DB::query(
                    'INSERT INTO scheduled_jobs(job_key, enabled, last_run_at, last_status, last_message, last_duration_ms) VALUES (?,?,?,?,?,?)',
                    [
                        $key,
                        1,
                        date('Y-m-d H:i:s'),
                        (string)$result['status'],
                        mb_substr((string)$result['message'], 0, 500),
                        (int)$result['durationMs'],
                    ]
                );
            }
        } catch (\Throwable $e) {
        }
    }

    /** @return resource|null */
    private static function acquireLock(string $key)
    {
        $dir = dirname(__DIR__, 3) . '/storage/schedule-locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . preg_replace('/[^a-z0-9_-]+/i', '_', $key) . '.lock';
        $fh = @fopen($path, 'c+');
        if (!$fh) {
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return null;
        }
        ftruncate($fh, 0);
        fwrite($fh, (string)getmypid() . ' ' . date('c'));
        fflush($fh);
        return $fh;
    }

    /** @param resource $fh */
    private static function releaseLock($fh): void
    {
        try {
            flock($fh, LOCK_UN);
            fclose($fh);
        } catch (\Throwable $e) {
        }
    }
}
