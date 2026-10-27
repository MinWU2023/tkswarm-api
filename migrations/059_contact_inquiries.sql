-- 官网「联系我们」留资
CREATE TABLE IF NOT EXISTS contact_inquiries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL DEFAULT '',
  company VARCHAR(128) NOT NULL DEFAULT '',
  phone VARCHAR(64) NOT NULL DEFAULT '',
  email VARCHAR(128) NOT NULL DEFAULT '',
  account_scale VARCHAR(32) NOT NULL DEFAULT '' COMMENT '账号规模档位',
  message TEXT NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'landing',
  page_url VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'new' COMMENT 'new|processing|done|spam',
  remark VARCHAR(500) NOT NULL DEFAULT '' COMMENT '后台跟进备注',
  ip VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  handled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ci_status_created (status, created_at),
  KEY idx_ci_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
