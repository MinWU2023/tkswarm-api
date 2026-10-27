<?php
declare(strict_types=1);

/**
 * 备用入口：当 Nginx 未配置 try_files 时，/api/index.php/auth/status 仍可进路由。
 * 推荐仍配置 baota_rewrite.conf，让所有 /api/* 走 ../index.php。
 */
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo === '' || $pathInfo === '/') {
    $pathInfo = $_SERVER['REDIRECT_URL'] ?? '';
}
if ($pathInfo === '' && !empty($_GET['path'])) {
    $pathInfo = '/' . ltrim((string)$_GET['path'], '/');
}
if ($pathInfo !== '' && !str_starts_with($pathInfo, '/api')) {
    $pathInfo = '/api' . (str_starts_with($pathInfo, '/') ? $pathInfo : '/' . $pathInfo);
}
if ($pathInfo !== '') {
    $_SERVER['REQUEST_URI'] = $pathInfo . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
}

require dirname(__DIR__) . '/index.php';
