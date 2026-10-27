<?php
declare(strict_types=1);

namespace App\Core;

class View
{
    public static function render(string $view, array $data = []): Response
    {
        $basePath = App::getInstance()?->getBasePath() ?? dirname(__DIR__, 2);
        $viewPath = $basePath . '/src/Views/' . str_replace('.', '/', $view) . '.php';

        if (!file_exists($viewPath)) {
            throw new \RuntimeException("View [{$view}] not found at {$viewPath}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        $content = ob_get_clean();

        return Response::make($content);
    }
}
