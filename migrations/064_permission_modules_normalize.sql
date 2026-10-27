-- 统一权限模块命名，并补充分组字典

UPDATE admin_permissions SET module='publish' WHERE module='matrix';
