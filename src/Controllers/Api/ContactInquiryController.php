<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Database\DB;
use App\Core\Request;
use App\Core\Response;

/**
 * 官网联系我们：公开提交 + 后台列表处理
 */
class ContactInquiryController extends Controller
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            DB::query(
                "CREATE TABLE IF NOT EXISTS contact_inquiries (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  name VARCHAR(64) NOT NULL DEFAULT '',
                  company VARCHAR(128) NOT NULL DEFAULT '',
                  phone VARCHAR(64) NOT NULL DEFAULT '',
                  email VARCHAR(128) NOT NULL DEFAULT '',
                  account_scale VARCHAR(32) NOT NULL DEFAULT '',
                  message TEXT NOT NULL,
                  source VARCHAR(32) NOT NULL DEFAULT 'landing',
                  page_url VARCHAR(255) NOT NULL DEFAULT '',
                  status VARCHAR(16) NOT NULL DEFAULT 'new',
                  remark VARCHAR(500) NOT NULL DEFAULT '',
                  ip VARCHAR(64) NOT NULL DEFAULT '',
                  user_agent VARCHAR(255) NOT NULL DEFAULT '',
                  handled_at DATETIME NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  KEY idx_ci_status_created (status, created_at),
                  KEY idx_ci_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
        }
    }

    public function submit(Request $request): Response
    {
        self::ensureSchema();
        $d = $request->json() ?: [];
        $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 64);
        $phone = mb_substr(trim((string)($d['phone'] ?? '')), 0, 64);
        $message = trim((string)($d['message'] ?? ''));
        if ($name === '' || $phone === '' || $message === '') {
            return $this->error('请填写姓名、联系方式与需求说明', 422);
        }
        if (mb_strlen($message) > 2000) {
            return $this->error('需求说明过长', 422);
        }

        $company = mb_substr(trim((string)($d['company'] ?? '')), 0, 128);
        $email = mb_substr(trim((string)($d['email'] ?? '')), 0, 128);
        $scale = mb_substr(trim((string)($d['accountScale'] ?? $d['account_scale'] ?? '')), 0, 32);
        $source = mb_substr(trim((string)($d['source'] ?? 'landing')), 0, 32) ?: 'landing';
        $pageUrl = mb_substr(trim((string)($d['pageUrl'] ?? $d['page_url'] ?? '')), 0, 255);
        $ip = mb_substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $ua = mb_substr((string)($request->getHeader('user-agent') ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

        DB::query(
            'INSERT INTO contact_inquiries
             (name, company, phone, email, account_scale, message, source, page_url, status, ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$name, $company, $phone, $email, $scale, $message, $source, $pageUrl, 'new', $ip, $ua]
        );
        $id = (int)DB::getPdo()->lastInsertId();

        return $this->success([
            'id' => $id,
            'message' => '提交成功，我们会尽快联系您',
        ], '提交成功');
    }

    public function list(Request $request): Response
    {
        self::ensureSchema();
        $status = trim((string)$request->get('status', ''));
        $keyword = trim((string)$request->get('keyword', $request->get('q', '')));
        $sql = 'SELECT * FROM contact_inquiries WHERE 1=1';
        $params = [];
        if ($status !== '') {
            $sql .= ' AND status=?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $sql .= ' AND (name LIKE ? OR phone LIKE ? OR company LIKE ? OR email LIKE ? OR message LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY id DESC LIMIT 500';
        $rows = DB::select($sql, $params) ?: [];
        return $this->success(array_map([$this, 'mapRow'], $rows));
    }

    public function detail(Request $request, string $id): Response
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM contact_inquiries WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        return $this->success($this->mapRow($row));
    }

    public function update(Request $request, string $id): Response
    {
        self::ensureSchema();
        $row = DB::selectOne('SELECT * FROM contact_inquiries WHERE id=?', [(int)$id]);
        if (!$row) {
            return $this->error('记录不存在', 404);
        }
        $d = $request->json() ?: [];
        $status = trim((string)($d['status'] ?? $row['status'])) ?: 'new';
        if (!in_array($status, ['new', 'processing', 'done', 'spam'], true)) {
            $status = 'new';
        }
        $remark = array_key_exists('remark', $d)
            ? mb_substr(trim((string)$d['remark']), 0, 500)
            : (string)($row['remark'] ?? '');
        $handledAt = $row['handled_at'];
        if (in_array($status, ['done', 'spam'], true) && !$handledAt) {
            $handledAt = date('Y-m-d H:i:s');
        }
        if ($status === 'new' || $status === 'processing') {
            // 重新打开时不清 handled_at，便于追溯；仅 done/spam 写入
        }
        DB::query(
            'UPDATE contact_inquiries SET status=?, remark=?, handled_at=? WHERE id=?',
            [$status, $remark, $handledAt, (int)$id]
        );
        return $this->success($this->mapRow(DB::selectOne('SELECT * FROM contact_inquiries WHERE id=?', [(int)$id])));
    }

    public function delete(Request $request, string $id): Response
    {
        self::ensureSchema();
        DB::query('DELETE FROM contact_inquiries WHERE id=?', [(int)$id]);
        return $this->success(true, '已删除');
    }

    /** @param array<string,mixed> $r */
    private function mapRow(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'name' => (string)($r['name'] ?? ''),
            'company' => (string)($r['company'] ?? ''),
            'phone' => (string)($r['phone'] ?? ''),
            'email' => (string)($r['email'] ?? ''),
            'accountScale' => (string)($r['account_scale'] ?? ''),
            'message' => (string)($r['message'] ?? ''),
            'source' => (string)($r['source'] ?? ''),
            'pageUrl' => (string)($r['page_url'] ?? ''),
            'status' => (string)($r['status'] ?? 'new'),
            'remark' => (string)($r['remark'] ?? ''),
            'ip' => (string)($r['ip'] ?? ''),
            'userAgent' => (string)($r['user_agent'] ?? ''),
            'handledAt' => $r['handled_at'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }
}
