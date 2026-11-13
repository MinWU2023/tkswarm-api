<?php

namespace App\Services;

use App\Core\Database\DB;

/**
 * 注册邮箱库：邮箱来源于 tkswarm-api/emails.txt（每行一个），导入 register_mailboxes 表后逐个取用。
 * 状态：unused 未使用 / used 已使用；used_at 记录取用时间。
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
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_register_mailboxes_email` (`email`),
            KEY `idx_register_mailboxes_status_id` (`status`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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

    /** @return array{unused:int,used:int,total:int} */
    public static function stats(): array
    {
        self::ensureSchema();
        $r = DB::selectOne("SELECT COUNT(*) AS total, SUM(status='unused') AS unused, SUM(status='used') AS used FROM `register_mailboxes`") ?: [];
        return [
            'unused' => (int)($r['unused'] ?? 0),
            'used' => (int)($r['used'] ?? 0),
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
