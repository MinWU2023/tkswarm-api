-- 账号池管理：分组管理排到账号列表前面
UPDATE `navigation_menus` SET `sort_order`=1 WHERE `id`=114 OR (`view`='groups' AND `subview`='account' AND `parent_id`=110);
UPDATE `navigation_menus` SET `sort_order`=2 WHERE `id`=111 OR (`view`='accounts' AND `subview`='list' AND `parent_id`=110 AND `title`='账号列表');
UPDATE `navigation_menus` SET `sort_order`=3 WHERE `id`=112 OR (`view`='accounts' AND `subview`='io' AND `parent_id`=110);
UPDATE `navigation_menus` SET `sort_order`=4 WHERE `id`=115 OR (`view`='warm' AND `parent_id`=110);
UPDATE `navigation_menus` SET `sort_order`=5 WHERE `id`=116 OR (`view`='accountWork' AND `parent_id`=110);
