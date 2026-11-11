<?php
declare(strict_types=1);

namespace App\Services\YouTube;

use App\Core\Database\DB;

/**
 * 计划任务：扫描 pending 的 youtube_publish_tasks 并执行上传。
 */
final class YouTubePublishDispatchService
{
    /**
     * @return array{scanned:int,ok:int,failed:int,skipped:int,items:list<array<string,mixed>>}
     */
    public static function tick(int $limit = 5): array
    {
        YouTubeSchema::ensureSchema();
        $limit = max(1, min(50, $limit));
        $rows = DB::select(
            "SELECT id, account_id, status FROM youtube_publish_tasks
             WHERE status='pending'
             ORDER BY id ASC
             LIMIT {$limit}"
        ) ?: [];

        $out = [
            'scanned' => count($rows),
            'ok' => 0,
            'failed' => 0,
            'skipped' => 0,
            'items' => [],
        ];

        foreach ($rows as $row) {
            $taskId = (int)($row['id'] ?? 0);
            $accountId = (int)($row['account_id'] ?? 0);
            if ($taskId <= 0 || $accountId <= 0) {
                $out['skipped']++;
                $out['items'][] = ['id' => $taskId, 'ok' => false, 'reason' => 'invalid task'];
                continue;
            }
            // 认领：避免多 worker 重复跑
            DB::query(
                "UPDATE youtube_publish_tasks
                 SET status='running', started_at=IFNULL(started_at, NOW()), error_message='', updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status='pending'",
                [$taskId]
            );
            $claimed = DB::selectOne('SELECT id, status FROM youtube_publish_tasks WHERE id=?', [$taskId]);
            if (!$claimed || (string)($claimed['status'] ?? '') !== 'running') {
                $out['skipped']++;
                $out['items'][] = ['id' => $taskId, 'ok' => false, 'reason' => 'claim missed'];
                continue;
            }
            try {
                $acc = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$accountId]);
                if (!$acc) {
                    throw new \RuntimeException('YouTube 账号不存在 #' . $accountId);
                }
                if (trim((string)($acc['refresh_token'] ?? '')) === '') {
                    throw new \RuntimeException('YouTube 账号未授权 #' . $accountId);
                }
                $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
                $svc = new YouTubeApiService($acc);
                // runPublishTask 内部会再设 running；此处已认领
                $result = $svc->runPublishTask($taskId);
                $out['ok']++;
                $out['items'][] = [
                    'id' => $taskId,
                    'ok' => true,
                    'youtubeVideoId' => (string)($result['youtubeVideoId'] ?? ''),
                ];
            } catch (\Throwable $e) {
                DB::query(
                    "UPDATE youtube_publish_tasks
                     SET status='failed', error_message=?, finished_at=NOW(), updated_at=CURRENT_TIMESTAMP
                     WHERE id=?",
                    [mb_substr($e->getMessage(), 0, 1000), $taskId]
                );
                $out['failed']++;
                $out['items'][] = ['id' => $taskId, 'ok' => false, 'reason' => $e->getMessage()];
            }
        }

        return $out;
    }
}
