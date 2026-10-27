<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AutoFollowService;
use App\Services\DataScope;

class AutoFollowController extends Controller
{
    public function list(Request $request): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        $ownerId = DataScope::isSuper() ? 0 : $this->writeOwnerId();
        $pack = AutoFollowService::listTasks(
            $ownerId,
            (int)$request->input('page', 1),
            (int)$request->input('pageSize', $request->input('page_size', 20)),
            trim((string)$request->input('keyword', '')),
            trim((string)$request->input('status', ''))
        );
        return $this->success($pack);
    }

    public function detail(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        $task = AutoFollowService::getTask(
            (int)$id,
            true,
            (int)$request->input('itemPage', $request->input('item_page', 1)),
            (int)$request->input('itemPageSize', $request->input('item_page_size', 50))
        );
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertOwns(['owner_id' => $task['ownerId']])) {
            return $err;
        }
        return $this->success($task);
    }

    public function create(Request $request): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        try {
            $out = AutoFollowService::create($request->all(), $this->writeOwnerId());
            return $this->success($out, '已创建自动增粉任务');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        $task = AutoFollowService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertOwns(['owner_id' => $task['ownerId']])) {
            return $err;
        }
        try {
            $out = AutoFollowService::update((int)$id, $request->all());
            return $this->success($out, '已更新');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function cancel(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        $task = AutoFollowService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertOwns(['owner_id' => $task['ownerId']])) {
            return $err;
        }
        try {
            return $this->success(AutoFollowService::cancel((int)$id), '已取消');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function delete(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        $task = AutoFollowService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertOwns(['owner_id' => $task['ownerId']])) {
            return $err;
        }
        AutoFollowService::delete((int)$id);
        return $this->success(['id' => (int)$id], '已删除');
    }

    public function eligibleCount(Request $request): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        try {
            $target = AutoFollowService::resolveTarget($request->all());
            $count = AutoFollowService::eligibleCount($target);
            return $this->success([
                'count' => $count,
                'target' => $target,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function targetSearch(Request $request): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        $keyword = trim((string)$request->input('keyword', $request->input('q', '')));
        $items = AutoFollowService::searchSystemAccounts(
            $keyword,
            (int)$request->input('limit', 30)
        );
        return $this->success(['items' => $items]);
    }
}
