-- 001_initial_tables.sql

CREATE TABLE IF NOT EXISTS `groups` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('account','proxy','message','uid') NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_name_type` (`name`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `proxies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `protocol` VARCHAR(20) NOT NULL DEFAULT 'http',
  `host` VARCHAR(100) NOT NULL,
  `port` INT NOT NULL,
  `username` VARCHAR(100) NOT NULL DEFAULT '',
  `password` VARCHAR(255) NOT NULL DEFAULT '',
  `password_encrypted` TEXT NULL,
  `country` VARCHAR(50) NOT NULL DEFAULT '',
  `group_id` INT NULL,
  `status` ENUM('unchecked','available','unavailable') NOT NULL DEFAULT 'unchecked',
  `latency_ms` INT NULL,
  `last_checked_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `nickname` VARCHAR(100) NOT NULL DEFAULT '',
  `avatar_url` TEXT NULL,
  `sec_uid` VARCHAR(255) NOT NULL DEFAULT '',
  `group_id` INT NULL,
  `proxy_id` INT NULL,
  `browser_id` VARCHAR(100) NOT NULL DEFAULT '',
  `status` ENUM('online','offline','expired') NOT NULL DEFAULT 'offline',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_group` (`group_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `account_id` INT NULL,
  `status` ENUM('draft','queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  `config_json` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `navigation_menus` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `parent_id` INT NULL,
  `title` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(50) NOT NULL DEFAULT '',
  `path` VARCHAR(255) NOT NULL DEFAULT '',
  `view` VARCHAR(100) NOT NULL DEFAULT '',
  `subview` VARCHAR(100) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `visible` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_parent` (`parent_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RBAC 权限系统数据表
CREATE TABLE IF NOT EXISTS `admin_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `module` VARCHAR(50) NOT NULL,
  `code` VARCHAR(100) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `permissions` TEXT NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `nickname` VARCHAR(50) NOT NULL DEFAULT '',
  `role_id` INT NOT NULL DEFAULT 1,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_sessions` (
  `token` VARCHAR(64) PRIMARY KEY,
  `user_id` INT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_expire` (`user_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `module` VARCHAR(50) NOT NULL,
  `action` VARCHAR(255) NOT NULL,
  `path` VARCHAR(255) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
