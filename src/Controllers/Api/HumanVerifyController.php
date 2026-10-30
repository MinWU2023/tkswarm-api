<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DataScope;
use App\Services\HumanVerifyService;

/**
 * 人工验证通知：列表 / 计数 / 确认恢复。
 */
class HumanVerifyController extends Controller
{
    public function list(Request $request): Response
    {
        HumanVerifyService::ensureSchema();
        $limit = (int)$request->get('limit', 50);
        return $this->success([
            'items' => HumanVerifyService::listPending($limit),
            'count' => HumanVerifyService::countPending(),
        ]);
    }

    public function count(Request $request): Response
    {
        HumanVerifyService::ensureSchema();
        return $this->success(['count' => HumanVerifyService::countPending()]);
    }

    public function resolve(Request $request, string $id): Response
    {
        HumanVerifyService::ensureSchema();
        $d = $request->json() ?: [];
        $action = trim((string)($d['action'] ?? 'resume')) ?: 'resume';
        if (!in_array($action, ['resume', 'dismiss'], true)) {
            $action = 'resume';
        }
        $userId = (int)(DataScope::loginUser()['id'] ?? 0);
        try {
            $out = HumanVerifyService::resolve((int)$id, $userId, $action);
            return $this->success($out, $action === 'dismiss' ? '已忽略' : '已确认，任务将继续执行');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 409);
        }
    }
}
