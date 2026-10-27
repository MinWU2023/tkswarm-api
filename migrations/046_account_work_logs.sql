-- 账号会话任务实时执行日志（总任务 / 子任务）
CREATE TABLE IF NOT EXISTS `account_work_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `batch_id` INT NOT NULL DEFAULT 0,
  `item_id` INT NOT NULL DEFAULT 0,
  `account_id` INT NOT NULL DEFAULT 0,
  `task_id` INT NOT NULL DEFAULT 0,
  `level` VARCHAR(16) NOT NULL DEFAULT 'info',
  `message` TEXT NOT NULL,
  `meta_json` MEDIUMTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_awl_item_time` (`item_id`, `id`),
  KEY `idx_awl_batch_time` (`batch_id`, `id`),
  KEY `idx_awl_account_time` (`account_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
