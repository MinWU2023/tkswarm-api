-- 后台菜单 ↔ 权限节点：每个菜单 id 对应 admin_permissions.code = menu.{id}

-- 列由 MenuPermissionService::ensureSchema() 自动补齐；若手动执行：
-- ALTER TABLE `navigation_menus` ADD COLUMN `permission_code` VARCHAR(128) NOT NULL DEFAULT '' AFTER `visible`;

-- 权限模块字典（SeedMeta 也会 upsert）
INSERT INTO `app_dicts` (`domain`, `code`, `label`, `sort_order`, `meta_json`) VALUES
('perm_module', 'menu', '后台菜单', 8, '{}')
ON DUPLICATE KEY UPDATE label=VALUES(label), sort_order=VALUES(sort_order);
