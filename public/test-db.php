<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$configPath = is_file(__DIR__ . '/config/database.php') ? __DIR__ . '/config/database.php' : dirname(__DIR__) . '/config/database.php';
$config = require $configPath;

try {
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $userCount = $pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
    $menuCount = $pdo->query("SELECT COUNT(*) FROM navigation_menus")->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'message' => 'MySQL 数据库连接成功！',
        'database' => $config['database'],
        'tables_count' => count($tables),
        'admin_users_count' => (int)$userCount,
        'navigation_menus_count' => (int)$menuCount,
        'server_info' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => '数据库连接失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
