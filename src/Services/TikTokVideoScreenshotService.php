<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * Studio 视频详情页截图：按账号+平台视频 ID 仅保留最新 N 条。
 */
class TikTokVideoScreenshotService
{
    public const KEEP_LATEST = 5;

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        DB::query(
            "CREATE TABLE IF NOT EXISTS tiktok_video_screenshots (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              account_id INT NOT NULL,
              video_id VARCHAR(64) NOT NULL DEFAULT '',
              video_url VARCHAR(500) NOT NULL DEFAULT '',
              screenshot_base64 LONGTEXT NOT NULL,
              mime_type VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
              source VARCHAR(32) NOT NULL DEFAULT 'studio_detail',
              page_url VARCHAR(500) NOT NULL DEFAULT '',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_tvs_account_video_id (account_id, video_id, id),
              KEY idx_tvs_video_created (video_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * @return array{id:int,accountId:int,videoId:string,kept:int,deleted:int}
     */
    public static function save(
        int $accountId,
        string $videoId,
        string $screenshotBase64,
        array $meta = []
    ): array {
        self::ensureSchema();
        $accountId = max(0, $accountId);
        $videoId = mb_substr(trim($videoId), 0, 64);
        if ($accountId < 1 || $videoId === '') {
            throw new \InvalidArgumentException('缺少 accountId 或 videoId');
        }

        $raw = trim($screenshotBase64);
        $mime = trim((string)($meta['mimeType'] ?? $meta['mime_type'] ?? 'image/jpeg')) ?: 'image/jpeg';
        // 允许 data:image/...;base64,xxxx 或纯 base64
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $raw, $m)) {
            $mime = strtolower($m[1]);
            $raw = $m[2];
        }
        $raw = preg_replace('/\s+/', '', $raw) ?? '';
        if ($raw === '' || strlen($raw) < 64) {
            throw new \InvalidArgumentException('截图 base64 无效或过短');
        }
        // 防极端大包（约 8MB base64 ≈ 6MB 图）
        if (strlen($raw) > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('截图过大');
        }
        if (!preg_match('#^[A-Za-z0-9+/]+=*$#', substr($raw, 0, 200))) {
            throw new \InvalidArgumentException('截图不是合法 base64');
        }

        $videoUrl = mb_substr(trim((string)($meta['videoUrl'] ?? $meta['video_url'] ?? '')), 0, 500);
        $pageUrl = mb_substr(trim((string)($meta['pageUrl'] ?? $meta['page_url'] ?? '')), 0, 500);
        $source = mb_substr(trim((string)($meta['source'] ?? 'studio_detail')) ?: 'studio_detail', 0, 32);
        $mime = mb_substr($mime, 0, 64);

        DB::query(
            'INSERT INTO tiktok_video_screenshots
             (account_id, video_id, video_url, screenshot_base64, mime_type, source, page_url)
             VALUES (?,?,?,?,?,?,?)',
            [$accountId, $videoId, $videoUrl, $raw, $mime, $source, $pageUrl]
        );
        $newId = (int)DB::getPdo()->lastInsertId();

        $keep = DB::select(
            'SELECT id FROM tiktok_video_screenshots
             WHERE account_id=? AND video_id=?
             ORDER BY id DESC LIMIT ' . self::KEEP_LATEST,
            [$accountId, $videoId]
        ) ?: [];
        $keepIds = array_map(static fn($r) => (int)$r['id'], $keep);
        $deleted = 0;
        if ($keepIds) {
            $ph = implode(',', array_fill(0, count($keepIds), '?'));
            $deleted = DB::execute(
                "DELETE FROM tiktok_video_screenshots
                 WHERE account_id=? AND video_id=? AND id NOT IN ({$ph})",
                array_merge([$accountId, $videoId], $keepIds)
            );
        }

        return [
            'id' => $newId,
            'accountId' => $accountId,
            'videoId' => $videoId,
            'kept' => count($keepIds),
            'deleted' => $deleted,
            'source' => $source,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listLatest(int $accountId, string $videoId, int $limit = self::KEEP_LATEST): array
    {
        self::ensureSchema();
        $limit = max(1, min(20, $limit));
        $rows = DB::select(
            "SELECT id, account_id, video_id, video_url, mime_type, source, page_url, created_at,
                    LEFT(screenshot_base64, 48) AS preview
             FROM tiktok_video_screenshots
             WHERE account_id=? AND video_id=?
             ORDER BY id DESC
             LIMIT {$limit}",
            [$accountId, mb_substr(trim($videoId), 0, 64)]
        ) ?: [];
        return array_map(static function ($r) {
            return [
                'id' => (int)($r['id'] ?? 0),
                'accountId' => (int)($r['account_id'] ?? 0),
                'videoId' => (string)($r['video_id'] ?? ''),
                'videoUrl' => (string)($r['video_url'] ?? ''),
                'mimeType' => (string)($r['mime_type'] ?? ''),
                'source' => (string)($r['source'] ?? ''),
                'pageUrl' => (string)($r['page_url'] ?? ''),
                'createdAt' => (string)($r['created_at'] ?? ''),
                'hasBase64' => strlen((string)($r['preview'] ?? '')) > 0,
            ];
        }, $rows);
    }
}
