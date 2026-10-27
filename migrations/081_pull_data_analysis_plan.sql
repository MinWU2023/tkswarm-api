-- 拉取数据成功 → AI 分析邮件计划任务：管理用户邮箱、HTML 邮件模板、计划任务表

ALTER TABLE `admin_users`
  ADD COLUMN `email` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '通知邮箱' AFTER `nickname`;

ALTER TABLE `admin_users`
  ADD KEY `idx_admin_users_email` (`email`);

CREATE TABLE IF NOT EXISTS `mail_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(64) NOT NULL DEFAULT '',
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `subject` VARCHAR(255) NOT NULL DEFAULT '',
  `html_body` MEDIUMTEXT NOT NULL,
  `description` VARCHAR(512) NOT NULL DEFAULT '',
  `variables_json` MEDIUMTEXT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用',
  `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_mail_templates_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `type` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '如 pull_data_analysis',
  `name` VARCHAR(191) NOT NULL DEFAULT '',
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending/running/completed/failed/skipped',
  `account_id` INT NOT NULL DEFAULT 0,
  `source_type` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'pull_stats 等',
  `source_ref_id` INT NOT NULL DEFAULT 0,
  `work_item_id` INT NOT NULL DEFAULT 0,
  `batch_id` INT NOT NULL DEFAULT 0,
  `recipients_json` MEDIUMTEXT NULL COMMENT '去重后的邮箱列表',
  `payload_json` MEDIUMTEXT NULL COMMENT '拉取数据快照',
  `ai_prompt_code` VARCHAR(64) NOT NULL DEFAULT 'pull_data_analysis',
  `mail_template_code` VARCHAR(64) NOT NULL DEFAULT 'pull_data_analysis_report',
  `ai_result_json` MEDIUMTEXT NULL,
  `ai_raw_text` MEDIUMTEXT NULL,
  `mail_results_json` MEDIUMTEXT NULL,
  `error_message` TEXT NULL,
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_plan_tasks_type_work_item` (`type`, `work_item_id`),
  KEY `idx_plan_tasks_status` (`status`),
  KEY `idx_plan_tasks_account` (`account_id`),
  KEY `idx_plan_tasks_type_status` (`type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMTP 默认空配置（系统级 settings）
INSERT INTO `settings` (`key`, `value`) VALUES
  ('smtp.host', ''),
  ('smtp.port', '465'),
  ('smtp.encryption', 'ssl'),
  ('smtp.username', ''),
  ('smtp.password', ''),
  ('smtp.from_email', ''),
  ('smtp.from_name', 'TkSwarm')
ON DUPLICATE KEY UPDATE `key`=`key`;
