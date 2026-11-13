-- 账号类型：main=主体号，operation=运营号（默认主体号）
-- AccountController::ensureSchema() 也会在首次访问时自动补列，此处为显式迁移记录。
ALTER TABLE `accounts`
  ADD COLUMN `account_type` VARCHAR(16) NOT NULL DEFAULT 'main' COMMENT '账号类型：main主体号/operation运营号';
