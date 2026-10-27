-- 010_task_type_warm.sql
-- 自动养号任务类型字典（单语句，兼容迁移器）

INSERT IGNORE INTO `app_dicts` (`domain`, `code`, `label`, `sort_order`, `meta_json`)
VALUES ('task_type', 'warm', '自动养号', 4, '{}');
