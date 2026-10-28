-- AI营销导航：新建二级「AI拓客」，原三项改名并下挂为其下级
-- AI拓客 → 拓客任务；AI线索 → 拓客线索；线索数据 → 拓客结果

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (356, 350, 'AI拓客', 'sparkles', '', 'aiLeads', 'list', 1, 1);

UPDATE `navigation_menus`
SET `parent_id`=356, `title`='AI拓客', `icon`='sparkles', `path`='', `view`='aiLeads', `subview`='list', `sort_order`=1, `visible`=1
WHERE `id`=356;

UPDATE `navigation_menus`
SET `parent_id`=356, `title`='拓客任务', `icon`='user-plus', `path`='', `view`='aiLeads', `subview`='list', `sort_order`=1, `visible`=1
WHERE `id`=351
   OR (`view`='aiLeads' AND `subview`='list' AND `title` IN ('AI拓客','拓客任务') AND `id`<>356 AND `parent_id` IN (350, 356));

UPDATE `navigation_menus`
SET `parent_id`=356, `title`='拓客线索', `icon`='users', `path`='', `view`='aiLeads', `subview`='clues', `sort_order`=2, `visible`=1
WHERE `id`=352
   OR (`view`='aiLeads' AND `subview`='clues');

UPDATE `navigation_menus`
SET `parent_id`=356, `title`='拓客结果', `icon`='database', `path`='', `view`='aiLeads', `subview`='clue_data', `sort_order`=3, `visible`=1
WHERE `id`=353
   OR (`view`='aiLeads' AND `subview`='clue_data');
