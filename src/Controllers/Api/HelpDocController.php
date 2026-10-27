<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;

/**
 * 帮助文档：分类 + 文档（公开已发布 + 后台 CRUD）
 */
class HelpDocController extends Controller
{
    public function publicCategories(Request $request): Response
    {
        $rows = DB::select(
            "SELECT id, name, slug, sort_order FROM help_categories WHERE visible=1 ORDER BY sort_order ASC, id ASC"
        );
        return $this->success(['items' => array_map([$this, 'mapCat'], $rows ?: [])]);
    }

    public function publicDocs(Request $request): Response
    {
        $categoryId = (int)$request->get('category_id', 0);
        $sql = "SELECT id, category_id, title, summary, sort_order, view_count, published_at, updated_at
                FROM help_documents WHERE status='published'";
        $params = [];
        if ($categoryId > 0) {
            $sql .= ' AND category_id=?';
            $params[] = $categoryId;
        }
        $sql .= ' ORDER BY sort_order ASC, id DESC LIMIT 200';
        $rows = DB::select($sql, $params);
        return $this->success(['items' => array_map([$this, 'mapDocPublic'], $rows ?: [])]);
    }

    public function publicDetail(Request $request, string $id): Response
    {
        $row = DB::selectOne(
            "SELECT * FROM help_documents WHERE id=? AND status='published'",
            [(int)$id]
        );
        if (!$row) {
            return $this->error('文档不存在或未发布', 404);
        }
        DB::query('UPDATE help_documents SET view_count=view_count+1 WHERE id=?', [(int)$id]);
        $row['view_count'] = (int)($row['view_count'] ?? 0) + 1;
        $cat = null;
        if (!empty($row['category_id'])) {
            $cat = DB::selectOne('SELECT id, name, slug FROM help_categories WHERE id=?', [(int)$row['category_id']]);
        }
        $data = $this->mapDocPublic($row, true);
        $data['category'] = $cat ? $this->mapCat($cat) : null;
        return $this->success($data);
    }

    // ---- admin categories ----
    public function listCategories(Request $request): Response
    {
        DataScope::ensureColumns();
        $rows = DB::select('SELECT * FROM help_categories ORDER BY sort_order ASC, id ASC');
        return $this->success(array_map([$this, 'mapCatAdmin'], $rows ?: []));
    }

    public function createCategory(Request $request): Response
    {
        $d = $request->json() ?: [];
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            return $this->error('分类名称不能为空');
        }
        $slug = trim((string)($d['slug'] ?? ''));
        $sort = (int)($d['sortOrder'] ?? $d['sort_order'] ?? 0);
        $visible = !empty($d['visible']) || !isset($d['visible']) ? 1 : 0;
        $ownerId = $this->writeOwnerId();
        DB::query(
            'INSERT INTO help_categories(name, slug, sort_order, visible, owner_id) VALUES (?,?,?,?,?)',
            [$name, $slug, $sort, $visible, $ownerId ?: null]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        return $this->success($this->mapCatAdmin(DB::selectOne('SELECT * FROM help_categories WHERE id=?', [$id])));
    }

    public function updateCategory(Request $request, string $id): Response
    {
        $row = DB::selectOne('SELECT * FROM help_categories WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('分类不存在', 404);
        }
        $d = $request->json() ?: [];
        $name = trim((string)($d['name'] ?? $row['name']));
        $slug = trim((string)($d['slug'] ?? $row['slug']));
        $sort = (int)($d['sortOrder'] ?? $d['sort_order'] ?? $row['sort_order']);
        $visible = array_key_exists('visible', $d) ? ((int)!empty($d['visible'])) : (int)$row['visible'];
        DB::query(
            'UPDATE help_categories SET name=?, slug=?, sort_order=?, visible=? WHERE id=?',
            [$name, $slug, $sort, $visible, (int)$id]
        );
        return $this->success($this->mapCatAdmin(DB::selectOne('SELECT * FROM help_categories WHERE id=?', [(int)$id])));
    }

    public function deleteCategory(Request $request, string $id): Response
    {
        $cnt = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM help_documents WHERE category_id=?', [(int)$id])['c'] ?? 0);
        if ($cnt > 0) {
            return $this->error('该分类下仍有文档，无法删除');
        }
        DB::query('DELETE FROM help_categories WHERE id=?', [(int)$id]);
        return $this->success(true);
    }

    // ---- admin documents ----
    public function listDocuments(Request $request): Response
    {
        $categoryId = (int)$request->get('category_id', 0);
        $sql = 'SELECT d.*, c.name AS category_name FROM help_documents d
                LEFT JOIN help_categories c ON c.id=d.category_id WHERE 1=1';
        $params = [];
        if ($categoryId > 0) {
            $sql .= ' AND d.category_id=?';
            $params[] = $categoryId;
        }
        $sql .= ' ORDER BY d.sort_order ASC, d.id DESC';
        $rows = DB::select($sql, $params);
        return $this->success(array_map([$this, 'mapDocAdmin'], $rows ?: []));
    }

    public function createDocument(Request $request): Response
    {
        $d = $request->json() ?: [];
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '') {
            return $this->error('标题不能为空');
        }
        $categoryId = (int)($d['categoryId'] ?? $d['category_id'] ?? 0);
        $summary = trim((string)($d['summary'] ?? ''));
        $content = (string)($d['content'] ?? '');
        $status = trim((string)($d['status'] ?? 'draft')) ?: 'draft';
        $sort = (int)($d['sortOrder'] ?? $d['sort_order'] ?? 0);
        $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;
        $ownerId = $this->writeOwnerId();
        DB::query(
            'INSERT INTO help_documents(category_id, title, summary, content, status, sort_order, owner_id, published_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$categoryId, $title, $summary, $content, $status, $sort, $ownerId ?: null, $publishedAt]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        return $this->success($this->mapDocAdmin(DB::selectOne(
            'SELECT d.*, c.name AS category_name FROM help_documents d LEFT JOIN help_categories c ON c.id=d.category_id WHERE d.id=?',
            [$id]
        )));
    }

    public function updateDocument(Request $request, string $id): Response
    {
        $row = DB::selectOne('SELECT * FROM help_documents WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('文档不存在', 404);
        }
        $d = $request->json() ?: [];
        $title = trim((string)($d['title'] ?? $row['title']));
        $categoryId = (int)($d['categoryId'] ?? $d['category_id'] ?? $row['category_id']);
        $summary = trim((string)($d['summary'] ?? $row['summary']));
        $content = array_key_exists('content', $d) ? (string)$d['content'] : (string)$row['content'];
        $status = trim((string)($d['status'] ?? $row['status'])) ?: 'draft';
        $sort = (int)($d['sortOrder'] ?? $d['sort_order'] ?? $row['sort_order']);
        $publishedAt = $row['published_at'];
        if ($status === 'published' && empty($publishedAt)) {
            $publishedAt = date('Y-m-d H:i:s');
        }
        DB::query(
            'UPDATE help_documents SET category_id=?, title=?, summary=?, content=?, status=?, sort_order=?, published_at=? WHERE id=?',
            [$categoryId, $title, $summary, $content, $status, $sort, $publishedAt, (int)$id]
        );
        return $this->success($this->mapDocAdmin(DB::selectOne(
            'SELECT d.*, c.name AS category_name FROM help_documents d LEFT JOIN help_categories c ON c.id=d.category_id WHERE d.id=?',
            [(int)$id]
        )));
    }

    public function deleteDocument(Request $request, string $id): Response
    {
        DB::query('DELETE FROM help_documents WHERE id=?', [(int)$id]);
        return $this->success(true);
    }

    private function mapCat(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'slug' => (string)($r['slug'] ?? ''),
            'sortOrder' => (int)($r['sort_order'] ?? 0),
        ];
    }

    private function mapCatAdmin(array $r): array
    {
        return array_merge($this->mapCat($r), [
            'visible' => (int)($r['visible'] ?? 1) === 1,
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ]);
    }

    private function mapDocPublic(array $r, bool $withContent = false): array
    {
        $out = [
            'id' => (int)$r['id'],
            'categoryId' => (int)($r['category_id'] ?? 0),
            'title' => (string)$r['title'],
            'summary' => (string)($r['summary'] ?? ''),
            'sortOrder' => (int)($r['sort_order'] ?? 0),
            'viewCount' => (int)($r['view_count'] ?? 0),
            'publishedAt' => $r['published_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
        if ($withContent) {
            $out['content'] = (string)($r['content'] ?? '');
        }
        return $out;
    }

    private function mapDocAdmin(?array $r): array
    {
        if (!$r) {
            return [];
        }
        return [
            'id' => (int)$r['id'],
            'categoryId' => (int)($r['category_id'] ?? 0),
            'categoryName' => (string)($r['category_name'] ?? ''),
            'title' => (string)$r['title'],
            'summary' => (string)($r['summary'] ?? ''),
            'content' => (string)($r['content'] ?? ''),
            'status' => (string)($r['status'] ?? 'draft'),
            'sortOrder' => (int)($r['sort_order'] ?? 0),
            'viewCount' => (int)($r['view_count'] ?? 0),
            'publishedAt' => $r['published_at'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }
}
