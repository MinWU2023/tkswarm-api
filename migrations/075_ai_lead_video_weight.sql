-- 075_ai_lead_video_weight.sql
-- AI拓客：视频激活权重、账号私信间隔、日额度

-- 账号首次发布视频成功时间（用于计算激活天数；列由 AiLeadVideoWeightService::ensureSchema 幂等补齐）
-- ALTER TABLE `accounts` ADD COLUMN `first_video_published_at` DATETIME NULL COMMENT '首次发视频成功时间';

-- 视频权重配置：激活天数阈值 -> 日私信次数（owner_id=0 为系统默认）
CREATE TABLE IF NOT EXISTS `account_video_weight_tiers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL DEFAULT 0 COMMENT '0=系统默认，>0=租户自定义',
  `days` INT NOT NULL DEFAULT 0 COMMENT '激活天数>=',
  `daily_dm_limit` INT NOT NULL DEFAULT 1 COMMENT '日私信次数',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_avwt_owner_days` (`owner_id`, `days`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 账号私信设置（间隔；每账号独立，缺省走系统默认）
CREATE TABLE IF NOT EXISTS `account_dm_settings` (
  `account_id` INT NOT NULL PRIMARY KEY,
  `dm_interval_min_sec` INT NOT NULL DEFAULT 180 COMMENT '私信最小间隔秒',
  `dm_interval_max_sec` INT NOT NULL DEFAULT 600 COMMENT '私信最大间隔秒',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 日额度表补 daily_limit（由计划任务按激活天数写入；列由 ensureSchema 幂等补齐）
-- ALTER TABLE `ai_lead_account_daily` ADD COLUMN `daily_limit` INT NOT NULL DEFAULT 0 COMMENT '当日私信上限' AFTER `times`;

-- 系统默认权重阶梯
INSERT IGNORE INTO `account_video_weight_tiers` (`id`, `owner_id`, `days`, `daily_dm_limit`, `sort_order`) VALUES
(1, 0, 0,  1, 1),
(2, 0, 3,  2, 2),
(3, 0, 7,  3, 3),
(4, 0, 14, 5, 4),
(5, 0, 30, 8, 5);
