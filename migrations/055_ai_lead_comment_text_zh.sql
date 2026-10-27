-- 055_ai_lead_comment_text_zh.sql
-- AI拓客评论：增加中文译文字段

ALTER TABLE `ai_lead_comments`
  ADD COLUMN `comment_text_zh` TEXT NULL COMMENT '评论中文译文' AFTER `comment_text`;
