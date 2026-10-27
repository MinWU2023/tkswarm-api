-- 076_ai_lead_optimization.sql
-- AI拓客优化：索引（列/索引由 ensureSchema 幂等补齐）

-- AiLeadVideoWeightService::ensureSchema / AiLeadExecutionService::ensureSchema 会执行：
--   ALTER TABLE ai_lead_records ADD INDEX idx_alr_account_status_time (account_id, status, updated_at);
--   ALTER TABLE ai_lead_records ADD INDEX idx_alr_status_username (status, username(64));
