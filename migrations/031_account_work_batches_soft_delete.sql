-- 031_account_work_batches_soft_delete.sql
-- 账号会话总任务软删除：删除后不再领取/执行，记录保留

ALTER TABLE `account_work_batches`
  ADD COLUMN `deleted_at` DATETIME NULL AFTER `finished_at`,
  ADD KEY `idx_awb_deleted` (`deleted_at`);
