-- 操作审计日志增加请求路由字段，便于前端「请求路由」列展示
ALTER TABLE `admin_logs`
  ADD COLUMN `path` VARCHAR(255) NOT NULL DEFAULT '' AFTER `action`;
