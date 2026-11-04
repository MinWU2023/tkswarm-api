<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 账号跨端占用：Node（桌面）与 linuxbrowse（服务器浏览器）互斥。
 * 字段：
 * - occupy_status：非空表示占用中（建议存 busy）
 * - occupy_owner：占用方标识，如 node:DESKTOP-xxx / linuxbrowse:lb-seat-01
 * 任务结束必须两边都清空这两个字段。
 */
final class AccountOccupyService
{
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $cols = DB::select("SHOW COLUMNS FROM accounts LIKE 'occupy_status'");
            if (!$cols) {
                DB::query("ALTER TABLE accounts ADD COLUMN occupy_status VARCHAR(32) NOT NULL DEFAULT ''");
            }
        } catch (\Throwable $e) {
        }
        try {
            $cols = DB::select("SHOW COLUMNS FROM accounts LIKE 'occupy_owner'");
            if (!$cols) {
                DB::query("ALTER TABLE accounts ADD COLUMN occupy_owner VARCHAR(128) NOT NULL DEFAULT ''");
            }
        } catch (\Throwable $e) {
        }
        try {
            $cols = DB::select("SHOW COLUMNS FROM accounts LIKE 'occupy_until'");
            if (!$cols) {
                DB::query('ALTER TABLE accounts ADD COLUMN occupy_until DATETIME NULL DEFAULT NULL');
            }
        } catch (\Throwable $e) {
        }
        try {
            $cols = DB::select("SHOW COLUMNS FROM accounts LIKE 'occupy_reason'");
            if (!$cols) {
                DB::query("ALTER TABLE accounts ADD COLUMN occupy_reason VARCHAR(191) NOT NULL DEFAULT ''");
            }
        } catch (\Throwable $e) {
        }
        self::reclaimExpired();
    }

    public static function reclaimExpired(): void
    {
        try {
            DB::query(
                "UPDATE accounts
                 SET occupy_status='', occupy_owner='', occupy_until=NULL, occupy_reason='', updated_at=CURRENT_TIMESTAMP
                 WHERE occupy_status<>''
                   AND occupy_until IS NOT NULL
                   AND occupy_until < CURRENT_TIMESTAMP"
            );
        } catch (\Throwable $e) {
        }
    }

    /**
     * @return array{occupied:bool,status:string,owner:string,reason:string,until:?string}
     */
    public static function snapshot(int $accountId): array
    {
        self::ensureSchema();
        self::reclaimExpired();
        $row = DB::selectOne(
            'SELECT occupy_status, occupy_owner, occupy_reason, occupy_until FROM accounts WHERE id=?',
            [$accountId]
        ) ?: [];
        $status = trim((string)($row['occupy_status'] ?? ''));
        $owner = trim((string)($row['occupy_owner'] ?? ''));
        return [
            'occupied' => $status !== '' && $owner !== '',
            'status' => $status,
            'owner' => $owner,
            'reason' => trim((string)($row['occupy_reason'] ?? '')),
            'until' => $row['occupy_until'] ?? null,
        ];
    }

    public static function ownerSide(string $owner): string
    {
        $o = strtolower(trim($owner));
        if ($o === '') {
            return '';
        }
        if (str_starts_with($o, 'linuxbrowse:') || str_starts_with($o, 'lb:') || $o === 'linuxbrowse') {
            return 'linuxbrowse';
        }
        if (str_starts_with($o, 'node:') || $o === 'node') {
            return 'node';
        }
        // 未知前缀按原串侧，避免误放行
        return $o;
    }

    public static function sideLabel(string $side): string
    {
        return match ($side) {
            'node' => '桌面 Node',
            'linuxbrowse' => '服务器浏览器',
            default => $side !== '' ? $side : '未知',
        };
    }

    /**
     * 占用账号。同 owner 可续租；不同侧占用则冲突。
     *
     * @return array{ok:bool,conflict?:bool,message?:string,snapshot:array}
     */
    public static function occupy(
        int $accountId,
        string $owner,
        string $reason = '',
        int $leaseSeconds = 1800
    ): array {
        self::ensureSchema();
        self::reclaimExpired();
        $owner = trim($owner);
        if ($accountId < 1 || $owner === '') {
            return [
                'ok' => false,
                'message' => '缺少 accountId 或 occupy_owner',
                'snapshot' => self::snapshot($accountId),
            ];
        }
        $leaseSeconds = max(60, min(7200, $leaseSeconds));
        $until = date('Y-m-d H:i:s', time() + $leaseSeconds);
        $reason = mb_substr(trim($reason), 0, 191);
        $mySide = self::ownerSide($owner);

        $row = DB::selectOne('SELECT id, occupy_status, occupy_owner, occupy_until FROM accounts WHERE id=?', [$accountId]);
        if (!$row) {
            return ['ok' => false, 'message' => '账号不存在', 'snapshot' => self::snapshot($accountId)];
        }

        $curOwner = trim((string)($row['occupy_owner'] ?? ''));
        $curStatus = trim((string)($row['occupy_status'] ?? ''));
        if ($curStatus !== '' && $curOwner !== '') {
            if ($curOwner === $owner) {
                DB::query(
                    "UPDATE accounts
                     SET occupy_status='busy', occupy_owner=?, occupy_until=?, occupy_reason=?, updated_at=CURRENT_TIMESTAMP
                     WHERE id=?",
                    [$owner, $until, $reason, $accountId]
                );
                return ['ok' => true, 'snapshot' => self::snapshot($accountId)];
            }
            $curSide = self::ownerSide($curOwner);
            if ($curSide !== '' && $curSide !== $mySide) {
                return [
                    'ok' => false,
                    'conflict' => true,
                    'message' => sprintf(
                        '账号已被「%s」占用（%s），当前「%s」不能执行。请等对方任务结束或释放占用。',
                        self::sideLabel($curSide),
                        $curOwner,
                        self::sideLabel($mySide)
                    ),
                    'snapshot' => self::snapshot($accountId),
                ];
            }
            // 同侧不同实例：也视为冲突，避免两台桌面抢同一号
            return [
                'ok' => false,
                'conflict' => true,
                'message' => sprintf(
                    '账号已被占用方「%s」占用，当前「%s」不能抢占。',
                    $curOwner,
                    $owner
                ),
                'snapshot' => self::snapshot($accountId),
            ];
        }

        // 条件更新：空闲才占，防并发
        DB::query(
            "UPDATE accounts
             SET occupy_status='busy',
                 occupy_owner=?,
                 occupy_until=?,
                 occupy_reason=?,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=?
               AND (occupy_status='' OR occupy_status IS NULL)
               AND (occupy_owner='' OR occupy_owner IS NULL)",
            [$owner, $until, $reason, $accountId]
        );
        $fresh = self::snapshot($accountId);
        if ($fresh['occupied'] && $fresh['owner'] === $owner) {
            return ['ok' => true, 'snapshot' => $fresh];
        }
        // 被并发抢走
        $other = $fresh['owner'] !== '' ? $fresh['owner'] : '其他端';
        return [
            'ok' => false,
            'conflict' => true,
            'message' => sprintf(
                '账号占用失败：已被「%s」抢先占用',
                $other
            ),
            'snapshot' => $fresh,
        ];
    }

    /**
     * 释放占用。仅占用方本人可清空；force=true 时超管/同侧清理可强清。
     *
     * @return array{ok:bool,message?:string,snapshot:array}
     */
    public static function release(int $accountId, string $owner, bool $force = false): array
    {
        self::ensureSchema();
        $owner = trim($owner);
        if ($accountId < 1) {
            return ['ok' => false, 'message' => '缺少 accountId', 'snapshot' => self::snapshot(0)];
        }
        $snap = self::snapshot($accountId);
        if (!$snap['occupied']) {
            return ['ok' => true, 'snapshot' => $snap];
        }
        if (!$force && $owner !== '' && $snap['owner'] !== $owner) {
            return [
                'ok' => false,
                'message' => sprintf('无权释放：当前占用方为「%s」', $snap['owner']),
                'snapshot' => $snap,
            ];
        }
        if ($force || $owner === '' || $snap['owner'] === $owner) {
            DB::query(
                "UPDATE accounts
                 SET occupy_status='', occupy_owner='', occupy_until=NULL, occupy_reason='', updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$accountId]
            );
        }
        return ['ok' => true, 'snapshot' => self::snapshot($accountId)];
    }

    /** 心跳续租 */
    public static function heartbeat(int $accountId, string $owner, int $leaseSeconds = 1800): array
    {
        self::ensureSchema();
        $owner = trim($owner);
        if ($accountId < 1 || $owner === '') {
            return ['ok' => false, 'message' => '缺少参数', 'snapshot' => self::snapshot($accountId)];
        }
        $leaseSeconds = max(60, min(7200, $leaseSeconds));
        $until = date('Y-m-d H:i:s', time() + $leaseSeconds);
        DB::query(
            "UPDATE accounts
             SET occupy_until=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND occupy_owner=? AND occupy_status<>''",
            [$until, $accountId, $owner]
        );
        $snap = self::snapshot($accountId);
        return [
            'ok' => $snap['occupied'] && $snap['owner'] === $owner,
            'snapshot' => $snap,
            'message' => ($snap['owner'] === $owner) ? null : '占用已失效或不属于当前占用方',
        ];
    }
}
