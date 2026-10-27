<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AiLeadExecutionService;
use App\Services\DataScope;
use App\Services\IndustryDataService;

/**
 * AI线索：汇总 AI 拓客跑出的意向用户与评论，支持换号、详情、导出
 */
class AiLeadClueController extends Controller
{
    private const RECORD_STATUSES = AiLeadController::RECORD_STATUSES;

    public function list(Request $request): Response
    {
        AiLeadExecutionService::ensureSchema();
        DataScope::ensureColumns();

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;

        $keyword = trim((string)$request->get('keyword', ''));
        $dmStatus = trim((string)$request->get('dmStatus', $request->get('dm_status', '')));
        $sourceType = trim((string)$request->get('sourceType', $request->get('source_type', '')));
        $leadId = (int)$request->get('leadId', $request->get('lead_id', 0));
        $minScore = (int)$request->get('minScore', $request->get('min_score', 0));
        $industryId = (int)$request->get('industryId', $request->get('industry_id', 0));

        $filters = [];
        $params = [];
        $this->applyOwnerFilter('l.owner_id', $filters, $params);
        $this->applyIndustryFilter($industryId, $filters, $params, 'x');

        if ($leadId > 0) {
            $filters[] = 'x.lead_id=?';
            $params[] = $leadId;
        }
        if ($sourceType === 'comment' || $sourceType === 'creator') {
            $filters[] = 'x.source_type=?';
            $params[] = $sourceType;
        }
        if ($minScore > 0) {
            $filters[] = 'x.intent_score>=?';
            $params[] = min(10, $minScore);
        }
        if ($keyword !== '') {
            $filters[] = '(x.nickname LIKE ? OR x.username LIKE ? OR l.keywords LIKE ? OR x.comment_text LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($dmStatus !== '') {
            if ($dmStatus === 'none') {
                $filters[] = '(x.dm_status IS NULL OR x.dm_status=\'\')';
            } elseif (isset(self::RECORD_STATUSES[$dmStatus])) {
                $filters[] = 'x.dm_status=?';
                $params[] = $dmStatus;
            }
        }

        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $unionSql = $this->clueUnionSql();

        $total = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM ({$unionSql}) x
             INNER JOIN ai_leads l ON l.id=x.lead_id
             {$where}",
            $params
        )['c'] ?? 0);

        $rows = DB::select(
            "SELECT x.*, l.keywords AS lead_keywords, l.type AS lead_type, l.status AS lead_status
             FROM ({$unionSql}) x
             INNER JOIN ai_leads l ON l.id=x.lead_id
             {$where}
             ORDER BY x.intent_score DESC, x.created_at DESC, x.source_id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];

        $pages = max(1, (int)ceil($total / $pageSize));
        $summary = $this->fetchClueSummary($where, $params, $unionSql);

        $sharedCounts = $this->fetchCommentSharedCounts($rows);

        return $this->success([
            'items' => array_map(function (array $row) use ($sharedCounts) {
                $mapped = $this->mapClue($row);
                $isComment = (string)($row['source_type'] ?? '') === 'comment';
                $mapped['canShare'] = $isComment;
                $mapped['sharedUserCount'] = $isComment
                    ? (int)($sharedCounts[(int)($row['source_id'] ?? 0)] ?? 0)
                    : 0;
                return $mapped;
            }, $rows),
            'total' => $total,
            'page' => min($page, $pages),
            'pages' => $pages,
            'pageSize' => $pageSize,
            'summary' => $summary,
            'exportFields' => $this->exportFieldList(),
            'dmStatuses' => $this->dmStatusList(),
            'sourceTypes' => [
                ['code' => 'comment', 'label' => '评论线索'],
                ['code' => 'creator', 'label' => '用户线索'],
            ],
            'industries' => $this->industryFilterOptions(),
        ]);
    }

    /** 线索数据：共享给当前登录用户的评论线索（无私信/账号字段） */
    public function listData(Request $request): Response
    {
        AiLeadExecutionService::ensureSchema();
        IndustryDataService::ensureSchema();

        $userId = $this->loginUserId();
        if ($userId <= 0) {
            return $this->success([
                'items' => [],
                'total' => 0,
                'page' => 1,
                'pages' => 1,
                'pageSize' => 20,
                'summary' => ['total' => 0],
                'industries' => $this->industryFilterOptions(),
            ]);
        }

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 20)));
        $offset = ($page - 1) * $pageSize;
        $keyword = trim((string)$request->get('keyword', ''));
        $leadId = (int)$request->get('leadId', $request->get('lead_id', 0));
        $minScore = (int)$request->get('minScore', $request->get('min_score', 0));
        $industryId = (int)$request->get('industryId', $request->get('industry_id', 0));

        $filters = ['su.user_id=?'];
        $params = [$userId];
        if ($leadId > 0) {
            $filters[] = 'cm.lead_id=?';
            $params[] = $leadId;
        }
        if ($minScore > 0) {
            $filters[] = 'cm.intent_score>=?';
            $params[] = min(10, $minScore);
        }
        if ($keyword !== '') {
            $filters[] = '(cm.nickname LIKE ? OR cm.username LIKE ? OR l.keywords LIKE ? OR cm.comment_text LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $this->applyIndustryFilter($industryId, $filters, $params, 'cm');
        $where = 'WHERE ' . implode(' AND ', $filters);

        $baseSql = "FROM ai_lead_comments cm
            INNER JOIN ai_lead_comment_shared_users su ON su.comment_id=cm.id
            INNER JOIN ai_leads l ON l.id=cm.lead_id
            {$where} AND cm.ai_pass=1";

        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c {$baseSql}", $params)['c'] ?? 0);
        $rows = DB::select(
            "SELECT cm.*, l.keywords AS lead_keywords, l.type AS lead_type
             {$baseSql}
             ORDER BY cm.intent_score DESC, cm.created_at DESC, cm.id DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            $params
        ) ?: [];
        $pages = max(1, (int)ceil($total / $pageSize));

        return $this->success([
            'items' => array_map(fn($r) => $this->mapClueDataRow($r), $rows),
            'total' => $total,
            'page' => min($page, $pages),
            'pages' => $pages,
            'pageSize' => $pageSize,
            'summary' => ['total' => $total],
            'industries' => $this->industryFilterOptions(),
        ]);
    }

    public function detailData(Request $request, string $key): Response
    {
        AiLeadExecutionService::ensureSchema();
        $userId = $this->loginUserId();
        if ($userId <= 0) {
            return $this->error('请先登录', 401);
        }
        $parsed = $this->parseClueKey($key);
        if (!$parsed || $parsed['sourceType'] !== 'comment') {
            return $this->error('无效线索标识', 422);
        }
        $row = DB::selectOne(
            "SELECT cm.*, l.keywords AS lead_keywords, l.type AS lead_type
             FROM ai_lead_comments cm
             INNER JOIN ai_lead_comment_shared_users su ON su.comment_id=cm.id AND su.user_id=?
             INNER JOIN ai_leads l ON l.id=cm.lead_id
             WHERE cm.id=? AND cm.ai_pass=1",
            [$userId, $parsed['sourceId']]
        );
        if (!$row) {
            return $this->error('线索不存在或无权查看', 404);
        }
        $mapped = $this->mapClueDataRow($row);
        $cm = DB::selectOne(
            "SELECT cm.*, v.video_url, v.video_name, v.video_cover, v.likes AS video_likes, v.views AS video_views
             FROM ai_lead_comments cm
             LEFT JOIN ai_lead_videos v ON v.id=cm.video_id
             WHERE cm.id=?",
            [$parsed['sourceId']]
        );
        if ($cm) {
            $mapped['comment'] = [
                'commentText' => (string)($cm['comment_text'] ?? ''),
                'commentTextZh' => (string)($cm['comment_text_zh'] ?? ''),
                'aiPass' => !empty($cm['ai_pass']),
                'aiReason' => (string)($cm['ai_reason'] ?? ''),
                'videoUrl' => (string)($cm['video_url'] ?? ''),
                'videoName' => (string)($cm['video_name'] ?? ''),
                'videoCover' => (string)($cm['video_cover'] ?? ''),
                'videoLikes' => (int)($cm['video_likes'] ?? 0),
                'videoViews' => (int)($cm['video_views'] ?? 0),
            ];
        }
        $mapped['lead'] = [
            'id' => (int)$row['lead_id'],
            'keywords' => (string)($row['lead_keywords'] ?? ''),
            'typeLabel' => AiLeadController::TYPES[(string)($row['lead_type'] ?? '')] ?? '',
        ];
        return $this->success($mapped);
    }

    public function detail(Request $request, string $key): Response
    {
        AiLeadExecutionService::ensureSchema();
        DataScope::ensureColumns();

        $parsed = $this->parseClueKey($key);
        if (!$parsed) {
            return $this->error('无效线索标识', 422);
        }
        $row = $this->fetchClueRow($parsed['sourceType'], $parsed['sourceId']);
        if (!$row) {
            return $this->error('线索不存在', 404);
        }
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$row['lead_id']]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }

        $mapped = $this->mapClue($row);
        $mapped['lead'] = [
            'id' => (int)$lead['id'],
            'keywords' => (string)($lead['keywords'] ?? ''),
            'type' => (string)($lead['type'] ?? ''),
            'typeLabel' => AiLeadController::TYPES[(string)($lead['type'] ?? '')] ?? (string)($lead['type'] ?? ''),
            'status' => (string)($lead['status'] ?? ''),
            'dmMessage' => (string)($lead['dm_message'] ?? ''),
        ];

        $uname = strtolower(trim((string)($row['username'] ?? '')));
        $uuid = trim((string)($row['uuid'] ?? ''));
        $records = DB::select(
            "SELECT r.*, a.username AS account_username, a.nickname AS account_nickname
             FROM ai_lead_records r
             LEFT JOIN accounts a ON a.id=r.account_id
             WHERE r.lead_id=? AND (LOWER(r.username)=? OR (?<>'' AND r.uuid=?))
             ORDER BY r.id DESC
             LIMIT 50",
            [(int)$row['lead_id'], $uname, $uuid, $uuid]
        ) ?: [];
        $mapped['dmRecords'] = array_map(static function (array $r) {
            $st = (string)($r['status'] ?? 'pending');
            return [
                'id' => (int)$r['id'],
                'accountId' => (int)$r['account_id'],
                'accountUsername' => (string)($r['account_username'] ?? ''),
                'accountNickname' => (string)($r['account_nickname'] ?? ''),
                'message' => (string)($r['message'] ?? ''),
                'errorMessage' => (string)($r['error_message'] ?? ''),
                'status' => $st,
                'statusLabel' => AiLeadController::RECORD_STATUSES[$st] ?? $st,
                'createdAt' => (string)($r['created_at'] ?? ''),
                'updatedAt' => (string)($r['updated_at'] ?? ''),
            ];
        }, $records);

        $accounts = DB::select(
            "SELECT la.account_id, a.username, a.nickname, a.login_status, a.enabled
             FROM ai_lead_accounts la
             LEFT JOIN accounts a ON a.id=la.account_id
             WHERE la.lead_id=?
             ORDER BY la.id ASC",
            [(int)$row['lead_id']]
        ) ?: [];
        $mapped['leadAccounts'] = array_map(static fn($a) => [
            'accountId' => (int)$a['account_id'],
            'username' => (string)($a['username'] ?? ''),
            'nickname' => (string)($a['nickname'] ?? ''),
            'loginStatus' => (string)($a['login_status'] ?? ''),
            'enabled' => (int)($a['enabled'] ?? 0),
        ], $accounts);

        if ($parsed['sourceType'] === 'comment') {
            $cm = DB::selectOne(
                "SELECT cm.*, v.video_url, v.video_name, v.video_cover, v.likes AS video_likes, v.views AS video_views
                 FROM ai_lead_comments cm
                 LEFT JOIN ai_lead_videos v ON v.id=cm.video_id
                 WHERE cm.id=?",
                [$parsed['sourceId']]
            );
            if ($cm) {
                $mapped['comment'] = [
                    'commentText' => (string)($cm['comment_text'] ?? ''),
                    'commentTextZh' => (string)($cm['comment_text_zh'] ?? ''),
                    'aiPass' => !empty($cm['ai_pass']),
                    'aiReason' => (string)($cm['ai_reason'] ?? ''),
                    'videoUrl' => (string)($cm['video_url'] ?? ''),
                    'videoName' => (string)($cm['video_name'] ?? ''),
                    'videoCover' => (string)($cm['video_cover'] ?? ''),
                    'videoLikes' => (int)($cm['video_likes'] ?? 0),
                    'videoViews' => (int)($cm['video_views'] ?? 0),
                ];
            }
        }

        $creator = DB::selectOne(
            "SELECT c.* FROM ai_lead_creators c
             WHERE c.lead_id=? AND (LOWER(c.username)=? OR (?<>'' AND c.uuid=?))
             ORDER BY c.id DESC LIMIT 1",
            [(int)$row['lead_id'], $uname, $uuid, $uuid]
        );
        if ($creator) {
            $mapped['creator'] = [
                'id' => (int)$creator['id'],
                'avatar' => (string)($creator['avatar'] ?? ''),
                'signature' => (string)($creator['signature'] ?? ''),
                'followers' => (int)($creator['followers'] ?? 0),
                'following' => (int)($creator['following'] ?? 0),
                'likes' => (int)($creator['likes'] ?? 0),
                'region' => (string)($creator['region'] ?? ''),
                'status' => (string)($creator['status'] ?? ''),
            ];
        }

        if ($parsed['sourceType'] === 'comment') {
            $sharedUsers = $this->fetchCommentSharedUsers($parsed['sourceId']);
            $mapped['sharedUsers'] = $sharedUsers;
            $mapped['sharedUserCount'] = count($sharedUsers);
            $mapped['canShare'] = true;
        } else {
            $mapped['sharedUsers'] = [];
            $mapped['sharedUserCount'] = 0;
            $mapped['canShare'] = false;
        }

        return $this->success($mapped);
    }

    /** 抄送目标：指定角色下的启用用户（默认「企业客户」） */
    public function shareRoleUsers(Request $request): Response
    {
        $roleName = trim((string)$request->get('roleName', $request->get('role_name', '企业客户')));
        if ($roleName === '') {
            $roleName = '企业客户';
        }
        $role = DB::selectOne(
            'SELECT id, name, status FROM admin_roles WHERE name=? LIMIT 1',
            [$roleName]
        );
        if (!$role) {
            return $this->success([
                'items' => [],
                'role' => null,
                'roleName' => $roleName,
            ]);
        }
        $rows = DB::select(
            'SELECT id, username, nickname, role_id, status FROM admin_users WHERE role_id=? AND status=1 ORDER BY id ASC',
            [(int)$role['id']]
        ) ?: [];
        return $this->success([
            'items' => array_map(static fn(array $u) => [
                'id' => (int)$u['id'],
                'username' => (string)($u['username'] ?? ''),
                'nickname' => (string)($u['nickname'] ?? ''),
                'label' => (string)(($u['nickname'] ?? '') !== '' ? $u['nickname'] : ($u['username'] ?? '')),
            ], $rows),
            'role' => [
                'id' => (int)$role['id'],
                'name' => (string)$role['name'],
            ],
            'roleName' => $roleName,
        ]);
    }

    /** 批量抄送：将评论线索共享给企业客户 */
    public function batchShare(Request $request): Response
    {
        AiLeadExecutionService::ensureSchema();
        DataScope::ensureColumns();

        $clueKeys = $request->input('clueKeys', $request->input('clue_keys', []));
        $userIds = $request->input('userIds', $request->input('user_ids', []));
        $roleName = trim((string)$request->input('roleName', $request->input('role_name', '企业客户')));
        if ($roleName === '') {
            $roleName = '企业客户';
        }
        if (!is_array($clueKeys) || !is_array($userIds)) {
            return $this->error('参数无效', 422);
        }
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return $this->error('请选择抄送客户', 422);
        }
        if (!$clueKeys) {
            return $this->error('请先勾选线索', 422);
        }

        $role = DB::selectOne(
            'SELECT id, name FROM admin_roles WHERE name=? AND status=1 LIMIT 1',
            [$roleName]
        );
        if (!$role) {
            return $this->error('未找到启用的「' . $roleName . '」角色', 422);
        }
        $roleId = (int)$role['id'];
        $validUserIds = [];
        foreach ($userIds as $uid) {
            $u = DB::selectOne(
                'SELECT id FROM admin_users WHERE id=? AND role_id=? AND status=1',
                [$uid, $roleId]
            );
            if ($u) {
                $validUserIds[] = $uid;
            }
        }
        if (!$validUserIds) {
            return $this->error('所选用户不属于「' . $roleName . '」角色或已停用', 422);
        }

        $commentIds = [];
        $skippedCreators = 0;
        $invalid = 0;
        foreach ($clueKeys as $key) {
            $parsed = $this->parseClueKey((string)$key);
            if (!$parsed) {
                $invalid++;
                continue;
            }
            if ($parsed['sourceType'] !== 'comment') {
                $skippedCreators++;
                continue;
            }
            $row = $this->fetchClueRow('comment', $parsed['sourceId']);
            if (!$row) {
                $invalid++;
                continue;
            }
            $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$row['lead_id']]);
            if ($err = $this->assertOwns($lead)) {
                return $err;
            }
            $commentIds[] = $parsed['sourceId'];
        }
        $commentIds = array_values(array_unique($commentIds));
        if (!$commentIds) {
            $msg = '没有可抄送的评论线索';
            if ($skippedCreators > 0) {
                $msg .= '（用户线索不支持抄送，请勾选评论线索）';
            }
            return $this->error($msg, 422);
        }

        $result = AiLeadExecutionService::shareCommentsToUsers($commentIds, $validUserIds);
        $result['skippedCreators'] = $skippedCreators;
        $result['invalid'] = $invalid;
        $result['roleName'] = $roleName;

        $msg = sprintf(
            '已抄送 %d 条评论线索给 %d 位客户（新建 %d 条共享关联）',
            $result['comments'],
            $result['users'],
            $result['created']
        );
        if ($skippedCreators > 0) {
            $msg .= sprintf('，跳过 %d 条用户线索', $skippedCreators);
        }
        return $this->success($result, $msg);
    }

    public function patchAccount(Request $request, string $key): Response
    {
        AiLeadExecutionService::ensureSchema();
        DataScope::ensureColumns();

        $parsed = $this->parseClueKey($key);
        if (!$parsed) {
            return $this->error('无效线索标识', 422);
        }
        $row = $this->fetchClueRow($parsed['sourceType'], $parsed['sourceId']);
        if (!$row) {
            return $this->error('线索不存在', 404);
        }
        $lead = DB::selectOne('SELECT * FROM ai_leads WHERE id=?', [(int)$row['lead_id']]);
        if ($err = $this->assertOwns($lead)) {
            return $err;
        }

        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        if ($accountId <= 0) {
            return $this->error('请选择关联账号', 422);
        }
        $bound = DB::selectOne(
            'SELECT id FROM ai_lead_accounts WHERE lead_id=? AND account_id=?',
            [(int)$row['lead_id'], $accountId]
        );
        if (!$bound) {
            return $this->error('该账号未关联到此拓客任务', 422);
        }

        $dmStatus = (string)($row['dm_status'] ?? '');
        if ($dmStatus === 'sent') {
            return $this->error('已发送成功的线索不可更换关联账号', 422);
        }

        $username = trim((string)($row['username'] ?? ''));
        $uuid = trim((string)($row['uuid'] ?? ''));
        if ($username === '' && $uuid === '') {
            return $this->error('线索缺少用户标识', 422);
        }

        $sendDm = filter_var($request->input('sendDm', $request->input('send_dm', false)), FILTER_VALIDATE_BOOLEAN);
        $recordId = (int)($row['dm_record_id'] ?? 0);

        if ($recordId > 0) {
            DB::query(
                "UPDATE ai_lead_records SET account_id=?, status='pending', error_message='', updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$accountId, $recordId]
            );
        } else {
            $recordId = (int)DB::table('ai_lead_records')->insert([
                'lead_id' => (int)$row['lead_id'],
                'account_id' => $accountId,
                'lead_log_id' => 0,
                'uuid' => mb_substr($uuid, 0, 64),
                'username' => mb_substr($username, 0, 128),
                'message' => mb_substr(trim((string)($lead['dm_message'] ?? '')), 0, 2000),
                'status' => 'pending',
            ]);
        }

        $schedule = null;
        if ($sendDm) {
            try {
                $schedule = AiLeadExecutionService::scheduleDmTest((int)$row['lead_id'], [
                    'username' => $username,
                    'accountId' => $accountId,
                    'limit' => 1,
                    'force' => true,
                ]);
            } catch (\Throwable $e) {
                $schedule = ['skipped' => true, 'reason' => 'error', 'error' => $e->getMessage()];
            }
        }

        $fresh = $this->fetchClueRow($parsed['sourceType'], $parsed['sourceId']);
        $mapped = $this->mapClue($fresh ?: $row);
        $mapped['recordId'] = $recordId;
        if (is_array($schedule)) {
            $mapped['schedule'] = $schedule;
            $mapped['needPoll'] = empty($schedule['skipped']) || (($schedule['reason'] ?? '') === 'already_queued');
        }
        return $this->success($mapped, $sendDm ? '已更换账号并入队私信' : '已更换关联账号');
    }

    public function exportCsv(Request $request): Response
    {
        AiLeadExecutionService::ensureSchema();
        DataScope::ensureColumns();

        $keyword = trim((string)$request->get('keyword', ''));
        $dmStatus = trim((string)$request->get('dmStatus', $request->get('dm_status', '')));
        $sourceType = trim((string)$request->get('sourceType', $request->get('source_type', '')));
        $leadId = (int)$request->get('leadId', $request->get('lead_id', 0));
        $minScore = (int)$request->get('minScore', $request->get('min_score', 0));
        $industryId = (int)$request->get('industryId', $request->get('industry_id', 0));

        $filters = [];
        $params = [];
        $this->applyOwnerFilter('l.owner_id', $filters, $params);
        $this->applyIndustryFilter($industryId, $filters, $params, 'x');

        if ($leadId > 0) {
            $filters[] = 'x.lead_id=?';
            $params[] = $leadId;
        }
        if ($sourceType === 'comment' || $sourceType === 'creator') {
            $filters[] = 'x.source_type=?';
            $params[] = $sourceType;
        }
        if ($minScore > 0) {
            $filters[] = 'x.intent_score>=?';
            $params[] = min(10, $minScore);
        }
        if ($keyword !== '') {
            $filters[] = '(x.nickname LIKE ? OR x.username LIKE ? OR l.keywords LIKE ? OR x.comment_text LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($dmStatus !== '') {
            if ($dmStatus === 'none') {
                $filters[] = '(x.dm_status IS NULL OR x.dm_status=\'\')';
            } elseif (isset(self::RECORD_STATUSES[$dmStatus])) {
                $filters[] = 'x.dm_status=?';
                $params[] = $dmStatus;
            }
        }

        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $unionSql = $this->clueUnionSql();
        $rows = DB::select(
            "SELECT x.*, l.keywords AS lead_keywords, l.type AS lead_type
             FROM ({$unionSql}) x
             INNER JOIN ai_leads l ON l.id=x.lead_id
             {$where}
             ORDER BY x.intent_score DESC, x.created_at DESC, x.source_id DESC
             LIMIT 10000",
            $params
        ) ?: [];

        $fieldDefs = $this->exportFieldDefs();
        $fieldsRaw = trim((string)$request->get('fields', ''));
        $headersRaw = trim((string)$request->get('headers', ''));
        $selected = $fieldsRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $fieldsRaw))))
            : array_keys($fieldDefs);
        $customHeaders = [];
        if ($headersRaw !== '') {
            $decoded = json_decode($headersRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $customHeaders[(string)$k] = trim((string)$v);
                }
            } else {
                $parts = array_map('trim', explode(',', $headersRaw));
                foreach ($selected as $i => $f) {
                    if (isset($parts[$i]) && $parts[$i] !== '') {
                        $customHeaders[$f] = $parts[$i];
                    }
                }
            }
        }
        $cols = [];
        foreach ($selected as $f) {
            if (!isset($fieldDefs[$f])) {
                continue;
            }
            $cols[] = [
                'key' => $f,
                'label' => (isset($customHeaders[$f]) && $customHeaders[$f] !== '')
                    ? $customHeaders[$f]
                    : $fieldDefs[$f],
            ];
        }
        if (!$cols) {
            foreach ($fieldDefs as $k => $label) {
                $cols[] = ['key' => $k, 'label' => $label];
            }
        }

        $esc = static fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"';
        $lines = [implode(',', array_map(static fn($c) => '"' . str_replace('"', '""', $c['label']) . '"', $cols))];
        foreach ($rows as $r) {
            $mapped = $this->mapExportRow($this->mapClue($r));
            $line = [];
            foreach ($cols as $c) {
                $line[] = $esc($mapped[$c['key']] ?? '');
            }
            $lines[] = implode(',', $line);
        }
        return Response::make("\xEF\xBB\xBF" . implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="ai-lead-clues.csv"',
        ]);
    }

    /** @return array<string,string> */
    private function exportFieldDefs(): array
    {
        return [
            'nickname' => '用户昵称',
            'username' => '用户名',
            'profileUrl' => '主页地址',
            'keywords' => '关键词',
            'intentScore' => '评级',
            'dmStatusLabel' => '私信状态',
            'accountUsername' => '关联账号',
            'accountId' => '关联账号ID',
            'sourceTypeLabel' => '线索类型',
            'industryLabel' => '行业',
            'commentText' => '评论内容',
            'commentTextZh' => '评论中文',
            'aiReason' => 'AI筛选原因',
            'videoUrl' => '来源视频',
            'errorMessage' => '失败原因',
            'leadId' => '拓客任务ID',
            'uuid' => '用户UUID',
            'createdAt' => '发现时间',
        ];
    }

    /** @return list<array{code:string,label:string,defaultOn:bool}> */
    private function exportFieldList(): array
    {
        $defs = $this->exportFieldDefs();
        $defaults = [
            'nickname', 'username', 'profileUrl', 'keywords', 'industryLabel', 'intentScore',
            'dmStatusLabel', 'accountUsername', 'createdAt',
        ];
        $out = [];
        foreach ($defs as $code => $label) {
            $out[] = [
                'code' => $code,
                'label' => $label,
                'defaultOn' => in_array($code, $defaults, true),
            ];
        }
        return $out;
    }

    /** @return list<array{code:string,label:string}> */
    private function dmStatusList(): array
    {
        $out = [['code' => 'none', 'label' => '未私信']];
        foreach (self::RECORD_STATUSES as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }
        return $out;
    }

    /** @param list<mixed> $params */
    private function fetchClueSummary(string $where, array $params, string $unionSql): array
    {
        $row = DB::selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN COALESCE(x.dm_status,'')='sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN COALESCE(x.dm_status,'')='failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN COALESCE(x.dm_status,'') IN ('pending','') THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN COALESCE(x.dm_status,'')='skipped' THEN 1 ELSE 0 END) AS skipped
             FROM ({$unionSql}) x
             INNER JOIN ai_leads l ON l.id=x.lead_id
             {$where}",
            $params
        ) ?: [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'sent' => (int)($row['sent'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'pending' => (int)($row['pending'] ?? 0),
            'skipped' => (int)($row['skipped'] ?? 0),
        ];
    }

    private function dmJoinSql(string $alias, string $leadCol, string $userCol, string $uuidCol): string
    {
        $latestDm = "SELECT r1.id, r1.lead_id, r1.username, r1.uuid, r1.account_id, r1.status, r1.error_message, r1.message
            FROM ai_lead_records r1
            INNER JOIN (
                SELECT lead_id, user_key, MAX(id) AS max_id
                FROM (
                    SELECT id, lead_id, IF(username<>'', LOWER(username), uuid) AS user_key
                    FROM ai_lead_records
                    WHERE username<>'' OR uuid<>''
                ) rk
                GROUP BY lead_id, user_key
            ) r2 ON r1.id=r2.max_id";
        return "LEFT JOIN ({$latestDm}) {$alias} ON {$alias}.lead_id={$leadCol}
            AND (
                ({$userCol}<>'' AND LOWER({$alias}.username)=LOWER({$userCol}))
                OR ({$uuidCol}<>'' AND {$alias}.uuid={$uuidCol})
            )";
    }

    private function clueUnionSql(): string
    {
        $dmJoinCm = $this->dmJoinSql('dm', 'cm.lead_id', 'cm.username', 'cm.uuid');
        $dmJoinCr = $this->dmJoinSql('dm', 'c.lead_id', 'c.username', 'c.uuid');

        return "SELECT
            CONCAT('c-', cm.id) AS clue_key,
            'comment' AS source_type,
            cm.id AS source_id,
            cm.lead_id,
            cm.username,
            cm.nickname,
            cm.profile_url,
            cm.uuid,
            cm.intent_score,
            cm.comment_text,
            cm.comment_text_zh,
            cm.ai_reason,
            cm.industry_id,
            cm.video_id,
            cm.created_at,
            COALESCE(dm.id, 0) AS dm_record_id,
            COALESCE(dm.account_id, 0) AS dm_account_id,
            COALESCE(dm.status, '') AS dm_status,
            COALESCE(dm.error_message, '') AS dm_error_message,
            COALESCE(dm.message, '') AS dm_message
        FROM ai_lead_comments cm
        INNER JOIN ai_leads l ON l.id=cm.lead_id
        {$dmJoinCm}
        WHERE cm.ai_pass=1

        UNION ALL

        SELECT
            CONCAT('r-', c.id) AS clue_key,
            'creator' AS source_type,
            c.id AS source_id,
            c.lead_id,
            c.username,
            c.nickname,
            c.profile_url,
            c.uuid,
            c.intent_score,
            '' AS comment_text,
            '' AS comment_text_zh,
            '' AS ai_reason,
            COALESCE(l.industry_id, 0) AS industry_id,
            0 AS video_id,
            c.created_at,
            COALESCE(dm.id, 0) AS dm_record_id,
            COALESCE(NULLIF(dm.account_id, 0), c.account_id) AS dm_account_id,
            COALESCE(dm.status, '') AS dm_status,
            COALESCE(dm.error_message, '') AS dm_error_message,
            COALESCE(dm.message, '') AS dm_message
        FROM ai_lead_creators c
        INNER JOIN ai_leads l ON l.id=c.lead_id
        {$dmJoinCr}
        WHERE " . AiLeadExecutionService::scrapedVideoCreatorFilterSql('c') . "
          AND NOT EXISTS (
            SELECT 1 FROM ai_lead_comments cm
            WHERE cm.lead_id=c.lead_id AND cm.ai_pass=1
              AND (LOWER(cm.username)=LOWER(c.username) OR (cm.uuid<>'' AND cm.uuid=c.uuid))
        )";
    }

    /** @return array{sourceType:string,sourceId:int}|null */
    private function parseClueKey(string $key): ?array
    {
        $key = trim($key);
        if (preg_match('/^c-(\d+)$/', $key, $m)) {
            return ['sourceType' => 'comment', 'sourceId' => (int)$m[1]];
        }
        if (preg_match('/^r-(\d+)$/', $key, $m)) {
            return ['sourceType' => 'creator', 'sourceId' => (int)$m[1]];
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function fetchClueRow(string $sourceType, int $sourceId): ?array
    {
        $unionSql = $this->clueUnionSql();
        if ($sourceType === 'comment') {
            return DB::selectOne(
                "SELECT x.*, l.keywords AS lead_keywords, l.type AS lead_type
                 FROM ({$unionSql}) x
                 INNER JOIN ai_leads l ON l.id=x.lead_id
                 WHERE x.source_type='comment' AND x.source_id=?",
                [$sourceId]
            ) ?: null;
        }
        return DB::selectOne(
            "SELECT x.*, l.keywords AS lead_keywords, l.type AS lead_type
             FROM ({$unionSql}) x
             INNER JOIN ai_leads l ON l.id=x.lead_id
             WHERE x.source_type='creator' AND x.source_id=?",
            [$sourceId]
        ) ?: null;
    }

    /** @param array<string,mixed> $row */
    private function mapClue(array $row): array
    {
        $dmStatus = trim((string)($row['dm_status'] ?? ''));
        $accountId = (int)($row['dm_account_id'] ?? 0);
        $accountUsername = '';
        $accountNickname = '';
        if ($accountId > 0) {
            $acc = DB::selectOne('SELECT username, nickname FROM accounts WHERE id=?', [$accountId]);
            $accountUsername = (string)($acc['username'] ?? '');
            $accountNickname = (string)($acc['nickname'] ?? '');
        }

        $profileUrl = trim((string)($row['profile_url'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        if ($profileUrl === '' && $username !== '') {
            $profileUrl = 'https://www.tiktok.com/@' . ltrim($username, '@');
        }

        $videoUrl = '';
        $videoName = '';
        $videoId = (int)($row['video_id'] ?? 0);
        if ($videoId > 0) {
            $v = DB::selectOne('SELECT video_url, video_name FROM ai_lead_videos WHERE id=?', [$videoId]);
            $videoUrl = (string)($v['video_url'] ?? '');
            $videoName = (string)($v['video_name'] ?? '');
        }

        $sourceType = (string)($row['source_type'] ?? '');
        $canChangeAccount = $dmStatus === '' || in_array($dmStatus, ['pending', 'failed', 'skipped'], true);

        return [
            'clueKey' => (string)($row['clue_key'] ?? ''),
            'sourceType' => $sourceType,
            'sourceTypeLabel' => $sourceType === 'comment' ? '评论线索' : '用户线索',
            'sourceId' => (int)($row['source_id'] ?? 0),
            'leadId' => (int)($row['lead_id'] ?? 0),
            'username' => $username,
            'nickname' => (string)($row['nickname'] ?? ''),
            'profileUrl' => $profileUrl,
            'uuid' => (string)($row['uuid'] ?? ''),
            'keywords' => (string)($row['lead_keywords'] ?? ''),
            'intentScore' => max(0, min(10, (int)($row['intent_score'] ?? 0))),
            'commentText' => (string)($row['comment_text'] ?? ''),
            'commentTextZh' => (string)($row['comment_text_zh'] ?? ''),
            'aiReason' => (string)($row['ai_reason'] ?? ''),
            'videoUrl' => $videoUrl,
            'videoName' => $videoName,
            'dmRecordId' => (int)($row['dm_record_id'] ?? 0),
            'dmStatus' => $dmStatus !== '' ? $dmStatus : 'none',
            'dmStatusLabel' => $dmStatus !== ''
                ? (self::RECORD_STATUSES[$dmStatus] ?? $dmStatus)
                : '未私信',
            'errorMessage' => (string)($row['dm_error_message'] ?? ''),
            'dmMessage' => (string)($row['dm_message'] ?? ''),
            'accountId' => $accountId,
            'accountUsername' => $accountUsername,
            'accountNickname' => $accountNickname,
            'canChangeAccount' => $canChangeAccount,
            'industryId' => (int)($row['industry_id'] ?? 0),
            'industryLabel' => IndustryDataService::pathLabel((int)($row['industry_id'] ?? 0)),
            'industry' => IndustryDataService::resolveMeta((int)($row['industry_id'] ?? 0)),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapClueDataRow(array $row): array
    {
        $profileUrl = trim((string)($row['profile_url'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        if ($profileUrl === '' && $username !== '') {
            $profileUrl = 'https://www.tiktok.com/@' . ltrim($username, '@');
        }
        $videoUrl = '';
        $videoName = '';
        $videoId = (int)($row['video_id'] ?? 0);
        if ($videoId > 0) {
            $v = DB::selectOne('SELECT video_url, video_name FROM ai_lead_videos WHERE id=?', [$videoId]);
            $videoUrl = (string)($v['video_url'] ?? '');
            $videoName = (string)($v['video_name'] ?? '');
        }
        return [
            'clueKey' => 'c-' . (int)($row['id'] ?? 0),
            'sourceType' => 'comment',
            'sourceTypeLabel' => '评论线索',
            'sourceId' => (int)($row['id'] ?? 0),
            'leadId' => (int)($row['lead_id'] ?? 0),
            'username' => $username,
            'nickname' => (string)($row['nickname'] ?? ''),
            'profileUrl' => $profileUrl,
            'uuid' => (string)($row['uuid'] ?? ''),
            'keywords' => (string)($row['lead_keywords'] ?? ''),
            'intentScore' => max(0, min(10, (int)($row['intent_score'] ?? 0))),
            'commentText' => (string)($row['comment_text'] ?? ''),
            'commentTextZh' => (string)($row['comment_text_zh'] ?? ''),
            'aiReason' => (string)($row['ai_reason'] ?? ''),
            'videoUrl' => $videoUrl,
            'videoName' => $videoName,
            'industryId' => (int)($row['industry_id'] ?? 0),
            'industryLabel' => IndustryDataService::pathLabel((int)($row['industry_id'] ?? 0)),
            'industry' => IndustryDataService::resolveMeta((int)($row['industry_id'] ?? 0)),
        ];
    }

    /** @param list<mixed> $params */
    private function applyIndustryFilter(int $industryId, array &$filters, array &$params, string $alias): void
    {
        if ($industryId <= 0) {
            return;
        }
        $ids = IndustryDataService::descendantIds($industryId);
        $ids[] = $industryId;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $filters[] = "{$alias}.industry_id IN ({$ph})";
        array_push($params, ...$ids);
    }

    /** @param list<array<string,mixed>> $rows @return array<int,int> */
    private function fetchCommentSharedCounts(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ((string)($row['source_type'] ?? '') !== 'comment') {
                continue;
            }
            $id = (int)($row['source_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $countRows = DB::select(
            "SELECT comment_id, COUNT(*) AS c
             FROM ai_lead_comment_shared_users
             WHERE comment_id IN ({$ph})
             GROUP BY comment_id",
            $ids
        ) ?: [];
        $out = [];
        foreach ($countRows as $r) {
            $out[(int)($r['comment_id'] ?? 0)] = (int)($r['c'] ?? 0);
        }
        return $out;
    }

    /** @return list<array{id:int,username:string,nickname:string,label:string,roleName:string}> */
    private function fetchCommentSharedUsers(int $commentId): array
    {
        if ($commentId <= 0) {
            return [];
        }
        $rows = DB::select(
            "SELECT u.id, u.username, u.nickname, r.name AS role_name
             FROM ai_lead_comment_shared_users su
             INNER JOIN admin_users u ON u.id=su.user_id
             LEFT JOIN admin_roles r ON r.id=u.role_id
             WHERE su.comment_id=?
             ORDER BY su.id ASC",
            [$commentId]
        ) ?: [];
        return array_map(static function (array $u) {
            $nickname = (string)($u['nickname'] ?? '');
            $username = (string)($u['username'] ?? '');
            return [
                'id' => (int)($u['id'] ?? 0),
                'username' => $username,
                'nickname' => $nickname,
                'label' => $nickname !== '' ? $nickname : $username,
                'roleName' => (string)($u['role_name'] ?? ''),
            ];
        }, $rows);
    }

    /** @return list<array{id:int,label:string,level:int}> */
    private function industryFilterOptions(): array
    {
        IndustryDataService::ensureSchema();
        $rows = DB::select(
            'SELECT id, name, level FROM industry_data WHERE enabled=1 ORDER BY level ASC, sort_order ASC, id ASC LIMIT 2000'
        ) ?: [];
        return array_map(static fn($r) => [
            'id' => (int)$r['id'],
            'label' => IndustryDataService::pathLabel((int)$r['id']),
            'level' => (int)$r['level'],
        ], $rows);
    }

    private function loginUserId(): int
    {
        $u = DataScope::user();
        return max(0, (int)($u['id'] ?? 0));
    }

    /** @param array<string,mixed> $mapped */
    private function mapExportRow(array $mapped): array
    {
        return [
            'nickname' => $mapped['nickname'] ?? '',
            'username' => $mapped['username'] ?? '',
            'profileUrl' => $mapped['profileUrl'] ?? '',
            'keywords' => $mapped['keywords'] ?? '',
            'industryLabel' => $mapped['industryLabel'] ?? '',
            'intentScore' => (string)($mapped['intentScore'] ?? 0),
            'dmStatusLabel' => $mapped['dmStatusLabel'] ?? '',
            'accountUsername' => $mapped['accountUsername'] ?? '',
            'accountId' => $mapped['accountId'] ? (string)$mapped['accountId'] : '',
            'sourceTypeLabel' => $mapped['sourceTypeLabel'] ?? '',
            'commentText' => $mapped['commentText'] ?? '',
            'commentTextZh' => $mapped['commentTextZh'] ?? '',
            'aiReason' => $mapped['aiReason'] ?? '',
            'videoUrl' => $mapped['videoUrl'] ?? '',
            'errorMessage' => $mapped['errorMessage'] ?? '',
            'leadId' => (string)($mapped['leadId'] ?? 0),
            'uuid' => $mapped['uuid'] ?? '',
            'createdAt' => $mapped['createdAt'] ?? '',
        ];
    }
}
