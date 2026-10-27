-- 036_interaction_template_content_mode.sql
-- 消息模板：一条记录要么是固定文案，要么是 AI 提示词（content_mode + content）

ALTER TABLE `interaction_templates`
  ADD COLUMN `content_mode` VARCHAR(16) NOT NULL DEFAULT 'fixed' COMMENT 'fixed|ai' AFTER `name`,
  ADD COLUMN `content` MEDIUMTEXT NULL COMMENT '固定文案或AI提示词正文' AFTER `content_mode`;

-- 旧双字段 → 单字段：有固定文案的保留为 fixed
UPDATE `interaction_templates`
SET `content_mode` = 'fixed',
    `content` = `fixed_text`
WHERE TRIM(IFNULL(`fixed_text`, '')) <> '';

-- 仅有 AI 提示词、无固定文案 → ai
UPDATE `interaction_templates`
SET `content_mode` = 'ai',
    `content` = `ai_prompt`
WHERE (TRIM(IFNULL(`fixed_text`, '')) = '')
  AND TRIM(IFNULL(`ai_prompt`, '')) <> '';

-- 两者都有：当前行保留固定文案；再插一条 AI 模板
INSERT INTO `interaction_templates`
  (`type`, `name`, `content_mode`, `content`, `fixed_text`, `ai_prompt`, `enabled`, `sort_order`, `is_builtin`, `owner_id`)
SELECT
  `type`,
  CONCAT(`name`, ' · AI'),
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
SET `content` = ''
WHERE `content` IS NULL;

ALTER TABLE `interaction_templates`
  MODIFY COLUMN `content` MEDIUMTEXT NOT NULL,
  ADD KEY `idx_it_mode` (`content_mode`);
