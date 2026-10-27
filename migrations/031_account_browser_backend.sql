-- 区分比特 / TkSwarm Browser 环境归属
ALTER TABLE accounts
  ADD COLUMN browser_backend VARCHAR(16) NOT NULL DEFAULT 'bit'
    COMMENT '当前主环境所属：bit|tkswarm' AFTER browser_profile_id,
  ADD COLUMN tkswarm_browser_profile_id VARCHAR(128) NOT NULL DEFAULT ''
    COMMENT '自研 TkSwarm Browser 环境 ID' AFTER browser_backend;

ALTER TABLE account_browser_envs
  ADD COLUMN browser_backend VARCHAR(16) NOT NULL DEFAULT 'bit'
    COMMENT 'bit|tkswarm' AFTER browser_type;
