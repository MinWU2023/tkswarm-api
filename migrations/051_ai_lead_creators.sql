-- 051_ai_lead_creators.sql
-- AI拓客：TikTok 用户（创作者）表

CREATE TABLE IF NOT EXISTS `ai_lead_creators` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lead_id` INT NOT NULL,
  `account_id` INT NOT NULL DEFAULT 0 COMMENT '发现该用户的执行账号',
  `lead_log_id` INT NOT NULL DEFAULT 0 COMMENT '来源视频日志',
  `uuid` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'TikTok 用户 uuid',
  `username` VARCHAR(128) NOT NULL DEFAULT '',
  `nickname` VARCHAR(255) NOT NULL DEFAULT '',
  `avatar` VARCHAR(512) NOT NULL DEFAULT '',
  `signature` TEXT NULL COMMENT '简介',
  `followers` INT NOT NULL DEFAULT 0,
  `following` INT NOT NULL DEFAULT 0,
  `likes` INT NOT NULL DEFAULT 0,
  `region` VARCHAR(64) NOT NULL DEFAULT '',
  `status` VARCHAR(32) NOT NULL DEFAULT 'new' COMMENT 'new|contacted|followed|ignored',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_alc_lead` (`lead_id`),
  KEY `idx_alc_account` (`account_id`),
  KEY `idx_alc_log` (`lead_log_id`),
  KEY `idx_alc_username` (`username`),
  KEY `idx_alc_status` (`status`),
  UNIQUE KEY `uk_alc_lead_uuid` (`lead_id`, `uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
