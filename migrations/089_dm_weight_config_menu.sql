-- 开发管理 · 日私信权重规则（系统默认阶梯，仅超管）

INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
VALUES (99, 'system', 'system.dm_weight', '管理日私信权重规则(仅超管)', 99);

INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
VALUES (750, 820, '日私信权重', 'gauge', '', 'dmWeightConfig', '', 5, 1);

UPDATE navigation_menus
SET parent_id=820, title='日私信权重', icon='gauge', path='', view='dmWeightConfig', subview='', sort_order=5, visible=1
WHERE id=750;
