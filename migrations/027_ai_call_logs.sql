-- 027_ai_call_logs.sql
-- AI 调用记录

CREATE TABLE IF NOT EXISTS `ai_call_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `platform_id` INT NULL,
  `platform_code` VARCHAR(64) NOT NULL DEFAULT '',
  `platform_name` VARCHAR(128) NOT NULL DEFAULT '',
  `config_id` INT NULL,
  `config_name` VARCHAR(128) NOT NULL DEFAULT '',
  `user_id` INT NULL,
  `username` VARCHAR(128) NOT NULL DEFAULT '',
  `operation` VARCHAR(64) NOT NULL DEFAULT 'chat',
  `status` VARCHAR(16) NOT NULL DEFAULT 'success',
  `error_message` VARCHAR(1000) NOT NULL DEFAULT '',
  `model` VARCHAR(128) NOT NULL DEFAULT '',
  `input_bytes` INT NOT NULL DEFAULT 0,
  `output_bytes` INT NOT NULL DEFAULT 0,
  `duration_ms` INT NOT NULL DEFAULT 0,
  `request_content` MEDIUMTEXT NULL,
  `response_content` MEDIUMTEXT NULL,
  `meta_json` TEXT NULL,
  `owner_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ai_log_created` (`created_at`),
  KEY `idx_ai_log_platform` (`platform_id`),
  KEY `idx_ai_log_config` (`config_id`),
  KEY `idx_ai_log_user` (`user_id`),
  KEY `idx_ai_log_status` (`status`),
  KEY `idx_ai_log_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admin_permissions` (`id`, `module`, `code`, `name`, `sort_order`) VALUES
(76, 'system', 'system.ai_log', '查看AI调用记录', 76);

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (730, 820, 'AI记录', 'activity', '', 'aiCallLogs', '', 5, 1);
