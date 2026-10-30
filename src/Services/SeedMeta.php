<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 元数据种子：字典 / 视图权限 / 智能体模板（与 Node seed-meta 对齐）
 */
class SeedMeta
{
    public static function ensure(): void
    {
        self::ensureTables();
        self::seedViewPermissions();
        self::seedAppDicts();
        self::seedAgentTemplates();
        self::seedNavigationMenus();
        PermissionCatalog::ensureSplitNodes();
        \App\Services\MenuPermissionService::syncAll();
    }

    private static function ensureTables(): void
    {
        DB::query("CREATE TABLE IF NOT EXISTS `app_dicts` (
          `domain` VARCHAR(191) NOT NULL,
          `code` VARCHAR(191) NOT NULL,
          `label` VARCHAR(512) NOT NULL,
          `sort_order` INT NOT NULL DEFAULT 0,
          `meta_json` TEXT NOT NULL,
          PRIMARY KEY (`domain`, `code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::query("CREATE TABLE IF NOT EXISTS `view_permissions` (
          `view` VARCHAR(191) PRIMARY KEY,
          `codes_json` TEXT NOT NULL,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::query("CREATE TABLE IF NOT EXISTS `agent_templates` (
          `key` VARCHAR(191) PRIMARY KEY,
          `name` VARCHAR(191) NOT NULL,
          `description` VARCHAR(512) NOT NULL DEFAULT '',
          `payload_json` MEDIUMTEXT NOT NULL,
          `sort_order` INT NOT NULL DEFAULT 0,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function upsertDict(string $domain, string $code, string $label, int $sort, string $meta = '{}'): void
    {
        DB::query(
            "INSERT INTO app_dicts(domain, code, label, sort_order, meta_json) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE label=VALUES(label), sort_order=VALUES(sort_order), meta_json=VALUES(meta_json)",
            [$domain, $code, $label, $sort, $meta]
        );
    }

    private static function seedViewPermissions(): void
    {
        $rows = [
            ['accounts', ['account.view', 'account.create', 'account.update', 'account.import', 'account.delete', 'account.export', 'group.view', 'group.manage']],
            ['proxies', ['proxy.view', 'proxy.create', 'proxy.update', 'proxy.delete', 'proxy.import', 'proxy.test']],
            ['agent', ['agent.view', 'agent.manage', 'agent.create', 'agent.update', 'agent.delete']],
            ['publish', ['publish.tasks', 'publish.tasks.view', 'publish.mass', 'publish.mass.view', 'matrix.publish', 'matrix.mass']],
            ['chat', ['chat.view', 'chat.manage', 'chat.send', 'chat.update', 'publish.mass']],
            ['uids', ['chat.view', 'chat.manage', 'publish.mass']],
            ['logs', ['logs.view', 'account.view', 'rbac.manage', 'rbac.log', 'rbac.logs.view']],
            ['rbac', [
                'rbac.manage', 'rbac.log', 'rbac.logs.view',
                'rbac.users.view', 'rbac.roles.view', 'rbac.permissions.view', 'rbac.menus.view',
                'rbac.country_data', 'rbac.country_data.view',
                'rbac.industry_data', 'rbac.industry_data.view',
                'rbac.company_data', 'rbac.company_data.view',
            ]],
            ['settings', ['system.view', 'system.settings.view', 'rbac.manage']],
            ['system', ['system.view', 'system.settings.view', 'rbac.manage']],
            ['versions', ['system.versions', 'system.view', 'rbac.manage']],
            ['helpDocs', ['system.help', 'system.view', 'rbac.manage']],
            ['tickets', ['system.tickets', 'system.view', 'rbac.manage']],
            ['envInstallers', ['system.env_installers', 'system.view', 'rbac.manage']],
            ['aiPlatform', ['system.ai_platform', 'system.view', 'rbac.manage']],
            ['aiPromptTemplates', ['system.ai_prompt', 'system.ai_platform', 'system.view', 'rbac.manage']],
            ['aiCallLogs', ['system.ai_log', 'system.ai_platform', 'system.view', 'rbac.manage']],
            ['smtpConfig', ['system.smtp', 'admin']],
            ['dmWeightConfig', ['system.dm_weight', 'admin']],
            ['dmWeightConfig', ['system.dm_weight', 'admin']],
            ['tasks', [
                'publish.tasks', 'publish.tasks.view', 'publish.mass', 'publish.mass.view',
                'warm.tasks', 'warm.view', 'publish.materials', 'publish.materials.view',
                'matrix.publish', 'matrix.mass',
            ]],
            ['materials', ['publish.materials', 'publish.materials.view', 'publish.tasks', 'publish.tasks.view', 'matrix.publish']],
            ['groups', ['group.view', 'group.manage', 'group.create', 'group.update', 'group.delete', 'account.view']],
            ['warm', ['warm.view', 'warm.tasks', 'warm.create', 'account.create', 'publish.tasks', 'matrix.publish', 'account.view']],
            ['msgTemplates', ['account.msg_template.comment', 'account.msg_template.dm', 'account.msg_template', 'account.create', 'publish.tasks', 'account.view', 'chat.manage']],
            ['accountWork', ['account.work.pause', 'account.work.delete', 'account.work.reset', 'account.work', 'account.view', 'account.create', 'publish.tasks']],
            ['accountWizard', ['account.view', 'account.create', 'account.work', 'account.work.pause', 'publish.tasks', 'warm.view', 'warm.tasks']],
            ['aiLeads', ['ai.leads', 'ai.leads.view', 'account.create', 'publish.tasks', 'account.view']],
            ['aiLeadClues', ['ai.leads', 'ai.leads.view', 'account.view']],
            ['aiLeadClueData', ['ai.leads', 'ai.leads.view', 'account.view']],
            ['autoFollow', ['ai.leads', 'ai.leads.view', 'account.create', 'publish.tasks', 'account.view']],
        ];
        foreach ($rows as [$view, $codes]) {
            DB::query(
                "INSERT INTO view_permissions(`view`, codes_json) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE codes_json=VALUES(codes_json), updated_at=CURRENT_TIMESTAMP",
                [$view, json_encode($codes, JSON_UNESCAPED_UNICODE)]
            );
        }
    }

    private static function seedAppDicts(): void
    {
        $count = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM app_dicts')['c'] ?? 0);
        if ($count > 0) {
            // 仍补齐后期新增域
        }

        $pairs = function (string $domain, array $items, ?callable $metaFn = null) {
            foreach ($items as $i => $item) {
                $code = $item[0];
                $label = $item[1];
                $meta = $metaFn ? $metaFn($item, $i) : '{}';
                self::upsertDict($domain, $code, $label, $i, $meta);
            }
        };

        $pairs('account_login', [
            ['online', '在线'], ['offline', '离线'], ['expired', '已过期'],
            ['checking', '检查中'], ['connecting', '连接中'],
        ], fn($item) => in_array($item[0], ['online', 'offline', 'expired'], true)
            ? '{"editable":true}' : '{"systemOnly":true}');

        $pairs('proxy_status', [['available', '可用'], ['unavailable', '不可用'], ['unchecked', '未检测']]);
        $pairs('task_status', [
            ['draft', '草稿'], ['queued', '排队中'], ['running', '运行中'], ['paused', '已暂停'],
            ['completed', '已完成'], ['failed', '失败'], ['cancelled', '已取消'],
        ]);
        $pairs('badge_extra', [
            ['success', '成功'], ['skipped', '跳过'], ['pending', '待发布'],
            ['publishing', '发布中'], ['error', '异常'],
        ]);
        $pairs('task_type', [
            ['publish', '视频发布'], ['message', '消息群发'], ['sync', '数据同步'], ['profile', '资料同步'],
            ['warm', '自动养号'], ['sync_fans', '同步粉丝列表'], ['dm_sync', 'API 私信同步'],
        ]);
        $pairs('proxy_protocol', [['http', 'HTTP'], ['https', 'HTTPS'], ['socks5', 'SOCKS5']]);
        $pairs('group_type', [
            ['account', '账号分组'], ['proxy', '代理分组'], ['message', '消息分组'], ['uid', 'UID 分组'],
        ]);
        $pairs('chat_filter', [
            ['all', '全部好友'], ['unread', '未读消息'], ['unreplied', '我未回复'], ['friendUnreplied', '好友未回复'],
            ['new', '新好友'], ['stranger', '陌生人'], ['follower', '粉丝'], ['mutual', '互关'],
            ['blocked', '已拉黑'], ['pinned', '已置顶'],
        ]);
        $pairs('lang', [
            ['zh', '中文'], ['en', '英语'], ['ja', '日语'], ['ko', '韩语'],
            ['es', '西班牙语'], ['fr', '法语'], ['de', '德语'], ['pt', '葡萄牙语'],
            ['id', '印尼语'], ['th', '泰语'], ['vi', '越南语'], ['ar', '阿拉伯语'], ['ru', '俄语'],
            ['auto', '自动检测'],
        ]);
        $pairs('ui_lang', [['zh-CN', '简体中文']]);
        $pairs('reply_tone', [
            ['friendly', '亲切友好'], ['professional', '专业稳重'], ['humor', '幽默风趣'],
            ['casual', '口语轻松'], ['concise', '简洁克制'],
        ]);
        $pairs('perm_module', [
            ['account', '账号中心'],
            ['proxy', '代理IP'],
            ['publish', '矩阵发布'],
            ['chat', '客服聚合'],
            ['agent', 'AI智能体'],
            ['ai', 'AI营销'],
            ['group', '业务分组'],
            ['logs', '操作日志'],
            ['rbac', '权限管理'],
            ['system', '系统管理'],
            ['menu', '菜单可见性'],
        ]);
        $pairs('perm_subgroup', [
            ['account.view', '账号·列表'],
            ['account.create', '账号·添加'],
            ['account.update', '账号·编辑'],
            ['account.import', '账号·导入'],
            ['account.delete', '账号·删除'],
            ['account.export', '账号·导出'],
            ['account.work', '矩阵任务'],
            ['account.msg_template', '消息模板'],
            ['proxy.view', '代理·列表'],
            ['proxy.create', '代理·添加'],
            ['proxy.update', '代理·编辑'],
            ['proxy.delete', '代理·删除'],
            ['proxy.import', '代理·导入'],
            ['proxy.test', '代理·检测'],
            ['group.view', '分组·列表'],
            ['group.create', '分组·添加'],
            ['group.update', '分组·编辑'],
            ['group.delete', '分组·删除'],
            ['group.assign', '分组·分配用户'],
            ['group.manage', '分组·总控(兼容)'],
            ['publish.tasks', '发布任务'],
            ['publish.mass', '群发任务'],
            ['publish.materials', '素材管理'],
            ['warm.tasks', '养号任务'],
            ['matrix.publish', '矩阵发布(旧)'],
            ['matrix.mass', '矩阵群发(旧)'],
            ['chat.manage', '客服管理'],
            ['chat.send', '客服发送'],
            ['agent.view', '智能体查看'],
            ['agent.manage', '智能体管理'],
            ['ai.leads', 'AI拓客'],
            ['logs.view', '实时日志'],
            ['rbac.users', '管理用户'],
            ['rbac.roles', '用户角色'],
            ['rbac.permissions', '权限节点'],
            ['rbac.logs', '操作日志'],
            ['rbac.menus', '后台菜单'],
            ['rbac.manage', '角色权限(总控)'],
            ['rbac.log', '审计日志(兼容)'],
            ['rbac.country_data', '国家数据'],
            ['rbac.industry_data', '行业数据'],
            ['rbac.company_data', '企业数据'],
            ['system.view', '系统设置'],
            ['system.versions', '版本管理'],
            ['system.help', '帮助文档'],
            ['system.tickets', '工单处理'],
            ['system.env_installers', '环境安装包'],
            ['system.ai_platform', 'AI开放平台'],
            ['system.ai_prompt', 'AI提示词'],
            ['system.ai_log', 'AI调用记录'],
            ['system.smtp', 'SMTP配置(超管)'],
            ['system.dm_weight', '日私信权重(超管)'],
            ['menu.visible', '菜单节点'],
        ]);
        $pairs('material_status', [['ready', '可用'], ['disabled', '停用'], ['missing', '文件缺失']]);
        $pairs('model_type', [['builtin', '内置模型'], ['custom', '自定义 API']]);
        $pairs('translation_mode', [['off', '关闭'], ['basic', '普通接口翻译'], ['ai', '智能翻译']]);
        $pairs('notification_sound', [['chime', '清脆'], ['soft', '柔和'], ['alert', '提醒'], ['none', '关闭']]);
        $pairs('browser_window_mode', [
            ['visible', '显示窗口（调试）'], ['hidden', '隐藏窗口（正式运行）'], ['auto', '按分项开关'],
        ]);
        $pairs('browser_type', [
            ['bit', '比特浏览器'],
            ['tkswarm', '第一页自研浏览器'],
            ['linux', '第一页自研浏览器 Linux'],
            ['adspower', 'AdsPower'], ['vmlogin', 'VMLogin'], ['playwright', 'Playwright'],
        ], fn($item) => in_array($item[0], ['bit', 'tkswarm', 'linux'], true) ? '{"ready":true}' : '{"ready":false}');
        // 仅保留比特浏览器：API/WS 无法真实发送 TikTok 私信，仅写本地会话，已下线
        $pairs('mass_channel', [
            ['bit', '比特浏览器'],
        ], function ($item) {
            $hints = [
                'bit' => ['hint' => '通过比特浏览器打开环境，尝试真实发送 TikTok 私信', 'confirm' => '比特浏览器（真实发私信）'],
            ];
            return json_encode($hints[$item[0]] ?? new \stdClass(), JSON_UNESCAPED_UNICODE);
        });
        // 清理历史无效通道
        try {
            DB::query("DELETE FROM app_dicts WHERE domain='mass_channel' AND code IN ('api','ws')");
        } catch (\Throwable $e) {
            // ignore if table/name differs
        }
        $pairs('mass_target', [
            ['imported', '导入好友'], ['new', '新好友'], ['unreplied', '未回复好友'], ['fixed', '固定对象'],
            ['followers', '粉丝/互关'],
        ]);
        $pairs('message_sync_mode', [
            ['api', 'API模式'], ['browser', '浏览器模式'],
        ]);
        $pairs('dm_channel', [
            ['auto', '自动'], ['api', 'API'], ['browser', '浏览器'],
        ]);
        $pairs('chat_emoji', [
            ['😀', '笑脸'], ['😂', '大笑'], ['❤️', '爱心'], ['👍', '点赞'], ['🙏', '感谢'], ['🔥', '火'],
            ['🎉', '庆祝'], ['😭', '哭'], ['😍', '喜欢'], ['🤝', '握手'], ['✨', '闪亮'], ['😎', '酷'],
        ]);
        $pairs('proxy_strategy', [
            ['sequential', '按代理顺序绑定（推荐）'], ['single', '全部绑定同一代理'], ['none', '不绑定代理'],
        ]);
        $pairs('export_scope', [['selected', '勾选账号'], ['group', '当前分组'], ['all', '全部账号']]);
        $pairs('export_format', [['txt', 'TXT'], ['csv', 'CSV'], ['json', 'JSON']]);
        $pairs('matrix_tab', [
            ['tasks', '视频任务'], ['materials', '素材库'],
        ]);
        try {
            DB::query("DELETE FROM app_dicts WHERE domain='matrix_tab' AND code IN ('mass','uids')");
        } catch (\Throwable $e) {
            // ignore
        }
        $pairs('settings_section', [
            ['env', '接口与开发环境'], ['browser', '浏览器设置'], ['message', '消息设置'],
            ['task', '任务与无头'], ['clock', '多国时钟'], ['backup', '备份与鉴权'], ['password', '修改账号密码'],
        ]);
        $pairs('agent_tab', [
            ['basic', '基础信息'], ['model', '模型配置'], ['prompt', '对话能力'],
            ['terminate', '终止条件'], ['poll', '号码轮询'],
        ]);
        $pairs('log_level', [['info', '信息 (INFO)'], ['warn', '警告 (WARN)'], ['error', '异常 (ERROR)']]);
        $pairs('role_status', [['1', '正常启用'], ['0', '停用锁定']]);
        $pairs('user_status', [['1', '正常启用'], ['0', '禁用锁定']]);
        $pairs('menu_visible', [['1', '显示'], ['0', '隐藏']]);
        $pairs('template_enabled', [['true', '启用'], ['false', '停用']]);
        $pairs('keyword_match', [['contains', '包含'], ['exact', '精确']]);
        $pairs('poll_resource', [['phone', '手机号'], ['link', '链接'], ['text', '文本']]);
        $pairs('material_source', [['files', '直接选择视频文件'], ['library', '素材库选择']]);
        $pairs('material_copy', [['false', '引用原路径（不复制）'], ['true', '复制到本地素材库']]);
        $pairs('publish_strategy', [
            ['deduplicate', '去重分配（素材不足则跳过）'], ['random', '随机分配'],
        ]);
        $pairs('distribution_strategy', [['sequential', '顺序轮询'], ['random', '随机']]);
        $pairs('profile_mode', [
            ['platform', '比特环境改 TikTok 资料'], ['local', '仅更新本地库字段'],
        ]);
        $pairs('video_delete_mode', [
            ['platform', '比特环境删除 TikTok 视频'], ['local', '仅清理本地同步记录'],
        ]);
        $pairs('chat_connect_scope', [['selected', '勾选账号'], ['online', '全部已登录 TikTok']]);

        $pages = [
            ['accounts', '账号管理', '维护 TikTok 账号、分组与浏览器环境'],
            ['proxies', '代理IP', '管理代理池并检测连通性'],
            ['agent', 'AI智能体', '选择开放平台与模型，配置语言风格'],
            ['publish', 'AI矩阵', '创建并执行视频发布任务'],
            ['chat', '客服聊天', '多账号聚合私信'],
            ['uids', 'UID好友', '客服群发目标 UID 池，按分组导入与维护'],
            ['logs', '实时操作日志', 'WebSocket 实时链路与系统级动作事件流'],
            ['rbac', '权限管理', '权限节点、角色、管理员与审计菜单'],
            ['settings', '基础设置', '配置浏览器、任务、发布确认与翻译'],
            ['groups', '分组管理', '维护账号分组与公司子组的增删改查'],
            ['warm', '自动养号', '按任务创建养号配置，批量执行刷互动养号'],
            ['msgTemplates', '消息模板', '评论 / 回复评论 / 私信 / 回复私信：固定文案与 AI 提示词'],
            ['accountWork', '矩阵任务', '按账号查看会话总任务与子任务，支持展开与暂停'],
            ['accountWizard', '账号向导', '养号、拉取账号数据与修改资料任务'],
            ['aiLeads', '拓客任务', '配置拓客规则、关联账号与私信明细'],
            ['aiLeadClues', '拓客线索', '查看拓客跑出的意向用户与评论，支持换号与导出'],
            ['aiLeadClueData', '拓客结果', '查看共享给当前账号的评论线索，含行业筛选'],
            ['autoFollow', '增粉任务', '选择被增粉账号与数量，按间隔调度系统账号关注'],
            ['rbac.permissions', '权限节点管理', '维护系统细粒度 API 路由鉴权与动作拦截节点'],
            ['rbac.roles', '用户角色管理', '配置管理角色并分配操作与功能节点权限'],
            ['rbac.users', '系统管理员用户', '维护平台管理员登录账户、密码与角色分配'],
            ['rbac.logs', '操作审计日志', '全量管理员关键资源操作与客户端行为历史追踪'],
            ['rbac.menus', '后台菜单架构', '配置系统顶部导航栏菜单树层级、视图路由与显示顺序'],
            ['rbac.country_data', '国家数据', '维护 ISO 国家中英文名称，供账号与业务配置引用'],
            ['rbac.industry_data', '行业数据', '维护外贸行业四级分类树，供拓客与业务配置引用'],
            ['rbac.company_data', '企业数据', '管理企业信息，与行业分类及管理用户绑定'],
            ['versions', '版本控制', '上传与管理客服客户端代码包版本，供 EXE 下载安装'],
            ['envInstallers', '环境安装包', '管理 Node / 比特等本机环境安装包'],
            ['helpDocs', '帮助文档', '维护客户端帮助分类与文档内容'],
            ['tickets', '工单处理', '查看并回复客服客户端提交的工单反馈'],
            ['system', '系统管理', '基础设置、开发管理、帮助与工单'],
        ];
        foreach ($pages as $i => [$code, $label, $desc]) {
            self::upsertDict('view_page', $code, $label, $i, json_encode(['description' => $desc], JSON_UNESCAPED_UNICODE));
        }
    }

    private static function seedAgentTemplates(): void
    {
        $count = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM agent_templates')['c'] ?? 0);
        if ($count > 0) {
            return;
        }
        $rows = [
            ['ecommerce', '电商客服机器人', '专业的电商客服机器人，负责解答客户商品咨询、引导下单、处理简单售后问题', [
                'roleInfo' => '你是一名专业的电商客服人员',
                'mission' => '解答咨询、引导下单、处理简单售后',
                'rules' => '礼貌友好；保护隐私',
                'openings' => ['亲，您好，欢迎光临小店，请问有什么可以帮助您的？'],
                'replyTone' => 'friendly',
                'replyLanguage' => 'zh',
            ], 1],
            ['trade', '外贸客服机器人', '专业的外贸客服机器人', [
                'roleInfo' => 'You are a professional foreign trade CS',
                'mission' => 'Answer product questions and shipping',
                'rules' => 'Stay professional',
                'openings' => ['Hello! How can I help you today?'],
                'replyTone' => 'professional',
                'replyLanguage' => 'en',
            ], 2],
            ['tech', '技术支持机器人', '专业的技术支持机器人', [
                'roleInfo' => '你是一名专业的技术支持工程师',
                'mission' => '解答技术问题、排查故障',
                'rules' => '通俗易懂；及时转接复杂问题',
                'openings' => ['欢迎咨询技术支持，请告诉我您遇到的问题'],
                'replyTone' => 'professional',
                'replyLanguage' => 'zh',
            ], 3],
        ];
        foreach ($rows as [$key, $name, $desc, $payload, $sort]) {
            DB::query(
                'INSERT INTO agent_templates(`key`, name, description, payload_json, sort_order) VALUES (?,?,?,?,?)',
                [$key, $name, $desc, json_encode($payload, JSON_UNESCAPED_UNICODE), $sort]
            );
        }
    }

    /** 增量补齐导航菜单（已存在 id 不覆盖） */
    private static function seedNavigationMenus(): void
    {
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `navigation_menus` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `parent_id` INT NULL,
              `title` VARCHAR(100) NOT NULL,
              `icon` VARCHAR(50) NOT NULL DEFAULT '',
              `path` VARCHAR(255) NOT NULL DEFAULT '',
              `view` VARCHAR(50) NOT NULL DEFAULT '',
              `subview` VARCHAR(50) NOT NULL DEFAULT '',
              `sort_order` INT NOT NULL DEFAULT 0,
              `visible` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
        }

        // 账号中心下：分组管理（与账号池管理同级）
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES (114, 100, '分组管理', 'folder', '', 'groups', 'account', 2, 1)"
        );
        // AI 矩阵下：自动养号、矩阵任务
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES (115, 400, '自动养号', 'activity', '', 'warm', 'list', 5, 1)"
        );
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES (116, 400, '矩阵任务', 'list', '', 'accountWork', 'list', 6, 1)"
        );
        // 一级菜单：AI智能体
        try {
            DB::query(
                "UPDATE navigation_menus SET title='AI智能体'
                 WHERE id=300 OR title IN ('AI 机器人','AI机器人','AI 智能体')
                    OR (parent_id IS NULL AND view='agent')"
            );
        } catch (\Throwable $e) {
        }
        // 消息模板：与「新建智能体」(312) 同级，挂在「智能体管理」(310) 下
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES (117, 310, '消息模板', 'message', '', 'msgTemplates', 'list', 3, 1)"
        );
        try {
            DB::query(
                "UPDATE navigation_menus
                 SET parent_id=310, title='消息模板', icon='message', path='', view='msgTemplates',
                     subview='list', sort_order=3, visible=1
                 WHERE id=117 OR view='msgTemplates' OR title='消息模板'"
            );
        } catch (\Throwable $e) {
        }
        try {
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (76, 'account', 'account.msg_template.comment', '管理评论模板', 76)"
            );
        } catch (\Throwable $e) {
        }
        // 视频素材目录（与「视频发布向导」同级）
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES (420, 400, '视频素材', 'folder', '', 'publish', 'materials', 2, 1)"
        );
        // 视频删除向导 / 视频评论（与发布向导、素材同级）
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES
              (430, 400, '视频删除向导', 'trash-2', '', 'publish', 'delete_wizard', 3, 1),
              (431, 430, '创建删除任务', 'trash-2', '', 'publish', 'create_delete', 1, 1),
              (440, 400, '视频评论', 'message-circle', '', 'publish', 'comment_reply_wizard', 4, 1),
              (441, 440, '回复视频评论', 'message-circle', '', 'publish', 'create_comment_reply', 1, 1),
              (444, 440, '拉取视频列表', 'list', '', 'publish', 'pull_video_list', 2, 1),
              (445, 440, '创建拉取视频列表', 'list', '', 'publish', 'create_pull_video_list', 3, 1),
              (442, 440, '拉取视频数据', 'download', '', 'publish', 'pull_comments', 4, 1),
              (443, 440, '创建拉取视频数据', 'download', '', 'publish', 'create_pull_comments', 5, 1)"
        );
        try {
            DB::query("UPDATE navigation_menus SET parent_id=400, title='视频评论', icon='message-circle', view='publish', subview='comment_reply_wizard', sort_order=4, visible=1 WHERE id=440 OR (view='publish' AND subview='comment_reply_wizard') OR (title='回复评论' AND parent_id=400)");
            DB::query("UPDATE navigation_menus SET parent_id=440, title='回复视频评论', icon='message-circle', view='publish', subview='create_comment_reply', sort_order=1, visible=1 WHERE id=441 OR (view='publish' AND subview='create_comment_reply')");
            DB::query(
                "INSERT IGNORE INTO navigation_menus
                  (id, parent_id, title, icon, path, view, subview, sort_order, visible, permission_code)
                 VALUES
                  (444, 440, '拉取视频列表', 'list', '', 'publish', 'pull_video_list', 2, 1, 'public'),
                  (445, 440, '创建拉取视频列表', 'list', '', 'publish', 'create_pull_video_list', 3, 1, 'public')"
            );
            DB::query("UPDATE navigation_menus SET parent_id=440, title='拉取视频列表', icon='list', view='publish', subview='pull_video_list', sort_order=2, visible=1, permission_code='public' WHERE id=444 OR (view='publish' AND subview='pull_video_list')");
            DB::query("UPDATE navigation_menus SET parent_id=440, title='创建拉取视频列表', icon='list', view='publish', subview='create_pull_video_list', sort_order=3, visible=1, permission_code='public' WHERE id=445 OR (view='publish' AND subview='create_pull_video_list')");
            DB::query("UPDATE navigation_menus SET parent_id=440, title='拉取视频数据', icon='download', view='publish', subview='pull_comments', sort_order=4, visible=1 WHERE id=442 OR (view='publish' AND subview='pull_comments')");
            DB::query("UPDATE navigation_menus SET parent_id=440, title='创建拉取视频数据', icon='download', view='publish', subview='create_pull_comments', sort_order=5, visible=1 WHERE id=443 OR (view='publish' AND subview='create_pull_comments')");
        } catch (\Throwable $e) {
        }
        // AI营销 → AI拓客（二级）→ 拓客任务 / 拓客线索 / 拓客结果；增粉仍挂 AI营销
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES
              (350, NULL, 'AI营销', 'sparkles', '', 'aiLeads', 'list', 4, 1),
              (356, 350, 'AI拓客', 'sparkles', '', 'aiLeads', 'list', 1, 1),
              (351, 356, '拓客任务', 'user-plus', '', 'aiLeads', 'list', 1, 1),
              (352, 356, '拓客线索', 'users', '', 'aiLeads', 'clues', 2, 1),
              (353, 356, '拓客结果', 'database', '', 'aiLeads', 'clue_data', 3, 1),
              (357, 350, 'AI增粉', 'user-plus', '', 'autoFollow', 'list', 2, 1),
              (354, 357, '增粉任务', 'user-plus', '', 'autoFollow', 'list', 1, 1),
              (355, 357, '创建任务', 'plus', '', 'autoFollow', 'create', 2, 1)"
        );
        try {
            DB::query("UPDATE navigation_menus SET parent_id=NULL, title='AI营销', icon='sparkles', path='', view='aiLeads', subview='list', sort_order=4, visible=1 WHERE id=350");
            DB::query("UPDATE navigation_menus SET parent_id=350, title='AI拓客', icon='sparkles', path='', view='aiLeads', subview='list', sort_order=1, visible=1 WHERE id=356");
            DB::query("UPDATE navigation_menus SET parent_id=356, title='拓客任务', icon='user-plus', path='', view='aiLeads', subview='list', sort_order=1, visible=1 WHERE id=351 OR (view='aiLeads' AND subview='list' AND title IN ('AI拓客','拓客任务') AND id<>356)");
            DB::query("UPDATE navigation_menus SET parent_id=356, title='拓客线索', icon='users', path='', view='aiLeads', subview='clues', sort_order=2, visible=1 WHERE id=352 OR (view='aiLeads' AND subview='clues')");
            DB::query("UPDATE navigation_menus SET parent_id=356, title='拓客结果', icon='database', path='', view='aiLeads', subview='clue_data', sort_order=3, visible=1 WHERE id=353 OR (view='aiLeads' AND subview='clue_data')");
            DB::query("UPDATE navigation_menus SET parent_id=350, title='AI增粉', icon='user-plus', path='', view='autoFollow', subview='list', sort_order=2, visible=1 WHERE id=357");
            DB::query("UPDATE navigation_menus SET parent_id=357, title='增粉任务', icon='user-plus', path='', view='autoFollow', subview='list', sort_order=1, visible=1 WHERE id=354 OR (view='autoFollow' AND subview='list' AND title IN ('自动增粉任务','增粉任务') AND id<>357)");
            DB::query("UPDATE navigation_menus SET parent_id=357, title='创建任务', icon='plus', path='', view='autoFollow', subview='create', sort_order=2, visible=1 WHERE id=355 OR (view='autoFollow' AND subview='create')");
            DB::query("UPDATE navigation_menus SET sort_order=5 WHERE id=400");
            DB::query("UPDATE navigation_menus SET sort_order=6 WHERE id=500");
            DB::query("UPDATE navigation_menus SET sort_order=7 WHERE id=600");
            DB::query("UPDATE navigation_menus SET sort_order=8 WHERE id=800");
        } catch (\Throwable $e) {
        }
        try {
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (77, 'ai', 'ai.leads', '管理AI拓客', 77)"
            );
        } catch (\Throwable $e) {
        }
        // 素材导入挂到「视频素材」下
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES (413, 420, '素材导入', 'upload', '', 'publish', 'material_import', 2, 1)"
        );
        // 修复：此前误把根菜单 400（AI 矩阵）/410（视频发布向导）写成了「视频发布任务」
        try {
            DB::query("UPDATE navigation_menus SET parent_id=NULL, title='AI 矩阵', icon='video', path='', view='publish', subview='tasks', sort_order=4, visible=1 WHERE id=400");
            DB::query("UPDATE navigation_menus SET parent_id=400, title='视频发布向导', icon='film', path='', view='publish', subview='tasks', sort_order=1, visible=1 WHERE id=410");
            DB::query("UPDATE navigation_menus SET parent_id=410, title='视频发布任务', icon='list', path='', view='publish', subview='tasks', sort_order=1, visible=1 WHERE id=411");
            DB::query("UPDATE navigation_menus SET parent_id=410, title='随机发布', icon='send', path='', view='publish', subview='create_publish', sort_order=2, visible=1 WHERE id=412");
            DB::query("UPDATE navigation_menus SET parent_id=210, title='导入导出', icon='upload', path='', view='proxies', subview='io', sort_order=2, visible=1 WHERE id=212");
            DB::query("UPDATE navigation_menus SET title='导入导出' WHERE title='代理导入导出' OR (id=212)");
            // 代理IP池挂到账号中心，隐藏一级「代理网络」
            DB::query("UPDATE navigation_menus SET parent_id=100, title='代理IP池', icon='server', path='', view='proxies', subview='', sort_order=2, visible=1 WHERE id=210 OR (title='代理IP池' AND view='proxies')");
            DB::query("UPDATE navigation_menus SET parent_id=210, sort_order=1, visible=1 WHERE id=211 OR (view='proxies' AND subview='list' AND title='代理列表')");
            DB::query("UPDATE navigation_menus SET parent_id=210, sort_order=2, visible=1 WHERE id=212 OR (view='proxies' AND subview='io')");
            DB::query("UPDATE navigation_menus SET parent_id=210, sort_order=3, visible=1 WHERE id=213 OR (view='proxies' AND subview='create')");
            DB::query("UPDATE navigation_menus SET sort_order=3 WHERE id=120 OR (parent_id=100 AND view='accounts' AND subview='browser' AND title='环境与指纹')");
            DB::query("UPDATE navigation_menus SET sort_order=4 WHERE id=130 OR (parent_id=100 AND view='logs' AND title='操作与审计')");
            DB::query("UPDATE navigation_menus SET visible=0 WHERE id=200 OR (parent_id IS NULL AND title='代理网络')");
            DB::query("UPDATE navigation_menus SET parent_id=100, title='分组管理', icon='folder', path='', view='groups', subview='account', sort_order=2, visible=1 WHERE id=114 OR (view='groups' AND parent_id=110)");
            DB::query("UPDATE navigation_menus SET parent_id=110, title='账号列表', icon='list', path='', view='accounts', subview='list', sort_order=1, visible=1 WHERE id=111");
            DB::query("UPDATE navigation_menus SET parent_id=110, title='导入导出', icon='upload', path='', view='accounts', subview='io', sort_order=3, visible=1 WHERE id=112");
            DB::query("UPDATE navigation_menus SET title='导入导出' WHERE title='账号导入导出' OR (view='accounts' AND subview='io')");
            DB::query("UPDATE navigation_menus SET parent_id=400, title='自动养号', icon='activity', path='', view='warm', subview='list', sort_order=5, visible=1 WHERE id=115");
            DB::query("UPDATE navigation_menus SET visible=1, parent_id=400, sort_order=5 WHERE view='warm' AND title='自动养号'");
            DB::query("UPDATE navigation_menus SET parent_id=310, title='消息模板', icon='message', path='', view='msgTemplates', subview='list', sort_order=3, visible=1 WHERE id=117");
            DB::query("UPDATE navigation_menus SET visible=1, parent_id=310, sort_order=3 WHERE view='msgTemplates'");
            DB::query("UPDATE navigation_menus SET parent_id=400, title='矩阵任务', icon='list', path='', view='accountWork', subview='list', sort_order=6, visible=1 WHERE id=116");
            DB::query("UPDATE navigation_menus SET visible=1, parent_id=400, sort_order=6 WHERE view='accountWork' AND title='矩阵任务'");
            DB::query("UPDATE navigation_menus SET visible=0 WHERE id=113");
            DB::query("DELETE FROM navigation_menus WHERE id=113 OR (view='accounts' AND subview='qr') OR title='扫码登录'");
        } catch (\Throwable $e) {
        }
        // 视频发布向导子项：任务 / 随机 / 群发 / 独立
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES
              (414, 410, '创建群发', 'film', '', 'publish', 'create_ordered', 3, 1),
              (415, 410, '独立发布', 'play', '', 'publish', 'create_independent', 4, 1),
              (416, 420, '素材库', 'folder', '', 'publish', 'materials', 1, 1)"
        );
        // UID好友：挂到客服聚合 → 与「进入多账号客服台」同级
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES (417, 510, 'UID好友', 'users', '', 'chat', 'uids', 2, 1)"
        );
        try {
            DB::query("UPDATE navigation_menus SET parent_id=400, title='视频素材', icon='folder', view='publish', subview='materials', sort_order=2, visible=1 WHERE id=420");
            DB::query("UPDATE navigation_menus SET parent_id=410, title='创建群发', icon='film', view='publish', subview='create_ordered', sort_order=3, visible=1 WHERE id=414");
            DB::query("UPDATE navigation_menus SET parent_id=410, title='独立发布', icon='play', view='publish', subview='create_independent', sort_order=4, visible=1 WHERE id=415");
            DB::query("UPDATE navigation_menus SET parent_id=420, title='素材库', icon='folder', view='publish', subview='materials', sort_order=1, visible=1 WHERE id=416");
            DB::query("UPDATE navigation_menus SET parent_id=420, title='素材导入', icon='upload', view='publish', subview='material_import', sort_order=2, visible=1 WHERE id=413");
            DB::query("UPDATE navigation_menus SET parent_id=510, title='UID好友', icon='users', view='chat', subview='uids', sort_order=2, visible=1 WHERE id=417");
            DB::query("UPDATE navigation_menus SET parent_id=510, view='chat', subview='uids', title='UID好友', sort_order=2, visible=1 WHERE title='UID好友' OR (view='publish' AND subview='uids')");
        } catch (\Throwable $e) {
        }
        // AI矩阵：一级 → 视频向导（二级）→ 发布/删除/回复/拉列表/拉详情（三级），映射现有 publish 页
        try {
            DB::query("UPDATE navigation_menus SET parent_id=NULL, title='AI矩阵', icon='video', path='', view='publish', subview='tasks', sort_order=5, visible=1 WHERE id=400");
            DB::query("UPDATE navigation_menus SET parent_id=400, title='视频向导', icon='film', path='', view='publish', subview='tasks', sort_order=1, visible=1 WHERE id=410");
            // 三级叶子：对应实际页面 / 向导
            DB::query("UPDATE navigation_menus SET parent_id=410, title='发布视频', icon='send', path='', view='publish', subview='tasks', sort_order=1, visible=1 WHERE id=411");
            DB::query("UPDATE navigation_menus SET parent_id=410, title='删除视频', icon='trash-2', path='', view='publish', subview='create_delete', sort_order=2, visible=1 WHERE id=430");
            DB::query("UPDATE navigation_menus SET parent_id=410, title='回复评论', icon='message-circle', path='', view='publish', subview='create_comment_reply', sort_order=3, visible=1 WHERE id=441");
            DB::query("UPDATE navigation_menus SET parent_id=410, title='拉取视频列表', icon='list', path='', view='publish', subview='pull_video_list', sort_order=4, visible=1, permission_code='public' WHERE id=444");
            DB::query("UPDATE navigation_menus SET parent_id=410, title='拉取视频详情', icon='download', path='', view='publish', subview='pull_comments', sort_order=5, visible=1 WHERE id=442");
            // 旧二级/重复入口隐藏（功能仍可从页面按钮进入）
            DB::query("UPDATE navigation_menus SET visible=0 WHERE id IN (412,414,415,431,440,443,445)");
            DB::query("UPDATE navigation_menus SET visible=0 WHERE view='publish' AND subview IN ('create_publish','create_ordered','create_independent','create_pull_video_list','create_pull_comments','delete_wizard','comment_reply_wizard') AND id NOT IN (411,430,441,442,444)");
            // 素材 / 账号向导 / 矩阵任务挂在 AI矩阵 下
            DB::query("UPDATE navigation_menus SET parent_id=400, title='视频素材', icon='folder', view='publish', subview='materials', sort_order=2, visible=1 WHERE id=420");
            try {
                DB::query("ALTER TABLE navigation_menus ADD COLUMN permission_code VARCHAR(64) NULL DEFAULT NULL AFTER visible");
            } catch (\Throwable $e) {
            }
            // 父级默认进「拉取数据」；叶子各自用独立 subview，避免与「修改资料」抢路由
            DB::query(
                "INSERT INTO navigation_menus
                  (id, parent_id, title, icon, path, view, subview, sort_order, visible)
                 VALUES (450, 400, '账号向导', 'users', '', 'accountWizard', 'pull_stats', 3, 1)
                 ON DUPLICATE KEY UPDATE parent_id=400, title='账号向导', icon='users', view='accountWizard', subview='pull_stats', sort_order=3, visible=1"
            );
            DB::query("UPDATE navigation_menus SET parent_id=450, title='自动养号', icon='activity', view='warm', subview='list', sort_order=1, visible=1 WHERE id=115");
            DB::query(
                "INSERT INTO navigation_menus
                  (id, parent_id, title, icon, path, view, subview, sort_order, visible)
                 VALUES
                  (451, 450, '拉取数据', 'download', '', 'accountWizard', 'pull_stats', 2, 1),
                  (452, 450, '修改资料', 'user', '', 'accountWizard', 'modify_profile', 3, 1)
                 ON DUPLICATE KEY UPDATE parent_id=450, title=VALUES(title), icon=VALUES(icon), view=VALUES(view), subview=VALUES(subview), sort_order=VALUES(sort_order), visible=1"
            );
            // 账号向导父级 + 拉取数据 / 修改资料：全角色开放
            try {
                DB::query("UPDATE navigation_menus SET permission_code='public' WHERE id IN (450,451,452)");
            } catch (\Throwable $e) {
            }
            DB::query("UPDATE navigation_menus SET parent_id=450, title='拉取数据', icon='download', view='accountWizard', subview='pull_stats', sort_order=2, visible=1 WHERE id=451");
            DB::query("UPDATE navigation_menus SET parent_id=450, title='修改资料', icon='user', view='accountWizard', subview='modify_profile', sort_order=3, visible=1 WHERE id=452");
            DB::query("UPDATE navigation_menus SET parent_id=400, title='矩阵任务', icon='list', view='accountWork', subview='list', sort_order=4, visible=1 WHERE id=116");
            // 旧「自动养号」直挂 AI矩阵 的重复项隐藏（功能已迁到账号向导下）
            DB::query("UPDATE navigation_menus SET visible=0 WHERE parent_id=400 AND view='warm' AND title='自动养号' AND id<>115");
        } catch (\Throwable $e) {
        }
        // 权限管理 / 系统管理：拆成两个一级菜单，并合理挂子级
        DB::query(
            "INSERT IGNORE INTO navigation_menus
              (id, parent_id, title, icon, path, view, subview, sort_order, visible)
             VALUES
              (600, NULL, '权限管理', 'shield', '', 'rbac', 'permissions', 6, 1),
              (605, 600, '角色与账号', 'users', '', 'rbac', 'users', 1, 1),
              (610, 605, '权限节点', 'shield', '', 'rbac', 'permissions', 1, 1),
              (620, 605, '用户角色', 'users', '', 'rbac', 'roles', 2, 1),
              (630, 605, '管理用户', 'user-check', '', 'rbac', 'users', 3, 1),
              (606, 600, '审计与导航', 'file-text', '', 'rbac', 'logs', 2, 1),
              (640, 606, '操作日志', 'file-text', '', 'rbac', 'logs', 1, 1),
              (650, 606, '后台菜单', 'menu', '', 'rbac', 'menus', 2, 1),
              (609, 600, '国家数据', 'globe', '', 'rbac', 'country_data', 3, 1),
              (607, 600, '行业数据', 'layers', '', 'rbac', 'industry_data', 4, 1),
              (608, 600, '企业数据', 'building-2', '', 'rbac', 'company_data', 5, 1),
              (800, NULL, '系统管理', 'settings', '', 'settings', 'browser', 7, 1),
              (810, 800, '基础配置', 'sliders', '', 'settings', 'browser', 1, 1),
              (660, 810, '基础设置', 'settings', '', 'settings', 'browser', 1, 1),
              (820, 800, '开发管理', 'cpu', '', 'aiPlatform', '', 2, 1),
              (670, 820, '版本控制', 'package', '', 'versions', '', 90, 0),
              (700, 820, '环境安装包', 'download', '', 'envInstallers', '', 91, 0),
              (710, 820, 'AI开放平台', 'cpu', '', 'aiPlatform', '', 1, 1),
              (720, 820, 'AI提示词模板', 'file-text', '', 'aiPromptTemplates', '', 2, 1),
              (730, 820, 'AI记录', 'activity', '', 'aiCallLogs', '', 3, 1),
              (830, 800, '支持服务', 'life-buoy', '', 'helpDocs', '', 3, 1),
              (680, 830, '帮助文档', 'book', '', 'helpDocs', '', 1, 1),
              (690, 830, '工单处理', 'ticket', '', 'tickets', '', 2, 1)"
        );
        try {
            // 一级：权限管理 / 系统管理
            DB::query("UPDATE navigation_menus SET parent_id=NULL, title='权限管理', icon='shield', path='', view='rbac', subview='permissions', sort_order=6, visible=1 WHERE id=600");
            DB::query("UPDATE navigation_menus SET parent_id=NULL, title='系统管理', icon='settings', path='', view='settings', subview='browser', sort_order=7, visible=1 WHERE id=800");
            // 权限管理二级目录
            DB::query("UPDATE navigation_menus SET parent_id=600, title='角色与账号', icon='users', path='', view='rbac', subview='users', sort_order=1, visible=1 WHERE id=605");
            DB::query("UPDATE navigation_menus SET parent_id=600, title='审计与导航', icon='file-text', path='', view='rbac', subview='logs', sort_order=2, visible=1 WHERE id=606");
            // 权限管理叶子
            DB::query("UPDATE navigation_menus SET parent_id=605, title='权限节点', icon='shield', path='', view='rbac', subview='permissions', sort_order=1, visible=1 WHERE id=610");
            DB::query("UPDATE navigation_menus SET parent_id=605, title='用户角色', icon='users', path='', view='rbac', subview='roles', sort_order=2, visible=1 WHERE id=620");
            DB::query("UPDATE navigation_menus SET parent_id=605, title='管理用户', icon='user-check', path='', view='rbac', subview='users', sort_order=3, visible=1 WHERE id=630");
            DB::query("UPDATE navigation_menus SET parent_id=606, title='操作日志', icon='file-text', path='', view='rbac', subview='logs', sort_order=1, visible=1 WHERE id=640");
            DB::query("UPDATE navigation_menus SET parent_id=606, title='后台菜单', icon='menu', path='', view='rbac', subview='menus', sort_order=2, visible=1 WHERE id=650");
            DB::query("UPDATE navigation_menus SET parent_id=600, title='国家数据', icon='globe', path='', view='rbac', subview='country_data', sort_order=3, visible=1 WHERE id=609 OR (view='rbac' AND subview='country_data')");
            DB::query("UPDATE navigation_menus SET parent_id=600, title='行业数据', icon='layers', path='', view='rbac', subview='industry_data', sort_order=4, visible=1 WHERE id=607 OR (view='rbac' AND subview='industry_data')");
            DB::query("UPDATE navigation_menus SET parent_id=600, title='企业数据', icon='building-2', path='', view='rbac', subview='company_data', sort_order=5, visible=1 WHERE id=608 OR (view='rbac' AND subview='company_data')");
            // 系统管理二级目录
            DB::query("UPDATE navigation_menus SET parent_id=800, title='基础配置', icon='sliders', path='', view='settings', subview='browser', sort_order=1, visible=1 WHERE id=810");
            DB::query("UPDATE navigation_menus SET parent_id=800, title='开发管理', icon='cpu', path='', view='aiPlatform', subview='', sort_order=2, visible=1 WHERE id=820");
            DB::query("UPDATE navigation_menus SET parent_id=800, title='支持服务', icon='life-buoy', path='', view='helpDocs', subview='', sort_order=3, visible=1 WHERE id=830");
            // 系统管理叶子（从旧 600 下迁出）
            DB::query("UPDATE navigation_menus SET parent_id=810, title='基础设置', icon='settings', path='', view='settings', subview='browser', sort_order=1, visible=1 WHERE id=660");
            // 版本控制 / 环境安装包：菜单隐藏（功能接口仍保留）
            DB::query("UPDATE navigation_menus SET parent_id=820, title='版本控制', icon='package', path='', view='versions', subview='', sort_order=90, visible=0 WHERE id=670");
            DB::query("UPDATE navigation_menus SET parent_id=820, title='环境安装包', icon='download', path='', view='envInstallers', subview='', sort_order=91, visible=0 WHERE id=700");
            DB::query(
                "INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
                 VALUES (710, 820, 'AI开放平台', 'cpu', '', 'aiPlatform', '', 1, 1)"
            );
            DB::query("UPDATE navigation_menus SET parent_id=820, title='AI开放平台', icon='cpu', path='', view='aiPlatform', subview='', sort_order=1, visible=1 WHERE id=710");
            DB::query(
                "INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
                 VALUES (720, 820, 'AI提示词模板', 'file-text', '', 'aiPromptTemplates', '', 2, 1)"
            );
            DB::query("UPDATE navigation_menus SET parent_id=820, title='AI提示词模板', icon='file-text', path='', view='aiPromptTemplates', subview='', sort_order=2, visible=1 WHERE id=720");
            DB::query(
                "INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
                 VALUES (730, 820, 'AI记录', 'activity', '', 'aiCallLogs', '', 3, 1)"
            );
            DB::query("UPDATE navigation_menus SET parent_id=820, title='AI记录', icon='activity', path='', view='aiCallLogs', subview='', sort_order=3, visible=1 WHERE id=730");
            DB::query(
                "INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
                 VALUES (740, 820, 'SMTP 配置', 'mail', '', 'smtpConfig', '', 4, 1)"
            );
            DB::query("UPDATE navigation_menus SET parent_id=820, title='SMTP 配置', icon='mail', path='', view='smtpConfig', subview='', sort_order=4, visible=1 WHERE id=740");
            DB::query(
                "INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
                 VALUES (750, 820, '日私信权重', 'gauge', '', 'dmWeightConfig', '', 5, 1)"
            );
            DB::query("UPDATE navigation_menus SET parent_id=820, title='日私信权重', icon='gauge', path='', view='dmWeightConfig', subview='', sort_order=5, visible=1 WHERE id=750");
            DB::query("UPDATE navigation_menus SET parent_id=830, title='帮助文档', icon='book', path='', view='helpDocs', subview='', sort_order=1, visible=1 WHERE id=680");
            DB::query("UPDATE navigation_menus SET parent_id=830, title='工单处理', icon='ticket', path='', view='tickets', subview='', sort_order=2, visible=1 WHERE id=690");
            // 兼容：旧「权限管理中台 / 权限与角色中台」归并到用户角色
            DB::query("UPDATE navigation_menus SET parent_id=605, title='用户角色', icon='users', path='', view='rbac', subview='roles', sort_order=2, visible=0 WHERE id=611");
            DB::query("UPDATE navigation_menus SET title='权限管理' WHERE title='权限与系统'");
        } catch (\Throwable $e) {
        }
        try {
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (70, 'system', 'system.versions', '管理客户端版本包', 70)"
            );
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (71, 'system', 'system.help', '管理帮助文档', 71)"
            );
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (72, 'system', 'system.tickets', '处理工单反馈', 72)"
            );
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (73, 'system', 'system.env_installers', '管理 Node/比特安装包', 73)"
            );
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (74, 'system', 'system.ai_platform', '管理AI开放平台', 74)"
            );
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (75, 'system', 'system.ai_prompt', '管理AI提示词模板', 75)"
            );
            // id 76 已被 account.msg_template 占用，system.ai_log 用 79
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (79, 'system', 'system.ai_log', '查看AI调用记录', 79)"
            );
            // 若历史上误用 76 写入了 system.ai_log（极少），纠正为 79
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 SELECT 79, 'system', 'system.ai_log', '查看AI调用记录', 79
                 FROM admin_permissions WHERE code='system.ai_log' AND id=76 LIMIT 1"
            );
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (77, 'rbac', 'rbac.country_data', '管理国家数据', 77)"
            );
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (78, 'rbac', 'rbac.industry_data', '管理行业数据', 78)"
            );
            DB::query(
                "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                 VALUES (88, 'rbac', 'rbac.company_data', '管理企业数据', 88)"
            );
            DB::query(
                "INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
                 VALUES (609, 600, '国家数据', 'globe', '', 'rbac', 'country_data', 3, 1)"
            );
            DB::query(
                "INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
                 VALUES (608, 600, '企业数据', 'building-2', '', 'rbac', 'company_data', 5, 1)"
            );
            // 与前端 canPermission 对齐：system.view / agent.* / publish.* / account.work / logs.view
            $extraPerms = [
                [80, 'system', 'system.view', '查看系统设置', 80],
                [81, 'agent', 'agent.view', '查看AI智能体', 81],
                [82, 'agent', 'agent.manage', '管理AI智能体', 82],
                [83, 'publish', 'publish.tasks', '发布任务写入', 83],
                [84, 'publish', 'publish.mass', '群发与客服相关写入', 84],
                [85, 'account', 'account.export', '导出账号数据', 85],
                [86, 'account', 'account.work.pause', '暂停矩阵任务', 86],
                [87, 'logs', 'logs.view', '查看实时操作日志', 87],
                [89, 'account', 'account.import', '导入账号', 2],
                [90, 'proxy', 'proxy.import', '批量导入代理', 11],
                [91, 'publish', 'warm.tasks', '养号任务写入', 21],
                [92, 'publish', 'publish.materials', '素材管理写入', 22],
                [94, 'account', 'account.work.delete', '删除矩阵任务', 86],
                [95, 'account', 'account.work.reset', '重置矩阵任务', 87],
                [97, 'account', 'account.msg_template.dm', '管理私信模板', 77],
                [98, 'system', 'system.smtp', '管理SMTP配置(仅超管)', 98],
                [99, 'system', 'system.dm_weight', '管理日私信权重规则(仅超管)', 99],
            ];
            foreach ($extraPerms as [$id, $mod, $code, $name, $sort]) {
                DB::query(
                    "INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
                     VALUES (?,?,?,?,?)",
                    [$id, $mod, $code, $name, $sort]
                );
            }
        } catch (\Throwable $e) {
        }
        try {
            \App\Services\Ai\AiPlatformCatalog::ensure();
        } catch (\Throwable $e) {
        }
        try {
            \App\Services\Ai\AiPromptCatalog::ensure();
        } catch (\Throwable $e) {
        }
        try {
            \App\Services\Ai\AiCallLogger::ensureTable();
        } catch (\Throwable $e) {
        }
    }
}