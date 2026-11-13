<?php

namespace App\Services;

use App\Core\Database\DB;

/**
 * 注册邮箱库：邮箱来源于 tkswarm-api/emails.txt（每行一个），导入 register_mailboxes 表后逐个取用。
 * 状态：unused 未使用 / used 已使用 / blocked 不可用（收件箱收到过 TikTok 邮件，取邮箱时忽略）；used_at 记录取用时间。
 * scripts/check-mailboxes.php 逐个读收件箱，把收到过 TikTok 邮件的邮箱标记为 blocked。
 */
class RegisterMailboxService
{
    public const STATUS_UNUSED = 'unused';
    public const STATUS_USED = 'used';

    private static bool $schemaEnsured = false;

    public static function emailsFile(): string
    {
        $custom = getenv('MAILBOX_EMAILS_FILE');
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'emails.txt';
    }

    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        self::$schemaEnsured = true;
        DB::query("CREATE TABLE IF NOT EXISTS `register_mailboxes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(191) NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'unused' COMMENT '使用状态：unused未使用/used已使用',
            `used_at` DATETIME NULL DEFAULT NULL COMMENT '取用时间',
            `claim_token` VARCHAR(32) NOT NULL DEFAULT '',
            `checked_at` DATETIME NULL DEFAULT NULL COMMENT '收件箱检查时间',
            `block_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '不可用原因',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_register_mailboxes_email` (`email`),
            KEY `idx_register_mailboxes_status_id` (`status`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // 老表补列（表已经存在时 CREATE IF NOT EXISTS 不会加新列）
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM `register_mailboxes`') ?: [], 'Field');
            if (!in_array('checked_at', $cols, true)) {
                DB::query("ALTER TABLE `register_mailboxes` ADD COLUMN `checked_at` DATETIME NULL DEFAULT NULL COMMENT '收件箱检查时间'");
            }
            if (!in_array('block_reason', $cols, true)) {
                DB::query("ALTER TABLE `register_mailboxes` ADD COLUMN `block_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '不可用原因'");
            }
        } catch (\Throwable $e) {
            // ignore schema alter race
        }
    }

    /**
     * 把 emails.txt 里的新邮箱导入数据表（已存在的忽略，不会改动其使用状态）。
     * @return int 本次新增条数
     */
    public static function syncFromFile(): int
    {
        self::ensureSchema();
        $file = self::emailsFile();
        if (!is_file($file) || !is_readable($file)) {
            return 0;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $emails = [];
        foreach ($lines as $i => $line) {
            $line = trim((string)$line);
            if ($i === 0) {
                $line = ltrim($line, "\xEF\xBB\xBF");
            }
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $line = mb_strtolower($line);
            if (!filter_var($line, FILTER_VALIDATE_EMAIL) || strlen($line) > 191) {
                continue;
            }
            $emails[$line] = true;
        }
        $emails = array_keys($emails);
        $added = 0;
        foreach (array_chunk($emails, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?)'));
            $added += DB::execute('INSERT IGNORE INTO `register_mailboxes` (`email`) VALUES ' . $placeholders, $chunk);
        }
        return $added;
    }

    /**
     * 按 id 顺序取出下一个未使用的邮箱并标记为已使用（单条 UPDATE 原子完成，并发下不会重复）。
     * 库里没有可用邮箱时会先从 emails.txt 补充一次。
     *
     * @return array{id:int,email:string,status:string,used_at:string}|null 邮箱用尽返回 null
     */
    public static function claimNext(): ?array
    {
        self::ensureSchema();
        $row = self::tryClaim();
        if ($row !== null) {
            return $row;
        }
        if (self::syncFromFile() > 0) {
            return self::tryClaim();
        }
        return null;
    }

    public const STATUS_BLOCKED = 'blocked';
    private const MAILBOX_BASE_URL = 'https://ai.dyyseo-js.com/api/mailbox';

    /**
     * 调上游 /messages 读某个邮箱的收件箱。
     * @return array{ok:bool,status:int,data:array,error:string,retryAfter:int}
     */
    public static function fetchMessages(string $address, int $limit = 50): array
    {
        $fail = static fn(string $e, int $s = 0, int $r = 0): array => ['ok' => false, 'status' => $s, 'data' => [], 'error' => $e, 'retryAfter' => $r];
        $cfg = UserSettingsService::getMailboxConfig(false);
        if (!$cfg['configured']) {
            return $fail('未配置邮箱 API 密钥，请到管理后台「官网设置」中填写');
        }
        if (!function_exists('curl_init')) {
            return $fail('服务器未启用 curl 扩展');
        }
        $ch = curl_init(self::MAILBOX_BASE_URL . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['apiKey'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['address' => $address, 'icloud' => '', 'limit' => $limit, 'refresh' => true], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return $fail('邮箱服务无响应' . ($curlErr !== '' ? '：' . $curlErr : ''));
        }
        $data = json_decode((string)$raw, true);
        if ($code < 200 || $code >= 300) {
            $retry = is_array($data) ? (int)($data['retry_after_seconds'] ?? 0) : 0;
            return $fail('邮箱服务返回 ' . $code . '：' . mb_substr(trim(strip_tags((string)$raw)), 0, 200), $code, $retry);
        }
        if (!is_array($data)) {
            return $fail('邮箱服务返回了非 JSON 内容', $code);
        }
        return ['ok' => true, 'status' => $code, 'data' => $data, 'error' => '', 'retryAfter' => 0];
    }

    /** @param array<mixed> $a */
    private static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    /** 在响应里（含嵌套）找第一个邮件数组（可能为空数组），找不到返回 null */
    private static function findMailList(array $data): ?array
    {
        $queue = [$data];
        $guard = 0;
        while ($queue && $guard++ < 200) {
            $cur = array_shift($queue);
            if (self::isList($cur) && $cur !== []) {
                return $cur;
            }
            foreach (['messages', 'items', 'mails', 'emails', 'results', 'list', 'data'] as $k) {
                if (isset($cur[$k]) && is_array($cur[$k]) && self::isList($cur[$k])) {
                    return $cur[$k];
                }
            }
            foreach ($cur as $v) {
                if (is_array($v)) {
                    $queue[] = $v;
                }
            }
        }
        return null;
    }

    /**
     * 收件箱里是否出现过 TikTok 的邮件（发件人 / 主题 / 正文里含 tiktok）。
     * 和注册流程里取验证码用的判断一致：文本里含 tiktok 即算。
     * 响应里找不到邮件数组时，退化为整段响应（先去掉邮箱地址本身）里找 tiktok。
     * @return array{hit:bool,count:int}
     */
    public static function inspectInbox(array $data, string $address): array
    {
        $strip = static fn(string $t): string => str_ireplace($address, '', $t);
        $list = self::findMailList($data);
        if ($list === null) {
            $text = $strip((string)json_encode($data, JSON_UNESCAPED_UNICODE));
            return ['hit' => (bool)preg_match('/tiktok/i', $text), 'count' => 0];
        }
        foreach ($list as $m) {
            $text = is_array($m) ? (string)json_encode($m, JSON_UNESCAPED_UNICODE) : (string)$m;
            if (preg_match('/tiktok/i', $strip($text))) {
                return ['hit' => true, 'count' => count($list)];
            }
        }
        return ['hit' => false, 'count' => count($list)];
    }

    /** 还没检查过收件箱的未使用邮箱，按 id 顺序 @return list<array{id:int,email:string}> */
    public static function pendingCheck(int $limit = 0, bool $recheck = false): array
    {
        self::ensureSchema();
        $sql = "SELECT `id`, `email` FROM `register_mailboxes` WHERE `status`='unused'" . ($recheck ? '' : ' AND `checked_at` IS NULL') . ' ORDER BY `id` ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        $rows = DB::select($sql) ?: [];
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'email' => (string)$r['email']], $rows);
    }

    /** 标记为不可用（收到过 TikTok 邮件），取邮箱时会被忽略 */
    public static function markBlocked(int $id, string $reason): void
    {
        DB::execute("UPDATE `register_mailboxes` SET `status`='blocked', `block_reason`=?, `checked_at`=NOW() WHERE `id`=? AND `status`='unused'", [mb_substr($reason, 0, 255), $id]);
    }

    /** 收件箱检查过、没有 TikTok 邮件：保持未使用，记录检查时间 */
    public static function markChecked(int $id): void
    {
        DB::execute('UPDATE `register_mailboxes` SET `checked_at`=NOW() WHERE `id`=?', [$id]);
    }

    /** @return array{unused:int,used:int,blocked:int,total:int} */
    public static function stats(): array
    {
        self::ensureSchema();
        $r = DB::selectOne("SELECT COUNT(*) AS total, SUM(status='unused') AS unused, SUM(status='used') AS used, SUM(status='blocked') AS blocked FROM `register_mailboxes`") ?: [];
        return [
            'unused' => (int)($r['unused'] ?? 0),
            'used' => (int)($r['used'] ?? 0),
            'blocked' => (int)($r['blocked'] ?? 0),
            'total' => (int)($r['total'] ?? 0),
        ];
    }

    /** @return array{id:int,email:string,status:string,used_at:string}|null */
    private static function tryClaim(): ?array
    {
        $token = bin2hex(random_bytes(8));
        $n = DB::execute(
            "UPDATE `register_mailboxes` SET `status`='used', `used_at`=NOW(), `claim_token`=? WHERE `status`='unused' ORDER BY `id` ASC LIMIT 1",
            [$token]
        );
        if ($n < 1) {
            return null;
        }
        $row = DB::selectOne('SELECT `id`, `email`, `status`, `used_at` FROM `register_mailboxes` WHERE `claim_token`=? LIMIT 1', [$token]);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'status' => (string)$row['status'],
            'used_at' => (string)($row['used_at'] ?? ''),
        ];
    }
}
