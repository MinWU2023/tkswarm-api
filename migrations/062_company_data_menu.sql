-- 企业数据：导航菜单 + 权限节点

INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible) VALUES
  (608, 600, '企业数据', 'building-2', '', 'rbac', 'company_data', 4, 1);

INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order) VALUES
  (88, 'rbac', 'rbac.company_data', '管理企业数据', 88);

UPDATE navigation_menus
SET parent_id=600, title='企业数据', icon='building-2', path='', view='rbac', subview='company_data', sort_order=4, visible=1
WHERE id=608 OR (view='rbac' AND subview='company_data');
