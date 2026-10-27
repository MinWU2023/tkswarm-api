-- 002_seed_initial_data.sql

-- 默认分组
INSERT IGNORE INTO `groups` (`id`, `name`, `type`, `description`) VALUES
(1, '默认账号组', 'account', '新账号默认分组'),
(2, '默认代理组', 'proxy', '新代理默认分组'),
(3, '默认消息组', 'message', '消息模板默认分组');

-- RBAC 默认权限节点
INSERT IGNORE INTO `admin_permissions` (`id`, `module`, `code`, `name`, `sort_order`) VALUES
(1, 'account', 'account.view', '查看账号列表', 1),
(2, 'account', 'account.create', '创建账号', 2),
(89, 'account', 'account.import', '导入账号', 2),
(3, 'account', 'account.delete', '删除账号', 3),
(4, 'proxy', 'proxy.view', '查看代理列表', 10),
(5, 'proxy', 'proxy.create', '添加代理', 11),
(90, 'proxy', 'proxy.import', '批量导入代理', 11),
(6, 'proxy', 'proxy.test', '批量检测代理测速', 12),
(7, 'matrix', 'matrix.publish', '发布视频矩阵任务', 20),
(8, 'matrix', 'matrix.mass', '私信群发任务', 21),
(9, 'chat', 'chat.manage', '客服工作台对话与翻译', 30),
(10, 'rbac', 'rbac.manage', '系统角色与权限分配', 40),
(11, 'rbac', 'rbac.log', '查看系统审计日志', 41),
(12, 'chat', 'chat.send', '发送客服私信', 31),
(13, 'group', 'group.manage', '管理业务分组', 50),
(77, 'ai', 'ai.leads', '管理AI拓客', 77);

-- 默认角色：超级管理员与操作员
INSERT IGNORE INTO `admin_roles` (`id`, `name`, `description`, `permissions`, `status`) VALUES
(1, '超级管理员', '拥有系统最高全量权限', '[\"*\"]', 1),
(2, '运营专员', '负责矩阵运营、私信与客服', '[\"1\",\"2\",\"4\",\"7\",\"8\",\"9\"]', 1);

-- 默认初始管理员账号 (admin / admin123)
-- hash generated via password_hash('admin123', PASSWORD_DEFAULT)
INSERT IGNORE INTO `admin_users` (`id`, `username`, `password_hash`, `nickname`, `role_id`, `status`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '平台超级管理员', 1, 1);

-- 默认三级导航菜单数据
INSERT IGNORE INTO `navigation_menus` (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`) VALUES
(100, NULL, '账号中心', 'user', '', 'accounts', 'list', 1, 1),
  (110, 100, '账号池管理', 'users', '', 'accounts', 'list', 1, 1),
    (114, 110, '分组管理', 'folder', '', 'groups', 'account', 1, 1),
    (111, 110, '账号列表', 'list', '', 'accounts', 'list', 2, 1),
    (112, 110, '导入导出', 'upload', '', 'accounts', 'io', 3, 1),
    (115, 110, '自动养号', 'activity', '', 'warm', 'list', 4, 1),
  (210, 100, '代理IP池', 'server', '', 'proxies', '', 2, 1),
    (211, 210, '代理列表', 'list', '', 'proxies', 'list', 1, 1),
    (212, 210, '导入导出', 'upload', '', 'proxies', 'io', 2, 1),
    (213, 210, '添加单个IP', 'plus', '', 'proxies', 'create', 3, 1),
  (120, 100, '环境与指纹', 'browser', '', 'accounts', 'browser', 3, 1),
    (121, 120, '比特浏览器环境', 'window', '', 'accounts', 'browser', 1, 1),
    (122, 120, '批量创建环境', 'plus-square', '', 'accounts', 'batch_browser', 2, 1),
  (130, 100, '操作与审计', 'activity', '', 'logs', '', 4, 1),
    (131, 130, '实时操作日志', 'file-text', '', 'logs', '', 1, 1),

(300, NULL, 'AI智能体', 'bot', '', 'agent', 'basic', 3, 1),
  (310, 300, '智能体管理', 'cpu', '', 'agent', 'basic', 1, 1),
    (311, 310, '智能体中心', 'list', '', 'agent', 'basic', 1, 1),
    (312, 310, '新建智能体', 'plus', '', 'agent', 'create', 2, 1),
    (117, 310, '消息模板', 'message', '', 'msgTemplates', 'list', 3, 1),

(350, NULL, 'AI营销', 'sparkles', '', 'aiLeads', 'list', 4, 1),
  (351, 350, 'AI拓客', 'user-plus', '', 'aiLeads', 'list', 1, 1),

(400, NULL, 'AI 矩阵', 'video', '', 'publish', 'tasks', 5, 1),
  (410, 400, '视频发布向导', 'film', '', 'publish', 'tasks', 1, 1),
    (411, 410, '视频发布任务', 'list', '', 'publish', 'tasks', 1, 1),
    (412, 410, '随机发布', 'send', '', 'publish', 'create_publish', 2, 1),
    (414, 410, '创建群发', 'film', '', 'publish', 'create_ordered', 3, 1),
    (415, 410, '独立发布', 'play', '', 'publish', 'create_independent', 4, 1),
  (420, 400, '视频素材', 'folder', '', 'publish', 'materials', 2, 1),
    (416, 420, '素材库', 'folder', '', 'publish', 'materials', 1, 1),
    (413, 420, '素材导入', 'upload', '', 'publish', 'material_import', 2, 1),

(500, NULL, '客服聚合', 'message-circle', '', 'chat', 'chat', 6, 1),
  (510, 500, '实时会话工作台', 'inbox', '', 'chat', 'chat', 1, 1),
    (511, 510, '进入多账号客服台', 'monitor', '', 'chat', 'chat', 1, 1),
    (417, 510, 'UID好友', 'users', '', 'chat', 'uids', 2, 1),

(600, NULL, '权限管理', 'shield', '', 'rbac', 'permissions', 7, 1),
  (605, 600, '角色与账号', 'users', '', 'rbac', 'users', 1, 1),
    (610, 605, '权限节点', 'shield', '', 'rbac', 'permissions', 1, 1),
    (620, 605, '用户角色', 'users', '', 'rbac', 'roles', 2, 1),
    (630, 605, '管理用户', 'user-check', '', 'rbac', 'users', 3, 1),
  (606, 600, '审计与导航', 'file-text', '', 'rbac', 'logs', 2, 1),
    (640, 606, '操作日志', 'file-text', '', 'rbac', 'logs', 1, 1),
    (650, 606, '后台菜单', 'menu', '', 'rbac', 'menus', 2, 1),

(800, NULL, '系统管理', 'settings', '', 'settings', 'browser', 8, 1),
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
    (690, 830, '工单处理', 'ticket', '', 'tickets', '', 2, 1);
