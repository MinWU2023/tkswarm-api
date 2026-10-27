<?php
declare(strict_types=1);

/**
 * 一键将账号会话总任务 / 子任务状态改回「待执行」(pending)
 * 浏览器访问：/reset-work-pending.php
 * 确认执行：/reset-work-pending.php?confirm=1
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
      .card{max-width:640px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px}
      h1{font-size:20px;margin:0 0 12px}
      p,li{line-height:1.6;color:#cbd5e1}
      code{background:#0f172a;padding:2px 6px;border-radius:4px}
      .ok{color:#4ade80}.err{color:#f87171}.muted{color:#94a3b8;font-size:13px}
      .btn{display:inline-block;margin-top:16px;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600}
      .btn-danger{background:#dc2626;color:#fff}.btn-ghost{background:#334155;color:#e2e8f0;margin-left:8px}
      table{width:100%;border-collapse:collapse;margin-top:16px;font-size:14px}
      th,td{border-bottom:1px solid #334155;padding:8px;text-align:left}
    </style></head><body><div class="card">';
    echo $body;
    echo '</div></body></html>';
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

$batchCount = (int)$pdo->query('SELECT COUNT(*) FROM account_work_batches')->fetchColumn();
$itemCount = (int)$pdo->query('SELECT COUNT(*) FROM account_work_items')->fetchColumn();
$batchByStatus = $pdo->query('SELECT status, COUNT(*) AS c FROM account_work_batches GROUP BY status ORDER BY c DESC')->fetchAll();
$itemByStatus = $pdo->query('SELECT status, COUNT(*) AS c FROM account_work_items GROUP BY status ORDER BY c DESC')->fetchAll();

if (!$confirm) {
    $rows = static function (array $list): string {
        if (!$list) {
            return '<tr><td colspan="2" class="muted">无数据</td></tr>';
        }
        $html = '';
        foreach ($list as $r) {
            $html .= '<tr><td><code>' . h((string)$r['status']) . '</code></td><td>' . (int)$r['c'] . '</td></tr>';
        }
        return $html;
    };

    page('重置为待执行', '
      <h1>总任务 / 子任务 → 待执行</h1>
      <p>将把全部 <code>account_work_batches</code>（总任务）与 <code>account_work_items</code>（子任务）状态改回 <code>pending</code>，并清掉锁、推送态、结束时间与软删除标记，便于重新领取执行。</p>
      <p class="muted">库：' . h($database) . ' · 总任务 ' . $batchCount . ' · 子任务 ' . $itemCount . '</p>
      <h2 style="font-size:15px;margin:20px 0 8px">当前总任务状态</h2>
      <table><thead><tr><th>status</th><th>数量</th></tr></thead><tbody>' . $rows($batchByStatus) . '</tbody></table>
      <h2 style="font-size:15px;margin:20px 0 8px">当前子任务状态</h2>
      <table><thead><tr><th>status</th><th>数量</th></tr></thead><tbody>' . $rows($itemByStatus) . '</tbody></table>
      <a class="btn btn-danger" href="?confirm=1" onclick="return confirm(\'确认将全部总任务/子任务改回待执行？\')">确认一键重置</a>
      <a class="btn btn-ghost" href="?">刷新统计</a>
    ');
    exit;
}

try {
    $pdo->beginTransaction();

    // 释放被总任务占用的浏览器环境
    $envReleased = 0;
    if ($pdo->query("SHOW TABLES LIKE 'account_browser_envs'")->fetchColumn()) {
        $envReleased = (int)$pdo->exec(
            "UPDATE account_browser_envs
             SET saturation='idle', locked_by='', batch_id=NULL, lease_until=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE batch_id IS NOT NULL OR saturation='busy'"
        );
    }

    $itemsUpdated = (int)$pdo->exec(
        "UPDATE account_work_items
         SET status='pending',
             error_message=NULL,
             started_at=NULL,
             finished_at=NULL,
             updated_at=CURRENT_TIMESTAMP"
    );

    $batchesUpdated = (int)$pdo->exec(
        "UPDATE account_work_batches
         SET status='pending',
             push_status='none',
             locked_by='',
             lease_until=NULL,
             browser_env_id=NULL,
             heartbeat_at=NULL,
             started_at=NULL,
             finished_at=NULL,
             deleted_at=NULL,
             last_error=NULL,
             item_success=0,
             item_fail=0,
             updated_at=CURRENT_TIMESTAMP"
    );

    // 关联业务任务一并回到可执行态（子任务 task_ref_id → tasks.id）
    $tasksUpdated = 0;
    $taskItemsUpdated = 0;
    $taskTable = $pdo->query("SHOW TABLES LIKE 'tasks'")->fetchColumn();
    if ($taskTable) {
        $tasksUpdated = (int)$pdo->exec(
            "UPDATE tasks t
             INNER JOIN (
               SELECT DISTINCT task_ref_id AS id FROM account_work_items WHERE task_ref_id > 0
             ) r ON r.id = t.id
             SET t.status='queued',
                 t.finished_at=NULL,
                 t.started_at=NULL,
                 t.updated_at=CURRENT_TIMESTAMP"
        );

        $ti = $pdo->query("SHOW TABLES LIKE 'task_items'")->fetchColumn();
        if ($ti) {
            $taskItemsUpdated = (int)$pdo->exec(
                "UPDATE task_items ti
                 INNER JOIN (
                   SELECT DISTINCT task_ref_id AS id FROM account_work_items WHERE task_ref_id > 0
                 ) r ON r.id = ti.task_id
                 SET ti.status='pending',
                     ti.error_message='',
                     ti.updated_at=CURRENT_TIMESTAMP"
            );
        }
    }

    $pdo->commit();

    page('重置完成', '
      <h1 class="ok">已全部改为待执行</h1>
      <ul>
        <li>总任务更新：<b>' . $batchesUpdated . '</b> / ' . $batchCount . '</li>
        <li>子任务更新：<b>' . $itemsUpdated . '</b> / ' . $itemCount . '</li>
        <li>关联 tasks → queued：<b>' . $tasksUpdated . '</b></li>
        <li>关联 task_items → pending：<b>' . $taskItemsUpdated . '</b></li>
        <li>浏览器环境释放（ROW_COUNT）：<b>' . (int)$envReleased . '</b></li>
      </ul>
      <p class="muted">请确保本机 Node 执行器已开启，总任务会重新被领取。</p>
      <a class="btn btn-ghost" href="?">查看当前状态</a>
    ');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    page('执行失败', '<h1 class="err">重置失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
}
