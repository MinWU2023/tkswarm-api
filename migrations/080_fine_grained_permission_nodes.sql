-- 细粒度权限节点（列表/添加/编辑/删除）；运行时 PermissionCatalog::ensureSplitNodes 也会幂等同步并展开旧角色

UPDATE admin_permissions SET name='创建账号' WHERE code='account.create';
UPDATE admin_permissions SET name='添加代理' WHERE code='proxy.create';
UPDATE admin_permissions SET name='发布任务写入(兼容)' WHERE code='publish.tasks';
UPDATE admin_permissions SET name='群发任务写入(兼容)' WHERE code='publish.mass';
UPDATE admin_permissions SET name='素材管理写入(兼容)' WHERE code='publish.materials';
UPDATE admin_permissions SET name='养号任务写入(兼容)' WHERE code='warm.tasks';
UPDATE admin_permissions SET name='系统角色与权限（总控·兼容）' WHERE code='rbac.manage';
UPDATE admin_permissions SET module='publish' WHERE module='matrix';

INSERT INTO admin_permissions (id, module, code, name, sort_order) VALUES
  (100, 'account', 'account.update', '编辑账号', 4),
  (101, 'proxy', 'proxy.update', '编辑代理', 13),
  (102, 'proxy', 'proxy.delete', '删除代理', 14),
  (103, 'group', 'group.view', '查看分组', 50),
  (104, 'group', 'group.create', '添加分组', 51),
  (105, 'group', 'group.update', '编辑分组', 52),
  (106, 'group', 'group.delete', '删除分组', 53),
  (110, 'rbac', 'rbac.users.view', '查看管理用户', 110),
  (111, 'rbac', 'rbac.users.create', '添加管理用户', 111),
  (112, 'rbac', 'rbac.users.update', '编辑管理用户', 112),
  (113, 'rbac', 'rbac.users.delete', '删除管理用户', 113),
  (114, 'rbac', 'rbac.roles.view', '查看用户角色', 114),
  (115, 'rbac', 'rbac.roles.create', '添加用户角色', 115),
  (116, 'rbac', 'rbac.roles.update', '编辑用户角色', 116),
  (117, 'rbac', 'rbac.roles.delete', '删除用户角色', 117),
  (118, 'rbac', 'rbac.permissions.view', '查看权限节点', 118),
  (119, 'rbac', 'rbac.permissions.create', '添加权限节点', 119),
  (120, 'rbac', 'rbac.permissions.update', '编辑权限节点', 120),
  (121, 'rbac', 'rbac.permissions.delete', '删除权限节点', 121),
  (122, 'rbac', 'rbac.logs.view', '查看操作日志', 122),
  (123, 'rbac', 'rbac.logs.clear', '清空操作日志', 123),
  (124, 'rbac', 'rbac.menus.view', '查看后台菜单', 124),
  (125, 'rbac', 'rbac.menus.update', '编辑后台菜单', 125),
  (130, 'publish', 'publish.tasks.view', '查看发布任务', 130),
  (131, 'publish', 'publish.tasks.create', '创建发布任务', 131),
  (132, 'publish', 'publish.tasks.update', '编辑发布任务', 132),
  (133, 'publish', 'publish.tasks.delete', '删除发布任务', 133),
  (134, 'publish', 'publish.mass.view', '查看群发任务', 134),
  (135, 'publish', 'publish.mass.create', '创建群发任务', 135),
  (136, 'publish', 'publish.mass.update', '编辑群发任务', 136),
  (137, 'publish', 'publish.mass.delete', '删除群发任务', 137),
  (138, 'publish', 'publish.materials.view', '查看素材', 138),
  (139, 'publish', 'publish.materials.create', '上传/添加素材', 139),
  (140, 'publish', 'publish.materials.update', '编辑素材', 140),
  (141, 'publish', 'publish.materials.delete', '删除素材', 141),
  (142, 'publish', 'warm.view', '查看养号任务', 142),
  (143, 'publish', 'warm.create', '创建养号任务', 143),
  (144, 'publish', 'warm.update', '编辑养号任务', 144),
  (145, 'publish', 'warm.delete', '删除养号任务', 145),
  (146, 'chat', 'chat.view', '查看客服会话', 146),
  (147, 'chat', 'chat.update', '管理客服会话设置', 147),
  (148, 'agent', 'agent.create', '创建智能体', 148),
  (149, 'agent', 'agent.update', '编辑智能体', 149),
  (150, 'agent', 'agent.delete', '删除智能体', 150),
  (151, 'ai', 'ai.leads.view', '查看AI拓客', 151),
  (152, 'ai', 'ai.leads.create', '创建AI拓客', 152),
  (153, 'ai', 'ai.leads.update', '编辑AI拓客', 153),
  (154, 'ai', 'ai.leads.delete', '删除AI拓客', 154),
  (160, 'rbac', 'rbac.country_data.view', '查看国家数据', 160),
  (161, 'rbac', 'rbac.country_data.create', '添加国家数据', 161),
  (162, 'rbac', 'rbac.country_data.update', '编辑国家数据', 162),
  (163, 'rbac', 'rbac.country_data.delete', '删除国家数据', 163),
  (164, 'rbac', 'rbac.industry_data.view', '查看行业数据', 164),
  (165, 'rbac', 'rbac.industry_data.create', '添加行业数据', 165),
  (166, 'rbac', 'rbac.industry_data.update', '编辑行业数据', 166),
  (167, 'rbac', 'rbac.industry_data.delete', '删除行业数据', 167),
  (168, 'rbac', 'rbac.company_data.view', '查看企业数据', 168),
  (169, 'rbac', 'rbac.company_data.create', '添加企业数据', 169),
  (170, 'system', 'system.settings.view', '查看基础设置', 170),
  (171, 'system', 'system.settings.update', '保存基础设置', 171),
  (172, 'rbac', 'rbac.company_data.update', '编辑企业数据', 172),
  (173, 'rbac', 'rbac.company_data.delete', '删除企业数据', 173)
ON DUPLICATE KEY UPDATE
  module=VALUES(module), code=VALUES(code), name=VALUES(name), sort_order=VALUES(sort_order);
