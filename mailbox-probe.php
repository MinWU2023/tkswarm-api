<?php
declare(strict_types=1);

/**
 * 邮件读信接口探测（只读，仅命令行）
 *
 * 用法（在 tkswarm-api 根目录）：
 *   php mailbox-probe.php dhrsa899np@edu.smbsports.com
 *   php mailbox-probe.php dhrsa899np@edu.smbsports.com --raw     # 不给 6 位数字打码（验证码会明文输出）
 *
 * 作用：用后台「官网设置」里已配好的邮箱密钥，直接调上游 {apiUrl}/messages，
 *       打印 HTTP 状态、顶层字段、字段结构和返回正文。密钥不会被打印。
 * 用完可直接删除本文件。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$address = trim((string)($argv[1] ?? ''));
$raw = in_array('--raw', $argv, true);
if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "用法: php mailbox-probe.php <邮箱地址> [--raw]\n");
    exit(1);
}

define('APP_PATH', __DIR__);
require APP_PATH . '/autoload.php';
new App\Core\App(APP_PATH);

try {
    $cfg = App\Services\UserSettingsService::getMailboxConfig(false);
} catch (\Throwable $e) {
    echo '读取邮箱配置失败: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
echo 'configured=' . var_export($cfg['configured'] ?? false, true)
    . ' providerRef=' . ($cfg['providerRef'] ?? '')
    . ' keyLen=' . strlen((string)($cfg['apiKey'] ?? '')) . PHP_EOL;
if (empty($cfg['configured'])) {
    echo '未配置邮箱 API 密钥（当前连的库里没有），请在配置了密钥的环境运行' . PHP_EOL;
    exit(1);
}

$base = 'https://ai.dyyseo-js.com/api/mailbox';

function mask(string $s, bool $raw): string
{
    return $raw ? $s : (string)preg_replace('/(?<!\d)\d{6}(?!\d)/', '******', $s);
}

/** 打印 JSON 结构：键名 + 类型/长度，不含具体值 */
function shape($v, int $depth = 0): string
{
    if ($depth > 5) {
        return '...';
    }
    if (is_array($v)) {
        $isList = array_keys($v) === range(0, count($v) - 1);
        if ($isList) {
            return 'list[' . count($v) . ']' . ($v ? '<' . shape($v[0], $depth + 1) . '>' : '');
        }
        $parts = [];
        foreach ($v as $k => $x) {
            $parts[] = $k . ':' . shape($x, $depth + 1);
        }
        return '{' . implode(', ', $parts) . '}';
    }
    if (is_string($v)) {
        return 'str(' . mb_strlen($v) . ')';
    }
    return gettype($v);
}

$variants = [
    'A 与线上一致(address+icloud+limit+refresh)' => ['address' => $address, 'icloud' => '', 'limit' => 20, 'refresh' => true],
    'B 仅 address' => ['address' => $address],
    'C address+provider_ref' => ['address' => $address, 'provider_ref' => (string)($cfg['providerRef'] ?? ''), 'limit' => 20, 'refresh' => true],
];

foreach ($variants as $label => $body) {
    $ch = curl_init($base . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['apiKey'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    echo PHP_EOL . "===== {$label}" . PHP_EOL;
    echo '请求体键: ' . implode(',', array_keys($body)) . PHP_EOL;
    echo "HTTP {$code}" . ($err !== '' ? " curlErr={$err}" : '') . ' 长度=' . strlen((string)$resp) . PHP_EOL;
    $d = json_decode((string)$resp, true);
    if (is_array($d)) {
        echo '顶层字段: ' . implode(',', array_keys($d)) . PHP_EOL;
        echo '结构: ' . mb_substr(shape($d), 0, 1500) . PHP_EOL;
    }
    echo '正文(前 6000 字符' . ($raw ? '' : '，6 位数字已打码') . '):' . PHP_EOL;
    echo mask(mb_substr((string)$resp, 0, 6000), $raw) . PHP_EOL;
}
