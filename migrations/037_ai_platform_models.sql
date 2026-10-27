-- 037_ai_platform_models.sql
-- 每个 AI 开放平台可配置的可用模型列表

CREATE TABLE IF NOT EXISTS `ai_platform_models` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `platform_id` INT NOT NULL,
  `code` VARCHAR(128) NOT NULL COMMENT '调用用模型 ID',
  `name` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '展示名',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ai_plat_model` (`platform_id`, `code`),
  KEY `idx_ai_plat_model_plat` (`platform_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `agents`
  ADD COLUMN `platform_id` INT NULL AFTER `model_id`;
