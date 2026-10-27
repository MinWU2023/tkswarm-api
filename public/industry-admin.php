<?php
declare(strict_types=1);

/**
 * 行业数据管理（浏览器直接访问）：/industry-admin.php
 *
 * - 读取 industry_data 全部节点，按层级缩进展示，默认全部收起
 * - 点箭头展开 / 收起子级
 * - 双击「中文名 / 英文名 / 排序」行内修改，回车保存、Esc 取消
 * - 删除（连同子级）、加子级、新增一级分类
 *
 * 英文名：表里有 name_en 列就写它；没有就复用 code 列（同一父级下唯一）。
 */

header('Content-Type: text/html; charset=utf-8');

const IND_MAX_LEVEL = 4;

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------ *
 * 数据库连接
 * ------------------------------------------------------------------ */

$configFile = is_file(__DIR__ . '/config/database.php')
    ? __DIR__ . '/config/database.php'
    : dirname(__DIR__) . '/config/database.php';

$pdo = null;
$bootError = '';

try {
    if (!is_file($configFile)) {
        throw new RuntimeException('未找到数据库配置：config/database.php');
    }
    $config = require $configFile;
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        (string)($config['host'] ?? '127.0.0.1'),
        (string)($config['port'] ?? '3306'),
        (string)($config['database'] ?? ''),
        (string)($config['charset'] ?? 'utf8mb4')
    );
    $pdo = new PDO($dsn, (string)($config['username'] ?? ''), (string)($config['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->query('SELECT 1 FROM `industry_data` LIMIT 1');
} catch (Throwable $e) {
    $bootError = $e->getMessage();
    $pdo = null;
}

/* ------------------------------------------------------------------ *
 * 工具函数
 * ------------------------------------------------------------------ */

function ind_columns(PDO $pdo): array
{
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `industry_data`') as $col) {
        $cols[(string)$col['Field']] = $col;
    }
    return $cols;
}

function ind_slug(string $name): string
{
    $s = strtolower(trim($name));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s !== '' ? $s : ('n-' . substr(md5($name), 0, 8));
}

/** 同一父级下保证 code 唯一（创建时用） */
function ind_unique_code(PDO $pdo, int $parentId, string $base): string
{
    $base = mb_substr(trim($base), 0, 120);
    if ($base === '') {
        $base = 'n-' . bin2hex(random_bytes(4));
    }
    $stmt = $pdo->prepare('SELECT `id` FROM `industry_data` WHERE `parent_id`=? AND `code`=? AND `id`<>? LIMIT 1');
    $i = 0;
    while (true) {
        $try = $i === 0 ? $base : $base . '-' . ($i + 1);
        $stmt->execute([$parentId, $try, 0]);
        if (!$stmt->fetchColumn()) {
            return mb_substr($try, 0, 128);
        }
        $i++;
        if ($i > 300) {
            return mb_substr($base . '-' . bin2hex(random_bytes(2)), 0, 128);
        }
    }
}

/** @return list<int> 所有后代 id（不含自身） */
function ind_descendant_ids(PDO $pdo, int $id): array
{
    $out = [];
    $seen = [$id => true]; // 防止脏数据成环导致死循环
    $queue = [$id];
    $stmt = $pdo->prepare('SELECT `id` FROM `industry_data` WHERE `parent_id`=?');
    while ($queue) {
        $cur = (int)array_shift($queue);
        $stmt->execute([$cur]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cid = (int)$cid;
            if (isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $out[] = $cid;
            $queue[] = $cid;
        }
    }
    return $out;
}

function ind_next_sort(PDO $pdo, int $parentId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(`sort_order`), 0) + 10 FROM `industry_data` WHERE `parent_id`=?');
    $stmt->execute([$parentId]);
    return (int)$stmt->fetchColumn();
}

function ind_map(array $r): array
{
    return [
        'id' => (int)$r['id'],
        'parentId' => (int)$r['parent_id'],
        'level' => (int)$r['level'],
        'name' => (string)$r['name'],
        'nameEn' => (string)($r['name_en'] ?? ''),
        'code' => (string)($r['code'] ?? ''),
        'sort' => (int)$r['sort_order'],
        'enabled' => (int)$r['enabled'] === 1,
    ];
}

function ind_row(PDO $pdo, string $enField, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT `id`,`parent_id`,`level`,`name`,`code`,`sort_order`,`enabled`,`{$enField}` AS `name_en`"
        . ' FROM `industry_data` WHERE `id`=? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? ind_map($row) : null;
}

/* ------------------------------------------------------------------ *
 * 动作处理
 * ------------------------------------------------------------------ */

function ind_list(PDO $pdo, string $enField): array
{
    $sql = "SELECT `id`,`parent_id`,`level`,`name`,`code`,`sort_order`,`enabled`,`{$enField}` AS `name_en`"
        . ' FROM `industry_data` ORDER BY `level` ASC, `sort_order` ASC, `id` ASC';
    $nodes = [];
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $nodes[] = ind_map($row);
    }
    $currentMax = 1;
    foreach ($nodes as $n) {
        $currentMax = max($currentMax, $n['level']);
    }
    return [
        'nodes' => $nodes,
        'enField' => $enField,
        'maxLevel' => IND_MAX_LEVEL,
        'currentMaxLevel' => $currentMax,
        'total' => count($nodes),
    ];
}

function ind_update(PDO $pdo, string $enField, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $field = (string)($in['field'] ?? '');
    $value = $in['value'] ?? '';
    if ($id <= 0) {
        throw new RuntimeException('缺少节点 ID');
    }
    $row = ind_row($pdo, $enField, $id);
    if (!$row) {
        throw new RuntimeException('节点不存在或已被删除');
    }

    if ($field === 'name') {
        $name = trim((string)$value);
        if ($name === '') {
            throw new RuntimeException('中文名不能为空');
        }
        $pdo->prepare('UPDATE `industry_data` SET `name`=?, `updated_at`=CURRENT_TIMESTAMP WHERE `id`=?')
            ->execute([mb_substr($name, 0, 128), $id]);
    } elseif ($field === 'en') {
        $en = trim((string)$value);
        if ($en === '') {
            throw new RuntimeException('英文名不能为空');
        }
        if ($enField === 'code') {
            $dup = $pdo->prepare('SELECT `id` FROM `industry_data` WHERE `parent_id`=? AND `code`=? AND `id`<>? LIMIT 1');
            $dup->execute([$row['parentId'], $en, $id]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('同一父级下已有相同英文名（' . $en . '），请换个写法');
            }
            $pdo->prepare('UPDATE `industry_data` SET `code`=?, `updated_at`=CURRENT_TIMESTAMP WHERE `id`=?')
                ->execute([mb_substr($en, 0, 128), $id]);
        } else {
            $pdo->prepare('UPDATE `industry_data` SET `name_en`=?, `updated_at`=CURRENT_TIMESTAMP WHERE `id`=?')
                ->execute([mb_substr($en, 0, 128), $id]);
        }
    } elseif ($field === 'sort') {
        $raw = trim((string)$value);
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            throw new RuntimeException('排序必须是整数');
        }
        $pdo->prepare('UPDATE `industry_data` SET `sort_order`=?, `updated_at`=CURRENT_TIMESTAMP WHERE `id`=?')
            ->execute([(int)$raw, $id]);
    } else {
        throw new RuntimeException('不支持的字段：' . $field);
    }

    return ind_row($pdo, $enField, $id) ?? $row;
}

function ind_create(PDO $pdo, string $enField, array $in): array
{
    $parentId = (int)($in['parentId'] ?? 0);
    $name = trim((string)($in['name'] ?? ''));
    $en = trim((string)($in['en'] ?? ''));
    $sortRaw = trim((string)($in['sort'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('中文名不能为空');
    }

    $level = 1;
    if ($parentId > 0) {
        $parent = ind_row($pdo, $enField, $parentId);
        if (!$parent) {
            throw new RuntimeException('父级不存在或已被删除');
        }
        $level = (int)$parent['level'] + 1;
        if ($level > IND_MAX_LEVEL) {
            throw new RuntimeException('最多支持 ' . IND_MAX_LEVEL . ' 级分类');
        }
    }

    $sort = $sortRaw === '' ? ind_next_sort($pdo, $parentId) : (int)$sortRaw;
    if ($sortRaw !== '' && !preg_match('/^-?\d+$/', $sortRaw)) {
        throw new RuntimeException('排序必须是整数');
    }
    if ($en === '') {
        $en = $enField === 'code' ? ind_slug($name) : '';
    }
    $code = ind_unique_code($pdo, $parentId, $en !== '' ? $en : $name);

    if ($enField === 'name_en') {
        $sql = 'INSERT INTO `industry_data` (`parent_id`,`level`,`name`,`code`,`name_en`,`description`,`sort_order`,`enabled`)'
            . " VALUES (?,?,?,?,?,'',?,1)";
        $params = [$parentId, $level, mb_substr($name, 0, 128), $code, mb_substr($en, 0, 128), $sort];
    } else {
        $sql = 'INSERT INTO `industry_data` (`parent_id`,`level`,`name`,`code`,`description`,`sort_order`,`enabled`)'
            . " VALUES (?,?,?,?,'',?,1)";
        $params = [$parentId, $level, mb_substr($name, 0, 128), $code, $sort];
    }
    $pdo->prepare($sql)->execute($params);

    $newId = (int)$pdo->lastInsertId();
    return ind_row($pdo, $enField, $newId) ?? ['id' => $newId];
}

function ind_delete(PDO $pdo, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('缺少节点 ID');
    }
    $stmt = $pdo->prepare('SELECT `id`,`name` FROM `industry_data` WHERE `id`=? LIMIT 1');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        throw new RuntimeException('节点不存在或已被删除');
    }
    $ids = array_merge([$id], ind_descendant_ids($pdo, $id));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM `industry_data` WHERE `id` IN ({$ph})")->execute($ids);
    return [
        'deleted' => count($ids),
        'childDeleted' => count($ids) - 1,
        'name' => (string)$target['name'],
    ];
}

function ind_handle(PDO $pdo, string $enField, string $action, array $in): array
{
    return match ($action) {
        'list' => ind_list($pdo, $enField),
        'update' => ind_update($pdo, $enField, $in),
        'create' => ind_create($pdo, $enField, $in),
        'delete' => ind_delete($pdo, $in),
        default => throw new RuntimeException('未知操作：' . $action),
    };
}

/* ------------------------------------------------------------------ *
 * JSON 接口（同文件，?action=xxx）
 * ------------------------------------------------------------------ */

$action = trim((string)($_GET['action'] ?? ''));

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!$pdo) {
            throw new RuntimeException($bootError !== '' ? $bootError : '数据库未连接');
        }
        $input = [];
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }
        $cols = ind_columns($pdo);
        $enField = isset($cols['name_en']) ? 'name_en' : 'code';
        echo json_encode(
            ['ok' => true, 'data' => ind_handle($pdo, $enField, $action, $input)],
            JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* 英文名列（页面初始化用） */
$enFieldForPage = 'code';
if ($pdo) {
    try {
        $cols = ind_columns($pdo);
        $enFieldForPage = isset($cols['name_en']) ? 'name_en' : 'code';
    } catch (Throwable $e) {
        $bootError = $bootError !== '' ? $bootError : $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>行业数据管理 · TkSwarm</title>
<style>
:root{
  --bg:#f4f6fb;
  --card:#ffffff;
  --line:#e9eef6;
  --line-strong:#dbe3ef;
  --text:#0f172a;
  --muted:#64748b;
  --faint:#94a3b8;
  --brand:#2563eb;
  --brand-soft:#eef4ff;
  --danger:#e11d48;
  --danger-soft:#fff1f4;
  --ok:#0f9d58;
  --l1:#4f46e5;
  --l2:#0ea5e9;
  --l3:#f59e0b;
  --l4:#94a3b8;
  --radius:16px;
  --shadow:0 1px 2px rgba(15,23,42,.04), 0 18px 40px -24px rgba(15,23,42,.35);
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:
    radial-gradient(1100px 520px at 100% -8%, #e8f0ff 0%, rgba(232,240,255,0) 62%),
    radial-gradient(800px 400px at -10% 0%, #f0f7ff 0%, rgba(240,247,255,0) 60%),
    var(--bg);
  color:var(--text);
  font:14px/1.55 -apple-system,"Segoe UI","Microsoft YaHei",system-ui,"Helvetica Neue",Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
}
button{font:inherit;color:inherit}

/* ---------------- 顶栏 ---------------- */
.topbar{
  position:sticky;top:0;z-index:30;
  display:flex;align-items:center;gap:16px;
  padding:16px 28px;
  background:rgba(255,255,255,.86);
  backdrop-filter:saturate(180%) blur(12px);
  border-bottom:1px solid var(--line);
}
.brand{display:flex;align-items:center;gap:12px;min-width:0}
.brand .logo{
  width:38px;height:38px;border-radius:11px;flex:0 0 auto;
  display:grid;place-items:center;color:#fff;font-weight:700;font-size:15px;
  background:linear-gradient(135deg,#3b82f6,#4f46e5);
  box-shadow:0 8px 18px -8px rgba(59,130,246,.9);
}
.brand h1{margin:0;font-size:17px;font-weight:700;letter-spacing:.2px}
.brand p{margin:1px 0 0;font-size:12.5px;color:var(--muted)}
.topbar .spacer{flex:1}
.stat{
  display:flex;flex-direction:column;align-items:flex-end;gap:1px;
  padding:0 14px;border-right:1px solid var(--line);
}
.stat:last-of-type{border-right:0}
.stat b{font-size:15px;font-weight:700;font-variant-numeric:tabular-nums}
.stat span{font-size:11.5px;color:var(--faint)}

/* ---------------- 主体 ---------------- */
.wrap{max-width:1320px;margin:0 auto;padding:22px 28px 56px}
.panel{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  overflow:hidden;
}

/* 工具栏 */
.toolbar{
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  padding:14px 18px;
  border-bottom:1px solid var(--line);
  background:linear-gradient(180deg,#fbfcff,#fff);
}
.search{position:relative;flex:1;min-width:220px;max-width:380px}
.search input{
  width:100%;height:36px;padding:0 12px 0 34px;
  border:1px solid var(--line-strong);border-radius:10px;
  background:#fff;color:var(--text);outline:none;
  transition:border-color .16s,box-shadow .16s;
}
.search input:focus{border-color:#9dc0ff;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.search .icon{
  position:absolute;left:11px;top:50%;transform:translateY(-50%);
  color:var(--faint);line-height:0;pointer-events:none;
}
.toolbar .grow{flex:1}

/* 按钮 */
.btn{
  display:inline-flex;align-items:center;gap:6px;
  height:36px;padding:0 14px;
  border:1px solid var(--line-strong);border-radius:10px;
  background:#fff;color:#334155;cursor:pointer;
  font-size:13.5px;font-weight:500;
  transition:all .16s ease;
  white-space:nowrap;
}
.btn:hover{border-color:#c7d5ea;background:#f8fbff;color:#0f172a}
.btn:active{transform:translateY(1px)}
.btn.primary{
  border-color:transparent;color:#fff;
  background:linear-gradient(135deg,#3b82f6,#2563eb);
  box-shadow:0 10px 20px -12px rgba(37,99,235,.9);
}
.btn.primary:hover{filter:brightness(1.04)}
.btn.ghost{background:#fff}
.btn.icon{padding:0 10px}
.btn svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}

/* 表头 */
.thead,.row{
  display:grid;
  grid-template-columns:minmax(280px,1fr) 210px 84px 84px 152px;
  align-items:center;
  column-gap:14px;
}
.thead{
  padding:10px 18px;
  font-size:12px;font-weight:600;letter-spacing:.4px;
  color:var(--faint);text-transform:uppercase;
  background:#fafbfe;border-bottom:1px solid var(--line);
}
.thead .c-sort,.thead .c-kids{text-align:center}
.thead .c-ops{text-align:right}

/* 行 */
.tree{position:relative}
.row{
  position:relative;
  min-height:46px;padding:6px 18px;
  border-bottom:1px solid #f2f5fa;
  background:#fff;
  transition:background .14s ease;
}
.row:last-child{border-bottom:0}
.row:hover{background:#f8fbff}
.row::before{
  content:"";position:absolute;left:0;top:0;bottom:0;width:3px;
  background:transparent;transition:background .16s;
}
.row[data-lvl="1"]::before{background:var(--l1)}
.row[data-lvl="2"]::before{background:var(--l2)}
.row[data-lvl="3"]::before{background:var(--l3)}
.row[data-lvl="4"]::before{background:var(--l4)}
.row[data-lvl="1"]{background:#fcfdff}
.row[data-lvl="1"]:hover{background:#f7faff}

/* 名称列（缩进 + 折叠箭头） */
.c-name{
  display:flex;align-items:center;gap:8px;min-width:0;
  padding-left:calc((var(--lvl) - 1) * 22px);
}
.caret{
  flex:0 0 auto;width:20px;height:20px;padding:0;
  display:grid;place-items:center;
  border:0;border-radius:6px;background:transparent;color:var(--faint);
  cursor:pointer;transition:all .15s;
}
.caret:hover{background:#eef4ff;color:var(--brand)}
.caret svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round;transition:transform .16s}
.caret.open svg{transform:rotate(90deg)}
.caret.leaf{visibility:hidden;pointer-events:none}
.name-text{
  font-weight:600;color:#101a33;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  cursor:text;padding:2px 4px;border-radius:6px;
  border:1px solid transparent;
}
.row[data-lvl="1"] .name-text{font-size:14.5px}
.name-text:hover{background:#f1f6ff;border-color:#dbe8ff}
.name-text .zero{color:var(--faint);font-weight:400}
.lvl{
  flex:0 0 auto;font-size:10.5px;font-weight:700;letter-spacing:.3px;
  padding:1px 6px;border-radius:999px;color:#fff;opacity:.92;
}
.lvl.l1{background:var(--l1)}
.lvl.l2{background:var(--l2)}
.lvl.l3{background:var(--l3)}
.lvl.l4{background:var(--l4)}
.tag-off{
  flex:0 0 auto;font-size:10.5px;padding:1px 6px;border-radius:999px;
  background:#f1f5f9;color:#94a3b8;
}

/* 英文名列 */
.c-en{
  min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  font-size:12.5px;color:#5b6b85;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  padding:2px 6px;border-radius:6px;border:1px solid transparent;
  cursor:text;justify-self:start;max-width:100%;
}
.c-en:hover{background:#f6f8fc;border-color:#e3ebf7;color:#0f172a}

/* 子级数 / 排序 */
.c-kids,.c-sort{text-align:center;font-variant-numeric:tabular-nums;color:#475569}
.c-kids .pill{
  display:inline-block;min-width:26px;padding:1px 8px;border-radius:999px;
  background:#f1f5fb;color:#64748b;font-size:12px;font-weight:600;
}
.c-kids .pill.has{background:#eaf1ff;color:#2563eb}
.c-sort span{
  display:inline-block;min-width:44px;padding:2px 6px;border-radius:6px;
  border:1px solid transparent;cursor:text;
}
.c-sort span:hover{background:#f6f8fc;border-color:#e3ebf7}

/* 操作 */
.c-ops{display:flex;justify-content:flex-end;gap:6px}
.act{
  height:28px;padding:0 10px;border-radius:8px;
  border:1px solid var(--line-strong);background:#fff;color:#475569;
  font-size:12.5px;cursor:pointer;transition:all .15s;white-space:nowrap;
}
.act:hover{border-color:#c9d8ee;background:#f7faff;color:#0f172a}
.act.add:hover{border-color:#bcd3ff;background:var(--brand-soft);color:var(--brand)}
.act.del:hover{border-color:#ffd3dc;background:var(--danger-soft);color:var(--danger)}

/* 行内编辑 */
.c-name.is-editing .name-text,
.c-name.is-editing .lvl,
.c-name.is-editing .tag-off,
.c-en.is-editing .en-text,
.c-sort.is-editing span{display:none}
.edit-input{
  width:100%;height:30px;padding:0 8px;
  border:1px solid #7fb0ff;border-radius:8px;outline:none;
  background:#fff;color:var(--text);
  box-shadow:0 0 0 3px rgba(37,99,235,.14);
  font:inherit;
}
.c-sort .edit-input{text-align:center;padding:0 4px}
.c-en .edit-input{font-family:inherit;font-size:13px}

/* 空态 / 加载 */
.empty{padding:56px 20px;text-align:center;color:var(--muted)}
.empty .big{font-size:15px;font-weight:600;color:#334155;margin-bottom:6px}
.empty .hint{color:var(--faint);font-size:13px}
.loading{padding:44px;text-align:center;color:var(--faint)}
.spinner{
  width:22px;height:22px;margin:0 auto 10px;
  border:2.5px solid #e2e9f4;border-top-color:var(--brand);
  border-radius:50%;animation:spin .8s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* 提示条 */
.note{
  display:flex;align-items:center;gap:8px;
  padding:9px 18px;font-size:12.5px;color:#7c6a3f;
  background:linear-gradient(180deg,#fffdf5,#fffaf0);
  border-bottom:1px solid #f3ecd9;
}
.note b{color:#8a6d2f}
.note .dot{width:6px;height:6px;border-radius:50%;background:#e2b93b;flex:0 0 auto}

/* Toast */
.toast{
  position:fixed;right:22px;bottom:22px;z-index:90;
  display:flex;flex-direction:column;gap:8px;align-items:flex-end;
  pointer-events:none;
}
.toast .item{
  pointer-events:auto;
  min-width:190px;max-width:420px;
  padding:10px 14px;border-radius:11px;
  background:#0f172a;color:#fff;font-size:13px;line-height:1.45;
  box-shadow:0 18px 38px -18px rgba(15,23,42,.7);
  animation:toastIn .18s ease;
}
.toast .item.ok{background:#0f9d58}
.toast .item.err{background:#e11d48}
@keyframes toastIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

/* 弹窗 */
.mask{
  position:fixed;inset:0;z-index:80;
  display:grid;place-items:center;
  background:rgba(15,23,42,.42);
  backdrop-filter:blur(2px);
  animation:fadeIn .14s ease;
}
.mask[hidden]{display:none}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{
  width:min(460px,calc(100vw - 32px));
  background:#fff;border-radius:16px;padding:22px 22px 18px;
  box-shadow:0 30px 70px -30px rgba(15,23,42,.6);
  animation:modalIn .18s cubic-bezier(.2,.9,.3,1.2);
}
@keyframes modalIn{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.modal h3{margin:0 0 4px;font-size:16px}
.modal .sub{font-size:12.5px;color:var(--muted);margin-bottom:16px}
.field{margin-bottom:12px}
.field label{display:block;font-size:12.5px;font-weight:600;color:#475569;margin-bottom:5px}
.field input{
  width:100%;height:38px;padding:0 12px;
  border:1px solid var(--line-strong);border-radius:10px;outline:none;
  background:#fff;font:inherit;transition:border-color .16s,box-shadow .16s;
}
.field input:focus{border-color:#8fb9ff;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}

@media (max-width:960px){
  .thead,.row{grid-template-columns:minmax(180px,1fr) 150px 64px 64px 128px;column-gap:10px}
  .wrap{padding:16px 12px 40px}
  .topbar{padding:12px 16px}
  .stat{display:none}
}
@media (max-width:720px){
  .thead{display:none}
  .row{grid-template-columns:1fr;row-gap:6px;padding:12px 16px}
  .c-en,.c-kids,.c-sort,.c-ops{justify-self:start;text-align:left}
  .c-ops{justify-self:end}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="brand">
    <div class="logo">行</div>
    <div>
      <h1>行业数据管理</h1>
      <p>industry_data · 四级分类树 · 双击即可修改</p>
    </div>
  </div>
  <div class="spacer"></div>
  <div class="stat"><b id="stat-total">-</b><span>节点总数</span></div>
  <div class="stat"><b id="stat-root">-</b><span>一级分类</span></div>
  <div class="stat"><b id="stat-level">-</b><span>当前层深</span></div>
</header>

<main class="wrap">
  <section class="panel">
    <div class="toolbar">
      <div class="search">
        <span class="icon">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.2-3.2"></path></svg>
        </span>
        <input id="q" type="search" placeholder="搜索中文名 / 英文名…" autocomplete="off">
      </div>
      <div class="grow"></div>
      <button class="btn ghost" type="button" id="btn-expand">全部展开</button>
      <button class="btn ghost" type="button" id="btn-collapse">全部收起</button>
      <button class="btn ghost icon" type="button" id="btn-refresh" title="刷新">
        <svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg>
      </button>
      <button class="btn primary" type="button" id="btn-add-root">
        <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
        新增一级分类
      </button>
    </div>

    <?php if ($enFieldForPage === 'code'): ?>
    <div class="note">
      <span class="dot"></span>
      <span>英文名写入 <b>code</b> 字段（同一父级下唯一，重复会提示）。表里若新增 <b>name_en</b> 列，本页会自动改用它。</span>
    </div>
    <?php endif; ?>

    <div class="thead">
      <div class="c-name">分类（中文名）</div>
      <div class="c-en">英文名</div>
      <div class="c-kids">子级</div>
      <div class="c-sort">排序</div>
      <div class="c-ops">操作</div>
    </div>

    <div id="tree" class="tree">
      <div class="loading"><div class="spinner"></div>正在加载行业数据…</div>
    </div>
  </section>
</main>

<div id="modal" class="mask" hidden>
  <div class="modal" role="dialog" aria-modal="true">
    <h3 id="modal-title">新增子级</h3>
    <div class="sub" id="modal-sub"></div>
    <div class="field">
      <label for="f-name">中文名 <span style="color:#e11d48">*</span></label>
      <input id="f-name" type="text" maxlength="128" placeholder="例如：离心泵">
    </div>
    <div class="field">
      <label for="f-en">英文名</label>
      <input id="f-en" type="text" maxlength="128" placeholder="例如：centrifugal-pump（留空自动生成）">
    </div>
    <div class="field">
      <label for="f-sort">排序</label>
      <input id="f-sort" type="number" step="1" placeholder="留空自动排到最后">
    </div>
    <div class="modal-actions">
      <button class="btn ghost" type="button" id="modal-cancel">取消</button>
      <button class="btn primary" type="button" id="modal-ok">确定</button>
    </div>
  </div>
</div>

<div id="toast" class="toast"></div>

<script>
(function () {
  'use strict';

  var MAX_LEVEL = <?= IND_MAX_LEVEL ?>;
  var EN_FIELD = <?= json_encode($enFieldForPage) ?>;

  var state = {
    nodes: [],
    byId: new Map(),
    children: new Map(),
    expanded: new Set(),
    filter: '',
    maxLevel: MAX_LEVEL,
    loaded: false
  };

  var el = {
    tree: document.getElementById('tree'),
    q: document.getElementById('q'),
    total: document.getElementById('stat-total'),
    root: document.getElementById('stat-root'),
    level: document.getElementById('stat-level'),
    modal: document.getElementById('modal'),
    modalTitle: document.getElementById('modal-title'),
    modalSub: document.getElementById('modal-sub'),
    fName: document.getElementById('f-name'),
    fEn: document.getElementById('f-en'),
    fSort: document.getElementById('f-sort')
  };

  /* ---------------- 小工具 ---------------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function toast(message, kind) {
    var box = document.getElementById('toast');
    var item = document.createElement('div');
    item.className = 'item' + (kind ? ' ' + kind : '');
    item.textContent = message;
    box.appendChild(item);
    setTimeout(function () {
      item.style.transition = 'opacity .2s, transform .2s';
      item.style.opacity = '0';
      item.style.transform = 'translateY(6px)';
      setTimeout(function () { item.remove(); }, 220);
    }, kind === 'err' ? 4200 : 2400);
  }

  function api(action, payload) {
    return fetch('industry-admin.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload || {})
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (json) {
        if (!json || json.ok !== true) {
          throw new Error((json && json.error) || ('请求失败 HTTP ' + res.status));
        }
        return json.data;
      });
    });
  }

  /* ---------------- 建树 ---------------- */
  function indexTree() {
    state.byId.clear();
    state.children.clear();
    state.nodes.forEach(function (n) { state.byId.set(n.id, n); });
    state.nodes.forEach(function (n) {
      var pid = state.byId.has(n.parentId) ? n.parentId : 0;
      if (!state.children.has(pid)) state.children.set(pid, []);
      state.children.get(pid).push(n);
    });
    state.children.forEach(function (list) {
      list.sort(function (a, b) {
        return (a.sort - b.sort) || (a.id - b.id);
      });
    });

    // 找出父子关系异常（成环）的节点，挂到最外层显示，避免数据“消失”
    var reach = new Set();
    (function walk(list, depth) {
      if (depth > 16) return;
      list.forEach(function (n) {
        if (reach.has(n.id)) return;
        reach.add(n.id);
        walk(kidsOf(n.id), depth + 1);
      });
    })(kidsOf(0), 0);
    state.orphans = state.nodes.filter(function (n) { return !reach.has(n.id); });
  }

  function kidsOf(id) { return state.children.get(id) || []; }
  function roots() {
    var list = kidsOf(0).slice();
    if (state.orphans && state.orphans.length) {
      var extra = state.orphans.filter(function (n) { return list.indexOf(n) < 0; });
      list = list.concat(extra);
    }
    return list;
  }

  function descendantCount(id, seen) {
    seen = seen || new Set([id]);
    var total = 0;
    kidsOf(id).forEach(function (k) {
      if (seen.has(k.id)) return;
      seen.add(k.id);
      total += 1 + descendantCount(k.id, seen);
    });
    return total;
  }

  /* ---------------- 渲染 ---------------- */
  function rowHtml(n) {
    var kids = kidsOf(n.id);
    var hasKids = kids.length > 0;
    var open = state.expanded.has(n.id);
    var lv = Math.min(4, Math.max(1, n.level || 1));
    var canAdd = (n.level || 1) < MAX_LEVEL;

    return ''
      + '<div class="row" data-id="' + n.id + '" data-lvl="' + lv + '" style="--lvl:' + lv + '">'
      + '  <div class="c-name">'
      + '    <button type="button" class="caret' + (hasKids ? (open ? ' open' : '') : ' leaf') + '" data-act="toggle" aria-label="展开/收起">'
      + '      <svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>'
      + '    </button>'
      + '    <span class="lvl l' + lv + '">L' + lv + '</span>'
      + '    <span class="name-text' + (n.name ? '' : ' zero') + '" data-edit="name" title="双击修改中文名">' + esc(n.name || '(未命名)') + '</span>'
      + (n.enabled ? '' : '<span class="tag-off">已停用</span>')
      + '  </div>'
      + '  <div class="c-en" data-edit="en" title="双击修改英文名"><span class="en-text">' + esc(n.nameEn || n.code || '') + '</span></div>'
      + '  <div class="c-kids">' + (hasKids ? '<span class="pill has">' + kids.length + '</span>' : '<span class="pill">0</span>') + '</div>'
      + '  <div class="c-sort" data-edit="sort" title="双击修改排序"><span>' + (n.sort || 0) + '</span></div>'
      + '  <div class="c-ops">'
      + (canAdd ? '<button type="button" class="act add" data-act="add">+ 子级</button>' : '')
      + '    <button type="button" class="act del" data-act="del">删除</button>'
      + '  </div>'
      + '</div>';
  }

  function collectVisible() {
    var out = [];
    var q = state.filter.trim().toLowerCase();

    if (q) {
      // 搜索：命中节点 + 其所有祖先
      var keep = new Set();
      state.nodes.forEach(function (n) {
        var hit = (n.name || '').toLowerCase().indexOf(q) >= 0
          || (n.nameEn || '').toLowerCase().indexOf(q) >= 0
          || (n.code || '').toLowerCase().indexOf(q) >= 0;
        if (!hit) return;
        var cur = n;
        var guard = 0;
        while (cur && guard < 12) {
          keep.add(cur.id);
          cur = state.byId.get(cur.parentId) || null;
          guard++;
        }
      });
      (function walk(list, seen) {
        list.forEach(function (n) {
          if (!keep.has(n.id) || seen.has(n.id)) return;
          seen.add(n.id);
          out.push(n);
          walk(kidsOf(n.id), seen);
        });
      })(roots(), new Set());
      return out;
    }

    (function walk(list, seen) {
      list.forEach(function (n) {
        if (seen.has(n.id)) return;
        seen.add(n.id);
        out.push(n);
        if (state.expanded.has(n.id)) walk(kidsOf(n.id), seen);
      });
    })(roots(), new Set());
    return out;
  }

  function render() {
    if (!state.loaded) return;

    el.total.textContent = state.nodes.length;
    el.root.textContent = roots().length;

    var maxLv = 1;
    state.nodes.forEach(function (n) { maxLv = Math.max(maxLv, n.level || 1); });
    el.level.textContent = maxLv;

    var visible = collectVisible();

    if (!state.nodes.length) {
      el.tree.innerHTML = '<div class="empty">'
        + '<div class="big">还没有行业数据</div>'
        + '<div class="hint">点右上角「新增一级分类」开始维护。</div>'
        + '</div>';
      return;
    }
    if (!visible.length) {
      el.tree.innerHTML = '<div class="empty">'
        + '<div class="big">没有匹配「' + esc(state.filter) + '」的分类</div>'
        + '<div class="hint">换个关键词试试，或清空搜索框。</div>'
        + '</div>';
      return;
    }

    el.tree.innerHTML = visible.map(rowHtml).join('');
  }

  /* ---------------- 数据加载 ---------------- */
  function load() {
    return api('list').then(function (data) {
      state.nodes = Array.isArray(data.nodes) ? data.nodes : [];
      state.maxLevel = data.maxLevel || MAX_LEVEL;
      indexTree();
      // 首次进入：全部收起（state.expanded 保持空）
      state.loaded = true;
      render();
    }).catch(function (err) {
      el.tree.innerHTML = '<div class="empty"><div class="big">加载失败</div><div class="hint">'
        + esc(err.message) + '</div></div>';
      toast(err.message, 'err');
    });
  }

  function reload(keepOpenId) {
    return api('list').then(function (data) {
      state.nodes = Array.isArray(data.nodes) ? data.nodes : [];
      indexTree();
      if (keepOpenId) {
        var still = state.byId.has(keepOpenId);
        if (still) state.expanded.add(keepOpenId);
      }
      // 清掉已经不存在的展开项
      Array.from(state.expanded).forEach(function (id) {
        if (!state.byId.has(id)) state.expanded.delete(id);
      });
      render();
    }).catch(function (err) { toast(err.message, 'err'); });
  }

  /* ---------------- 行内编辑 ---------------- */
  function beginEdit(row, id, field) {
    var cell = field === 'name' ? row.querySelector('.c-name')
      : field === 'en' ? row.querySelector('.c-en')
        : row.querySelector('.c-sort');
    if (!cell || cell.classList.contains('is-editing')) return;

    var node = state.byId.get(id);
    if (!node) return;
    var current = field === 'name' ? node.name : field === 'en' ? (node.nameEn || node.code || '') : String(node.sort || 0);

    cell.classList.add('is-editing');
    var input = document.createElement('input');
    input.className = 'edit-input';
    input.type = field === 'sort' ? 'number' : 'text';
    if (field === 'sort') input.step = '1';
    input.value = current;
    cell.appendChild(input);
    input.focus();
    input.select();

    var done = false;
    function finish(save) {
      if (done) return;
      done = true;
      var next = input.value;
      input.remove();
      cell.classList.remove('is-editing');
      if (!save || String(next).trim() === String(current).trim()) return;
      api('update', { id: id, field: field, value: next })
        .then(function () {
          toast('已保存', 'ok');
          return reload();
        })
        .catch(function (err) {
          toast(err.message, 'err');
        });
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    input.addEventListener('blur', function () { finish(true); });
  }

  /* ---------------- 事件 ---------------- */
  el.tree.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var row = btn.closest('.row');
    if (!row) return;
    var id = Number(row.dataset.id);
    var act = btn.dataset.act;

    if (act === 'toggle') {
      if (state.expanded.has(id)) state.expanded.delete(id);
      else state.expanded.add(id);
      render();
      return;
    }
    if (act === 'add') {
      openCreate(id);
      return;
    }
    if (act === 'del') {
      doDelete(id);
    }
  });

  el.tree.addEventListener('dblclick', function (e) {
    if (e.target.closest('button')) return; // 箭头 / 操作按钮不触发编辑
    var cell = e.target.closest('[data-edit]');
    if (!cell) return;
    var row = cell.closest('.row');
    if (!row) return;
    beginEdit(row, Number(row.dataset.id), cell.dataset.edit);
  });

  function doDelete(id) {
    var node = state.byId.get(id);
    if (!node) return;
    var kids = descendantCount(id);
    var msg = kids > 0
      ? '确定删除「' + node.name + '」及其 ' + kids + ' 个子级吗？\n\n此操作不可恢复。'
      : '确定删除「' + node.name + '」吗？';
    if (!window.confirm(msg)) return;
    api('delete', { id: id }).then(function (res) {
      state.expanded.delete(id);
      toast('已删除「' + res.name + '」' + (res.childDeleted ? '（含 ' + res.childDeleted + ' 个子级）' : ''), 'ok');
      return reload();
    }).catch(function (err) { toast(err.message, 'err'); });
  }

  /* ---------------- 新增 ---------------- */
  var createParentId = 0;

  function openCreate(parentId) {
    createParentId = parentId || 0;
    var parent = createParentId ? state.byId.get(createParentId) : null;
    if (createParentId && !parent) { toast('父级不存在', 'err'); return; }

    if (parent) {
      var nextLevel = (parent.level || 1) + 1;
      if (nextLevel > MAX_LEVEL) { toast('最多支持 ' + MAX_LEVEL + ' 级分类', 'err'); return; }
      el.modalTitle.textContent = '新增子级（L' + nextLevel + '）';
      el.modalSub.textContent = '父级：' + parent.name + '（L' + (parent.level || 1) + '）';
    } else {
      el.modalTitle.textContent = '新增一级分类（L1）';
      el.modalSub.textContent = '将创建在最外层，排序留空则排在最后。';
    }

    el.fName.value = '';
    el.fEn.value = '';
    el.fSort.value = '';
    el.modal.hidden = false;
    setTimeout(function () { el.fName.focus(); }, 30);
  }

  function closeCreate() {
    el.modal.hidden = true;
    createParentId = 0;
  }

  function submitCreate() {
    var name = el.fName.value.trim();
    if (!name) { el.fName.focus(); toast('中文名不能为空', 'err'); return; }
    var payload = {
      parentId: createParentId,
      name: name,
      en: el.fEn.value.trim(),
      sort: el.fSort.value.trim()
    };
    api('create', payload).then(function (row) {
      closeCreate();
      toast('已添加「' + name + '」', 'ok');
      if (createParentId) state.expanded.add(createParentId);
      return reload(createParentId || null);
    }).catch(function (err) { toast(err.message, 'err'); });
  }

  document.getElementById('modal-ok').addEventListener('click', submitCreate);
  document.getElementById('modal-cancel').addEventListener('click', closeCreate);
  el.modal.addEventListener('click', function (e) {
    if (e.target === el.modal) closeCreate();
  });
  el.modal.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); submitCreate(); }
    else if (e.key === 'Escape') { e.preventDefault(); closeCreate(); }
  });

  /* ---------------- 工具栏 ---------------- */
  document.getElementById('btn-add-root').addEventListener('click', function () { openCreate(0); });
  document.getElementById('btn-refresh').addEventListener('click', function () {
    reload().then(function () { toast('已刷新', 'ok'); });
  });
  document.getElementById('btn-expand').addEventListener('click', function () {
    state.nodes.forEach(function (n) {
      if (kidsOf(n.id).length) state.expanded.add(n.id);
    });
    render();
  });
  document.getElementById('btn-collapse').addEventListener('click', function () {
    state.expanded.clear();
    render();
  });

  var searchTimer = null;
  el.q.addEventListener('input', function () {
    clearTimeout(searchTimer);
    var v = el.q.value;
    searchTimer = setTimeout(function () {
      state.filter = v;
      render();
    }, 120);
  });

  /* ---------------- 启动 ---------------- */
  load();
})();
</script>
</body>
</html>
