-- 005_node_parity_meta_tables.sql
-- 与 Node Rebuild 对齐的元数据 / 扩展表（MySQL）

CREATE TABLE IF NOT EXISTS `chat_tags` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL UNIQUE,
  `color` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `agent_templates` (
  `key` VARCHAR(191) PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `description` VARCHAR(512) NOT NULL DEFAULT '',
  `payload_json` MEDIUMTEXT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `view_permissions` (
  `view` VARCHAR(191) PRIMARY KEY,
  `codes_json` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_dicts` (
  `domain` VARCHAR(191) NOT NULL,
  `code` VARCHAR(191) NOT NULL,
  `label` VARCHAR(512) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `meta_json` TEXT NOT NULL,
  PRIMARY KEY (`domain`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
