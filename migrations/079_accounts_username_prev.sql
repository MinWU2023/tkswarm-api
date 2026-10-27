-- 账号名改动手动保留原名
-- 场景：总任务里有「修改资料」分任务，把新 Username 写进了系统，但新名字还没在 TikTok 生效，
--       此时用新名字登录必然失败。登录必须回退到 TikTok 实际仍在使用的原账号名。
-- 说明：服务层已有 ensureUsernamePrevColumn() 自动补列，本迁移用于显式建表规范。
ALTER TABLE `accounts`
  ADD COLUMN `username_prev` VARCHAR(128) NULL DEFAULT NULL
  COMMENT '改资料前原账号名（TikTok 实际仍在用）' AFTER `username`;
