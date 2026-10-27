-- 023: Node.js / 比特浏览器安装包（按系统 win|mac 分发）
CREATE TABLE IF NOT EXISTS `env_installers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `kind` VARCHAR(16) NOT NULL COMMENT 'node|bit',
  `platform` VARCHAR(16) NOT NULL COMMENT 'win|mac',
  `version` VARCHAR(64) NOT NULL,
  `title` VARCHAR(191) NOT NULL DEFAULT '',
  `download_url` VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '外链；若上传了文件则优先走本站下载接口',
  `file_path` VARCHAR(512) NOT NULL DEFAULT '',
  `file_name` VARCHAR(255) NOT NULL DEFAULT '',
  `file_size` BIGINT NOT NULL DEFAULT 0,
  `sha256` VARCHAR(64) NOT NULL DEFAULT '',
  `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
  `is_recommended` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `download_count` INT NOT NULL DEFAULT 0,
  `notes` VARCHAR(500) NOT NULL DEFAULT '',
  `owner_id` INT NULL,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_env_kind_plat_ver` (`kind`, `platform`, `version`),
  KEY `idx_env_pub` (`kind`, `platform`, `status`, `is_recommended`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 预置 Node 官方直链（Win/Mac），管理员可改/补比特安装包
INSERT IGNORE INTO `env_installers`
  (`kind`, `platform`, `version`, `title`, `download_url`, `status`, `is_recommended`, `sort_order`, `published_at`)
VALUES
  ('node', 'win', '22.14.0', '22.14.0 LTS（推荐·视频矩阵）', 'https://nodejs.org/dist/v22.14.0/node-v22.14.0-x64.msi', 'published', 1, 1, NOW()),
  ('node', 'mac', '22.14.0', '22.14.0 LTS（推荐·视频矩阵）', 'https://nodejs.org/dist/v22.14.0/node-v22.14.0.pkg', 'published', 1, 1, NOW()),
  ('node', 'win', '20.18.1', '20.18.1 LTS（兼容）', 'https://nodejs.org/dist/v20.18.1/node-v20.18.1-x64.msi', 'published', 0, 2, NOW()),
  ('node', 'mac', '20.18.1', '20.18.1 LTS（兼容）', 'https://nodejs.org/dist/v20.18.1/node-v20.18.1.pkg', 'published', 0, 2, NOW()),
  ('node', 'win', '18.20.5', '18.20.5 LTS（旧环境）', 'https://nodejs.org/dist/v18.20.5/node-v18.20.5-x64.msi', 'published', 0, 3, NOW()),
  ('node', 'mac', '18.20.5', '18.20.5 LTS（旧环境）', 'https://nodejs.org/dist/v18.20.5/node-v18.20.5.pkg', 'published', 0, 3, NOW()),
  ('bit', 'win', '7.1.4', '7.1.4（推荐·请上传安装包或填写直链）', '', 'draft', 1, 1, NULL),
  ('bit', 'mac', '7.1.4', '7.1.4（推荐·请上传安装包或填写直链）', '', 'draft', 1, 1, NULL),
  ('bit', 'win', '7.1.3', '7.1.3', '', 'draft', 0, 2, NULL),
  ('bit', 'mac', '7.1.3', '7.1.3', '', 'draft', 0, 2, NULL);

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (700, 600, '环境安装包', 'download', '', 'envInstallers', '', 10, 1);

INSERT IGNORE INTO `admin_permissions` (`id`, `module`, `code`, `name`, `sort_order`)
VALUES
  (73, 'system', 'system.env_installers', '管理 Node/比特安装包', 73);
