<?php
declare(strict_types=1);

/**
 * 清除拓客线索分配给企业客户的抄送/共享关联（不可逆）
 * - ai_lead_comment_shared_users：评论线索 → 企业客户（批量抄送）
 * - ai_lead_shared_users：拓客任务 → 共享用户
 *
 * 只删分配关系，不删除线索 / 评论 / 拓客任务本身。
 *
 * 浏览器访问：/clear-ai-lead-clue-shares.php
 * 确认执行：  /clear-ai-lead-clue-shares.php?confirm=1
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

$confirm = isset($_GET['confirm']) && (string)$_GET['confirm'] === '1';

$configFile = is_file(__DIR__ . '/config/database.php')
    ? __DIR__ . '/config/database.php'
    : dirname(__DIR__) . '/config/database.php';

/** @var list<array{table:string,label:string,desc:string}> */
$targets = [
    [
        'table' => 'ai_lead_comment_shared_users',
        'label' => '评论线索抄送（企业客户）',
        'desc' => '拓客线索「批量抄送」写入的 comment↔user 关联',
    ],
    [
        'table' => 'ai_lead_shared_users',
        'label' => '拓客任务共享用户',
        'desc' => '拓客任务级共享给用户的 lead↔user 关联',
    ],
];

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
      th{color:#94a3b8;font-weight:600}
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

function distinctUsers(PDO $pdo, string $table): ?int
{
    if (!tableExists($pdo, $table)) {
        return null;
    }
    return (int)$pdo->query(
        'SELECT COUNT(DISTINCT user_id) FROM `' . str_replace('`', '``', $table) . '`'
    )->fetchColumn();
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

$stats = [];
$totalRows = 0;
foreach ($targets as $t) {
    $cnt = tableCount($pdo, $t['table']);
    $users = $cnt === null ? null : distinctUsers($pdo, $t['table']);
    $stats[] = array_merge($t, [
        'exists' => $cnt !== null,
        'count' => $cnt ?? 0,
        'users' => $users ?? 0,
    ]);
    if ($cnt !== null) {
        $totalRows += $cnt;
    }
}

// 企业客户角色用户数（仅展示）
$enterpriseUsers = 0;
try {
    $role = $pdo->query(
        "SELECT id FROM admin_roles WHERE name LIKE '%企业客户%' AND status=1 LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($role) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE role_id=? AND status=1');
        $st->execute([(int)$role['id']]);
        $enterpriseUsers = (int)$st->fetchColumn();
    }
} catch (Throwable $e) {
    $enterpriseUsers = 0;
}

if (!$confirm) {
    $rowsHtml = '';
    foreach ($stats as $s) {
        if (!$s['exists']) {
            $rowsHtml .= '<tr><td><code>' . h($s['table']) . '</code><div class="muted">' . h($s['label']) . '</div></td>'
                . '<td colspan="2" class="warn">表不存在</td></tr>';
            continue;
        }
        $rowsHtml .= '<tr><td><code>' . h($s['table']) . '</code><div class="muted">' . h($s['desc']) . '</div></td>'
            . '<td><b>' . (int)$s['count'] . '</b> 条关联</td>'
            . '<td><b>' . (int)$s['users'] . '</b> 位用户</td></tr>';
    }

    page('清除拓客线索企业用户分配', '
      <h1>清除拓客线索 → 企业客户分配</h1>
      <p>将清空线索抄送/任务共享关联表。<b class="warn">只删分配关系，不删除线索、评论、拓客任务本身。</b></p>
      <p class="muted">库：' . h($database) . ' · 启用中的企业客户角色用户约 <b>' . $enterpriseUsers . '</b> 人</p>
      <h2>预览统计</h2>
      <table>
        <thead><tr><th>表</th><th>关联数</th><th>涉及用户</th></tr></thead>
        <tbody>' . $rowsHtml . '</tbody>
      </table>
      <p class="muted">合计待清除关联：<b>' . $totalRows . '</b> 条</p>
      <a class="btn btn-danger" href="?confirm=1" onclick="return confirm(\'确认清除全部拓客线索的企业用户分配？此操作不可撤销！\')">确认清除分配</a>
      <a class="btn btn-ghost" href="?">刷新统计</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
    exit;
}

try {
    $pdo->beginTransaction();
    $results = [];
    foreach ($stats as $s) {
        if (!$s['exists']) {
            $results[] = ['table' => $s['table'], 'deleted' => null, 'msg' => '表不存在，跳过'];
            continue;
        }
        $before = (int)$s['count'];
        $pdo->exec('DELETE FROM `' . str_replace('`', '``', $s['table']) . '`');
        $left = tableCount($pdo, $s['table']) ?? 0;
        $results[] = [
            'table' => $s['table'],
            'deleted' => $before - $left,
            'left' => $left,
            'msg' => 'ok',
        ];
    }
    $pdo->commit();

    $list = '';
    foreach ($results as $r) {
        if ($r['deleted'] === null) {
            $list .= '<li><code>' . h($r['table']) . '</code>：' . h($r['msg']) . '</li>';
        } else {
            $list .= '<li><code>' . h($r['table']) . '</code>：已删除 <b>' . (int)$r['deleted'] . '</b>，剩余 <b>' . (int)($r['left'] ?? 0) . '</b></li>';
        }
    }

    page('清除完成', '
      <h1 class="ok">已清除拓客线索的企业用户分配</h1>
      <ul>' . $list . '</ul>
      <p class="muted">线索正文、评论、拓客任务配置均保留；企业客户侧「拓客结果 / 抄送线索」将不再看到这些分配。</p>
      <a class="btn btn-ghost" href="?">查看当前状态</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    page('执行失败', '<h1 class="err">清除失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
}
