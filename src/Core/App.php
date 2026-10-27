<?php
declare(strict_types=1);

namespace App\Core;

class App
{
    private static ?App $instance = null;
    private array $config = [];
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        self::$instance = $this;
        $this->init();
    }

    public static function getInstance(): ?App
    {
        return self::$instance;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    private function init(): void
    {
        // 错误处理与时区
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        date_default_timezone_set('Asia/Shanghai');

        // 加载配置
        $this->loadConfig();

        // 注册异常处理
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
    }

    private function loadConfig(): void
    {
        $configFile = $this->basePath . '/config/database.php';
        if (file_exists($configFile)) {
            $this->config['db'] = require $configFile;
        }
        $appConfigFile = $this->basePath . '/config/app.php';
        if (file_exists($appConfigFile)) {
            $this->config['app'] = require $appConfigFile;
        }
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $val = $this->config;
        foreach ($parts as $p) {
            if (!is_array($val) || !isset($val[$p])) {
                return $default;
            }
            $val = $val[$p];
        }
        return $val;
    }

    public function run(): void
    {
        $request = Request::capture();
        $response = Router::dispatch($request);
        $response->send();
    }

    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if (error_reporting() & $level) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }
        return true;
    }

    public function handleException(\Throwable $e): void
    {
        $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api') || 
                 (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

        if (!headers_sent()) {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if ($origin !== '') {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            } else {
                header('Access-Control-Allow-Origin: *');
            }
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Acting-As');
        }

        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            $code = is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600
                ? $e->getCode()
                : 500;
            http_response_code($code);
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $payload = json_encode([
                'success' => false,
                'code' => $code,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Internal Server Error',
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ], $flags);
            echo $payload !== false ? $payload : '{"success":false,"code":500,"message":"Internal Server Error"}';
            exit;
        }

        http_response_code(500);
        echo "<div style='padding:20px;font-family:sans-serif;'>";
        echo "<h2>System Error</h2>";
        echo "<p style='color:#dc2626;font-size:16px;'>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre style='background:#f1f5f9;padding:15px;border-radius:6px;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</div>";
        exit;
    }
}
