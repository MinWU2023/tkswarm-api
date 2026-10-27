<?php
declare(strict_types=1);

namespace App\Services;

/**
 * API 路由 → 权限码映射（任一命中即可）。
 */
class RoutePermissionMap
{
    /** @var list<string> */
    private static array $exemptPrefixes = [
        '/api/auth/',
        '/api/meta',
        '/api/menus/tree',
        '/api/node/',
        '/api/translate/',
        '/api/browser/',
        '/api/dashboard/',
        '/api/system/info',
    ];

    public static function isExempt(string $method, string $path): bool
    {
        foreach (self::$exemptPrefixes as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>|null null = 不校验
     */
    public static function requiredCodes(string $method, string $path): ?array
    {
        if (self::isExempt($method, $path)) {
            return null;
        }

        foreach (self::explicitRules() as $rule) {
            if ($rule['method'] !== $method && $rule['method'] !== '*') {
                continue;
            }
            if (!preg_match($rule['pattern'], $path)) {
                continue;
            }
            return $rule['codes'];
        }

        return self::inferCodes($method, $path);
    }

    /** @return list<array{method:string,pattern:string,codes:list<string>}> */
    private static function explicitRules(): array
    {
        return [
            ['method' => 'POST', 'pattern' => '#^/api/permissions#', 'codes' => ['rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/roles#', 'codes' => ['rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/admin-users#', 'codes' => ['rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/admin-users#', 'codes' => ['rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/admin-logs/clear#', 'codes' => ['rbac.manage', 'rbac.log']],
            ['method' => 'POST', 'pattern' => '#^/api/system/backup#', 'codes' => ['system.view', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/system/restore#', 'codes' => ['system.view', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/settings#', 'codes' => ['system.view', 'rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/settings#', 'codes' => ['system.view', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/menus#', 'codes' => ['rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/menus#', 'codes' => ['rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/menus#', 'codes' => ['rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/menus/sync-permissions#', 'codes' => ['rbac.manage']],
            ['method' => 'GET', 'pattern' => '#^/api/accounts/export#', 'codes' => ['account.export']],
            ['method' => 'GET', 'pattern' => '#^/api/proxies/export#', 'codes' => ['proxy.view', 'proxy.create']],
            ['method' => 'POST', 'pattern' => '#^/api/accounts/batch-import#', 'codes' => ['account.import']],
            ['method' => 'POST', 'pattern' => '#^/api/accounts/batch-delete#', 'codes' => ['account.delete']],
            ['method' => 'POST', 'pattern' => '#^/api/accounts/batch-delete-banned#', 'codes' => ['account.delete']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/batch-import#', 'codes' => ['proxy.import']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/batch-delete#', 'codes' => ['proxy.create']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/batch-unassign-owner#', 'codes' => ['proxy.create']],
            ['method' => 'POST', 'pattern' => '#^/api/chat/messages$#', 'codes' => ['chat.send', 'chat.manage', 'publish.mass']],
            ['method' => 'POST', 'pattern' => '#^/api/uids/import#', 'codes' => ['chat.manage', 'publish.mass']],
            ['method' => 'POST', 'pattern' => '#^/api/country-data#', 'codes' => ['rbac.country_data', 'rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/country-data#', 'codes' => ['rbac.country_data', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/country-data#', 'codes' => ['rbac.country_data', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/industry-data#', 'codes' => ['rbac.industry_data', 'rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/industry-data#', 'codes' => ['rbac.industry_data', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/industry-data#', 'codes' => ['rbac.industry_data', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/companies#', 'codes' => ['rbac.company_data', 'rbac.industry_data', 'rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/companies#', 'codes' => ['rbac.company_data', 'rbac.industry_data', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/companies#', 'codes' => ['rbac.company_data', 'rbac.industry_data', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/account-work-batches/clear#', 'codes' => ['account.work.delete']],
            ['method' => 'POST', 'pattern' => '#^/api/account-work-batches/.+/pause#', 'codes' => ['account.work.pause']],
            ['method' => 'POST', 'pattern' => '#^/api/account-work-batches/.+/resume#', 'codes' => ['account.work.pause']],
            ['method' => 'POST', 'pattern' => '#^/api/account-work-batches/.+/delete#', 'codes' => ['account.work.delete']],
            ['method' => 'POST', 'pattern' => '#^/api/account-work-batches/.+/reset#', 'codes' => ['account.work.reset']],
            ['method' => 'POST', 'pattern' => '#^/api/account-work-batches/.+/force-fail#', 'codes' => ['account.work.pause']],
            ['method' => 'POST', 'pattern' => '#^/api/account-work-batches/.+/items/.+/pause#', 'codes' => ['account.work.pause']],
            ['method' => 'POST', 'pattern' => '#^/api/account-work-batches/.+/items/.+/resume#', 'codes' => ['account.work.pause']],
            ['method' => 'POST', 'pattern' => '#^/api/account-work-batches/.+/items/.+/reset#', 'codes' => ['account.work.reset']],
        ];
    }

    /** @return list<string>|null */
    private static function inferCodes(string $method, string $path): ?array
    {
        $isRead = in_array($method, ['GET', 'HEAD'], true);
        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if (!$isRead && !$isWrite) {
            return null;
        }

        $map = [
            '/api/accounts' => [
                'read' => ['account.view', 'account.create', 'account.export'],
                'write' => ['account.create'],
                'delete' => ['account.delete'],
            ],
            '/api/proxies' => [
                'read' => ['proxy.view', 'proxy.create'],
                'write' => ['proxy.create'],
            ],
            '/api/groups' => [
                'read' => ['group.manage', 'account.view'],
                'write' => ['group.manage'],
            ],
            '/api/tasks' => [
                'read' => ['publish.tasks', 'publish.mass', 'warm.tasks', 'matrix.publish'],
                'write' => ['publish.tasks', 'publish.mass', 'warm.tasks'],
            ],
            '/api/materials' => [
                'read' => ['publish.materials', 'publish.tasks'],
                'write' => ['publish.materials'],
            ],
            '/api/chat/' => [
                'read' => ['chat.manage', 'chat.send', 'publish.mass'],
                'write' => ['chat.manage', 'publish.mass'],
            ],
            '/api/uids' => [
                'read' => ['chat.manage', 'publish.mass'],
                'write' => ['chat.manage', 'publish.mass'],
            ],
            '/api/agents' => [
                'read' => ['agent.view', 'agent.manage'],
                'write' => ['agent.manage'],
            ],
            '/api/ai-leads' => [
                'read' => ['ai.leads', 'account.view'],
                'write' => ['ai.leads'],
            ],
            '/api/ai-lead-clues' => [
                'read' => ['ai.leads', 'account.view'],
                'write' => ['ai.leads'],
            ],
            '/api/ai-lead-clue-data' => [
                'read' => ['ai.leads', 'account.view'],
                'write' => ['ai.leads'],
            ],
            '/api/auto-follow-tasks' => [
                'read' => ['ai.leads', 'account.view'],
                'write' => ['ai.leads'],
            ],
            '/api/interaction-templates' => [
                'read' => ['account.msg_template.comment', 'account.msg_template.dm', 'chat.manage'],
                'write' => ['account.msg_template.comment', 'account.msg_template.dm', 'chat.manage'],
            ],
            '/api/message-templates' => [
                'read' => ['chat.manage', 'account.msg_template.comment', 'account.msg_template.dm'],
                'write' => ['chat.manage', 'account.msg_template.comment', 'account.msg_template.dm'],
            ],
            '/api/account-work-batches' => [
                'read' => ['account.work.pause', 'account.work.delete', 'account.work.reset', 'account.view'],
                'write' => ['account.work.pause', 'account.work.delete', 'account.work.reset'],
            ],
            '/api/permissions' => ['read' => ['rbac.manage'], 'write' => ['rbac.manage']],
            '/api/roles' => ['read' => ['rbac.manage'], 'write' => ['rbac.manage']],
            '/api/admin-users' => ['read' => ['rbac.manage'], 'write' => ['rbac.manage']],
            '/api/admin-logs' => ['read' => ['rbac.log', 'rbac.manage', 'logs.view'], 'write' => ['rbac.manage']],
            '/api/country-data' => [
                'read' => ['rbac.country_data', 'rbac.manage', 'account.view', 'account.create', 'proxy.view', 'proxy.create', 'system.view'],
                'write' => ['rbac.country_data', 'rbac.manage'],
            ],
            '/api/industry-data' => ['read' => ['rbac.industry_data', 'rbac.manage'], 'write' => ['rbac.industry_data', 'rbac.manage']],
            '/api/companies' => ['read' => ['rbac.company_data', 'rbac.industry_data', 'rbac.manage'], 'write' => ['rbac.company_data', 'rbac.industry_data', 'rbac.manage']],
            '/api/settings' => ['read' => ['system.view', 'rbac.manage'], 'write' => ['system.view', 'rbac.manage']],
            '/api/help-' => ['read' => ['system.help', 'system.view', 'rbac.manage'], 'write' => ['system.help', 'rbac.manage']],
            '/api/tickets' => ['read' => ['system.tickets', 'system.view', 'rbac.manage'], 'write' => ['system.tickets', 'rbac.manage']],
            '/api/client-versions' => ['read' => ['system.versions', 'system.view', 'rbac.manage'], 'write' => ['system.versions', 'rbac.manage']],
            '/api/env-installers' => ['read' => ['system.env_installers', 'system.view', 'rbac.manage'], 'write' => ['system.env_installers', 'rbac.manage']],
            '/api/ai-platforms' => ['read' => ['system.ai_platform', 'system.view', 'rbac.manage'], 'write' => ['system.ai_platform', 'rbac.manage']],
            '/api/ai-prompt-templates' => ['read' => ['system.ai_prompt', 'system.ai_platform', 'system.view', 'rbac.manage'], 'write' => ['system.ai_prompt', 'rbac.manage']],
            '/api/ai-call-logs' => ['read' => ['system.ai_log', 'system.ai_platform', 'system.view', 'rbac.manage'], 'write' => ['system.ai_log', 'rbac.manage']],
        ];

        foreach ($map as $prefix => $rules) {
            if (!str_starts_with($path, $prefix)) {
                continue;
            }
            if ($method === 'DELETE' && isset($rules['delete'])) {
                return $rules['delete'];
            }
            return $isRead ? $rules['read'] : $rules['write'];
        }

        return null;
    }
}
