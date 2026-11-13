<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;

/**
 * Crontab 心跳：每分钟把当前时间写入 public/datetime.txt，便于确认计划任务是否在跑。
 *
 * 用法:
 *   php artisan crontab:heartbeat
 *
 * 查看:
 *   cat public/datetime.txt
 *   或浏览器访问 /datetime.txt（若 web 根目录指向 public）
 */
class CrontabHeartbeatCommand extends Command
{
    protected string $signature = 'crontab:heartbeat';
    protected string $description = '将当前时间写入 public/datetime.txt，用于检测 crontab / schedule 是否执行';

    public function handle(array $args = []): int
    {
        $now = date('Y-m-d H:i:s');
        $dir = rtrim((string)(defined('APP_PATH') ? APP_PATH : dirname(__DIR__, 2)), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'public';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->error('无法创建目录: ' . $dir);
                return 1;
            }
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'datetime.txt';
        $ok = @file_put_contents($file, $now . "\n", LOCK_EX);
        if ($ok === false) {
            $this->error('写入失败: ' . $file);
            return 1;
        }
        $this->info("wrote {$file} => {$now}");
        return 0;
    }
}
