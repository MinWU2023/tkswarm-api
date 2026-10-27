<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Controllers\Api\ClientVersionController;

/**
 * 客户端版本发布管理
 */
class VersionManageController extends Controller
{
    private function api(): ClientVersionController
    {
        return new ClientVersionController();
    }

    public function stats(Request $request): Response
    {
        $published = 0;
        $draft = 0;
        $total = 0;
        try {
            $published = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM client_versions WHERE status='published'")['c'] ?? 0);
            $draft = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM client_versions WHERE status='draft'")['c'] ?? 0);
            $total = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM client_versions')['c'] ?? 0);
        } catch (\Throwable $e) {
        }
        return $this->success(compact('published', 'draft', 'total'));
    }

    public function list(Request $request): Response
    {
        return $this->api()->list($request);
    }

    public function create(Request $request): Response
    {
        return $this->api()->create($request);
    }

    public function update(Request $request, string $id): Response
    {
        return $this->api()->update($request, $id);
    }

    public function upload(Request $request, string $id): Response
    {
        return $this->api()->upload($request, $id);
    }

    public function publish(Request $request, string $id): Response
    {
        return $this->api()->publish($request, $id);
    }

    public function setLatest(Request $request, string $id): Response
    {
        return $this->api()->setLatest($request, $id);
    }

    public function delete(Request $request, string $id): Response
    {
        return $this->api()->delete($request, $id);
    }
}
