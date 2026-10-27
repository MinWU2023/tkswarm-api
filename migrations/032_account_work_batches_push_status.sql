-- 032_account_work_batches_push_status.sql
-- 推送状态：none未推送 / offered已推WSS待接单 / executing本机执行中

ALTER TABLE `account_work_batches`
  ADD COLUMN `push_status` VARCHAR(32) NOT NULL DEFAULT 'none' AFTER `status`,
  ADD KEY `idx_awb_push` (`push_status`, `status`);
