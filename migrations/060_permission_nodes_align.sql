-- 对齐前端权限码：补齐缺失节点，并兼容 matrix.* ↔ publish.*
-- 运行时 SeedMeta / ensure 也会幂等补齐

INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order) VALUES
  (79, 'system', 'system.ai_log', '查看AI调用记录', 79),
  (80, 'system', 'system.view', '查看系统设置', 80),
  (81, 'agent', 'agent.view', '查看AI智能体', 81),
  (82, 'agent', 'agent.manage', '管理AI智能体', 82),
  (83, 'matrix', 'publish.tasks', '发布任务写入', 83),
  (84, 'matrix', 'publish.mass', '群发与客服相关写入', 84),
  (85, 'account', 'account.export', '导出账号数据', 85),
  (86, 'account', 'account.work.pause', '暂停任务汇总', 86),
  (87, 'logs', 'logs.view', '查看实时操作日志', 87);

-- 兼容：若 id=76 被误写成 system.ai_log，补一条正确 id=79（76 留给 account.msg_template）
INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
SELECT 79, 'system', 'system.ai_log', '查看AI调用记录', 79
FROM admin_permissions WHERE code='system.ai_log' AND id=76 LIMIT 1;

-- 若历史上只有 matrix.*，把角色权限 ID 同步一份到 publish.*（按 code 匹配）
-- 说明：角色存的是 permission id 列表；此处仅保证 publish.* 节点存在，管理员需在角色里勾选；
-- 前端 canPermission 同时兼容 matrix.publish / publish.tasks。
