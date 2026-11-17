<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 把头像 /uploads 相对路径或本机 127.0.0.1 地址转成 API 公网可访问 URL。
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
        $candidates[] = 'http://tkswarm-api.dyyweb.com';

        foreach ($candidates as $base) {
            $base = rtrim((string)$base, '/');
            if ($base === '' || self::isLocalBase($base)) {
                continue;
            }
            return $base;
        }
        return 'http://tkswarm-api.dyyweb.com';
    }

    private static function isLocalBase(string $base): bool
    {
        return (bool)preg_match('#^https?://(?:127\.0\.0\.1|localhost)(?::\d+)?/?$#i', $base);
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

        // http://127.0.0.1:PORT/uploads/xxx → 公网 API /uploads/xxx
        if (preg_match('#^https?://(?:127\.0\.0\.1|localhost)(?::\d+)?(/uploads/.+)$#i', $path, $m)) {
            return self::appBase() . $m[1];
        }

        if (preg_match('#^(https?:)?//#i', $path)) {
            return $path;
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        // /uploads/... 挂在 API public 下，企业门户与其它端统一走公网域名
        if (str_starts_with($path, '/uploads/')) {
            return self::appBase() . $path;
        }

        return $path;
    }
}
