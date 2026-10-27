-- 006_agents_node_parity.sql
-- 将早期简版 agents 表升级为与 Node schema 对齐

ALTER TABLE `agents`
  MODIFY COLUMN `name` VARCHAR(191) NOT NULL,
  MODIFY COLUMN `api_base` VARCHAR(512) NOT NULL DEFAULT '',
  MODIFY COLUMN `api_key` VARCHAR(512) NOT NULL DEFAULT '';

-- 以下列若不存在由 AgentController::ensureAgentsSchema 运行时补齐；
-- 迁移环境已有库时手动执行对应 ADD COLUMN（忽略 Duplicate column 错误）:

-- ALTER TABLE agents ADD COLUMN description VARCHAR(512) NOT NULL DEFAULT '';
-- ALTER TABLE agents ADD COLUMN avatar VARCHAR(512) NOT NULL DEFAULT '';
-- ALTER TABLE agents ADD COLUMN enabled TINYINT NOT NULL DEFAULT 1;
-- ALTER TABLE agents ADD COLUMN model_type VARCHAR(64) NOT NULL DEFAULT 'builtin';
-- ALTER TABLE agents ADD COLUMN model_id VARCHAR(128) NOT NULL DEFAULT 'builtin';
-- ALTER TABLE agents ADD COLUMN temperature DOUBLE NOT NULL DEFAULT 0.7;
-- ALTER TABLE agents ADD COLUMN reply_tone VARCHAR(64) NOT NULL DEFAULT 'friendly';
-- ALTER TABLE agents ADD COLUMN reply_language VARCHAR(32) NOT NULL DEFAULT 'zh';
-- ALTER TABLE agents ADD COLUMN role_info TEXT NULL;
-- ALTER TABLE agents ADD COLUMN mission TEXT NULL;
-- ALTER TABLE agents ADD COLUMN rules TEXT NULL;
-- ALTER TABLE agents ADD COLUMN openings_json MEDIUMTEXT NULL;
-- ALTER TABLE agents ADD COLUMN success_terminate_json MEDIUMTEXT NULL;
-- ALTER TABLE agents ADD COLUMN fail_terminate_json MEDIUMTEXT NULL;
-- ALTER TABLE agents ADD COLUMN poll_json MEDIUMTEXT NULL;
