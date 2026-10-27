-- 开发管理 · SMTP 配置菜单与权限节点（配置本身复用 settings 表 smtp.* 键）

INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
VALUES (98, 'system', 'system.smtp', '管理SMTP配置(仅超管)', 98);

INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
VALUES (740, 820, 'SMTP 配置', 'mail', '', 'smtpConfig', '', 4, 1);

UPDATE navigation_menus
SET parent_id=820, title='SMTP 配置', icon='mail', path='', view='smtpConfig', subview='', sort_order=4, visible=1
WHERE id=740;

-- SMTP 默认空配置键（幂等）
INSERT INTO settings (`key`, `value`) VALUES
  ('smtp.host', '""'),
  ('smtp.port', '"465"'),
  ('smtp.encryption', '"ssl"'),
  ('smtp.username', '""'),
  ('smtp.password', '""'),
  ('smtp.from_email', '""'),
  ('smtp.from_name', '"TkSwarm"')
ON DUPLICATE KEY UPDATE `key`=`key`;
