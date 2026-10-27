-- TikTok Studio 数据分析（Analytics）四页签采集
-- Overview / Content / Followers / Viewers
-- 与运行时 ensureSchema() / ensureTikTokTables() 中的 DDL 保持一致，二者都带 IF NOT EXISTS，谁先跑都可以

-- 每个页签一行：页签名、页面地址、指标数、抓到的整页可见文本
CREATE TABLE IF NOT EXISTS tiktok_studio_tabs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  tab_key VARCHAR(64) NOT NULL,
  tab_label VARCHAR(191) NOT NULL DEFAULT '',
  page_url TEXT NULL,
  metric_count INT NOT NULL DEFAULT 0,
  raw_text MEDIUMTEXT NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tt_studio_tab (account_id, tab_key),
  KEY idx_tt_studio_tab_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 归一化指标：一项一行，前端按 tab_key 分组直接渲染
CREATE TABLE IF NOT EXISTS tiktok_studio_metrics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  tab_key VARCHAR(64) NOT NULL,
  metric_key VARCHAR(96) NOT NULL,
  metric_label VARCHAR(191) NOT NULL DEFAULT '',
  value_num BIGINT NOT NULL DEFAULT 0,
  value_text VARCHAR(64) NOT NULL DEFAULT '',
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tt_studio_metric (account_id, tab_key, metric_key),
  KEY idx_tt_studio_metric_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Content 页签的逐条内容数据
CREATE TABLE IF NOT EXISTS tiktok_studio_content (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  row_no INT NOT NULL DEFAULT 0,
  title VARCHAR(512) NOT NULL DEFAULT '',
  views_num BIGINT NOT NULL DEFAULT 0,
  likes_num BIGINT NOT NULL DEFAULT 0,
  comments_num BIGINT NOT NULL DEFAULT 0,
  shares_num BIGINT NOT NULL DEFAULT 0,
  posted_at VARCHAR(64) NOT NULL DEFAULT '',
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tt_studio_content_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 每次拉取的现场信息（页面地址 / 页签数 / 接口数 / 成功失败原因），排错用
CREATE TABLE IF NOT EXISTS tiktok_studio_sync (
  account_id INT PRIMARY KEY,
  page_url TEXT NULL,
  tab_count INT NOT NULL DEFAULT 0,
  api_count INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'ok',
  message TEXT NULL,
  meta_json MEDIUMTEXT NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 抓到的原始接口 JSON（最近 10 条），解析不准时可据此精确回补
CREATE TABLE IF NOT EXISTS tiktok_studio_api (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  url TEXT NULL,
  payload MEDIUMTEXT NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tt_studio_api_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
