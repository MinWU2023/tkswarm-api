<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiPromptCatalog;
use App\Services\Ai\AiPromptHelper;

/**
 * 账号系统通知 & 违规封停判定。
 *
 * 流程：
 *   1) Node 抓 Studio「系统通知」→ ingest() 按 (时间 + 内容) 排重入库；
 *   2) 入库时用关键词做机器初筛，命中的标记 is_violation=1；
 *   3) 有新的命中通知 → assess() 调 AI 提示词模板 account_ban_assess 复核，
 *      返回 is_banned / trigger_time / recover_time / reason；
 *   4) 依据 (is_banned && 恢复时间为空或未到期) 回写 accounts.account_status / ban_reason / ban_until；
 *   5) restoreExpired() 到解封时间自动恢复为 normal（Node 定时任务 + 列表页兜底调用）。
 */
class AccountBanService
{
    public const PROMPT_CODE = 'account_ban_assess';

    /** 违规/封停相关字样：命中即视为「疑似违规通知」，再交 AI 复核（宁多勿漏，最终由 AI 定性） */
    public const VIOLATION_KEYWORDS = [
        // 英文
        'banned', 'ban ', 'suspend', 'violat', 'community guideline', 'community standards',
        'restrict', 'deactivat', 'terminat', 'permanently', 'account has been', 'account was',
        'not eligible', 'under review', 'appeal', 'strike', 'warning', 'prohibit',
        'removed', 'taken down', 'no longer available', 'ban notice',
        // 中文
        '封禁', '封号', '封停', '停用', '停封', '禁用', '禁言', '限制', '违规', '违反',
        '社区规范', '社区准则', '处罚', '处置', '申诉', '警告', '永久', '暂时', '下架', '删除',
    ];

    /**
     * 建表 + 补字段。注意 $done 必须放在最后：建表失败时要允许下次重试，
     * 否则第一次在 try/catch 里失败后，后续所有调用都会直接 return，表永远不存在。
     */
    public static function ensureSchema(bool $force = false): void
    {
        static $done = false;
        if ($done && !$force) {
            return;
        }

        try {
            DB::getPdo()->exec(
                "CREATE TABLE IF NOT EXISTS account_notifications (
                  id BIGINT AUTO_INCREMENT PRIMARY KEY,
                  account_id INT NOT NULL,
                  owner_id INT NOT NULL DEFAULT 0,
                  notice_hash CHAR(40) NOT NULL,
                  notice_key VARCHAR(191) NOT NULL DEFAULT '',
                  category VARCHAR(32) NOT NULL DEFAULT 'general',
                  title VARCHAR(512) NOT NULL DEFAULT '',
                  content TEXT NULL,
                  notice_time VARCHAR(64) NOT NULL DEFAULT '',
                  noticed_at DATETIME NULL,
                  matched_keywords VARCHAR(512) NOT NULL DEFAULT '',
                  is_violation TINYINT(1) NOT NULL DEFAULT 0,
                  ai_judged TINYINT(1) NOT NULL DEFAULT 0,
                  ai_result_json MEDIUMTEXT NULL,
                  raw_json MEDIUMTEXT NULL,
                  source VARCHAR(32) NOT NULL DEFAULT 'studio',
                  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uk_an_acc_hash (account_id, notice_hash),
                  KEY idx_an_acc_time (account_id, noticed_at),
                  KEY idx_an_acc_violation (account_id, is_violation)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            error_log('AccountBanService ensureSchema create table failed: ' . $e->getMessage());
        }

        $alters = [
            'ban_reason' => 'ALTER TABLE accounts ADD COLUMN ban_reason TEXT NULL',
            'ban_until' => 'ALTER TABLE accounts ADD COLUMN ban_until DATETIME NULL DEFAULT NULL',
            'ban_checked_at' => 'ALTER TABLE accounts ADD COLUMN ban_checked_at DATETIME NULL DEFAULT NULL',
            'ban_source' => "ALTER TABLE accounts ADD COLUMN ban_source VARCHAR(16) NOT NULL DEFAULT ''",
            'ban_ai_json' => 'ALTER TABLE accounts ADD COLUMN ban_ai_json MEDIUMTEXT NULL',
        ];
        foreach ($alters as $col => $sql) {
            if (!self::hasColumn('accounts', $col)) {
                try {
                    DB::getPdo()->exec($sql);
                } catch (\Throwable $e) {
                    error_log("AccountBanService add column {$col} failed: " . $e->getMessage());
                }
            }
        }

        // 只有确认表真的存在才把 $done 置位，否则下次调用继续重试
        try {
            $exists = DB::select("SHOW TABLES LIKE 'account_notifications'");
            if (!empty($exists)) {
                $done = true;
            }
        } catch (\Throwable $e) {
            error_log('AccountBanService ensureSchema verify failed: ' . $e->getMessage());
        }
    }

    /** 表是否存在（用于入库时的自愈判断） */
    private static function notificationsTableExists(): bool
    {
        try {
            return !empty(DB::select("SHOW TABLES LIKE 'account_notifications'"));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function hasColumn(string $table, string $column): bool
    {
        try {
            foreach (DB::select("SHOW COLUMNS FROM `{$table}`") as $row) {
                if ((string)($row['Field'] ?? '') === $column) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
        }
        return false;
    }

    // ------------------------------------------------------------------ 入库

    /**
     * @param list<array<string,mixed>> $items
     * @return array{inserted:int,skipped:int,violations:int,newViolations:int,ban:?array<string,mixed>}
     */
    public static function ingest(int $accountId, array $items, array $meta = []): array
    {
        self::ensureSchema();
        if (!self::notificationsTableExists()) {
            // 上次建表被吞掉了（例如在 try/catch 里首次调用失败），强制再建一次
            self::ensureSchema(true);
        }
        $account = DB::selectOne('SELECT id, owner_id, account_status, ban_source FROM accounts WHERE id=?', [$accountId]);
        $ownerId = (int)($account['owner_id'] ?? 0);
        $source = trim((string)($meta['source'] ?? 'studio')) ?: 'studio';

        $inserted = 0;
        $skipped = 0;
        $violations = 0;
        $newViolations = 0;
        $lastError = null;
        $schemaRetried = false;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = self::clip((string)($item['title'] ?? ''), 512);
            $content = trim((string)($item['content'] ?? ''));
            $timeText = trim((string)($item['time'] ?? $item['noticeTime'] ?? $item['notice_time'] ?? ''));
            if ($title === '' && $content === '') {
                continue;
            }
            $noticedAt = self::parseTime($timeText);
            $noticeKey = trim((string)($item['key'] ?? $item['id'] ?? ''));
            $hashBody = $noticeKey !== '' ? ($content . "\n#" . $noticeKey) : $content;
            $hash = self::hashNotice($title, $hashBody, $noticedAt);
            $keys = self::matchKeywords($title . ' ' . $content . ' ' . $timeText);
            $isViolation = $keys ? 1 : 0;
            $category = trim((string)($item['category'] ?? ''));
            if ($category === '') {
                $category = $isViolation ? 'violation' : 'general';
            }
            $raw = $item['raw'] ?? null;
            $rawJson = '';
            if ($raw !== null) {
                $rawJson = is_string($raw) ? $raw : (json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '');
            }

            $affected = 0;
            try {
                $affected = DB::execute(
                    'INSERT IGNORE INTO account_notifications
                       (account_id, owner_id, notice_hash, notice_key, category, title, content,
                        notice_time, noticed_at, matched_keywords, is_violation, raw_json, source)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $accountId,
                        $ownerId,
                        $hash,
                        self::clip((string)($item['key'] ?? $item['id'] ?? ''), 191),
                        self::clip($category, 32),
                        $title,
                        $content,
                        self::clip($timeText, 64),
                        $noticedAt,
                        self::clip(implode(',', $keys), 512),
                        $isViolation,
                        self::clip($rawJson, 60000),
                        $source,
                    ]
                );
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                error_log('account_notifications INSERT error: ' . $e->getMessage());
                // 表不存在（1054/1146）时强制重建后重试一次，避免整批静默丢弃
                if (!$schemaRetried && preg_match('/1146|1054|doesn\'t exist|Unknown table/i', $lastError)) {
                    $schemaRetried = true;
                    self::ensureSchema(true);
                    try {
                        $affected = DB::execute(
                            'INSERT IGNORE INTO account_notifications
                               (account_id, owner_id, notice_hash, notice_key, category, title, content,
                                notice_time, noticed_at, matched_keywords, is_violation, raw_json, source)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                            [
                                $accountId,
                                $ownerId,
                                $hash,
                                self::clip((string)($item['key'] ?? $item['id'] ?? ''), 191),
                                self::clip($category, 32),
                                $title,
                                $content,
                                self::clip($timeText, 64),
                                $noticedAt,
                                self::clip(implode(',', $keys), 512),
                                $isViolation,
                                self::clip($rawJson, 60000),
                                $source,
                            ]
                        );
                        $lastError = null;
                    } catch (\Throwable $e2) {
                        $lastError = $e2->getMessage();
                        error_log('account_notifications INSERT retry error: ' . $e2->getMessage());
                        continue;
                    }
                } else {
                    continue;
                }
            }

            if ($affected > 0) {
                $inserted++;
                if ($isViolation) {
                    $newViolations++;
                }
            } else {
                $skipped++;
            }
            if ($isViolation) {
                $violations++;
            }
        }

        $ban = null;
        if ($newViolations > 0) {
            // 出现新的疑似违规通知 → 立刻让 AI 复核账号状态
            $ban = self::assess($accountId);
        }

        return [
            'received' => count($items),
            'tableReady' => self::notificationsTableExists(),
            'inserted' => $inserted,
            'skipped' => $skipped,
            'violations' => $violations,
            'newViolations' => $newViolations,
            'ban' => $ban,
            'error' => $lastError ?? null,
        ];
    }

    /** 排重键：内容归一化 + （能解析出绝对日期时才带上日期），相对时间不参与，避免「3 天前」变「4 天前」重复入库 */
    public static function hashNotice(string $title, string $content, ?string $noticedAt): string
    {
        $body = trim($content) !== '' ? $content : $title;
        $norm = mb_strtolower($body);
        $norm = preg_replace('/\s+/u', ' ', $norm) ?? $norm;
        $norm = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $norm) ?? $norm;
        $norm = trim($norm);
        $day = $noticedAt ? substr($noticedAt, 0, 10) : '';
        return sha1($norm . '|' . $day);
    }

    /** @return list<string> */
    public static function matchKeywords(string $text): array
    {
        $text = mb_strtolower($text);
        if (trim($text) === '') {
            return [];
        }
        $hits = [];
        foreach (self::VIOLATION_KEYWORDS as $kw) {
            if ($kw === '') {
                continue;
            }
            if (mb_strpos($text, mb_strtolower($kw)) !== false) {
                $hits[] = $kw;
            }
        }
        return array_values(array_unique($hits));
    }

    private static function parseTime(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(刚刚|just now|now)$/iu', $text)) {
            return date('Y-m-d H:i:s');
        }
        // TikTok 上的相对时间：3d / 2h / 5分钟前 / 2 hours ago → 换算成通知发生时间，不用抓取时刻
        if (preg_match('/^(\d+)\s*(seconds?|secs?|s|秒|minutes?|mins?|m|分钟|hours?|hrs?|h|小时|days?|d|天|weeks?|w|周|months?|mo|个月|years?|y|年)\s*(ago|前)?$/iu', $text, $m)) {
            $n = max(0, (int)$m[1]);
            $unit = mb_strtolower($m[2]);
            $sec = 60;
            if (preg_match('/^(s|sec|secs|second|seconds|秒)$/u', $unit)) {
                $sec = 1;
            } elseif (preg_match('/^(h|hr|hrs|hour|hours|小时)$/u', $unit)) {
                $sec = 3600;
            } elseif (preg_match('/^(d|day|days|天)$/u', $unit)) {
                $sec = 86400;
            } elseif (preg_match('/^(w|week|weeks|周)$/u', $unit)) {
                $sec = 604800;
            } elseif (preg_match('/^(mo|month|months|个月)$/u', $unit)) {
                $sec = 2592000;
            } elseif (preg_match('/^(y|year|years|年)$/u', $unit)) {
                $sec = 31536000;
            }
            return date('Y-m-d H:i:s', time() - ($n * $sec));
        }
        // M-D / MM/DD（Studio 通知里常见，如 9-24、09/24）→ 补年份；比今天晚一天以上就算去年
        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})$/', $text, $m)) {
            $mon = (int)$m[1];
            $day = (int)$m[2];
            if ($mon >= 1 && $mon <= 12 && $day >= 1 && $day <= 31) {
                $year = (int)date('Y');
                $ts = mktime(0, 0, 0, $mon, $day, $year);
                if ($ts !== false && $ts > time() + 86400) {
                    $ts = mktime(0, 0, 0, $mon, $day, $year - 1);
                }
                return $ts ? date('Y-m-d H:i:s', $ts) : null;
            }
        }
        $ts = strtotime($text);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        // 只有「时:分」没有日期的，按今天补
        if (!preg_match('/\d{4}|\d{1,2}[-\/月]\d{1,2}|[A-Za-z]{3}/', $text)) {
            $today = date('Y-m-d') . ' ' . preg_replace('/^\D+/', '', $text);
            $ts2 = strtotime($today);
            if ($ts2 !== false && $ts2 > 0) {
                return date('Y-m-d H:i:s', $ts2);
            }
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private static function clip(string $s, int $len): string
    {
        return mb_substr($s, 0, $len);
    }

    // ------------------------------------------------------------------ AI 判定

    public static function resolvePrompt(): string
    {
        try {
            AiPromptCatalog::ensure();
            $row = DB::selectOne('SELECT content FROM ai_prompt_templates WHERE code=? AND enabled=1 LIMIT 1', [self::PROMPT_CODE]);
            $content = trim((string)($row['content'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        } catch (\Throwable $e) {
        }
        return self::defaultPrompt();
    }

    public static function defaultPrompt(): string
    {
        return "你是 TikTok 账号风控分析助手。下面是该账号收到的系统通知（JSON 数组，含通知时间与正文）：\n"
            . "{{notices_json}}\n\n账号：{{account}}\n当前时间：{{now}}\n\n"
            . "判断这些通知是否表示该账号已被 TikTok 违规封停/禁用/限权，以及预计何时恢复。\n"
            . "输出仅一个 JSON 对象：{\"is_banned\": true|false, \"trigger_time\": \"YYYY-MM-DD HH:MM\"|\"\", "
            . "\"recover_time\": \"YYYY-MM-DD HH:MM\"|\"\", \"reason\": \"简体中文解释\"}";
    }

    public static function systemPrompt(): string
    {
        return 'You are a TikTok account risk analyst. Decide from system notifications whether the account '
            . 'is banned/suspended/disabled for policy violations, and when it will recover. '
            . 'Warnings or single-video takedowns alone are NOT a ban. '
            . 'Output a single valid JSON object with keys is_banned (boolean), trigger_time (string, "" if unknown), '
            . 'recover_time (string, "" if unknown), reason (Simplified Chinese). '
            . 'No markdown, no text outside the JSON.';
    }

    /**
     * 用 AI 复核该账号的封停状态并回写。
     *
     * @return array<string,mixed>
     */
    public static function assess(int $accountId): array
    {
        self::ensureSchema();
        $account = DB::selectOne('SELECT * FROM accounts WHERE id=?', [$accountId]);
        if (!$account) {
            return ['ok' => false, 'error' => '账号不存在'];
        }
        $rows = DB::select(
            'SELECT id, category, title, content, notice_time, noticed_at, matched_keywords
               FROM account_notifications
              WHERE account_id=? AND is_violation=1
              ORDER BY COALESCE(noticed_at, created_at) DESC, id DESC
              LIMIT 20',
            [$accountId]
        );
        if (!$rows) {
            DB::query('UPDATE accounts SET ban_checked_at=NOW() WHERE id=?', [$accountId]);
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => '没有命中违禁词的通知',
                'banned' => ((string)($account['account_status'] ?? 'normal')) === 'banned',
            ];
        }

        $payload = [];
        foreach ($rows as $r) {
            $payload[] = [
                'time' => (string)(($r['noticed_at'] ?? '') !== '' && $r['noticed_at'] !== null ? $r['noticed_at'] : ($r['notice_time'] ?? '')),
                'category' => (string)($r['category'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'content' => (string)($r['content'] ?? ''),
            ];
        }

        $rendered = AiPromptHelper::render(self::resolvePrompt(), [
            'notices_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'account' => (string)($account['username'] ?? ''),
            'now' => date('Y-m-d H:i:s'),
        ]);

        $raw = '';
        try {
            $result = AiClient::chat(
                [
                    ['role' => 'system', 'content' => self::systemPrompt()],
                    ['role' => 'user', 'content' => $rendered],
                ],
                ['operation' => self::PROMPT_CODE, 'timeout' => 60]
            );
            $raw = trim((string)($result['content'] ?? ''));
        } catch (\Throwable $e) {
            try {
                DB::query(
                    'UPDATE accounts SET ban_checked_at=NOW(), ban_ai_json=? WHERE id=?',
                    [json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) ?: '', $accountId]
                );
            } catch (\Throwable $e2) {
            }
            return ['ok' => false, 'error' => 'AI 调用失败：' . $e->getMessage(), 'banned' => ((string)($account['account_status'] ?? 'normal')) === 'banned'];
        }

        $verdict = self::parseVerdict($raw);
        $saidBanned = (bool)$verdict['is_banned'];
        $recoverAt = $verdict['recover_time'];
        $triggerAt = $verdict['trigger_time'];
        $reason = $verdict['reason'];

        // 有解封时间且已过期 → 视为已恢复
        $expired = $recoverAt !== null && strtotime($recoverAt) !== false && strtotime($recoverAt) <= time();
        $isBanned = $saidBanned && !$expired;

        $prevStatus = (string)($account['account_status'] ?? 'normal');
        $prevSource = (string)($account['ban_source'] ?? '');
        $manual = $prevStatus === 'banned' && $prevSource === 'manual';
        if ($manual && !$isBanned) {
            // 人工设置的封禁不被 AI 自动解除
            $isBanned = true;
            $reason = $reason !== '' ? $reason : (string)($account['ban_reason'] ?? '');
            $recoverAt = $account['ban_until'] ?? null;
        }

        $aiJson = json_encode([
            'raw' => mb_substr($raw, 0, 20000),
            'parsed' => $verdict,
            'expired' => $expired,
            'judged_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE) ?: '';

        try {
            DB::query(
                'UPDATE accounts SET account_status=?, ban_reason=?, ban_until=?, ban_checked_at=NOW(), ban_source=?, ban_ai_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [
                    $isBanned ? 'banned' : 'normal',
                    $isBanned ? self::clip($reason, 2000) : ($expired ? self::clip($reason, 2000) : null),
                    $recoverAt,
                    $isBanned ? ($manual ? 'manual' : 'ai') : '',
                    $aiJson,
                    $accountId,
                ]
            );
            $ids = array_column($rows, 'id');
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge([mb_substr($aiJson, 0, 20000)], $ids);
                DB::query("UPDATE account_notifications SET ai_judged=1, ai_result_json=? WHERE id IN ({$ph})", $params);
            }
        } catch (\Throwable $e) {
        }

        return [
            'ok' => true,
            'is_banned' => $isBanned,
            'ai_said_banned' => $saidBanned,
            'recover_time' => $recoverAt,
            'trigger_time' => $triggerAt,
            'reason' => $reason,
            'notice_count' => count($rows),
            'expired' => $expired,
            'raw' => mb_substr($raw, 0, 4000),
        ];
    }

    /**
     * 宽松解析 AI 返回：兼容 is_banned / banned / exists / recover_time / unban_time 等字段名。
     *
     * @return array{is_banned:bool,trigger_time:?string,recover_time:?string,reason:string}
     */
    public static function parseVerdict(string $raw): array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?/i', '', $text) ?? $text;
        $text = preg_replace('/```$/', '', $text) ?? $text;
        $text = trim($text);
        $data = null;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
        }
        if (!is_array($data)) {
            $data = json_decode($text, true);
        }
        if (!is_array($data)) {
            return ['is_banned' => false, 'trigger_time' => null, 'recover_time' => null, 'reason' => ''];
        }
        $pick = static function (array $d, array $keys) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $d)) {
                    return $d[$k];
                }
            }
            return null;
        };
        $flag = $pick($data, ['is_banned', 'isBanned', 'banned', 'exists', 'is_violation', 'isViolation', 'violation']);
        if (is_string($flag)) {
            $flag = in_array(mb_strtolower(trim($flag)), ['1', 'true', 'yes', 'y', '是', '存在', 'banned'], true);
        }
        $trigger = $pick($data, ['trigger_time', 'triggerTime', 'violation_time', 'violationTime', 'ban_time', 'banned_at', 'start_time']);
        $recover = $pick($data, ['recover_time', 'recoverTime', 'unban_time', 'unbanTime', 'unban_at', 'restore_time', 'expire_time', 'end_time']);
        $reason = $pick($data, ['reason', 'explanation', 'describe', 'description', 'detail', 'msg', 'message']);

        return [
            'is_banned' => (bool)$flag,
            'trigger_time' => self::normalizeDateTime(is_scalar($trigger) ? (string)$trigger : ''),
            'recover_time' => self::normalizeDateTime(is_scalar($recover) ? (string)$recover : ''),
            'reason' => is_scalar($reason) ? trim((string)$reason) : '',
        ];
    }

    private static function normalizeDateTime(string $s): ?string
    {
        $s = trim($s);
        if ($s === '' || $s === 'null' || $s === '""' || $s === '-') {
            return null;
        }
        $ts = strtotime($s);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    // ------------------------------------------------------------------ 解封

    /** 到解封时间自动恢复为 normal，返回恢复数量 */
    public static function restoreExpired(): int
    {
        self::ensureSchema();
        try {
            return DB::execute(
                "UPDATE accounts
                    SET account_status='normal', ban_until=NULL, ban_source='auto-restored',
                        ban_checked_at=NOW(), updated_at=CURRENT_TIMESTAMP
                  WHERE account_status='banned' AND ban_until IS NOT NULL AND ban_until<=NOW()"
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ------------------------------------------------------------------ 查询

    /** 有「未判定的违规通知」的账号（供定时任务补跑 AI 判定，带 30 分钟冷却避免反复烧额度） */
    public static function pendingAssessAccountIds(int $limit = 3): array
    {
        self::ensureSchema();
        $limit = max(1, $limit);
        try {
            $rows = DB::select(
                "SELECT DISTINCT n.account_id
                   FROM account_notifications n
                   JOIN accounts a ON a.id = n.account_id
                  WHERE n.is_violation = 1 AND n.ai_judged = 0
                    AND (a.ban_checked_at IS NULL OR a.ban_checked_at < (NOW() - INTERVAL 30 MINUTE))
                  ORDER BY n.account_id ASC
                  LIMIT {$limit}"
            );
            return array_map('intval', array_column($rows, 'account_id'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    public static function listForAccount(int $accountId, int $page = 1, int $pageSize = 20, string $filter = ''): array
    {
        self::ensureSchema();
        if (!self::notificationsTableExists()) {
            self::ensureSchema(true);
        }
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $where = 'WHERE account_id=?';
        $params = [$accountId];
        if ($filter === 'violation') {
            $where .= ' AND is_violation=1';
        }
        $empty = [
            'items' => [],
            'total' => 0,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => 1,
            'violationCount' => 0,
        ];
        try {
            $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM account_notifications {$where}", $params)['c'] ?? 0);
            $offset = ($page - 1) * $pageSize;
            $rows = DB::select(
                "SELECT * FROM account_notifications {$where}
                  ORDER BY COALESCE(noticed_at, created_at) DESC, id DESC
                  LIMIT {$pageSize} OFFSET {$offset}",
                $params
            );
            return [
                'items' => array_map([self::class, 'publicNotice'], $rows),
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'pages' => max(1, (int)ceil($total / $pageSize)),
                'violationCount' => (int)(DB::selectOne(
                    'SELECT COUNT(*) AS c FROM account_notifications WHERE account_id=? AND is_violation=1',
                    [$accountId]
                )['c'] ?? 0),
            ];
        } catch (\Throwable $e) {
            error_log('AccountBanService listForAccount failed: ' . $e->getMessage());
            $empty['error'] = $e->getMessage();
            return $empty;
        }
    }

    public static function find(int $accountId, int $noticeId): ?array
    {
        self::ensureSchema();
        try {
            $row = DB::selectOne('SELECT * FROM account_notifications WHERE id=? AND account_id=?', [$noticeId, $accountId]);
            return $row ? self::publicNotice($row) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    public static function publicNotice(array $row): array
    {
        $keywords = trim((string)($row['matched_keywords'] ?? ''));
        return [
            'id' => (int)($row['id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'content' => (string)($row['content'] ?? ''),
            'category' => (string)($row['category'] ?? 'general'),
            'noticeTime' => (string)($row['notice_time'] ?? ''),
            'noticedAt' => $row['noticed_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'isViolation' => (int)($row['is_violation'] ?? 0) === 1,
            'keywords' => $keywords === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $keywords)))),
            'aiJudged' => (int)($row['ai_judged'] ?? 0) === 1,
            'source' => (string)($row['source'] ?? 'studio'),
        ];
    }

    /** @return array<string,mixed> */
    public static function banStateOf(?array $account): array
    {
        if (!$account) {
            return ['status' => 'normal', 'banned' => false, 'reason' => '', 'banUntil' => null, 'checkedAt' => null, 'source' => ''];
        }
        $status = (string)($account['account_status'] ?? 'normal');
        return [
            'status' => $status,
            'banned' => $status === 'banned',
            'reason' => (string)($account['ban_reason'] ?? ''),
            'banUntil' => $account['ban_until'] ?? null,
            'checkedAt' => $account['ban_checked_at'] ?? null,
            'source' => (string)($account['ban_source'] ?? ''),
        ];
    }
}
