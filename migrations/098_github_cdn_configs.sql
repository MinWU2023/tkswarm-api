-- GitHub Release CDN 配置 + 素材 CDN 字段

CREATE TABLE IF NOT EXISTS `github_cdn_configs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `owner` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'GitHub owner/org',
  `repo` VARCHAR(150) NOT NULL DEFAULT '',
  `token_enc` TEXT NULL COMMENT 'SecretStore 加密的 PAT',
  `tag_prefix` VARCHAR(64) NOT NULL DEFAULT 'videos',
  `url_template` VARCHAR(500) NOT NULL DEFAULT 'https://github.com/{owner}/{repo}/releases/download/{tag}/{name}',
  `cdn_prefix` VARCHAR(300) NOT NULL DEFAULT '' COMMENT '可选镜像前缀，如 https://ghfast.top/',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_github_cdn_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- materials CDN 字段（若已存在请忽略报错；运行时 GithubReleaseCdnService::ensureSchema 也会补齐）
ALTER TABLE `materials` ADD COLUMN `cdn_url` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '对外分发链接';
ALTER TABLE `materials` ADD COLUMN `github_asset_url` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'GitHub Release 直链';
ALTER TABLE `materials` ADD COLUMN `github_config_id` INT NOT NULL DEFAULT 0 COMMENT '上传所用配置';
ALTER TABLE `materials` ADD COLUMN `cover_file_path` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '封面本地路径';
ALTER TABLE `materials` ADD COLUMN `cover_file_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '封面文件名';
ALTER TABLE `materials` ADD COLUMN `cover_cdn_url` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '封面 CDN 链接';
ALTER TABLE `materials` ADD COLUMN `cover_github_asset_url` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '封面 GitHub Release 直链';
