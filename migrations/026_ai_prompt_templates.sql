-- 026_ai_prompt_templates.sql
-- AI 提示词模板（不同模板不同变量占位）

CREATE TABLE IF NOT EXISTS `ai_prompt_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(64) NOT NULL DEFAULT '',
  `name` VARCHAR(128) NOT NULL,
  `category` VARCHAR(64) NOT NULL DEFAULT 'general',
  `description` VARCHAR(512) NOT NULL DEFAULT '',
  `content` MEDIUMTEXT NOT NULL,
  `variables_json` MEDIUMTEXT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
  `owner_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ai_prompt_code` (`code`),
  KEY `idx_ai_prompt_cat` (`category`),
  KEY `idx_ai_prompt_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admin_permissions` (`id`, `module`, `code`, `name`, `sort_order`) VALUES
(75, 'system', 'system.ai_prompt', '管理AI提示词模板', 75);

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (720, 820, 'AI提示词模板', 'file-text', '', 'aiPromptTemplates', '', 4, 1);
