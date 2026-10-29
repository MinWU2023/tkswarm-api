<?php
declare(strict_types=1);

/**
 * 紧急终止 AI 拓客私信常态派发
 * ---------------------------------------------------------------
 * 浏览器访问：/stop-ai-lead-dm-dispatch.php
 * 确认执行：/stop-ai-lead-dm-dispatch.php?confirm=1
 *
 * 做的事：
 *  1) 给全部拓客任务写 dm_dispatch_enabled=0（cron 不再入队）
 *  2) 取消队列中 pending/running 的 ai_lead_dm 子任务
 *  3) 收尾已无活跃子项的批次
 *
 * 不做的事：不清空线索 / 拓客任务 / 已发送记录。
 *
 * 说明：清空任务数据后若仍在派发，通常是因为系统 crontab 仍在跑
 * `php artisan ai-lead:dm-dispatch`，且库里还有 status=active 的拓客任务与
 * 待发评论；本脚本关闭派发开关并清空队列即可立刻止血。
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

$hasLeads = tableExists($pdo, 'ai_leads');
$hasItems = tableExists($pdo, 'account_work_items');
$hasBatches = tableExists($pdo, 'account_work_batches');
$hasDmCol = $hasLeads && columnExists($pdo, 'ai_leads', 'dm_dispatch_enabled');

$activeLeads = 0;
$dispatchOn = 0;
$pendingDm = 0;
$runningDm = 0;
$activeLeadsWithComments = 0;

if ($hasLeads) {
    $activeLeads = (int)$pdo->query("SELECT COUNT(*) FROM ai_leads WHERE status='active'")->fetchColumn();
    if ($hasDmCol) {
        $dispatchOn = (int)$pdo->query(
            'SELECT COUNT(*) FROM ai_leads WHERE COALESCE(dm_dispatch_enabled,1)=1'
        )->fetchColumn();
    } else {
        $dispatchOn = $activeLeads;
    }
    if (tableExists($pdo, 'ai_lead_comments')) {
        $activeLeadsWithComments = (int)$pdo->query(
            "SELECT COUNT(DISTINCT cm.lead_id) FROM ai_lead_comments cm
             INNER JOIN ai_leads l ON l.id=cm.lead_id AND l.status='active'
             WHERE cm.ai_pass=1 AND cm.username<>''"
        )->fetchColumn();
    }
}
if ($hasItems) {
    $pendingDm = (int)$pdo->query(
        "SELECT COUNT(*) FROM account_work_items WHERE task_type='ai_lead_dm' AND status='pending'"
    )->fetchColumn();
    $runningDm = (int)$pdo->query(
        "SELECT COUNT(*) FROM account_work_items WHERE task_type='ai_lead_dm' AND status='running'"
    )->fetchColumn();
}

if (!$confirm) {
    page('终止 AI 拓客私信派发', '
      <h1 class="warn">紧急终止私信常态派发</h1>
      <p>清空任务数据后若仍在派发，是因为服务器 crontab 仍在执行 <code>php artisan ai-lead:dm-dispatch</code>，
      只要库里还有 <code>status=active</code> 的拓客任务 + 待发评论，就会不断往队列塞 <code>ai_lead_dm</code>。</p>
      <h2>当前快照</h2>
      <table><thead><tr><th>项</th><th>数量</th></tr></thead><tbody>
        <tr><td>active 拓客任务</td><td><b>' . $activeLeads . '</b></td></tr>
        <tr><td>私信派发开关开启（dm_dispatch_enabled=1）</td><td><b>' . $dispatchOn . '</b>' . ($hasDmCol ? '' : ' <span class="muted">(列尚未创建，将按全部开启处理)</span>') . '</td></tr>
        <tr><td>有待发评论的 active 任务</td><td><b>' . $activeLeadsWithComments . '</b></td></tr>
        <tr><td>队列 pending ai_lead_dm</td><td><b>' . $pendingDm . '</b></td></tr>
        <tr><td>队列 running ai_lead_dm</td><td><b>' . $runningDm . '</b></td></tr>
      </tbody></table>
      <h2>执行后</h2>
      <ul>
        <li>全部任务 <code>dm_dispatch_enabled=0</code></li>
        <li>取消全部 pending/running 的 <code>ai_lead_dm</code></li>
        <li>收尾对应批次；不清空线索与已发送记录</li>
      </ul>
      <p class="muted">库：' . h($database) . '</p>
      <a class="btn btn-danger" href="?confirm=1" onclick="return confirm(\'确认立即终止全部 AI 拓客私信派发？\')">确认终止派发</a>
      <a class="btn btn-ghost" href="?">刷新统计</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
    exit;
}

try {
    if ($hasLeads && !$hasDmCol) {
        $pdo->exec(
            "ALTER TABLE ai_leads ADD COLUMN dm_dispatch_enabled TINYINT(1) NOT NULL DEFAULT 1
             COMMENT '是否允许私信派发' AFTER dm_message"
        );
        $hasDmCol = true;
    }

    $leadsDisabled = 0;
    if ($hasLeads && $hasDmCol) {
        $leadsDisabled = (int)$pdo->exec(
            'UPDATE ai_leads
             SET dm_dispatch_enabled=0, updated_at=CURRENT_TIMESTAMP
             WHERE COALESCE(dm_dispatch_enabled,1)=1'
        );
    }

    $batchIds = [];
    if ($hasItems) {
        $batchIds = $pdo->query(
            "SELECT DISTINCT batch_id FROM account_work_items
             WHERE task_type='ai_lead_dm' AND status IN ('pending','running') AND batch_id IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    $itemsCancelled = 0;
    if ($hasItems) {
        $itemsCancelled = (int)$pdo->exec(
            "UPDATE account_work_items
             SET status='cancelled',
                 finished_at=CURRENT_TIMESTAMP,
                 updated_at=CURRENT_TIMESTAMP,
                 error_message='紧急终止：私信派发已关闭'
             WHERE task_type='ai_lead_dm' AND status IN ('pending','running')"
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
      <h1 class="ok">已终止 AI 拓客私信派发</h1>
      <ul>
        <li>关闭派发开关任务数：<b>' . $leadsDisabled . '</b></li>
        <li>取消 ai_lead_dm 子任务：<b>' . $itemsCancelled . '</b></li>
        <li>收尾批次：<b>' . $batchesClosed . '</b></li>
      </ul>
      <p class="muted">若 Node 正在执行中的浏览器窗口仍在发信，需等当前这条结束；之后不会再入队。可在拓客任务页按任务重新打开「私信派发」。</p>
      <a class="btn btn-ghost" href="?">查看当前状态</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
} catch (Throwable $e) {
    page('执行失败', '<h1 class="err">终止失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
}
