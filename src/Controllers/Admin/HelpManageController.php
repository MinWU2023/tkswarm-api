<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Controllers\Api\HelpDocController;

/**
 * 帮助文档：分类 + 文档发布管理
 */
class HelpManageController extends Controller
{
    private function api(): HelpDocController
    {
        return new HelpDocController();
    }

    public function stats(Request $request): Response
    {
        $categories = 0;
        $docs = 0;
        $published = 0;
        try {
            $categories = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM help_categories')['c'] ?? 0);
            $docs = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM help_documents')['c'] ?? 0);
            $published = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM help_documents WHERE status='published'")['c'] ?? 0);
        } catch (\Throwable $e) {
        }
        return $this->success(compact('categories', 'docs', 'published'));
    }

    public function listCategories(Request $request): Response
    {
        return $this->api()->listCategories($request);
    }

    public function createCategory(Request $request): Response
    {
        return $this->api()->createCategory($request);
    }

    public function updateCategory(Request $request, string $id): Response
    {
        return $this->api()->updateCategory($request, $id);
    }

    public function deleteCategory(Request $request, string $id): Response
    {
        return $this->api()->deleteCategory($request, $id);
    }

    public function listDocuments(Request $request): Response
    {
        return $this->api()->listDocuments($request);
    }

    public function createDocument(Request $request): Response
    {
        return $this->api()->createDocument($request);
    }

    public function updateDocument(Request $request, string $id): Response
    {
        return $this->api()->updateDocument($request, $id);
    }

    public function deleteDocument(Request $request, string $id): Response
    {
        return $this->api()->deleteDocument($request, $id);
    }
}
