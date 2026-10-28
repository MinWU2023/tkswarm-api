<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiLeadVideoWeightService;
use App\Services\DataScope;

/**
 * 开发管理 · 日私信权重规则（仅登录超管可访问）
 * 配置表 account_video_weight_tiers（owner_id=0 系统默认）。
 */
class DmWeightConfigController extends Controller
{
    private function denyUnlessLoginSuper(): ?Response
    {
        DataScope::bootFromGlobals();
        if (!DataScope::isLoginSuper()) {
            return $this->error('仅超级管理员可管理日私信权重规则', 403);
        }
        return null;
    }

    public function get(Request $request): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        AiLeadVideoWeightService::ensureSchema();
        $tiers = AiLeadVideoWeightService::listTiers(0);
        if (!$tiers) {
            $tiers = array_map(static fn($t) => [
                'id' => 0,
                'days' => (int)$t['days'],
                'dailyDmLimit' => (int)$t['daily_dm_limit'],
                'sortOrder' => 0,
            ], AiLeadVideoWeightService::DEFAULT_TIERS);
        }
        return $this->success([
            'tiers' => $tiers,
            'ranges' => AiLeadVideoWeightService::tiersToRanges($tiers),
            'defaults' => AiLeadVideoWeightService::DEFAULT_TIERS,
            'defaultRanges' => AiLeadVideoWeightService::tiersToRanges(
                array_map(static fn($t) => [
                    'days' => (int)$t['days'],
                    'dailyDmLimit' => (int)$t['daily_dm_limit'],
                ], AiLeadVideoWeightService::DEFAULT_TIERS)
            ),
        ]);
    }

    public function save(Request $request): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        AiLeadVideoWeightService::ensureSchema();
        $ranges = $request->input('ranges', null);
        if (!is_array($ranges)) {
            $tiersIn = $request->input('tiers', []);
            if (!is_array($tiersIn)) {
                $tiersIn = [];
            }
            $ranges = AiLeadVideoWeightService::tiersToRanges($tiersIn);
        }
        try {
            $saved = AiLeadVideoWeightService::saveSystemRanges($ranges);
            return $this->success($saved);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** 恢复内置默认阶梯 */
    public function reset(Request $request): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        AiLeadVideoWeightService::ensureSchema();
        try {
            $tiers = array_map(static fn($t) => [
                'days' => (int)$t['days'],
                'dailyDmLimit' => (int)$t['daily_dm_limit'],
            ], AiLeadVideoWeightService::DEFAULT_TIERS);
            $saved = AiLeadVideoWeightService::saveTiers(0, $tiers, true);
            return $this->success([
                'tiers' => $saved,
                'ranges' => AiLeadVideoWeightService::tiersToRanges($saved),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
