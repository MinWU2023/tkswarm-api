<?php
declare(strict_types=1);

namespace App\Core;

class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $get;
    private array $post;
    private array $params;
    private array $headers;
    private ?array $json = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($this->uri, PHP_URL_PATH) ?? '/';
        $this->path = rawurldecode($path);
        $this->get = $_GET;
        $this->post = $_POST;
        $this->headers = $this->captureHeaders();
        
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $decoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->json = $decoded;
            }
        }
        $this->params = array_merge($this->get, $this->post, $this->json ?? []);
    }

    public static function capture(): self
    {
        return new self();
    }

    private function captureHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerKey = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerKey] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $headerKey = strtolower(str_replace('_', '-', $key));
                $headers[$headerKey] = $value;
            }
        }
        return $headers;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->params;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * 取标量字符串；若 JSON/表单把字段解析成数组（重复 key），取第一个非数组叶子，避免 (string)$array 触发 500。
     */
    public function stringInput(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        while (is_array($v)) {
            $v = reset($v);
            if ($v === false) {
                return $default;
            }
        }
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v ? '1' : '';
        }
        return trim((string)$v);
    }

    public function boolInput(string $key, bool $default = false): bool
    {
        $v = $this->input($key, $default);
        while (is_array($v)) {
            $v = reset($v);
            if ($v === false) {
                return $default;
            }
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (int)$v !== 0;
        }
        $s = strtolower(trim((string)($v ?? '')));
        if ($s === '') {
            return $default;
        }
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    /** 原始 JSON body（无则 null）；控制器里常用 `$request->json() ?: []` */
    public function json(): ?array
    {
        return $this->json;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? ($this->json[$key] ?? $default);
    }

    public function isJson(): bool
    {
        $contentType = $this->getHeader('content-type', '');
        return str_contains($contentType, 'application/json');
    }

    public function isAjax(): bool
    {
        return strtolower($this->getHeader('x-requested-with', '')) === 'xmlhttprequest';
    }

    public function bearerToken(): ?string
    {
        $auth = $this->getHeader('authorization', '');
        if (preg_match('/Bearer\s+(\S+)/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
