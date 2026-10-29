<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;
use App\Services\PullCommentService;

class PullCommentController extends Controller
{
    public function list(Request $request): Response
    {
        DataScope::ensureColumns();
        PullCommentService::ensureSchema();
        $ownerId = DataScope::isSuper() ? 0 : $this->writeOwnerId();
        $pack = PullCommentService::listTasks(
            $ownerId,
            (int)$request->input('page', 1),
            (int)$request->input('pageSize', $request->input('page_size', 20)),
            trim((string)$request->input('keyword', '')),
            trim((string)$request->input('status', ''))
        );
        return $this->success($pack);
    }

    public function detail(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        PullCommentService::ensureSchema();
        $task = PullCommentService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertOwns(['owner_id' => $task['ownerId']])) {
            return $err;
        }
        return $this->success($task);
    }

    public function create(Request $request): Response
    {
        DataScope::ensureColumns();
        PullCommentService::ensureSchema();
        $materialIds = $request->input('materialIds', $request->input('material_ids', []));
        if (!is_array($materialIds)) {
            $materialIds = preg_split('/[,\s]+/', (string)$materialIds) ?: [];
        }
        try {
            $out = PullCommentService::createFromMaterials(
                $materialIds,
                $this->writeOwnerId(),
                (string)$request->input('browserBackend', $request->input('browser_backend', 'bit')),
                trim((string)$request->input('name', '')),
                !DataScope::isSuper()
            );
            return $this->success($out, '已按账号创建 ' . ($out['created'] ?? 0) . ' 个拉取视频数据分任务');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        PullCommentService::ensureSchema();
        $task = PullCommentService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertOwns(['owner_id' => $task['ownerId']])) {
            return $err;
        }
        try {
            $out = PullCommentService::updateTask((int)$id, $request->all());
            return $this->success($out, '已更新');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function cancel(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        PullCommentService::ensureSchema();
        $task = PullCommentService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertOwns(['owner_id' => $task['ownerId']])) {
            return $err;
        }
        return $this->success(PullCommentService::cancelTask((int)$id), '已取消');
    }

    public function delete(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        PullCommentService::ensureSchema();
        $task = PullCommentService::getTask((int)$id);
        if (!$task) {
            return $this->error('任务不存在', 404);
        }
        if ($err = $this->assertOwns(['owner_id' => $task['ownerId']])) {
            return $err;
        }
        PullCommentService::deleteTask((int)$id);
        return $this->success(['id' => (int)$id], '已删除');
    }

    /** 已发布素材列表（创建任务用） */
    public function publishedMaterials(Request $request): Response
    {
        DataScope::ensureColumns();
        PullCommentService::ensureSchema();
        \App\Services\AccountWorkBatchService::ensureMaterialsPublishColumns();
        // 已标记发布但缺平台链接：尽量用账号已同步的 tiktok_videos 回填，否则列表会一直空
        try {
            [$scopeSql, $scopeParams] = DataScope::materialListSql('m');
            $need = DB::select(
                "SELECT m.id, m.publish_account_id FROM materials m
                 WHERE m.publish_status='published'
                   AND m.publish_account_id>0
                   AND m.platform_video_id='' AND m.platform_video_url=''" . $scopeSql . '
                 ORDER BY m.id DESC LIMIT 50',
                $scopeParams
            ) ?: [];
            foreach ($need as $row) {
                \App\Services\AccountWorkBatchService::backfillPlatformVideoFromSyncedVideos(
                    (int)($row['id'] ?? 0),
                    (int)($row['publish_account_id'] ?? 0)
                );
            }
        } catch (\Throwable $e) {
            // 回填失败不阻断列表
        }
        $filters = ["m.publish_status='published'"];
        $params = [];
        [$matSql, $matParams] = DataScope::materialListSql('m');
        $params = array_merge($params, $matParams);
        $filters[] = "(m.platform_video_url<>'' OR m.platform_video_id<>'')";
        $filters[] = 'm.publish_account_id>0';
        $keyword = trim((string)$request->input('keyword', ''));
        if ($keyword !== '') {
            $filters[] = '(m.name LIKE ? OR m.file_name LIKE ? OR m.platform_video_id LIKE ? OR a.username LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $accountId = (int)$request->input('accountId', $request->input('account_id', 0));
        if ($accountId > 0) {
            $filters[] = 'm.publish_account_id=?';
            $params[] = $accountId;
        }
        $where = 'WHERE ' . implode(' AND ', $filters) . $matSql;
        $page = max(1, (int)$request->input('page', 1));
        $pageSize = max(1, min(100, (int)$request->input('pageSize', $request->input('page_size', 40))));
        $total = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM materials m
             LEFT JOIN accounts a ON a.id=m.publish_account_id
             {$where}",
            $params
        )['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $rows = DB::select(
            "SELECT m.id, m.name, m.file_name, m.cover_base64, m.publish_account_id, m.publish_account,
                    m.platform_video_id, m.platform_video_url, m.share_title, m.view_count, m.like_count,
                    m.comment_count, m.platform_published_at, m.last_comment_pulled_at, m.created_at,
                    a.username AS publish_account_name, a.nickname AS publish_account_nickname
             FROM materials m
             LEFT JOIN accounts a ON a.id=m.publish_account_id
             {$where}
             ORDER BY m.id DESC
             LIMIT {$offset}, {$pageSize}",
            $params
        ) ?: [];
        $items = array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'name' => (string)($r['name'] ?? ''),
                'fileName' => (string)($r['file_name'] ?? ''),
                'coverBase64' => (string)($r['cover_base64'] ?? ''),
                'publishAccountId' => (int)($r['publish_account_id'] ?? 0),
                'publishAccount' => (string)($r['publish_account'] ?? $r['publish_account_name'] ?? ''),
                'publishAccountNickname' => (string)($r['publish_account_nickname'] ?? ''),
                'platformVideoId' => (string)($r['platform_video_id'] ?? ''),
                'platformVideoUrl' => (string)($r['platform_video_url'] ?? ''),
                'shareTitle' => (string)($r['share_title'] ?? ''),
                'viewCount' => (int)($r['view_count'] ?? 0),
                'likeCount' => (int)($r['like_count'] ?? 0),
                'commentCount' => (int)($r['comment_count'] ?? 0),
                'platformPublishedAt' => (string)($r['platform_published_at'] ?? ''),
                'lastCommentPulledAt' => (string)($r['last_comment_pulled_at'] ?? ''),
                'createdAt' => (string)($r['created_at'] ?? ''),
            ];
        }, $rows);
        return $this->success([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => $pageSize,
        ]);
    }

    public function materialDetail(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        PullCommentService::ensureSchema();
        $mat = DB::selectOne(
            'SELECT id, owner_id, publish_account_id FROM materials WHERE id=?',
            [(int)$id]
        );
        if ($err = $this->assertMaterialVisible($mat)) {
            return $err;
        }
        return $this->success(PullCommentService::materialDetail((int)$id));
    }
}
