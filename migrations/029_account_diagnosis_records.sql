-- 账号诊断截图记录（仅保留每账号最近 3 条，由业务层裁剪）
CREATE TABLE IF NOT EXISTS account_diagnosis_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  screenshot_path VARCHAR(512) NOT NULL,
  label VARCHAR(64) NOT NULL DEFAULT 'account-check',
  source VARCHAR(32) NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_adr_account_created (account_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
