-- 013: 客服连接上次私信同步时间（幂等）
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

ALTER TABLE `chat_connections`
  ADD COLUMN `last_synced_at` DATETIME NULL DEFAULT NULL;
