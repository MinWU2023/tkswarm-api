-- 048_proxy_nav_under_accounts.sql
-- 去掉一级菜单「代理网络」，将「代理IP池」及其子项挂到「账号中心」下

-- 代理IP池：挂到账号中心（100），排在账号池管理之后
UPDATE `navigation_menus`
SET `parent_id`=100,
    `title`='代理IP池',
    `icon`='server',
    `path`='',
    `view`='proxies',
    `subview`='',
    `sort_order`=2,
    `visible`=1
WHERE `id`=210 OR (`title`='代理IP池' AND `view`='proxies');

-- 子项仍挂在代理IP池下
UPDATE `navigation_menus` SET `parent_id`=210, `sort_order`=1, `visible`=1 WHERE `id`=211 OR (`view`='proxies' AND `subview`='list' AND `title`='代理列表');
UPDATE `navigation_menus` SET `parent_id`=210, `sort_order`=2, `visible`=1 WHERE `id`=212 OR (`view`='proxies' AND `subview`='io');
UPDATE `navigation_menus` SET `parent_id`=210, `sort_order`=3, `visible`=1 WHERE `id`=213 OR (`view`='proxies' AND `subview`='create');

-- 环境与指纹、操作与审计顺延排序
UPDATE `navigation_menus` SET `sort_order`=3 WHERE `id`=120 OR (`parent_id`=100 AND `view`='accounts' AND `subview`='browser' AND `title`='环境与指纹');
UPDATE `navigation_menus` SET `sort_order`=4 WHERE `id`=130 OR (`parent_id`=100 AND `view`='logs' AND `title`='操作与审计');

-- 隐藏原一级「代理网络」
UPDATE `navigation_menus` SET `visible`=0 WHERE `id`=200 OR (`parent_id` IS NULL AND `title`='代理网络');
