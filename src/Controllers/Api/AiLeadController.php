<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountWorkBatchService;
use App\Services\AiLeadExecutionService;
use App\Services\AiLeadVideoWeightService;
use App\Services\DataScope;
use App\Services\IndustryDataService;
use PDOException;

/**
 * AI拓客：配置 CRUD、关联账号、视频日志、私信记录、账号日私信次数
 */
class AiLeadController extends Controller
{
    public const TYPES = [
        'user' => '按用户',
        'video' => '按视频',
    ];

    public const STATUSES = [
        'draft' => '草稿',
        'active' => '进行中',
        'paused' => '已暂停',
        'completed' => '已完成',
    ];

    public const RECORD_STATUSES = [
        'pending' => '待发送',
        'sent' => '已发送',
        'failed' => '失败',
        'skipped' => '已跳过',
    ];

    public const CREATOR_STATUSES = [
        'new' => '新发现',
        'contacted' => '已联系',
        'followed' => '已关注',
        'ignored' => '已忽略',
    ];

    public function list(Request $request): Response
    {
        $this->ensureSchema();
        AiLeadExecutionService::ensureSchema();
        DataScope::ensureColumns();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;
        $keyword = trim((string)$request->get('keyword', ''));
        $status = trim((string)$request->get('status', ''));
        $type = trim((string)$request->get('type', ''));

        $filters = [];
        $params = [];
        $this->applyOwnerFilter('l.owner_id', $filters, $params);
        if ($status !== '' && isset(self::STATUSES[$status])) {
            $filters[] = 'l.status=?';
            $params[] = $status;
        }
        if ($type !== '' && isset(self::TYPES[$type])) {
            $filters[] = 'l.type=?';
            $params[] = $type;
        }
        if ($keyword !== '') {
            $filters[] = '(l.keywords LIKE ? OR l.type LIKE ? OR CAST(l.id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM ai_leads l {$where}", $params)['c'] ?? 0);
        $creatorCountFilter = AiLeadExecutionService::scrapedVideoCreatorFilterSql('cr');
        $rows = DB::select(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM ai_lead_accounts a WHERE a.lead_id=l.id) AS account_count,
                    (SELECT COUNT(*) FROM ai_lead_logs g WHERE g.lead_id=l.id) AS log_count,
                    (SELECT COUNT(*) FROM ai_lead_records r WHERE r.lead_id=l.id) AS record_count,
                    (SELECT COUNT(*) FROM ai_lead_comments c WHERE c.lead_id=l.id AND c.ai_pass=1) AS lead_comment_count,
                    (SELECT COUNT(*) FROM ai_lead_creators cr WHERE cr.lead_id=l.id AND {$creatorCountFilter}) AS creator_count
             FROM ai_leads l
             {$where}
             ORDER BY l.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        $pages = max(1, (int)ceil($total / $pageSize));
        return $this->success([
            'items' => array_map(fn(array $r) => $this->mapLead($r, true), $rows),
            'total' => $total,
            'page' => min($page, $pages),
            'pages' => $pages,
            'pageSize' => $pageSize,
            'statuses' => $this->statusList(),
            'types' => $this->typeList(),
        ]);
    }

    public function detail(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertAiLeadOwns($lead)) {
            return $err;
        }

        $accounts = DB::select(
            "SELECT la.id, la.lead_id, la.account_id, la.created_at,
                    a.username, a.nickname, a.login_status, a.enabled
             FROM ai_lead_accounts la
             LEFT JOIN accounts a ON a.id=la.account_id
             WHERE la.lead_id=?
             ORDER BY la.id ASC",
            [(int)$id]
        ) ?: [];

        $logs = DB::select(
            'SELECT * FROM ai_lead_logs WHERE lead_id=? ORDER BY id DESC LIMIT 200',
            [(int)$id]
        ) ?: [];

        $accountIds = array_values(array_unique(array_map(static fn($a) => (int)$a['account_id'], $accounts)));
        AiLeadExecutionService::ensureSchema();
        // 优先按 lead_id；兼容旧数据（lead_log_id / account_id）
        $records = DB::select(
            "SELECT r.*, a.username AS account_username
             FROM ai_lead_records r
             LEFT JOIN accounts a ON a.id=r.account_id
             WHERE r.lead_id=?
             ORDER BY r.id DESC
             LIMIT 500",
            [(int)$id]
        ) ?: [];
        if (!$records) {
            $logIds = array_map(static fn($r) => (int)$r['id'], $logs);
            if ($logIds) {
                $ph = implode(',', array_fill(0, count($logIds), '?'));
                $records = DB::select(
                    "SELECT r.*, a.username AS account_username
                     FROM ai_lead_records r
                     LEFT JOIN accounts a ON a.id=r.account_id
                     WHERE r.lead_log_id IN ({$ph})
                     ORDER BY r.id DESC
                     LIMIT 500",
                    $logIds
                ) ?: [];
            }
            if ($accountIds) {
                $ph = implode(',', array_fill(0, count($accountIds), '?'));
                $extra = DB::select(
                    "SELECT r.*, a.username AS account_username
                     FROM ai_lead_records r
                     LEFT JOIN accounts a ON a.id=r.account_id
                     WHERE r.account_id IN ({$ph}) AND r.lead_log_id=0 AND (r.lead_id=0 OR r.lead_id IS NULL)
                     ORDER BY r.id DESC
                     LIMIT 200",
                    $accountIds
                ) ?: [];
                $seen = [];
                foreach ($records as $r) {
                    $seen[(int)$r['id']] = true;
                }
                foreach ($extra as $r) {
                    if (!isset($seen[(int)$r['id']])) {
                        $records[] = $r;
                    }
                }
            }
        }

        $daily = [];
        if ($accountIds) {
            $ph = implode(',', array_fill(0, count($accountIds), '?'));
            $daily = DB::select(
                "SELECT d.*, a.username AS account_username
                 FROM ai_lead_account_daily d
                 LEFT JOIN accounts a ON a.id=d.account_id
                 WHERE d.account_id IN ({$ph})
                 ORDER BY d.date DESC, d.id DESC
                 LIMIT 100",
                $accountIds
            ) ?: [];
        }

        AiLeadExecutionService::ensureSchema();
        $creatorFilter = AiLeadExecutionService::scrapedVideoCreatorFilterSql('c');
        $creators = DB::select(
            "SELECT c.*, a.username AS account_username
             FROM ai_lead_creators c
             LEFT JOIN accounts a ON a.id=c.account_id
             WHERE c.lead_id=? AND {$creatorFilter}
             ORDER BY c.intent_score DESC, c.id DESC
             LIMIT 500",
            [(int)$id]
        ) ?: [];

        $videos = DB::select(
            "SELECT v.*, c.username AS creator_username, c.nickname AS creator_nickname
             FROM ai_lead_videos v
             LEFT JOIN ai_lead_creators c ON c.id=v.creator_id
             WHERE v.lead_id=?
             ORDER BY v.id DESC
             LIMIT 500",
            [(int)$id]
        ) ?: [];
        $comments = DB::select(
            "SELECT cm.*, v.video_url, v.video_name
             FROM ai_lead_comments cm
             LEFT JOIN ai_lead_videos v ON v.id=cm.video_id
             WHERE cm.lead_id=?
             ORDER BY cm.intent_score DESC, cm.id DESC
             LIMIT 500",
            [(int)$id]
        ) ?: [];

        $mapped = $this->mapLead($lead);
        AiLeadVideoWeightService::ensureSchema();
        $mapped['accounts'] = array_map(function (array $a) {
            $m = $this->mapAccount($a);
            try {
                $m['dmWeight'] = AiLeadVideoWeightService::accountWeightSummary((int)$m['accountId']);
            } catch (\Throwable $e) {
                $m['dmWeight'] = [];
            }
            return $m;
        }, $accounts);
        $mapped['dmProgress'] = AiLeadExecutionService::dmProgressStats((int)$id);
        $mapped['logs'] = array_map([$this, 'mapLog'], $logs);
        $mapped['records'] = array_map([$this, 'mapRecord'], $records);
        $mapped['daily'] = array_map([$this, 'mapDaily'], $daily);
        $mapped['creators'] = array_map([$this, 'mapCreator'], $creators);
        $mapped['videos'] = array_map([$this, 'mapVideo'], $videos);
        $mapped['comments'] = array_map([$this, 'mapComment'], $comments);
        $mapped['sharedUserIds'] = array_map(
            static fn($r) => (int)$r['user_id'],
            DB::select('SELECT user_id FROM ai_lead_shared_users WHERE lead_id=? ORDER BY id ASC', [(int)$id]) ?: []
        );
        $mapped['creatorCount'] = count($creators);
        $mapped['videoCount'] = count($videos);
        $mapped['commentCount'] = count($comments);
        $mapped['leadCommentCount'] = count(array_filter($comments, static fn($c) => !empty($c['ai_pass'])));
        return $this->success($mapped);
    }

    public function create(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        try {
            $payload = $this->parseBody($request);
            $row = [
                'type' => $payload['type'],
                'limit_follows' => $payload['limit_follows'],
                'limit_likes' => $payload['limit_likes'],
                'limit_look' => $payload['limit_look'],
                'keywords' => $payload['keywords'],
                'dm_message' => $payload['dm_message'],
                'status' => $payload['status'],
                'scrape_account_id' => $payload['scrape_account_id'],
                'industry_id' => $payload['industry_id'],
                'browser_backend' => $payload['browser_backend'],
            ];
            $this->stampOwner($row);
            $id = (int)DB::table('ai_leads')->insert($row);
            $this->syncAccounts($id, $payload['account_ids'], $payload['scrape_account_id']);
            AiLeadExecutionService::syncLeadSharedUsers($id, $payload['shared_user_ids']);
            $schedule = null;
            if ($payload['status'] === 'active') {
                $schedule = AiLeadExecutionService::scheduleIfActive($id);
            }
            $fresh = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [$id]);
            $mapped = $this->mapLead($fresh);
            if (is_array($schedule)) {
                $mapped['schedule'] = $schedule;
                $mapped['needPoll'] = empty($schedule['skipped']) || (($schedule['reason'] ?? '') === 'already_queued');
            }
            return $this->success($mapped, '拓客任务已创建');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->error('创建失败：' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertAiLeadOwns($old)) {
            return $err;
        }
        try {
            $payload = $this->parseBody($request);
            DB::query(
                'UPDATE ai_leads SET type=?, limit_follows=?, limit_likes=?, limit_look=?, keywords=?, dm_message=?, status=?, scrape_account_id=?, industry_id=?, browser_backend=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [
                    $payload['type'],
                    $payload['limit_follows'],
                    $payload['limit_likes'],
                    $payload['limit_look'],
                    $payload['keywords'],
                    $payload['dm_message'],
                    $payload['status'],
                    $payload['scrape_account_id'],
                    $payload['industry_id'],
                    $payload['browser_backend'],
                    (int)$id,
                ]
            );
            $this->syncAccounts((int)$id, $payload['account_ids'], $payload['scrape_account_id']);
            AiLeadExecutionService::syncLeadSharedUsers((int)$id, $payload['shared_user_ids']);
            $schedule = null;
            if ($payload['status'] === 'active') {
                $schedule = AiLeadExecutionService::scheduleIfActive((int)$id);
            }
            $fresh = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
            $mapped = $this->mapLead($fresh);
            if (is_array($schedule)) {
                $mapped['schedule'] = $schedule;
                $mapped['needPoll'] = empty($schedule['skipped']) || (($schedule['reason'] ?? '') === 'already_queued');
            }
            return $this->success($mapped, '拓客任务已更新');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->error('更新失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 独立跑私信逻辑（不抓取）：入队 ai_lead_dm。
     */
    public function testDm(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertAiLeadOwns($old)) {
            return $err;
        }
        $username = trim((string)$request->input('username', ''));
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $limit = (int)$request->input('limit', 0);
        // 点某条记录重试时默认只测 1 人；工具栏全量测试默认最多 3 条
        if ($limit < 1) {
            $limit = $username !== '' ? 1 : 3;
        }
        try {
            $force = filter_var($request->input('force', $request->input('forceRetry', false)), FILTER_VALIDATE_BOOLEAN);
            $schedule = AiLeadExecutionService::scheduleDmTest((int)$id, [
                'username' => $username,
                'accountId' => $accountId,
                'limit' => $limit,
                'force' => $force || $username !== '',
            ]);
        } catch (\Throwable $e) {
            return $this->error('私信测试入队失败：' . $e->getMessage(), 500);
        }
        if (!empty($schedule['skipped'])) {
            $reason = (string)($schedule['reason'] ?? '');
            $tips = [
                'not_found' => '拓客任务不存在',
                'already_queued' => '已有私信测试在队列中，请稍候',
                'no_dm_account' => '请先关联可用私信账号（需绑定环境与代理）',
            ];
            $msg = $tips[$reason] ?? ('无法入队：' . ($reason !== '' ? $reason : 'unknown'));
            if ($reason === 'already_queued') {
                return $this->success([
                    'schedule' => $schedule,
                    'needPoll' => true,
                ], $msg);
            }
            return $this->error($msg, 422);
        }
        return $this->success([
            'schedule' => $schedule,
            'needPoll' => true,
            'accountId' => (int)($schedule['account_id'] ?? 0),
            'batchId' => (int)($schedule['batch_id'] ?? 0),
        ], '私信测试已入队，将打开对方主页并尝试发信');
    }

    public function patchStatus(Request $request, string $id): Response
    {
        $this->ensureSchema();
        AccountWorkBatchService::ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertAiLeadOwns($old)) {
            return $err;
        }
        $status = trim((string)$request->input('status', ''));
        if (!isset(self::STATUSES[$status])) {
            return $this->error('无效状态', 422);
        }
        $clearData = $request->input('clearData', $request->input('clear_data', false));
        $clearData = $clearData === true || $clearData === 1 || $clearData === '1' || $clearData === 'true';
        $forceRestart = $request->input('forceRestart', $request->input('force_restart', false));
        $forceRestart = $forceRestart === true || $forceRestart === 1 || $forceRestart === '1' || $forceRestart === 'true';
        $cleared = false;
        if ($status === 'active' && $clearData) {
            $this->clearLeadRelatedData((int)$id);
            $cleared = true;
        }
        // 已是 active 再点启用/恢复：允许重新入队或定点推送
        DB::query('UPDATE ai_leads SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$status, (int)$id]);
        $schedule = null;
        if ($status === 'active') {
            try {
                if ($forceRestart) {
                    // 重新推送：终止旧进程/环境后重新入队
                    $schedule = AiLeadExecutionService::forceReschedule((int)$id);
                } else {
                    // 若上次入队失败留下假 running，先清掉便于重入队
                    if ((string)($old['status'] ?? '') === 'active'
                        && in_array((string)($old['run_status'] ?? ''), ['running', 'queued', 'failed'], true)
                    ) {
                        $stillQueued = DB::selectOne(
                            "SELECT id FROM account_work_items
                             WHERE task_type='ai_lead' AND task_ref_id=? AND status IN ('pending','running')
                             LIMIT 1",
                            [(int)$id]
                        );
                        if (!$stillQueued && (string)($old['run_status'] ?? '') !== 'queued') {
                            DB::query(
                                "UPDATE ai_leads SET run_status='idle', updated_at=CURRENT_TIMESTAMP WHERE id=?",
                                [(int)$id]
                            );
                        }
                    }
                    $schedule = AiLeadExecutionService::scheduleIfActive((int)$id);
                }
            } catch (\Throwable $e) {
                $schedule = [
                    'skipped' => true,
                    'reason' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        } elseif (in_array($status, ['paused', 'draft'], true)) {
            // 先写 paused，再 abort：Node 进度上报立刻收到 aborted，停止翻页/私信
            DB::query(
                "UPDATE ai_leads SET status=?, run_status='idle', updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$status, (int)$id]
            );
            $mappedAbort = null;
            try {
                $mappedAbort = AiLeadExecutionService::abortRunning(
                    (int)$id,
                    $status === 'paused' ? '任务已暂停' : '任务已停用'
                );
            } catch (\Throwable $e) {
                $mappedAbort = null;
            }
            if (is_array($mappedAbort)) {
                $schedule = [
                    'skipped' => true,
                    'reason' => 'aborted',
                    'close_profiles' => $mappedAbort['close_profiles'] ?? [],
                    'aborted_batches' => $mappedAbort['aborted_batches'] ?? [],
                ];
            }
        }
        $fresh = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        $mapped = $this->mapLead($fresh);
        if (is_array($schedule)) {
            $reason = (string)($schedule['reason'] ?? '');
            $mapped['schedule'] = $schedule;
            // 有新 batch / 旧队列待推 才领取；aborted 只关窗
            $mapped['needPoll'] = $reason !== 'aborted' && (
                empty($schedule['skipped'])
                || $reason === 'already_queued'
                || (!empty($schedule['force']) && (int)($schedule['batch_id'] ?? 0) > 0)
            );
            $mapped['scheduleMessage'] = '';
            $mapped['closeProfiles'] = array_values(array_filter($schedule['close_profiles'] ?? []));
            $mapped['abortedBatches'] = array_values(array_filter($schedule['aborted_batches'] ?? []));
        }
        $msg = '状态已更新';
        if (is_array($schedule) && !empty($schedule['skipped'])) {
            $reason = (string)($schedule['reason'] ?? '');
            if ($reason === 'no_eligible_account') {
                $msg = '已启用，但无可用账号（需绑定比特环境 + 代理，并指定查数据账号）';
            } elseif ($reason === 'already_queued') {
                $msg = '已启用，任务已在队列中（batch#' . (int)($schedule['batch_id'] ?? 0) . '），将尝试推送';
            } elseif ($reason === 'aborted') {
                $msg = $status === 'paused' ? '已暂停并终止运行中的环境' : '已终止运行中的环境';
            } elseif ($reason === 'error') {
                $msg = '已启用，但入队失败：' . (string)($schedule['error'] ?? 'unknown');
            }
        } elseif (is_array($schedule) && !empty($schedule['batch_id'])) {
            $abortedN = count($schedule['aborted_batches'] ?? []);
            if (!empty($schedule['force']) && $abortedN > 0) {
                $msg = '已终止旧任务并重新入队 batch#' . (int)$schedule['batch_id'];
            } elseif (!empty($schedule['force'])) {
                $msg = '已释放环境并重新入队 batch#' . (int)$schedule['batch_id'];
            } else {
                $msg = '已启用并入队 AI拓客独立任务 batch#' . (int)$schedule['batch_id'];
            }
        }
        if ($cleared) {
            $msg = ($msg === '状态已更新' ? '已清除关联数据并重新推送' : ('已清除关联数据；' . $msg));
        }
        $mapped['clearedData'] = $cleared;
        if (is_array($schedule)) {
            $mapped['scheduleMessage'] = $msg;
        }
        return $this->success($mapped, $msg);
    }

    /** 清除拓客关联明细（保留任务本身与关联账号；不回滚账号日私信次数） */
    private function clearLeadRelatedData(int $leadId): void
    {
        AiLeadExecutionService::ensureSchema();
        DB::query('DELETE FROM ai_lead_records WHERE lead_id=?', [$leadId]);
        $logIds = array_column(DB::select('SELECT id FROM ai_lead_logs WHERE lead_id=?', [$leadId]) ?: [], 'id');
        if ($logIds) {
            $ph = implode(',', array_fill(0, count($logIds), '?'));
            DB::query("DELETE FROM ai_lead_records WHERE lead_log_id IN ({$ph})", $logIds);
        }
        DB::query('DELETE FROM ai_lead_comments WHERE lead_id=?', [$leadId]);
        DB::query('DELETE FROM ai_lead_videos WHERE lead_id=?', [$leadId]);
        DB::query('DELETE FROM ai_lead_creators WHERE lead_id=?', [$leadId]);
        DB::query('DELETE FROM ai_lead_logs WHERE lead_id=?', [$leadId]);
        DB::query(
            "UPDATE ai_leads SET run_status='idle', stats_json=NULL, progress_json=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$leadId]
        );
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertAiLeadOwns($old)) {
            return $err;
        }
        $leadId = (int)$id;
        AiLeadExecutionService::ensureSchema();
        DB::query('DELETE FROM ai_lead_records WHERE lead_id=?', [$leadId]);
        $logIds = array_column(DB::select('SELECT id FROM ai_lead_logs WHERE lead_id=?', [$leadId]) ?: [], 'id');
        if ($logIds) {
            $ph = implode(',', array_fill(0, count($logIds), '?'));
            DB::query("DELETE FROM ai_lead_records WHERE lead_log_id IN ({$ph})", $logIds);
        }
        DB::query('DELETE FROM ai_lead_comments WHERE lead_id=?', [$leadId]);
        DB::query('DELETE FROM ai_lead_videos WHERE lead_id=?', [$leadId]);
        DB::query('DELETE FROM ai_lead_creators WHERE lead_id=?', [$leadId]);
        DB::query('DELETE FROM ai_lead_logs WHERE lead_id=?', [$leadId]);
        DB::query('DELETE FROM ai_lead_accounts WHERE lead_id=?', [$leadId]);
        DB::query('DELETE FROM ai_leads WHERE id=?', [$leadId]);
        return $this->success(['id' => $leadId], '已删除');
    }

    /** 明细页：追加视频日志 */
    public function createLog(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertAiLeadOwns($lead)) {
            return $err;
        }
        $videoUrl = trim((string)$request->input('videoUrl', $request->input('video_url', '')));
        $videoCover = trim((string)$request->input('videoCover', $request->input('video_cover', '')));
        $videoName = trim((string)$request->input('videoName', $request->input('video_name', '')));
        $comment = trim((string)$request->input('comment', ''));
        if ($videoUrl === '' && $videoName === '') {
            return $this->error('请填写视频地址或名称', 422);
        }
        $logId = (int)DB::table('ai_lead_logs')->insert([
            'lead_id' => (int)$id,
            'video_url' => mb_substr($videoUrl, 0, 512),
            'video_cover' => mb_substr($videoCover, 0, 512),
            'video_name' => mb_substr($videoName, 0, 255),
            'comment' => $comment,
        ]);
        $row = DB::selectOne('SELECT * FROM ai_lead_logs WHERE id=?', [$logId]);
        return $this->success($this->mapLog($row), '已添加视频记录');
    }

    /** 明细页：追加私信记录 */
    public function createRecord(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertAiLeadOwns($lead)) {
            return $err;
        }
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $leadLogId = (int)$request->input('leadLogId', $request->input('lead_log_id', 0));
        $uuid = trim((string)$request->input('uuid', ''));
        $username = trim((string)$request->input('username', ''));
        $message = trim((string)$request->input('message', ''));
        $status = trim((string)$request->input('status', 'pending'));
        if ($accountId <= 0) {
            return $this->error('请选择账号', 422);
        }
        $bound = DB::selectOne(
            'SELECT id FROM ai_lead_accounts WHERE lead_id=? AND account_id=?',
            [(int)$id, $accountId]
        );
        if (!$bound) {
            return $this->error('该账号未关联到此拓客任务', 422);
        }
        if ($leadLogId > 0) {
            $log = DB::selectOne('SELECT id FROM ai_lead_logs WHERE id=? AND lead_id=?', [$leadLogId, (int)$id]);
            if (!$log) {
                return $this->error('视频日志不存在', 422);
            }
        }
        if (!isset(self::RECORD_STATUSES[$status])) {
            $status = 'pending';
        }
        AiLeadExecutionService::ensureSchema();
        if ($status === 'sent') {
            $out = AiLeadExecutionService::recordDm((int)$id, [
                'accountId' => $accountId,
                'leadLogId' => $leadLogId,
                'uuid' => $uuid,
                'username' => $username,
                'message' => $message,
                'status' => 'sent',
            ]);
            $row = DB::selectOne(
                'SELECT r.*, a.username AS account_username FROM ai_lead_records r LEFT JOIN accounts a ON a.id=r.account_id WHERE r.id=?',
                [(int)$out['id']]
            );
            return $this->success($this->mapRecord($row), '已添加私信记录');
        }
        $rid = (int)DB::table('ai_lead_records')->insert([
            'lead_id' => (int)$id,
            'account_id' => $accountId,
            'lead_log_id' => $leadLogId,
            'uuid' => mb_substr($uuid, 0, 64),
            'username' => mb_substr($username, 0, 128),
            'message' => $message,
            'status' => $status,
        ]);
        $row = DB::selectOne(
            'SELECT r.*, a.username AS account_username FROM ai_lead_records r LEFT JOIN accounts a ON a.id=r.account_id WHERE r.id=?',
            [$rid]
        );
        return $this->success($this->mapRecord($row), '已添加私信记录');
    }

    /** 明细页：追加 / 更新 TikTok 用户 */
    public function createCreator(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$id]);
        if ($err = $this->assertAiLeadOwns($lead)) {
            return $err;
        }
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        $leadLogId = (int)$request->input('leadLogId', $request->input('lead_log_id', 0));
        $uuid = trim((string)$request->input('uuid', ''));
        $username = trim((string)$request->input('username', ''));
        $nickname = trim((string)$request->input('nickname', ''));
        $avatar = trim((string)$request->input('avatar', ''));
        $profileUrl = trim((string)$request->input('profileUrl', $request->input('profile_url', '')));
        $signature = trim((string)$request->input('signature', ''));
        $followers = max(0, (int)$request->input('followers', 0));
        $following = max(0, (int)$request->input('following', 0));
        $likes = max(0, (int)$request->input('likes', 0));
        $region = trim((string)$request->input('region', ''));
        $status = trim((string)$request->input('status', 'new'));
        if ($uuid === '' && $username === '') {
            return $this->error('请填写用户 UUID 或用户名', 422);
        }
        if ($uuid === '') {
            $uuid = 'u_' . md5(strtolower($username));
        }
        if ($accountId > 0) {
            $bound = DB::selectOne(
                'SELECT id FROM ai_lead_accounts WHERE lead_id=? AND account_id=?',
                [(int)$id, $accountId]
            );
            if (!$bound) {
                return $this->error('该账号未关联到此拓客任务', 422);
            }
        }
        if ($leadLogId > 0) {
            $log = DB::selectOne('SELECT id FROM ai_lead_logs WHERE id=? AND lead_id=?', [$leadLogId, (int)$id]);
            if (!$log) {
                return $this->error('视频日志不存在', 422);
            }
        }
        if (!isset(self::CREATOR_STATUSES[$status])) {
            $status = 'new';
        }
        $exist = DB::selectOne(
            'SELECT id FROM ai_lead_creators WHERE lead_id=? AND uuid=?',
            [(int)$id, mb_substr($uuid, 0, 64)]
        );
        $rowData = [
            'lead_id' => (int)$id,
            'account_id' => $accountId,
            'lead_log_id' => $leadLogId,
            'uuid' => mb_substr($uuid, 0, 64),
            'username' => mb_substr($username, 0, 128),
            'nickname' => mb_substr($nickname, 0, 255),
            'avatar' => mb_substr($avatar, 0, 512),
            'profile_url' => mb_substr($profileUrl, 0, 512),
            'signature' => $signature,
            'followers' => $followers,
            'following' => $following,
            'likes' => $likes,
            'region' => mb_substr($region, 0, 64),
            'status' => $status,
        ];
        if ($exist) {
            DB::query(
                'UPDATE ai_lead_creators SET account_id=?, lead_log_id=?, username=?, nickname=?, avatar=?, profile_url=?, signature=?, followers=?, following=?, likes=?, region=?, status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [
                    $rowData['account_id'], $rowData['lead_log_id'], $rowData['username'], $rowData['nickname'],
                    $rowData['avatar'], $rowData['profile_url'], $rowData['signature'], $rowData['followers'], $rowData['following'],
                    $rowData['likes'], $rowData['region'], $rowData['status'], (int)$exist['id'],
                ]
            );
            $cid = (int)$exist['id'];
        } else {
            $cid = (int)DB::table('ai_lead_creators')->insert($rowData);
        }
        $row = DB::selectOne(
            'SELECT c.*, a.username AS account_username FROM ai_lead_creators c LEFT JOIN accounts a ON a.id=c.account_id WHERE c.id=?',
            [$cid]
        );
        return $this->success($this->mapCreator($row), $exist ? '已更新 TK 用户' : '已添加 TK 用户');
    }

    private function parseBody(Request $request): array
    {
        $type = strtolower(trim((string)$request->input('type', 'user')));
        // 兼容旧值
        if ($type === 'search' || $type === 'comment') {
            $type = 'user';
        }
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('拓客类型仅支持：按用户 / 按视频');
        }
        $status = trim((string)$request->input('status', 'draft'));
        if (!isset(self::STATUSES[$status])) {
            $status = 'draft';
        }
        $keywordsRaw = trim((string)$request->input('keywords', ''));
        // 仅保留一个关键词（兼容旧数据多行/逗号）
        $keywordsParts = preg_split('/[\r\n,，;；]+/u', $keywordsRaw) ?: [];
        $keywords = trim((string)($keywordsParts[0] ?? ''));
        if ($keywords === '') {
            throw new \InvalidArgumentException('请填写一个关键词');
        }
        $accountIds = $request->input('accountIds', $request->input('account_ids', []));
        if (!is_array($accountIds)) {
            $accountIds = [];
        }
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        $scrapeAccountId = (int)$request->input('scrapeAccountId', $request->input('scrape_account_id', 0));
        if ($scrapeAccountId < 1) {
            throw new \InvalidArgumentException('请选择查数据账号');
        }
        if ($accountIds && !in_array($scrapeAccountId, $accountIds, true)) {
            $accountIds[] = $scrapeAccountId;
        }
        if (!$accountIds) {
            $accountIds = [$scrapeAccountId];
        }
        $browserBackend = strtolower(trim((string)$request->input('browserBackend', $request->input('browser_backend', 'bit'))));
        if (!in_array($browserBackend, ['bit', 'tkswarm', 'linux'], true)) {
            $browserBackend = 'bit';
        }
        $acc = DB::selectOne(
            'SELECT id, browser_profile_id, browser_backend, tkswarm_browser_profile_id, linux_browser_profile_id, proxy_id, enabled, owner_id FROM accounts WHERE id=?',
            [$scrapeAccountId]
        );
        if (!$acc || !DataScope::accountOwnsRow($acc)) {
            throw new \InvalidArgumentException('查数据账号不存在或无权使用');
        }
        foreach ($accountIds as $aid) {
            $row = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$aid]);
            if (!$row || !DataScope::accountOwnsRow($row)) {
                throw new \InvalidArgumentException("关联账号 #{$aid} 不存在或无权使用");
            }
        }
        if (array_key_exists('enabled', $acc) && $acc['enabled'] !== null && (int)$acc['enabled'] === 0) {
            throw new \InvalidArgumentException('查数据账号已禁用');
        }
        // Linux 服务器浏览器：环境由任务自动创建，这里没有绑定 ID 也算正常
        $profile = $browserBackend === 'tkswarm'
            ? trim((string)($acc['tkswarm_browser_profile_id'] ?? ''))
            : ($browserBackend === 'linux'
                ? trim((string)($acc['linux_browser_profile_id'] ?? ''))
                : trim((string)($acc['browser_profile_id'] ?? '')));
        if ($profile === '' && $browserBackend !== 'linux') {
            $env = DB::selectOne(
                "SELECT browser_profile_id FROM account_browser_envs
                 WHERE account_id=? AND browser_backend=?
                 ORDER BY is_primary DESC, id ASC LIMIT 1",
                [$scrapeAccountId, $browserBackend]
            );
            $profile = trim((string)($env['browser_profile_id'] ?? ''));
        }
        if ($profile === '' && $browserBackend === 'bit') {
            $profile = trim((string)($acc['browser_profile_id'] ?? ''));
        }
        if ($profile === '' && $browserBackend !== 'linux') {
            throw new \InvalidArgumentException(
                $browserBackend === 'tkswarm'
                    ? '查数据账号未绑定第一页自研浏览器环境'
                    : '查数据账号未绑定比特浏览器环境'
            );
        }
        if ((int)($acc['proxy_id'] ?? 0) < 1) {
            throw new \InvalidArgumentException('查数据账号未绑定代理');
        }
        $industryId = (int)$request->input('industryId', $request->input('industry_id', 0));
        if ($industryId > 0) {
            $ind = DB::selectOne('SELECT id, level FROM industry_data WHERE id=? AND enabled=1', [$industryId]);
            if (!$ind) {
                throw new \InvalidArgumentException('所选行业不存在或已停用');
            }
        } else {
            throw new \InvalidArgumentException('请选择行业分类');
        }
        $sharedUserIds = $request->input('sharedUserIds', $request->input('shared_user_ids', []));
        if (!is_array($sharedUserIds)) {
            $sharedUserIds = [];
        }
        $sharedUserIds = array_values(array_unique(array_filter(array_map('intval', $sharedUserIds))));
        return [
            'type' => $type,
            'limit_follows' => max(0, (int)$request->input('limitFollows', $request->input('limit_follows', 0))),
            'limit_likes' => max(0, (int)$request->input('limitLikes', $request->input('limit_likes', 0))),
            'limit_look' => max(0, (int)$request->input('limitLook', $request->input('limit_look', 0))),
            'keywords' => mb_substr($keywords, 0, 200),
            'dm_message' => mb_substr(trim((string)$request->input('dmMessage', $request->input('dm_message', ''))), 0, 2000),
            'status' => $status,
            'account_ids' => $accountIds,
            'scrape_account_id' => $scrapeAccountId,
            'industry_id' => $industryId,
            'shared_user_ids' => $sharedUserIds,
            'browser_backend' => $browserBackend,
        ];
    }

    private function syncAccounts(int $leadId, array $accountIds, int $scrapeAccountId = 0): void
    {
        if ($scrapeAccountId > 0 && !in_array($scrapeAccountId, $accountIds, true)) {
            $accountIds[] = $scrapeAccountId;
        }
        DB::query('DELETE FROM ai_lead_accounts WHERE lead_id=?', [$leadId]);
        foreach ($accountIds as $aid) {
            if ($aid <= 0) {
                continue;
            }
            DB::query(
                'INSERT IGNORE INTO ai_lead_accounts (lead_id, account_id) VALUES (?,?)',
                [$leadId, $aid]
            );
        }
    }

    private function mapLead(?array $row, bool $withDmProgress = false): array
    {
        if (!$row) {
            return [];
        }
        $stats = json_decode((string)($row['stats_json'] ?? ''), true) ?: [];
        $progress = json_decode((string)($row['progress_json'] ?? ''), true) ?: [];
        $leadId = (int)$row['id'];
        $status = (string)($row['status'] ?? 'draft');
        if ($withDmProgress && in_array($status, ['active', 'completed'], true)) {
            try {
                $prog = AiLeadExecutionService::dmProgressStats($leadId);
                $stats['dmsPending'] = $prog['dmsPending'];
                $stats['dmsSent'] = $prog['dmsSent'];
                $stats['dmsNeeded'] = max((int)($stats['dmsNeeded'] ?? 0), $prog['dmsNeeded']);
            } catch (\Throwable $e) {
            }
        }
        return [
            'id' => (int)$row['id'],
            'ownerId' => (int)($row['owner_id'] ?? 0),
            'type' => (string)($row['type'] ?? ''),
            'typeLabel' => self::TYPES[(string)($row['type'] ?? '')] ?? (string)($row['type'] ?? ''),
            'limitFollows' => (int)($row['limit_follows'] ?? 0),
            'limitLikes' => (int)($row['limit_likes'] ?? 0),
            'limitLook' => (int)($row['limit_look'] ?? 0),
            'keywords' => (string)($row['keywords'] ?? ''),
            'dmMessage' => (string)($row['dm_message'] ?? ''),
            'browserBackend' => in_array(strtolower(trim((string)($row['browser_backend'] ?? 'bit'))), ['bit', 'tkswarm', 'linux'], true)
                ? strtolower(trim((string)($row['browser_backend'] ?? 'bit')))
                : 'bit',
            'dailyDmLimit' => null,
            'status' => $status,
            'statusLabel' => self::STATUSES[$status] ?? $status,
            'runStatus' => (string)($row['run_status'] ?? 'idle'),
            'lastRunAt' => (string)($row['last_run_at'] ?? ''),
            'scrapeAccountId' => (int)($row['scrape_account_id'] ?? 0),
            'stats' => $stats,
            'progress' => $progress,
            'dmProgress' => $withDmProgress ? [
                'dmsPending' => (int)($stats['dmsPending'] ?? 0),
                'dmsSent' => (int)($stats['dmsSent'] ?? 0),
                'dmsNeeded' => (int)($stats['dmsNeeded'] ?? 0),
            ] : [],
            'accountCount' => (int)($row['account_count'] ?? 0),
            'logCount' => (int)($row['log_count'] ?? 0),
            'recordCount' => (int)($row['record_count'] ?? 0),
            'leadCommentCount' => (int)($row['lead_comment_count'] ?? 0),
            'creatorCount' => (int)($row['creator_count'] ?? 0),
            'industryId' => (int)($row['industry_id'] ?? 0),
            'industry' => IndustryDataService::resolveMeta((int)($row['industry_id'] ?? 0)),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function mapAccount(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'leadId' => (int)$row['lead_id'],
            'accountId' => (int)$row['account_id'],
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
            'loginStatus' => (string)($row['login_status'] ?? ''),
            'enabled' => (int)($row['enabled'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function mapLog(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'leadId' => (int)$row['lead_id'],
            'videoUrl' => (string)($row['video_url'] ?? ''),
            'videoCover' => (string)($row['video_cover'] ?? ''),
            'videoName' => (string)($row['video_name'] ?? ''),
            'comment' => (string)($row['comment'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function mapRecord(array $row): array
    {
        $st = (string)($row['status'] ?? 'pending');
        return [
            'id' => (int)$row['id'],
            'leadId' => (int)($row['lead_id'] ?? 0),
            'accountId' => (int)$row['account_id'],
            'accountUsername' => (string)($row['account_username'] ?? ''),
            'leadLogId' => (int)($row['lead_log_id'] ?? 0),
            'uuid' => (string)($row['uuid'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'errorMessage' => (string)($row['error_message'] ?? ''),
            'status' => $st,
            'statusLabel' => self::RECORD_STATUSES[$st] ?? $st,
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function mapDaily(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'accountId' => (int)$row['account_id'],
            'accountUsername' => (string)($row['account_username'] ?? ''),
            'times' => (int)($row['times'] ?? 0),
            'dailyLimit' => (int)($row['daily_limit'] ?? 0),
            'date' => (string)($row['date'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function mapCreator(array $row): array
    {
        $st = (string)($row['status'] ?? 'new');
        return [
            'id' => (int)$row['id'],
            'leadId' => (int)$row['lead_id'],
            'accountId' => (int)($row['account_id'] ?? 0),
            'accountUsername' => (string)($row['account_username'] ?? ''),
            'leadLogId' => (int)($row['lead_log_id'] ?? 0),
            'uuid' => (string)($row['uuid'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
            'avatar' => (string)($row['avatar'] ?? ''),
            'profileUrl' => (string)($row['profile_url'] ?? ''),
            'signature' => (string)($row['signature'] ?? ''),
            'followers' => (int)($row['followers'] ?? 0),
            'following' => (int)($row['following'] ?? 0),
            'likes' => (int)($row['likes'] ?? 0),
            'region' => (string)($row['region'] ?? ''),
            'status' => $st,
            'statusLabel' => self::CREATOR_STATUSES[$st] ?? $st,
            'intentScore' => max(0, min(10, (int)($row['intent_score'] ?? 0))),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function statusList(): array
    {
        $out = [];
        foreach (self::STATUSES as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }
        return $out;
    }

    private function mapVideo(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'leadId' => (int)$row['lead_id'],
            'creatorId' => (int)($row['creator_id'] ?? 0),
            'creatorUsername' => (string)($row['creator_username'] ?? ''),
            'creatorNickname' => (string)($row['creator_nickname'] ?? ''),
            'accountId' => (int)($row['account_id'] ?? 0),
            'videoUrl' => (string)($row['video_url'] ?? ''),
            'videoCover' => (string)($row['video_cover'] ?? ''),
            'videoName' => (string)($row['video_name'] ?? ''),
            'likes' => (int)($row['likes'] ?? 0),
            'views' => (int)($row['views'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function mapComment(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'leadId' => (int)$row['lead_id'],
            'videoId' => (int)($row['video_id'] ?? 0),
            'videoUrl' => (string)($row['video_url'] ?? ''),
            'videoName' => (string)($row['video_name'] ?? ''),
            'uuid' => (string)($row['uuid'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
            'profileUrl' => (string)($row['profile_url'] ?? ''),
            'commentText' => (string)($row['comment_text'] ?? ''),
            'commentTextZh' => (string)($row['comment_text_zh'] ?? ''),
            'aiPass' => !empty($row['ai_pass']),
            'aiReason' => (string)($row['ai_reason'] ?? ''),
            'intentScore' => max(0, min(10, (int)($row['intent_score'] ?? 0))),
            'industryId' => (int)($row['industry_id'] ?? 0),
            'industry' => IndustryDataService::resolveMeta((int)($row['industry_id'] ?? 0)),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function typeList(): array
    {
        $out = [];
        foreach (self::TYPES as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }
        return $out;
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `ai_leads` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `owner_id` INT NOT NULL DEFAULT 0,
              `type` VARCHAR(64) NOT NULL DEFAULT '',
              `limit_follows` INT NOT NULL DEFAULT 0,
              `limit_likes` INT NOT NULL DEFAULT 0,
              `limit_look` INT NOT NULL DEFAULT 0,
              `keywords` TEXT NULL,
              `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
              `scrape_account_id` INT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_al_owner` (`owner_id`),
              KEY `idx_al_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try {
                $leadCols = array_column(DB::select('SHOW COLUMNS FROM ai_leads') ?: [], 'Field');
                if (!in_array('scrape_account_id', $leadCols, true)) {
                    DB::query('ALTER TABLE ai_leads ADD COLUMN scrape_account_id INT NOT NULL DEFAULT 0 AFTER status');
                }
                if (!in_array('industry_id', $leadCols, true)) {
                    DB::query('ALTER TABLE ai_leads ADD COLUMN industry_id INT NOT NULL DEFAULT 0 AFTER scrape_account_id');
                    DB::query('ALTER TABLE ai_leads ADD KEY idx_al_industry (industry_id)');
                }
                if (!in_array('browser_backend', $leadCols, true)) {
                    DB::query("ALTER TABLE ai_leads ADD COLUMN browser_backend VARCHAR(16) NOT NULL DEFAULT 'bit' AFTER industry_id");
                }
            } catch (\Throwable $e) {
            }
            try {
                DB::query(
                    'UPDATE ai_leads l
                     INNER JOIN accounts a ON a.id=l.scrape_account_id
                     SET l.owner_id=a.owner_id
                     WHERE l.owner_id=0 AND a.owner_id>0'
                );
            } catch (\Throwable $e) {
            }
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_accounts` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `lead_id` INT NOT NULL,
              `account_id` INT NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_ala_lead_account` (`lead_id`, `account_id`),
              KEY `idx_ala_account` (`account_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_logs` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `lead_id` INT NOT NULL,
              `video_url` VARCHAR(512) NOT NULL DEFAULT '',
              `video_cover` VARCHAR(512) NOT NULL DEFAULT '',
              `video_name` VARCHAR(255) NOT NULL DEFAULT '',
              `comment` TEXT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_all_lead` (`lead_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_records` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `account_id` INT NOT NULL,
              `lead_log_id` INT NOT NULL DEFAULT 0,
              `uuid` VARCHAR(64) NOT NULL DEFAULT '',
              `username` VARCHAR(128) NOT NULL DEFAULT '',
              `message` TEXT NULL,
              `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_alr_account` (`account_id`),
              KEY `idx_alr_log` (`lead_log_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_account_daily` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `account_id` INT NOT NULL,
              `times` INT NOT NULL DEFAULT 0,
              `date` DATE NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_alad_account_date` (`account_id`, `date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            DB::query("CREATE TABLE IF NOT EXISTS `ai_lead_creators` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `lead_id` INT NOT NULL,
              `account_id` INT NOT NULL DEFAULT 0,
              `lead_log_id` INT NOT NULL DEFAULT 0,
              `uuid` VARCHAR(64) NOT NULL DEFAULT '',
              `username` VARCHAR(128) NOT NULL DEFAULT '',
              `nickname` VARCHAR(255) NOT NULL DEFAULT '',
              `avatar` VARCHAR(512) NOT NULL DEFAULT '',
              `signature` TEXT NULL,
              `followers` INT NOT NULL DEFAULT 0,
              `following` INT NOT NULL DEFAULT 0,
              `likes` INT NOT NULL DEFAULT 0,
              `region` VARCHAR(64) NOT NULL DEFAULT '',
              `status` VARCHAR(32) NOT NULL DEFAULT 'new',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_alc_lead` (`lead_id`),
              KEY `idx_alc_account` (`account_id`),
              UNIQUE KEY `uk_alc_lead_uuid` (`lead_id`, `uuid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            // ignore
        }
        AiLeadExecutionService::ensureSchema();
    }

    /** 视频权重阶梯（系统/租户默认） */
    public function videoWeightTiers(Request $request): Response
    {
        AiLeadVideoWeightService::ensureSchema();
        $ownerId = max(0, (int)$request->get('ownerId', $request->get('owner_id', 0)));
        if ($ownerId < 1) {
            $ownerId = $this->writeOwnerId();
        }
        return $this->success([
            'tiers' => AiLeadVideoWeightService::listTiers($ownerId),
            'defaults' => AiLeadVideoWeightService::DEFAULT_TIERS,
            'defaultIntervalMinSec' => AiLeadVideoWeightService::DEFAULT_INTERVAL_MIN_SEC,
            'defaultIntervalMaxSec' => AiLeadVideoWeightService::DEFAULT_INTERVAL_MAX_SEC,
        ]);
    }

    /** 保存租户视频权重阶梯 */
    public function saveVideoWeightTiers(Request $request): Response
    {
        AiLeadVideoWeightService::ensureSchema();
        $ownerId = $this->writeOwnerId();
        if ($ownerId < 1) {
            return $this->error('未登录', 401);
        }
        $tiers = $request->input('tiers', []);
        if (!is_array($tiers)) {
            $tiers = [];
        }
        try {
            $saved = AiLeadVideoWeightService::saveTiers($ownerId, $tiers);
            return $this->success(['tiers' => $saved]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** 账号私信权重摘要（激活天数、日额度、间隔） */
    public function accountDmWeight(Request $request, string $id): Response
    {
        AiLeadVideoWeightService::ensureSchema();
        $accountId = (int)$id;
        if ($accountId < 1) {
            return $this->error('账号无效', 422);
        }
        $acc = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
        if (!$acc) {
            return $this->error('账号不存在', 404);
        }
        DataScope::ensureColumns();
        if ($err = $this->assertAccountOwns($acc)) {
            return $err;
        }
        return $this->success(AiLeadVideoWeightService::accountWeightSummary($accountId));
    }

    /** 保存账号私信间隔（每账号独立，缺省走系统默认） */
    public function saveAccountDmSettings(Request $request, string $id): Response
    {
        AiLeadVideoWeightService::ensureSchema();
        $accountId = (int)$id;
        $acc = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [$accountId]);
        if (!$acc) {
            return $this->error('账号不存在', 404);
        }
        DataScope::ensureColumns();
        if ($err = $this->assertAccountOwns($acc)) {
            return $err;
        }
        $min = (int)$request->input('dmIntervalMinSec', $request->input('dm_interval_min_sec', 0));
        $max = (int)$request->input('dmIntervalMaxSec', $request->input('dm_interval_max_sec', 0));
        if ($min < 1) {
            $min = AiLeadVideoWeightService::DEFAULT_INTERVAL_MIN_SEC;
        }
        if ($max < 1) {
            $max = AiLeadVideoWeightService::DEFAULT_INTERVAL_MAX_SEC;
        }
        try {
            $interval = AiLeadVideoWeightService::saveAccountInterval($accountId, $min, $max);
            return $this->success(array_merge(
                AiLeadVideoWeightService::accountWeightSummary($accountId),
                ['savedInterval' => $interval]
            ));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
