-- 注册邮箱库：邮箱来自 tkswarm-api/emails.txt，导入后逐个取用，并记录使用状态与时间。
-- RegisterMailboxService::ensureSchema() 也会在首次访问时自动建表，此处为显式迁移记录。
CREATE TABLE IF NOT EXISTS `register_mailboxes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(191) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'unused' COMMENT '使用状态：unused未使用/used已使用',
  `used_at` DATETIME NULL DEFAULT NULL COMMENT '取用时间',
  `claim_token` VARCHAR(32) NOT NULL DEFAULT '',
  `checked_at` DATETIME NULL DEFAULT NULL COMMENT '收件箱检查时间',
  `block_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '不可用原因',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_register_mailboxes_email` (`email`),
  KEY `idx_register_mailboxes_status_id` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
