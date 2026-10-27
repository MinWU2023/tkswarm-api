# 拉取数据成功 → AI 分析邮件计划任务

Studio「拉取数据」(`pull_stats`) 成功后，系统自动入队计划任务：汇总账号指标 → 调用 AI 提示词分析 → 按 HTML 邮件模板发送给绑定管理用户 / 企业用户邮箱。

---

## 1. 触发条件

入口：`AccountWorkBatchService::finishItem`

同时满足：

1. 工作项 `task_type === 'pull_stats'`
2. 状态 `status === 'completed'`
3. 账号存在有效收件邮箱（见下节）

入队方法：

```php
\App\Services\PlanTask\PullDataAnalysisService::enqueueFromWorkItem($workItemRow);
```

- 同一 `work_item_id` 只会入队一次（表唯一键 `uk_plan_tasks_type_work_item`）
- 无邮箱时**跳过入队**（不写失败任务）
- 入队异常只写 `error_log`，**不阻断**拉取成功回写

---

## 2. 收件人规则

解析服务：`PlanTaskRecipientResolver::resolveForAccount($accountId)`

| 来源 | 规则 |
|------|------|
| 直接绑定管理用户 | `accounts.owner_id` → `admin_users.email` |
| 绑定企业用户 | `accounts.company_id` → `company_users` → `admin_users.email` |

- 仅启用用户（`status=1`）
- 邮箱须合法；大小写不敏感去重
- **不使用** `companies.contact_email`

管理用户邮箱在「权限管理 → 管理用户」编辑页填写「通知邮箱」。

---

## 3. 数据表

迁移：`migrations/081_pull_data_analysis_plan.sql`  
运行时也会 `ensureSchema` 幂等建表。

### `admin_users.email`

通知邮箱，可空。

### `mail_templates`

| 字段 | 说明 |
|------|------|
| `code` | 模板标识，内置 `pull_data_analysis_report` |
| `subject` / `html_body` | 支持 `{{var}}` 占位符 |
| `variables_json` | 变量声明 |
| `is_builtin` | 内置模板可被目录刷新 |

### `plan_tasks`

| 字段 | 说明 |
|------|------|
| `type` | 固定 `pull_data_analysis` |
| `status` | `pending` / `running` / `completed` / `failed` / `skipped` |
| `account_id` / `work_item_id` / `batch_id` | 溯源 |
| `recipients_json` | 去重后的收件人列表 |
| `payload_json` | 拉取指标快照 |
| `ai_prompt_code` | 默认 `pull_data_analysis` |
| `mail_template_code` | 默认 `pull_data_analysis_report` |
| `ai_result_json` / `ai_raw_text` | AI 结果 |
| `mail_results_json` | 逐封发送结果 |

### AI 提示词

复用表 `ai_prompt_templates`，内置 code：`pull_data_analysis`  
种子：`AiPromptCatalog::definitions()`

变量：`account`、`period`、`metrics_json`、`now`  
期望输出 JSON：`summary` / `strengths` / `issues` / `actions` / `score`

---

## 4. 处理流水线

```
pending → running → AI 分析 → 渲染 HTML 邮件 → 逐邮箱 SMTP 发送 → completed|failed|skipped
```

核心类：

| 类 | 职责 |
|----|------|
| `PullDataAnalysisService` | 入队、采数、tick、processOne |
| `PlanTaskRecipientResolver` | 邮箱解析 |
| `MailTemplateCatalog` | HTML 模板种子与渲染 |
| `MailService` | SMTP 发信 |
| `AiClient` + `AiPromptHelper` | AI 调用 |

采数表：`tiktok_profiles`、`tiktok_analytics_28d`、`tiktok_studio_metrics`、`tiktok_studio_content`

---

## 5. 命令行用法

在 `tkswarm-api` 目录：

```bash
# 批量处理 pending（默认 limit=5）
php artisan plan-task:pull-analysis

# 指定数量
php artisan plan-task:pull-analysis --limit=20

# 按任务 ID 执行（pending/failed）
php artisan plan-task:pull-analysis --id=12

# 强制重跑（忽略当前非 pending/failed 限制时配合业务；failed/pending 均可）
php artisan plan-task:pull-analysis --id=12 --force
```

### crontab（推荐每分钟）

```cron
* * * * * cd /path/to/tkswarm-api && php artisan plan-task:pull-analysis --limit=10 >> /var/log/tkswarm-pull-analysis.log 2>&1
```

### 代码内调用

```php
use App\Services\PlanTask\PullDataAnalysisService;

// 扫描队列
$result = PullDataAnalysisService::tick(10);

// 处理单条
$result = PullDataAnalysisService::processOne(12, true);

// 手动入队（需完整 work item 行，含 id/account_id/task_type=pull_stats）
PullDataAnalysisService::enqueueFromWorkItem([
    'id' => 1001,
    'account_id' => 88,
    'batch_id' => 50,
    'task_type' => 'pull_stats',
    'task_ref_id' => 0,
]);
```

---

## 6. SMTP 配置

系统设置 →「邮件 SMTP」，或直接写 `settings` 表：

| key | 示例 |
|-----|------|
| `smtp.host` | `smtp.exmail.qq.com` |
| `smtp.port` | `465` |
| `smtp.encryption` | `ssl` / `tls` / `none` |
| `smtp.username` | 登录用户 |
| `smtp.password` | 授权码 |
| `smtp.from_email` | 发件地址 |
| `smtp.from_name` | `TkSwarm` |

未配置 SMTP 时任务会 `failed`，错误信息提示缺少配置。

---

## 7. 排查清单

1. **未入队**：账号无 `owner_id`/`company_id`，或对应用户未填 `email`
2. **一直 pending**：未挂 crontab / 未执行 `plan-task:pull-analysis`
3. **AI 失败**：检查 AI 开放平台启用配置与 `ai_call_logs`
4. **邮件失败**：检查 SMTP；看 `plan_tasks.mail_results_json` / `error_message`
5. **重复入队**：同一 `work_item_id` 会被唯一键挡住（返回 already_enqueued）

查任务：

```sql
SELECT id, status, account_id, work_item_id, error_message, created_at, finished_at
FROM plan_tasks
WHERE type='pull_data_analysis'
ORDER BY id DESC
LIMIT 20;
```

---

## 8. 相关文件

- `src/Services/PlanTask/PullDataAnalysisService.php`
- `src/Services/PlanTask/PlanTaskRecipientResolver.php`
- `src/Services/Mail/MailService.php`
- `src/Services/Mail/MailTemplateCatalog.php`
- `src/Commands/PullDataAnalysisCommand.php`
- `src/Services/Ai/AiPromptCatalog.php`（`pull_data_analysis`）
- `src/Services/AccountWorkBatchService.php`（finishItem 钩子）
- `migrations/081_pull_data_analysis_plan.sql`
