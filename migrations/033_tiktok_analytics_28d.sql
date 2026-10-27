-- Studio 拉取：近 28 天日指标（每次拉取先删后写）
-- 列补齐由 NodeBridge/AccountController ensureSchema 负责（兼容旧 MySQL）
CREATE TABLE IF NOT EXISTS tiktok_analytics_28d (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  metric_date DATE NOT NULL,
  video_views BIGINT NOT NULL DEFAULT 0,
  profile_views BIGINT NOT NULL DEFAULT 0,
  likes_count BIGINT NOT NULL DEFAULT 0,
  comments_count BIGINT NOT NULL DEFAULT 0,
  shares_count BIGINT NOT NULL DEFAULT 0,
  est_rewards DECIMAL(14,4) NOT NULL DEFAULT 0,
  pulled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tt_analytics_acc_date (account_id, metric_date),
  KEY idx_tt_analytics_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
