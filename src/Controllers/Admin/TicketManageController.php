<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Controllers\Api\TicketController;
use App\Core\Database\DB;
use App\Core\Controller;

/**
 * 工单管理（多方法控制台接口）
 */
class TicketManageController extends Controller
{
    private function api(): TicketController
    {
        return new TicketController();
    }

    public function stats(Request $request): Response
    {
        $rows = [];
        try {
            $rows = DB::select("SELECT status, COUNT(*) AS c FROM support_tickets GROUP BY status") ?: [];
        } catch (\Throwable $e) {
        }
        $byStatus = [];
        foreach ($rows as $r) {
            $byStatus[(string)$r['status']] = (int)$r['c'];
        }
        return $this->success([
            'byStatus' => $byStatus,
            'open' => (int)($byStatus['open'] ?? 0) + (int)($byStatus['processing'] ?? 0),
            'total' => array_sum($byStatus),
        ]);
    }

    public function list(Request $request): Response
    {
        return $this->api()->list($request);
    }

    public function detail(Request $request, string $id): Response
    {
        return $this->api()->detail($request, $id);
    }

    public function update(Request $request, string $id): Response
    {
        return $this->api()->update($request, $id);
    }

    public function delete(Request $request, string $id): Response
    {
        return $this->api()->delete($request, $id);
    }
}
