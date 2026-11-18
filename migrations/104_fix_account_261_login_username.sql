-- 104_fix_account_261_login_username.sql
-- 账号 #261：TikTok 上真实账号名仍是 user84586512313815，
-- 但系统里的 Username 已被「修改资料」改成 Yogamats，而 TikTok 并未真正改成功。
-- 目标状态（与 AccountWorkBatchService::resolveLoginUsername 的当前逻辑一致）：
--   accounts.username      = 'Yogamats'            （系统里保留新名）
--   accounts.username_prev = 'user84586512313815'  （TikTok 实际仍在用的原名，登录使用它）
-- 之后只有「修改资料」任务保存后在 TikTok 回读确认新名生效，才会清掉 username_prev 改用新名登录。
-- 幂等：重复执行结果相同；账号 261 不存在或 username 已不是 Yogamats 时不做任何改动。
-- 前置：accounts.username_prev 列由 079_accounts_username_prev.sql 创建。

UPDATE `accounts`
   SET `username_prev` = 'user84586512313815',
       `updated_at` = CURRENT_TIMESTAMP
 WHERE `id` = 261
   AND LOWER(TRIM(LEADING '@' FROM `username`)) = 'yogamats';

-- 执行后可手动核对：SELECT id, username, username_prev FROM accounts WHERE id = 261;
-- 期望：username=Yogamats, username_prev=user84586512313815
