-- 035_interaction_templates.sql
-- 互动消息模板：评论 / 回复评论 / 私信 / 回复私信
-- 注意：036 起改为 content_mode(fixed|ai) + content 单字段；下列旧列兼容保留

CREATE TABLE IF NOT EXISTS `interaction_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `type` VARCHAR(32) NOT NULL COMMENT 'comment|comment_reply|dm|dm_reply',
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `fixed_text` TEXT NOT NULL COMMENT '旧：固定文案（见 content）',
  `ai_prompt` MEDIUMTEXT NOT NULL COMMENT '旧：AI提示词（见 content）',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
  `owner_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_it_type` (`type`),
  KEY `idx_it_owner` (`owner_id`),
  KEY `idx_it_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admin_permissions` (`id`, `module`, `code`, `name`, `sort_order`) VALUES
(76, 'account', 'account.msg_template', '管理消息模板（评论/私信）', 76);

-- 消息模板与「新建智能体」同级（parent=智能体管理 310）
INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (117, 310, '消息模板', 'message', '', 'msgTemplates', 'list', 3, 1);

UPDATE `navigation_menus`
SET `parent_id`=310, `title`='消息模板', `icon`='message', `view`='msgTemplates',
    `subview`='list', `sort_order`=3, `visible`=1
WHERE `id`=117 OR `view`='msgTemplates' OR `title`='消息模板';

UPDATE `navigation_menus` SET `sort_order`=1 WHERE `id`=114 AND `view`='groups';
UPDATE `navigation_menus` SET `sort_order`=5 WHERE `id`=116 AND `view`='accountWork';
