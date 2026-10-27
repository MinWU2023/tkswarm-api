-- 024: 客户端版本支持外链下载地址（可不上传 zip）
ALTER TABLE `client_versions`
  ADD COLUMN `download_url` VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '包下载外链；有本地文件时优先本站下载' AFTER `description`;
