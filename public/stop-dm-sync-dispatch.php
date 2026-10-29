<?php
declare(strict_types=1);

/**
 * 紧急终止「私信同步」计划入队（dm:sync）
 * ---------------------------------------------------------------
 * 浏览器访问：/stop-dm-sync-dispatch.php
 * 确认终止：/stop-dm-sync-dispatch.php?confirm=1
 * 重新开启：/stop-dm-sync-dispatch.php?enable=1
 *
 * 原因：
 *  清空 tasks / 会话后，crontab 仍跑 `php artisan dm:sync`，
 *  会按设置间隔给合格账号重新 INSERT dm_sync 任务；
 *  Node 开关一开 → attachDueQueuedTasks → 任务汇总又冒出「私信同步」。
 *  本系统没有 Redis 任务队列，源头是 MySQL + dm:sync cron。
 *
 * 做的事：
 *  1) settings.dmSyncDispatchEnabled = 0（新版 cron 认这个）
 *  2) 兼容旧版未部署代码：chat_connections.sync_messages=0 + 各用户 privateMessageSync=false
 *  3) 取消 queued/running 的 dm_sync 计划任务
 *  4) 取消队列 pending/running 的 dm_sync 子任务并收尾批次
 *  5) 刷新 chat_connections.last_synced_at
 *
 * 重要：crontab 跑的是服务器磁盘上的 PHP。本地改了必须同步到挂 cron 的那台机，
 * 否则旧版 DmSyncCommand 不读 dmSyncDispatchEnabled。本脚本用「旧代码也会检查」的字段双保险。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

$confirm = isset($_GET['confirm']) && (string)$_GET['confirm'] === '1';
$enable = isset($_GET['enable']) && (string)$_GET['enable'] === '1';

$configFile = is_file(__DIR__ . '/config/database.php')
    ? __DIR__ . '/config/database.php'
    : dirname(__DIR__) . '/config/database.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>
      body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:32px}
      .card{max-width:760px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px}
      h1{font-size:20px;margin:0 0 12px} h2{font-size:15px;margin:20px 0 8px;color:#93c5fd}
      p,li{line-height:1.6;color:#cbd5e1}
      code{background:#0f172a;padding:2px 6px;border-radius:4px}
      .ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}.muted{color:#94a3b8;font-size:13px}
      .btn{display:inline-block;margin-top:16px;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600}
      .btn-danger{background:#dc2626;color:#fff}.btn-ok{background:#16a34a;color:#fff}.btn-ghost{background:#334155;color:#e2e8f0;margin-left:8px}
      table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px}
      th,td{border-bottom:1px solid #334155;padding:8px;text-align:left}
    </style></head><body><div class="card">';
    echo $body;
    echo '</div></body></html>';
}

function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function ensureSettingsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS settings (
          `key` VARCHAR(100) NOT NULL PRIMARY KEY,
          `value` TEXT NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function setDmSyncFlag(PDO $pdo, bool $on): void
{
    ensureSettingsTable($pdo);
    $val = json_encode($on);
    $st = $pdo->prepare(
        'INSERT INTO settings(`key`, `value`) VALUES (?,?)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=CURRENT_TIMESTAMP'
    );
    $st->execute(['dmSyncDispatchEnabled', $val]);
}

function getDmSyncFlag(PDO $pdo): bool
{
    if (!tableExists($pdo, 'settings')) {
        return true;
    }
    $st = $pdo->prepare('SELECT `value` FROM settings WHERE `key`=? LIMIT 1');
    $st->execute(['dmSyncDispatchEnabled']);
    $raw = $st->fetchColumn();
    if ($raw === false || $raw === null) {
        return true;
    }
    $decoded = json_decode((string)$raw, true);
    if (is_bool($decoded)) {
        return $decoded;
    }
    return !in_array(strtolower(trim((string)$raw)), ['0', 'false', 'off', 'no'], true);
}

if (!is_file($configFile)) {
    page('配置缺失', '<h1 class="err">数据库配置不存在</h1><p>未找到 <code>' . h($configFile) . '</code></p>');
    exit;
}

$db = require $configFile;
$host = (string)($db['host'] ?? '127.0.0.1');
$port = (int)($db['port'] ?? 3306);
$database = (string)($db['database'] ?? 'tkswarm');
$username = (string)($db['username'] ?? 'root');
$password = (string)($db['password'] ?? '');
$charset = (string)($db['charset'] ?? 'utf8mb4');

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    page('连接失败', '<h1 class="err">数据库连接失败</h1><pre class="err">' . h($e->getMessage()) . '</pre>');
    exit;
}

$flagOn = getDmSyncFlag($pdo);
$queuedDm = tableExists($pdo, 'tasks')
    ? (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE type='dm_sync' AND status IN ('queued','running','paused')")->fetchColumn()
    : 0;
$pendingWork = tableExists($pdo, 'account_work_items')
    ? (int)$pdo->query("SELECT COUNT(*) FROM account_work_items WHERE task_type='dm_sync' AND status IN ('pending','running')")->fetchColumn()
    : 0;

if ($enable) {
    try {
        setDmSyncFlag($pdo, true);
        $syncMsgRestored = 0;
        if (tableExists($pdo, 'chat_connections')) {
            $syncMsgRestored = (int)$pdo->exec(
                'UPDATE chat_connections SET sync_messages=1, updated_at=CURRENT_TIMESTAMP'
            );
        }
        $userRestored = 0;
        if (tableExists($pdo, 'user_settings')) {
            $userRestored = (int)$pdo->exec(
                "UPDATE user_settings SET `value`='true', updated_at=CURRENT_TIMESTAMP
                 WHERE `key`='privateMessageSync'"
            );
        }
        page('已重新开启', '
          <h1 class="ok">已开启私信同步派发</h1>
          <ul>
            <li><code>dmSyncDispatchEnabled=1</code></li>
            <li>chat_connections.sync_messages 恢复：<b>' . $syncMsgRestored . '</b></li>
            <li>user_settings.privateMessageSync 恢复：<b>' . $userRestored . '</b></li>
          </ul>
          <p class="muted">请确认挂 cron 的服务器已部署含开关判断的 <code>DmSyncCommand.php</code>。</p>
          <a class="btn btn-ghost" href="?">查看状态</a>
          <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
        ');
    } catch (Throwable $e) {
        page('失败', '<h1 class="err">开启失败</h1><pre class="err">' . h($e->getMessage()) . '</pre>');
    }
    exit;
}

if (!$confirm) {
    page('终止私信同步派发', '
      <h1 class="warn">紧急终止私信同步（dm:sync）入队</h1>
      <p>清空计划任务后仍不断出现「私信同步」，是因为服务器 crontab 仍在执行
      <code>php artisan dm:sync</code>，会给合格账号<strong>新建</strong> dm_sync 任务（不是 Redis）。</p>
      <h2>当前快照</h2>
      <table><thead><tr><th>项</th><th>值</th></tr></thead><tbody>
        <tr><td>全局派发开关 dmSyncDispatchEnabled</td><td><b class="' . ($flagOn ? 'ok' : 'warn') . '">' . ($flagOn ? '开启' : '已关闭') . '</b></td></tr>
        <tr><td>计划任务 dm_sync（queued/running/paused）</td><td><b>' . $queuedDm . '</b></td></tr>
        <tr><td>会话子任务 dm_sync（pending/running）</td><td><b>' . $pendingWork . '</b></td></tr>
      </tbody></table>
      <h2>执行后</h2>
      <ul>
        <li>写入 <code>dmSyncDispatchEnabled=0</code>（新版代码）</li>
        <li>全部账号 <code>sync_messages=0</code> + 用户设置 <code>privateMessageSync=false</code>（旧版 cron 也会跳过）</li>
        <li>取消全部 dm_sync 计划任务与会话子任务</li>
        <li>刷新 last_synced_at</li>
      </ul>
      <p class="warn">若 crontab 机器上的代码未同步，仅写新开关不够；本工具已用旧字段兜底。也可临时注释掉 cron 里的 <code>dm:sync</code>。</p>
      <p class="muted">库：' . h($database) . '</p>
      <a class="btn btn-danger" href="?confirm=1" onclick="return confirm(\'确认终止全部私信同步派发？\')">确认终止派发</a>
      ' . ($flagOn ? '' : '<a class="btn btn-ok" href="?enable=1">重新开启派发</a>') . '
      <a class="btn btn-ghost" href="?">刷新</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
    exit;
}

try {
    setDmSyncFlag($pdo, false);

    // 旧版 DmSyncCommand 只认这两处，不认 dmSyncDispatchEnabled
    $syncMsgOff = 0;
    if (tableExists($pdo, 'chat_connections')) {
        $syncMsgOff = (int)$pdo->exec(
            'UPDATE chat_connections SET sync_messages=0, last_synced_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP'
        );
    }
    $userSyncOff = 0;
    if (tableExists($pdo, 'user_settings')) {
        // 已有行改 false；没有行的用户靠 defaults=true，再插一条 false
        $userSyncOff = (int)$pdo->exec(
            "UPDATE user_settings SET `value`='false', updated_at=CURRENT_TIMESTAMP
             WHERE `key`='privateMessageSync'"
        );
        $ownerIds = [];
        if (tableExists($pdo, 'accounts')) {
            $ownerIds = $pdo->query(
                'SELECT DISTINCT owner_id FROM accounts WHERE owner_id>0'
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        $ins = $pdo->prepare(
            "INSERT INTO user_settings(user_id, `key`, `value`) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=CURRENT_TIMESTAMP"
        );
        foreach ($ownerIds as $oid) {
            $oid = (int)$oid;
            if ($oid < 1) {
                continue;
            }
            $ins->execute([$oid, 'privateMessageSync', 'false']);
            $userSyncOff++;
        }
    }

    $tasksCancelled = 0;
    if (tableExists($pdo, 'tasks')) {
        $tasksCancelled = (int)$pdo->exec(
            "UPDATE tasks
             SET status='cancelled',
                 finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP),
                 updated_at=CURRENT_TIMESTAMP
             WHERE type='dm_sync' AND status IN ('queued','running','paused','draft')"
        );
    }

    $batchIds = [];
    if (tableExists($pdo, 'account_work_items')) {
        $batchIds = $pdo->query(
            "SELECT DISTINCT batch_id FROM account_work_items
             WHERE task_type='dm_sync' AND status IN ('pending','running') AND batch_id IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    $itemsCancelled = 0;
    if (tableExists($pdo, 'account_work_items')) {
        $itemsCancelled = (int)$pdo->exec(
            "UPDATE account_work_items
             SET status='cancelled',
                 finished_at=CURRENT_TIMESTAMP,
                 updated_at=CURRENT_TIMESTAMP,
                 error_message='紧急终止：私信同步派发已关闭'
             WHERE task_type='dm_sync' AND status IN ('pending','running')"
        );
    }

    $batchesClosed = 0;
    if (tableExists($pdo, 'account_work_batches') && $batchIds) {
        foreach ($batchIds as $bid) {
            $bid = (int)$bid;
            if ($bid < 1) {
                continue;
            }
            $active = (int)$pdo->query(
                "SELECT COUNT(*) FROM account_work_items
                 WHERE batch_id={$bid} AND status IN ('pending','running')"
            )->fetchColumn();
            if ($active > 0) {
                continue;
            }
            $batchesClosed += (int)$pdo->exec(
                "UPDATE account_work_batches
                 SET status=IF(item_fail>0 AND item_success<1,'failed','completed'),
                     locked_by='', lease_until=NULL, push_status='none',
                     finished_at=COALESCE(finished_at,CURRENT_TIMESTAMP),
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id={$bid} AND status IN ('pending','running','paused')"
            );
        }
    }

    page('终止完成', '
      <h1 class="ok">已终止私信同步派发</h1>
      <ul>
        <li>全局开关 dmSyncDispatchEnabled：<b>已关闭</b></li>
        <li>兼容旧 cron：sync_messages=0 影响行数 <b>' . $syncMsgOff . '</b></li>
        <li>兼容旧 cron：privateMessageSync=false 写入 <b>' . $userSyncOff . '</b></li>
        <li>取消 dm_sync 计划任务：<b>' . $tasksCancelled . '</b></li>
        <li>取消会话子任务：<b>' . $itemsCancelled . '</b></li>
        <li>收尾批次：<b>' . $batchesClosed . '</b></li>
      </ul>
      <p class="warn">请把本仓库 <code>src/Commands/DmSyncCommand.php</code> 同步到 crontab 所在服务器后再依赖新开关；未同步时靠上面的旧字段也能停。</p>
      <a class="btn btn-ok" href="?enable=1">重新开启派发</a>
      <a class="btn btn-ghost" href="?">查看状态</a>
      <a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>
    ');
} catch (Throwable $e) {
    page('执行失败', '<h1 class="err">终止失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
}
