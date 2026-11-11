<?php
declare(strict_types=1);

namespace App\Services\YouTube;

use App\Core\Database\DB;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiPromptCatalog;
use App\Services\Ai\AiPromptHelper;

/**
 * 计划任务：处理未回复的观众顶层评论
 * 1) AI 判定恶意低俗辱骂诋毁 → 删除
 * 2) 否则 AI 自动回复
 */
final class YouTubeCommentModerateService
{
    /**
     * @return array{
     *   scanned:int,deleted:int,replied:int,failed:int,skipped:int,
     *   items:list<array<string,mixed>>
     * }
     */
    public static function tick(int $limit = 10): array
    {
        YouTubeSchema::ensureSchema();
        YouTubeSchema::ensureCommentCreatorColumns();
        YouTubeSchema::ensureCommentAutoHandleColumns();
        AiPromptCatalog::ensure();

        $limit = max(1, min(50, $limit));
        $rows = DB::select(
            "SELECT c.*
             FROM youtube_comments c
             INNER JOIN youtube_accounts a ON a.id=c.account_id
             WHERE c.parent_id=0
               AND IFNULL(c.is_creator_comment,0)=0
               AND IFNULL(c.creator_replied,0)=0
               AND IFNULL(c.moderation_status,'') NOT IN ('deleted','rejected')
               AND IFNULL(c.auto_handle_status,'') IN ('','error')
               AND TRIM(IFNULL(a.refresh_token,''))<>''
             ORDER BY c.id ASC
             LIMIT {$limit}"
        ) ?: [];

        $out = [
            'scanned' => count($rows),
            'deleted' => 0,
            'replied' => 0,
            'failed' => 0,
            'skipped' => 0,
            'items' => [],
        ];

        foreach ($rows as $row) {
            $commentId = (int)($row['id'] ?? 0);
            $accountId = (int)($row['account_id'] ?? 0);
            if ($commentId <= 0 || $accountId <= 0) {
                $out['skipped']++;
                $out['items'][] = ['id' => $commentId, 'ok' => false, 'action' => 'skip', 'reason' => 'invalid'];
                continue;
            }

            DB::query(
                "UPDATE youtube_comments
                 SET auto_handle_status='processing', auto_handle_note='', updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND IFNULL(auto_handle_status,'') IN ('','error')",
                [$commentId]
            );
            $claimed = DB::selectOne('SELECT id, auto_handle_status FROM youtube_comments WHERE id=?', [$commentId]);
            if (!$claimed || (string)($claimed['auto_handle_status'] ?? '') !== 'processing') {
                $out['skipped']++;
                $out['items'][] = ['id' => $commentId, 'ok' => false, 'action' => 'skip', 'reason' => 'claim missed'];
                continue;
            }

            try {
                $result = self::processOne($row);
                $action = (string)($result['action'] ?? '');
                if ($action === 'deleted') {
                    $out['deleted']++;
                } elseif ($action === 'replied') {
                    $out['replied']++;
                } else {
                    $out['skipped']++;
                }
                $out['items'][] = array_merge(['id' => $commentId, 'ok' => true], $result);
            } catch (\Throwable $e) {
                self::markHandle(
                    $commentId,
                    'error',
                    mb_substr($e->getMessage(), 0, 500)
                );
                $out['failed']++;
                $out['items'][] = [
                    'id' => $commentId,
                    'ok' => false,
                    'action' => 'error',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $comment
     * @return array<string,mixed>
     */
    private static function processOne(array $comment): array
    {
        $commentId = (int)$comment['id'];
        $accountId = (int)$comment['account_id'];
        $acc = DB::selectOne('SELECT * FROM youtube_accounts WHERE id=?', [$accountId]);
        if (!$acc) {
            throw new \RuntimeException('YouTube 账号不存在 #' . $accountId);
        }
        if (trim((string)($acc['refresh_token'] ?? '')) === '') {
            throw new \RuntimeException('YouTube 账号未授权 #' . $accountId);
        }

        $video = self::loadVideo($comment, $accountId);
        $commentText = trim((string)($comment['text_original'] ?? ''));
        if ($commentText === '') {
            $commentText = trim(strip_tags((string)($comment['text_display'] ?? '')));
        }
        if ($commentText === '') {
            self::markHandle($commentId, 'skipped', '评论内容为空');
            return ['action' => 'skipped', 'reason' => 'empty comment'];
        }

        $judge = self::judgeToxicity($commentText, (string)($comment['author_display_name'] ?? ''), (string)($video['title'] ?? ''));
        if (!empty($judge['is_toxic'])) {
            $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
            $svc = new YouTubeApiService($acc);
            $ytCid = trim((string)($comment['youtube_comment_id'] ?? ''));
            if ($ytCid === '') {
                throw new \RuntimeException('缺少评论平台 ID');
            }
            $del = $svc->deleteComment($ytCid);
            $note = '恶意评论已删除：' . (string)($judge['reason'] ?? '');
            self::markHandle($commentId, 'deleted', mb_substr($note, 0, 500));
            return [
                'action' => 'deleted',
                'mode' => $del['mode'] ?? '',
                'reason' => (string)($judge['reason'] ?? ''),
                'confidence' => $judge['confidence'] ?? null,
            ];
        }

        $replyText = self::generateReply($acc, $comment, $video, $commentText);
        if ($replyText === '') {
            throw new \RuntimeException('AI 未生成有效回复');
        }

        $acc = YouTubeOAuthConfigService::applyToAccountRow($acc);
        $svc = new YouTubeApiService($acc);
        $parentYt = trim((string)($comment['youtube_comment_id'] ?? ''));
        $svc->replyToComment($parentYt, $replyText);

        $creatorChannelId = trim((string)($acc['channel_id'] ?? ''));
        if ($creatorChannelId !== '') {
            YouTubeSchema::refreshCreatorCommentFlags(
                $accountId,
                (int)($comment['video_id'] ?? 0),
                (string)($comment['youtube_video_id'] ?? ''),
                $creatorChannelId
            );
        }
        self::markHandle($commentId, 'replied', mb_substr('已自动回复：' . $replyText, 0, 500));
        return [
            'action' => 'replied',
            'reply' => $replyText,
            'reason' => (string)($judge['reason'] ?? '非恶意'),
        ];
    }

    /**
     * @return array{is_toxic:bool,confidence:?float,reason:string,labels:list<string>}
     */
    private static function judgeToxicity(string $commentText, string $author, string $videoTitle): array
    {
        $tpl = DB::selectOne(
            "SELECT * FROM ai_prompt_templates WHERE code='youtube_comment_toxicity' AND enabled=1 LIMIT 1"
        );
        $prompt = trim((string)($tpl['content'] ?? ''));
        if ($prompt === '') {
            $prompt = "Decide if this YouTube comment is toxic (insult/vulgar/slander/hate/threat).\n"
                . "Comment: {{comment_text}}\nAuthor: {{author}}\nVideo: {{video_title}}\n"
                . "Return JSON: {\"is_toxic\":true|false,\"confidence\":0.0,\"reason\":\"中文原因\",\"labels\":[]}";
        }
        $vars = [
            'comment_text' => $commentText,
            'author' => $author,
            'video_title' => $videoTitle,
        ];
        $rendered = AiPromptHelper::render($prompt, $vars, false);
        $result = AiClient::chat(
            [
                ['role' => 'system', 'content' => 'You are a strict but fair comment moderator. Return JSON only.'],
                ['role' => 'user', 'content' => $rendered],
            ],
            ['operation' => 'youtube_comment_toxicity', 'timeout' => 45]
        );
        $raw = trim((string)($result['content'] ?? ''));
        $parsed = self::parseJsonObject($raw);
        $isToxic = !empty($parsed['is_toxic']) || !empty($parsed['toxic']) || !empty($parsed['should_delete']);
        $confidence = null;
        if (isset($parsed['confidence']) && is_numeric($parsed['confidence'])) {
            $confidence = (float)$parsed['confidence'];
        }
        $labels = [];
        if (is_array($parsed['labels'] ?? null)) {
            foreach ($parsed['labels'] as $lb) {
                if (is_scalar($lb) && trim((string)$lb) !== '') {
                    $labels[] = trim((string)$lb);
                }
            }
        }
        return [
            'is_toxic' => (bool)$isToxic,
            'confidence' => $confidence,
            'reason' => trim((string)($parsed['reason'] ?? $parsed['message'] ?? '')),
            'labels' => $labels,
            'raw' => $raw,
        ];
    }

    /**
     * @param array<string,mixed> $acc
     * @param array<string,mixed> $comment
     * @param array<string,mixed>|null $video
     */
    private static function generateReply(array $acc, array $comment, ?array $video, string $commentText): string
    {
        $tpl = DB::selectOne(
            "SELECT * FROM ai_prompt_templates WHERE code='youtube_comment_reply' AND enabled=1 LIMIT 1"
        );
        $prompt = trim((string)($tpl['content'] ?? ''));
        if ($prompt === '') {
            $prompt = "Reply as the YouTube creator to this comment.\n"
                . "Channel: {{channel_title}}\nVideo: {{video_title}}\nAuthor: {{author}}\nComment: {{comment_text}}\n"
                . "Tone: {{tone}}\nLanguage: {{language}}\nOutput reply text only.";
        }
        $vars = [
            'comment_text' => $commentText,
            'author' => (string)($comment['author_display_name'] ?? ''),
            'video_title' => (string)($video['title'] ?? ''),
            'video_desc' => mb_substr((string)($video['description'] ?? ''), 0, 800),
            'channel_title' => (string)($acc['channel_title'] ?? $acc['nickname'] ?? 'my channel'),
            'tone' => 'friendly and professional',
            'language' => 'same as the comment (prefer English if unclear)',
        ];
        $rendered = AiPromptHelper::render($prompt, $vars, false);
        $result = AiClient::chat(
            [
                ['role' => 'system', 'content' => 'You write YouTube creator comment replies. Output the reply text alone — no quotes, no markdown.'],
                ['role' => 'user', 'content' => $rendered],
            ],
            ['operation' => 'youtube_comment_reply', 'timeout' => 45]
        );
        $reply = trim((string)($result['content'] ?? ''));
        $reply = preg_replace('/^["“”\'`]+|["“”\'`]+$/u', '', $reply) ?? $reply;
        $reply = trim(preg_replace('/\s+/u', ' ', $reply) ?? $reply);
        return $reply;
    }

    /** @param array<string,mixed> $comment */
    private static function loadVideo(array $comment, int $accountId): ?array
    {
        $localVid = (int)($comment['video_id'] ?? 0);
        $ytVid = (string)($comment['youtube_video_id'] ?? '');
        if ($localVid > 0) {
            $video = DB::selectOne('SELECT * FROM youtube_videos WHERE id=? AND account_id=? LIMIT 1', [$localVid, $accountId]);
            if ($video) {
                return $video;
            }
        }
        if ($ytVid !== '') {
            return DB::selectOne(
                'SELECT * FROM youtube_videos WHERE youtube_video_id=? AND account_id=? LIMIT 1',
                [$ytVid, $accountId]
            ) ?: null;
        }
        return null;
    }

    private static function markHandle(int $commentId, string $status, string $note = ''): void
    {
        DB::query(
            "UPDATE youtube_comments
             SET auto_handle_status=?, auto_handle_note=?, auto_handled_at=NOW(), updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$status, $note, $commentId]
        );
    }

    /** @return array<string,mixed> */
    private static function parseJsonObject(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
