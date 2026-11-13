<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AutoFollowDispatchService;
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

    /**
     * 立即派发：把该任务下已到期且仍 pending 的明细挂到账号会话总任务。
     * 用于计划任务未跑 / 卡在「待执行」时的手工补偿。
     */
    public function dispatchNow(Request $request, string $id): Response
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
        if (empty($task['dispatchEnabled'])) {
            return $this->error('该任务已关闭自动化派发，请先「开自动化」', 422);
        }
        if (in_array((string)($task['status'] ?? ''), ['cancelled', 'failed'], true)) {
            return $this->error('任务已取消或失败，无法派发', 422);
        }
        try {
            $result = AutoFollowDispatchService::tickForTask((int)$id, 50);
            $msg = ($result['enqueued'] ?? 0) > 0
                ? "已入队 {$result['enqueued']} 条关注明细，请到「矩阵任务」查看执行"
                : (($result['scanned'] ?? 0) < 1
                    ? '暂无到期明细（可能尚未到预约时间，或已全部入队）'
                    : "扫描 {$result['scanned']} 条，成功入队 0（失败 {$result['failed']} / 跳过 {$result['skipped']}）");
            return $this->success($result, $msg);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * 单条开关：间断关注自动化派发（计划任务 auto-follow:dispatch）。
     */
    public function patchDispatch(Request $request, string $id): Response
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
        $raw = $request->input('dispatchEnabled', $request->input('dispatch_enabled', null));
        if ($raw === null) {
            return $this->error('请提供 dispatchEnabled', 422);
        }
        $enabled = $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';
        try {
            $out = AutoFollowService::setDispatchEnabled((int)$id, $enabled);
            return $this->success($out, $enabled ? '已开启自动化派发' : '已关闭自动化派发');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * 批量开关间断关注自动化派发。
     */
    public function batchDispatch(Request $request): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        $ids = $request->input('taskIds', $request->input('ids', []));
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return $this->error('请选择任务', 400);
        }
        $raw = $request->input('dispatchEnabled', $request->input('dispatch_enabled', null));
        if ($raw === null) {
            return $this->error('请提供 dispatchEnabled', 422);
        }
        $enabled = $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';

        $allowed = [];
        foreach ($ids as $id) {
            $task = AutoFollowService::getTask($id);
            if (!$task) {
                continue;
            }
            if ($this->assertOwns(['owner_id' => $task['ownerId']])) {
                continue;
            }
            $allowed[] = $id;
        }
        if (!$allowed) {
            return $this->error('没有可操作的任务', 403);
        }
        $result = AutoFollowService::batchSetDispatchEnabled($allowed, $enabled);
        return $this->success($result, $enabled ? '已批量开启自动化派发' : '已批量关闭自动化派发');
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

    /** 可选粉丝账号 ID 列表（排除目标本身与已关注） */
    public function eligibleAccounts(Request $request): Response
    {
        DataScope::ensureColumns();
        AutoFollowService::ensureSchema();
        try {
            $target = AutoFollowService::resolveTarget($request->all());
            $items = AutoFollowService::eligibleAccounts($target);
            $ids = array_map(static fn(array $a) => (int)$a['id'], $items);
            return $this->success([
                'count' => count($ids),
                'ids' => $ids,
                'items' => $items,
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
