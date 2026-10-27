-- 050_ai_leads.sql
-- AI营销 / AI拓客：配置、账号关联、视频日志、私信记录、账号日私信次数

CREATE TABLE IF NOT EXISTS `ai_leads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL DEFAULT 0,
  `type` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '拓客类型',
  `limit_follows` INT NOT NULL DEFAULT 0 COMMENT '关注下限',
  `limit_likes` INT NOT NULL DEFAULT 0 COMMENT '点赞下限',
  `limit_look` INT NOT NULL DEFAULT 0 COMMENT '浏览下限',
  `keywords` TEXT NULL COMMENT '关键词，多行或逗号分隔',
  `status` VARCHAR(32) NOT NULL DEFAULT 'draft' COMMENT 'draft|active|paused|completed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_al_owner` (`owner_id`),
  KEY `idx_al_status` (`status`),
  KEY `idx_al_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_lead_accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lead_id` INT NOT NULL,
  `account_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ala_lead_account` (`lead_id`, `account_id`),
  KEY `idx_ala_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_lead_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lead_id` INT NOT NULL,
  `video_url` VARCHAR(512) NOT NULL DEFAULT '',
  `video_cover` VARCHAR(512) NOT NULL DEFAULT '',
  `video_name` VARCHAR(255) NOT NULL DEFAULT '',
  `comment` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_all_lead` (`lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_lead_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT NOT NULL,
  `lead_log_id` INT NOT NULL DEFAULT 0,
  `uuid` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '对方用户 uuid',
  `username` VARCHAR(128) NOT NULL DEFAULT '',
  `message` TEXT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending|sent|failed|skipped',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_alr_account` (`account_id`),
  KEY `idx_alr_log` (`lead_log_id`),
  KEY `idx_alr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_lead_account_daily` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT NOT NULL,
  `times` INT NOT NULL DEFAULT 0 COMMENT '当日私信次数',
  `date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_alad_account_date` (`account_id`, `date`),
  KEY `idx_alad_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admin_permissions` (`id`, `module`, `code`, `name`, `sort_order`) VALUES
(77, 'ai', 'ai.leads', '管理AI拓客', 77);

-- 一级：AI营销；二级：AI拓客（排在 AI智能体 与 AI矩阵 之间）
INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (350, NULL, 'AI营销', 'sparkles', '', 'aiLeads', 'list', 4, 1),
  (351, 350, 'AI拓客', 'user-plus', '', 'aiLeads', 'list', 1, 1);

UPDATE `navigation_menus` SET `sort_order`=5 WHERE `id`=400 OR (`parent_id` IS NULL AND `view`='publish' AND `title` LIKE 'AI%矩阵%');
UPDATE `navigation_menus` SET `sort_order`=6 WHERE `id`=500 OR (`parent_id` IS NULL AND `view`='chat');
UPDATE `navigation_menus` SET `sort_order`=7 WHERE `id`=600 OR (`parent_id` IS NULL AND `view`='rbac');
UPDATE `navigation_menus` SET `sort_order`=8 WHERE `id`=800 OR (`parent_id` IS NULL AND (`view`='settings' OR `view`='system'));
