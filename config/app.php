<?php
declare(strict_types=1);

return [
    'name' => 'TKSwarm Enterprise Matrix API',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => (bool)(getenv('APP_DEBUG') ?: false),
    'url' => getenv('APP_URL') ?: 'http://127.0.0.1:8000',
    'timezone' => 'Asia/Shanghai',
];
