<?php
declare(strict_types=1);

namespace App\Services\PlanTask;

use App\Core\Database\DB;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiPromptCatalog;
use App\Services\Ai\AiPromptHelper;
use App\Services\Mail\MailService;
use App\Services\Mail\MailTemplateCatalog;

/**
 * 拉取数据（pull_stats）成功后的 AI 分析 + 邮件计划任务。
 */
class PullDataAnalysisService
{
    public const TYPE = 'pull_data_analysis';
    public const PROMPT_CODE = 'pull_data_analysis';
    public const MAIL_CODE = 'pull_data_analysis_report';

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        PlanTaskRecipientResolver::ensureAdminEmailColumn();
        MailTemplateCatalog::ensure();
        AiPromptCatalog::ensure();
        DB::query(
            "CREATE TABLE IF NOT EXISTS `plan_tasks` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `type` VARCHAR(64) NOT NULL DEFAULT '',
              `name` VARCHAR(191) NOT NULL DEFAULT '',
              `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
              `account_id` INT NOT NULL DEFAULT 0,
              `source_type` VARCHAR(64) NOT NULL DEFAULT '',
              `source_ref_id` INT NOT NULL DEFAULT 0,
              `work_item_id` INT NOT NULL DEFAULT 0,
              `batch_id` INT NOT NULL DEFAULT 0,
              `recipients_json` MEDIUMTEXT NULL,
              `payload_json` MEDIUMTEXT NULL,
              `ai_prompt_code` VARCHAR(64) NOT NULL DEFAULT 'pull_data_analysis',
              `mail_template_code` VARCHAR(64) NOT NULL DEFAULT 'pull_data_analysis_report',
              `ai_result_json` MEDIUMTEXT NULL,
              `ai_raw_text` MEDIUMTEXT NULL,
              `mail_results_json` MEDIUMTEXT NULL,
              `error_message` TEXT NULL,
              `started_at` DATETIME NULL,
              `finished_at` DATETIME NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_plan_tasks_type_work_item` (`type`, `work_item_id`),
              KEY `idx_plan_tasks_status` (`status`),
              KEY `idx_plan_tasks_account` (`account_id`),
              KEY `idx_plan_tasks_type_status` (`type`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * 工作项完成钩子：仅 pull_stats + completed 时尝试入队。
     *
     * @param array<string,mixed> $item account_work_items 行
     * @return array{ok:bool,skipped?:bool,reason?:string,id?:int}
     */
    public static function enqueueFromWorkItem(array $item): array
    {
        self::ensureSchema();
        $type = (string)($item['task_type'] ?? '');
        if ($type !== 'pull_stats') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_pull_stats'];
        }
        $accountId = (int)($item['account_id'] ?? 0);
        $workItemId = (int)($item['id'] ?? 0);
        $batchId = (int)($item['batch_id'] ?? 0);
        if ($accountId <= 0 || $workItemId <= 0) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_ids'];
        }

        $exist = DB::selectOne(
            'SELECT id, status FROM plan_tasks WHERE type=? AND work_item_id=? LIMIT 1',
            [self::TYPE, $workItemId]
        );
        if ($exist) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'already_enqueued', 'id' => (int)$exist['id']];
        }

        $resolved = PlanTaskRecipientResolver::resolveForAccount($accountId);
        if (!$resolved['emails']) {
            return [
                'ok' => false,
                'skipped' => true,
                'reason' => 'no_recipient_email',
            ];
        }

        $account = $resolved['account'] ?: [];
        $username = (string)($account['username'] ?? ('#' . $accountId));
        $payload = self::collectMetricsSnapshot($accountId);
        $now = date('Y-m-d H:i:s');
        $id = (int)DB::table('plan_tasks')->insert([
            'type' => self::TYPE,
            'name' => '拉取数据分析 · ' . $username,
            'status' => 'pending',
            'account_id' => $accountId,
            'source_type' => 'pull_stats',
            'source_ref_id' => (int)($item['task_ref_id'] ?? 0),
            'work_item_id' => $workItemId,
            'batch_id' => $batchId,
            'recipients_json' => json_encode($resolved['recipients'], JSON_UNESCAPED_UNICODE),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ai_prompt_code' => self::PROMPT_CODE,
            'mail_template_code' => self::MAIL_CODE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @return array{scanned:int,processed:int,completed:int,failed:int,skipped:int,items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 5): array
    {
        self::ensureSchema();
        $limit = max(1, min(50, $limit));
        $rows = DB::select(
            "SELECT * FROM plan_tasks
             WHERE type=? AND status='pending'
             ORDER BY id ASC LIMIT {$limit}",
            [self::TYPE]
        ) ?: [];

        $out = [
            'scanned' => count($rows),
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'items' => [],
        ];
        foreach ($rows as $row) {
            $out['processed']++;
            $result = self::processOne((int)$row['id']);
            $out['items'][] = $result;
            if (!empty($result['ok'])) {
                if (($result['status'] ?? '') === 'skipped') {
                    $out['skipped']++;
                } else {
                    $out['completed']++;
                }
            } else {
                $out['failed']++;
            }
        }
        return $out;
    }

    /**
     * @return array{ok:bool,id:int,status?:string,error?:string,mails?:list<array<string,mixed>>}
     */
    public static function processOne(int $id, bool $force = false): array
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM plan_tasks WHERE id=? LIMIT 1', [$id]);
        if (!$row) {
            return ['ok' => false, 'id' => $id, 'error' => '任务不存在'];
        }
        if ((string)$row['type'] !== self::TYPE) {
            return ['ok' => false, 'id' => $id, 'error' => '任务类型不匹配'];
        }
        $status = (string)($row['status'] ?? '');
        if (!$force && !in_array($status, ['pending', 'failed'], true)) {
            return ['ok' => false, 'id' => $id, 'error' => '仅 pending/failed 可执行，当前=' . $status];
        }

        DB::query(
            "UPDATE plan_tasks SET status='running', started_at=COALESCE(started_at, NOW()), error_message='', updated_at=NOW() WHERE id=?",
            [$id]
        );

        try {
            $recipients = json_decode((string)($row['recipients_json'] ?? '[]'), true) ?: [];
            $emails = [];
            foreach ($recipients as $r) {
                if (is_string($r)) {
                    $emails[] = strtolower(trim($r));
                } elseif (is_array($r)) {
                    $emails[] = strtolower(trim((string)($r['email'] ?? '')));
                }
            }
            $emails = array_values(array_unique(array_filter($emails, static fn ($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))));
            if (!$emails) {
                // 运行时再解析一次
                $resolved = PlanTaskRecipientResolver::resolveForAccount((int)$row['account_id']);
                $emails = $resolved['emails'];
                $recipients = $resolved['recipients'];
                DB::query(
                    'UPDATE plan_tasks SET recipients_json=?, updated_at=NOW() WHERE id=?',
                    [json_encode($recipients, JSON_UNESCAPED_UNICODE), $id]
                );
            }
            if (!$emails) {
                self::finish($id, 'skipped', null, null, [], '无有效收件邮箱（账号未绑定管理用户/企业用户或邮箱为空）');
                return ['ok' => true, 'id' => $id, 'status' => 'skipped'];
            }

            $payload = json_decode((string)($row['payload_json'] ?? '{}'), true) ?: [];
            if (!$payload) {
                $payload = self::collectMetricsSnapshot((int)$row['account_id']);
                DB::query(
                    'UPDATE plan_tasks SET payload_json=?, updated_at=NOW() WHERE id=?',
                    [json_encode($payload, JSON_UNESCAPED_UNICODE), $id]
                );
            }

            $ai = self::runAiAnalysis($payload);
            $mailVars = self::buildMailVariables($payload, $ai['parsed'], $ai['raw']);
            $rendered = MailTemplateCatalog::renderByCode(
                (string)($row['mail_template_code'] ?: self::MAIL_CODE),
                $mailVars
            );

            $mailResults = [];
            $anyOk = false;
            $errors = [];
            foreach ($emails as $email) {
                $send = MailService::sendHtml($email, $rendered['subject'], $rendered['html']);
                $mailResults[] = [
                    'email' => $email,
                    'ok' => !empty($send['ok']),
                    'error' => $send['error'] ?? null,
                    'sentAt' => date('Y-m-d H:i:s'),
                ];
                if (!empty($send['ok'])) {
                    $anyOk = true;
                } else {
                    $errors[] = $email . ': ' . ($send['error'] ?? 'send_failed');
                }
            }

            if (!$anyOk) {
                self::finish(
                    $id,
                    'failed',
                    $ai['parsed'],
                    $ai['raw'],
                    $mailResults,
                    '邮件全部发送失败：' . implode('；', $errors)
                );
                return ['ok' => false, 'id' => $id, 'error' => '邮件全部发送失败', 'mails' => $mailResults];
            }

            $warn = $errors ? ('部分失败：' . implode('；', $errors)) : '';
            self::finish($id, 'completed', $ai['parsed'], $ai['raw'], $mailResults, $warn);
            return ['ok' => true, 'id' => $id, 'status' => 'completed', 'mails' => $mailResults];
        } catch (\Throwable $e) {
            self::finish($id, 'failed', null, null, [], $e->getMessage());
            return ['ok' => false, 'id' => $id, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed>|null $aiResult
     * @param list<array<string,mixed>> $mailResults
     */
    private static function finish(
        int $id,
        string $status,
        ?array $aiResult,
        ?string $aiRaw,
        array $mailResults,
        string $error = ''
    ): void {
        DB::query(
            'UPDATE plan_tasks
             SET status=?, ai_result_json=?, ai_raw_text=?, mail_results_json=?, error_message=?, finished_at=NOW(), updated_at=NOW()
             WHERE id=?',
            [
                $status,
                $aiResult === null ? null : json_encode($aiResult, JSON_UNESCAPED_UNICODE),
                $aiRaw,
                $mailResults ? json_encode($mailResults, JSON_UNESCAPED_UNICODE) : null,
                $error,
                $id,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function collectMetricsSnapshot(int $accountId): array
    {
        $account = DB::selectOne(
            'SELECT id, username, nickname, status, login_status, owner_id, company_id FROM accounts WHERE id=? LIMIT 1',
            [$accountId]
        ) ?: ['id' => $accountId];

        $profile = null;
        try {
            $profile = DB::selectOne('SELECT * FROM tiktok_profiles WHERE account_id=? LIMIT 1', [$accountId]);
        } catch (\Throwable $e) {
        }

        $analytics = [];
        try {
            $analytics = DB::select(
                'SELECT metric_date, video_views, profile_views, likes_count, comments_count, shares_count, est_rewards
                 FROM tiktok_analytics_28d WHERE account_id=? ORDER BY metric_date ASC',
                [$accountId]
            ) ?: [];
        } catch (\Throwable $e) {
        }

        $metrics = [];
        try {
            $metrics = DB::select(
                'SELECT tab_key, metric_key, metric_label, value_num, value_text
                 FROM tiktok_studio_metrics WHERE account_id=? ORDER BY tab_key, id',
                [$accountId]
            ) ?: [];
        } catch (\Throwable $e) {
        }

        $content = [];
        try {
            $content = DB::select(
                'SELECT * FROM tiktok_studio_content WHERE account_id=? ORDER BY id DESC LIMIT 20',
                [$accountId]
            ) ?: [];
        } catch (\Throwable $e) {
        }

        $totals = [
            'video_views' => 0,
            'profile_views' => 0,
            'likes_count' => 0,
            'comments_count' => 0,
            'shares_count' => 0,
            'est_rewards' => 0.0,
            'days' => count($analytics),
        ];
        foreach ($analytics as $day) {
            $totals['video_views'] += (int)($day['video_views'] ?? 0);
            $totals['profile_views'] += (int)($day['profile_views'] ?? 0);
            $totals['likes_count'] += (int)($day['likes_count'] ?? 0);
            $totals['comments_count'] += (int)($day['comments_count'] ?? 0);
            $totals['shares_count'] += (int)($day['shares_count'] ?? 0);
            $totals['est_rewards'] += (float)($day['est_rewards'] ?? 0);
        }

        return [
            'collectedAt' => date('Y-m-d H:i:s'),
            'period' => '近 28 天',
            'account' => [
                'id' => (int)($account['id'] ?? $accountId),
                'username' => (string)($account['username'] ?? ''),
                'nickname' => (string)($account['nickname'] ?? ''),
                'status' => (string)($account['status'] ?? ''),
                'loginStatus' => (string)($account['login_status'] ?? ''),
            ],
            'profile' => $profile ? [
                'handle' => (string)($profile['handle'] ?? ''),
                'displayName' => (string)($profile['display_name'] ?? ''),
                'followers' => (int)($profile['followers_count'] ?? 0),
                'following' => (int)($profile['following_count'] ?? 0),
                'likes' => (int)($profile['likes_count'] ?? 0),
                'videos' => (int)($profile['videos_count'] ?? 0),
                'comments' => (int)($profile['comments_count'] ?? 0),
                'bio' => (string)($profile['bio'] ?? ''),
                'lastSyncedAt' => $profile['last_synced_at'] ?? null,
            ] : null,
            'analytics28d' => [
                'totals' => $totals,
                'daily' => array_slice($analytics, -14),
            ],
            'studioMetrics' => array_slice($metrics, 0, 80),
            'topContent' => array_map(static function ($c) {
                return [
                    'title' => (string)($c['title'] ?? $c['caption'] ?? $c['name'] ?? ''),
                    'views' => (int)($c['views'] ?? $c['view_count'] ?? 0),
                    'likes' => (int)($c['likes'] ?? $c['like_count'] ?? 0),
                    'comments' => (int)($c['comments'] ?? $c['comment_count'] ?? 0),
                ];
            }, $content),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{raw:string,parsed:array<string,mixed>}
     */
    private static function runAiAnalysis(array $payload): array
    {
        AiPromptCatalog::ensure();
        $tpl = DB::selectOne(
            'SELECT content FROM ai_prompt_templates WHERE code=? AND enabled=1 LIMIT 1',
            [self::PROMPT_CODE]
        );
        $content = trim((string)($tpl['content'] ?? ''));
        if ($content === '') {
            $defs = AiPromptCatalog::definitions();
            foreach ($defs as $d) {
                if (($d['code'] ?? '') === self::PROMPT_CODE) {
                    $content = (string)$d['content'];
                    break;
                }
            }
        }
        if ($content === '') {
            throw new \RuntimeException('缺少 AI 提示词模板 pull_data_analysis');
        }

        $account = (string)($payload['account']['username'] ?? '');
        $period = (string)($payload['period'] ?? '近 28 天');
        $metricsJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rendered = AiPromptHelper::render($content, [
            'account' => $account,
            'period' => $period,
            'metrics_json' => $metricsJson ?: '{}',
            'now' => date('Y-m-d H:i:s'),
        ]);

        $result = AiClient::chat(
            [
                [
                    'role' => 'system',
                    'content' => '你是资深 TikTok 账号增长顾问。根据提供的账号数据做客观、可执行的中文分析。'
                        . '只输出一个合法 JSON 对象，不要 markdown 代码块，不要额外解释。',
                ],
                ['role' => 'user', 'content' => $rendered],
            ],
            ['operation' => self::PROMPT_CODE, 'timeout' => 90]
        );
        $raw = trim((string)($result['content'] ?? ''));
        $parsed = self::parseAiJson($raw);
        return ['raw' => $raw, 'parsed' => $parsed];
    }

    /**
     * @return array<string,mixed>
     */
    private static function parseAiJson(string $raw): array
    {
        $text = trim($raw);
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $text = $m[0];
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            return [
                'summary' => $raw !== '' ? $raw : 'AI 未返回可解析结果',
                'strengths' => [],
                'issues' => [],
                'actions' => [],
            ];
        }
        return [
            'summary' => (string)($data['summary'] ?? $data['overview'] ?? ''),
            'strengths' => self::normalizeList($data['strengths'] ?? $data['pros'] ?? []),
            'issues' => self::normalizeList($data['issues'] ?? $data['problems'] ?? []),
            'actions' => self::normalizeList($data['actions'] ?? $data['solutions'] ?? $data['plan'] ?? []),
            'score' => $data['score'] ?? null,
        ];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function normalizeList($raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\r\n]+|；|;/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $title = trim((string)($item['title'] ?? $item['point'] ?? $item['text'] ?? ''));
                $reason = trim((string)($item['reason'] ?? $item['why'] ?? $item['detail'] ?? ''));
                $line = $title;
                if ($reason !== '') {
                    $line = $title !== '' ? ($title . ' —— ' . $reason) : $reason;
                }
            } else {
                $line = trim((string)$item);
            }
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $ai
     * @return array<string,string>
     */
    private static function buildMailVariables(array $payload, array $ai, string $rawAi): array
    {
        $account = (string)($payload['account']['username'] ?? '');
        $nickname = (string)($payload['account']['nickname'] ?? '');
        $period = (string)($payload['period'] ?? '近 28 天');
        $totals = $payload['analytics28d']['totals'] ?? [];
        $profile = $payload['profile'] ?? null;

        $cards = [];
        $cards[] = self::metricCard('视频播放', self::fmtNum((int)($totals['video_views'] ?? 0)));
        $cards[] = self::metricCard('主页访问', self::fmtNum((int)($totals['profile_views'] ?? 0)));
        $cards[] = self::metricCard('获赞', self::fmtNum((int)($totals['likes_count'] ?? 0)));
        $cards[] = self::metricCard('评论', self::fmtNum((int)($totals['comments_count'] ?? 0)));
        if (is_array($profile)) {
            $cards[] = self::metricCard('粉丝', self::fmtNum((int)($profile['followers'] ?? 0)));
            $cards[] = self::metricCard('作品数', self::fmtNum((int)($profile['videos'] ?? 0)));
        }

        $summary = trim((string)($ai['summary'] ?? ''));
        if ($summary === '' && $rawAi !== '') {
            $summary = mb_substr($rawAi, 0, 800);
        }

        return [
            'account' => '@' . ltrim($account, '@'),
            'nickname' => $nickname !== '' ? ('（' . $nickname . '）') : '',
            'report_date' => date('Y-m-d H:i'),
            'period' => $period,
            'metric_cards_html' => '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
                . implode('', array_map(static fn ($c) => '<td style="width:33%;padding:4px;">' . $c . '</td>', array_slice($cards, 0, 3)))
                . '</tr><tr>'
                . implode('', array_map(static fn ($c) => '<td style="width:33%;padding:4px;">' . $c . '</td>', array_slice($cards, 3, 3)))
                . '</tr></table>',
            'summary_html' => self::escHtml($summary !== '' ? $summary : '暂无概况'),
            'strengths_html' => self::listHtml($ai['strengths'] ?? [], '暂无明显亮点，建议持续观察数据趋势。'),
            'issues_html' => self::listHtml($ai['issues'] ?? [], '暂未识别出显著问题。'),
            'actions_html' => self::listHtml($ai['actions'] ?? [], '建议保持现有节奏并继续拉取数据复盘。'),
            'raw_metrics_html' => self::escHtml(self::rawMetricsText($payload)),
            'footer_note' => '本邮件由 TkSwarm 计划任务自动生成，请勿直接回复。',
        ];
    }

    private static function metricCard(string $label, string $value): string
    {
        return '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 12px;text-align:center;">'
            . '<div style="font-size:11px;color:#64748b;letter-spacing:0.04em;">' . self::escHtml($label) . '</div>'
            . '<div style="margin-top:6px;font-size:20px;font-weight:700;color:#0f172a;">' . self::escHtml($value) . '</div>'
            . '</div>';
    }

    /** @param list<string> $items */
    private static function listHtml(array $items, string $empty): string
    {
        if (!$items) {
            return self::escHtml($empty);
        }
        $lis = '';
        foreach ($items as $item) {
            $lis .= '<li style="margin:0 0 8px;">' . self::escHtml($item) . '</li>';
        }
        return '<ul style="margin:0;padding-left:18px;">' . $lis . '</ul>';
    }

    /** @param array<string,mixed> $payload */
    private static function rawMetricsText(array $payload): string
    {
        $lines = [];
        $t = $payload['analytics28d']['totals'] ?? [];
        $lines[] = '统计天数：' . (int)($t['days'] ?? 0);
        $lines[] = '视频播放：' . self::fmtNum((int)($t['video_views'] ?? 0));
        $lines[] = '主页访问：' . self::fmtNum((int)($t['profile_views'] ?? 0));
        $lines[] = '点赞：' . self::fmtNum((int)($t['likes_count'] ?? 0));
        $lines[] = '评论：' . self::fmtNum((int)($t['comments_count'] ?? 0));
        $lines[] = '分享：' . self::fmtNum((int)($t['shares_count'] ?? 0));
        $lines[] = '预估收益：' . number_format((float)($t['est_rewards'] ?? 0), 2);
        $p = $payload['profile'] ?? null;
        if (is_array($p)) {
            $lines[] = '粉丝：' . self::fmtNum((int)($p['followers'] ?? 0));
            $lines[] = '作品：' . self::fmtNum((int)($p['videos'] ?? 0));
            $lines[] = '资料同步：' . (string)($p['lastSyncedAt'] ?? '-');
        }
        return implode("\n", $lines);
    }

    private static function fmtNum(int $n): string
    {
        if ($n >= 100000000) {
            return rtrim(rtrim(number_format($n / 100000000, 2), '0'), '.') . '亿';
        }
        if ($n >= 10000) {
            return rtrim(rtrim(number_format($n / 10000, 2), '0'), '.') . '万';
        }
        return number_format($n);
    }

    private static function escHtml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
