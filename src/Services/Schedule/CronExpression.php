<?php
declare(strict_types=1);

namespace App\Services\Schedule;

/**
 * 精简五段 cron 匹配（分 时 日 月 周），支持 * /n n a,b a-b a-b/n
 */
final class CronExpression
{
    public static function isDue(string $expression, ?int $ts = null): bool
    {
        $ts = $ts ?? time();
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            return false;
        }
        [$min, $hour, $dom, $month, $dow] = $parts;
        $t = getdate($ts);
        return self::matchField($min, (int)$t['minutes'], 0, 59)
            && self::matchField($hour, (int)$t['hours'], 0, 23)
            && self::matchField($dom, (int)$t['mday'], 1, 31)
            && self::matchField($month, (int)$t['mon'], 1, 12)
            && self::matchField($dow, (int)$t['wday'], 0, 6);
    }

    private static function matchField(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $step = 1;
            if (str_contains($chunk, '/')) {
                [$chunk, $stepStr] = explode('/', $chunk, 2);
                $step = max(1, (int)$stepStr);
            }
            if ($chunk === '*' || $chunk === '') {
                if ($step === 1 || (($value - $min) % $step) === 0) {
                    return true;
                }
                continue;
            }
            if (str_contains($chunk, '-')) {
                [$a, $b] = array_map('intval', explode('-', $chunk, 2));
                if ($value < $a || $value > $b) {
                    continue;
                }
                if ($step === 1 || (($value - $a) % $step) === 0) {
                    return true;
                }
                continue;
            }
            $n = (int)$chunk;
            if ($n < $min || $n > $max) {
                continue;
            }
            if ($step === 1) {
                if ($value === $n) {
                    return true;
                }
            } elseif ($value >= $n && (($value - $n) % $step) === 0) {
                return true;
            }
        }
        return false;
    }
}
