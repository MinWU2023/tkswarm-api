-- 008_account_group_parent.sql
-- 账号分组支持两级：一级大区/池 + 二级公司子组
-- 列由 GroupController::ensureSchema 运行时幂等补齐（避免重复 ALTER 导致迁移失败）。

SELECT 1 AS ok;
