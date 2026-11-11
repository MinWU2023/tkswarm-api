<?php
declare(strict_types=1);

namespace App\Services\YouTube;

use App\Core\Database\DB;

/**
 * YouTube 表结构 ensure + API 资源落库助手。
 */
final class YouTubeSchema
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        DB::query(
            "CREATE TABLE IF NOT EXISTS `youtube_accounts` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `owner_id` INT NOT NULL DEFAULT 0,
              `username` VARCHAR(191) NOT NULL DEFAULT '',
              `password` VARCHAR(255) NOT NULL DEFAULT '',
              `nickname` VARCHAR(191) NOT NULL DEFAULT '',
              `channel_id` VARCHAR(64) NOT NULL DEFAULT '',
              `channel_title` VARCHAR(255) NOT NULL DEFAULT '',
              `channel_custom_url` VARCHAR(191) NOT NULL DEFAULT '',
              `google_cloud_project_id` VARCHAR(128) NOT NULL DEFAULT '',
              `api_key` VARCHAR(255) NOT NULL DEFAULT '',
              `oauth_client_id` VARCHAR(255) NOT NULL DEFAULT '',
              `oauth_client_secret` VARCHAR(255) NOT NULL DEFAULT '',
              `oauth_redirect_uri` VARCHAR(500) NOT NULL DEFAULT '',
              `oauth_scopes` TEXT NULL,
              `access_token` TEXT NULL,
              `refresh_token` TEXT NULL,
              `token_type` VARCHAR(32) NOT NULL DEFAULT 'Bearer',
              `token_expires_at` DATETIME NULL,
              `last_login_at` DATETIME NULL,
              `status` VARCHAR(32) NOT NULL DEFAULT 'enabled',
              `last_error` VARCHAR(500) NOT NULL DEFAULT '',
              `extra_json` JSON NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_yt_acc_owner` (`owner_id`),
              KEY `idx_yt_acc_channel` (`channel_id`),
              KEY `idx_yt_acc_username` (`username`),
              KEY `idx_yt_acc_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        DB::query(
            "CREATE TABLE IF NOT EXISTS `youtube_videos` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `owner_id` INT NOT NULL DEFAULT 0,
              `account_id` INT UNSIGNED NOT NULL DEFAULT 0,
              `youtube_video_id` VARCHAR(64) NOT NULL DEFAULT '',
              `channel_id` VARCHAR(64) NOT NULL DEFAULT '',
              `title` VARCHAR(255) NOT NULL DEFAULT '',
              `description` MEDIUMTEXT NULL,
              `tags_json` JSON NULL,
              `category_id` VARCHAR(32) NOT NULL DEFAULT '',
              `privacy_status` VARCHAR(32) NOT NULL DEFAULT 'private',
              `publish_at` DATETIME NULL,
              `thumbnail_url` VARCHAR(500) NOT NULL DEFAULT '',
              `duration_iso` VARCHAR(32) NOT NULL DEFAULT '',
              `duration_seconds` INT NOT NULL DEFAULT 0,
              `view_count` BIGINT NOT NULL DEFAULT 0,
              `like_count` BIGINT NOT NULL DEFAULT 0,
              `comment_count` INT NOT NULL DEFAULT 0,
              `favorite_count` BIGINT NOT NULL DEFAULT 0,
              `upload_status` VARCHAR(32) NOT NULL DEFAULT '',
              `privacy_status_api` VARCHAR(32) NOT NULL DEFAULT '',
              `embeddable` TINYINT(1) NOT NULL DEFAULT 1,
              `made_for_kids` TINYINT(1) NOT NULL DEFAULT 0,
              `local_file_path` VARCHAR(500) NOT NULL DEFAULT '',
              `mime_type` VARCHAR(128) NOT NULL DEFAULT '',
              `file_size` BIGINT NOT NULL DEFAULT 0,
              `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
              `published_at` DATETIME NULL,
              `last_synced_at` DATETIME NULL,
              `raw_json` JSON NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_yt_video_platform` (`youtube_video_id`),
              KEY `idx_yt_video_account` (`account_id`),
              KEY `idx_yt_video_owner` (`owner_id`),
              KEY `idx_yt_video_status` (`status`),
              KEY `idx_yt_video_channel` (`channel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        DB::query(
            "CREATE TABLE IF NOT EXISTS `youtube_publish_tasks` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `owner_id` INT NOT NULL DEFAULT 0,
              `account_id` INT UNSIGNED NOT NULL DEFAULT 0,
              `video_id` INT UNSIGNED NULL,
              `name` VARCHAR(255) NOT NULL DEFAULT '',
              `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
              `title` VARCHAR(255) NOT NULL DEFAULT '',
              `description` MEDIUMTEXT NULL,
              `tags_json` JSON NULL,
              `category_id` VARCHAR(32) NOT NULL DEFAULT '22',
              `privacy_status` VARCHAR(32) NOT NULL DEFAULT 'private',
              `publish_at` DATETIME NULL,
              `made_for_kids` TINYINT(1) NOT NULL DEFAULT 0,
              `local_file_path` VARCHAR(500) NOT NULL DEFAULT '',
              `mime_type` VARCHAR(128) NOT NULL DEFAULT 'video/*',
              `file_size` BIGINT NOT NULL DEFAULT 0,
              `youtube_video_id` VARCHAR(64) NOT NULL DEFAULT '',
              `progress_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
              `upload_session_url` VARCHAR(1000) NOT NULL DEFAULT '',
              `error_message` VARCHAR(1000) NOT NULL DEFAULT '',
              `request_json` JSON NULL,
              `response_json` JSON NULL,
              `started_at` DATETIME NULL,
              `finished_at` DATETIME NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_yt_pub_owner` (`owner_id`),
              KEY `idx_yt_pub_account` (`account_id`),
              KEY `idx_yt_pub_video` (`video_id`),
              KEY `idx_yt_pub_status` (`status`),
              KEY `idx_yt_pub_ytvid` (`youtube_video_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        DB::query(
            "CREATE TABLE IF NOT EXISTS `youtube_comments` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `owner_id` INT NOT NULL DEFAULT 0,
              `account_id` INT UNSIGNED NOT NULL DEFAULT 0,
              `video_id` INT UNSIGNED NULL,
              `youtube_video_id` VARCHAR(64) NOT NULL DEFAULT '',
              `youtube_comment_id` VARCHAR(128) NOT NULL DEFAULT '',
              `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
              `parent_youtube_comment_id` VARCHAR(128) NOT NULL DEFAULT '',
              `author_channel_id` VARCHAR(64) NOT NULL DEFAULT '',
              `author_display_name` VARCHAR(255) NOT NULL DEFAULT '',
              `author_profile_image_url` VARCHAR(500) NOT NULL DEFAULT '',
              `author_channel_url` VARCHAR(500) NOT NULL DEFAULT '',
              `text_display` MEDIUMTEXT NULL,
              `text_original` MEDIUMTEXT NULL,
              `like_count` INT NOT NULL DEFAULT 0,
              `total_reply_count` INT NOT NULL DEFAULT 0,
              `is_public` TINYINT(1) NOT NULL DEFAULT 1,
              `can_reply` TINYINT(1) NOT NULL DEFAULT 1,
              `moderation_status` VARCHAR(32) NOT NULL DEFAULT '',
              `published_at` DATETIME NULL,
              `platform_updated_at` DATETIME NULL,
              `pulled_at` DATETIME NULL,
              `raw_json` JSON NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_yt_comment_platform` (`youtube_comment_id`),
              KEY `idx_yt_cmt_account` (`account_id`),
              KEY `idx_yt_cmt_video` (`video_id`),
              KEY `idx_yt_cmt_ytvideo` (`youtube_video_id`),
              KEY `idx_yt_cmt_parent` (`parent_id`),
              KEY `idx_yt_cmt_parent_yt` (`parent_youtube_comment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::ensurePublishTaskExtraColumns();

        DB::query(
            "CREATE TABLE IF NOT EXISTS `youtube_comment_reply_tasks` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `owner_id` INT NOT NULL DEFAULT 0,
              `account_id` INT UNSIGNED NOT NULL DEFAULT 0,
              `video_id` INT UNSIGNED NULL,
              `comment_id` INT UNSIGNED NULL,
              `youtube_video_id` VARCHAR(64) NOT NULL DEFAULT '',
              `parent_youtube_comment_id` VARCHAR(128) NOT NULL DEFAULT '',
              `reply_text` MEDIUMTEXT NOT NULL,
              `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
              `youtube_reply_comment_id` VARCHAR(128) NOT NULL DEFAULT '',
              `error_message` VARCHAR(1000) NOT NULL DEFAULT '',
              `request_json` JSON NULL,
              `response_json` JSON NULL,
              `started_at` DATETIME NULL,
              `finished_at` DATETIME NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_yt_crt_owner` (`owner_id`),
              KEY `idx_yt_crt_account` (`account_id`),
              KEY `idx_yt_crt_video` (`video_id`),
              KEY `idx_yt_crt_comment` (`comment_id`),
              KEY `idx_yt_crt_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** TikTok 素材同步：给发布任务补 material_id / source_cdn_url */
    public static function ensurePublishTaskExtraColumns(): void
    {
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM youtube_publish_tasks') ?: [], 'Field');
        } catch (\Throwable $e) {
            return;
        }
        if (!$cols) {
            return;
        }
        $alters = [
            'material_id' => "ALTER TABLE youtube_publish_tasks ADD COLUMN material_id INT UNSIGNED NULL AFTER video_id",
            'tiktok_account_id' => "ALTER TABLE youtube_publish_tasks ADD COLUMN tiktok_account_id INT UNSIGNED NULL AFTER material_id",
            'source_cdn_url' => "ALTER TABLE youtube_publish_tasks ADD COLUMN source_cdn_url VARCHAR(1000) NOT NULL DEFAULT '' AFTER local_file_path",
        ];
        foreach ($alters as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    DB::query($sql);
                } catch (\Throwable $e) {
                }
            }
        }
        try {
            $idx = DB::select("SHOW INDEX FROM youtube_publish_tasks WHERE Key_name='idx_yt_pub_material'");
            if (!$idx) {
                DB::query('ALTER TABLE youtube_publish_tasks ADD KEY idx_yt_pub_material (material_id)');
            }
        } catch (\Throwable $e) {
        }
        try {
            $idx2 = DB::select("SHOW INDEX FROM youtube_publish_tasks WHERE Key_name='idx_yt_pub_tiktok_acc'");
            if (!$idx2) {
                DB::query('ALTER TABLE youtube_publish_tasks ADD KEY idx_yt_pub_tiktok_acc (tiktok_account_id)');
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param array<string,mixed> $item videos resource
     * @param array<string,mixed> $extra 本地字段覆盖
     */
    public static function upsertVideoFromApi(int $accountId, int $ownerId, array $item, array $extra = []): int
    {
        self::ensureSchema();
        $ytId = (string)($item['id'] ?? '');
        if ($ytId === '') {
            return 0;
        }
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $stats = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
        $status = is_array($item['status'] ?? null) ? $item['status'] : [];
        $details = is_array($item['contentDetails'] ?? null) ? $item['contentDetails'] : [];
        $tags = $snippet['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }
        $durationIso = (string)($details['duration'] ?? '');
        $thumbs = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
        $thumb = (string)(
            $thumbs['maxres']['url']
            ?? $thumbs['standard']['url']
            ?? $thumbs['high']['url']
            ?? $thumbs['medium']['url']
            ?? $thumbs['default']['url']
            ?? ''
        );
        $publishedAt = self::parseYtDatetime((string)($snippet['publishedAt'] ?? ''));
        $publishAt = self::parseYtDatetime((string)($status['publishAt'] ?? ''));
        $uploadStatus = (string)($status['uploadStatus'] ?? '');
        $privacy = (string)($status['privacyStatus'] ?? 'private');
        $localStatus = match ($uploadStatus) {
            'processed' => 'ready',
            'uploaded' => 'processing',
            'rejected', 'failed' => 'failed',
            'deleted' => 'deleted',
            default => ($uploadStatus !== '' ? 'processing' : 'ready'),
        };

        $fields = [
            'owner_id' => $ownerId,
            'account_id' => $accountId,
            'youtube_video_id' => $ytId,
            'channel_id' => (string)($snippet['channelId'] ?? ''),
            'title' => mb_substr((string)($snippet['title'] ?? ''), 0, 255),
            'description' => (string)($snippet['description'] ?? ''),
            'tags_json' => json_encode(array_values($tags), JSON_UNESCAPED_UNICODE),
            'category_id' => (string)($snippet['categoryId'] ?? ''),
            'privacy_status' => $privacy !== '' ? $privacy : 'private',
            'publish_at' => $publishAt,
            'thumbnail_url' => mb_substr($thumb, 0, 500),
            'duration_iso' => $durationIso,
            'duration_seconds' => self::iso8601DurationToSeconds($durationIso),
            'view_count' => (int)($stats['viewCount'] ?? 0),
            'like_count' => (int)($stats['likeCount'] ?? 0),
            'comment_count' => (int)($stats['commentCount'] ?? 0),
            'favorite_count' => (int)($stats['favoriteCount'] ?? 0),
            'upload_status' => $uploadStatus,
            'privacy_status_api' => $privacy,
            'embeddable' => !empty($status['embeddable']) ? 1 : 0,
            'made_for_kids' => !empty($status['madeForKids']) || !empty($status['selfDeclaredMadeForKids']) ? 1 : 0,
            'status' => (string)($extra['status'] ?? $localStatus),
            'published_at' => $publishedAt,
            'last_synced_at' => date('Y-m-d H:i:s'),
            'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE),
        ];
        foreach (['local_file_path', 'mime_type', 'file_size'] as $k) {
            if (array_key_exists($k, $extra)) {
                $fields[$k] = $extra[$k];
            }
        }

        $exist = DB::selectOne(
            'SELECT id FROM youtube_videos WHERE account_id=? AND youtube_video_id=? LIMIT 1',
            [$accountId, $ytId]
        );
        if ($exist) {
            $sets = [];
            $params = [];
            foreach ($fields as $col => $val) {
                if (in_array($col, ['account_id', 'youtube_video_id'], true)) {
                    continue;
                }
                $sets[] = "`{$col}`=?";
                $params[] = $val;
            }
            $params[] = (int)$exist['id'];
            DB::query(
                'UPDATE youtube_videos SET ' . implode(', ', $sets) . ', updated_at=CURRENT_TIMESTAMP WHERE id=?',
                $params
            );
            return (int)$exist['id'];
        }

        $cols = array_keys($fields);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        DB::query(
            'INSERT INTO youtube_videos (`' . implode('`,`', $cols) . '`) VALUES (' . $placeholders . ')',
            array_values($fields)
        );
        return (int)DB::getPdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $comment comments resource
     * @param array<string,mixed>|null $thread commentThreads 外层（顶层时带 totalReplyCount）
     */
    public static function upsertCommentFromApi(
        int $accountId,
        int $ownerId,
        int $localVideoId,
        string $youtubeVideoId,
        array $comment,
        int $parentLocalId = 0,
        ?array $thread = null
    ): int {
        self::ensureSchema();
        $ytCommentId = (string)($comment['id'] ?? '');
        if ($ytCommentId === '') {
            return 0;
        }
        $snippet = is_array($comment['snippet'] ?? null) ? $comment['snippet'] : [];
        $parentYt = (string)($snippet['parentId'] ?? '');
        if ($parentLocalId > 0 && $parentYt === '') {
            $p = DB::selectOne('SELECT youtube_comment_id FROM youtube_comments WHERE id=?', [$parentLocalId]);
            $parentYt = (string)($p['youtube_comment_id'] ?? '');
        }
        $totalReply = 0;
        $canReply = 1;
        $isPublic = 1;
        if (is_array($thread)) {
            $totalReply = (int)($thread['snippet']['totalReplyCount'] ?? 0);
            $canReply = !empty($thread['snippet']['canReply']) ? 1 : 0;
            $isPublic = !isset($thread['snippet']['isPublic']) || !empty($thread['snippet']['isPublic']) ? 1 : 0;
            if ($youtubeVideoId === '') {
                $youtubeVideoId = (string)($thread['snippet']['videoId'] ?? '');
            }
        }
        if ($youtubeVideoId === '') {
            $youtubeVideoId = (string)($snippet['videoId'] ?? '');
        }

        $fields = [
            'owner_id' => $ownerId,
            'account_id' => $accountId,
            'video_id' => $localVideoId > 0 ? $localVideoId : null,
            'youtube_video_id' => $youtubeVideoId,
            'youtube_comment_id' => $ytCommentId,
            'parent_id' => $parentLocalId,
            'parent_youtube_comment_id' => $parentYt,
            'author_channel_id' => (string)($snippet['authorChannelId']['value'] ?? $snippet['authorChannelId'] ?? ''),
            'author_display_name' => mb_substr((string)($snippet['authorDisplayName'] ?? ''), 0, 255),
            'author_profile_image_url' => mb_substr((string)($snippet['authorProfileImageUrl'] ?? ''), 0, 500),
            'author_channel_url' => mb_substr((string)($snippet['authorChannelUrl'] ?? ''), 0, 500),
            'text_display' => (string)($snippet['textDisplay'] ?? ''),
            'text_original' => (string)($snippet['textOriginal'] ?? ''),
            'like_count' => (int)($snippet['likeCount'] ?? 0),
            'total_reply_count' => $totalReply,
            'is_public' => $isPublic,
            'can_reply' => $canReply,
            'moderation_status' => (string)($snippet['moderationStatus'] ?? ''),
            'published_at' => self::parseYtDatetime((string)($snippet['publishedAt'] ?? '')),
            'platform_updated_at' => self::parseYtDatetime((string)($snippet['updatedAt'] ?? '')),
            'pulled_at' => date('Y-m-d H:i:s'),
            'raw_json' => json_encode($comment, JSON_UNESCAPED_UNICODE),
        ];

        $exist = DB::selectOne(
            'SELECT id FROM youtube_comments WHERE youtube_comment_id=? LIMIT 1',
            [$ytCommentId]
        );
        if ($exist) {
            $sets = [];
            $params = [];
            foreach ($fields as $col => $val) {
                if ($col === 'youtube_comment_id') {
                    continue;
                }
                $sets[] = "`{$col}`=?";
                $params[] = $val;
            }
            $params[] = (int)$exist['id'];
            DB::query(
                'UPDATE youtube_comments SET ' . implode(', ', $sets) . ', updated_at=CURRENT_TIMESTAMP WHERE id=?',
                $params
            );
            return (int)$exist['id'];
        }

        $cols = array_keys($fields);
        DB::query(
            'INSERT INTO youtube_comments (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
            array_values($fields)
        );
        return (int)DB::getPdo()->lastInsertId();
    }

    public static function parseYtDatetime(string $iso): ?string
    {
        $iso = trim($iso);
        if ($iso === '') {
            return null;
        }
        $ts = strtotime($iso);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    public static function iso8601DurationToSeconds(string $iso): int
    {
        if ($iso === '' || !preg_match('/^P/', $iso)) {
            return 0;
        }
        try {
            $interval = new \DateInterval($iso);
            return ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
