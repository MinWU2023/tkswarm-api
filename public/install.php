<?php
declare(strict_types=1);

// TKSwarm 数据库向导安装程序
error_reporting(E_ALL);
ini_set('display_errors', '1');

$configFile = dirname(__DIR__) . '/config/database.php';
$installedLock = dirname(__DIR__) . '/install.lock';

if (file_exists($installedLock)) {
    die("<div style='font-family:sans-serif;padding:30px;color:#dc2626;text-align:center;'><h2>系统已安装</h2><p>若需重新安装，请手动删除项目根目录下的 install.lock 文件。</p></div>");
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? '127.0.0.1');
    $port = (int)($_POST['port'] ?? 3306);
    $database = trim($_POST['database'] ?? 'tkswarm');
    $username = trim($_POST['username'] ?? 'root');
    $password = $_POST['password'] ?? '';
    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminPass = $_POST['admin_pass'] ?? 'admin123';

    try {
        // 1. 连接测试与自动建库
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $pdo->exec("USE `{$database}`;");

        // 2. 执行数据迁移文件
        $files = glob(dirname(__DIR__) . '/migrations/*.sql');
        sort($files);
        foreach ($files as $f) {
            $sql = file_get_contents($f);
            if (!empty($sql)) {
                $pdo->exec($sql);
            }
        }

        // 3. 更新管理员密码
        if (!empty($adminPass)) {
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE `admin_users` SET `password_hash` = ?, `username` = ? WHERE `id` = 1");
            $stmt->execute([$hash, $adminUser]);
        }

        // 4. 写出 database.php 配置文件
        $configContent = "<?php\ndeclare(strict_types=1);\n\nreturn [\n" .
            "    'driver' => 'mysql',\n" .
            "    'host' => '{$host}',\n" .
            "    'port' => {$port},\n" .
            "    'database' => '{$database}',\n" .
            "    'username' => '{$username}',\n" .
            "    'password' => '{$password}',\n" .
            "    'charset' => 'utf8mb4',\n" .
            "    'collation' => 'utf8mb4_unicode_ci',\n" .
            "    'prefix' => '',\n" .
            "];\n";
        file_put_contents($configFile, $configContent);
        file_put_contents($installedLock, date('Y-m-d H:i:s'));

        $success = true;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <title>TKSwarm 企业级矩阵 - 安装向导</title>
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/ui.css">
  <link rel="stylesheet" href="/theme-tech.css">
  <style>
    body { background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .install-box { max-width: 580px; margin: 50px auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 10px 25px rgba(0,0,0,0.05); padding: 36px; }
    .install-header { margin-bottom: 24px; text-align: center; }
    .install-header h1 { font-size: 24px; font-weight: 800; color: #1e293b; margin: 0 0 8px; }
    .install-header p { font-size: 14px; color: #64748b; margin: 0; }
    .field { margin-bottom: 16px; }
    .field label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
    .field input { width: 100%; box-sizing: border-box; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0 12px; font-size: 14px; }
    .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 13.5px; }
    .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; padding: 16px; border-radius: 6px; text-align: center; }
  </style>
</head>
<body>
  <div class="install-box">
    <div class="install-header">
      <h1>TKSwarm MySQL 环境安装向导</h1>
      <p>面向 PHP 8.2+ 与 MySQL 5.8+/8.0 企业级高可用运行环境</p>
    </div>

    <?php if ($success): ?>
      <div class="alert-success">
        <h3 style="margin: 0 0 10px;">安装初始化成功！</h3>
        <p style="margin: 0 0 16px; font-size: 14px;">数据库迁移及管理员账号已成功配置。</p>
        <a href="/" style="display:inline-block; padding:10px 24px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;">进入控制台</a>
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="alert-error"><b>安装失败：</b><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div style="font-weight: 700; color: #0f172a; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">一、MySQL 数据库连接</div>
        <div style="display: flex; gap: 12px;">
          <div class="field" style="flex: 3;">
            <label>数据库服务器 (Host)</label>
            <input type="text" name="host" value="127.0.0.1" required>
          </div>
          <div class="field" style="flex: 1;">
            <label>端口 (Port)</label>
            <input type="number" name="port" value="3306" required>
          </div>
        </div>
        <div class="field">
          <label>数据库名称 (Database Name - 自动创建)</label>
          <input type="text" name="database" value="tkswarm" required>
        </div>
        <div style="display: flex; gap: 12px;">
          <div class="field" style="flex: 1;">
            <label>数据库用户名 (User)</label>
            <input type="text" name="username" value="root" required>
          </div>
          <div class="field" style="flex: 1;">
            <label>数据库密码 (Password)</label>
            <input type="password" name="password" placeholder="未设置请留空">
          </div>
        </div>

        <div style="font-weight: 700; color: #0f172a; margin: 20px 0 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">二、超级管理员账号配置</div>
        <div style="display: flex; gap: 12px;">
          <div class="field" style="flex: 1;">
            <label>管理员登录名</label>
            <input type="text" name="admin_user" value="admin" required>
          </div>
          <div class="field" style="flex: 1;">
            <label>管理员登录密码</label>
            <input type="password" name="admin_pass" value="admin123" required>
          </div>
        </div>

        <button type="submit" style="width:100%; height:42px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-weight:600; font-size:15px; cursor:pointer; margin-top:12px;">立即安装并执行数据迁移</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
