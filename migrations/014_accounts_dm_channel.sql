-- 014: 账号私信通道 auto|api|browser（幂等：列已存在则忽略）
ALTER TABLE `accounts`
  ADD COLUMN `dm_channel` VARCHAR(16) NOT NULL DEFAULT 'auto';
