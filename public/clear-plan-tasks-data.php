<?php
declare(strict_types=1);

/**
 * 清空计划任务及由其挂载的会话总/子任务（不可逆）
 * ---------------------------------------------------------------
 * 浏览器访问：/clear-plan-tasks-data.php
 * 确认执行：/clear-plan-tasks-data.php?confirm=1
 *
 * 背景：
 *  「任务汇总」里的批量清除只软删 account_work_*。
 *  父级 tasks 里 status=queued 的发布 / 私信同步 / 养号等还在；
 *  Node 右下角开关一开 → claim → attachDueQueuedTasks → 又挂出新的总任务。
 *  本系统任务队列在 MySQL，没有 Redis 任务队列；要止血必须清 tasks（及会话表）。
 *
 * 清空：
 *  - tasks / task_*（矩阵计划任务及明细）
 *  - account_work_*（会话总任务与子任务）
 *  - plan_tasks（若存在）
 *
 * 不清空：
 *  - 账号 / 代理 / 素材 / 用户权限
 *  - AI拓客 / 增粉 / 拉评主数据（请用「清空全部任务数据」或各自终止工具）
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

$confirm = isset($_GET['confirm']) && (string)$_GET['confirm'] === '1';

$configFile = is_file(__DIR__ . '/config/database.php')
    ? __DIR__ . '/config/database.php'
    : dirname(__DIR__) . '/config/database.php';

$groups = [
    '会话总任务 / 子任务（开关开启后会重新挂载的目标）' => [
        'account_work_logs',
        'account_work_items',
        'account_work_batches',
    ],
    '计划任务（发布 / 私信同步 / 养号 / 改资料等父任务）' => [
        'task_action_results',
        'task_plans',
        'task_events',
        'task_runs',
        'task_items',
        'tasks',
    ],
    '后台计划配置' => [
        'plan_tasks',
    ],
];

$tables = [];
foreach ($groups as $list) {
    foreach ($list as $t) {
        if (!in_array($t, $tables, true)) {
            $tables[] = $t;
        }
    }
}

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
      .card{max-width:860px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px}
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

function tableCount(PDO $pdo, string $table): ?int
{
    if (!tableExists($pdo, $table)) {
        return null;
    }
    return (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
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

$queuedByType = [];
if (tableExists($pdo, 'tasks')) {
    $queuedByType = $pdo->query(
        "SELECT type, COUNT(*) AS c FROM tasks
         WHERE status IN ('queued','running','paused')
         GROUP BY type ORDER BY c DESC"
    )->fetchAll() ?: [];
}

$counts = [];
$totalRows = 0;
foreach ($tables as $t) {
    $c = tableCount($pdo, $t);
    $counts[$t] = $c;
    if ($c !== null) {
        $totalRows += $c;
    }
}

if (!$confirm) {
    $typeRows = '';
    if ($queuedByType) {
        foreach ($queuedByType as $r) {
            $typeRows .= '<tr><td><code>' . h((string)$r['type']) . '</code></td><td><b>' . (int)$r['c'] . '</b></td></tr>';
        }
    } else {
        $typeRows = '<tr><td colspan="2" class="muted">当前无 queued/running/paused 的计划任务</td></tr>';
    }

    $sections = '';
    foreach ($groups as $label => $list) {
        $rowsHtml = '';
        foreach ($list as $t) {
            $c = $counts[$t] ?? null;
            if ($c === null) {
                $rowsHtml .= '<tr><td><code>' . h($t) . '</code></td><td class="muted">表不存在</td></tr>';
            } else {
                $rowsHtml .= '<tr><td><code>' . h($t) . '</code></td><td><b>' . $c . '</b></td></tr>';
            }
        }
        $sections .= '<h2>' . h($label) . '</h2><table><thead><tr><th>表</th><th>当前行数</th></tr></thead><tbody>'
            . $rowsHtml . '</tbody></table>';
    }

    page('清空计划任务数据', '
      <h1 class="warn">清空计划任务 + 会话总/子任务</h1>
      <p>任务汇总里「批量清除」只软删会话；父级 <code>tasks</code> 仍是 <code>queued</code> 时，
      右下角执行开关一开就会再次挂出「私信同步 / 发布视频」等。本项目<strong>没有 Redis 任务队列</strong>，源头在 MySQL。</p>
      <h2>仍可被重新挂载的计划任务（按类型）</h2>
      <table><thead><tr><th>type</th><th>queued/running/paused</th></tr></thead><tbody>' . $typeRows . '</tbody></table>
      <p class="muted">库：' . h($database) . ' · 将 TRUNCATE 约 <b>' . $totalRows . '</b> 行（不可逆）</p>
      ' . $sections . '
      <a class="btn btn-danger" href="?confirm=1" onclick="return confirm(\'确认永久清空计划任务与会话总/子任务？不可撤销！\')">确认清空计划任务数据</a>
      <a class="btn btn-ghost" href="?">刷新统计</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
    exit;
}

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $envReleased = 0;
    if (tableExists($pdo, 'account_browser_envs')) {
        $envReleased = (int)$pdo->exec(
            "UPDATE account_browser_envs
             SET saturation='idle', locked_by='', batch_id=NULL, lease_until=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE batch_id IS NOT NULL OR saturation='busy' OR locked_by<>''"
        );
    }

    $results = [];
    foreach ($tables as $t) {
        if (!tableExists($pdo, $t)) {
            $results[$t] = 'skip (missing)';
            continue;
        }
        $before = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn();
        $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $t) . '`');
        $results[$t] = 'truncated ' . $before . ' → 0';
    }

    // 阻止 crontab dm:sync 立刻给所有账号重新 INSERT（清空后 last_synced 间隔仍到期）
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS settings (
          `key` VARCHAR(100) NOT NULL PRIMARY KEY,
          `value` TEXT NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $st = $pdo->prepare(
        'INSERT INTO settings(`key`, `value`) VALUES (?,?)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=CURRENT_TIMESTAMP'
    );
    $st->execute(['dmSyncDispatchEnabled', 'false']);
    $syncedBump = 0;
    if (tableExists($pdo, 'chat_connections')) {
        // 旧版 dm:sync 认 sync_messages；未部署新代码时也能挡住立刻重建
        $syncedBump = (int)$pdo->exec(
            'UPDATE chat_connections
             SET sync_messages=0, last_synced_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP'
        );
    }
    if (tableExists($pdo, 'user_settings')) {
        $pdo->exec(
            "UPDATE user_settings SET `value`='false', updated_at=CURRENT_TIMESTAMP
             WHERE `key`='privateMessageSync'"
        );
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $list = '';
    foreach ($results as $t => $msg) {
        $list .= '<li><code>' . h($t) . '</code>：' . h($msg) . '</li>';
    }

    page('清空完成', '
      <h1 class="ok">已清空计划任务与会话数据</h1>
      <ul>' . $list . '</ul>
      <p class="muted">浏览器环境任务锁释放：' . $envReleased
        . '；已关闭 <code>dmSyncDispatchEnabled</code>；刷新 last_synced_at：' . $syncedBump
        . '。若仍要跑私信同步，请到快捷操作「终止私信同步派发」页点重新开启。</p>
      <a class="btn btn-ghost" href="?">查看当前状态</a>
      <a class="btn btn-ghost" href="/stop-dm-sync-dispatch.php">私信同步派发控制</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
} catch (Throwable $e) {
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignore) {
    }
    page('执行失败', '<h1 class="err">清空失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
}
