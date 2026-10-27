<?php
declare(strict_types=1);

namespace App\Core\Database;

use PDO;

class QueryBuilder
{
    private string $table;
    private array $columns = ['*'];
    private array $wheres = [];
    private array $bindings = [];
    private array $orders = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $joins = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function select(string|array $columns = ['*']): self
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => strtoupper($operator),
            'boolean' => 'AND'
        ];
        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => strtoupper($operator),
            'boolean' => 'OR'
        ];
        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            $this->wheres[] = ['type' => 'raw', 'sql' => '0 = 1', 'boolean' => 'AND'];
            return $this;
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => "{$column} IN ({$placeholders})",
            'boolean' => 'AND'
        ];
        foreach ($values as $v) {
            $this->bindings[] = $v;
        }
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => "{$column} IS NULL", 'boolean' => 'AND'];
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => "{$column} IS NOT NULL", 'boolean' => 'AND'];
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => 'AND'];
        foreach ($bindings as $b) {
            $this->bindings[] = $b;
        }
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orders[] = "{$column} " . strtoupper($direction);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function page(int $page = 1, int $pageSize = 20): self
    {
        $page = max(1, $page);
        $this->limit = $pageSize;
        $this->offset = ($page - 1) * $pageSize;
        return $this;
    }

    private function buildSelectSql(): string
    {
        $sql = "SELECT " . implode(', ', $this->columns) . " FROM `{$this->table}`";
        if (!empty($this->wheres)) {
            $whereParts = [];
            foreach ($this->wheres as $i => $w) {
                $bool = $i === 0 ? '' : $w['boolean'] . ' ';
                if ($w['type'] === 'basic') {
                    $whereParts[] = "{$bool}`{$w['column']}` {$w['operator']} ?";
                } elseif ($w['type'] === 'raw') {
                    $whereParts[] = "{$bool}{$w['sql']}";
                }
            }
            $sql .= " WHERE " . implode(' ', $whereParts);
        }
        if (!empty($this->orders)) {
            $sql .= " ORDER BY " . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        return $sql;
    }

    public function get(): array
    {
        $sql = $this->buildSelectSql();
        return DB::select($sql, $this->bindings);
    }

    public function first(): ?array
    {
        $this->limit(1);
        $sql = $this->buildSelectSql();
        return DB::selectOne($sql, $this->bindings);
    }

    public function find(int|string $id): ?array
    {
        return $this->where('id', $id)->first();
    }

    public function count(string $column = '*'): int
    {
        $prevCols = $this->columns;
        $this->columns = ["COUNT({$column}) AS aggregate"];
        $sql = $this->buildSelectSql();
        $this->columns = $prevCols;
        $row = DB::selectOne($sql, $this->bindings);
        return (int)($row['aggregate'] ?? 0);
    }

    public function paginate(int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $total = $this->count();
        $items = $this->page($page, $pageSize)->get();
        return [
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'lastPage' => (int)ceil($total / $pageSize),
            'items' => $items
        ];
    }

    public function insert(array $data): int|string
    {
        $keys = array_keys($data);
        $fields = '`' . implode('`, `', $keys) . '`';
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $sql = "INSERT INTO `{$this->table}` ({$fields}) VALUES ({$placeholders})";
        
        $pdo = DB::getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return $pdo->lastInsertId();
    }

    public function update(array $data): int
    {
        $set = [];
        $bindings = [];
        foreach ($data as $k => $v) {
            $set[] = "`{$k}` = ?";
            $bindings[] = $v;
        }
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $set);

        if (!empty($this->wheres)) {
            $whereParts = [];
            foreach ($this->wheres as $i => $w) {
                $bool = $i === 0 ? '' : $w['boolean'] . ' ';
                if ($w['type'] === 'basic') {
                    $whereParts[] = "{$bool}`{$w['column']}` {$w['operator']} ?";
                } elseif ($w['type'] === 'raw') {
                    $whereParts[] = "{$bool}{$w['sql']}";
                }
            }
            $sql .= " WHERE " . implode(' ', $whereParts);
        }
        $bindings = array_merge($bindings, $this->bindings);
        return DB::execute($sql, $bindings);
    }

    public function delete(): int
    {
        $sql = "DELETE FROM `{$this->table}`";
        if (!empty($this->wheres)) {
            $whereParts = [];
            foreach ($this->wheres as $i => $w) {
                $bool = $i === 0 ? '' : $w['boolean'] . ' ';
                if ($w['type'] === 'basic') {
                    $whereParts[] = "{$bool}`{$w['column']}` {$w['operator']} ?";
                } elseif ($w['type'] === 'raw') {
                    $whereParts[] = "{$bool}{$w['sql']}";
                }
            }
            $sql .= " WHERE " . implode(' ', $whereParts);
        }
        return DB::execute($sql, $this->bindings);
    }
}
