<?php
declare(strict_types=1);

namespace App\Controllers\Company;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;
use App\Services\RBAC;

/**
 * 企业客户门户鉴权：仅角色名含「企业客户」的 admin_users 可登录。
 */
class AuthController extends Controller
{
    public function captcha(Request $request): Response
    {
        // 复用主站验证码逻辑
        return (new \App\Controllers\Api\AuthController())->captcha($request);
    }

    public function status(Request $request): Response
    {
        $token = $this->bearer($request);
        if ($token === '') {
            return $this->success([
                'authEnabled' => true,
                'authenticated' => false,
                'portal' => 'company',
                'user' => null,
            ]);
        }
        $user = $this->userFromToken($token);
        if (!$user || !$this->isEnterpriseRole($user)) {
            return $this->success([
                'authEnabled' => true,
                'authenticated' => false,
                'portal' => 'company',
                'user' => null,
            ]);
        }
        return $this->success([
            'authEnabled' => true,
            'authenticated' => true,
            'portal' => 'company',
            'user' => $this->publicUser($user),
        ]);
    }

    public function login(Request $request): Response
    {
        $username = trim((string)$request->post('username', $request->input('username', '')));
        $password = (string)$request->post('password', $request->input('password', ''));
        $captchaKey = (string)$request->post('captchaKey', $request->input('captchaKey', ''));
        $captchaCode = strtoupper(trim((string)$request->post('captchaCode', $request->input('captchaCode', ''))));
        $rememberMe = (bool)$request->post('rememberMe', $request->input('rememberMe', false));

        if ($username === '' || $password === '') {
            return $this->error('用户名或密码不能为空', 422);
        }

        if ($captchaKey !== '') {
            $setting = DB::table('settings')->where('key', 'captcha:' . $captchaKey)->first();
            if (!$setting) {
                return $this->error('验证码已过期，请点击刷新', 400);
            }
            $data = json_decode((string)$setting['value'], true);
            if (!$data || ($data['expires_at'] ?? 0) < time()) {
                DB::table('settings')->where('key', 'captcha:' . $captchaKey)->delete();
                return $this->error('验证码已过期，请点击刷新', 400);
            }
            if (($data['code'] ?? '') !== $captchaCode) {
                return $this->error('验证码不正确', 400);
            }
            DB::table('settings')->where('key', 'captcha:' . $captchaKey)->delete();
        }

        $user = DB::table('admin_users')->where('username', $username)->first();
        if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
            return $this->error('用户名或密码错误', 401);
        }
        if ((int)($user['status'] ?? 0) !== 1) {
            return $this->error('账号已被冻结停用', 403);
        }
        if (!$this->isEnterpriseRole($user)) {
            return $this->error('仅企业客户角色可登录企业门户', 403);
        }

        $token = bin2hex(random_bytes(32));
        $expireDays = $rememberMe ? 30 : 7;
        $expires = date('Y-m-d H:i:s', time() + 86400 * $expireDays);
        DB::table('admin_sessions')->insert([
            'user_id' => (int)$user['id'],
            'token' => $token,
            'expires_at' => $expires,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            RBAC::logOperation(
                (int)$user['id'],
                '企业门户登录',
                'company_auth',
                (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                '/api/company/auth/login'
            );
        } catch (\Throwable $e) {
        }

        return $this->success([
            'token' => $token,
            'user' => $this->publicUser($user),
            'portal' => 'company',
        ], '登录成功');
    }

    public function logout(Request $request): Response
    {
        $token = $this->bearer($request);
        if ($token !== '') {
            try {
                DB::table('admin_sessions')->where('token', $token)->delete();
            } catch (\Throwable $e) {
            }
        }
        return $this->success(null, '已退出');
    }

    private function bearer(Request $request): string
    {
        $token = $request->bearerToken()
            ?? $request->input('token')
            ?? ($_COOKIE['tkswarm_company_token'] ?? null);
        return trim((string)$token);
    }

    /** @return array<string,mixed>|null */
    private function userFromToken(string $token): ?array
    {
        $session = DB::table('admin_sessions')
            ->where('token', $token)
            ->whereRaw('expires_at > ?', [date('Y-m-d H:i:s')])
            ->first();
        if (!$session) {
            return null;
        }
        $user = DB::table('admin_users')->where('id', (int)$session['user_id'])->first();
        if (!$user || (int)($user['status'] ?? 0) !== 1) {
            return null;
        }
        return $user;
    }

    /** @param array<string,mixed> $user */
    private function isEnterpriseRole(array $user): bool
    {
        $roleId = (int)($user['role_id'] ?? 0);
        if ($roleId < 1) {
            return false;
        }
        $role = DB::selectOne('SELECT name FROM admin_roles WHERE id=?', [$roleId]);
        $name = (string)($role['name'] ?? '');
        return str_contains($name, '企业客户');
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function publicUser(array $user): array
    {
        $roleId = (int)($user['role_id'] ?? 0);
        $role = $roleId > 0
            ? DB::selectOne('SELECT name FROM admin_roles WHERE id=?', [$roleId])
            : null;
        return [
            'id' => (int)$user['id'],
            'username' => (string)($user['username'] ?? ''),
            'nickname' => (string)($user['nickname'] ?? ''),
            'roleId' => $roleId,
            'role' => (string)($role['name'] ?? '企业客户'),
            'portal' => 'company',
        ];
    }

    /**
     * 供业务 API 复用：校验企业客户会话并 boot DataScope。
     * @return array{0:?array<string,mixed>,1:?Response}
     */
    public static function requireEnterprise(Request $request): array
    {
        $token = trim((string)(
            $request->bearerToken()
            ?? $request->input('token')
            ?? ($_COOKIE['tkswarm_company_token'] ?? '')
        ));
        if ($token === '') {
            return [null, Response::error('未授权或登录凭证已失效，请重新登录', 401)];
        }
        $session = DB::table('admin_sessions')
            ->where('token', $token)
            ->whereRaw('expires_at > ?', [date('Y-m-d H:i:s')])
            ->first();
        if (!$session) {
            return [null, Response::error('登录状态已过期，请重新登录', 401)];
        }
        $user = DB::table('admin_users')->where('id', (int)$session['user_id'])->first();
        if (!$user || (int)($user['status'] ?? 0) !== 1) {
            return [null, Response::error('用户账号不存在或已被冻结', 403)];
        }
        $roleId = (int)($user['role_id'] ?? 0);
        $role = $roleId > 0 ? DB::selectOne('SELECT name FROM admin_roles WHERE id=?', [$roleId]) : null;
        $roleName = (string)($role['name'] ?? '');
        if (!str_contains($roleName, '企业客户')) {
            return [null, Response::error('仅企业客户可访问企业门户', 403)];
        }
        $GLOBALS['auth_user'] = $user;
        DataScope::bootFromGlobals($request);
        return [$user, null];
    }
}
