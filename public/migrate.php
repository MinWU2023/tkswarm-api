<?php
declare(strict_types=1);

/**
 * TKSwarm MySQL Migration Runner
 * 支持通过浏览器直接访问执行 (http://tkswarm-api.wumin/migrate.php)，同时完全兼容 CLI 命令行运行。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$isCli = (PHP_SAPI === 'cli');

// 自动识别项目根目录 APP_PATH
$appPath = is_file(__DIR__ . '/autoload.php') ? __DIR__ : dirname(__DIR__);
if (!defined('APP_PATH')) {
    define('APP_PATH', $appPath);
}

// 引入自动加载器
if (is_file(APP_PATH . '/autoload.php')) {
    require_once APP_PATH . '/autoload.php';
}

$configFile = APP_PATH . '/config/database.php';
if (!is_file($configFile)) {
    if ($isCli) {
        echo "\033[31m[ERROR] 数据库配置文件不存在: {$configFile}\033[0m\n";
        exit(1);
    } else {
        renderHtmlError("数据库配置文件不存在", "未在系统路径找到配置文件：<code>{$configFile}</code>，请先运行安装向导或配置数据库。");
        exit;
    }
}

$dbConfig = require $configFile;

$host = $dbConfig['host'] ?? '127.0.0.1';
$port = (int)($dbConfig['port'] ?? 3306);
$database = $dbConfig['database'] ?? 'tkswarm';
$username = $dbConfig['username'] ?? 'root';
$password = $dbConfig['password'] ?? '';
$charset = $dbConfig['charset'] ?? 'utf8mb4';

// 1. 连接数据库
$pdo = null;
$connectError = null;
$serverVersion = '';

try {
    // 首次尝试连接并自动确保数据库存在
    $rootDsn = "mysql:host={$host};port={$port};charset={$charset}";
    $rootPdo = new PDO($rootDsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` DEFAULT CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci;");

    // 连接到目标数据库
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci"
    ]);

    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (\Throwable $e) {
    $connectError = $e->getMessage();
    if ($isCli) {
        echo "\033[31m[ERROR] 数据库连接失败: {$connectError}\033[0m\n";
        exit(1);
    } else {
        renderHtmlError("数据库连接失败", "无法连接至 MySQL 数据库 (<code>{$host}:{$port}/{$database}</code>)：<br><pre>{$connectError}</pre><p>请检查 MySQL 服务是否正在运行，以及 <code>config/database.php</code> 中的账号密码配置。</p>");
        exit;
    }
}

// 2. 确保迁移记录表 migrations 存在
$pdo->exec("CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `migration` VARCHAR(255) NOT NULL UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$applied = $pdo->query("SELECT `migration` FROM `migrations`")->fetchAll(PDO::FETCH_COLUMN);
if (!is_array($applied)) {
    $applied = [];
}

// 3. 扫描迁移文件（.sql + .php）
$migrationsDir = APP_PATH . '/migrations';
$sqlFiles = is_dir($migrationsDir) ? glob($migrationsDir . '/*.sql') : [];
$phpFiles = is_dir($migrationsDir) ? glob($migrationsDir . '/*.php') : [];
$files = array_values(array_filter(array_merge($sqlFiles ?: [], $phpFiles ?: []), static function ($f) {
    $base = basename((string)$f);
    // 跳过辅助 dump / 下划线临时脚本
    if (str_starts_with($base, '_')) {
        return false;
    }
    return (bool)preg_match('/^\d+/', $base);
}));
sort($files);

$results = [];
$hasError = false;
$successCount = 0;
$skipCount = 0;

if ($isCli) {
    echo "=========================================================\n";
    echo " TKSwarm MySQL Migration Engine (PHP8.2+)\n";
    echo " Target Database: {$database} on {$host}:{$port}\n";
    echo "=========================================================\n\n";
}

foreach ($files as $file) {
    $filename = basename($file);
    $item = [
        'file' => $filename,
        'path' => $file,
        'status' => 'pending',
        'message' => '',
        'time_ms' => 0
    ];

    if (in_array($filename, $applied, true)) {
        $item['status'] = 'skipped';
        $item['message'] = '之前已执行，无需重复迁移';
        $results[] = $item;
        $skipCount++;
        if ($isCli) {
            echo "  [SKIP] {$filename} (already migrated)\n";
        }
        continue;
    }

    if ($isCli) {
        echo "  [MIGRATING] {$filename} ... ";
    }

    $startTime = microtime(true);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    try {
        if ($ext === 'php') {
            $runner = require $file;
            if (!is_callable($runner)) {
                throw new RuntimeException('PHP 迁移必须 return callable(PDO $pdo): string');
            }
            $message = (string)$runner($pdo);
            if ($message === '') {
                $message = '执行成功';
            }
        } else {
            $sql = (string)file_get_contents($file);
            runMigrationSql($pdo, $sql);
            $message = null;
        }

        $stmt = $pdo->prepare("INSERT INTO `migrations` (`migration`) VALUES (?)");
        $stmt->execute([$filename]);

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $item['status'] = 'success';
        $item['time_ms'] = $durationMs;
        $item['message'] = $message !== null
            ? "{$message} (耗时 {$durationMs}ms)"
            : "执行成功 (耗时 {$durationMs}ms)";
        $results[] = $item;
        $successCount++;

        if ($isCli) {
            echo "\033[32mSUCCESS ({$durationMs}ms)\033[0m\n";
            if ($message) {
                echo "  → {$message}\n";
            }
        }
    } catch (\Throwable $e) {
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $item['status'] = 'failed';
        $item['time_ms'] = $durationMs;
        $item['message'] = $e->getMessage();
        $results[] = $item;
        $hasError = true;

        if ($isCli) {
            echo "\033[31mFAILED\033[0m\n";
            echo "  Error: " . $e->getMessage() . "\n";
            exit(1);
        }
        break; // 停止后续执行
    }
}

if ($isCli) {
    echo "\n=========================================================\n";
    if ($hasError) {
        echo "\033[31mMigrations halted due to error.\033[0m\n";
        exit(1);
    } else {
        echo "\033[32mAll migrations finished successfully! ({$successCount} new, {$skipCount} skipped)\033[0m\n";
        exit(0);
    }
}

// 4. 浏览器端渲染现代科技风格 HTML 界面
header('Content-Type: text/html; charset=utf-8');
renderHtmlView($database, $host, $port, $serverVersion, $results, $successCount, $skipCount, $hasError);

/**
 * 执行迁移 SQL：按语句逐条执行（避免 MULTI_STATEMENTS + PREPARE 冲突）。
 * 对「列/索引已存在」类错误做幂等忽略。
 */
function runMigrationSql(PDO $pdo, string $sql): void
{
    $statements = splitMigrationStatements($sql);
    if (!$statements) {
        return;
    }

    foreach ($statements as $statement) {
        try {
            $stmt = $pdo->query($statement);
            if ($stmt === false) {
                continue;
            }
            do {
                try {
                    $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (\Throwable $e) {
                    // 非结果集语句忽略
                }
            } while ($stmt->nextRowset());
            $stmt->closeCursor();
        } catch (\Throwable $e) {
            if (isIgnorableMigrationError($e)) {
                continue;
            }
            throw $e;
        }
    }
}

/**
 * @return list<string>
 */
function splitMigrationStatements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
    $sql = str_replace("\r\n", "\n", $sql);

    $out = [];
    $buf = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($ch === '\\' && ($inSingle || $inDouble)) {
            $buf .= $ch . $next;
            $i++;
            continue;
        }
        if ($ch === "'" && !$inDouble && !$inBacktick) {
            // SQL 转义 '' 
            if ($inSingle && $next === "'") {
                $buf .= "''";
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
            $buf .= $ch;
            continue;
        }
        if ($ch === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
            $buf .= $ch;
            continue;
        }
        if ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
            $buf .= $ch;
            continue;
        }

        if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $statement = trim($buf);
            if ($statement !== '') {
                $out[] = $statement;
            }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }

    $statement = trim($buf);
    if ($statement !== '') {
        $out[] = $statement;
    }
    return $out;
}

function isIgnorableMigrationError(\Throwable $e): bool
{
    $code = (int)($e->errorInfo[1] ?? 0);
    // 1072 Key column doesn't exist — 绝不可忽略（说明前置 ADD COLUMN 未成功）
    if ($code === 1072) {
        return false;
    }
    // 1060 Duplicate column, 1061 Duplicate key, 1091 Can't DROP, 1050 Table exists
    if (in_array($code, [1060, 1061, 1091, 1050], true)) {
        return true;
    }
    // 1054 Unknown column：仅当用于幂等回填/改列时忽略；勿掩盖缺列建索引
    if ($code === 1054) {
        $msg = $e->getMessage();
        if (preg_match('/ADD\s+UNIQUE|ADD\s+(?:KEY|INDEX|PRIMARY)|UNIQUE\s+KEY/i', $msg)) {
            return false;
        }
        return true;
    }
    $msg = $e->getMessage();
    if (preg_match('/Key column .+ doesn\'t exist|1072/i', $msg)) {
        return false;
    }
    return (bool)preg_match(
        '/Duplicate column name|Duplicate key name|already exists|Can\'t DROP/i',
        $msg
    );
}

/**
 * 渲染全套现代科技感 HTML 页面
 */
function renderHtmlView(string $database, string $host, int $port, string $serverVersion, array $results, int $successCount, int $skipCount, bool $hasError): void
{
    $totalCount = count($results);
    $statusColor = $hasError ? '#ef4444' : ($successCount > 0 ? '#10b981' : '#3b82f6');
    $statusTitle = $hasError ? '部分迁移执行失败' : ($successCount > 0 ? '数据迁移执行完成' : '所有迁移均为最新状态');
    $statusDesc = $hasError 
        ? '在执行过程中遇到 SQL 语法或约束冲突，请根据下方详细报错排查。' 
        : ($successCount > 0 
            ? "本次成功执行了 {$successCount} 个新迁移脚本，跳过 {$skipCount} 个已执行脚本。" 
            : "数据库已处于最新状态，全部 {$skipCount} 个迁移文件均已顺利就绪。");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TKSwarm 数据库迁移执行器</title>
  <style>
    :root {
      --bg: #070c18;
      --card-bg: rgba(15, 23, 42, 0.85);
      --border: rgba(56, 189, 248, 0.2);
      --primary: #38bdf8;
      --primary-glow: rgba(56, 189, 248, 0.35);
      --success: #10b981;
      --danger: #ef4444;
      --warning: #f59e0b;
      --text: #f8fafc;
      --muted: #94a3b8;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
      background-color: var(--bg);
      background-image: radial-gradient(circle at 10% 20%, rgba(14, 165, 233, 0.12) 0%, transparent 40%),
                        radial-gradient(circle at 90% 80%, rgba(99, 102, 241, 0.1) 0%, transparent 40%);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 40px 20px;
    }
    .container {
      width: 100%;
      max-width: 960px;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }
    .header-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
      padding-bottom: 16px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    .logo-group {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .logo-badge {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: linear-gradient(135deg, #0284c7 0%, #38bdf8 100%);
      display: grid;
      place-items: center;
      font-weight: 800;
      font-size: 22px;
      color: #fff;
      box-shadow: 0 0 20px var(--primary-glow);
    }
    .logo-text h1 {
      font-size: 22px;
      font-weight: 700;
      letter-spacing: -0.02em;
    }
    .logo-text p {
      font-size: 13px;
      color: var(--muted);
      margin-top: 2px;
    }
    .env-tags {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .tag {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      background: rgba(15, 23, 42, 0.6);
      border: 1px solid var(--border);
      color: var(--text);
    }
    .tag-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--success);
      box-shadow: 0 0 8px var(--success);
    }
    .summary-card {
      background: var(--card-bg);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid <?= $hasError ? 'rgba(239, 68, 68, 0.4)' : 'rgba(56, 189, 248, 0.3)' ?>;
      border-radius: 16px;
      padding: 24px;
      display: flex;
      align-items: center;
      gap: 20px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
    }
    .summary-icon {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      display: grid;
      place-items: center;
      font-size: 26px;
      background: <?= $hasError ? 'rgba(239, 68, 68, 0.15)' : ($successCount > 0 ? 'rgba(16, 185, 129, 0.15)' : 'rgba(56, 189, 248, 0.15)') ?>;
      border: 1px solid <?= $statusColor ?>;
      color: <?= $statusColor ?>;
      flex-shrink: 0;
    }
    .summary-info h2 {
      font-size: 18px;
      font-weight: 700;
      color: <?= $statusColor ?>;
    }
    .summary-info p {
      font-size: 14px;
      color: var(--muted);
      margin-top: 4px;
      line-height: 1.5;
    }
    .stats-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
    }
    .stat-box {
      background: rgba(15, 23, 42, 0.6);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 16px;
      text-align: center;
    }
    .stat-box small {
      font-size: 12px;
      color: var(--muted);
      display: block;
    }
    .stat-box b {
      font-size: 22px;
      font-weight: 700;
      margin-top: 4px;
      display: block;
      color: #fff;
    }
    .stat-box.success b { color: var(--success); }
    .stat-box.skip b { color: #38bdf8; }
    .stat-box.fail b { color: var(--danger); }
    .table-panel {
      background: var(--card-bg);
      backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
    }
    .table-panel-head {
      padding: 18px 24px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .table-panel-head h3 {
      font-size: 15px;
      font-weight: 600;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 13.5px;
    }
    th, td {
      padding: 14px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }
    th {
      background: rgba(255, 255, 255, 0.02);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--muted);
      font-weight: 600;
    }
    tbody tr:last-child td {
      border-bottom: none;
    }
    tbody tr:hover td {
      background: rgba(255, 255, 255, 0.03);
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 10px;
      border-radius: 6px;
      font-size: 11.5px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .badge-success { background: rgba(16, 185, 129, 0.18); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); }
    .badge-skipped { background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); }
    .badge-failed  { background: rgba(239, 68, 68, 0.18); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.35); }
    .file-name {
      font-family: "JetBrains Mono", Consolas, Monaco, monospace;
      font-weight: 600;
      color: #e2e8f0;
    }
    .err-msg {
      margin-top: 6px;
      padding: 8px 12px;
      border-radius: 6px;
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.25);
      color: #fca5a5;
      font-size: 12px;
      font-family: monospace;
      word-break: break-all;
    }
    .btn-bar {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 10px;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: 8px;
      font-size: 13.5px;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      border: none;
      transition: all 0.2s ease;
    }
    .btn-primary {
      background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%);
      color: #fff;
      box-shadow: 0 4px 14px rgba(14, 165, 233, 0.3);
    }
    .btn-primary:hover {
      filter: brightness(1.1);
      transform: translateY(-1px);
    }
    .btn-ghost {
      background: rgba(255, 255, 255, 0.08);
      color: #e2e8f0;
      border: 1px solid rgba(255, 255, 255, 0.15);
    }
    .btn-ghost:hover {
      background: rgba(255, 255, 255, 0.15);
      color: #fff;
    }
    @media (max-width: 768px) {
      .stats-row { grid-template-columns: repeat(2, 1fr); }
      .summary-card { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header-bar">
      <div class="logo-group">
        <div class="logo-badge">TK</div>
        <div class="logo-text">
          <h1>TKSwarm 数据库迁移执行器</h1>
          <p>PHP8.2+ MySQL Migration Engine</p>
        </div>
      </div>
      <div class="env-tags">
        <span class="tag"><span class="tag-dot"></span>MySQL <?= htmlspecialchars($serverVersion ?: '8.0+') ?></span>
        <span class="tag">库名: <?= htmlspecialchars($database) ?></span>
        <span class="tag">端口: <?= htmlspecialchars((string)$port) ?></span>
      </div>
    </div>

    <div class="summary-card">
      <div class="summary-icon"><?= $hasError ? '✕' : ($successCount > 0 ? '✓' : '●') ?></div>
      <div class="summary-info">
        <h2><?= htmlspecialchars($statusTitle) ?></h2>
        <p><?= htmlspecialchars($statusDesc) ?></p>
      </div>
    </div>

    <div class="stats-row">
      <div class="stat-box">
        <small>迁移文件总数</small>
        <b><?= $totalCount ?></b>
      </div>
      <div class="stat-box success">
        <small>本次新迁移</small>
        <b><?= $successCount ?></b>
      </div>
      <div class="stat-box skip">
        <small>已存在已跳过</small>
        <b><?= $skipCount ?></b>
      </div>
      <div class="stat-box <?= $hasError ? 'fail' : '' ?>">
        <small>失败异常</small>
        <b><?= $hasError ? '1' : '0' ?></b>
      </div>
    </div>

    <div class="table-panel">
      <div class="table-panel-head">
        <h3>迁移执行结果明细</h3>
        <span style="font-size:12px;color:var(--muted)">扫描路径: migrations/*.{sql,php}</span>
      </div>
      <table>
        <thead>
          <tr>
            <th style="width: 50px;">#</th>
            <th>迁移文件 (Migration File)</th>
            <th style="width: 140px;">状态 (Status)</th>
            <th style="width: 110px;">耗时</th>
            <th>执行反馈</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($results)): ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:36px;color:var(--muted);">未发现任何迁移 SQL 文件</td>
            </tr>
          <?php else: ?>
            <?php foreach ($results as $idx => $r): ?>
              <tr>
                <td style="color:var(--muted);"><?= $idx + 1 ?></td>
                <td><span class="file-name"><?= htmlspecialchars($r['file']) ?></span></td>
                <td>
                  <?php if ($r['status'] === 'success'): ?>
                    <span class="badge badge-success">✓ 执行成功</span>
                  <?php elseif ($r['status'] === 'skipped'): ?>
                    <span class="badge badge-skipped">● 已就绪跳过</span>
                  <?php else: ?>
                    <span class="badge badge-failed">✕ 迁移失败</span>
                  <?php endif; ?>
                </td>
                <td style="color:var(--muted);font-size:12.5px;"><?= $r['time_ms'] > 0 ? $r['time_ms'] . ' ms' : '-' ?></td>
                <td>
                  <span style="color: <?= $r['status'] === 'failed' ? '#f87171' : 'var(--muted)' ?>;">
                    <?= htmlspecialchars($r['message']) ?>
                  </span>
                  <?php if ($r['status'] === 'failed' && !empty($r['message'])): ?>
                    <div class="err-msg"><?= htmlspecialchars($r['message']) ?></div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="btn-bar">
      <a href="/test-db.php" class="btn btn-ghost" target="_blank">📊 数据库状态检测</a>
      <a href="/migrate.php" class="btn btn-ghost">🔄 再次检测 / 重新执行</a>
      <a href="http://127.0.0.1:8999" class="btn btn-primary" target="_blank">🚀 进入 TKSwarm 系统后台</a>
    </div>
  </div>
</body>
</html>
<?php
}

function renderHtmlError(string $title, string $message): void
{
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($title) ?> - TKSwarm</title>
  <style>
    body {
      background: #0b1120;
      color: #f8fafc;
      font-family: -apple-system, sans-serif;
      display: grid;
      place-items: center;
      min-height: 100vh;
      margin: 0;
      padding: 24px;
    }
    .card {
      background: #1e293b;
      border: 1px solid #ef4444;
      border-radius: 16px;
      padding: 32px;
      max-width: 600px;
      width: 100%;
      box-shadow: 0 20px 40px rgba(0,0,0,0.5);
    }
    h2 { color: #f87171; margin-top: 0; }
    pre { background: #0f172a; padding: 12px; border-radius: 8px; overflow-x: auto; color: #fca5a5; }
    a { color: #38bdf8; text-decoration: none; }
  </style>
</head>
<body>
  <div class="card">
    <h2><?= htmlspecialchars($title) ?></h2>
    <div><?= $message ?></div>
    <div style="margin-top:20px;">
      <a href="/install.php">前往安装配置向导 &rarr;</a>
    </div>
  </div>
</body>
</html>
<?php
}
