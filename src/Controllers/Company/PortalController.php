<?php
declare(strict_types=1);

namespace App\Controllers\Company;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * 企业客户门户 SPA 入口（独立于 /admin）
 */
class PortalController extends Controller
{
    /**
     * SPA 入口；部分路由带 {id}，PHP 8 named args 要求方法声明同名参数。
     */
    public function spa(Request $request, string $id = ''): Response
    {
        $file = dirname(__DIR__, 3) . '/public/company/index.html';
        if (!is_file($file)) {
            return Response::make('Company portal not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return Response::make((string)file_get_contents($file), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
