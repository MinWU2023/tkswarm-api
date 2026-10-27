<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Core\Database\DB;

/**
 * 仅处理「纯 PHP 可完成」的轻量任务；比特/养号/粉丝/私信同步留给本机 Node task-runner。
 */
class TaskRunnerCommand extends Command
{
    protected string $signature = 'task:run';
    protected string $description = '扫描排队任务：自动化类型留给 Node，其余可在此扩展';

    /** @var list<string> */
    private array $nodeOwned = ['publish', 'message', 'warm', 'sync_fans', 'dm_sync', 'sync', 'profile'];

    public function handle(array $args = []): int
    {
        $this->info('TkSwarm Task Runner (PHP) — Node 负责比特类任务');

        $tasks = DB::select(
            "SELECT id, name, type, status FROM tasks WHERE status='queued' ORDER BY id ASC LIMIT 20"
        );
        if (!$tasks) {
            $this->info('No queued tasks.');
            return 0;
        }

        $left = 0;
        $skipped = 0;
        foreach ($tasks as $task) {
            $type = (string)($task['type'] ?? '');
            if (in_array($type, $this->nodeOwned, true)) {
                $this->info("Skip #{$task['id']} [{$type}] — await Node task-runner");
                $skipped++;
                continue;
            }
            // 未知类型：不假装完成
            $this->info("Leave #{$task['id']} [{$type}] untouched (no PHP executor)");
            $left++;
        }

        $this->info("Done. Node-owned skipped={$skipped}, untouched={$left}");
        return 0;
    }
}
