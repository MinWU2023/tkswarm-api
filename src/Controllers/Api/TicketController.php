<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;

/**
 * 工单反馈：客户端提交 + 后台处理
 */
class TicketController extends Controller
{
    public function submit(Request $request): Response
    {
        $d = $request->json() ?: [];
        $subject = trim((string)($d['subject'] ?? ''));
        $content = trim((string)($d['content'] ?? ''));
        if ($subject === '' || $content === '') {
            return $this->error('请填写主题与问题描述');
        }
        $name = trim((string)($d['contactName'] ?? $d['contact_name'] ?? ''));
        $phone = trim((string)($d['contactPhone'] ?? $d['contact_phone'] ?? ''));
        $email = trim((string)($d['contactEmail'] ?? $d['contact_email'] ?? ''));
        $platform = trim((string)($d['clientPlatform'] ?? $d['client_platform'] ?? ''));
        $version = trim((string)($d['clientVersion'] ?? $d['client_version'] ?? ''));
        $priority = trim((string)($d['priority'] ?? 'normal')) ?: 'normal';
        $ticketNo = 'T' . date('YmdHis') . substr((string)mt_rand(100, 999), 0, 3);

        DB::query(
            'INSERT INTO support_tickets
             (ticket_no, contact_name, contact_phone, contact_email, subject, content, status, priority, client_platform, client_version)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$ticketNo, $name, $phone, $email, $subject, $content, 'open', $priority, $platform, $version]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        return $this->success([
            'id' => $id,
            'ticketNo' => $ticketNo,
            'message' => '提交成功，我们会尽快处理',
        ]);
    }

    public function list(Request $request): Response
    {
        DataScope::ensureColumns();
        $status = trim((string)$request->get('status', ''));
        $sql = 'SELECT * FROM support_tickets WHERE 1=1';
        $params = [];
        if ($status !== '') {
            $sql .= ' AND status=?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT 500';
        $rows = DB::select($sql, $params);
        return $this->success(array_map([$this, 'mapRow'], $rows ?: []));
    }

    public function detail(Request $request, string $id): Response
    {
        $row = DB::selectOne('SELECT * FROM support_tickets WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('工单不存在', 404);
        }
        return $this->success($this->mapRow($row));
    }

    public function update(Request $request, string $id): Response
    {
        $row = DB::selectOne('SELECT * FROM support_tickets WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('工单不存在', 404);
        }
        $d = $request->json() ?: [];
        $status = trim((string)($d['status'] ?? $row['status'])) ?: 'open';
        $priority = trim((string)($d['priority'] ?? $row['priority'])) ?: 'normal';
        $reply = array_key_exists('reply', $d) ? (string)$d['reply'] : (string)($row['reply'] ?? '');
        $repliedAt = $row['replied_at'];
        if ($reply !== '' && ($reply !== (string)($row['reply'] ?? '') || in_array($status, ['replied', 'closed'], true))) {
            $repliedAt = $repliedAt ?: date('Y-m-d H:i:s');
        }
        DB::query(
            'UPDATE support_tickets SET status=?, priority=?, reply=?, replied_at=? WHERE id=?',
            [$status, $priority, $reply, $repliedAt, (int)$id]
        );
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM support_tickets WHERE id=?', [(int)$id])));
    }

    public function delete(Request $request, string $id): Response
    {
        DB::query('DELETE FROM support_tickets WHERE id=?', [(int)$id]);
        return $this->success(true);
    }

    private function mapRow(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'ticketNo' => (string)($r['ticket_no'] ?? ''),
            'contactName' => (string)($r['contact_name'] ?? ''),
            'contactPhone' => (string)($r['contact_phone'] ?? ''),
            'contactEmail' => (string)($r['contact_email'] ?? ''),
            'subject' => (string)$r['subject'],
            'content' => (string)$r['content'],
            'status' => (string)($r['status'] ?? 'open'),
            'priority' => (string)($r['priority'] ?? 'normal'),
            'reply' => (string)($r['reply'] ?? ''),
            'repliedAt' => $r['replied_at'] ?? null,
            'clientPlatform' => (string)($r['client_platform'] ?? ''),
            'clientVersion' => (string)($r['client_version'] ?? ''),
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }
}
