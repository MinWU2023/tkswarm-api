-- 视频向导 · 视频增值（分任务列表：创建 / 编辑 / 派发）
-- 任务表由 VideoBoostService::ensureSchema() 自动建表；此处只注册菜单。

INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
VALUES (446, 410, '视频增值', 'trending-up', '', 'videoBoost', 'list', 6, 1);

UPDATE navigation_menus
SET parent_id=410, title='视频增值', icon='trending-up', path='', view='videoBoost', subview='list', sort_order=6, visible=1
WHERE id=446;
