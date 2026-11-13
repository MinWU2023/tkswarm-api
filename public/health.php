<?php
declare(strict_types=1);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');
$payload = [
    'ok' => true,
    'message' => 'PHP 站点正常',
    'indexPhp' => is_file(__DIR__ . '/index.php'),
    'autoload' => is_file(dirname(__DIR__) . '/autoload.php'),
    'routes' => is_file(dirname(__DIR__) . '/routes/web.php'),
    'documentRoot' => __DIR__,
    'time' => date('c'),
    'phpTimezone' => date_default_timezone_get(),
];
try {
    $configPath = dirname(__DIR__) . '/config/database.php';
    if (is_file($configPath)) {
        $config = require $configPath;
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=" . ($config['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
        ]);
        $row = $pdo->query("SELECT NOW() AS db_now, UTC_TIMESTAMP() AS utc_now, @@session.time_zone AS sess_tz")->fetch(PDO::FETCH_ASSOC) ?: [];
        $payload['mysql'] = $row;
    }
} catch (Throwable $e) {
    $payload['mysql'] = ['error' => $e->getMessage()];
}
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
