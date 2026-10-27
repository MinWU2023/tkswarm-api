<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\DataScope;
use PDOException;

class MessageTemplateController extends Controller
{
    /** @var string[]|null */
    private static ?array $columns = null;

    public function list(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $groupId = (int)$request->get('groupId', 0);
        // 绝对不写 t.name：旧库只有 title 时 SELECT t.* 仍可用
        $filters = [];
        $params = [];
        if ($groupId > 0) {
            $filters[] = 't.group_id=?';
            $params[] = $groupId;
        }
        $this->applyOwnerFilter('t.owner_id', $filters, $params);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $rows = DB::select(
            "SELECT t.*, g.name AS group_name
             FROM message_templates t
             LEFT JOIN `groups` g ON g.id = t.group_id
             {$where}
             ORDER BY t.id DESC",
            $params
        );
        foreach ($rows as &$row) {
            $row['name'] = $row['name'] ?? $row['title'] ?? '';
            $row['variables'] = $row['variables'] ?? $row['shortcut'] ?? '';
            $row['enabled'] = array_key_exists('enabled', $row) ? !empty($row['enabled']) : true;
        }
        unset($row);
        return $this->success($rows);
    }

    public function create(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $name = trim((string)$request->input('name', ''));
        $content = trim((string)$request->input('content', ''));
        if ($name === '' || $content === '') {
            return $this->error('名称与内容不能为空', 422);
        }
        $groupId = $this->resolveGroup($request->input('groupId'));
        if ($groupId === false) {
            return $this->error('话术分类不存在', 422);
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $variables = trim((string)$request->input('variables', ''));
        try {
            $this->insertTemplate($name, $content, $variables, $enabled, $groupId);
            $id = (int)DB::getPdo()->lastInsertId();
            return $this->success([
                'id' => $id,
                'name' => $name,
                'content' => $content,
                'variables' => $variables,
                'enabled' => (bool)$enabled,
                'groupId' => $groupId,
            ], '消息模板已创建');
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return $this->error('模板名称已存在', 409);
            }
            throw $e;
        }
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT id, owner_id FROM message_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($old)) {
            return $err;
        }
        $name = trim((string)$request->input('name', ''));
        $content = trim((string)$request->input('content', ''));
        if ($name === '' || $content === '') {
            return $this->error('名称与内容不能为空', 422);
        }
        $groupId = $this->resolveGroup($request->input('groupId'));
        if ($groupId === false) {
            return $this->error('话术分类不存在', 422);
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $variables = trim((string)$request->input('variables', ''));
        try {
            $this->updateTemplate((int)$id, $name, $content, $variables, $enabled, $groupId);
            return $this->success([
                'id' => (int)$id,
                'name' => $name,
                'content' => $content,
                'variables' => $variables,
                'enabled' => (bool)$enabled,
                'groupId' => $groupId,
            ], '消息模板已更新');
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return $this->error('模板名称已存在', 409);
            }
            throw $e;
        }
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT id, owner_id FROM message_templates WHERE id=?', [(int)$id]);
        if ($err = $this->assertOwns($old)) {
            return $err;
        }
        DB::query('DELETE FROM message_templates WHERE id=?', [(int)$id]);
        return $this->success(null, '消息模板已删除');
    }

    private function resolveGroup(mixed $groupId): int|false|null
    {
        if ($groupId === null || $groupId === '') {
            return null;
        }
        $id = (int)$groupId;
        $g = DB::selectOne("SELECT id FROM `groups` WHERE id=? AND type='message'", [$id]);
        return $g ? (int)$g['id'] : false;
    }

    private function insertTemplate(string $name, string $content, string $variables, int $enabled, ?int $groupId): void
    {
        $cols = ['content', 'group_id'];
        $vals = [$content, $groupId];
        if ($this->hasColumn('name')) {
            $cols[] = '`name`';
            $vals[] = $name;
        } elseif ($this->hasColumn('title')) {
            $cols[] = 'title';
            $vals[] = $name;
        }
        if ($this->hasColumn('variables')) {
            $cols[] = 'variables';
            $vals[] = $variables;
        } elseif ($this->hasColumn('shortcut')) {
            $cols[] = 'shortcut';
            $vals[] = $variables;
        }
        if ($this->hasColumn('enabled')) {
            $cols[] = 'enabled';
            $vals[] = $enabled;
        }
        $cols[] = 'owner_id';
        $vals[] = $this->writeOwnerId();
        $ph = implode(',', array_fill(0, count($cols), '?'));
        DB::query('INSERT INTO message_templates(' . implode(',', $cols) . ") VALUES ({$ph})", $vals);
    }

    private function updateTemplate(int $id, string $name, string $content, string $variables, int $enabled, ?int $groupId): void
    {
        $sets = ['content=?', 'group_id=?', 'updated_at=CURRENT_TIMESTAMP'];
        $vals = [$content, $groupId];
        if ($this->hasColumn('name')) {
            $sets[] = '`name`=?';
            $vals[] = $name;
        } elseif ($this->hasColumn('title')) {
            $sets[] = 'title=?';
            $vals[] = $name;
        }
        if ($this->hasColumn('variables')) {
            $sets[] = 'variables=?';
            $vals[] = $variables;
        } elseif ($this->hasColumn('shortcut')) {
            $sets[] = 'shortcut=?';
            $vals[] = $variables;
        }
        if ($this->hasColumn('enabled')) {
            $sets[] = 'enabled=?';
            $vals[] = $enabled;
        }
        $vals[] = $id;
        DB::query('UPDATE message_templates SET ' . implode(',', $sets) . ' WHERE id=?', $vals);
    }

    private function hasColumn(string $column): bool
    {
        if (self::$columns === null) {
            self::$columns = $this->loadColumns();
        }
        return in_array($column, self::$columns, true);
    }

    private function loadColumns(): array
    {
        $rows = DB::select('SHOW COLUMNS FROM message_templates') ?: [];
        $cols = [];
        foreach ($rows as $row) {
            $field = $row['Field'] ?? $row['field'] ?? $row['FIELD'] ?? null;
            if (is_string($field) && $field !== '') {
                $cols[] = $field;
            }
        }
        return $cols;
    }

    private function ensureSchema(): void
    {
        static $done = false;
        if ($done && self::$columns !== null) {
            return;
        }
        DB::query("CREATE TABLE IF NOT EXISTS message_templates (
          id INT AUTO_INCREMENT PRIMARY KEY,
          `name` VARCHAR(191) NOT NULL,
          content TEXT NOT NULL,
          variables VARCHAR(512) NOT NULL DEFAULT '',
          group_id INT NULL,
          enabled TINYINT NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_message_templates_name (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $cols = $this->loadColumns();

        if (!in_array('name', $cols, true)) {
            try {
                DB::query("ALTER TABLE message_templates ADD COLUMN `name` VARCHAR(191) NOT NULL DEFAULT ''");
                $cols[] = 'name';
            } catch (\Throwable $e) { /* ignore */ }
            if (in_array('title', $cols, true) && in_array('name', $cols, true)) {
                try {
                    DB::query("UPDATE message_templates SET `name`=`title` WHERE `name`='' OR `name` IS NULL");
                } catch (\Throwable $e) { /* ignore */ }
            }
        }
        if (!in_array('variables', $cols, true)) {
            try {
                DB::query("ALTER TABLE message_templates ADD COLUMN variables VARCHAR(512) NOT NULL DEFAULT ''");
                $cols[] = 'variables';
                if (in_array('shortcut', $cols, true)) {
                    DB::query("UPDATE message_templates SET variables=shortcut WHERE variables='' OR variables IS NULL");
                }
            } catch (\Throwable $e) { /* ignore */ }
        }
        if (!in_array('enabled', $cols, true)) {
            try {
                DB::query('ALTER TABLE message_templates ADD COLUMN enabled TINYINT NOT NULL DEFAULT 1');
                $cols[] = 'enabled';
            } catch (\Throwable $e) { /* ignore */ }
        }
        if (!in_array('group_id', $cols, true)) {
            try {
                DB::query('ALTER TABLE message_templates ADD COLUMN group_id INT NULL');
                $cols[] = 'group_id';
            } catch (\Throwable $e) { /* ignore */ }
        }

        self::$columns = array_values(array_unique($cols));
        $done = true;
    }
}
