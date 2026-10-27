<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\OperationAudit;

class Router
{
    private static array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'PATCH' => [],
        'DELETE' => [],
        'OPTIONS' => [],
    ];
    private static array $groupMiddlewares = [];
    private static string $groupPrefix = '';

    public static function group(array $attributes, callable $callback): void
    {
        $prevPrefix = self::$groupPrefix;
        $prevMiddlewares = self::$groupMiddlewares;

        if (isset($attributes['prefix'])) {
            self::$groupPrefix = rtrim($prevPrefix, '/') . '/' . trim($attributes['prefix'], '/');
        }
        if (isset($attributes['middleware'])) {
            $middlewares = (array)$attributes['middleware'];
            self::$groupMiddlewares = array_merge($prevMiddlewares, $middlewares);
        }

        $callback();

        self::$groupPrefix = $prevPrefix;
        self::$groupMiddlewares = $prevMiddlewares;
    }

    public static function get(string $uri, mixed $action, array $middleware = []): void
    {
        self::addRoute('GET', $uri, $action, $middleware);
    }

    public static function post(string $uri, mixed $action, array $middleware = []): void
    {
        self::addRoute('POST', $uri, $action, $middleware);
    }

    public static function put(string $uri, mixed $action, array $middleware = []): void
    {
        self::addRoute('PUT', $uri, $action, $middleware);
    }

    public static function patch(string $uri, mixed $action, array $middleware = []): void
    {
        self::addRoute('PATCH', $uri, $action, $middleware);
    }

    public static function delete(string $uri, mixed $action, array $middleware = []): void
    {
        self::addRoute('DELETE', $uri, $action, $middleware);
    }

    public static function any(string $uri, mixed $action, array $middleware = []): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            self::addRoute($method, $uri, $action, $middleware);
        }
    }

    private static function addRoute(string $method, string $uri, mixed $action, array $middleware = []): void
    {
        $uri = rtrim(self::$groupPrefix, '/') . '/' . trim($uri, '/');
        if ($uri === '') {
            $uri = '/';
        }
        $middlewares = array_merge(self::$groupMiddlewares, $middleware);

        // 将路由模式转换成正则表达式 /user/{id} -> #^/user/(?P<id>[^/]+)$#
        $pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $uri);
        $pattern = '#^' . $pattern . '$#';

        self::$routes[$method][] = [
            'uri' => $uri,
            'pattern' => $pattern,
            'action' => $action,
            'middleware' => $middlewares
        ];
    }

    public static function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // 预检请求直接放行
        if ($method === 'OPTIONS') {
            return Response::make('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Acting-As'
            ]);
        }

        $routes = self::$routes[$method] ?? [];
        // 浏览器/播放器常发 HEAD 探测；无独立 HEAD 路由时回落到 GET
        if ($method === 'HEAD' && $routes === []) {
            $routes = self::$routes['GET'] ?? [];
        }
        foreach ($routes as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = [];
                foreach ($matches as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = $v;
                    }
                }

                // 执行中间件
                foreach ($route['middleware'] as $mw) {
                    if (is_string($mw) && class_exists($mw)) {
                        $instance = new $mw();
                        $res = $instance->handle($request);
                        if ($res instanceof Response) {
                            return $res;
                        }
                    } elseif (is_callable($mw)) {
                        $res = $mw($request);
                        if ($res instanceof Response) {
                            return $res;
                        }
                    }
                }

                $action = $route['action'];
                if (is_callable($action)) {
                    $result = call_user_func_array($action, array_merge([$request], $params));
                } elseif (is_string($action) && str_contains($action, '@')) {
                    [$ctrl, $act] = explode('@', $action);
                    if (!class_exists($ctrl)) {
                        return Response::error("Controller [{$ctrl}] not found", 500);
                    }
                    $controller = new $ctrl();
                    if (!method_exists($controller, $act)) {
                        return Response::error("Action [{$act}] not found in [{$ctrl}]", 500);
                    }
                    $result = call_user_func_array([$controller, $act], array_merge([$request], $params));
                } else {
                    return Response::error("Invalid route action", 500);
                }

                if ($result instanceof Response) {
                    $response = $result;
                } elseif (is_array($result) || is_object($result)) {
                    $response = Response::json($result);
                } else {
                    $response = Response::make((string)$result);
                }

                OperationAudit::maybeRecord($request, $response);
                return $response;
            }
        }

        // 默认匹配 404
        if (str_starts_with($path, '/api')) {
            return Response::error("Route [{$method} {$path}] not found", 404);
        }

        return Response::make("<h2 style='font-family:sans-serif;text-align:center;margin-top:50px;'>404 Not Found</h2>", 404);
    }
}
