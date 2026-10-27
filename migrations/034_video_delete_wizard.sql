-- 034_video_delete_wizard.sql
-- 素材关联平台视频 ID；AI 矩阵增加「视频删除向导」菜单（列补齐也由 MaterialController::ensureSchema 负责）

ALTER TABLE `materials`
  ADD COLUMN `platform_video_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'TikTok 平台 video_id' AFTER `publish_account`;
ALTER TABLE `materials`
  ADD COLUMN `platform_video_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'TikTok 视频链接' AFTER `platform_video_id`;

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (430, 400, '视频删除向导', 'trash-2', '', 'publish', 'delete_wizard', 3, 1),
  (431, 430, '创建删除任务', 'trash-2', '', 'publish', 'create_delete', 1, 1);
