<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\CompanyService;
use App\Services\DataScope;
use App\Services\IndustryDataService;

class CompanyController extends Controller
{
    public function list(Request $request): Response
    {
        CompanyService::ensureSchema();
        IndustryDataService::ensureSchema();

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, min(100, (int)$request->get('pageSize', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $status = $request->get('status', null);
        $industryId = (int)$request->get('industryId', $request->get('industry_id', 0));

        $filters = [];
        $params = [];
        if ($keyword !== '') {
            $filters[] = '(c.name LIKE ? OR c.contact_name LIKE ? OR c.contact_phone LIKE ? OR c.contact_email LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($status !== null && $status !== '') {
            $filters[] = 'c.status=?';
            $params[] = (int)$status;
        }
        if ($industryId > 0) {
            $filters[] = 'c.industry_id=?';
            $params[] = $industryId;
        }
        DataScope::applyCompanyListScope($filters, $params);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM companies c {$where}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;

        $rows = DB::select(
            "SELECT c.*, u.id AS primary_user_id, u.username AS primary_username, u.nickname AS primary_nickname
             FROM companies c
             LEFT JOIN company_users cu ON cu.company_id = c.id AND cu.is_primary = 1
             LEFT JOIN admin_users u ON u.id = cu.user_id
             {$where}
             ORDER BY c.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        $items = array_map(static function (array $row) {
            $mapped = CompanyService::mapRow($row);
            $mapped['primaryUser'] = !empty($row['primary_user_id']) ? [
                'id' => (int)$row['primary_user_id'],
                'username' => (string)($row['primary_username'] ?? ''),
                'nickname' => (string)($row['primary_nickname'] ?? ''),
            ] : null;
            $mapped['userCount'] = (int)(DB::selectOne(
                'SELECT COUNT(*) AS c FROM company_users WHERE company_id=?',
                [(int)$row['id']]
            )['c'] ?? 0);
            return $mapped;
        }, $rows);

        return $this->success([
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function create(Request $request): Response
    {
        CompanyService::ensureSchema();
        try {
            $companyId = CompanyService::create([
                'name' => $request->input('name'),
                'contact_name' => $request->input('contactName', $request->input('contact_name')),
                'contact_phone' => $request->input('contactPhone', $request->input('contact_phone')),
                'contact_email' => $request->input('contactEmail', $request->input('contact_email')),
                'industry_id' => $request->input('industryId', $request->input('industry_id')),
                'status' => $request->input('status', 1),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        try {
            DataScope::stampCompanyCreator($companyId);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success(CompanyService::find($companyId), '企业已创建');
    }

    public function show(Request $request, string $id): Response
    {
        CompanyService::ensureSchema();
        $companyId = (int)$id;
        if (!DataScope::isCompanyAccessible($companyId)) {
            return $this->error('无权查看该企业', 403);
        }
        $row = DB::selectOne('SELECT * FROM companies WHERE id=?', [$companyId]);
        if (!$row) {
            return $this->error('企业不存在', 404);
        }
        $mapped = CompanyService::mapRow($row);
        $users = DB::select(
            'SELECT u.id, u.username, u.nickname, u.status, cu.is_primary
             FROM company_users cu
             INNER JOIN admin_users u ON u.id = cu.user_id
             WHERE cu.company_id=?
             ORDER BY cu.is_primary DESC, u.id ASC',
            [(int)$id]
        ) ?: [];
        $mapped['users'] = array_map(static function (array $u) {
            return [
                'id' => (int)$u['id'],
                'username' => (string)($u['username'] ?? ''),
                'nickname' => (string)($u['nickname'] ?? ''),
                'status' => (int)($u['status'] ?? 1),
                'isPrimary' => !empty($u['is_primary']),
            ];
        }, $users);
        return $this->success($mapped);
    }

    public function update(Request $request, string $id): Response
    {
        CompanyService::ensureSchema();
        $companyId = (int)$id;
        if (!DataScope::isCompanyAccessible($companyId)) {
            return $this->error('无权编辑该企业', 403);
        }
        $exist = DB::table('companies')->where('id', $companyId)->first();
        if (!$exist) {
            return $this->error('企业不存在', 404);
        }

        try {
            CompanyService::update($companyId, [
                'name' => $request->input('name'),
                'contact_name' => $request->input('contactName', $request->input('contact_name')),
                'contact_phone' => $request->input('contactPhone', $request->input('contact_phone')),
                'contact_email' => $request->input('contactEmail', $request->input('contact_email')),
                'industry_id' => $request->input('industryId', $request->input('industry_id')),
                'status' => $request->input('status'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success(CompanyService::find($companyId), '企业信息已更新');
    }

    public function delete(Request $request, string $id): Response
    {
        CompanyService::ensureSchema();
        $companyId = (int)$id;
        if (!DataScope::isCompanyAccessible($companyId)) {
            return $this->error('无权删除该企业', 403);
        }
        try {
            CompanyService::delete($companyId);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
        return $this->success(null, '企业已删除');
    }
}
