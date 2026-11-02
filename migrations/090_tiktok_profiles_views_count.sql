-- 账号终身浏览量（拉取数据任务从主页视频汇总写入）
-- 幂等：列已存在时忽略错误（亦可由 NodeBridge/AccountController ensureSchema 补齐）
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tiktok_profiles' AND COLUMN_NAME = 'views_count'
);
SET @sql := IF(
  @exists = 0,
  'ALTER TABLE tiktok_profiles ADD COLUMN views_count BIGINT NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
