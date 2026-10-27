<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth\RBAC;
use App\Core\Request;
use App\Core\Response;

/**
 * 后台写操作审计：在 Router 派发成功后统一落库。
 */
class OperationAudit
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** 节点回调等高频/非人工操作，不记入审计 */
    private const SKIP_PREFIXES = [
        '/api/node/',
    ];

    private const SKIP_EXACT = [
        '/api/auth/login',
        '/api/auth/setup',
        '/api/auth/captcha',
        '/api/tickets',
    ];

    public static function maybeRecord(Request $request, Response $response): void
    {
        try {
            $method = strtoupper($request->getMethod());
            if (!in_array($method, self::MUTATING, true)) {
                return;
            }

            $path = $request->getPath();
            if (!str_starts_with($path, '/api')) {
                return;
            }
            if (self::shouldSkip($path)) {
                return;
            }
            if (!self::isSuccess($response)) {
                return;
            }

            $user = $GLOBALS['auth_user'] ?? null;
            $userId = (int)($user['id'] ?? 0);
            // 未登录的写接口（除已单独记登录日志的）不记
            if ($userId <= 0 && empty($user)) {
                return;
            }

            $module = self::resolveModule($path);
            $action = self::resolveAction($method, $path);
            RBAC::logOperation(
                $userId,
                $action,
                $module,
                (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                $path
            );
        } catch (\Throwable $e) {
            // 审计失败不影响主流程
            error_log('[OperationAudit] ' . $e->getMessage());
        }
    }

    private static function shouldSkip(string $path): bool
    {
        if (in_array($path, self::SKIP_EXACT, true)) {
            return true;
        }
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function isSuccess(Response $response): bool
    {
        $code = $response->getStatusCode();
        if ($code >= 400) {
            return false;
        }
        $raw = $response->getContent();
        if (!is_string($raw) || $raw === '') {
            return $code >= 200 && $code < 400;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return true;
        }
        if (array_key_exists('success', $data)) {
            return (bool)$data['success'];
        }
        if (isset($data['code']) && is_numeric($data['code'])) {
            return (int)$data['code'] < 400;
        }
        return true;
    }

    private static function resolveModule(string $path): string
    {
        // /api/{segment}/...
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        $seg = $parts[1] ?? 'system';

        $map = [
            'admin-users' => 'rbac',
            'admin-roles' => 'rbac',
            'admin-permissions' => 'rbac',
            'admin-logs' => 'rbac',
            'admin-menus' => 'rbac',
            'accounts' => 'account',
            'account-groups' => 'account',
            'account-work' => 'account',
            'proxies' => 'proxy',
            'proxy-groups' => 'proxy',
            'materials' => 'material',
            'tasks' => 'task',
            'settings' => 'settings',
            'auth' => 'auth',
            'agents' => 'ai',
            'ai-platforms' => 'ai',
            'ai-models' => 'ai',
            'prompt-templates' => 'ai',
            'interaction-templates' => 'interaction',
            'message-templates' => 'message',
            'chat' => 'chat',
            'mass' => 'mass',
            'warm' => 'warm',
            'publish' => 'publish',
            'comment-reply' => 'comment_reply',
            'video-delete' => 'video_delete',
            'client-versions' => 'ops',
            'env-installers' => 'ops',
            'help' => 'ops',
            'tickets' => 'ops',
            'meta' => 'system',
            'dashboard' => 'system',
        ];

        return $map[$seg] ?? preg_replace('/[^a-z0-9_]/', '_', strtolower($seg)) ?: 'system';
    }

    private static function resolveAction(string $method, string $path): string
    {
        $tail = preg_replace('#^/api/#', '', $path) ?: $path;
        // 末段常见动词更易读
        if (preg_match('#/(delete|clear|import|export|save|create|update|sync|start|stop|retry|assign)$#', $path, $m)) {
            return strtoupper($method) . ' ' . $m[1] . ' · ' . $tail;
        }
        return strtoupper($method) . ' ' . $tail;
    }
}
