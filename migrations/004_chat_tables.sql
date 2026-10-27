-- 004: 客服本地会话表（与 Node / ChatController 运行时结构对齐）
-- 注意：旧版曾用 peer_uid/sender；若库已是旧结构，由 019 迁移升级。

CREATE TABLE IF NOT EXISTS `chat_friends` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT NOT NULL,
  `friend_uid` VARCHAR(191) NOT NULL DEFAULT '',
  `username` VARCHAR(255) NOT NULL DEFAULT '',
  `nickname` VARCHAR(255) NOT NULL DEFAULT '',
  `avatar_url` TEXT NULL,
  `relation` VARCHAR(32) NOT NULL DEFAULT 'stranger',
  `tags` VARCHAR(512) NOT NULL DEFAULT '',
  `unread_count` INT NOT NULL DEFAULT 0,
  `is_pinned` TINYINT NOT NULL DEFAULT 0,
  `is_new` TINYINT NOT NULL DEFAULT 0,
  `last_message` TEXT NULL,
  `last_direction` VARCHAR(8) NOT NULL DEFAULT '',
  `last_message_at` DATETIME NULL,
  `translate_in` TINYINT NOT NULL DEFAULT 0,
  `translate_out` TINYINT NOT NULL DEFAULT 0,
  `translate_target` VARCHAR(16) NOT NULL DEFAULT 'zh',
  `translate_source` VARCHAR(16) NOT NULL DEFAULT 'auto',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_chat_friend` (`account_id`, `friend_uid`),
  KEY `idx_chat_friends_last` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT NOT NULL,
  `friend_id` INT NOT NULL,
  `direction` VARCHAR(8) NOT NULL,
  `msg_type` VARCHAR(32) NOT NULL DEFAULT 'text',
  `content` TEXT NOT NULL,
  `translated` TEXT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'sent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_chat_msg_friend` (`friend_id`, `id`),
  KEY `idx_chat_msg_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_connections` (
  `account_id` INT PRIMARY KEY,
  `status` VARCHAR(32) NOT NULL DEFAULT 'offline',
  `auto_translate` TINYINT NOT NULL DEFAULT 0,
  `sync_messages` TINYINT NOT NULL DEFAULT 1,
  `ai_hosting` TINYINT NOT NULL DEFAULT 0,
  `agent_id` INT NULL,
  `connected_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_tags` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `color` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_chat_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
