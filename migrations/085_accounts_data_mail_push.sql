-- 账号「查看数据」邮件推送开关：控制拉取数据成功后是否自动入队 AI 分析发信计划任务
-- 1=开启 0=关闭（默认关闭，需在查看数据弹框或批量编辑中手动开启）

ALTER TABLE `accounts`
  ADD COLUMN `data_mail_push` TINYINT NOT NULL DEFAULT 0 COMMENT '拉取数据后自动邮件推送：1开 0关（默认关）' AFTER `nickname_changed_at`;
