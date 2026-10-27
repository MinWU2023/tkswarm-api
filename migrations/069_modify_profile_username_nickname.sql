-- 修改资料：Username 冲突恢复 + 昵称 7 天限制记录
ALTER TABLE accounts
  ADD COLUMN nickname_changed_at DATETIME NULL DEFAULT NULL
  COMMENT '昵称上次成功修改或检测到7天限制的时间' AFTER profile_avatar;

ALTER TABLE account_work_items
  ADD COLUMN original_username VARCHAR(128) NULL DEFAULT NULL
  COMMENT '修改资料：原账号名称 Username' AFTER error_message,
  ADD COLUMN username_fail_reason TEXT NULL
  COMMENT '修改资料：账号名称修改失败原因' AFTER original_username,
  ADD COLUMN nickname_error TEXT NULL
  COMMENT '修改资料：昵称错误提示（如7天限制）' AFTER username_fail_reason,
  ADD COLUMN nickname_locked_at DATETIME NULL DEFAULT NULL
  COMMENT '修改资料：昵称触发7天仅改一次的时间' AFTER nickname_error;
