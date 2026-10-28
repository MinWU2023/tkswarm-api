-- 全部账号邮件推送改为默认关闭；已有开启的也统一为关闭（可在查看数据/批量编辑中再开）

ALTER TABLE `accounts`
  MODIFY COLUMN `data_mail_push` TINYINT NOT NULL DEFAULT 0 COMMENT '拉取数据后自动邮件推送：1开 0关（默认关）';

UPDATE `accounts` SET `data_mail_push` = 0 WHERE `data_mail_push` <> 0;
