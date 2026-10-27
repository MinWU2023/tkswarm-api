-- 038_agents_platform_id.sql
-- 智能体绑定 AI 开放平台；model_id 存平台模型 code

ALTER TABLE `agents`
  ADD COLUMN `platform_id` INT NULL AFTER `model_id`;

-- 若列已存在会报 Duplicate column，可忽略
