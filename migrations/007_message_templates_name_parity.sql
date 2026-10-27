-- 007_message_templates_name_parity.sql
-- 话术表旧字段 title/shortcut；业务统一 name/variables/enabled。
-- 列升级由 MessageTemplateController / Node message-templates 运行时补齐。
-- 本文件仅登记迁移版本，避免 SELECT 触发 PDO 未缓冲结果集错误。

SET @tkswarm_migration_007 := 1;
