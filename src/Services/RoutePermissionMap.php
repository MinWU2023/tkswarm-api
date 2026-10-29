<?php
declare(strict_types=1);

namespace App\Services;

/**
 * API 路由 → 权限码映射（任一命中即可）。
 * 约定：列表用 *.view，添加 *.create，编辑 *.update，删除 *.delete；
 * 粗粒度码通过 PermissionCatalog::aliases 兼容。
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
            // —— 权限节点 ——
            ['method' => 'GET', 'pattern' => '#^/api/permissions#', 'codes' => ['rbac.permissions.view', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/permissions/delete#', 'codes' => ['rbac.permissions.delete', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/permissions#', 'codes' => ['rbac.permissions.delete', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/permissions#', 'codes' => ['rbac.permissions.create', 'rbac.permissions.update', 'rbac.manage']],

            // —— 角色 ——
            ['method' => 'GET', 'pattern' => '#^/api/roles#', 'codes' => ['rbac.roles.view', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/roles/delete#', 'codes' => ['rbac.roles.delete', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/roles#', 'codes' => ['rbac.roles.delete', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/roles#', 'codes' => ['rbac.roles.create', 'rbac.roles.update', 'rbac.manage']],

            // —— 管理用户 ——
            ['method' => 'GET', 'pattern' => '#^/api/admin-users#', 'codes' => ['rbac.users.view', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/admin-users/delete#', 'codes' => ['rbac.users.delete', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/admin-users#', 'codes' => ['rbac.users.delete', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/admin-users#', 'codes' => ['rbac.users.create', 'rbac.users.update', 'rbac.manage']],

            // —— 审计日志 ——
            ['method' => 'GET', 'pattern' => '#^/api/admin-logs#', 'codes' => ['rbac.logs.view', 'rbac.log', 'rbac.manage', 'logs.view']],
            ['method' => 'POST', 'pattern' => '#^/api/admin-logs/clear#', 'codes' => ['rbac.logs.clear', 'rbac.manage']],

            // —— 菜单 ——
            ['method' => 'GET', 'pattern' => '#^/api/menus(?!/tree)#', 'codes' => ['rbac.menus.view', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/menus/sync-permissions#', 'codes' => ['rbac.menus.update', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/menus#', 'codes' => ['rbac.menus.update', 'rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/menus#', 'codes' => ['rbac.menus.update', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/menus#', 'codes' => ['rbac.menus.update', 'rbac.manage']],

            // —— 系统设置 / 备份 ——
            ['method' => 'GET', 'pattern' => '#^/api/settings#', 'codes' => ['system.settings.view', 'system.view', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/settings#', 'codes' => ['system.settings.update', 'system.view', 'rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/settings#', 'codes' => ['system.settings.update', 'system.view', 'rbac.manage']],

            // SMTP：中间件放行已登录超管（*）；控制器再强制 isLoginSuper
            ['method' => 'GET', 'pattern' => '#^/api/smtp-config#', 'codes' => ['system.smtp', 'admin']],
            ['method' => 'POST', 'pattern' => '#^/api/smtp-config#', 'codes' => ['system.smtp', 'admin']],
            ['method' => 'PUT', 'pattern' => '#^/api/smtp-config#', 'codes' => ['system.smtp', 'admin']],
            ['method' => 'POST', 'pattern' => '#^/api/system/backup#', 'codes' => ['system.settings.update', 'system.view', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/system/restore#', 'codes' => ['system.settings.update', 'system.view', 'rbac.manage']],

            // —— 账号 ——
            // 列表只读：已登录即可（DataScope 仍按归属过滤），供「拉取视频列表」等全角色功能勾选账号
            ['method' => 'GET', 'pattern' => '#^/api/accounts$#', 'codes' => []],
            ['method' => 'GET', 'pattern' => '#^/api/accounts/export#', 'codes' => ['account.export']],
            ['method' => 'POST', 'pattern' => '#^/api/accounts/batch-import#', 'codes' => ['account.import']],
            ['method' => 'POST', 'pattern' => '#^/api/accounts/batch-delete#', 'codes' => ['account.delete']],
            ['method' => 'POST', 'pattern' => '#^/api/accounts/batch-delete-banned#', 'codes' => ['account.delete']],
            ['method' => 'DELETE', 'pattern' => '#^/api/accounts#', 'codes' => ['account.delete']],
            ['method' => 'PUT', 'pattern' => '#^/api/accounts#', 'codes' => ['account.update', 'account.create']],
            ['method' => 'PATCH', 'pattern' => '#^/api/accounts#', 'codes' => ['account.update', 'account.create']],
            ['method' => 'POST', 'pattern' => '#^/api/accounts$#', 'codes' => ['account.create']],
            ['method' => 'POST', 'pattern' => '#^/api/accounts/#', 'codes' => ['account.update', 'account.create']],

            // —— 代理 ——
            ['method' => 'GET', 'pattern' => '#^/api/proxies/export#', 'codes' => ['proxy.view', 'proxy.create']],
            ['method' => 'GET', 'pattern' => '#^/api/proxies/\d+/accounts#', 'codes' => ['proxy.view', 'proxy.create', 'proxy.update']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/\d+/bind-accounts#', 'codes' => ['proxy.update', 'proxy.create']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/\d+/unbind-accounts#', 'codes' => ['proxy.update', 'proxy.create']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/batch-import#', 'codes' => ['proxy.import']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/batch-delete#', 'codes' => ['proxy.delete', 'proxy.create']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/batch-unassign-owner#', 'codes' => ['proxy.update', 'proxy.create']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/.+/test#', 'codes' => ['proxy.test', 'proxy.create']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies/batch-test#', 'codes' => ['proxy.test', 'proxy.create']],
            ['method' => 'DELETE', 'pattern' => '#^/api/proxies#', 'codes' => ['proxy.delete', 'proxy.create']],
            ['method' => 'PUT', 'pattern' => '#^/api/proxies#', 'codes' => ['proxy.update', 'proxy.create']],
            ['method' => 'POST', 'pattern' => '#^/api/proxies$#', 'codes' => ['proxy.create']],

            // —— 分组 ——
            ['method' => 'GET', 'pattern' => '#^/api/groups#', 'codes' => ['group.view', 'group.manage', 'account.view']],
            ['method' => 'POST', 'pattern' => '#^/api/groups/\d+/assign-owner#', 'codes' => ['group.assign']],
            ['method' => 'POST', 'pattern' => '#^/api/groups$#', 'codes' => ['group.create', 'group.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/groups#', 'codes' => ['group.update', 'group.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/groups#', 'codes' => ['group.delete', 'group.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/groups/.+/delete#', 'codes' => ['group.delete', 'group.manage']],

            // —— 任务 / 养号 / 群发 ——
            ['method' => 'GET', 'pattern' => '#^/api/tasks#', 'codes' => ['publish.tasks.view', 'publish.mass.view', 'warm.view', 'publish.tasks', 'publish.mass', 'warm.tasks', 'matrix.publish']],
            ['method' => 'DELETE', 'pattern' => '#^/api/tasks#', 'codes' => ['publish.tasks.delete', 'publish.mass.delete', 'warm.delete', 'publish.tasks', 'publish.mass', 'warm.tasks']],
            ['method' => 'POST', 'pattern' => '#^/api/tasks/batch-delete#', 'codes' => ['publish.tasks.delete', 'publish.mass.delete', 'warm.delete', 'publish.tasks', 'publish.mass', 'warm.tasks']],
            ['method' => 'PUT', 'pattern' => '#^/api/tasks#', 'codes' => ['publish.tasks.update', 'publish.mass.update', 'warm.update', 'publish.tasks', 'publish.mass', 'warm.tasks']],
            ['method' => 'POST', 'pattern' => '#^/api/tasks$#', 'codes' => ['publish.tasks.create', 'publish.mass.create', 'warm.create', 'publish.tasks', 'publish.mass', 'warm.tasks']],
            ['method' => 'POST', 'pattern' => '#^/api/tasks/#', 'codes' => ['publish.tasks.update', 'publish.mass.update', 'warm.update', 'publish.tasks.create', 'publish.mass.create', 'warm.create', 'publish.tasks', 'publish.mass', 'warm.tasks']],

            // —— 素材 ——
            ['method' => 'GET', 'pattern' => '#^/api/materials#', 'codes' => ['publish.materials.view', 'publish.materials', 'publish.tasks']],
            ['method' => 'POST', 'pattern' => '#^/api/materials$#', 'codes' => ['publish.materials.create', 'publish.materials']],
            ['method' => 'PUT', 'pattern' => '#^/api/materials#', 'codes' => ['publish.materials.update', 'publish.materials']],
            ['method' => 'DELETE', 'pattern' => '#^/api/materials#', 'codes' => ['publish.materials.delete', 'publish.materials']],
            ['method' => 'POST', 'pattern' => '#^/api/materials/#', 'codes' => ['publish.materials.create', 'publish.materials.update', 'publish.materials']],

            // —— 客服 / UID ——
            ['method' => 'POST', 'pattern' => '#^/api/chat/messages$#', 'codes' => ['chat.send', 'chat.manage', 'publish.mass']],
            ['method' => 'POST', 'pattern' => '#^/api/uids/import#', 'codes' => ['chat.update', 'chat.manage', 'publish.mass']],
            ['method' => 'GET', 'pattern' => '#^/api/chat/#', 'codes' => ['chat.view', 'chat.manage', 'chat.send', 'publish.mass']],
            ['method' => 'POST', 'pattern' => '#^/api/chat/#', 'codes' => ['chat.update', 'chat.manage', 'publish.mass']],

            // —— 国家 / 行业 / 企业 ——
            ['method' => 'GET', 'pattern' => '#^/api/country-data#', 'codes' => ['rbac.country_data.view', 'rbac.country_data', 'rbac.manage', 'account.view', 'system.view']],
            ['method' => 'POST', 'pattern' => '#^/api/country-data#', 'codes' => ['rbac.country_data.create', 'rbac.country_data.update', 'rbac.country_data', 'rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/country-data#', 'codes' => ['rbac.country_data.update', 'rbac.country_data', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/country-data#', 'codes' => ['rbac.country_data.delete', 'rbac.country_data', 'rbac.manage']],
            ['method' => 'GET', 'pattern' => '#^/api/industry-data#', 'codes' => ['rbac.industry_data.view', 'rbac.industry_data', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/industry-data#', 'codes' => ['rbac.industry_data.create', 'rbac.industry_data.update', 'rbac.industry_data', 'rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/industry-data#', 'codes' => ['rbac.industry_data.update', 'rbac.industry_data', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/industry-data#', 'codes' => ['rbac.industry_data.delete', 'rbac.industry_data', 'rbac.manage']],
            ['method' => 'GET', 'pattern' => '#^/api/companies#', 'codes' => ['rbac.company_data.view', 'rbac.company_data', 'rbac.industry_data', 'rbac.manage']],
            ['method' => 'POST', 'pattern' => '#^/api/companies#', 'codes' => ['rbac.company_data.create', 'rbac.company_data.update', 'rbac.company_data', 'rbac.manage']],
            ['method' => 'PUT', 'pattern' => '#^/api/companies#', 'codes' => ['rbac.company_data.update', 'rbac.company_data', 'rbac.manage']],
            ['method' => 'DELETE', 'pattern' => '#^/api/companies#', 'codes' => ['rbac.company_data.delete', 'rbac.company_data', 'rbac.manage']],

            // —— 任务汇总 ——
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
                'write' => ['account.create', 'account.update'],
                'delete' => ['account.delete'],
            ],
            '/api/proxies' => [
                'read' => ['proxy.view', 'proxy.create'],
                'write' => ['proxy.create', 'proxy.update'],
                'delete' => ['proxy.delete', 'proxy.create'],
            ],
            '/api/groups' => [
                'read' => ['group.view', 'group.manage', 'account.view'],
                'write' => ['group.create', 'group.update', 'group.manage'],
                'delete' => ['group.delete', 'group.manage'],
            ],
            '/api/tasks' => [
                'read' => ['publish.tasks.view', 'publish.mass.view', 'warm.view', 'publish.tasks', 'publish.mass', 'warm.tasks', 'matrix.publish'],
                'write' => ['publish.tasks.create', 'publish.tasks.update', 'publish.mass.create', 'warm.create', 'publish.tasks', 'publish.mass', 'warm.tasks'],
                'delete' => ['publish.tasks.delete', 'publish.mass.delete', 'warm.delete', 'publish.tasks', 'publish.mass', 'warm.tasks'],
            ],
            '/api/materials' => [
                'read' => ['publish.materials.view', 'publish.materials', 'publish.tasks'],
                'write' => ['publish.materials.create', 'publish.materials.update', 'publish.materials'],
                'delete' => ['publish.materials.delete', 'publish.materials'],
            ],
            '/api/chat/' => [
                'read' => ['chat.view', 'chat.manage', 'chat.send', 'publish.mass'],
                'write' => ['chat.update', 'chat.manage', 'publish.mass'],
            ],
            '/api/uids' => [
                'read' => ['chat.view', 'chat.manage', 'publish.mass'],
                'write' => ['chat.update', 'chat.manage', 'publish.mass'],
            ],
            '/api/agents' => [
                'read' => ['agent.view', 'agent.manage'],
                'write' => ['agent.create', 'agent.update', 'agent.manage'],
                'delete' => ['agent.delete', 'agent.manage'],
            ],
            '/api/ai-leads' => [
                'read' => ['ai.leads.view', 'ai.leads', 'account.view'],
                'write' => ['ai.leads.create', 'ai.leads.update', 'ai.leads'],
                'delete' => ['ai.leads.delete', 'ai.leads'],
            ],
            '/api/ai-lead-clues' => [
                'read' => ['ai.leads.view', 'ai.leads', 'account.view'],
                'write' => ['ai.leads.update', 'ai.leads'],
            ],
            '/api/ai-lead-clue-data' => [
                'read' => ['ai.leads.view', 'ai.leads', 'account.view'],
                'write' => ['ai.leads.update', 'ai.leads'],
            ],
            '/api/auto-follow-tasks' => [
                'read' => ['ai.leads.view', 'ai.leads', 'account.view'],
                'write' => ['ai.leads.create', 'ai.leads.update', 'ai.leads'],
                'delete' => ['ai.leads.delete', 'ai.leads'],
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
            '/api/permissions' => ['read' => ['rbac.permissions.view', 'rbac.manage'], 'write' => ['rbac.permissions.create', 'rbac.permissions.update', 'rbac.manage'], 'delete' => ['rbac.permissions.delete', 'rbac.manage']],
            '/api/roles' => ['read' => ['rbac.roles.view', 'rbac.manage'], 'write' => ['rbac.roles.create', 'rbac.roles.update', 'rbac.manage'], 'delete' => ['rbac.roles.delete', 'rbac.manage']],
            '/api/admin-users' => ['read' => ['rbac.users.view', 'rbac.manage'], 'write' => ['rbac.users.create', 'rbac.users.update', 'rbac.manage'], 'delete' => ['rbac.users.delete', 'rbac.manage']],
            '/api/admin-logs' => ['read' => ['rbac.logs.view', 'rbac.log', 'rbac.manage', 'logs.view'], 'write' => ['rbac.logs.clear', 'rbac.manage']],
            '/api/country-data' => [
                'read' => ['rbac.country_data.view', 'rbac.country_data', 'rbac.manage', 'account.view', 'system.view'],
                'write' => ['rbac.country_data.create', 'rbac.country_data.update', 'rbac.country_data', 'rbac.manage'],
                'delete' => ['rbac.country_data.delete', 'rbac.country_data', 'rbac.manage'],
            ],
            '/api/industry-data' => [
                'read' => ['rbac.industry_data.view', 'rbac.industry_data', 'rbac.manage'],
                'write' => ['rbac.industry_data.create', 'rbac.industry_data.update', 'rbac.industry_data', 'rbac.manage'],
                'delete' => ['rbac.industry_data.delete', 'rbac.industry_data', 'rbac.manage'],
            ],
            '/api/companies' => [
                'read' => ['rbac.company_data.view', 'rbac.company_data', 'rbac.industry_data', 'rbac.manage'],
                'write' => ['rbac.company_data.create', 'rbac.company_data.update', 'rbac.company_data', 'rbac.manage'],
                'delete' => ['rbac.company_data.delete', 'rbac.company_data', 'rbac.manage'],
            ],
            '/api/settings' => [
                'read' => ['system.settings.view', 'system.view', 'rbac.manage'],
                'write' => ['system.settings.update', 'system.view', 'rbac.manage'],
            ],
            '/api/smtp-config' => [
                'read' => ['system.smtp', 'admin'],
                'write' => ['system.smtp', 'admin'],
            ],
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
