-- 发布/群发等任务名称：旧库 VARCHAR(100) 易因素材长标题拼接超长而 SQLSTATE[22001]
ALTER TABLE `tasks`
  MODIFY COLUMN `name` VARCHAR(255) NOT NULL;
