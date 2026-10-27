-- 029_account_work_menu.sql
-- 账号池管理下增加「任务汇总」菜单

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (116, 110, '任务汇总', 'list', '', 'accountWork', 'list', 5, 1);
