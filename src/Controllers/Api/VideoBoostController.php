<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DataScope;
use App\Services\VideoBoostService;

/**
 * 视频增值任务（视频向导 → 视频增值）
 */
class VideoBoostController extends Controller
{
    /** 超管可访问全部视频增值任务；其余角色仅限自己名下的任务 */
    private function assertTaskOwns(array $task): ?Response
    {
        if (DataScope::isSuper()) {
            return null;
        }
        return $this->assertOwns(['owner_id' => $task['ownerId'] ?? 0]);
    }

    public function list(Request $request): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        $ownerId = DataScope::isSuper() ? 0 : $this->writeOwnerId();
        return $this->success(VideoBoostService::listTasks(
            $ownerId,
            (int)$request->input('page', 1),
            (int)$request->input('pageSize', $request->input('page_size', 20)),
            trim((string)$request->input('keyword', '')),
            trim((string)$request->input('status', ''))
        ));
    }

    public function detail(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        $task = VideoBoostService::getTask(
            (int)$id,
            true,
            (int)$request->input('itemPage', $request->input('item_page', 1)),
            (int)$request->input('itemPageSize', $request->input('item_page_size', 50))
        );
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertTaskOwns($task)) {
            return $err;
        }
        return $this->success($task);
    }

    public function create(Request $request): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        try {
            return $this->success(VideoBoostService::create($request->all(), $this->writeOwnerId()), '已创建视频增值任务');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        $task = VideoBoostService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertTaskOwns($task)) {
            return $err;
        }
        try {
            return $this->success(VideoBoostService::update((int)$id, $request->all()), '已更新并重新生成明细');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function cancel(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        $task = VideoBoostService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertTaskOwns($task)) {
            return $err;
        }
        try {
            return $this->success(VideoBoostService::cancel((int)$id), '已取消');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function delete(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        $task = VideoBoostService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertTaskOwns($task)) {
            return $err;
        }
        VideoBoostService::delete((int)$id);
        return $this->success(['id' => (int)$id], '已删除');
    }

    public function patchDispatch(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        $task = VideoBoostService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertTaskOwns($task)) {
            return $err;
        }
        $raw = $request->input('dispatchEnabled', $request->input('dispatch_enabled', null));
        if ($raw === null) {
            return $this->error('请提供 dispatchEnabled', 422);
        }
        $enabled = $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';
        try {
            return $this->success(
                VideoBoostService::setDispatchEnabled((int)$id, $enabled),
                $enabled ? '已开启自动派发' : '已关闭自动派发'
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** 可选已发布视频（带发布账号/链接） */
    public function videos(Request $request): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        return $this->success(VideoBoostService::listVideos(
            trim((string)$request->input('keyword', '')),
            (int)$request->input('page', 1),
            (int)$request->input('pageSize', $request->input('page_size', 12))
        ));
    }

    /** 「视频增量」分类下可选消息模板 */
    public function templates(Request $request): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        return $this->success(['items' => VideoBoostService::listTemplates()]);
    }

    /** 当前可用执行账号数量（排除视频发布账号） */
    public function eligibleCount(Request $request): Response
    {
        DataScope::ensureColumns();
        VideoBoostService::ensureSchema();
        return $this->success([
            'count' => VideoBoostService::eligibleCount((int)$request->input('materialId', $request->input('material_id', 0))),
        ]);
    }
}
