<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth\RBAC;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\CustomVideoTypeService;
use App\Services\DataScope;

/**
 * 开发管理 · 视频交付类型（数据表配置；实际积分 = points × multiplier）
 */
class VideoDeliveryTypeController extends Controller
{
    private function denyUnlessManage(): ?Response
    {
        DataScope::bootFromGlobals();
        if (DataScope::isLoginSuper()) {
            return null;
        }
        $login = DataScope::loginUser();
        $uid = (int)($login['id'] ?? 0);
        if ($uid <= 0) {
            return $this->error('未登录', 401);
        }
        if (RBAC::checkPermission($uid, 'system.video_delivery_types')) {
            return null;
        }
        return $this->error('无权管理视频交付类型（需要权限节点：system.video_delivery_types）', 403);
    }

    public function list(Request $request): Response
    {
        CustomVideoTypeService::ensureSchema();
        $enabledOnly = in_array(
            strtolower((string)$request->get('enabled', $request->get('enabledOnly', ''))),
            ['1', 'true', 'yes'],
            true
        );
        return $this->success([
            'items' => CustomVideoTypeService::listTypes($enabledOnly),
            'pointsMultiplier' => CustomVideoTypeService::pointsMultiplier(),
            'guideGroups' => CustomVideoTypeService::guideGroupOptions(),
        ]);
    }

    public function quote(Request $request): Response
    {
        CustomVideoTypeService::ensureSchema();
        $code = trim((string)$request->input('code', $request->input('settleType', '')));
        $qty = max(1, (int)$request->input('quantity', $request->input('qty', 1)));
        $manual = $request->input('pointsCost', $request->input('points_cost', null));
        $manualPts = $manual === null || $manual === '' ? null : (int)$manual;
        $quote = CustomVideoTypeService::quote($code, $qty, $manualPts);
        if (empty($quote['ok'])) {
            return $this->error((string)($quote['message'] ?? '计价失败'), 422);
        }
        return $this->success($quote);
    }

    public function saveSettings(Request $request): Response
    {
        if ($deny = $this->denyUnlessManage()) {
            return $deny;
        }
        $mul = max(1, (int)$request->input('pointsMultiplier', $request->input('points_multiplier', 50)));
        $saved = CustomVideoTypeService::savePointsMultiplier($mul);
        return $this->success([
            'pointsMultiplier' => $saved,
            'items' => CustomVideoTypeService::listTypes(false),
        ], '积分倍率已保存');
    }

    public function create(Request $request): Response
    {
        if ($deny = $this->denyUnlessManage()) {
            return $deny;
        }
        try {
            $row = CustomVideoTypeService::create($request->all() ?: []);
            return $this->success($row, '已添加');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function update(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessManage()) {
            return $deny;
        }
        try {
            $row = CustomVideoTypeService::update((int)$id, $request->all() ?: []);
            return $this->success($row, '已保存');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function delete(Request $request, string $id): Response
    {
        if ($deny = $this->denyUnlessManage()) {
            return $deny;
        }
        CustomVideoTypeService::delete((int)$id);
        return $this->success(['id' => (int)$id], '已删除');
    }
}
