-- 拉取评论：分任务表、评论树、素材互动字段
-- 编号用 073：070 已被 070_account_profile_change_logs.sql 占用。
-- 评论去重在应用层按 素材+用户名+正文 更新，不在 TEXT 上建联合唯一索引（utf8mb4 前缀易超 767 字节导致整文件失败，后续迁移不再执行）。

CREATE TABLE IF NOT EXISTS `pull_comment_tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL DEFAULT 0,
  `account_id` INT NOT NULL DEFAULT 0,
  `name` VARCHAR(255) NOT NULL DEFAULT '',
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `browser_backend` VARCHAR(32) NOT NULL DEFAULT 'bit',
  `material_ids_json` TEXT NULL,
  `video_count` INT NOT NULL DEFAULT 0,
  `done_count` INT NOT NULL DEFAULT 0,
  `comment_count` INT NOT NULL DEFAULT 0,
  `error_message` VARCHAR(512) NOT NULL DEFAULT '',
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_pct_owner` (`owner_id`),
  KEY `idx_pct_account` (`account_id`),
  KEY `idx_pct_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `material_comments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `material_id` INT NOT NULL DEFAULT 0,
  `account_id` INT NOT NULL DEFAULT 0,
  `owner_id` INT NOT NULL DEFAULT 0,
  `parent_id` INT NOT NULL DEFAULT 0,
  `platform_comment_id` VARCHAR(128) NOT NULL DEFAULT '',
  `username` VARCHAR(128) NOT NULL DEFAULT '',
  `nickname` VARCHAR(255) NOT NULL DEFAULT '',
  `uuid` VARCHAR(64) NOT NULL DEFAULT '',
  `profile_url` VARCHAR(500) NOT NULL DEFAULT '',
  `avatar_url` VARCHAR(500) NOT NULL DEFAULT '',
  `comment_text` TEXT NULL,
  `digg_count` INT NOT NULL DEFAULT 0,
  `reply_count` INT NOT NULL DEFAULT 0,
  `comment_time` VARCHAR(64) NOT NULL DEFAULT '',
  `commented_at` DATETIME NULL,
  `pulled_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_mc_material` (`material_id`),
  KEY `idx_mc_parent` (`parent_id`),
  KEY `idx_mc_account` (`account_id`),
  KEY `idx_mc_user` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE materials ADD COLUMN view_count BIGINT NOT NULL DEFAULT 0;
ALTER TABLE materials ADD COLUMN like_count BIGINT NOT NULL DEFAULT 0;
ALTER TABLE materials ADD COLUMN comment_count INT NOT NULL DEFAULT 0;
ALTER TABLE materials ADD COLUMN platform_published_at DATETIME NULL;
ALTER TABLE materials ADD COLUMN last_comment_pulled_at DATETIME NULL;
