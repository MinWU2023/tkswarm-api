-- 开发管理 · 测试注册环境：独立数据表，直接存放代理字段与邮箱字段。
-- TestRegisterEnvController::ensureSchema() 也会在首次访问时自动建表，此处为显式迁移记录。
CREATE TABLE IF NOT EXISTS `test_register_envs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL DEFAULT 0,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `proxy_protocol` VARCHAR(16) NOT NULL DEFAULT 'socks5',
  `proxy_host` VARCHAR(191) NOT NULL DEFAULT '',
  `proxy_port` INT NOT NULL DEFAULT 0,
  `proxy_username` VARCHAR(191) NOT NULL DEFAULT '',
  `proxy_password_encrypted` TEXT NULL,
  `country` VARCHAR(64) NOT NULL DEFAULT '',
  `email` VARCHAR(191) NOT NULL DEFAULT '',
  `browser_backend` VARCHAR(32) NOT NULL DEFAULT 'tkswarm',
  `browser_profile_id` VARCHAR(128) NOT NULL DEFAULT '',
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
