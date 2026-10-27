-- 054_ai_lead_intent_score.sql
-- AI拓客：意向评论/用户增加 1～10 分意向程度
-- 兼容 MySQL（不使用 ADD COLUMN IF NOT EXISTS）

ALTER TABLE `ai_lead_comments`
  ADD COLUMN `intent_score` TINYINT NOT NULL DEFAULT 0 COMMENT '意向程度1-10' AFTER `ai_reason`;

ALTER TABLE `ai_lead_creators`
  ADD COLUMN `intent_score` TINYINT NOT NULL DEFAULT 0 COMMENT '意向程度1-10（取该用户最高分评论）' AFTER `status`;

ALTER TABLE `ai_lead_comments`
  ADD KEY `idx_alc_intent` (`lead_id`, `ai_pass`, `intent_score`);

ALTER TABLE `ai_lead_creators`
  ADD KEY `idx_alc_creator_intent` (`lead_id`, `intent_score`);
