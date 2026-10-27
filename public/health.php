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
echo json_encode([
    'ok' => true,
    'message' => 'PHP 站点正常',
    'indexPhp' => is_file(__DIR__ . '/index.php'),
    'autoload' => is_file(dirname(__DIR__) . '/autoload.php'),
    'routes' => is_file(dirname(__DIR__) . '/routes/web.php'),
    'documentRoot' => __DIR__,
    'time' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
