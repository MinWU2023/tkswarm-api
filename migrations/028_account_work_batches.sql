-- 028_account_work_batches.sql
-- 账号浏览器会话总任务：一次登录串行执行多类分任务

CREATE TABLE IF NOT EXISTS `account_work_batches` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT NOT NULL,
  `owner_id` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending|running|completed|failed|cancelled',
  `locked_by` VARCHAR(128) NOT NULL DEFAULT '',
  `lease_until` DATETIME NULL,
  `item_total` INT NOT NULL DEFAULT 0,
  `item_success` INT NOT NULL DEFAULT 0,
  `item_fail` INT NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `heartbeat_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_awb_account_status` (`account_id`, `status`),
  KEY `idx_awb_status_lease` (`status`, `lease_until`),
  KEY `idx_awb_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `account_work_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `batch_id` INT NOT NULL,
  `account_id` INT NOT NULL,
  `owner_id` INT NOT NULL DEFAULT 0,
  `task_type` VARCHAR(50) NOT NULL COMMENT '顺序:publish→delete_video→comment_reply→dm_reply/message→warm；登录登出为会话钩子',
  `task_ref_id` INT NOT NULL DEFAULT 0 COMMENT '对应各类任务主表 id，如 tasks.id',
  `priority` INT NOT NULL DEFAULT 100 COMMENT '越小越先:改资料5 发布10 删视频20 回评30 回私信40 养号50',
  `seq` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending|running|completed|failed|skipped|cancelled',
  `payload_json` MEDIUMTEXT NULL,
  `result_json` MEDIUMTEXT NULL,
  `error_message` TEXT NULL,
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_awi_batch_status` (`batch_id`, `status`, `priority`, `seq`, `id`),
  KEY `idx_awi_account` (`account_id`, `status`),
  KEY `idx_awi_task_ref` (`task_type`, `task_ref_id`),
  KEY `idx_awi_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
