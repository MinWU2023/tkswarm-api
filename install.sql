-- TKSwarm 完整 MySQL 数据库初始化与迁移脚本 (单文件直接导入版)
-- 适用 MySQL 5.7+ / 5.8+ / 8.0+

CREATE DATABASE IF NOT EXISTS `tkswarm` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `tkswarm`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. 业务分组表
DROP TABLE IF EXISTS `groups`;
CREATE TABLE `groups` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('account','proxy','message','uid') NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_name_type` (`name`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 代理池表
DROP TABLE IF EXISTS `proxies`;
CREATE TABLE `proxies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `protocol` VARCHAR(20) NOT NULL DEFAULT 'http',
  `host` VARCHAR(100) NOT NULL,
  `port` INT NOT NULL,
  `username` VARCHAR(100) NOT NULL DEFAULT '',
  `password` VARCHAR(255) NOT NULL DEFAULT '',
  `password_encrypted` TEXT NULL,
  `country` VARCHAR(50) NOT NULL DEFAULT '',
  `group_id` INT NULL,
  `status` ENUM('unchecked','available','unavailable') NOT NULL DEFAULT 'unchecked',
  `latency_ms` INT NULL,
  `last_checked_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 账号主表
DROP TABLE IF EXISTS `accounts`;
CREATE TABLE `accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `nickname` VARCHAR(100) NOT NULL DEFAULT '',
  `avatar_url` TEXT NULL,
  `sec_uid` VARCHAR(255) NOT NULL DEFAULT '',
  `group_id` INT NULL,
  `proxy_id` INT NULL,
  `browser_id` VARCHAR(100) NOT NULL DEFAULT '',
  `status` ENUM('online','offline','expired') NOT NULL DEFAULT 'offline',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_group` (`group_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 任务中心表
DROP TABLE IF EXISTS `tasks`;
CREATE TABLE `tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `account_id` INT NULL,
  `status` ENUM('draft','queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  `config_json` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 导航菜单表 (三级级联)
DROP TABLE IF EXISTS `navigation_menus`;
CREATE TABLE `navigation_menus` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `parent_id` INT NULL,
  `title` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(50) NOT NULL DEFAULT '',
  `path` VARCHAR(255) NOT NULL DEFAULT '',
  `view` VARCHAR(100) NOT NULL DEFAULT '',
  `subview` VARCHAR(100) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `visible` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_parent` (`parent_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. RBAC 权限节点表
DROP TABLE IF EXISTS `admin_permissions`;
CREATE TABLE `admin_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `module` VARCHAR(50) NOT NULL,
  `code` VARCHAR(100) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. RBAC 角色表
DROP TABLE IF EXISTS `admin_roles`;
CREATE TABLE `admin_roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `permissions` TEXT NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. 管理员用户表
DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `nickname` VARCHAR(50) NOT NULL DEFAULT '',
  `role_id` INT NOT NULL DEFAULT 1,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. 会话 Session 表
DROP TABLE IF EXISTS `admin_sessions`;
CREATE TABLE `admin_sessions` (
  `token` VARCHAR(64) PRIMARY KEY,
  `user_id` INT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_expire` (`user_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. 操作日志表
DROP TABLE IF EXISTS `admin_logs`;
CREATE TABLE `admin_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `module` VARCHAR(50) NOT NULL,
  `action` VARCHAR(255) NOT NULL,
  `path` VARCHAR(255) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. 素材资源去重表
DROP TABLE IF EXISTS `materials`;
CREATE TABLE `materials` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `path` VARCHAR(500) NOT NULL,
  `type` ENUM('video','image','other') NOT NULL DEFAULT 'video',
  `size_bytes` BIGINT NOT NULL DEFAULT 0,
  `sha256` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_sha256` (`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. AI 智能体表
DROP TABLE IF EXISTS `agents`;
CREATE TABLE `agents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `model` VARCHAR(100) NOT NULL DEFAULT 'gpt-4o-mini',
  `system_prompt` TEXT NULL,
  `api_base` VARCHAR(255) NOT NULL DEFAULT '',
  `api_key` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. 系统参数设置表
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id` INT NOT NULL,
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `key`),
  KEY `idx_user_settings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. 快捷话术模板表
DROP TABLE IF EXISTS `message_templates`;
CREATE TABLE `message_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `group_id` INT NULL,
  `title` VARCHAR(100) NOT NULL,
  `content` TEXT NOT NULL,
  `shortcut` VARCHAR(50) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. 客服聚合私信好友表
DROP TABLE IF EXISTS `chat_friends`;
CREATE TABLE `chat_friends` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT NOT NULL,
  `peer_uid` VARCHAR(100) NOT NULL,
  `nickname` VARCHAR(100) NOT NULL DEFAULT '',
  `avatar_url` TEXT NULL,
  `unread_count` INT NOT NULL DEFAULT 0,
  `last_message` TEXT NULL,
  `last_message_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_account_peer` (`account_id`, `peer_uid`),
  KEY `idx_last_msg_at` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. 客服聊天消息表
DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE `chat_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `friend_id` INT NOT NULL,
  `sender` ENUM('me','peer') NOT NULL DEFAULT 'peer',
  `content` TEXT NOT NULL,
  `translated_content` TEXT NULL,
  `status` ENUM('sending','sent','failed') NOT NULL DEFAULT 'sent',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_friend_time` (`friend_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =================================================================
-- 初始业务数据播种 (Seed Data)
-- =================================================================

-- 默认分组
INSERT INTO `groups` (`id`, `name`, `type`, `description`) VALUES
(1, '默认账号组', 'account', '新账号默认分组'),
(2, '默认代理组', 'proxy', '新代理默认分组'),
(3, '默认消息组', 'message', '消息模板默认分组');

-- RBAC 权限节点
INSERT INTO `admin_permissions` (`id`, `module`, `code`, `name`, `sort_order`) VALUES
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

-- 默认角色：超级管理员
INSERT INTO `admin_roles` (`id`, `name`, `description`, `permissions`, `status`) VALUES
(1, '超级管理员', '拥有系统最高全量权限', '[\"*\"]', 1),
(2, '运营专员', '负责矩阵运营、私信与客服', '[\"1\",\"2\",\"4\",\"7\",\"8\",\"9\",\"12\"]', 1);

-- 默认初始管理员账号 (admin / admin123)
INSERT INTO `admin_users` (`id`, `username`, `password_hash`, `nickname`, `role_id`, `status`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '平台超级管理员', 1, 1);

-- 默认三级导航菜单数据
INSERT INTO `navigation_menus` (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`) VALUES
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

SET FOREIGN_KEY_CHECKS = 1;
