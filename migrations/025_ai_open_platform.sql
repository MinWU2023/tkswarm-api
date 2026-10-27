-- 025_ai_open_platform.sql
-- AI 开放平台：平台 1 : N 开发配置

CREATE TABLE IF NOT EXISTS `ai_platforms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `region` VARCHAR(16) NOT NULL DEFAULT 'cn' COMMENT 'cn|intl|local',
  `driver` VARCHAR(64) NOT NULL DEFAULT 'openai_compatible',
  `icon` VARCHAR(64) NOT NULL DEFAULT 'bot',
  `description` VARCHAR(512) NOT NULL DEFAULT '',
  `default_base_url` VARCHAR(512) NOT NULL DEFAULT '',
  `field_schema_json` MEDIUMTEXT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ai_platform_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_platform_configs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `platform_id` INT NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `config_json` MEDIUMTEXT NOT NULL COMMENT '业务配置；密钥字段经 SecretStore 加密后写入',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '全局启用：最多一条为 1',
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `owner_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_ai_cfg_platform` (`platform_id`),
  KEY `idx_ai_cfg_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admin_permissions` (`id`, `module`, `code`, `name`, `sort_order`) VALUES
(74, 'system', 'system.ai_platform', '管理AI开放平台', 74);

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (710, 820, 'AI开放平台', 'cpu', '', 'aiPlatform', '', 3, 1);
