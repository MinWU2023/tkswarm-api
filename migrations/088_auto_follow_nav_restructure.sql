-- AI营销导航：新建二级「AI增粉」，原两项改名并下挂为其下级
-- 自动增粉任务 → 增粉任务；创建增粉任务 → 创建任务

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (357, 350, 'AI增粉', 'user-plus', '', 'autoFollow', 'list', 2, 1);

UPDATE `navigation_menus`
SET `parent_id`=350, `title`='AI增粉', `icon`='user-plus', `path`='', `view`='autoFollow', `subview`='list', `sort_order`=2, `visible`=1
WHERE `id`=357;

UPDATE `navigation_menus`
SET `parent_id`=357, `title`='增粉任务', `icon`='user-plus', `path`='', `view`='autoFollow', `subview`='list', `sort_order`=1, `visible`=1
WHERE `id`=354
   OR (`view`='autoFollow' AND `subview`='list' AND `title` IN ('自动增粉任务','增粉任务') AND `id`<>357);

UPDATE `navigation_menus`
SET `parent_id`=357, `title`='创建任务', `icon`='plus', `path`='', `view`='autoFollow', `subview`='create', `sort_order`=2, `visible`=1
WHERE `id`=355
   OR (`view`='autoFollow' AND `subview`='create');
