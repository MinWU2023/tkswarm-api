-- 账号 ↔ 比特环境多对多；环境饱和态 idle|busy
CREATE TABLE IF NOT EXISTS account_browser_envs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  browser_type VARCHAR(32) NOT NULL DEFAULT 'bit',
  browser_profile_id VARCHAR(128) NOT NULL,
  saturation VARCHAR(16) NOT NULL DEFAULT 'idle' COMMENT 'idle=待使用 busy=执行中',
  locked_by VARCHAR(128) NOT NULL DEFAULT '',
  batch_id INT NULL,
  lease_until DATETIME NULL,
  label VARCHAR(64) NOT NULL DEFAULT '',
  is_primary TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_abe_profile (browser_profile_id),
  KEY idx_abe_account_sat (account_id, saturation),
  KEY idx_abe_lease (saturation, lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 总任务记录本次租用的环境
ALTER TABLE account_work_batches
  ADD COLUMN browser_env_id INT NULL AFTER account_id;

-- 从旧列回填（幂等）
INSERT IGNORE INTO account_browser_envs (account_id, browser_profile_id, is_primary, saturation)
SELECT id, browser_profile_id, 1, 'idle'
FROM accounts
WHERE browser_profile_id IS NOT NULL AND TRIM(browser_profile_id) <> '';
