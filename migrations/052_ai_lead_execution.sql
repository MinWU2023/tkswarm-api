-- 052_ai_lead_execution.sql
-- AI拓客执行层：视频表、评论表、执行进度字段

-- 列由 AiLeadExecutionService::ensureSchema() 幂等补齐；此处仅建表

CREATE TABLE IF NOT EXISTS `ai_lead_videos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lead_id` INT NOT NULL,
  `creator_id` INT NOT NULL DEFAULT 0 COMMENT '关联 ai_lead_creators',
  `account_id` INT NOT NULL DEFAULT 0 COMMENT '抓取账号',
  `video_url` VARCHAR(512) NOT NULL DEFAULT '',
  `video_cover` VARCHAR(512) NOT NULL DEFAULT '',
  `video_name` VARCHAR(255) NOT NULL DEFAULT '',
  `likes` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_alv_lead` (`lead_id`),
  KEY `idx_alv_creator` (`creator_id`),
  UNIQUE KEY `uk_alv_lead_url` (`lead_id`, `video_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_lead_comments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lead_id` INT NOT NULL,
  `video_id` INT NOT NULL DEFAULT 0 COMMENT '关联 ai_lead_videos',
  `uuid` VARCHAR(64) NOT NULL DEFAULT '',
  `username` VARCHAR(128) NOT NULL DEFAULT '',
  `nickname` VARCHAR(255) NOT NULL DEFAULT '',
  `profile_url` VARCHAR(512) NOT NULL DEFAULT '',
  `comment_text` TEXT NULL,
  `ai_pass` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'AI判定为询价/问产品',
  `ai_reason` VARCHAR(512) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_alc_lead` (`lead_id`),
  KEY `idx_alc_video` (`video_id`),
  KEY `idx_alc_pass` (`lead_id`, `ai_pass`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
