<?php
declare(strict_types=1);

/**
 * 清除「拉取视频列表」写入的线上视频脏数据（不可逆）
 *
 * 会删除：
 * - materials.origin='online'（及关联 material_comments）
 * - tiktok_videos
 * - tiktok_video_screenshots
 *
 * 不会删除：本地上传素材（origin=local）、账号本身、任务主表。
 *
 * 浏览器访问：/clear-pulled-online-videos.php
 * 指定账号：  /clear-pulled-online-videos.php?accountId=123
 * 确认执行：  /clear-pulled-online-videos.php?confirm=1
 *             /clear-pulled-online-videos.php?accountId=123&confirm=1
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

$confirm = isset($_GET['confirm']) && (string)$_GET['confirm'] === '1';
$accountId = max(0, (int)($_GET['accountId'] ?? $_GET['account_id'] ?? 0));

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
      .card{max-width:860px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px}
      h1{font-size:20px;margin:0 0 12px} h2{font-size:15px;margin:20px 0 8px;color:#93c5fd}
      p,li{line-height:1.6;color:#cbd5e1}
      code{background:#0f172a;padding:2px 6px;border-radius:4px}
      .ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}.muted{color:#94a3b8;font-size:13px}
      .btn{display:inline-block;margin-top:16px;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600}
      .btn-danger{background:#dc2626;color:#fff}.btn-ghost{background:#334155;color:#e2e8f0;margin-left:8px}
      table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px}
      th,td{border-bottom:1px solid #334155;padding:8px;text-align:left}
      th{color:#94a3b8;font-weight:600}
      input[type=number]{background:#0f172a;border:1px solid #475569;color:#e2e8f0;padding:8px 10px;border-radius:8px;width:160px}
      form{margin-top:12px}
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

try {
    if (!is_file($configFile)) {
        throw new RuntimeException('找不到数据库配置：' . $configFile);
    }
    /** @var array<string,mixed> $cfg */
    $cfg = require $configFile;
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $cfg['host'] ?? '127.0.0.1',
        $cfg['port'] ?? 3306,
        $cfg['database'] ?? ($cfg['dbname'] ?? '')
    );
    $pdo = new PDO($dsn, (string)($cfg['username'] ?? $cfg['user'] ?? ''), (string)($cfg['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    page('清除拉取视频列表数据', '<h1 class="err">数据库连接失败</h1><p>' . h($e->getMessage()) . '</p>');
    exit;
}

$scopeLabel = $accountId > 0 ? ('账号 #' . $accountId) : '全部账号';
$accSql = $accountId > 0 ? ' AND publish_account_id=?' : '';
$tvSql = $accountId > 0 ? ' WHERE account_id=?' : '';
$params = $accountId > 0 ? [$accountId] : [];

$stats = [
    'online_materials' => 0,
    'material_comments' => 0,
    'tiktok_videos' => 0,
    'tiktok_video_screenshots' => 0,
];

try {
    if (tableExists($pdo, 'materials')) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM materials WHERE origin='online'{$accSql}");
        $st->execute($params);
        $stats['online_materials'] = (int)$st->fetchColumn();
    }
    if (tableExists($pdo, 'material_comments') && tableExists($pdo, 'materials')) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM material_comments mc
             INNER JOIN materials m ON m.id=mc.material_id
             WHERE m.origin='online'{$accSql}"
        );
        $st->execute($params);
        $stats['material_comments'] = (int)$st->fetchColumn();
    }
    if (tableExists($pdo, 'tiktok_videos')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM tiktok_videos' . ($accountId > 0 ? ' WHERE account_id=?' : ''));
        $st->execute($params);
        $stats['tiktok_videos'] = (int)$st->fetchColumn();
    }
    if (tableExists($pdo, 'tiktok_video_screenshots')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM tiktok_video_screenshots' . ($accountId > 0 ? ' WHERE account_id=?' : ''));
        $st->execute($params);
        $stats['tiktok_video_screenshots'] = (int)$st->fetchColumn();
    }
} catch (Throwable $e) {
    page('清除拉取视频列表数据', '<h1 class="err">预览失败</h1><p>' . h($e->getMessage()) . '</p>');
    exit;
}

$topAccounts = [];
try {
    if (tableExists($pdo, 'materials')) {
        $st = $pdo->query(
            "SELECT publish_account_id AS aid, publish_account AS uname, COUNT(*) AS c
             FROM materials WHERE origin='online' AND publish_account_id>0
             GROUP BY publish_account_id, publish_account
             ORDER BY c DESC LIMIT 20"
        );
        $topAccounts = $st ? ($st->fetchAll() ?: []) : [];
    }
} catch (Throwable $e) {
    $topAccounts = [];
}

if (!$confirm) {
    $rows = '';
    foreach ($stats as $k => $v) {
        $rows .= '<tr><td><code>' . h($k) . '</code></td><td>' . (int)$v . '</td></tr>';
    }
    $accRows = '';
    foreach ($topAccounts as $r) {
        $aid = (int)($r['aid'] ?? 0);
        $accRows .= '<tr><td>#' . $aid . ' @' . h((string)($r['uname'] ?? '')) . '</td><td>'
            . (int)($r['c'] ?? 0) . '</td><td><a class="btn btn-ghost" style="margin:0;padding:4px 10px;font-size:12px" href="?accountId='
            . $aid . '">只清此号</a></td></tr>';
    }
    $qs = $accountId > 0 ? ('accountId=' . $accountId . '&confirm=1') : 'confirm=1';
    $body = '<h1>清除拉取视频列表数据</h1>'
        . '<p class="warn">用于清理「拉取视频列表」误吸入的非本账号 / 推荐视频脏数据。</p>'
        . '<p>当前范围：<b>' . h($scopeLabel) . '</b></p>'
        . '<form method="get"><label class="muted">按账号 ID 过滤（留空=全部）</label><br>'
        . '<input type="number" name="accountId" min="0" value="' . ($accountId > 0 ? $accountId : '') . '" placeholder="accountId">'
        . ' <button class="btn btn-ghost" type="submit" style="margin-top:0">预览</button></form>'
        . '<h2>将删除的数据量</h2><table><thead><tr><th>目标</th><th>行数</th></tr></thead><tbody>'
        . $rows . '</tbody></table>'
        . ($accRows !== '' ? ('<h2>线上素材最多的账号</h2><table><thead><tr><th>账号</th><th>online 素材</th><th></th></tr></thead><tbody>'
            . $accRows . '</tbody></table>') : '')
        . '<p class="muted">保留：本地上传素材（origin=local）、账号、发布/拉评任务主表。</p>'
        . '<p class="muted">根因已修：Studio 监听过宽 + 无作者过滤；重拉列表前请先清脏数据。</p>'
        . '<a class="btn btn-danger" href="?' . h($qs) . '" onclick="return confirm(\'确认永久删除 '
        . h($scopeLabel) . ' 的拉取列表视频数据？不可撤销！\')">确认清除</a>'
        . '<a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>';
    page('清除拉取视频列表数据', $body);
    exit;
}

$deleted = [
    'material_comments' => 0,
    'online_materials' => 0,
    'tiktok_videos' => 0,
    'tiktok_video_screenshots' => 0,
];
$errors = [];

try {
    $pdo->beginTransaction();

    if (tableExists($pdo, 'material_comments') && tableExists($pdo, 'materials')) {
        if ($accountId > 0) {
            $st = $pdo->prepare(
                "DELETE mc FROM material_comments mc
                 INNER JOIN materials m ON m.id=mc.material_id
                 WHERE m.origin='online' AND m.publish_account_id=?"
            );
            $st->execute([$accountId]);
            $deleted['material_comments'] = $st->rowCount();
        } else {
            $deleted['material_comments'] = (int)$pdo->exec(
                "DELETE mc FROM material_comments mc
                 INNER JOIN materials m ON m.id=mc.material_id
                 WHERE m.origin='online'"
            );
        }
    }

    if (tableExists($pdo, 'materials')) {
        if ($accountId > 0) {
            $st = $pdo->prepare("DELETE FROM materials WHERE origin='online' AND publish_account_id=?");
            $st->execute([$accountId]);
            $deleted['online_materials'] = $st->rowCount();
        } else {
            $deleted['online_materials'] = (int)$pdo->exec("DELETE FROM materials WHERE origin='online'");
        }
    }

    if (tableExists($pdo, 'tiktok_videos')) {
        if ($accountId > 0) {
            $st = $pdo->prepare('DELETE FROM tiktok_videos WHERE account_id=?');
            $st->execute([$accountId]);
            $deleted['tiktok_videos'] = $st->rowCount();
        } else {
            $deleted['tiktok_videos'] = (int)$pdo->exec('DELETE FROM tiktok_videos');
        }
    }

    if (tableExists($pdo, 'tiktok_video_screenshots')) {
        if ($accountId > 0) {
            $st = $pdo->prepare('DELETE FROM tiktok_video_screenshots WHERE account_id=?');
            $st->execute([$accountId]);
            $deleted['tiktok_video_screenshots'] = $st->rowCount();
        } else {
            $deleted['tiktok_video_screenshots'] = (int)$pdo->exec('DELETE FROM tiktok_video_screenshots');
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errors[] = $e->getMessage();
}

$lines = '';
foreach ($deleted as $k => $v) {
    $lines .= '<tr><td><code>' . h($k) . '</code></td><td class="ok">' . (int)$v . '</td></tr>';
}
$errHtml = $errors
    ? ('<p class="err">' . h(implode('; ', $errors)) . '</p>')
    : '<p class="ok">清除完成。可重新对账号执行「拉取视频列表」。</p>';

page(
    '清除拉取视频列表数据',
    '<h1>清除结果 · ' . h($scopeLabel) . '</h1>'
    . $errHtml
    . '<table><thead><tr><th>目标</th><th>删除行数</th></tr></thead><tbody>' . $lines . '</tbody></table>'
    . '<a class="btn btn-ghost" href="/clear-pulled-online-videos.php">返回预览</a>'
    . '<a class="btn btn-ghost" href="/admin/#/quick-ops">返回快捷操作</a>'
);
