-- 047_clear_task_and_account_work_data.sql
-- 清空账号会话总任务 / 分任务，以及关联的业务任务主表与明细（不可逆）

SET FOREIGN_KEY_CHECKS = 0;

-- 会话总任务链路：日志 → 分任务 → 总任务
TRUNCATE TABLE `account_work_logs`;
TRUNCATE TABLE `account_work_items`;
TRUNCATE TABLE `account_work_batches`;

-- 业务任务主表链路（task_ref_id 等指向 tasks.id）
TRUNCATE TABLE `task_action_results`;
TRUNCATE TABLE `task_plans`;
TRUNCATE TABLE `task_events`;
TRUNCATE TABLE `task_runs`;
TRUNCATE TABLE `task_items`;
TRUNCATE TABLE `tasks`;

SET FOREIGN_KEY_CHECKS = 1;
