<?php
declare(strict_types=1);

/**
 * 一键解绑：将所有账号与代理的关联清空（accounts.proxy_id → NULL）
 * 只解除关联，不删除代理，也不删除账号。
 * 浏览器访问：/unbind-proxies.php            （预览统计）
 * 确认执行：  /unbind-proxies.php?confirm=1
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

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
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

if (!hasColumn($pdo, 'accounts', 'proxy_id')) {
    page('无需处理', '<h1 class="err">accounts 表没有 proxy_id 字段</h1>');
    exit;
}

$accountTotal = (int)$pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$accountBound = (int)$pdo->query('SELECT COUNT(*) FROM accounts WHERE proxy_id IS NOT NULL AND proxy_id <> 0')->fetchColumn();
$proxyTotal = (int)$pdo->query('SELECT COUNT(*) FROM proxies')->fetchColumn();
$proxyInUse = (int)$pdo->query('SELECT COUNT(DISTINCT proxy_id) FROM accounts WHERE proxy_id IS NOT NULL AND proxy_id <> 0')->fetchColumn();

// 部分环境绑定表也可能存了 proxy_id，存在则一并清空
$envHasProxy = hasColumn($pdo, 'account_browser_envs', 'proxy_id');
$envBound = 0;
if ($envHasProxy) {
    $envBound = (int)$pdo->query('SELECT COUNT(*) FROM account_browser_envs WHERE proxy_id IS NOT NULL AND proxy_id <> 0')->fetchColumn();
}

if (!$confirm) {
    page('解绑全部代理', '
      <h1>代理 ↔ 账号：一键全部解绑</h1>
      <p>将把所有账号的代理关联清空（<code>accounts.proxy_id = NULL</code>）。<b>只解除关联，不会删除代理，也不会删除账号。</b></p>
      <p class="muted">库：' . h($database) . '</p>
      <table><tbody>
        <tr><td>账号总数</td><td><b>' . $accountTotal . '</b></td></tr>
        <tr><td>当前已绑定代理的账号</td><td><b>' . $accountBound . '</b></td></tr>
        <tr><td>代理总数</td><td><b>' . $proxyTotal . '</b></td></tr>
        <tr><td>正在被账号使用的代理</td><td><b>' . $proxyInUse . '</b></td></tr>'
        . ($envHasProxy ? '<tr><td>环境绑定表中带代理的记录</td><td><b>' . $envBound . '</b></td></tr>' : '') . '
      </tbody></table>
      <a class="btn btn-danger" href="?confirm=1" onclick="return confirm(\'确认解除所有账号与代理的关联？\')">确认全部解绑</a>
      <a class="btn btn-ghost" href="?">刷新统计</a>
    ');
    exit;
}

try {
    $pdo->beginTransaction();

    $accountsUpdated = (int)$pdo->exec(
        'UPDATE accounts SET proxy_id = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE proxy_id IS NOT NULL AND proxy_id <> 0'
    );

    $envUpdated = 0;
    if ($envHasProxy) {
        $envUpdated = (int)$pdo->exec(
            'UPDATE account_browser_envs SET proxy_id = NULL
             WHERE proxy_id IS NOT NULL AND proxy_id <> 0'
        );
    }

    $pdo->commit();

    $left = (int)$pdo->query('SELECT COUNT(*) FROM accounts WHERE proxy_id IS NOT NULL AND proxy_id <> 0')->fetchColumn();

    page('解绑完成', '
      <h1 class="ok">已解除全部账号与代理的关联</h1>
      <ul>
        <li>账号解绑：<b>' . $accountsUpdated . '</b> / ' . $accountTotal . '</li>'
        . ($envHasProxy ? '<li>环境绑定记录清空代理：<b>' . $envUpdated . '</b></li>' : '') . '
        <li>仍绑定代理的账号：<b>' . $left . '</b></li>
        <li>代理保留：<b>' . $proxyTotal . '</b> 条（未删除）</li>
      </ul>
      <p class="muted">已在比特/自研浏览器里创建的环境不会被改动；如需让环境也清掉代理，请在环境里单独处理。</p>
      <a class="btn btn-ghost" href="?">查看当前状态</a>
    ');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    page('执行失败', '<h1 class="err">解绑失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
}
