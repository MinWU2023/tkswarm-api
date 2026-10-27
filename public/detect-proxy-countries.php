<?php
declare(strict_types=1);

/**
 * 批量识别代理国家/地区（按 host 公网 IP 查询，写入 proxies.country / region / city）
 * 浏览器访问：/detect-proxy-countries.php
 * 仅空值：/detect-proxy-countries.php?confirm=1
 * 强制全量：/detect-proxy-countries.php?confirm=1&force=1
 * 限制条数：&limit=100（默认 200，最大 2000）
 *
 * 说明：基于代理主机 IP 的地理位置，不等同于经代理探测出口 IP；
 * 需要更精确出口国家时请用代理页「批量检测」。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('max_execution_time', '0');
header('Content-Type: text/html; charset=utf-8');

$confirm = isset($_GET['confirm']) && (string)$_GET['confirm'] === '1';
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
$limit = max(1, min(2000, (int)($_GET['limit'] ?? 200)));

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
      .card{max-width:960px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px}
      h1{font-size:20px;margin:0 0 12px}
      p,li{line-height:1.6;color:#cbd5e1}
      code{background:#0f172a;padding:2px 6px;border-radius:4px}
      .ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}.muted{color:#94a3b8;font-size:13px}
      .btn{display:inline-block;margin-top:16px;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600}
      .btn-primary{background:#2563eb;color:#fff}.btn-ghost{background:#334155;color:#e2e8f0;margin-left:8px}
      table{width:100%;border-collapse:collapse;margin-top:16px;font-size:13px}
      th,td{border-bottom:1px solid #334155;padding:8px;text-align:left;vertical-align:top}
      .scroll{max-height:420px;overflow:auto}
    </style></head><body><div class="card">';
    echo $body;
    echo '</div></body></html>';
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

/** @return array{country:string,region:string,city:string,query:string,error:string} */
function lookupGeo(string $host): array
{
    $empty = ['country' => '', 'region' => '', 'city' => '', 'query' => '', 'error' => ''];
    $host = trim($host);
    if ($host === '') {
        $empty['error'] = 'empty host';
        return $empty;
    }
    // 域名先解析成 IPv4
    $ip = $host;
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $resolved = gethostbyname($host);
        if ($resolved === $host || !filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $empty['error'] = 'host resolve failed';
            return $empty;
        }
        $ip = $resolved;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $empty['error'] = 'not ipv4';
        return $empty;
    }
    // 私网/回环不查
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $empty['error'] = 'private/reserved ip';
        $empty['query'] = $ip;
        return $empty;
    }

    // lang=zh-CN：国家/州省/城市返回中文名
    $url = 'http://ip-api.com/json/' . rawurlencode($ip)
        . '?lang=zh-CN&fields=status,message,country,countryCode,regionName,city,query';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'header' => "Accept: application/json\r\nUser-Agent: TkSwarm-detect-proxy-countries/1.0\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        $empty['error'] = 'http failed';
        $empty['query'] = $ip;
        return $empty;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        $empty['error'] = (string)($data['message'] ?? 'lookup failed');
        $empty['query'] = $ip;
        return $empty;
    }
    // 优先中文国家名；若无则回退国家代码
    $country = trim((string)($data['country'] ?? ''));
    if ($country === '') {
        $country = trim((string)($data['countryCode'] ?? ''));
    }
    return [
        'country' => $country,
        'region' => trim((string)($data['regionName'] ?? '')),
        'city' => trim((string)($data['city'] ?? '')),
        'query' => trim((string)($data['query'] ?? $ip)),
        'error' => '',
    ];
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

$hasRegion = columnExists($pdo, 'proxies', 'region');
$hasCity = columnExists($pdo, 'proxies', 'city');

$total = (int)$pdo->query('SELECT COUNT(*) FROM proxies')->fetchColumn();
$emptyCountry = (int)$pdo->query("SELECT COUNT(*) FROM proxies WHERE TRIM(COALESCE(country,''))=''")->fetchColumn();
$withCountry = $total - $emptyCountry;

$where = $force
    ? '1=1'
    : "TRIM(COALESCE(country,''))=''";
$countSql = "SELECT COUNT(*) FROM proxies WHERE {$where}";
$todoCount = (int)$pdo->query($countSql)->fetchColumn();

if (!$confirm) {
    $sample = $pdo->query(
        "SELECT id, host, port, protocol, country, "
        . ($hasRegion ? 'region' : "'' AS region") . ', '
        . ($hasCity ? 'city' : "'' AS city")
        . " FROM proxies WHERE {$where} ORDER BY id ASC LIMIT 20"
    )->fetchAll();

    $rowsHtml = '';
    foreach ($sample as $r) {
        $loc = trim(implode(' · ', array_filter([
            (string)($r['country'] ?? ''),
            (string)($r['region'] ?? ''),
            (string)($r['city'] ?? ''),
        ])));
        $rowsHtml .= '<tr>'
            . '<td>#' . (int)$r['id'] . '</td>'
            . '<td><code>' . h((string)$r['protocol']) . '://' . h((string)$r['host']) . ':' . (int)$r['port'] . '</code></td>'
            . '<td>' . h($loc !== '' ? $loc : '（空）') . '</td>'
            . '</tr>';
    }
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="3" class="muted">没有待处理代理</td></tr>';
    }

    $modeLabel = $force ? '强制重检全部有 host 的代理' : '仅检测 country 为空的代理';
    page('检测代理国家/地区', '
      <h1>批量写入代理国家 / 地区</h1>
      <p>按代理 <code>host</code> 的公网 IP 调用 ip-api 写入 <code>proxies.country</code>'
        . ($hasRegion ? ' / <code>region</code>' : '')
        . ($hasCity ? ' / <code>city</code>' : '')
        . '。</p>
      <p class="muted">库：' . h($database)
        . ' · 代理总数 <b>' . $total . '</b>'
        . ' · 已有国家 <b class="ok">' . $withCountry . '</b>'
        . ' · 国家为空 <b class="warn">' . $emptyCountry . '</b>'
        . ' · 本次模式将处理约 <b>' . min($todoCount, $limit) . '</b> / ' . $todoCount . ' 条（limit=' . $limit . '）</p>
      <p>当前模式：<b>' . h($modeLabel) . '</b></p>
      <div class="scroll"><table><thead><tr><th>ID</th><th>代理</th><th>现有国家/地区</th></tr></thead><tbody>'
        . $rowsHtml . '</tbody></table></div>
      <a class="btn btn-primary" href="?confirm=1&limit=' . $limit . '" onclick="return confirm(\'确认开始检测并写入数据库？\')">开始检测（仅空值）</a>
      <a class="btn btn-ghost" href="?confirm=1&force=1&limit=' . $limit . '" onclick="return confirm(\'确认强制重检？已有国家也会被覆盖。\')">强制全量重检</a>
      <a class="btn btn-ghost" href="?">刷新</a>
      <p class="muted" style="margin-top:16px">提示：免费接口约 45 次/分钟，脚本会自动间隔请求。更精确的「经代理出口 IP」国家请用代理管理页的批量检测。</p>
    ');
    exit;
}

try {
    $sql = 'SELECT id, host, port, protocol, country'
        . ($hasRegion ? ', region' : '')
        . ($hasCity ? ', city' : '')
        . " FROM proxies WHERE {$where} ORDER BY id ASC LIMIT {$limit}";
    $rows = $pdo->query($sql)->fetchAll();

    $updSql = 'UPDATE proxies SET country=?'
        . ($hasRegion ? ', region=?' : '')
        . ($hasCity ? ', city=?' : '')
        . ', updated_at=CURRENT_TIMESTAMP WHERE id=?';
    $upd = $pdo->prepare($updSql);

    $updated = 0;
    $skipped = 0;
    $failed = 0;
    $details = [];

    foreach ($rows as $i => $r) {
        $id = (int)$r['id'];
        $geo = lookupGeo((string)$r['host']);
        if ($geo['country'] === '') {
            $failed++;
            $details[] = [
                'id' => $id,
                'host' => (string)$r['host'],
                'ok' => false,
                'msg' => $geo['error'] !== '' ? $geo['error'] : 'no country',
            ];
            // 轻微间隔，避免触发限流
            usleep(200000);
            continue;
        }

        $params = [$geo['country']];
        if ($hasRegion) {
            $params[] = $geo['region'];
        }
        if ($hasCity) {
            $params[] = $geo['city'];
        }
        $params[] = $id;
        $upd->execute($params);
        $updated++;
        $details[] = [
            'id' => $id,
            'host' => (string)$r['host'],
            'ok' => true,
            'msg' => trim(implode(' · ', array_filter([$geo['country'], $geo['region'], $geo['city']])))
                . ($geo['query'] !== '' ? ' ← ' . $geo['query'] : ''),
        ];

        // ip-api 免费额度约 45/min
        usleep(1500000);
        if (($i + 1) % 20 === 0) {
            // 额外喘息
            usleep(500000);
        }
    }

    $list = '';
    foreach ($details as $d) {
        $cls = $d['ok'] ? 'ok' : 'err';
        $list .= '<tr><td>#' . (int)$d['id'] . '</td><td><code>' . h($d['host']) . '</code></td>'
            . '<td class="' . $cls . '">' . h($d['msg']) . '</td></tr>';
    }
    if ($list === '') {
        $list = '<tr><td colspan="3" class="muted">没有处理任何行</td></tr>';
    }

    $remain = (int)$pdo->query($countSql)->fetchColumn();
    page('检测完成', '
      <h1 class="ok">代理国家检测完成</h1>
      <p>成功 <b class="ok">' . $updated . '</b> · 失败 <b class="err">' . $failed . '</b> · 跳过 <b>' . $skipped . '</b>'
        . ' · 本批最多 ' . $limit . ' 条 · 剩余待处理约 <b>' . $remain . '</b></p>
      <div class="scroll"><table><thead><tr><th>ID</th><th>Host</th><th>结果</th></tr></thead><tbody>'
        . $list . '</tbody></table></div>
      ' . ($remain > 0
            ? '<a class="btn btn-primary" href="?confirm=1&limit=' . $limit . ($force ? '&force=1' : '') . '">继续下一批</a>'
            : '') . '
      <a class="btn btn-ghost" href="?">返回预览</a>
    ');
} catch (Throwable $e) {
    page('执行失败', '<h1 class="err">检测失败</h1><pre class="err">' . h($e->getMessage()) . '</pre><a class="btn btn-ghost" href="?">返回</a>');
}
