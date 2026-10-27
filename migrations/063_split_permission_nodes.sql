-- 将名称中用 / 分隔的多操作权限拆成独立节点（运行时 PermissionCatalog::ensureSplitNodes 也会幂等执行）

UPDATE admin_permissions SET name='创建账号' WHERE code='account.create';
UPDATE admin_permissions SET name='添加代理' WHERE code='proxy.create';
UPDATE admin_permissions SET name='发布任务写入' WHERE code='publish.tasks';

UPDATE admin_permissions SET code='account.msg_template.comment', name='管理评论模板'
WHERE id=76 OR code='account.msg_template';

UPDATE admin_permissions SET code='account.work.pause', name='暂停任务汇总'
WHERE id=86 OR code='account.work';

INSERT INTO admin_permissions (id, module, code, name, sort_order) VALUES
  (89, 'account', 'account.import', '导入账号', 2),
  (90, 'proxy', 'proxy.import', '批量导入代理', 11),
  (91, 'publish', 'warm.tasks', '养号任务写入', 21),
  (92, 'publish', 'publish.materials', '素材管理写入', 22),
  (94, 'account', 'account.work.delete', '删除任务汇总', 86),
  (95, 'account', 'account.work.reset', '重置任务汇总', 87),
  (97, 'account', 'account.msg_template.dm', '管理私信模板', 77)
ON DUPLICATE KEY UPDATE
  module=VALUES(module), code=VALUES(code), name=VALUES(name), sort_order=VALUES(sort_order);
