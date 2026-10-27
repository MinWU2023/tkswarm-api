-- 056_account_work_batches_batch_scope.sql
-- AI 拓客独立总任务与普通总任务分离

ALTER TABLE `account_work_batches`
  ADD COLUMN `batch_scope` VARCHAR(32) NOT NULL DEFAULT 'general'
    COMMENT 'general|ai_lead'
    AFTER `owner_id`,
  ADD KEY `idx_awb_account_scope` (`account_id`, `batch_scope`, `status`);
