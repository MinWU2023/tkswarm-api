-- 视频详情页截图：每账号+视频保留最新 5 条
-- 运行时 TikTokVideoScreenshotService::ensureSchema 也会自动建表

CREATE TABLE IF NOT EXISTS `tiktok_video_screenshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `video_id` VARCHAR(64) NOT NULL DEFAULT '',
  `video_url` VARCHAR(500) NOT NULL DEFAULT '',
  `screenshot_base64` LONGTEXT NOT NULL,
  `mime_type` VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
  `source` VARCHAR(32) NOT NULL DEFAULT 'studio_detail',
  `page_url` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tvs_account_video_id` (`account_id`, `video_id`, `id`),
  KEY `idx_tvs_video_created` (`video_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
