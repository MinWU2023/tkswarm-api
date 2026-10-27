<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;
use App\Services\UserSettingsService;

class SystemController extends Controller
{
    /** 官网首页：客户端下载链接等（无需登录） */
    public function publicSiteConfig(Request $request): Response
    {
        return $this->success(UserSettingsService::getPublicSiteConfig());
    }

    public function getSettings(Request $request): Response
    {
        return $this->success(UserSettingsService::getForUser());
    }

    public function saveSettings(Request $request): Response
    {
        $all = $request->all();
        // 兼容参考站 translateMode=normal → basic；旧秒制间隔→分钟
        if (isset($all['translateMode']) && !isset($all['translationMode'])) {
            $all['translationMode'] = $all['translateMode'] === 'normal' ? 'basic' : $all['translateMode'];
        }
        if (isset($all['translationMode']) && $all['translationMode'] === 'normal') {
            $all['translationMode'] = 'basic';
        }
        if (isset($all['privateMessageInterval']) && !isset($all['messageSyncInterval'])) {
            $all['messageSyncInterval'] = (int)$all['privateMessageInterval'];
        }
        if (isset($all['messageSyncInterval'])) {
            $iv = (int)$all['messageSyncInterval'];
            // 历史 UI 曾用秒（常见 5–120）；超过 120 按秒折算为分钟
            if ($iv > 120) {
                $iv = max(1, (int)floor($iv / 60));
            }
            $all['messageSyncInterval'] = max(1, min(60, $iv ?: 10));
        }
        if (isset($all['notificationInterval'])) {
            $all['notificationInterval'] = max(1, min(60, (int)$all['notificationInterval'] ?: 1));
        }
        if (isset($all['fansSyncMaxPerRun'])) {
            $all['fansSyncMaxPerRun'] = max(50, min(2000, (int)$all['fansSyncMaxPerRun'] ?: 500));
        }
        $filtered = [];
        foreach ($all as $k => $v) {
            $secretKeys = ['browserApiToken', 'tkswarmBrowserApiToken', 'translationApiKey', 'smtp.password'];
            if (in_array($k, $secretKeys, true) && ($v === '' || $v === '******')) {
                continue;
            }
            $filtered[$k] = $v;
        }
        UserSettingsService::saveForUser($filtered);
        return $this->success($all, '系统设置已保存');
    }

    public function dashboardStats(Request $request): Response
    {
        DataScope::ensureColumns();
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $accCount = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM accounts WHERE 1=1{$ownSql}", $ownParams)['c'] ?? 0);
        $onlineAcc = 0;
        try {
            $onlineAcc = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM accounts WHERE login_status='online'{$ownSql}", $ownParams)['c'] ?? 0);
        } catch (\Throwable $e) {
            $onlineAcc = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM accounts WHERE status='online'{$ownSql}", $ownParams)['c'] ?? 0);
        }
        $proxyCount = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM proxies WHERE 1=1{$ownSql}", $ownParams)['c'] ?? 0);
        $availProxy = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM proxies WHERE status='available'{$ownSql}", $ownParams)['c'] ?? 0);
        $taskCount = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM tasks WHERE 1=1{$ownSql}", $ownParams)['c'] ?? 0);
        $runningTasks = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM tasks WHERE status IN ('queued','running'){$ownSql}", $ownParams)['c'] ?? 0);

        return $this->success([
            'accounts' => $accCount,
            'onlineAccounts' => $onlineAcc,
            'proxies' => $proxyCount,
            'availableProxies' => $availProxy,
            'tasks' => $taskCount,
            'runningTasks' => $runningTasks,
        ]);
    }

    public function dashboardActivity(Request $request): Response
    {
        DataScope::ensureColumns();
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        try {
            $rows = DB::select(
                "SELECT id, name, type, status, success_count, fail_count, total_count, created_at, updated_at
                 FROM tasks WHERE 1=1{$ownSql} ORDER BY updated_at DESC LIMIT 8",
                $ownParams
            );
        } catch (\Throwable $e) {
            $rows = [];
        }
        return $this->success($rows);
    }
}
