<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($title ?? 'TKSwarm 权限与角色管理') ?></title>
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/ui.css">
  <link rel="stylesheet" href="/theme-tech.css">
  <style>
    .rbac-container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
    .rbac-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--tech-border); padding-bottom: 10px; }
    .rbac-tab-btn { padding: 8px 18px; border-radius: var(--tech-radius-md); border: 1px solid transparent; background: transparent; cursor: pointer; font-size: 14px; font-weight: 500; color: var(--tech-text-muted); }
    .rbac-tab-btn.active { background: var(--tech-primary-soft); color: var(--tech-primary); border-color: rgba(37,99,235,0.2); font-weight: 600; }
    .node-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; background: #e0f2fe; color: #0284c7; }
  </style>
</head>
<body style="background: var(--tech-bg); min-height: 100vh;">
  <header class="navbar">
    <div class="logo">TKSwarm</div>
    <div style="font-weight: 600; font-size: 15px; color: #1e293b;">系统安全与 RBAC 权限中台</div>
    <div class="user-info" style="margin-left: auto;">
      <a href="/" class="ghost tiny" style="text-decoration:none; display:inline-flex; align-items:center; padding:6px 14px; border-radius:6px; border:1px solid #cbd5e1; color:#475569;">返回矩阵控制台</a>
    </div>
  </header>

  <div class="rbac-container">
    <div class="rbac-tabs">
      <button class="rbac-tab-btn active" onclick="switchTab('roles')">用户角色与赋权</button>
      <button class="rbac-tab-btn" onclick="switchTab('nodes')">权限节点列表</button>
      <button class="rbac-tab-btn" onclick="switchTab('users')">系统管理用户</button>
      <button class="rbac-tab-btn" onclick="switchTab('logs')">审计操作日志</button>
    </div>

    <!-- 角色管理 -->
    <div id="tab-roles" class="panel">
      <div class="panel-head">
        <h2>用户角色 (Roles)</h2>
        <span class="muted">配置系统角色及其挂接的权限节点清单</span>
      </div>
      <div style="padding: 20px;">
        <table class="table" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>角色名称</th>
              <th>描述</th>
              <th>状态</th>
              <th>已分配节点数</th>
              <th>创建时间</th>
            </tr>
          </thead>
          <tbody id="roles-tbody">
            <tr><td colspan="6" style="text-align:center;">加载中...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 权限节点 -->
    <div id="tab-nodes" class="panel" style="display:none;">
      <div class="panel-head">
        <h2>权限节点 (Permissions)</h2>
        <span class="muted">包含多模块、控制器、细粒度操作代码</span>
      </div>
      <div style="padding: 20px;">
        <table class="table" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>所属模块</th>
              <th>节点代码 (Code)</th>
              <th>节点名称</th>
              <th>排序</th>
            </tr>
          </thead>
          <tbody id="nodes-tbody">
            <tr><td colspan="5" style="text-align:center;">加载中...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 管理用户 -->
    <div id="tab-users" class="panel" style="display:none;">
      <div class="panel-head">
        <h2>系统管理员 (Admin Users)</h2>
        <span class="muted">可登录中台系统的后台运营与技术人员账号</span>
      </div>
      <div style="padding: 20px;">
        <table class="table" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>用户名</th>
              <th>昵称</th>
              <th>所属角色</th>
              <th>状态</th>
              <th>创建时间</th>
            </tr>
          </thead>
          <tbody id="users-tbody">
            <tr><td colspan="6" style="text-align:center;">加载中...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 操作日志 -->
    <div id="tab-logs" class="panel" style="display:none;">
      <div class="panel-head">
        <h2>操作日志 (Operation Logs)</h2>
        <span class="muted">记录用户在系统中调用的关键敏感指令与变动审计</span>
      </div>
      <div style="padding: 20px;">
        <table class="table" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>用户ID</th>
              <th>模块</th>
              <th>行为说明</th>
              <th>客户端IP</th>
              <th>记录时间</th>
            </tr>
          </thead>
          <tbody id="logs-tbody">
            <tr><td colspan="6" style="text-align:center;">加载中...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    function switchTab(tab) {
      document.querySelectorAll('.rbac-tab-btn').forEach(b => b.classList.remove('active'));
      event.target.classList.add('active');
      ['roles', 'nodes', 'users', 'logs'].forEach(t => {
        document.getElementById('tab-' + t).style.display = (t === tab ? 'block' : 'none');
      });
      if(tab === 'roles') loadRoles();
      if(tab === 'nodes') loadNodes();
      if(tab === 'users') loadUsers();
      if(tab === 'logs') loadLogs();
    }

    async function fetchApi(url) {
      const token = localStorage.getItem('tkswarm_token') || '';
      const res = await fetch(url, { headers: { 'Authorization': 'Bearer ' + token } });
      const json = await res.json();
      return json.data;
    }

    async function loadRoles() {
      const data = await fetchApi('/admin/roles/data');
      if(!data) return;
      document.getElementById('roles-tbody').innerHTML = data.map(r => `
        <tr>
          <td><b>#${r.id}</b></td>
          <td><b>${r.name}</b></td>
          <td>${r.description || '-'}</td>
          <td><span class="badge online">正常启用</span></td>
          <td><span class="node-badge">${JSON.parse(r.permissions || '[]').length} 个节点</span></td>
          <td>${r.created_at}</td>
        </tr>
      `).join('');
    }

    async function loadNodes() {
      const data = await fetchApi('/admin/permissions/data');
      if(!data) return;
      document.getElementById('nodes-tbody').innerHTML = data.map(n => `
        <tr>
          <td>#${n.id}</td>
          <td><span class="node-badge">${n.module}</span></td>
          <td><code>${n.code}</code></td>
          <td><b>${n.name}</b></td>
          <td>${n.sort_order}</td>
        </tr>
      `).join('');
    }

    async function loadUsers() {
      const data = await fetchApi('/admin/users/data');
      if(!data || !data.items) return;
      document.getElementById('users-tbody').innerHTML = data.items.map(u => `
        <tr>
          <td>#${u.id}</td>
          <td><b>${u.username}</b></td>
          <td>${u.nickname || '-'}</td>
          <td>角色 #${u.role_id}</td>
          <td><span class="badge online">正常</span></td>
          <td>${u.created_at}</td>
        </tr>
      `).join('');
    }

    async function loadLogs() {
      const data = await fetchApi('/admin/logs/data');
      if(!data || !data.items) return;
      document.getElementById('logs-tbody').innerHTML = data.items.map(l => `
        <tr>
          <td>#${l.id}</td>
          <td>用户 #${l.user_id}</td>
          <td><span class="node-badge">${l.module}</span></td>
          <td>${l.action}</td>
          <td>${l.ip}</td>
          <td>${l.created_at}</td>
        </tr>
      `).join('');
    }

    loadRoles();
  </script>
</body>
</html>
