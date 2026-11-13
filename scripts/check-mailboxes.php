<?php
declare(strict_types=1);

/**
 * 逐个检查邮箱库里的未使用邮箱：读收件箱，收到过 TikTok 邮件（验证码等）的说明已经被注册过，
 * 标记为 blocked（不可用），之后 mailbox() 取邮箱时会忽略；没收到过的保持 unused 并记录检查时间。
 *
 * 用法（在 tkswarm-api 目录下）：
 *   php scripts/check-mailboxes.php                 检查所有还没检查过的未使用邮箱
 *   php scripts/check-mailboxes.php --limit=50      只检查前 50 个
 *   php scripts/check-mailboxes.php --sleep=2000    每个邮箱之间间隔 2000ms（默认 1500）
 *   php scripts/check-mailboxes.php --recheck       连已检查过的未使用邮箱一起重新检查
 *   php scripts/check-mailboxes.php --stats         只看统计，不检查
 * 可以中断后重复运行：已检查的会跳过，读信失败的邮箱保持未检查，下次继续。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_PATH', dirname(__DIR__));
require APP_PATH . '/autoload.php';

use App\Core\App;
use App\Core\Database\DB;
use App\Services\RegisterMailboxService as Box;

@set_time_limit(0);
new App(APP_PATH);

$opt = getopt('', ['limit::', 'sleep::', 'recheck', 'stats']);
$limit = isset($opt['limit']) ? max(0, (int)$opt['limit']) : 0;
$sleepMs = isset($opt['sleep']) ? max(0, (int)$opt['sleep']) : 1500;
$recheck = isset($opt['recheck']);

$added = Box::syncFromFile();
echo "[mailbox] emails.txt 新导入 {$added} 条\n";

$printStats = static function (): void {
    $r = DB::selectOne("SELECT COUNT(*) AS total, SUM(status='unused') AS unused, SUM(status='used') AS used, SUM(status='blocked') AS blocked, SUM(status='unused' AND checked_at IS NULL) AS pending FROM `register_mailboxes`") ?: [];
    echo sprintf("[mailbox] 共 %d 条：可用 %d（其中未检查 %d）/ 已使用 %d / 不可用 %d\n",
        (int)($r['total'] ?? 0), (int)($r['unused'] ?? 0), (int)($r['pending'] ?? 0), (int)($r['used'] ?? 0), (int)($r['blocked'] ?? 0));
};

if (isset($opt['stats'])) {
    $printStats();
    exit(0);
}

$todo = Box::pendingCheck($limit, $recheck);
$total = count($todo);
echo "[mailbox] 待检查 {$total} 个\n";

$blocked = 0;
$clean = 0;
$errors = 0;
$consecutiveErrors = 0;

foreach ($todo as $i => $row) {
    $n = $i + 1;
    $res = null;
    for ($try = 1; $try <= 4; $try++) {
        $res = Box::fetchMessages($row['email']);
        if ($res['ok'] || $res['status'] !== 429) {
            break;
        }
        $wait = $res['retryAfter'] > 0 ? min(120, $res['retryAfter']) : 20;
        echo "[mailbox] {$n}/{$total} 被限流，{$wait} 秒后重试（第 {$try} 次）\n";
        sleep($wait);
    }

    if (!$res['ok']) {
        $errors++;
        $consecutiveErrors++;
        echo "[mailbox] {$n}/{$total} {$row['email']} 读信失败，保持未检查：{$res['error']}\n";
        if ($consecutiveErrors >= 8) {
            echo "[mailbox] 连续 8 次失败，先停下（检查密钥、网络或上游状态后重新运行即可继续）\n";
            break;
        }
    } else {
        $consecutiveErrors = 0;
        $info = Box::inspectInbox($res['data'], $row['email']);
        if ($info['hit']) {
            Box::markBlocked($row['id'], '收件箱已有 TikTok 邮件（已注册过或收到过验证码）');
            $blocked++;
            echo "[mailbox] {$n}/{$total} {$row['email']} 收到过 TikTok 邮件 → 不可用\n";
        } else {
            Box::markChecked($row['id']);
            $clean++;
            echo "[mailbox] {$n}/{$total} {$row['email']} 干净（收件箱 {$info['count']} 封）\n";
        }
    }
    if ($sleepMs > 0 && $n < $total) {
        usleep($sleepMs * 1000);
    }
}

echo "[mailbox] 本次：不可用 {$blocked} / 干净 {$clean} / 读信失败 {$errors}\n";
$printStats();
exit($errors > 0 ? 2 : 0);
