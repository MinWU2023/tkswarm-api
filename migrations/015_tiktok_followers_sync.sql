-- 015: 粉丝列表拉取审计表
CREATE TABLE IF NOT EXISTS `tiktok_followers_sync` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT NOT NULL,
  `friend_uid` VARCHAR(191) NOT NULL,
  `username` VARCHAR(255) NOT NULL DEFAULT '',
  `nickname` VARCHAR(255) NOT NULL DEFAULT '',
  `avatar_url` TEXT NULL,
  `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_fans_sync` (`account_id`, `friend_uid`),
  KEY `idx_fans_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
