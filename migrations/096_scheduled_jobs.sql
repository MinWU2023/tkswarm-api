-- 统一计划任务启停与最近执行记录（定义在代码 ScheduleRegistry，状态在此表）
CREATE TABLE IF NOT EXISTS `scheduled_jobs` (
  `job_key` VARCHAR(64) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_run_at` DATETIME NULL,
  `last_status` VARCHAR(32) NULL,
  `last_message` VARCHAR(500) NULL,
  `last_duration_ms` INT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`job_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
