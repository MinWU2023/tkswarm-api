<?php
declare(strict_types=1);

/**
 * 紧急终止养号常态化派发
 * ---------------------------------------------------------------
 * 浏览器访问：/stop-warm-recurring-dispatch.php
 * 确认执行：/stop-warm-recurring-dispatch.php?confirm=1
 *
 * 做的事：
 *  1) 全部养号任务 recurring_dispatch_enabled=0（cron warm:recurring 不再重置入队）
 *  2) 取消队列中 pending/running 的 warm 子任务
 *  3) 收尾已无活跃子项的批次
 *
 * 不做的事：不清空养号任务 / payload 常态化配置 / 执行计划。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

$confirm = isset($_GET['confirm']) && (string)$_GET['confirm'] === '1';

$configFile = is_file(__DIR__ . '/config/database.php')
    ? __DIR__ . '/config/database.php'
    : dirname(__DIR__) . '/config/database.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>
      body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:32px}
      .card{max-width:760px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px}
      h1{font-size:20px;margin:0 0 12px} h2{font-size:15px;margin:20px 0 8px;color:#93c5fd}
      p,li{line-height:1.6;color:#cbd5e1}
      code{background:#0f172a;padding:2px 6px;border-radius:4px}
      .ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}.muted{color:#94a3b8;font-size:13px}
      .btn{display:inline-block;margin-top:16px;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600}
      .btn-danger{background:#dc2626;color:#fff}.btn-ghost{background:#334155;color:#e2e8f0;margin-left:8px}
      table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px}
      th,td{border-bottom:1px solid #334155;padding:8px;text-align:left}
    </style></head><body><div class="card">';
    echo $body;
    echo '</div></body></html>';
}

function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

if (!is_file($configFile)) {
    page('配置缺失', '<h1 class="err">数据库配置不存在</h1><p>未找到 <code>' . h($configFile) . '</code></p>');
    exit;
}

$db = require $configFile;
$host = (string)($db['host'] ?? '127.0.0.1');
$port = (int)($db['port'] ?? 3306);
$database = (string)($db['database'] ?? 'tkswarm');
$username = (string)($db['username'] ?? 'root');
$password = (string)($db['password'] ?? '');
$charset = (string)($db['charset'] ?? 'utf8mb4');

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    page('连接失败', '<h1 class="err">数据库连接失败</h1><pre class="err">' . h($e->getMessage()) . '</pre>');
    exit;
}

$hasTasks = tableExists($pdo, 'tasks');
$hasItems = tableExists($pdo, 'account_work_items');
$hasBatches = tableExists($pdo, 'account_work_batches');
$hasDispCol = $hasTasks && columnExists($pdo, 'tasks', 'recurring_dispatch_enabled');

$warmTotal = 0;
$dispatchOn = 0;
$endedWarm = 0;
$recurringHint = 0;
$pendingWork = 0;
$runningWork = 0;

if ($hasTasks) {
    $warmTotal = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE type='warm'")->fetchColumn();
    $endedWarm = (int)$pdo->query(
        "SELECT COUNT(*) FROM tasks WHERE type='warm' AND status IN ('completed','failed')"
    )->fetchColumn();
    if ($hasDispCol) {
        $dispatchOn = (int)$pdo->query(
            "SELECT COUNT(*) FROM tasks WHERE type='warm' AND COALESCE(recurring_dispatch_enabled,1)=1"
        )->fetchColumn();
    } else {
        $dispatchOn = $warmTotal;
    }
    // payload 含 recurring 的粗略统计（JSON 文本匹配）
    $recurringHint = (int)$pdo->query(
        "SELECT COUNT(*) FROM tasks
         WHERE type='warm'
           AND (payload LIKE '%\"recurring\":true%' OR payload LIKE '%\"recurring\":1%'
                OR payload LIKE '%\"isRecurring\":true%' OR payload LIKE '%\"isRecurring\":1%')"
    )->fetchColumn();
}
if ($hasItems) {
    $pendingWork = (int)$pdo->query(
        "SELECT COUNT(*) FROM account_work_items WHERE task_type='warm' AND status='pending'"
    )->fetchColumn();
    $runningWork = (int)$pdo->query(
        "SELECT COUNT(*) FROM account_work_items WHERE task_type='warm' AND status='running'"
    )->fetchColumn();
}

if (!$confirm) {
    page('终止养号常态化派发', '
      <h1 class="warn">紧急终止养号常态化派发</h1>
      <p>crontab 若仍在跑 <code>php artisan warm:recurring</code>，已结束且勾选常态化的养号会按间隔重新入队。
      本工具关闭全部养号任务的常态化派发开关，并取消队列中的 <code>warm</code> 子任务。</p>
      <h2>当前快照</h2>
      <table><thead><tr><th>项</th><th>数量</th></tr></thead><tbody>
        <tr><td>养号任务总数</td><td><b>' . $warmTotal . '</b></td></tr>
        <tr><td>已结束（completed/failed）</td><td><b>' . $endedWarm . '</b></td></tr>
        <tr><td>payload 含常态化标记（约）</td><td><b>' . $recurringHint . '</b></td></tr>
        <tr><td>常态化派发开关开启</td><td><b>' . $dispatchOn . '</b>' . ($hasDispCol ? '' : ' <span class="muted">(列尚未创建)</span>') . '</td></tr>
        <tr><td>队列 pending warm</td><td><b>' . $pendingWork . '</b></td></tr>
        <tr><td>队列 running warm</td><td><b>' . $runningWork . '</b></td></tr>
      </tbody></table>
      <h2>执行后</h2>
      <ul>
        <li>全部养号任务 <code>recurring_dispatch_enabled=0</code></li>
        <li>取消全部 pending/running 的 <code>warm</code></li>
        <li>收尾对应批次；保留任务与常态化配置，可在列表重新「开常态」</li>
      </ul>
      <p class="muted">注意：会取消当前队列中所有养号分项（含非常态化一次性养号）。库：' . h($database) . '</p>
      <a class="btn btn-danger" href="?confirm=1" onclick="return confirm(\'确认立即终止全部养号常态化派发，并取消队列中的 warm 子任务？\')">确认终止派发</a>
      <a class="btn btn-ghost" href="?">刷新统计</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
    exit;
}

try {
    if ($hasTasks && !$hasDispCol) {
        $pdo->exec(
            "ALTER TABLE tasks ADD COLUMN recurring_dispatch_enabled TINYINT(1) NOT NULL DEFAULT 1
             COMMENT '养号常态化派发开关'"
        );
        $hasDispCol = true;
    }

    $tasksDisabled = 0;
    if ($hasTasks && $hasDispCol) {
        $tasksDisabled = (int)$pdo->exec(
            "UPDATE tasks
             SET recurring_dispatch_enabled=0, updated_at=CURRENT_TIMESTAMP
             WHERE type='warm' AND COALESCE(recurring_dispatch_enabled,1)=1"
        );
    }

    $batchIds = [];
    if ($hasItems) {
        $batchIds = $pdo->query(
            "SELECT DISTINCT batch_id FROM account_work_items
             WHERE task_type='warm' AND status IN ('pending','running') AND batch_id IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    $itemsCancelled = 0;
    if ($hasItems) {
        $itemsCancelled = (int)$pdo->exec(
            "UPDATE account_work_items
             SET status='cancelled',
                 finished_at=CURRENT_TIMESTAMP,
                 updated_at=CURRENT_TIMESTAMP,
                 error_message='紧急终止：养号常态化派发已关闭'
             WHERE task_type='warm' AND status IN ('pending','running')"
        );
    }

    $batchesClosed = 0;
    if ($hasBatches && $batchIds) {
        foreach ($batchIds as $bid) {
            $bid = (int)$bid;
            if ($bid < 1) {
                continue;
            }
            $active = (int)$pdo->query(
                "SELECT COUNT(*) FROM account_work_items
                 WHERE batch_id={$bid} AND status IN ('pending','running')"
            )->fetchColumn();
            if ($active > 0) {
                continue;
            }
            $batchesClosed += (int)$pdo->exec(
                "UPDATE account_work_batches
                 SET status=IF(item_fail>0 AND item_success<1,'failed','completed'),
                     locked_by='', lease_until=NULL, push_status='none',
                     finished_at=COALESCE(finished_at,CURRENT_TIMESTAMP),
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id={$bid} AND status IN ('pending','running','paused')"
            );
        }
    }

    page('终止完成', '
      <h1 class="ok">已终止养号常态化派发</h1>
      <ul>
        <li>关闭常态化派发开关任务数：<b>' . $tasksDisabled . '</b></li>
        <li>取消 warm 子任务：<b>' . $itemsCancelled . '</b></li>
        <li>收尾批次：<b>' . $batchesClosed . '</b></li>
      </ul>
      <p class="muted">可在养号任务列表对已配置常态化的任务重新「开常态」。</p>
      <a class="btn btn-ghost" href="?">查看当前状态</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
} catch (Throwable $e) {
    page('执行失败', '<h1 class="err">终止失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
}
