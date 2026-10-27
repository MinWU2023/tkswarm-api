-- 049_rename_comment_reply_menu.sql
-- 「回复评论」菜单改名为「视频评论」

UPDATE `navigation_menus`
SET `title`='视频评论'
WHERE `id`=440
   OR (`view`='publish' AND `subview`='comment_reply_wizard')
   OR (`title`='回复评论' AND `parent_id`=400);
