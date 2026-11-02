-- 素材标签：多话题拼接易超过 VARCHAR(255)，发布时读到截断结果导致标签缺失
ALTER TABLE `materials`
  MODIFY COLUMN `tags` TEXT NOT NULL;
