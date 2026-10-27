-- 039_msg_templates_nav_under_agent.sql
-- 消息模板菜单：与「新建智能体」同级，挂在「智能体管理」(310) 下

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (117, 310, '消息模板', 'message', '', 'msgTemplates', 'list', 3, 1);

UPDATE `navigation_menus`
SET `parent_id`=310,
    `title`='消息模板',
    `icon`='message',
    `path`='',
    `view`='msgTemplates',
    `subview`='list',
    `sort_order`=3,
    `visible`=1
WHERE `id`=117 OR `view`='msgTemplates' OR `title`='消息模板';
