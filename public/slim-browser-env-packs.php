<?php
declare(strict_types=1);

/**
 * 环境包精简：解压 → 只保留登录会话目录 → 重新打包并原名覆盖
 *
 * 浏览器：
 *   /slim-browser-env-packs.php
 *   /slim-browser-env-packs.php?page=2&keyword=xxx
 *   /slim-browser-env-packs.php?action=slim&envId=123&confirm=1
 *
 * 规则与自研浏览器 1.0.41+ 一致：仅 Cookies / Local Storage / Preferences 等会话路径，
 * 去掉 IndexedDB、Service Worker、Cache、blob 等。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('max_execution_time', '300');
header('Content-Type: text/html; charset=utf-8');

$action = (string)($_GET['action'] ?? 'list');
$confirm = isset($_GET['confirm']) && (string)$_GET['confirm'] === '1';
$envId = max(0, (int)($_GET['envId'] ?? $_GET['id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(100, max(10, (int)($_GET['pageSize'] ?? 20)));
$keyword = trim((string)($_GET['keyword'] ?? ''));

$configFile = is_file(__DIR__ . '/config/database.php')
    ? __DIR__ . '/config/database.php'
    : dirname(__DIR__) . '/config/database.php';

/** 相对 userdata/ 的白名单（与 tkswarm-browses profile-backup 会话规则一致） */
const SESSION_KEEP_RELS = [
    'Local State',
    'First Run',
    'Default/Preferences',
    'Default/Secure Preferences',
    'Default/Cookies',
    'Default/Cookies-journal',
    'Default/Login Data',
    'Default/Login Data-journal',
    'Default/Local Storage',
    'Default/Session Storage',
    'Default/Network/Cookies',
    'Default/Network/Cookies-journal',
    'Default/Network/Network Persistent State',
    'Default/Network/TransportSecurity',
    'Default/Extension Cookies',
    'Default/Extension Cookies-journal',
];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatBytes(int $n): string
{
    if ($n <= 0) {
        return '0B';
    }
    if ($n < 1024) {
        return $n . 'B';
    }
    if ($n < 1048576) {
        return round($n / 1024) . 'KB';
    }
    return number_format($n / 1048576, 1) . 'MB';
}

function page(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>
      body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:28px}
      .card{max-width:1100px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:22px 24px}
      h1{font-size:20px;margin:0 0 8px} h2{font-size:15px;margin:18px 0 8px;color:#93c5fd}
      p,li{line-height:1.55;color:#cbd5e1;font-size:14px}
      .ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}.muted{color:#94a3b8;font-size:13px}
      code{background:#0f172a;padding:2px 6px;border-radius:4px;font-size:12px}
      .btn{display:inline-block;padding:8px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;border:0;cursor:pointer}
      .btn-danger{background:#dc2626;color:#fff}.btn-primary{background:#2563eb;color:#fff}
      .btn-ghost{background:#334155;color:#e2e8f0;margin-left:6px}
      .btn-sm{padding:5px 10px;font-size:12px}
      table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px}
      th,td{border-bottom:1px solid #334155;padding:9px 8px;text-align:left;vertical-align:top}
      th{color:#94a3b8;font-weight:600}
      .toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:14px 0}
      input[type=search],input[type=text]{background:#0f172a;border:1px solid #475569;color:#e2e8f0;padding:8px 10px;border-radius:8px;min-width:220px}
      .pager{display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap}
      .badge{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700}
      .badge-ok{background:#064e3b;color:#6ee7b7}.badge-warn{background:#78350f;color:#fcd34d}.badge-miss{background:#334155;color:#94a3b8}
      .flash{padding:10px 12px;border-radius:8px;margin:10px 0;background:#0f172a;border:1px solid #334155}
    </style></head><body><div class="card">';
    echo $body;
    echo '</div></body></html>';
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        if ($f->isDir()) {
            @rmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

function copyPathFiltered(string $src, string $dest): void
{
    if (!file_exists($src)) {
        return;
    }
    if (is_dir($src)) {
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        $dh = opendir($src);
        if ($dh === false) {
            return;
        }
        while (($name = readdir($dh)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            copyPathFiltered($src . DIRECTORY_SEPARATOR . $name, $dest . DIRECTORY_SEPARATOR . $name);
        }
        closedir($dh);
        return;
    }
    $parent = dirname($dest);
    if (!is_dir($parent)) {
        mkdir($parent, 0775, true);
    }
    copy($src, $dest);
}

/**
 * 从 userdata 根只拷贝会话白名单到新目录。
 * @return array{kept:int,bytes:int}
 */
function slimUserdata(string $srcUserdata, string $destUserdata): array
{
    if (!is_dir($destUserdata)) {
        mkdir($destUserdata, 0775, true);
    }
    $kept = 0;
    $bytes = 0;
    foreach (SESSION_KEEP_RELS as $rel) {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $rel))));
        $from = $srcUserdata . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        if (!file_exists($from)) {
            continue;
        }
        $to = $destUserdata . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        copyPathFiltered($from, $to);
        $kept++;
        if (is_file($to)) {
            $bytes += (int)filesize($to);
        } elseif (is_dir($to)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($to, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $bytes += (int)$f->getSize();
                }
            }
        }
    }
    return ['kept' => $kept, 'bytes' => $bytes];
}

function extractTgz(string $tgzPath, string $destDir): void
{
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }
    if (!class_exists('PharData')) {
        throw new RuntimeException('PHP 未启用 Phar 扩展，无法解压 .tgz');
    }
    $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tk-slim-x-' . bin2hex(random_bytes(6));
    mkdir($work, 0775, true);
    $tarGz = $work . DIRECTORY_SEPARATOR . 'pack.tar.gz';
    if (!@copy($tgzPath, $tarGz)) {
        rrmdir($work);
        throw new RuntimeException('复制环境包到临时目录失败');
    }
    try {
        $phar = new PharData($tarGz);
        // .tar.gz → 先解压出 .tar 再 extract，兼容部分 PHP/Phar 行为
        try {
            $phar->decompress(); // creates pack.tar beside pack.tar.gz
            $tar = $work . DIRECTORY_SEPARATOR . 'pack.tar';
            if (is_file($tar)) {
                $phar2 = new PharData($tar);
                $phar2->extractTo($destDir, null, true);
            } else {
                $phar->extractTo($destDir, null, true);
            }
        } catch (Throwable $e) {
            $phar->extractTo($destDir, null, true);
        }
    } finally {
        rrmdir($work);
    }
}

function buildTgzFromDir(string $srcDir, string $destTgz): void
{
    if (!class_exists('PharData')) {
        throw new RuntimeException('PHP 未启用 Phar 扩展，无法打包 .tgz');
    }
    $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tk-slim-p-' . bin2hex(random_bytes(6));
    mkdir($work, 0775, true);
    $tar = $work . DIRECTORY_SEPARATOR . 'pack.tar';
    try {
        if (is_file($tar)) {
            @unlink($tar);
        }
        $phar = new PharData($tar);
        $phar->buildFromDirectory($srcDir);
        // compress → pack.tar.gz
        $phar->compress(Phar::GZ);
        unset($phar);
        $gz = $tar . '.gz';
        if (!is_file($gz)) {
            throw new RuntimeException('压缩 .tar.gz 失败');
        }
        $parent = dirname($destTgz);
        if (!is_dir($parent)) {
            mkdir($parent, 0775, true);
        }
        if (is_file($destTgz)) {
            @unlink($destTgz);
        }
        if (!@rename($gz, $destTgz) && !@copy($gz, $destTgz)) {
            throw new RuntimeException('写出精简包失败');
        }
        @unlink($gz);
        @unlink($tar);
    } finally {
        rrmdir($work);
    }
}

/**
 * @return array{ok:bool,message:string,before?:int,after?:int,kept?:int}
 */
function slimOnePack(array $row): array
{
    $path = trim((string)($row['pack_path'] ?? ''));
    if ($path === '' || !is_file($path)) {
        return ['ok' => false, 'message' => '环境包文件不存在'];
    }
    $before = (int)filesize($path);
    $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tk-slim-w-' . bin2hex(random_bytes(6));
    mkdir($work, 0775, true);
    $extract = $work . DIRECTORY_SEPARATOR . 'in';
    $stage = $work . DIRECTORY_SEPARATOR . 'out';
    mkdir($extract, 0775, true);
    mkdir($stage, 0775, true);
    try {
        extractTgz($path, $extract);

        $metaSrc = $extract . DIRECTORY_SEPARATOR . 'meta.json';
        $userdataSrc = $extract . DIRECTORY_SEPARATOR . 'userdata';
        // 兼容：若解压后直接是 Chromium 目录（无 userdata 包装）
        if (!is_dir($userdataSrc)) {
            if (is_dir($extract . DIRECTORY_SEPARATOR . 'Default') || is_file($extract . DIRECTORY_SEPARATOR . 'Local State')) {
                $userdataSrc = $extract;
            } else {
                // 找第一层含 Default 的目录
                foreach (scandir($extract) ?: [] as $n) {
                    if ($n === '.' || $n === '..') {
                        continue;
                    }
                    $p = $extract . DIRECTORY_SEPARATOR . $n;
                    if (is_dir($p) && (is_dir($p . DIRECTORY_SEPARATOR . 'Default') || is_file($p . DIRECTORY_SEPARATOR . 'Local State'))) {
                        $userdataSrc = $p;
                        break;
                    }
                }
            }
        }
        if (!is_dir($userdataSrc)) {
            return ['ok' => false, 'message' => '包内未找到 userdata / Chromium 配置目录'];
        }

        if (is_file($metaSrc)) {
            copy($metaSrc, $stage . DIRECTORY_SEPARATOR . 'meta.json');
        } else {
            file_put_contents(
                $stage . DIRECTORY_SEPARATOR . 'meta.json',
                json_encode([
                    'format' => 'tkswarm-browser-profile',
                    'formatVersion' => 1,
                    'slimmedAt' => date('c'),
                    'note' => 'server-slim-session-only',
                    'profileId' => (string)($row['browser_profile_id'] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }

        $udOut = $stage . DIRECTORY_SEPARATOR . 'userdata';
        $stat = slimUserdata($userdataSrc, $udOut);
        if ($stat['kept'] < 1) {
            return ['ok' => false, 'message' => '白名单路径均不存在，已中止以免生成空包'];
        }

        $outTgz = $work . DIRECTORY_SEPARATOR . 'slim.tgz';
        buildTgzFromDir($stage, $outTgz);
        $after = (int)filesize($outTgz);
        if ($after < 64) {
            return ['ok' => false, 'message' => '精简后文件过小，已中止覆盖'];
        }

        $sha = hash_file('sha256', $outTgz) ?: '';
        $bak = $path . '.bak-' . date('YmdHis');
        @rename($path, $bak);
        if (!@rename($outTgz, $path) && !@copy($outTgz, $path)) {
            // 恢复备份
            if (is_file($bak)) {
                @rename($bak, $path);
            }
            return ['ok' => false, 'message' => '覆盖原环境包失败'];
        }
        @unlink($outTgz);
        // 成功后删备份，避免占盘；需要回滚可改成保留
        @unlink($bak);

        return [
            'ok' => true,
            'message' => '已精简并原名覆盖',
            'before' => $before,
            'after' => $after,
            'kept' => $stat['kept'],
            'sha256' => $sha,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } finally {
        rrmdir($work);
    }
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
    page('环境包精简', '<h1 class="err">数据库连接失败</h1><p>' . h($e->getMessage()) . '</p>');
    exit;
}

$flashHtml = '';

if ($action === 'slim' && $envId > 0) {
    $row = $pdo->prepare('SELECT * FROM account_browser_envs WHERE id=? LIMIT 1');
    $row->execute([$envId]);
    $env = $row->fetch() ?: null;
    if (!$env) {
        page('环境包精简', '<h1 class="err">记录不存在</h1><p><a class="btn btn-ghost" href="?page=' . (int)$page . '">返回列表</a></p>');
        exit;
    }
    $pid = (string)($env['browser_profile_id'] ?? '');
    $sizeNow = (is_file((string)($env['pack_path'] ?? '')) ? (int)filesize((string)$env['pack_path']) : 0);

    if (!$confirm) {
        $body = '<h1>确认精简环境包</h1>'
            . '<p>环境 ID：<code>' . h($pid) . '</code></p>'
            . '<p>绑定账号：#' . (int)($env['account_id'] ?? 0) . '</p>'
            . '<p>当前体积：<b>' . h(formatBytes($sizeNow)) . '</b></p>'
            . '<p class="muted">将解压包，仅保留会话白名单路径，再打包覆盖原文件（文件名不变），并更新 pack_bytes / sha256 / revision。</p>'
            . '<p><a class="btn btn-danger" href="?action=slim&envId=' . $envId . '&confirm=1&page=' . (int)$page . '&keyword=' . h(rawurlencode($keyword)) . '">确认精简并覆盖</a>'
            . '<a class="btn btn-ghost" href="?page=' . (int)$page . '&keyword=' . h(rawurlencode($keyword)) . '">取消</a></p>';
        page('环境包精简确认', $body);
        exit;
    }

    $result = slimOnePack($env);
    if ($result['ok']) {
        $rev = (int)($env['pack_revision'] ?? 0) + 1;
        $st = $pdo->prepare(
            'UPDATE account_browser_envs
             SET pack_sha256=?, pack_bytes=?, pack_revision=?, pack_updated_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
             WHERE id=?'
        );
        $st->execute([
            (string)($result['sha256'] ?? ''),
            (int)($result['after'] ?? 0),
            $rev,
            $envId,
        ]);
        $flashHtml = '<div class="flash ok">#' . $envId . ' / ' . h($pid)
            . '：' . h((string)$result['message'])
            . ' · ' . h(formatBytes((int)($result['before'] ?? 0)))
            . ' → ' . h(formatBytes((int)($result['after'] ?? 0)))
            . ' · 保留路径 ' . (int)($result['kept'] ?? 0)
            . ' · rev ' . $rev
            . '</div>';
    } else {
        $flashHtml = '<div class="flash err">#' . $envId . ' / ' . h($pid)
            . '：精简失败 — ' . h((string)$result['message']) . '</div>';
    }
    $action = 'list';
}

// —— 列表 ——
$where = ["TRIM(IFNULL(e.pack_path,''))<>''"];
$params = [];
if ($keyword !== '') {
    $where[] = '(e.browser_profile_id LIKE ? OR CAST(e.account_id AS CHAR) LIKE ? OR a.username LIKE ? OR a.nickname LIKE ? OR e.pack_path LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$countSt = $pdo->prepare(
    "SELECT COUNT(*) AS c
     FROM account_browser_envs e
     LEFT JOIN accounts a ON a.id = e.account_id
     WHERE {$whereSql}"
);
$countSt->execute($params);
$total = (int)($countSt->fetch()['c'] ?? 0);
$pages = max(1, (int)ceil($total / max(1, $pageSize)));
$page = min($page, $pages);
$offset = ($page - 1) * $pageSize;

$listSt = $pdo->prepare(
    "SELECT e.id, e.browser_profile_id, e.account_id, e.pack_path, e.pack_bytes, e.pack_sha256,
            e.pack_revision, e.pack_updated_at, e.label,
            a.username, a.nickname
     FROM account_browser_envs e
     LEFT JOIN accounts a ON a.id = e.account_id
     WHERE {$whereSql}
     ORDER BY e.pack_bytes DESC, e.id DESC
     LIMIT {$pageSize} OFFSET {$offset}"
);
$listSt->execute($params);
$rows = $listSt->fetchAll() ?: [];

$qBase = '?keyword=' . rawurlencode($keyword) . '&pageSize=' . $pageSize;

$rowsHtml = '';
if (!$rows) {
    $rowsHtml = '<tr><td colspan="8" class="muted">没有环境包记录</td></tr>';
} else {
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $pid = (string)($r['browser_profile_id'] ?? '');
        $path = (string)($r['pack_path'] ?? '');
        $exists = $path !== '' && is_file($path);
        $diskBytes = $exists ? (int)filesize($path) : 0;
        $dbBytes = (int)($r['pack_bytes'] ?? 0);
        $bytes = $diskBytes > 0 ? $diskBytes : $dbBytes;
        $user = trim((string)($r['username'] ?? ''));
        $nick = trim((string)($r['nickname'] ?? ''));
        $acct = $user !== ''
            ? ('#' . (int)$r['account_id'] . ' @' . $user . ($nick !== '' && $nick !== $user ? ' · ' . $nick : ''))
            : ('#' . (int)$r['account_id']);
        $badge = !$exists
            ? '<span class="badge badge-miss">文件缺失</span>'
            : ($bytes >= 8 * 1048576
                ? '<span class="badge badge-warn">偏大</span>'
                : '<span class="badge badge-ok">正常</span>');
        $slimBtn = $exists
            ? '<a class="btn btn-danger btn-sm" href="?action=slim&envId=' . $id . '&page=' . $page . '&keyword=' . h(rawurlencode($keyword)) . '">精简覆盖</a>'
            : '<span class="muted">—</span>';
        $rowsHtml .= '<tr>'
            . '<td>' . $id . '</td>'
            . '<td><code title="' . h($pid) . '">' . h(mb_substr($pid, 0, 16)) . (mb_strlen($pid) > 16 ? '…' : '') . '</code></td>'
            . '<td>' . h($acct) . '</td>'
            . '<td><b>' . h(formatBytes($bytes)) . '</b> ' . $badge . '</td>'
            . '<td>rev ' . (int)($r['pack_revision'] ?? 0) . '</td>'
            . '<td class="muted">' . h((string)($r['pack_updated_at'] ?? '-')) . '</td>'
            . '<td class="muted" style="max-width:220px;word-break:break-all">' . h(basename($path)) . '</td>'
            . '<td>' . $slimBtn . '</td>'
            . '</tr>';
    }
}

$pager = '<div class="pager"><span class="muted">共 ' . $total . ' 个 · 第 ' . $page . '/' . $pages . ' 页</span>';
if ($page > 1) {
    $pager .= '<a class="btn btn-ghost btn-sm" href="' . h($qBase . '&page=' . ($page - 1)) . '">上一页</a>';
}
if ($page < $pages) {
    $pager .= '<a class="btn btn-ghost btn-sm" href="' . h($qBase . '&page=' . ($page + 1)) . '">下一页</a>';
}
$pager .= '</div>';

$body = '<h1>环境包精简（原名覆盖）</h1>'
    . '<p class="muted">解压 → 仅保留登录会话白名单 → 重新打包覆盖原 <code>{profileId}.tgz</code>，并更新库内体积/校验/版本号。'
    . ' 请先升级自研浏览器到 1.0.41+；本工具用于清理中心库里已有的偏大旧包。</p>'
    . $flashHtml
    . '<div class="toolbar">'
    . '<form method="get" style="display:flex;gap:8px;align-items:center;margin:0">'
    . '<input type="search" name="keyword" value="' . h($keyword) . '" placeholder="环境ID / 账号 / 用户名">'
    . '<button class="btn btn-primary btn-sm" type="submit">查询</button>'
    . ($keyword !== '' ? '<a class="btn btn-ghost btn-sm" href="?">重置</a>' : '')
    . '</form>'
    . '<a class="btn btn-ghost btn-sm" href="/admin/#/quick-ops">返回快捷操作</a>'
    . '</div>'
    . '<table><thead><tr>'
    . '<th>ID</th><th>环境 ID</th><th>绑定账号</th><th>体积</th><th>版本</th><th>更新时间</th><th>文件</th><th>操作</th>'
    . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
    . $pager
    . '<h2>白名单路径</h2><ul class="muted">'
    . implode('', array_map(static fn ($r) => '<li><code>userdata/' . h($r) . '</code></li>', SESSION_KEEP_RELS))
    . '</ul>';

page('环境包精简', $body);
