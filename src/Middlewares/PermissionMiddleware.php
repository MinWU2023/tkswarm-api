<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Core\Auth\RBAC;
use App\Services\DataScope;
use App\Services\RoutePermissionMap;

class PermissionMiddleware
{
    public function handle(Request $request): ?Response
    {
        if (!$this->authEnabled()) {
            return null;
        }

        $user = $GLOBALS['auth_user'] ?? null;
        if (!$user) {
            return null;
        }

        $effective = DataScope::effectiveUser();
        $userId = (int)($effective['id'] ?? $user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $method = $request->getMethod();
        $path = rtrim($request->getPath(), '/') ?: '/';

        $required = RoutePermissionMap::requiredCodes($method, $path);
        if ($required === null || $required === []) {
            return null;
        }

        $held = RBAC::userPermissionCodes($userId);
        foreach ($required as $code) {
            if (RBAC::matchesHeldCode($code, $held)) {
                return null;
            }
        }

        return Response::error('当前角色无权执行此操作（需要：' . implode(' / ', $required) . '）', 403);
    }

    private function authEnabled(): bool
    {
        try {
            $row = DB::selectOne("SELECT `value` FROM settings WHERE `key`='authEnabled'");
        } catch (\Throwable $e) {
            return true;
        }
        if (!$row) {
            return true;
        }
        $val = json_decode((string)$row['value'], true);
        if ($val === null && $row['value'] !== 'null') {
            $val = $row['value'];
        }
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }
}
