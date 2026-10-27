<?php
declare(strict_types=1);

namespace App\Core;

class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private mixed $content = '';

    public function __construct(mixed $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public static function make(mixed $content = '', int $statusCode = 200, array $headers = []): self
    {
        return new self($content, $statusCode, $headers);
    }

    public static function json(mixed $data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $content = json_encode($data, $flags);
        if ($content === false) {
            $content = '{"success":false,"code":500,"message":"JSON encode failed","data":null}';
            $statusCode = 500;
        }
        return new self($content, $statusCode, $headers);
    }

    public static function success(mixed $data = null, string $message = 'success', int $code = 200): self
    {
        return self::json([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    public static function error(string $message = 'error', int $code = 400, mixed $data = null): self
    {
        return self::json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'data' => $data
        ], $code >= 400 && $code < 600 ? $code : 400);
    }

    private ?string $filePath = null;
    private ?int $fileStart = null;
    private ?int $fileEnd = null;

    public static function file(string $path, int $statusCode = 200, array $headers = [], ?int $start = null, ?int $end = null): self
    {
        $res = new self('', $statusCode, $headers);
        $res->filePath = $path;
        $res->fileStart = $start;
        $res->fileEnd = $end;
        return $res;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContent(): mixed
    {
        return $this->content;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            // 确保 JSON/文件响应也带跨域头（含 404/500）
            if (!isset($this->headers['Access-Control-Allow-Origin'])) {
                $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
                if ($origin !== '') {
                    $this->headers['Access-Control-Allow-Origin'] = $origin;
                    $this->headers['Vary'] = 'Origin';
                } else {
                    $this->headers['Access-Control-Allow-Origin'] = '*';
                }
                $this->headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
                $this->headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization, X-Requested-With, Accept, Origin';
            }
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }
        // HEAD 只返回头，不输出正文（视频探测依赖）
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            exit;
        }
        echo (string)$this->content;
        if ($this->filePath && is_file($this->filePath)) {
            $fp = fopen($this->filePath, 'rb');
            if ($fp) {
                if ($this->fileStart !== null) {
                    fseek($fp, $this->fileStart);
                    $left = ($this->fileEnd ?? (filesize($this->filePath) - 1)) - $this->fileStart + 1;
                    while ($left > 0 && !feof($fp)) {
                        $chunk = fread($fp, min(8192, $left));
                        if ($chunk === false) {
                            break;
                        }
                        echo $chunk;
                        $left -= strlen($chunk);
                    }
                } else {
                    fpassthru($fp);
                }
                fclose($fp);
            }
        }
        exit;
    }
}
