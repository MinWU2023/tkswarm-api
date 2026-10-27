<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * 官网落地页：根域名介绍与下载入口
 */
class LandingController extends Controller
{
    public function spa(Request $request): Response
    {
        $file = dirname(__DIR__, 3) . '/public/landing/index.html';
        if (!is_file($file)) {
            return Response::make('Landing page not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return Response::make(file_get_contents($file), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
