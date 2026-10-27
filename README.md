# TKSwarm PHP8.2+ & MySQL 企业级 MVC 框架 API 架构

面向 **PHP 8.2+** 与 **MySQL 5.8 / 8.0+** 的高性能原生 MVC 企业级中台程序，完整重构并替换了接口层、数据库层与鉴权机制，全面支持多模块、多控制器、多视图、多路由与 RBAC 权限中台。

---

## 一、目录结构与架构规范

```text
tkswarm-api/
├── public/                     # Web 服务器唯一公开入口根目录 (DocumentRoot)
│   ├── index.php               # 原生 MVC 核心入口调度文件
│   ├── install.php             # 可视化 MySQL 数据库安装向导
│   └── .htaccess               # Apache/Nginx 优雅 URL 重写配置
├── src/
│   ├── Core/                   # 框架底层核心引擎
│   │   ├── App.php             # 核心应用容器、错误与异常捕获
│   │   ├── Request.php         # HTTP 请求封装类
│   │   ├── Response.php        # JSON / HTML / 重定向响应封装类
│   │   ├── Router.php          # 类似 Laravel 风格的高性能正则路由器
│   │   ├── Controller.php      # 基础控制器基类
│   │   ├── View.php            # 原生高效模板视图渲染类
│   │   ├── Database/
│   │   │   ├── DB.php          # 高性能 PDO 单例与事务管理类
│   │   │   └── QueryBuilder.php# 类似 ThinkPHP / Laravel 流式链式构造器
│   │   ├── Schedule/
│   │   │   ├── Command.php     # 命令行/计划任务抽象类
│   │   │   └── Scheduler.php   # 计划任务调度器与命令分发引擎
│   │   └── Auth/
│   │       └── RBAC.php        # 细粒度角色权限判断与操作日志记录器
│   ├── Controllers/            # 多模块业务控制器
│   │   ├── Api/                # RESTful API 接口模块
│   │   │   ├── AuthController.php    # 登录/登出/鉴权状态
│   │   │   ├── AccountController.php # 账号列表/增删/状态统计
│   │   │   ├── ProxyController.php   # 代理池管理与连通记录
│   │   │   └── MenuController.php    # 三级级联导航动态构建树
│   │   └── Admin/              # 管理后台模块
│   │       └── PermissionController.php # 权限节点/角色赋权/管理员/审计日志
│   ├── Middlewares/            # 中间件层
│   │   ├── CorsMiddleware.php  # 跨域控制
│   │   └── AuthMiddleware.php  # Token/Session RBAC 拦截
│   └── Views/                  # 多视图模板层
│       └── admin/
│           └── rbac.php        # 权限与角色中台多页面独立视图
├── config/                     # 配置中心
│   ├── app.php                 # 系统运行参数
│   └── database.php            # MySQL 数据库连接配置
├── migrations/                 # 数据库迁移 SQL 文件库
│   ├── 001_initial_tables.sql  # 核心业务表结构定义
│   └── 002_seed_initial_data.sql # 预置管理员、角色、三级菜单
├── routes/
│   └── web.php                 # 路由规则集中配置文件
├── artisan                     # CLI 命令行调度入口文件
├── migrate.php                 # 数据库自动化迁移命令行工具
└── autoload.php                # PSR-4 标准自动加载器
```

---

## 二、部署与运行方式

### 1. 数据库向导安装 (Web Install)
在浏览器中直接访问安装向导：
`http://你的域名或IP/install.php`
填写数据库地址、账号密码及管理员信息，向导将自动执行建库、全量数据迁移并生成 `install.lock`。

### 2. 数据库命令行迁移 (CLI Migrate)
在终端执行：
```bash
php migrate.php
```
将自动按版本顺序执行 `migrations/` 下未被应用的 SQL 脚本。

> **注意**：以 `migrations/` + `php migrate.php` 为准。根目录 `install.sql` 可能滞后，新环境请走安装向导或 migrate，勿单独依赖旧 `install.sql`。迁移 `011–014` 已改为幂等；旧聊天表结构由 `019_chat_schema_parity.sql` 升级到 `friend_uid` / `direction`。

### 3. CLI 计划任务调度 (Artisan & Commands)
- 查看可用任务：
  ```bash
  php artisan list
  ```
- 扫描并执行排队任务（Task Runner）：
  ```bash
  php artisan task:run
  ```
- 全量代理连通性诊断与探测（Proxy Probe）：
  ```bash
  php artisan proxy:probe
  ```
- API 模式私信同步入队（按消息设置间隔，需本机 Node 执行 `dm_sync`）：
  ```bash
  php artisan dm:sync
  ```
  建议 cron 每分钟：`* * * * * cd /path/to/tkswarm-api && php artisan dm:sync`
- 常态化自动养号（已成功/失败且到期则重新入队并挂账号总任务）：
  ```bash
  php artisan warm:recurring
  ```
  建议 cron 每小时：`0 * * * * cd /path/to/tkswarm-api && php artisan warm:recurring >> /var/log/tkswarm-warm-recurring.log 2>&1`
- AI 拓客私信调度（按账号视频激活权重与间隔独立推送，需 Node 执行 `ai_lead_dm`）：
  ```bash
  php artisan ai-lead:daily-quota      # 每日刷新日额度
  php artisan ai-lead:dm-dispatch      # 每分钟扫描并入队待发私信
  php artisan ai-lead:backfill-video-activation  # 存量：回填首次发视频时间
  php artisan ai-lead:sync-stats       # 刷新任务列表中的待发/已发统计
  ```
  建议 cron：
  - `5 0 * * *` → `ai-lead:daily-quota`
  - `* * * * *` → `ai-lead:dm-dispatch`
  详见 [`docs/ai-lead-optimization-checklist.md`](../docs/ai-lead-optimization-checklist.md)

### 4. 翻译接口
- `GET /api/translate/config` — 模式/配额（Key 脱敏）
- `POST /api/translate` — `{ text, from, to }` 普通或 AI 翻译
- `POST /api/translate/detect` — 语言检测

### 5. 粉丝 / 私信同步
- `POST /api/chat/accounts/{id}/refresh-fans` — 创建 `sync_fans` 任务（Node+比特拉粉丝列表）
- `GET /api/chat/accounts/{id}/refresh-fans/status` — 进度
- 消息设置：`privateMessageSync` + `messageSyncMode=api` + `messageSyncInterval`（分钟）

通过内置的多路由与视图渲染，直接访问权限管理页面：
`http://你的域名或IP/admin/rbac`
包含：
1. **角色管理 (Roles)**：角色创建、权限节点分配、状态切换；
2. **权限节点 (Permissions)**：细粒度权限节点查看；
3. **管理用户 (Users)**：管理员账号与所属角色分配；
4. **审计日志 (Logs)**：记录用户关键行为与客户端 IP。
