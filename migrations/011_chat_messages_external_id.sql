-- 011: 私信同步去重用平台消息 ID（幂等：列已存在则忽略）
ALTER TABLE `chat_messages`
  ADD COLUMN `external_id` VARCHAR(191) NULL DEFAULT NULL;
