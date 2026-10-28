<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth\RBAC;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;

class AuthController extends Controller
{
    public function captcha(Request $request): Response
    {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $key = 'cap_' . bin2hex(random_bytes(8)) . time();

        // 存入 settings 或缓存临时表，这里存储在 settings 表带有前缀
        DB::table('settings')->insert([
            'key' => 'captcha:' . $key,
            'value' => json_encode(['code' => strtoupper($code), 'expires_at' => time() + 300]),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $w = 120;
        $h = 42;
        $colors = ['#2563eb', '#0284c7', '#0d9488', '#4f46e5', '#d97706'];
        $charElements = '';
        for ($i = 0; $i < 4; $i++) {
            $ch = $code[$i];
            $x = 20 + $i * 24;
            $y = 28 + random_int(-3, 3);
            $rot = random_int(-12, 12);
            $col = $colors[$i % count($colors)];
            $charElements .= "<text x=\"{$x}\" y=\"{$y}\" font-family=\"Arial, sans-serif\" font-weight=\"bold\" font-size=\"24\" fill=\"{$col}\" transform=\"rotate({$rot} {$x} {$y})\">{$ch}</text>";
        }

        $lines = '';
        for ($i = 0; $i < 3; $i++) {
            $x1 = random_int(0, 30);
            $y1 = random_int(0, $h);
            $x2 = random_int($w - 30, $w);
            $y2 = random_int(0, $h);
            $col = $colors[random_int(0, count($colors) - 1)];
            $lines .= "<line x1=\"{$x1}\" y1=\"{$y1}\" x2=\"{$x2}\" y2=\"{$y2}\" stroke=\"{$col}\" stroke-width=\"1.2\" opacity=\"0.4\"/>";
        }

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$w}\" height=\"{$h}\" viewBox=\"0 0 {$w} {$h}\">
            <rect width=\"{$w}\" height=\"{$h}\" fill=\"#f1f5f9\" rx=\"8\"/>
            {$lines}
            {$charElements}
        </svg>";

        return $this->success([
            'captchaKey' => $key,
            'svg' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'rawSvg' => $svg
        ]);
    }

    public function status(Request $request): Response
    {
        $authEnabled = $this->authEnabled();
        $token = $request->bearerToken();
        $user = null;
        if ($token) {
            $session = DB::table('admin_sessions')->where('token', $token)->whereRaw('expires_at > ?', [date('Y-m-d H:i:s')])->first();
            if ($session) {
                $user = DB::table('admin_users')->where('id', $session['user_id'])->first();
            }
        }

        $userCount = DB::table('admin_users')->count();
        $authenticated = (bool)$user || !$authEnabled;

        $loginIsSuper = false;
        $children = [];
        $actingAs = null;
        $effectiveUser = $user;
        if ($user) {
            $loginIsSuper = (int)($user['role_id'] ?? 0) === 1;
            $loginRoleName = $this->roleName($user);
            $loginIsOpsAdmin = !$loginIsSuper && str_contains($loginRoleName, '运营管理');
            $children = array_map(static function ($c) {
                return [
                    'id' => (int)$c['id'],
                    'username' => $c['username'],
                    'nickname' => $c['nickname'] ?? '',
                ];
            }, DataScope::childrenOf((int)$user['id']));

            $rawActing = $request->getHeader('x-acting-as') ?? $request->get('actingAs');
            if ($rawActing !== null && $rawActing !== '' && $rawActing !== '0' && $rawActing !== 'self') {
                $aid = (int)$rawActing;
                if ($aid > 0 && $aid !== (int)$user['id']) {
                    $ok = false;
                    if ($loginIsSuper) {
                        $ok = true;
                    } elseif ($loginIsOpsAdmin) {
                        $ok = !DataScope::isSwitchBlockedTarget($aid);
                    } else {
                        $ok = DataScope::isDirectChild((int)$user['id'], $aid);
                    }
                    if ($ok) {
                        $target = DB::table('admin_users')->where('id', $aid)->first();
                        if ($target && (int)($target['status'] ?? 0) === 1) {
                            $actingAs = [
                                'id' => (int)$target['id'],
                                'username' => $target['username'],
                                'nickname' => $target['nickname'] ?? '',
                            ];
                            $effectiveUser = $target;
                        }
                    }
                }
            }
        } elseif (!$authEnabled) {
            $loginIsSuper = true;
        }

        return $this->success([
            'authEnabled' => $authEnabled,
            'needsSetup' => $authEnabled && $userCount === 0,
            'authenticated' => $authenticated,
            'isSuper' => $loginIsSuper,
            'children' => $children,
            'actingAs' => $actingAs,
            'loginUser' => $user ? $this->formatAuthUser($user) : null,
            'user' => $effectiveUser
                ? $this->formatAuthUser($effectiveUser)
                : (!$authEnabled ? [
                    'id' => 0,
                    'username' => 'guest',
                    'role' => 'admin',
                    'roleId' => 1,
                    'permissions' => ['*'],
                    'isSuper' => true,
                ] : null),
        ]);
    }

    public function switchableUsers(Request $request): Response
    {
        $user = $GLOBALS['auth_user'] ?? null;
        if (!$user) {
            return $this->error('未登录', 401);
        }
        $keyword = trim((string)$request->get('keyword', ''));
        return $this->success(DataScope::switchableUsers($keyword));
    }

    public function accountFilterOptions(Request $request): Response
    {
        $keyword = trim((string)$request->get('keyword', ''));
        $industryId = (int)$request->get('industryId', $request->get('industry_id', 0));
        return $this->success([
            'enabled' => DataScope::canUseAccountAdvancedFilter(),
            'users' => DataScope::accountFilterUsers($keyword),
            'companies' => DataScope::accountFilterCompanies($keyword, $industryId),
        ]);
    }

    public function accountAssignTargets(Request $request): Response
    {
        return $this->success(DataScope::accountAssignTargets());
    }

    public function groupAssignTargets(Request $request): Response
    {
        return $this->success(DataScope::groupAssignTargets());
    }

    public function proxyAssignTargets(Request $request): Response
    {
        return $this->success(DataScope::proxyAssignTargets());
    }

    private function authEnabled(): bool
    {
        $row = DB::selectOne("SELECT `value` FROM settings WHERE `key`='authEnabled'");
        if (!$row) {
            return true;
        }
        $val = json_decode((string)$row['value'], true);
        if ($val === null && $row['value'] !== 'null') {
            $val = $row['value'];
        }
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    private function roleName(array $user): string
    {
        if (empty($user['role_id'])) {
            return 'admin';
        }
        $role = DB::selectOne('SELECT name FROM admin_roles WHERE id=?', [(int)$user['role_id']]);
        return (string)($role['name'] ?? 'user');
    }

    /** @param array<string,mixed> $user */
    private function formatAuthUser(array $user): array
    {
        $roleId = (int)($user['role_id'] ?? 0);
        $isSuper = $roleId === 1;
        return [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'nickname' => (string)($user['nickname'] ?? ''),
            'role' => $this->roleName($user),
            'roleId' => $roleId > 0 ? $roleId : null,
            'role_id' => $roleId > 0 ? $roleId : null,
            'permissions' => $this->permissionsForUser($user),
            'isSuper' => $isSuper,
        ];
    }

    private function permissionsForUser(array $user): array
    {
        $roleId = (int)($user['role_id'] ?? 0);
        if ($roleId === 1 || $roleId === 0) {
            return ['*'];
        }
        $role = DB::selectOne('SELECT permissions, status FROM admin_roles WHERE id=?', [$roleId]);
        if (!$role || (int)$role['status'] !== 1) {
            return [];
        }
        $ids = json_decode((string)($role['permissions'] ?? '[]'), true);
        if (!is_array($ids)) {
            $ids = [];
        }
        if (in_array('*', $ids, true)) {
            return ['*'];
        }
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select("SELECT code FROM admin_permissions WHERE id IN ({$ph})", array_map('intval', $ids));
        return array_values(array_map(fn($r) => $r['code'], $rows));
    }

    public function setup(Request $request): Response
    {
        $count = (int)DB::table('admin_users')->count();
        if ($count > 0) {
            return $this->error('管理员已存在，请直接登录', 409);
        }
        $username = trim((string)$request->input('username', ''));
        $password = (string)$request->input('password', '');
        if (mb_strlen($username) < 2 || mb_strlen($username) > 40) {
            return $this->error('用户名长度为 2-40', 422);
        }
        if (strlen($password) < 6) {
            return $this->error('密码至少 6 位', 422);
        }
        DB::query(
            'INSERT INTO settings(`key`, `value`) VALUES (?,?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=CURRENT_TIMESTAMP',
            ['authEnabled', 'true']
        );
        $id = DB::table('admin_users')->insert([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => $username,
            'role_id' => 1,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 86400 * 7);
        DB::table('admin_sessions')->insert([
            'user_id' => $id,
            'token' => $token,
            'expires_at' => $expires,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->success([
            'token' => $token,
            'expiresAt' => $expires,
            'user' => [
                'id' => (int)$id,
                'username' => $username,
                'role' => 'admin',
                'roleId' => 1,
                'permissions' => ['*'],
            ],
        ], '管理员已创建并开启鉴权');
    }

    public function login(Request $request): Response
    {
        $username = trim((string)$request->post('username', ''));
        $password = (string)$request->post('password', '');
        $captchaKey = (string)$request->post('captchaKey', '');
        $captchaCode = strtoupper(trim((string)$request->post('captchaCode', '')));
        $rememberMe = (bool)$request->post('rememberMe', false);

        if (empty($username) || empty($password)) {
            return $this->error('用户名或密码不能为空');
        }

        // 校验验证码
        if (!empty($captchaKey)) {
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
        if (!$user) {
            return $this->error('用户名或密码错误', 401);
        }

        if ($user['status'] !== 1) {
            return $this->error('账号已被冻结停用', 403);
        }

        if (!password_verify($password, $user['password_hash'])) {
            return $this->error('用户名或密码错误', 401);
        }

        $token = bin2hex(random_bytes(32));
        $expireDays = $rememberMe ? 30 : 7;
        $expires = date('Y-m-d H:i:s', time() + 86400 * $expireDays);

        DB::table('admin_sessions')->insert([
            'user_id' => $user['id'],
            'token' => $token,
            'expires_at' => $expires,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // 记录登录日志（登录路由在 Auth 中间件外，由此处单独写入）
        RBAC::logOperation(
            (int)$user['id'],
            '用户登录系统',
            'auth',
            (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            '/api/auth/login'
        );

        return $this->success([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'nickname' => $user['nickname'],
                'role_id' => $user['role_id']
            ]
        ], '登录成功');
    }

    public function updatePassword(Request $request): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            return $this->error('未登录', 401);
        }
        $session = DB::table('admin_sessions')->where('token', $token)->whereRaw('expires_at > ?', [date('Y-m-d H:i:s')])->first();
        if (!$session) {
            return $this->error('登录会话已过期', 401);
        }
        $oldPassword = (string)$request->post('oldPassword', '');
        $newPassword = (string)$request->post('newPassword', '');
        if (strlen($newPassword) < 6) {
            return $this->error('新密码长度不能少于 6 位');
        }
        $user = DB::table('admin_users')->where('id', $session['user_id'])->first();
        if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
            return $this->error('原密码不正确', 422);
        }
        DB::table('admin_users')->where('id', $user['id'])->update([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        return $this->success(null, '密码修改成功');
    }

    public function logout(Request $request): Response
    {
        $token = $request->bearerToken();
        if ($token) {
            DB::table('admin_sessions')->where('token', $token)->delete();
        }
        return $this->success(null, '已安全登出');
    }
}
