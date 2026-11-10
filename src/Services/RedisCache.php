<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 轻量 Redis 客户端（无 composer 依赖）。
 * 优先使用 phpredis 扩展；没有扩展时用纯 PHP socket 直连（RESP 协议）。
 *
 * 连接参数（环境变量，均可选）：
 *   TKSWARM_REDIS_HOST     默认 127.0.0.1
 *   TKSWARM_REDIS_PORT     默认 6379
 *   TKSWARM_REDIS_PASSWORD 默认空
 *   TKSWARM_REDIS_DB       默认 0
 *   TKSWARM_REDIS_PREFIX   默认 tkswarm:
 *
 * 所有方法失败时都不抛异常：读返回 null，写返回 false，并写 error_log，
 * 避免 Redis 故障拖垮坐席主流程。
 */
final class RedisCache
{
    private static $ext = null;      // \Redis 实例（phpredis）
    private static $sock = null;     // socket 资源
    private static bool $failed = false;

    /** 客服搜索结果有效期：一天 */
    public const SEAT_SEARCH_TTL = 86400;

    private static function env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_ENV[$key] ?? '';
        }
        $v = trim((string)$v);
        return $v !== '' ? $v : $default;
    }

    public static function prefix(): string
    {
        return self::env('TKSWARM_REDIS_PREFIX', 'tkswarm:');
    }

    private static function connect(): bool
    {
        if (self::$ext !== null || self::$sock !== null) {
            return true;
        }
        if (self::$failed) {
            return false;
        }
        $host = self::env('TKSWARM_REDIS_HOST', '127.0.0.1');
        $port = (int)self::env('TKSWARM_REDIS_PORT', '6379');
        $pass = self::env('TKSWARM_REDIS_PASSWORD', '');
        $db = (int)self::env('TKSWARM_REDIS_DB', '0');

        try {
            if (class_exists('\\Redis')) {
                $r = new \Redis();
                if (!$r->connect($host, $port, 2.0)) {
                    throw new \RuntimeException('phpredis connect failed');
                }
                if ($pass !== '') {
                    $r->auth($pass);
                }
                if ($db > 0) {
                    $r->select($db);
                }
                self::$ext = $r;
                return true;
            }

            $errno = 0;
            $errstr = '';
            $s = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2.0);
            if (!$s) {
                throw new \RuntimeException("socket connect failed: {$errstr} ({$errno})");
            }
            stream_set_timeout($s, 3);
            self::$sock = $s;
            if ($pass !== '') {
                self::cmd(['AUTH', $pass]);
            }
            if ($db > 0) {
                self::cmd(['SELECT', (string)$db]);
            }
            return true;
        } catch (\Throwable $e) {
            self::$failed = true;
            self::$ext = null;
            if (is_resource(self::$sock)) {
                @fclose(self::$sock);
            }
            self::$sock = null;
            error_log('[redis] connect failed: ' . $e->getMessage());
            return false;
        }
    }

    /** 发送一条 RESP 命令并读取回复 */
    private static function cmd(array $args)
    {
        $s = self::$sock;
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $a = (string)$a;
            $out .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        if (@fwrite($s, $out) === false) {
            throw new \RuntimeException('redis write failed');
        }
        return self::readReply($s);
    }

    private static function readReply($s)
    {
        $line = fgets($s);
        if ($line === false) {
            throw new \RuntimeException('redis read failed');
        }
        $type = $line[0];
        $body = rtrim(substr($line, 1), "\r\n");
        switch ($type) {
            case '+':
                return $body;
            case '-':
                throw new \RuntimeException('redis error: ' . $body);
            case ':':
                return (int)$body;
            case '$':
                $len = (int)$body;
                if ($len < 0) {
                    return null;
                }
                $data = '';
                $remain = $len + 2;
                while ($remain > 0) {
                    $chunk = fread($s, $remain);
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('redis bulk read failed');
                    }
                    $data .= $chunk;
                    $remain -= strlen($chunk);
                }
                return substr($data, 0, $len);
            case '*':
                $n = (int)$body;
                if ($n < 0) {
                    return null;
                }
                $arr = [];
                for ($i = 0; $i < $n; $i++) {
                    $arr[] = self::readReply($s);
                }
                return $arr;
        }
        throw new \RuntimeException('redis unknown reply: ' . $line);
    }

    /** 写入字符串并设置过期秒数 */
    public static function setex(string $key, int $ttlSeconds, string $value): bool
    {
        if (!self::connect()) {
            return false;
        }
        $k = self::prefix() . $key;
        try {
            if (self::$ext !== null) {
                return (bool)self::$ext->setex($k, $ttlSeconds, $value);
            }
            self::cmd(['SETEX', $k, (string)$ttlSeconds, $value]);
            return true;
        } catch (\Throwable $e) {
            error_log('[redis] setex failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function get(string $key): ?string
    {
        if (!self::connect()) {
            return null;
        }
        $k = self::prefix() . $key;
        try {
            if (self::$ext !== null) {
                $v = self::$ext->get($k);
                return $v === false ? null : (string)$v;
            }
            $v = self::cmd(['GET', $k]);
            return $v === null ? null : (string)$v;
        } catch (\Throwable $e) {
            error_log('[redis] get failed: ' . $e->getMessage());
            return null;
        }
    }

    /** 剩余有效秒数；key 不存在返回 -2，无过期返回 -1，失败返回 null */
    public static function ttl(string $key): ?int
    {
        if (!self::connect()) {
            return null;
        }
        $k = self::prefix() . $key;
        try {
            if (self::$ext !== null) {
                return (int)self::$ext->ttl($k);
            }
            return (int)self::cmd(['TTL', $k]);
        } catch (\Throwable $e) {
            error_log('[redis] ttl failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function del(string $key): bool
    {
        if (!self::connect()) {
            return false;
        }
        $k = self::prefix() . $key;
        try {
            if (self::$ext !== null) {
                self::$ext->del($k);
                return true;
            }
            self::cmd(['DEL', $k]);
            return true;
        } catch (\Throwable $e) {
            error_log('[redis] del failed: ' . $e->getMessage());
            return false;
        }
    }

    /** 客服搜索结果：每个账号一个独立 key */
    public static function seatSearchKey(int $accountId): string
    {
        return 'chat:seat-search:' . $accountId;
    }

    public static function saveSeatSearch(int $accountId, array $data): bool
    {
        $data['savedAt'] = date('c');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return self::setex(self::seatSearchKey($accountId), self::SEAT_SEARCH_TTL, $json);
    }

    public static function loadSeatSearch(int $accountId): ?array
    {
        $raw = self::get(self::seatSearchKey($accountId));
        if ($raw === null || $raw === '') {
            return null;
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return null;
        }
        $ttl = self::ttl(self::seatSearchKey($accountId));
        $arr['expiresIn'] = ($ttl !== null && $ttl > 0) ? $ttl : null;
        return $arr;
    }

    public static function clearSeatSearch(int $accountId): bool
    {
        return self::del(self::seatSearchKey($accountId));
    }
}
