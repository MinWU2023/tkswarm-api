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
    public function spa(Request $request): Response
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
