-- 账号系统通知（TikTok Studio → 系统通知）+ 违规封停判定
-- 排重：同一账号下 (notice_hash) 唯一，notice_hash = sha1(规范化时间 + 内容)

CREATE TABLE IF NOT EXISTS account_notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  owner_id INT NOT NULL DEFAULT 0,
  notice_hash CHAR(40) NOT NULL,
  notice_key VARCHAR(191) NOT NULL DEFAULT '',
  category VARCHAR(32) NOT NULL DEFAULT 'general',
  title VARCHAR(512) NOT NULL DEFAULT '',
  content TEXT NULL,
  notice_time VARCHAR(64) NOT NULL DEFAULT '',
  noticed_at DATETIME NULL,
  matched_keywords VARCHAR(512) NOT NULL DEFAULT '',
  is_violation TINYINT(1) NOT NULL DEFAULT 0,
  ai_judged TINYINT(1) NOT NULL DEFAULT 0,
  ai_result_json MEDIUMTEXT NULL,
  raw_json MEDIUMTEXT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'studio',
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_an_acc_hash (account_id, notice_hash),
  KEY idx_an_acc_time (account_id, noticed_at),
  KEY idx_an_acc_violation (account_id, is_violation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 账号封禁状态：状态复用已有 accounts.account_status('normal'|'banned')，这里补原因/解封时间/判定信息
ALTER TABLE accounts ADD COLUMN ban_reason TEXT NULL;
ALTER TABLE accounts ADD COLUMN ban_until DATETIME NULL DEFAULT NULL;
ALTER TABLE accounts ADD COLUMN ban_checked_at DATETIME NULL DEFAULT NULL;
ALTER TABLE accounts ADD COLUMN ban_source VARCHAR(16) NOT NULL DEFAULT '';
ALTER TABLE accounts ADD COLUMN ban_ai_json MEDIUMTEXT NULL;
