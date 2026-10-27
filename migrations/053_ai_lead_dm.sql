-- 053_ai_lead_dm.sql
-- AI拓客私信：文案字段、私信记录挂 lead_id（列由 AiLeadExecutionService::ensureSchema 幂等补齐）

-- 运行时 ensureSchema 会执行：
--   ALTER TABLE ai_leads ADD COLUMN dm_message TEXT NULL
--   ALTER TABLE ai_lead_records ADD COLUMN lead_id INT NOT NULL DEFAULT 0

CREATE TABLE IF NOT EXISTS `ai_lead_account_daily` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT NOT NULL,
  `times` INT NOT NULL DEFAULT 0 COMMENT '当日私信次数',
  `date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_alad_account_date` (`account_id`, `date`),
  KEY `idx_alad_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
