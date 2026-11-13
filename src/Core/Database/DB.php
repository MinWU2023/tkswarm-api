<?php
declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use PDOStatement;
use PDOException;
use App\Core\App;

class DB
{
    private static ?PDO $pdo = null;
    private static array $config = [];

    public static function connect(?array $config = null): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        if ($config === null) {
            $config = App::getInstance()?->getConfig('db') ?? [];
        }
        self::$config = $config;

        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? 'tkswarm';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        // 与 PHP Asia/Shanghai 对齐，避免 CURRENT_TIMESTAMP / NOW() 按 UTC 写入 DATETIME
        $tz = '+08:00';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            // INIT 里只设字符集；时区在连接后强制 exec，避免部分环境 INIT_COMMAND 未生效
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
        ];

        try {
            self::$pdo = new PDO($dsn, $username, $password, $options);
            self::applySessionTimezone(self::$pdo);
            return self::$pdo;
        } catch (PDOException $e) {
            throw new \RuntimeException("MySQL Connection Error: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    /** 强制会话东八区（DATETIME 默认值 / NOW() / CURRENT_TIMESTAMP 都走中国墙钟） */
    private static function applySessionTimezone(PDO $pdo): void
    {
        try {
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (\Throwable $e) {
            // 忽略：无权限时仍依赖 PHP 侧 DB::now() 显式写入
        }
    }

    /** 中国墙钟时间（强制 Asia/Shanghai），用于显式写入 created_at / updated_at */
    public static function now(): string
    {
        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            if (date_default_timezone_get() !== 'Asia/Shanghai') {
                date_default_timezone_set('Asia/Shanghai');
            }
            return date('Y-m-d H:i:s');
        }
    }

    public static function getPdo(): PDO
    {
        return self::connect();
    }

    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }

    public static function name(string $table): QueryBuilder
    {
        $prefix = self::$config['prefix'] ?? '';
        return new QueryBuilder($prefix . $table);
    }

    public static function query(string $sql, array $bindings = []): PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt;
    }

    public static function select(string $sql, array $bindings = []): array
    {
        return self::query($sql, $bindings)->fetchAll();
    }

    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $res = self::query($sql, $bindings)->fetch();
        return $res ?: null;
    }

    public static function execute(string $sql, array $bindings = []): int
    {
        return self::query($sql, $bindings)->rowCount();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connect();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
