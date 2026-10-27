-- 历史无归属素材：挂到首个超级管理员，避免全员可见
UPDATE materials m
SET owner_id = (
    SELECT MIN(u.id) FROM admin_users u WHERE u.role_id = 1 LIMIT 1
)
WHERE (m.owner_id IS NULL OR m.owner_id = 0)
  AND EXISTS (SELECT 1 FROM admin_users u WHERE u.role_id = 1);
