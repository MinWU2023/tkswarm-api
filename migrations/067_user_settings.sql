-- 基础设置按用户隔离
CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id` INT NOT NULL,
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `key`),
  KEY `idx_user_settings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 将历史全局基础设置复制到各管理员（authEnabled / captcha 仍留 settings）
INSERT INTO user_settings (user_id, `key`, `value`, created_at, updated_at)
SELECT u.id, s.`key`, s.`value`, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM admin_users u
CROSS JOIN settings s
WHERE s.`key` NOT IN ('authEnabled')
  AND s.`key` NOT LIKE 'captcha:%'
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at);
