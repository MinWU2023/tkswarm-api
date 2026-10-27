-- 041_comment_reply_wizard.sql
-- AI 矩阵下增加「视频评论」向导（与视频删除向导同级）

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (440, 400, '视频评论', 'message-circle', '', 'publish', 'comment_reply_wizard', 4, 1),
  (441, 440, '创建回复任务', 'message-circle', '', 'publish', 'create_comment_reply', 1, 1);
