-- 077_rename_pull_comments_menu.sql
-- 「拉取评论任务」菜单改名为「拉取视频数据」

UPDATE `navigation_menus`
SET `title`='拉取视频数据'
WHERE `id`=442
   OR (`view`='publish' AND `subview`='pull_comments')
   OR `title` IN ('拉取评论任务', '拉取评论');

UPDATE `navigation_menus`
SET `title`='创建拉取视频数据'
WHERE `id`=443
   OR (`view`='publish' AND `subview`='create_pull_comments')
   OR `title` IN ('创建拉取任务', '创建拉取评论');
