-- 042_dev_manage_menu.sql
-- 「客户端运维」改名为「开发管理」；隐藏「版本控制」「环境安装包」菜单

UPDATE `navigation_menus`
SET title='开发管理', icon='cpu', view='aiPlatform', subview='', sort_order=2, visible=1
WHERE id=820;

UPDATE `navigation_menus`
SET visible=0, sort_order=90
WHERE id IN (670, 700);

-- 开发管理下剩余叶子重排
UPDATE `navigation_menus` SET parent_id=820, title='AI开放平台', icon='cpu', view='aiPlatform', subview='', sort_order=1, visible=1 WHERE id=710;
UPDATE `navigation_menus` SET parent_id=820, title='AI提示词模板', icon='file-text', view='aiPromptTemplates', subview='', sort_order=2, visible=1 WHERE id=720;
UPDATE `navigation_menus` SET parent_id=820, title='AI记录', icon='activity', view='aiCallLogs', subview='', sort_order=3, visible=1 WHERE id=730;
