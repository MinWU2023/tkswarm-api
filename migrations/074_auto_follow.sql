-- 自动增粉：主任务、明细调度、关注关系记录

CREATE TABLE IF NOT EXISTS `auto_follow_tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL DEFAULT 0,
  `name` VARCHAR(255) NOT NULL DEFAULT '',
  `target_account_id` INT NOT NULL DEFAULT 0,
  `target_tiktok_uid` VARCHAR(128) NOT NULL DEFAULT '',
  `target_username` VARCHAR(128) NOT NULL DEFAULT '',
  `target_display` VARCHAR(255) NOT NULL DEFAULT '',
  `follow_count` INT NOT NULL DEFAULT 0,
  `min_interval_hours` DECIMAL(10,2) NOT NULL DEFAULT 1,
  `max_interval_hours` DECIMAL(10,2) NOT NULL DEFAULT 2,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `scheduled_count` INT NOT NULL DEFAULT 0,
  `enqueued_count` INT NOT NULL DEFAULT 0,
  `success_count` INT NOT NULL DEFAULT 0,
  `failed_count` INT NOT NULL DEFAULT 0,
  `error_message` VARCHAR(512) NOT NULL DEFAULT '',
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_aft_owner` (`owner_id`),
  KEY `idx_aft_status` (`status`),
  KEY `idx_aft_target_acc` (`target_account_id`),
  KEY `idx_aft_target_uid` (`target_tiktok_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auto_follow_task_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `task_id` INT NOT NULL DEFAULT 0,
  `source_account_id` INT NOT NULL DEFAULT 0,
  `owner_id` INT NOT NULL DEFAULT 0,
  `scheduled_at` DATETIME NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `work_item_id` INT NOT NULL DEFAULT 0,
  `error_message` VARCHAR(512) NOT NULL DEFAULT '',
  `executed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_afti_task_source` (`task_id`, `source_account_id`),
  KEY `idx_afti_due` (`status`, `scheduled_at`),
  KEY `idx_afti_source` (`source_account_id`),
  KEY `idx_afti_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_follow_relations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `follower_account_id` INT NOT NULL DEFAULT 0,
  `followed_account_id` INT NOT NULL DEFAULT 0,
  `followed_tiktok_uid` VARCHAR(128) NOT NULL DEFAULT '',
  `followed_username` VARCHAR(128) NOT NULL DEFAULT '',
  `source` VARCHAR(64) NOT NULL DEFAULT 'auto_follow',
  `task_id` INT NOT NULL DEFAULT 0,
  `task_item_id` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_afr_pair` (`follower_account_id`, `followed_account_id`, `followed_tiktok_uid`),
  KEY `idx_afr_follower` (`follower_account_id`),
  KEY `idx_afr_followed_acc` (`followed_account_id`),
  KEY `idx_afr_followed_uid` (`followed_tiktok_uid`),
  KEY `idx_afr_followed_user` (`followed_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
