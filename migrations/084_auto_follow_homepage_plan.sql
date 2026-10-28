-- 自动增粉：主页浏览计划字段（搜索词、停留、观看数、点赞/评论概率、评论模板）

ALTER TABLE `auto_follow_tasks`
  ADD COLUMN `search_keyword` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '搜索关键词（默认目标用户名）' AFTER `max_interval_hours`,
  ADD COLUMN `stay_seconds_min` INT NOT NULL DEFAULT 8 COMMENT '视频停留秒数下限' AFTER `search_keyword`,
  ADD COLUMN `stay_seconds_max` INT NOT NULL DEFAULT 20 COMMENT '视频停留秒数上限' AFTER `stay_seconds_min`,
  ADD COLUMN `watch_video_count` INT NOT NULL DEFAULT 3 COMMENT '主页计划观看视频数' AFTER `stay_seconds_max`,
  ADD COLUMN `like_rate` INT NOT NULL DEFAULT 40 COMMENT '点赞概率 0-100' AFTER `watch_video_count`,
  ADD COLUMN `comment_rate` INT NOT NULL DEFAULT 20 COMMENT '评论概率 0-100' AFTER `like_rate`,
  ADD COLUMN `comment_mode` VARCHAR(16) NOT NULL DEFAULT 'ai' COMMENT 'fixed|ai' AFTER `comment_rate`,
  ADD COLUMN `comment_ai_template_id` INT NOT NULL DEFAULT 0 COMMENT 'AI评论模板ID' AFTER `comment_mode`,
  ADD COLUMN `comment_ai_prompt` TEXT NULL COMMENT 'AI评论提示词快照' AFTER `comment_ai_template_id`,
  ADD COLUMN `comment_text` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '固定评论文案兜底' AFTER `comment_ai_prompt`;

ALTER TABLE `auto_follow_task_items`
  ADD COLUMN `action_plan_json` MEDIUMTEXT NULL COMMENT '主页操作明细计划 JSON' AFTER `scheduled_at`;
