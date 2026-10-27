<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;

class UidController extends Controller
{
    public function groups(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $filters = ["g.type='uid'"];
        $params = [];
        $this->applyOwnerFilter('g.owner_id', $filters, $params);
        $where = 'WHERE ' . implode(' AND ', $filters);
        $rows = DB::select(
            "SELECT g.id,g.name,g.description,
                (SELECT COUNT(*) FROM uid_targets u WHERE u.group_id=g.id) AS total,
                (SELECT COUNT(*) FROM uid_targets u WHERE u.group_id=g.id AND u.status='unused') AS unused,
                (SELECT COUNT(*) FROM uid_targets u WHERE u.group_id=g.id AND u.status='used') AS used
             FROM `groups` g {$where} ORDER BY g.id DESC",
            $params
        );
        return $this->success($rows);
    }

    public function list(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $groupId = (int)$request->get('groupId', 0);
        if ($groupId < 1) {
            return $this->error('请选择 UID 分组', 400);
        }
        $group = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='uid'", [$groupId]);
        if ($err = $this->assertOwns($group)) {
            return $err;
        }
        $status = trim((string)$request->get('status', ''));
        $keyword = trim((string)$request->get('keyword', ''));
        $filters = ['group_id=?'];
        $params = [$groupId];
        if (in_array($status, ['unused', 'used', 'failed'], true)) {
            $filters[] = 'status=?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $filters[] = "(uid LIKE ? OR IFNULL(username,'') LIKE ?)";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }
        $this->applyOwnerFilter('owner_id', $filters, $params);
        $where = implode(' AND ', $filters);
        $page = max(1, (int)$request->input('page', $request->get('page', 1)));
        $pageSize = (int)$request->input('pageSize', $request->input('page_size', $request->get('pageSize', 20)));
        $pageSize = max(1, min(100, $pageSize > 0 ? $pageSize : 20));
        $totalRow = DB::selectOne("SELECT COUNT(*) AS c FROM uid_targets WHERE {$where}", $params);
        $total = (int)($totalRow['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $items = DB::select(
            "SELECT id,group_id,uid,username,status,used_by_account_id,used_task_id,used_at,created_at
             FROM uid_targets WHERE {$where} ORDER BY id DESC LIMIT {$offset}, {$pageSize}",
            $params
        );
        $statsFilters = ['group_id=?'];
        $statsParams = [$groupId];
        $this->applyOwnerFilter('owner_id', $statsFilters, $statsParams);
        $statsWhere = implode(' AND ', $statsFilters);
        $stats = DB::selectOne(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status='unused' THEN 1 ELSE 0 END) AS unused,
                SUM(CASE WHEN status='used' THEN 1 ELSE 0 END) AS used,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
             FROM uid_targets WHERE {$statsWhere}",
            $statsParams
        );
        return $this->success([
            'items' => $items,
            'stats' => [
                'total' => (int)($stats['total'] ?? 0),
                'unused' => (int)($stats['unused'] ?? 0),
                'used' => (int)($stats['used'] ?? 0),
                'failed' => (int)($stats['failed'] ?? 0),
            ],
            'keyword' => $keyword,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => $pages,
        ]);
    }

    public function import(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $groupId = (int)$request->input('groupId', 0);
        $content = (string)$request->input('content', '');
        $mode = (string)$request->input('mode', 'append');
        if (!in_array($mode, ['append', 'replace', 'dedupe'], true)) {
            $mode = 'append';
        }
        $group = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='uid'", [$groupId]);
        if ($err = $this->assertOwns($group)) {
            return $err;
        }
        $rows = $this->parseLines($content);
        if (!$rows) {
            return $this->error('没有可导入的 UID', 400);
        }
        $ownerId = $this->writeOwnerId();
        $cleared = 0;
        $imported = 0;
        $duplicates = 0;
        if ($mode === 'replace') {
            [$ownSql, $ownParams] = $this->ownerSql('owner_id');
            $stmt = DB::query("DELETE FROM uid_targets WHERE group_id=?{$ownSql}", array_merge([$groupId], $ownParams));
            $cleared = $stmt->rowCount();
        }
        if ($mode === 'dedupe') {
            [$ownSql, $ownParams] = $this->ownerSql('owner_id');
            $existing = DB::select(
                "SELECT LOWER(TRIM(uid)) AS u FROM uid_targets WHERE group_id=?{$ownSql}",
                array_merge([$groupId], $ownParams)
            );
            $seen = [];
            foreach ($existing as $e) {
                $seen[$e['u']] = true;
            }
            foreach ($rows as $row) {
                $key = mb_strtolower(trim($row['uid']));
                if (isset($seen[$key])) {
                    $duplicates++;
                    continue;
                }
                $seen[$key] = true;
                try {
                    DB::query(
                        'INSERT INTO uid_targets(group_id,uid,username,owner_id) VALUES (?,?,?,?)',
                        [$groupId, $row['uid'], $row['username'], $ownerId]
                    );
                    $imported++;
                } catch (\Throwable $e) {
                    $duplicates++;
                }
            }
        } else {
            foreach ($rows as $row) {
                try {
                    DB::query(
                        'INSERT INTO uid_targets(group_id,uid,username,owner_id) VALUES (?,?,?,?)',
                        [$groupId, $row['uid'], $row['username'], $ownerId]
                    );
                    $imported++;
                } catch (\Throwable $e) {
                    $duplicates++;
                }
            }
        }
        return $this->success([
            'imported' => $imported,
            'duplicates' => $duplicates,
            'cleared' => $cleared,
            'total' => count($rows),
            'mode' => $mode,
        ], 'UID 导入完成');
    }

    public function export(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $groupId = (int)$request->get('groupId', 0);
        if ($groupId < 1) {
            return $this->error('请选择 UID 分组', 400);
        }
        $group = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='uid'", [$groupId]);
        if ($err = $this->assertOwns($group)) {
            return $err;
        }
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $rows = DB::select(
            "SELECT uid,username FROM uid_targets WHERE group_id=? AND status='unused'{$ownSql} ORDER BY id",
            array_merge([$groupId], $ownParams)
        );
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = $row['username'] ? ($row['uid'] . '----' . $row['username']) : $row['uid'];
        }
        $text = implode("\n", $lines);
        return Response::make($text, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="unused-uids.txt"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function dedupe(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $groupId = (int)$request->input('groupId', 0);
        $group = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='uid'", [$groupId]);
        if ($err = $this->assertOwns($group)) {
            return $err;
        }
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $before = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM uid_targets WHERE group_id=?{$ownSql}",
            array_merge([$groupId], $ownParams)
        )['c'] ?? 0);
        DB::query(
            "DELETE FROM uid_targets WHERE group_id=?{$ownSql} AND id NOT IN (
                SELECT mid FROM (
                  SELECT MIN(id) AS mid FROM uid_targets WHERE group_id=?{$ownSql} GROUP BY LOWER(TRIM(uid))
                ) t
             )",
            array_merge([$groupId], $ownParams, [$groupId], $ownParams)
        );
        $after = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM uid_targets WHERE group_id=?{$ownSql}",
            array_merge([$groupId], $ownParams)
        )['c'] ?? 0);
        $removed = $before - $after;
        return $this->success(
            ['removed' => $removed, 'remaining' => $after],
            $removed === 0 ? '没有重复 UID' : "已去掉 {$removed} 条重复 UID"
        );
    }

    public function cleanup(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $groupId = (int)$request->input('groupId', 0);
        $status = (string)$request->input('status', '');
        if (!in_array($status, ['used', 'unused', 'failed'], true)) {
            return $this->error('状态无效', 422);
        }
        $group = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='uid'", [$groupId]);
        if ($err = $this->assertOwns($group)) {
            return $err;
        }
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $stmt = DB::query(
            "DELETE FROM uid_targets WHERE group_id=? AND status=?{$ownSql}",
            array_merge([$groupId, $status], $ownParams)
        );
        return $this->success(['deleted' => $stmt->rowCount(), 'status' => $status], '已清理 ' . $stmt->rowCount() . ' 条');
    }

    public function keepUnused(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $groupId = (int)$request->input('groupId', 0);
        $group = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='uid'", [$groupId]);
        if ($err = $this->assertOwns($group)) {
            return $err;
        }
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $stmt = DB::query(
            "DELETE FROM uid_targets WHERE group_id=? AND status<>'unused'{$ownSql}",
            array_merge([$groupId], $ownParams)
        );
        $remaining = (int)(DB::selectOne(
            "SELECT COUNT(*) AS c FROM uid_targets WHERE group_id=? AND status='unused'{$ownSql}",
            array_merge([$groupId], $ownParams)
        )['c'] ?? 0);
        return $this->success(
            ['deleted' => $stmt->rowCount(), 'remaining' => $remaining],
            "私信成功去重完成，剩余未使用 {$remaining}"
        );
    }

    public function clearGroup(Request $request, string $groupId): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $group = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='uid'", [(int)$groupId]);
        if ($err = $this->assertOwns($group)) {
            return $err;
        }
        [$ownSql, $ownParams] = $this->ownerSql('owner_id');
        $stmt = DB::query(
            "DELETE FROM uid_targets WHERE group_id=?{$ownSql}",
            array_merge([(int)$groupId], $ownParams)
        );
        return $this->success(['deleted' => $stmt->rowCount()], '已按组删除 UID');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne('SELECT id, uid, owner_id FROM uid_targets WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($row)) {
            return $err;
        }
        DB::query('DELETE FROM uid_targets WHERE id=?', [(int)$id]);
        return $this->success(['id' => (int)$row['id'], 'uid' => $row['uid']], '已删除该 UID');
    }

    private function parseLines(string $content): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/----|\t|,/', $line);
            $uid = trim((string)($parts[0] ?? ''));
            $username = trim((string)($parts[1] ?? ''));
            if ($uid !== '') {
                $out[] = ['uid' => $uid, 'username' => $username];
            }
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
        DB::query("CREATE TABLE IF NOT EXISTS uid_targets (
          id INT AUTO_INCREMENT PRIMARY KEY,
          group_id INT NOT NULL,
          uid VARCHAR(191) NOT NULL,
          username VARCHAR(512) NOT NULL DEFAULT '',
          status VARCHAR(32) NOT NULL DEFAULT 'unused',
          used_by_account_id INT NULL,
          used_task_id INT NULL,
          used_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_uid_group (group_id, uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
