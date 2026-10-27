<?php
declare(strict_types=1);

// 尽早处理跨域，避免异常/404 时浏览器看不到 CORS 头
$__corsOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!headers_sent()) {
    if ($__corsOrigin !== '') {
        header('Access-Control-Allow-Origin: ' . $__corsOrigin);
        header('Vary: Origin');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Acting-As');
    header('Access-Control-Expose-Headers: Content-Disposition, Content-Type');
    header('Access-Control-Max-Age: 86400');
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 定义应用根路径
define('APP_PATH', dirname(__DIR__));

// 引入 PSR-4 自动加载器
require APP_PATH . '/autoload.php';

// 初始化框架核心应用
$app = new App\Core\App(APP_PATH);

// 加载路由映射
require APP_PATH . '/routes/web.php';

// 启动路由分发
$app->run();
