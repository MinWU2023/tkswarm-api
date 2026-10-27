-- 分组管理：从账号池管理(110)下移到账号中心(100)同级
UPDATE `navigation_menus`
SET `parent_id` = 100, `sort_order` = 2, `icon` = 'folder', `visible` = 1
WHERE `id` = 114 OR (`view` = 'groups' AND `parent_id` = 110);

UPDATE `navigation_menus` SET `sort_order` = 1 WHERE `id` = 110;
UPDATE `navigation_menus` SET `sort_order` = 3 WHERE `id` = 210 OR (`parent_id` = 100 AND `view` = 'proxies' AND `title` = '代理IP池');
UPDATE `navigation_menus` SET `sort_order` = 4 WHERE `id` = 120 OR (`parent_id` = 100 AND `view` = 'accounts' AND `subview` = 'browser');
UPDATE `navigation_menus` SET `sort_order` = 5 WHERE `id` = 130 OR (`parent_id` = 100 AND `view` = 'logs');
