<?php
declare(strict_types=1);

namespace App\Services;

class AccountImport
{
    public static function parseLine(string $line, string $format = 'auto'): ?array
    {
        $text = trim($line);
        if ($text === '' || str_starts_with($text, '#')) {
            return null;
        }
        if ($format === 'json' || ($format === 'auto' && str_starts_with($text, '{'))) {
            return self::parseJson($text);
        }
        $parts = array_map('trim', explode('----', $text));
        $resolved = $format === 'auto' ? self::detect($parts) : $format;

        if ($resolved === 'ck_only') {
            $cookie = $parts[0];
            if (!preg_match('/sessionid|sid_tt/i', $cookie)) {
                throw new \InvalidArgumentException('CK 中未找到 sessionid');
            }
            return self::row(self::cookieUsername($cookie), '', '', $cookie);
        }
        if ($resolved === 'user_ck') {
            if (count($parts) < 2) {
                throw new \InvalidArgumentException('格式应为 账号----CK');
            }
            return self::row($parts[0], '', '', implode('----', array_slice($parts, 1)));
        }
        if ($resolved === 'user_pass_ck') {
            if (count($parts) < 3) {
                throw new \InvalidArgumentException('格式应为 账号----密码----CK');
            }
            return self::row($parts[0], $parts[1], '', implode('----', array_slice($parts, 2)));
        }
        if ($resolved === 'user_pass') {
            if (count($parts) < 2) {
                throw new \InvalidArgumentException('格式应为 账号----密码');
            }
            return self::row($parts[0], $parts[1], '', '');
        }
        if ($resolved === 'user_pass_2fa') {
            if (count($parts) < 3) {
                throw new \InvalidArgumentException('格式应为 账号----密码----2FA');
            }
            $totp = strtoupper(preg_replace('/\s+/', '', $parts[2]) ?? '');
            // 去掉尾注（已用）等非 Base32 字符
            $totp = preg_replace('/[^A-Z2-7=]/', '', $totp) ?? '';
            if (!preg_match('/^[A-Z2-7]+=*$/', $totp) || strlen($totp) < 16) {
                throw new \InvalidArgumentException('2FA 密钥不是有效的 Base32');
            }
            return self::row($parts[0], $parts[1], $totp, implode('----', array_slice($parts, 3)));
        }
        if ($resolved === 'email_pass_user') {
            if (count($parts) < 3) {
                throw new \InvalidArgumentException('格式应为 邮箱----密码----用户名----国家');
            }
            $row = self::row($parts[2] ?: $parts[0], $parts[1], '', '');
            $row['country'] = $parts[3] ?? '';
            return $row;
        }
        throw new \InvalidArgumentException('不支持的导入格式：' . $resolved);
    }

    private static function detect(array $parts): string
    {
        $p2 = preg_replace('/\s+/', '', $parts[2] ?? '') ?? '';
        if (count($parts) >= 3 && preg_match('/^[A-Z2-7]+=*$/i', $p2)) {
            return 'user_pass_2fa';
        }
        if (count($parts) >= 3 && preg_match('/sessionid|sid_tt|tt_chain/i', $parts[2])) {
            return 'user_pass_ck';
        }
        if (count($parts) === 2 && preg_match('/sessionid|sid_tt|tt_chain/i', $parts[1])) {
            return 'user_ck';
        }
        if (count($parts) === 1 && preg_match('/sessionid|sid_tt/i', $parts[0])) {
            return 'ck_only';
        }
        return 'user_pass';
    }

    private static function parseJson(string $line): array
    {
        $data = json_decode($line, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON 无法解析');
        }
        $username = (string)($data['username'] ?? $data['user'] ?? $data['account'] ?? $data['uniqueId'] ?? $data['handle'] ?? '');
        $cookie = $data['cookie'] ?? $data['cookies'] ?? $data['ck'] ?? '';
        if (is_array($cookie)) {
            $cookie = json_encode($cookie, JSON_UNESCAPED_UNICODE);
        }
        if ($username === '' && $cookie === '') {
            throw new \InvalidArgumentException('JSON 缺少 username/cookie');
        }
        $totp = strtoupper(preg_replace('/\s+/', '', (string)($data['totp'] ?? $data['totpSecret'] ?? $data['2fa'] ?? $data['twofa'] ?? '')) ?? '');
        $row = self::row(
            $username !== '' ? $username : (self::cookieUsername((string)$cookie) ?: ('json_' . base_convert((string)time(), 10, 36))),
            (string)($data['password'] ?? $data['pwd'] ?? ''),
            $totp,
            (string)$cookie
        );
        $row['country'] = (string)($data['country'] ?? $data['region'] ?? '');
        $row['nickname'] = (string)($data['nickname'] ?? $data['nickName'] ?? $data['displayName'] ?? '');
        return $row;
    }

    private static function cookieUsername(string $cookie): string
    {
        if (!preg_match('/(?:^|;\s*)(?:sessionid|uid_tt|sid_tt)=([^;]+)/i', $cookie, $m)) {
            return 'ck_' . base_convert((string)(int)(microtime(true) * 1000), 10, 36);
        }
        $decoded = urldecode($m[1]);
        if (preg_match('/[a-zA-Z0-9._]{3,64}/', $decoded, $u)) {
            return $u[0];
        }
        return 'ck_' . base_convert((string)time(), 10, 36);
    }

    private static function row(string $username, string $password, string $totp, string $cookie): array
    {
        return [
            'username' => $username,
            'password' => $password,
            'totpSecret' => $totp,
            'cookie' => $cookie,
            'country' => '',
            'nickname' => '',
        ];
    }
}
