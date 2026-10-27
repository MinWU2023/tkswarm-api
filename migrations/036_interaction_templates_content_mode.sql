-- 036_interaction_templates_content_mode.sql
-- 一条模板要么是固定文案，要么是 AI 提示词（content_mode + content）

ALTER TABLE `interaction_templates`
  ADD COLUMN `content_mode` VARCHAR(16) NOT NULL DEFAULT 'fixed' COMMENT 'fixed|ai' AFTER `name`,
  ADD COLUMN `content` MEDIUMTEXT NULL AFTER `content_mode`;

-- 迁移旧双字段：有固定文案 → fixed；仅有 AI → ai
UPDATE `interaction_templates`
SET `content_mode` = 'fixed',
    `content` = `fixed_text`
WHERE TRIM(IFNULL(`fixed_text`, '')) <> '';

UPDATE `interaction_templates`
SET `content_mode` = 'ai',
    `content` = `ai_prompt`
WHERE TRIM(IFNULL(`fixed_text`, '')) = ''
  AND TRIM(IFNULL(`ai_prompt`, '')) <> '';

-- 双字段都有内容：原行保留固定文案，再插一条 AI 模板
INSERT INTO `interaction_templates`
  (`type`, `name`, `content_mode`, `content`, `fixed_text`, `ai_prompt`, `enabled`, `sort_order`, `is_builtin`, `owner_id`)
SELECT
  `type`,
  CONCAT(`name`, ' (AI)'),
  'ai',
  `ai_prompt`,
  '',
  `ai_prompt`,
  `enabled`,
  `sort_order` + 100,
  `is_builtin`,
  `owner_id`
FROM `interaction_templates`
WHERE TRIM(IFNULL(`fixed_text`, '')) <> ''
  AND TRIM(IFNULL(`ai_prompt`, '')) <> '';

UPDATE `interaction_templates`
SET `fixed_text` = IF(`content_mode` = 'fixed', IFNULL(`content`, ''), ''),
    `ai_prompt` = IF(`content_mode` = 'ai', IFNULL(`content`, ''), '')
WHERE `content` IS NOT NULL;

ALTER TABLE `interaction_templates`
  MODIFY COLUMN `content` MEDIUMTEXT NOT NULL,
  ADD KEY `idx_it_content_mode` (`content_mode`);
