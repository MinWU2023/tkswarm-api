-- 环境包缺文件自愈：标记已推送 / 已补齐，避免重复入队
-- 运行时 ensureSchema 也会自动补列；本文件供手工迁移

ALTER TABLE `account_browser_envs`
  ADD COLUMN `pack_heal_status` VARCHAR(16) NOT NULL DEFAULT '' AFTER `pack_host_id`;

ALTER TABLE `account_browser_envs`
  ADD COLUMN `pack_heal_at` DATETIME NULL AFTER `pack_heal_status`;
