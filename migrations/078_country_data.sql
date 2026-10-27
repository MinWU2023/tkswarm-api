-- 国家数据：表结构、导航菜单、权限节点

CREATE TABLE IF NOT EXISTS `country_data` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` CHAR(2) NOT NULL COMMENT 'ISO3166-1 alpha-2',
  `name_zh` VARCHAR(128) NOT NULL DEFAULT '',
  `name_en` VARCHAR(128) NOT NULL DEFAULT '',
  `continent` VARCHAR(32) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_country_code` (`code`),
  KEY `idx_country_enabled` (`enabled`),
  KEY `idx_country_name_zh` (`name_zh`),
  KEY `idx_country_name_en` (`name_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible) VALUES
  (609, 600, '国家数据', 'globe', '', 'rbac', 'country_data', 3, 1);

INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order) VALUES
  (77, 'rbac', 'rbac.country_data', '管理国家数据', 77);

UPDATE navigation_menus
SET parent_id=600, title='国家数据', icon='globe', path='', view='rbac', subview='country_data', sort_order=3, visible=1
WHERE id=609 OR (view='rbac' AND subview='country_data');

UPDATE navigation_menus SET sort_order=4 WHERE id=607 OR (view='rbac' AND subview='industry_data');
UPDATE navigation_menus SET sort_order=5 WHERE id=608 OR (view='rbac' AND subview='company_data');
