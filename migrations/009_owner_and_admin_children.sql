-- 009_owner_and_admin_children.sql
-- 管理员父子关系表；业务表 owner_id 由 DataScope::ensureColumns 运行时幂等补齐。

CREATE TABLE IF NOT EXISTS `admin_user_children` (
  `parent_id` INT NOT NULL,
  `child_id` INT NOT NULL,
  PRIMARY KEY (`parent_id`, `child_id`),
  KEY `idx_admin_child` (`child_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
