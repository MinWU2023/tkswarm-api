<?php
declare(strict_types=1);

/**
 * 后台部署 worker：php scripts/ssh-deploy-worker.php {id}
 */
define('APP_PATH', dirname(__DIR__));
require APP_PATH . '/autoload.php';

use App\Core\App;
use App\Services\SshServer\SshServerService;

$id = isset($argv[1]) ? (int)$argv[1] : 0;
if ($id <= 0) {
    fwrite(STDERR, "usage: php ssh-deploy-worker.php {id}\n");
    exit(1);
}

@set_time_limit(0);
ignore_user_abort(true);

// 初始化 DB 等
new App(APP_PATH);

fwrite(STDOUT, '[worker] deploy ssh_server#' . $id . "\n");
$res = SshServerService::deploy($id);
fwrite(STDOUT, '[worker] ' . ($res['ok'] ? 'OK' : 'FAIL') . ' ' . ($res['message'] ?? '') . "\n");
exit(!empty($res['ok']) ? 0 : 2);
