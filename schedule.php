<?php
declare(strict_types=1);

/**
 * 统一计划任务入口 —— 服务器 crontab 只需挂载本文件：
 *
 *   * * * * * cd /path/to/tkswarm-api && php schedule.php >> /var/log/tkswarm-schedule.log 2>&1
 *
 * 等价于：php artisan schedule:run
 * 各任务是否执行由运营控制台「计划任务」开关控制（scheduled_jobs.enabled）。
 */

define('APP_PATH', __DIR__);
require APP_PATH . '/autoload.php';
require APP_PATH . '/src/Core/Schedule/bootstrap_commands.php';

new App\Core\App(APP_PATH);

$argvCopy = $argv ?? ['schedule.php'];
// 透传 --job / --force 到 schedule:run
$pass = array_slice($argvCopy, 1);
$scheduler = tkswarm_register_commands(new App\Core\Schedule\Scheduler());
$exitCode = $scheduler->run(array_merge(['artisan', 'schedule:run'], $pass));
exit($exitCode);
