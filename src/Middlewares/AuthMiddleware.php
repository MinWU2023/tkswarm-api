<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;

class AuthMiddleware
{
    public function handle(Request $request): ?Response
    {
        $authEnabled = $this->authEnabled();

        $token = $request->bearerToken() ?? $request->input('token') ?? ($_COOKIE['tkswarm_token'] ?? null);

        if (!$authEnabled) {
            $GLOBALS['auth_user'] = [
                'id' => 0,
                'username' => 'guest',
                'nickname' => '访客',
                'role_id' => 1,
                'status' => 1,
            ];
            return DataScope::bootFromGlobals($request);
        }

        if (!$token) {
            return Response::error('未授权或登录凭证已失效，请重新登录', 401);
        }

        $session = DB::table('admin_sessions')
            ->where('token', $token)
            ->whereRaw('expires_at > ?', [date('Y-m-d H:i:s')])
            ->first();

        if (!$session) {
            return Response::error('登录状态已过期，请重新登录', 401);
        }

        $user = DB::table('admin_users')->where('id', $session['user_id'])->first();
        if (!$user || (int)($user['status'] ?? 0) !== 1) {
            return Response::error('用户账号不存在或已被冻结', 403);
        }

        $GLOBALS['auth_user'] = $user;
        return DataScope::bootFromGlobals($request);
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
