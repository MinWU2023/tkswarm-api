<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 把头像 /uploads 相对路径或本机 127.0.0.1 地址转成 API 公网可访问 URL。
 * 尽量少用含 # 的字符类正则，避免分隔符冲突。
 */
class MediaUrl
{
    public static function appBase(): string
    {
        $candidates = [
            rtrim((string)(getenv('APP_URL') ?: ''), '/'),
        ];
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '') {
            $candidates[] = ($https ? 'https://' : 'http://') . $host;
        }
        $candidates[] = 'https://tkswarm-api.dyyweb.com';

        foreach ($candidates as $base) {
            $base = rtrim((string)$base, '/');
            if ($base === '' || self::isLocalBase($base)) {
                continue;
            }
            return $base;
        }
        return 'https://tkswarm-api.dyyweb.com';
    }

    private static function isLocalBase(string $base): bool
    {
        return (bool)preg_match('~^https?://(?:127\.0\.0\.1|localhost)(?::\d+)?/?$~i', $base);
    }

    /** 从 /uploads/xxx 或 URL 末尾取出文件名 */
    private static function uploadFileName(string $path): string
    {
        $path = explode('?', $path, 2)[0];
        $path = explode('#', $path, 2)[0];
        $pos = stripos($path, '/uploads/');
        if ($pos === false) {
            return '';
        }
        $name = basename(substr($path, $pos + strlen('/uploads/')));
        return preg_match('~^[\w.\-]+$~', $name) ? $name : '';
    }

    public static function publicUrl(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, 'data:') || str_starts_with($path, 'blob:')) {
            return $path;
        }

        // http://127.0.0.1:PORT/uploads/xxx → /api/media/xxx
        if (preg_match('~^https?://(?:127\.0\.0\.1|localhost)(?::\d+)/uploads/~i', $path)
            || preg_match('~^https?://(?:127\.0\.0\.1|localhost)/uploads/~i', $path)
        ) {
            $name = self::uploadFileName($path);
            if ($name !== '') {
                return self::appBase() . '/api/media/' . rawurlencode($name);
            }
        }

        if (preg_match('~^(https?:)?//~i', $path)) {
            if (stripos($path, 'tkswarm-api.dyyweb.com') !== false && stripos($path, '/uploads/') !== false) {
                $name = self::uploadFileName($path);
                if ($name !== '') {
                    return self::appBase() . '/api/media/' . rawurlencode($name);
                }
            }
            return $path;
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if (str_starts_with($path, '/uploads/')) {
            $name = self::uploadFileName($path);
            if ($name !== '') {
                return self::appBase() . '/api/media/' . rawurlencode($name);
            }
        }
        if (str_starts_with($path, '/api/media/')) {
            return self::appBase() . $path;
        }

        return $path;
    }
}
