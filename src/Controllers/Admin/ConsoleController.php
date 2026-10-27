<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\UserSettingsService;

/**
 * 运营控制台：SPA 入口与总览统计
 */
class ConsoleController extends Controller
{
    /** 控制台首页 / 深链回落 */
    public function spa(Request $request): Response
    {
        $file = dirname(__DIR__, 3) . '/public/admin/index.html';
        if (!is_file($file)) {
            return Response::make('Admin console not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return Response::make(file_get_contents($file), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** 总览数据 */
    public function overview(Request $request): Response
    {
        $ticketsOpen = 0;
        $ticketsTotal = 0;
        $versionsPublished = 0;
        $versionsTotal = 0;
        $docsPublished = 0;
        $docsTotal = 0;
        $categories = 0;

        try {
            $ticketsOpen = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM support_tickets WHERE status IN ('open','processing')")['c'] ?? 0);
            $ticketsTotal = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM support_tickets')['c'] ?? 0);
        } catch (\Throwable $e) {
        }
        try {
            $versionsPublished = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM client_versions WHERE status='published'")['c'] ?? 0);
            $versionsTotal = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM client_versions')['c'] ?? 0);
        } catch (\Throwable $e) {
        }
        try {
            $docsPublished = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM help_documents WHERE status='published'")['c'] ?? 0);
            $docsTotal = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM help_documents')['c'] ?? 0);
            $categories = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM help_categories')['c'] ?? 0);
        } catch (\Throwable $e) {
        }

        $recentTickets = [];
        try {
            $recentTickets = DB::select('SELECT id, ticket_no, subject, status, priority, created_at FROM support_tickets ORDER BY id DESC LIMIT 8') ?: [];
        } catch (\Throwable $e) {
        }

        return $this->success([
            'stats' => [
                'ticketsOpen' => $ticketsOpen,
                'ticketsTotal' => $ticketsTotal,
                'versionsPublished' => $versionsPublished,
                'versionsTotal' => $versionsTotal,
                'docsPublished' => $docsPublished,
                'docsTotal' => $docsTotal,
                'categories' => $categories,
            ],
            'recentTickets' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'ticketNo' => (string)($r['ticket_no'] ?? ''),
                    'subject' => (string)($r['subject'] ?? ''),
                    'status' => (string)($r['status'] ?? ''),
                    'priority' => (string)($r['priority'] ?? ''),
                    'createdAt' => $r['created_at'] ?? null,
                ];
            }, $recentTickets),
        ]);
    }

    /** 官网首页下载链接 */
    public function siteSettings(Request $request): Response
    {
        return $this->success(UserSettingsService::getPublicSiteConfig());
    }

    public function saveSiteSettings(Request $request): Response
    {
        $win = trim((string)$request->input('landingDownloadWinUrl', ''));
        $mac = trim((string)$request->input('landingDownloadMacUrl', ''));
        if ($win === '' || $mac === '') {
            return $this->error('请填写 Windows 与 macOS 下载链接', 422);
        }
        UserSettingsService::saveForUser([
            'landingDownloadWinUrl' => $win,
            'landingDownloadMacUrl' => $mac,
        ]);
        return $this->success(UserSettingsService::getPublicSiteConfig(), '官网下载链接已保存');
    }
}
