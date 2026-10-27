-- 019: 将旧 004/install.sql（peer_uid/sender）升级到运行时结构（friend_uid/direction）
-- 干净库若已是新结构，ADD/CHANGE 失败会被 migrate.php 幂等忽略。

-- chat_friends: peer_uid → friend_uid（若已是 friend_uid 则本句失败并忽略）
ALTER TABLE `chat_friends`
  CHANGE COLUMN `peer_uid` `friend_uid` VARCHAR(191) NOT NULL DEFAULT '';

ALTER TABLE `chat_friends` ADD COLUMN `username` VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE `chat_friends` ADD COLUMN `relation` VARCHAR(32) NOT NULL DEFAULT 'stranger';
ALTER TABLE `chat_friends` ADD COLUMN `tags` VARCHAR(512) NOT NULL DEFAULT '';
ALTER TABLE `chat_friends` ADD COLUMN `is_pinned` TINYINT NOT NULL DEFAULT 0;
ALTER TABLE `chat_friends` ADD COLUMN `is_new` TINYINT NOT NULL DEFAULT 0;
ALTER TABLE `chat_friends` ADD COLUMN `last_direction` VARCHAR(8) NOT NULL DEFAULT '';
ALTER TABLE `chat_friends` ADD COLUMN `translate_in` TINYINT NOT NULL DEFAULT 0;
ALTER TABLE `chat_friends` ADD COLUMN `translate_out` TINYINT NOT NULL DEFAULT 0;
ALTER TABLE `chat_friends` ADD COLUMN `translate_target` VARCHAR(16) NOT NULL DEFAULT 'zh';
ALTER TABLE `chat_friends` ADD COLUMN `translate_source` VARCHAR(16) NOT NULL DEFAULT 'auto';

-- chat_messages: 补齐运行时列
ALTER TABLE `chat_messages` ADD COLUMN `direction` VARCHAR(8) NOT NULL DEFAULT 'in';
ALTER TABLE `chat_messages` ADD COLUMN `account_id` INT NOT NULL DEFAULT 0;
ALTER TABLE `chat_messages` ADD COLUMN `msg_type` VARCHAR(32) NOT NULL DEFAULT 'text';
ALTER TABLE `chat_messages` ADD COLUMN `translated` TEXT NULL;
ALTER TABLE `chat_messages` ADD COLUMN `external_id` VARCHAR(191) NULL DEFAULT NULL;

-- 旧 sender → direction（无 sender 列时整句失败并忽略）
UPDATE `chat_messages`
SET `direction` = CASE WHEN `sender` = 'me' THEN 'out' ELSE 'in' END
WHERE `direction` = '' OR `direction` IS NULL OR `direction` = 'in';

-- 旧 translated_content → translated（无该列时忽略）
UPDATE `chat_messages`
SET `translated` = COALESCE(`translated`, `translated_content`)
WHERE `translated` IS NULL AND `translated_content` IS NOT NULL;

-- 回填 account_id
UPDATE `chat_messages` AS m
INNER JOIN `chat_friends` AS f ON f.id = m.friend_id
SET m.account_id = f.account_id
WHERE m.account_id = 0 OR m.account_id IS NULL;

CREATE TABLE IF NOT EXISTS `chat_connections` (
  `account_id` INT PRIMARY KEY,
  `status` VARCHAR(32) NOT NULL DEFAULT 'offline',
  `auto_translate` TINYINT NOT NULL DEFAULT 0,
  `sync_messages` TINYINT NOT NULL DEFAULT 1,
  `ai_hosting` TINYINT NOT NULL DEFAULT 0,
  `agent_id` INT NULL,
  `connected_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `last_synced_at` DATETIME NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_tags` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `color` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_chat_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `chat_messages`
  ADD UNIQUE KEY `uk_chat_msg_ext` (`account_id`, `external_id`);
