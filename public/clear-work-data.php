<?php
declare(strict_types=1);

/**
 * 一键清空全部账号的各类任务及衍生关联表（不可逆）
 * 浏览器访问：/clear-work-data.php
 * 确认执行：/clear-work-data.php?confirm=1
 *
 * 不清空：账号 / 代理 / 素材主数据 / 用户权限等。
 * 会释放浏览器环境上的任务锁（saturation→idle）。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

$confirm = isset($_GET['confirm']) && (string)$_GET['confirm'] === '1';

$configFile = is_file(__DIR__ . '/config/database.php')
    ? __DIR__ . '/config/database.php'
    : dirname(__DIR__) . '/config/database.php';

/**
 * 分组展示用；实际清空按 $tables 顺序（子表在前）。
 * @var array<string, list<string>>
 */
$groups = [
    '账号会话总任务' => [
        'account_work_logs',
        'account_work_items',
        'account_work_batches',
    ],
    '计划任务 / 矩阵任务' => [
        'task_action_results',
        'task_plans',
        'task_events',
        'task_runs',
        'task_items',
        'tasks',
    ],
    '后台计划任务' => [
        'plan_tasks',
    ],
    '自动增粉' => [
        'auto_follow_task_items',
        'auto_follow_tasks',
        'account_follow_relations',
    ],
    '拉取评论' => [
        'material_comments',
        'pull_comment_tasks',
    ],
    'AI拓客衍生（执行/线索数据）' => [
        'ai_lead_comment_shared_users',
        'ai_lead_shared_users',
        'ai_lead_comments',
        'ai_lead_videos',
        'ai_lead_records',
        'ai_lead_logs',
        'ai_lead_account_daily',
        'ai_lead_creators',
        'ai_lead_accounts',
        'ai_leads',
    ],
];

/** @var list<string> */
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

    page('一键清空任务数据', '
      <h1 class="warn">清空全部账号的各类任务及衍生数据</h1>
      <p>将 <b>TRUNCATE</b>（清空并重置自增 ID）下列相关表，操作<strong>不可逆</strong>。</p>
      <ul>
        <li>保留：账号、代理、素材文件、用户/权限、环境绑定行（仅释放任务锁）</li>
        <li>清空：会话总任务、计划任务、增粉、拉评、AI拓客执行衍生、后台 plan_tasks 等</li>
      </ul>
      <p class="muted">库：' . h($database) . ' · 合计约 <b>' . $totalRows . '</b> 行</p>
      ' . $sections . '
      <a class="btn btn-danger" href="?confirm=1" onclick="return confirm(\'确认永久清空全部任务及衍生数据？此操作不可撤销！\')">确认一键清空</a>
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

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $list = '';
    foreach ($results as $t => $msg) {
        $list .= '<li><code>' . h($t) . '</code>：' . h($msg) . '</li>';
    }

    page('清空完成', '
      <h1 class="ok">已清空全部相关任务数据</h1>
      <ul>' . $list . '</ul>
      <p class="muted">浏览器环境任务锁释放（ROW_COUNT）：' . $envReleased . '</p>
      <a class="btn btn-ghost" href="?">查看当前状态</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
} catch (Throwable $e) {
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignore) {
    }
    page('执行失败', '<h1 class="err">清空失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
}
