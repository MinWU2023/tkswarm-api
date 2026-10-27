-- 020: 客户端代码版本包（供 EXE / WebIntoApp 客户端下载）
CREATE TABLE IF NOT EXISTS `client_versions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `version` VARCHAR(64) NOT NULL,
  `title` VARCHAR(191) NOT NULL DEFAULT '',
  `channel` VARCHAR(32) NOT NULL DEFAULT 'stable',
  `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
  `description` TEXT NULL,
  `file_path` VARCHAR(512) NOT NULL DEFAULT '',
  `file_name` VARCHAR(255) NOT NULL DEFAULT '',
  `file_size` BIGINT NOT NULL DEFAULT 0,
  `sha256` VARCHAR(64) NOT NULL DEFAULT '',
  `download_count` INT NOT NULL DEFAULT 0,
  `is_latest` TINYINT NOT NULL DEFAULT 0,
  `owner_id` INT NULL,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_client_version` (`version`, `channel`),
  KEY `idx_client_ver_status` (`status`, `is_latest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
