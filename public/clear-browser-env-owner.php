<?php
declare(strict_types=1);

/**
 * 浏览器环境归属 / 绑定数据维护（不可逆操作需 confirm）
 *
 * Web：
 *   /clear-browser-env-owner.php
 *   /clear-browser-env-owner.php?action=reset-owner&confirm=1
 *   /clear-browser-env-owner.php?action=sync-owner&confirm=1
 *   /clear-browser-env-owner.php?action=rebuild&confirm=1
 *   /clear-browser-env-owner.php?action=clear-all&confirm=1
 *
 * CLI：
 *   php clear-browser-env-owner.php reset-owner --confirm
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
}

$actions = [
    'reset-owner' => '清空环境 owner_user_id（全部置 0，解除绑定到具体登录用户）',
    'sync-owner' => '按账号 owner_id 重新同步环境 owner_user_id',
    'rebuild' => '从 accounts 表旧列回填 account_browser_envs（bit/tkswarm/linux）',
    'clear-all' => '清空全部环境绑定（删 account_browser_envs + 清空账号 browser 列）',
];

$action = 'reset-owner';
$confirm = false;

if ($isCli) {
    global $argv;
    $argAction = (string)($argv[1] ?? '');
    if ($argAction === '' || $argAction === '--stats') {
        $action = 'stats';
    } else {
        $action = $argAction;
    }
    $confirm = in_array('--confirm', $argv, true) || in_array('-y', $argv, true);
} else {
    $action = (string)($_GET['action'] ?? 'reset-owner');
    $confirm = isset($_GET['confirm']) && (string)$_GET['confirm'] === '1';
}

if ($action !== 'stats' && !isset($actions[$action])) {
    $action = 'reset-owner';
}

$configFile = is_file(__DIR__ . '/config/database.php')
    ? __DIR__ . '/config/database.php'
    : dirname(__DIR__) . '/config/database.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function out(string $line): void
{
    global $isCli;
    if ($isCli) {
        echo $line . PHP_EOL;
        return;
    }
    echo $line;
}

function page(string $title, string $body): void
{
    global $isCli;
    if ($isCli) {
        echo strip_tags(str_replace(['</h1>', '</p>', '<br>', '<li>', '</li>'], ["\n", "\n", "\n", '- ', "\n"], $body)) . PHP_EOL;
        return;
    }
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>
      body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:32px}
      .card{max-width:760px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px}
      h1{font-size:20px;margin:0 0 12px}
      p,li{line-height:1.6;color:#cbd5e1}
      code{background:#0f172a;padding:2px 6px;border-radius:4px}
      .ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}.muted{color:#94a3b8;font-size:13px}
      .btn{display:inline-block;margin:8px 8px 0 0;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px}
      .btn-danger{background:#dc2626;color:#fff}.btn-warn{background:#d97706;color:#fff}.btn-ghost{background:#334155;color:#e2e8f0}
      table{width:100%;border-collapse:collapse;margin-top:16px;font-size:14px}
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
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

function scalar(PDO $pdo, string $sql): int
{
    return (int)$pdo->query($sql)->fetchColumn();
}

/** @return list<array{owner_user_id:int,cnt:int}> */
function ownerDistribution(PDO $pdo): array
{
    if (!tableExists($pdo, 'account_browser_envs')) {
        return [];
    }
    $st = $pdo->query(
        'SELECT owner_user_id, COUNT(*) AS cnt FROM account_browser_envs GROUP BY owner_user_id ORDER BY cnt DESC LIMIT 20'
    );
    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function collectStats(PDO $pdo): array
{
    $envTotal = tableExists($pdo, 'account_browser_envs') ? scalar($pdo, 'SELECT COUNT(*) FROM account_browser_envs') : 0;
    $envWithOwner = tableExists($pdo, 'account_browser_envs')
        ? scalar($pdo, 'SELECT COUNT(*) FROM account_browser_envs WHERE owner_user_id > 0')
        : 0;
    $accBit = tableExists($pdo, 'accounts')
        ? scalar($pdo, "SELECT COUNT(*) FROM accounts WHERE browser_profile_id IS NOT NULL AND TRIM(browser_profile_id) <> ''")
        : 0;
    $accTkswarm = (tableExists($pdo, 'accounts') && columnExists($pdo, 'accounts', 'tkswarm_browser_profile_id'))
        ? scalar($pdo, "SELECT COUNT(*) FROM accounts WHERE tkswarm_browser_profile_id IS NOT NULL AND TRIM(tkswarm_browser_profile_id) <> ''")
        : 0;
    $accLinux = (tableExists($pdo, 'accounts') && columnExists($pdo, 'accounts', 'linux_browser_profile_id'))
        ? scalar($pdo, "SELECT COUNT(*) FROM accounts WHERE linux_browser_profile_id IS NOT NULL AND TRIM(linux_browser_profile_id) <> ''")
        : 0;

    return [
        'envTotal' => $envTotal,
        'envWithOwner' => $envWithOwner,
        'accBit' => $accBit,
        'accTkswarm' => $accTkswarm,
        'accLinux' => $accLinux,
        'owners' => ownerDistribution($pdo),
    ];
}

function rebuildFromAccounts(PDO $pdo): array
{
    $inserted = 0;
    if (!tableExists($pdo, 'account_browser_envs') || !tableExists($pdo, 'accounts')) {
        return ['inserted' => 0, 'note' => '缺少表'];
    }

    $hasBackend = columnExists($pdo, 'accounts', 'browser_backend');
    $hasTkswarm = columnExists($pdo, 'accounts', 'tkswarm_browser_profile_id');
    $hasLinux = columnExists($pdo, 'accounts', 'linux_browser_profile_id');

    $backendExpr = $hasBackend ? "COALESCE(NULLIF(browser_backend,''), 'bit')" : "'bit'";

    $inserted += (int)$pdo->exec(
        "INSERT IGNORE INTO account_browser_envs
            (account_id, owner_user_id, browser_profile_id, is_primary, saturation, browser_backend, browser_type)
         SELECT id, COALESCE(owner_id, 0), browser_profile_id, 1, 'idle', {$backendExpr}, {$backendExpr}
         FROM accounts
         WHERE browser_profile_id IS NOT NULL AND TRIM(browser_profile_id) <> ''"
    );

    if ($hasTkswarm) {
        $inserted += (int)$pdo->exec(
            "INSERT IGNORE INTO account_browser_envs
                (account_id, owner_user_id, browser_profile_id, is_primary, saturation, browser_backend, browser_type)
             SELECT id, COALESCE(owner_id, 0), tkswarm_browser_profile_id, 0, 'idle', 'tkswarm', 'tkswarm'
             FROM accounts
             WHERE tkswarm_browser_profile_id IS NOT NULL AND TRIM(tkswarm_browser_profile_id) <> ''"
        );
    }

    if ($hasLinux) {
        $inserted += (int)$pdo->exec(
            "INSERT IGNORE INTO account_browser_envs
                (account_id, owner_user_id, browser_profile_id, is_primary, saturation, browser_backend, browser_type)
             SELECT id, COALESCE(owner_id, 0), linux_browser_profile_id, 0, 'idle', 'linux', 'linux'
             FROM accounts
             WHERE linux_browser_profile_id IS NOT NULL AND TRIM(linux_browser_profile_id) <> ''"
        );
    }

    return ['inserted' => $inserted, 'note' => 'INSERT IGNORE 行数（含已存在跳过）'];
}

if (!is_file($configFile)) {
    page('配置缺失', '<h1 class="err">数据库配置不存在</h1><p>未找到 <code>' . h($configFile) . '</code></p>');
    exit(1);
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
    exit(1);
}

$stats = collectStats($pdo);

if ($isCli && $action === 'stats') {
    out('account_browser_envs: ' . $stats['envTotal'] . ' 行，owner_user_id>0: ' . $stats['envWithOwner']);
    out('accounts.browser_profile_id: ' . $stats['accBit']);
    out('accounts.tkswarm_browser_profile_id: ' . $stats['accTkswarm']);
    out('accounts.linux_browser_profile_id: ' . $stats['accLinux']);
    exit(0);
}

if (!$confirm) {
    $ownerRows = '';
    foreach ($stats['owners'] as $row) {
        $oid = (int)($row['owner_user_id'] ?? 0);
        $ownerRows .= '<tr><td>' . ($oid > 0 ? $oid : '<span class="muted">0（未绑定用户）</span>') . '</td><td><b>' . (int)($row['cnt'] ?? 0) . '</b></td></tr>';
    }
    if ($ownerRows === '') {
        $ownerRows = '<tr><td colspan="2" class="muted">account_browser_envs 无数据或表不存在</td></tr>';
    }

    $btns = '';
    foreach ($actions as $key => $label) {
        $cls = $key === 'clear-all' ? 'btn-danger' : ($key === 'reset-owner' ? 'btn-warn' : 'btn-ghost');
        $btns .= '<a class="btn ' . $cls . '" href="?action=' . h($key) . '&confirm=1" onclick="return confirm(\'确认执行：' . h($label) . '？\')">' . h($key) . '</a>';
    }

    page('浏览器环境数据维护', '
      <h1 class="warn">浏览器环境归属 / 绑定维护</h1>
      <p>环境列表展示依赖 <code>account_browser_envs</code>（及账号表 browser 列）。仅在本机浏览器里创建、未写入数据库的环境，<strong>浏览器离线时无法显示</strong>。</p>
      <p class="muted">库：' . h($database) . ' @ ' . h($host) . ':' . $port . '</p>
      <table>
        <tr><th>统计项</th><th>数量</th></tr>
        <tr><td>account_browser_envs 总行数</td><td><b>' . $stats['envTotal'] . '</b></td></tr>
        <tr><td>owner_user_id &gt; 0</td><td><b>' . $stats['envWithOwner'] . '</b></td></tr>
        <tr><td>accounts.browser_profile_id（比特）</td><td><b>' . $stats['accBit'] . '</b></td></tr>
        <tr><td>accounts.tkswarm_browser_profile_id</td><td><b>' . $stats['accTkswarm'] . '</b></td></tr>
        <tr><td>accounts.linux_browser_profile_id</td><td><b>' . $stats['accLinux'] . '</b></td></tr>
      </table>
      <h1 style="font-size:16px;margin-top:20px">owner_user_id 分布（前 20）</h1>
      <table><thead><tr><th>owner_user_id</th><th>环境数</th></tr></thead><tbody>' . $ownerRows . '</tbody></table>
      <p style="margin-top:20px"><b>可选操作：</b></p>
      <ul>
        <li><code>reset-owner</code>：' . h($actions['reset-owner']) . '</li>
        <li><code>sync-owner</code>：' . h($actions['sync-owner']) . '</li>
        <li><code>rebuild</code>：' . h($actions['rebuild']) . '</li>
        <li><code>clear-all</code>：' . h($actions['clear-all']) . '</li>
      </ul>
      ' . $btns . '
      <a class="btn btn-ghost" href="?">刷新统计</a>
      <p class="muted" style="margin-top:16px">CLI：php clear-browser-env-owner.php reset-owner --confirm</p>
    ');
    exit(0);
}

try {
    $pdo->beginTransaction();
    $result = [];

    if ($action === 'reset-owner') {
        if (!tableExists($pdo, 'account_browser_envs')) {
            throw new RuntimeException('表 account_browser_envs 不存在');
        }
        $before = scalar($pdo, 'SELECT COUNT(*) FROM account_browser_envs WHERE owner_user_id > 0');
        $pdo->exec(
            "UPDATE account_browser_envs
             SET owner_user_id = 0, updated_at = CURRENT_TIMESTAMP
             WHERE owner_user_id <> 0"
        );
        $result[] = "已将 {$before} 条环境的 owner_user_id 清空为 0";
    } elseif ($action === 'sync-owner') {
        if (!tableExists($pdo, 'account_browser_envs') || !tableExists($pdo, 'accounts')) {
            throw new RuntimeException('缺少 account_browser_envs 或 accounts 表');
        }
        $n = (int)$pdo->exec(
            "UPDATE account_browser_envs e
             INNER JOIN accounts a ON a.id = e.account_id
             SET e.owner_user_id = COALESCE(a.owner_id, 0), e.updated_at = CURRENT_TIMESTAMP"
        );
        $result[] = "已按账号 owner_id 同步 {$n} 条环境归属";
    } elseif ($action === 'rebuild') {
        $rb = rebuildFromAccounts($pdo);
        $result[] = '回填 account_browser_envs：' . $rb['inserted'] . ' 行（' . $rb['note'] . ')';
        if (tableExists($pdo, 'account_browser_envs') && tableExists($pdo, 'accounts')) {
            $n = (int)$pdo->exec(
                "UPDATE account_browser_envs e
                 INNER JOIN accounts a ON a.id = e.account_id
                 SET e.owner_user_id = COALESCE(a.owner_id, 0)
                 WHERE e.owner_user_id = 0 AND a.owner_id IS NOT NULL AND a.owner_id > 0"
            );
            $result[] = "顺带同步 owner_user_id：{$n} 条";
        }
    } elseif ($action === 'clear-all') {
        if (tableExists($pdo, 'account_browser_envs')) {
            $before = scalar($pdo, 'SELECT COUNT(*) FROM account_browser_envs');
            $pdo->exec('DELETE FROM account_browser_envs');
            $result[] = "已删除 account_browser_envs {$before} 行";
        }
        if (tableExists($pdo, 'accounts')) {
            $sets = ["browser_profile_id = ''"];
            if (columnExists($pdo, 'accounts', 'tkswarm_browser_profile_id')) {
                $sets[] = "tkswarm_browser_profile_id = ''";
            }
            if (columnExists($pdo, 'accounts', 'linux_browser_profile_id')) {
                $sets[] = "linux_browser_profile_id = ''";
            }
            if (columnExists($pdo, 'accounts', 'browser_backend')) {
                $sets[] = "browser_backend = 'bit'";
            }
            $n = (int)$pdo->exec('UPDATE accounts SET ' . implode(', ', $sets));
            $result[] = "已清空 accounts 浏览器相关列（影响 {$n} 行）";
        }
    }

    $pdo->commit();
    $after = collectStats($pdo);

    $list = '';
    foreach ($result as $line) {
        $list .= '<li class="ok">' . h($line) . '</li>';
    }

    page('执行完成', '
      <h1 class="ok">操作完成：' . h($action) . '</h1>
      <ul>' . $list . '</ul>
      <p class="muted">当前 account_browser_envs：' . $after['envTotal'] . ' 行；owner_user_id&gt;0：' . $after['envWithOwner'] . '</p>
      <a class="btn btn-ghost" href="?">返回统计</a>
    ');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    page('执行失败', '<h1 class="err">失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
    exit(1);
}
