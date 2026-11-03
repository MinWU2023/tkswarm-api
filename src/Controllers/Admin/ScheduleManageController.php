<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Schedule\ScheduleRunner;

/**
 * 运营控制台：统一计划任务启停与手动触发
 */
class ScheduleManageController extends Controller
{
    public function list(Request $request): Response
    {
        $jobs = ScheduleRunner::listJobs();
        return $this->success([
            'jobs' => $jobs,
            'mountHint' => '* * * * * cd /path/to/tkswarm-api && php schedule.php >> /var/log/tkswarm-schedule.log 2>&1',
            'note' => '服务器 crontab 只需挂载 schedule.php；下列任务仅在「开启」时由调度器按 cron 执行。',
        ]);
    }

    public function update(Request $request, string $key = ''): Response
    {
        $key = trim($key !== '' ? $key : (string)$request->input('key', ''));
        if ($key === '') {
            return $this->error('缺少任务标识', 422);
        }
        $enabledRaw = $request->input('enabled', null);
        if ($enabledRaw === null) {
            return $this->error('请提供 enabled', 422);
        }
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            $enabled = in_array((string)$enabledRaw, ['1', 'true', 'on', 'yes'], true);
        }
        if (!ScheduleRunner::setEnabled($key, (bool)$enabled)) {
            return $this->error('未知计划任务', 404);
        }
        return $this->success([
            'key' => $key,
            'enabled' => (bool)$enabled,
            'jobs' => ScheduleRunner::listJobs(),
        ]);
    }

    public function run(Request $request, string $key = ''): Response
    {
        $key = trim($key !== '' ? $key : (string)$request->input('key', ''));
        if ($key === '') {
            return $this->error('缺少任务标识', 422);
        }
        // 手动触发：即使关闭也可强制跑一次，便于排查
        $result = ScheduleRunner::runOne($key, true);
        return $this->success([
            'result' => $result,
            'jobs' => ScheduleRunner::listJobs(),
        ]);
    }
}
