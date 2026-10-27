<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Request;
use App\Core\Response;

class CorsMiddleware
{
    public static function apply(?string $origin = null): void
    {
        if (headers_sent()) {
            return;
        }
        $origin = $origin ?: ($_SERVER['HTTP_ORIGIN'] ?? '');
        // 反射请求 Origin，兼容带 Authorization 的跨域；无 Origin 时用 *
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Acting-As');
        header('Access-Control-Expose-Headers: Content-Disposition, Content-Type');
        header('Access-Control-Max-Age: 86400');
    }

    public function handle(Request $request): ?Response
    {
        self::apply($request->getHeader('Origin'));
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return Response::make('', 204, self::headers($request->getHeader('Origin')));
        }
        return null;
    }

    public static function headers(?string $origin = null): array
    {
        $origin = $origin ?: ($_SERVER['HTTP_ORIGIN'] ?? '');
        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Acting-As',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Access-Control-Max-Age' => '86400',
        ];
        if ($origin !== '') {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Vary'] = 'Origin';
        } else {
            $headers['Access-Control-Allow-Origin'] = '*';
        }
        return $headers;
    }
}
