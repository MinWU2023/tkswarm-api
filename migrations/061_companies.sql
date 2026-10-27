-- 企业/公司表，与管理用户、行业数据绑定

CREATE TABLE IF NOT EXISTS `companies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '公司名称',
  `contact_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '联系人',
  `contact_phone` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '联系电话',
  `contact_email` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '联系邮箱',
  `industry_id` INT NOT NULL DEFAULT 0 COMMENT '行业节点 industry_data.id',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=正常 0=停用',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_companies_industry` (`industry_id`),
  KEY `idx_companies_status` (`status`),
  KEY `idx_companies_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_users` (
  `company_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=主账号',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`company_id`, `user_id`),
  KEY `idx_company_users_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `admin_users`
  ADD COLUMN `company_id` INT NULL DEFAULT NULL COMMENT '所属企业 companies.id' AFTER `role_id`;

ALTER TABLE `admin_users`
  ADD KEY `idx_admin_users_company` (`company_id`);
