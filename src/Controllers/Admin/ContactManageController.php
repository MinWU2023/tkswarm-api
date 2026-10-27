<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Api\ContactInquiryController;
use App\Core\Controller;
use App\Core\Database\DB;
use App\Core\Request;
use App\Core\Response;

class ContactManageController extends Controller
{
    private function api(): ContactInquiryController
    {
        return new ContactInquiryController();
    }

    public function stats(Request $request): Response
    {
        ContactInquiryController::ensureSchema();
        $rows = [];
        try {
            $rows = DB::select('SELECT status, COUNT(*) AS c FROM contact_inquiries GROUP BY status') ?: [];
        } catch (\Throwable $e) {
        }
        $byStatus = [];
        foreach ($rows as $r) {
            $byStatus[(string)$r['status']] = (int)$r['c'];
        }
        return $this->success([
            'byStatus' => $byStatus,
            'new' => (int)($byStatus['new'] ?? 0),
            'processing' => (int)($byStatus['processing'] ?? 0),
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
