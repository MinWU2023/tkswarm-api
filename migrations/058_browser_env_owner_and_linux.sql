-- 浏览器环境：三类型 bit|tkswarm|linux + 归属登录用户（admin_users.id）
-- 自研桌面 / Linux 与比特共用同一环境表；登录账号即 admin_users（两浏览器通用）

ALTER TABLE account_browser_envs
  ADD COLUMN owner_user_id INT NOT NULL DEFAULT 0
    COMMENT '所属管理员 admin_users.id（与 API 登录用户一致）' AFTER account_id;

ALTER TABLE account_browser_envs
  MODIFY COLUMN browser_backend VARCHAR(16) NOT NULL DEFAULT 'bit'
    COMMENT 'bit=比特 tkswarm=自研桌面 linux=Linux Browser';

ALTER TABLE account_browser_envs
  MODIFY COLUMN browser_type VARCHAR(32) NOT NULL DEFAULT 'bit'
    COMMENT '与 browser_backend 对齐：bit|tkswarm|linux';

-- 从 TikTok 账号 owner_id 回填环境归属
UPDATE account_browser_envs e
INNER JOIN accounts a ON a.id = e.account_id
SET e.owner_user_id = COALESCE(a.owner_id, 0)
WHERE e.owner_user_id = 0 AND a.owner_id IS NOT NULL AND a.owner_id > 0;

ALTER TABLE accounts
  ADD COLUMN linux_browser_profile_id VARCHAR(128) NOT NULL DEFAULT ''
    COMMENT 'Linux Browser 环境 ID' AFTER tkswarm_browser_profile_id;

ALTER TABLE accounts
  MODIFY COLUMN browser_backend VARCHAR(16) NOT NULL DEFAULT 'bit'
    COMMENT '当前主环境：bit|tkswarm|linux';

-- 历史 tkswarm 行：browser_type 与 backend 对齐
UPDATE account_browser_envs
SET browser_type = browser_backend
WHERE browser_backend IN ('tkswarm', 'linux')
  AND (browser_type = '' OR browser_type = 'bit');

CREATE INDEX idx_abe_owner ON account_browser_envs (owner_user_id);
CREATE INDEX idx_abe_backend ON account_browser_envs (browser_backend);
