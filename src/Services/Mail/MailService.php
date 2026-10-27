<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Database\DB;

/**
 * 基于系统 settings 表的 SMTP 发信（原生 socket，无第三方依赖）。
 */
class MailService
{
    public const SETTING_KEYS = [
        'smtp.host',
        'smtp.port',
        'smtp.encryption',
        'smtp.username',
        'smtp.password',
        'smtp.from_email',
        'smtp.from_name',
    ];

    /** @return array<string,string> */
    public static function config(): array
    {
        $out = [
            'smtp.host' => '',
            'smtp.port' => '465',
            'smtp.encryption' => 'ssl',
            'smtp.username' => '',
            'smtp.password' => '',
            'smtp.from_email' => '',
            'smtp.from_name' => 'TkSwarm',
        ];
        try {
            $ph = implode(',', array_fill(0, count(self::SETTING_KEYS), '?'));
            $rows = DB::select(
                "SELECT `key`, `value` FROM settings WHERE `key` IN ({$ph})",
                self::SETTING_KEYS
            ) ?: [];
            foreach ($rows as $row) {
                $k = (string)($row['key'] ?? '');
                if (!isset($out[$k])) {
                    continue;
                }
                $raw = (string)($row['value'] ?? '');
                $decoded = json_decode($raw, true);
                if (is_scalar($decoded) || $decoded === null && $raw === 'null') {
                    $out[$k] = $decoded === null ? '' : (string)$decoded;
                } else {
                    $out[$k] = $raw;
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    public static function isConfigured(): bool
    {
        $c = self::config();
        $from = trim($c['smtp.from_email'] !== '' ? $c['smtp.from_email'] : $c['smtp.username']);
        return trim($c['smtp.host']) !== '' && $from !== '';
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public static function sendHtml(string $to, string $subject, string $htmlBody): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => '收件邮箱无效'];
        }
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => '未配置 SMTP（settings: smtp.host / smtp.from_email）'];
        }

        $c = self::config();
        $host = trim($c['smtp.host']);
        $port = (int)($c['smtp.port'] ?: 465);
        $enc = strtolower(trim($c['smtp.encryption'] ?: 'ssl'));
        $user = trim($c['smtp.username']);
        $pass = (string)$c['smtp.password'];
        $fromEmail = trim($c['smtp.from_email'] !== '' ? $c['smtp.from_email'] : $user);
        $fromName = trim($c['smtp.from_name'] !== '' ? $c['smtp.from_name'] : 'TkSwarm');

        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . self::encodeAddress($fromName, $fromEmail);
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'X-Mailer: TkSwarm-MailService';

        $plain = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($plain !== '' ? $plain : $subject)) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody)) . "\r\n"
            . "--{$boundary}--\r\n";

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        try {
            self::smtpSend($host, $port, $enc, $user, $pass, $fromEmail, $to, $message);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function encodeAddress(string $name, string $email): string
    {
        $email = trim($email);
        $name = trim($name);
        if ($name === '') {
            return '<' . $email . '>';
        }
        return self::encodeHeader($name) . ' <' . $email . '>';
    }

    private static function encodeHeader(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $text)) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    private static function smtpSend(
        string $host,
        int $port,
        string $enc,
        string $user,
        string $pass,
        string $fromEmail,
        string $to,
        string $message
    ): void {
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $remote . ':' . max(1, $port),
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT
        );
        if (!$fp) {
            throw new \RuntimeException("无法连接 SMTP {$host}:{$port} ({$errno}) {$errstr}");
        }
        stream_set_timeout($fp, 30);

        try {
            self::expect($fp, [220]);
            self::cmd($fp, 'EHLO tkswarm.local', [250]);
            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS 协商失败');
                }
                self::cmd($fp, 'EHLO tkswarm.local', [250]);
            }
            if ($user !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode($user), [334]);
                self::cmd($fp, base64_encode($pass), [235]);
            }
            self::cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            self::cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            fwrite($fp, $message . "\r\n.\r\n");
            self::expect($fp, [250]);
            self::cmd($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }

    /** @param list<int> $okCodes */
    private static function cmd($fp, string $line, array $okCodes): void
    {
        fwrite($fp, $line . "\r\n");
        self::expect($fp, $okCodes);
    }

    /** @param list<int> $okCodes */
    private static function expect($fp, array $okCodes): void
    {
        $resp = '';
        while (($line = fgets($fp, 515)) !== false) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new \RuntimeException('SMTP 响应异常：' . trim($resp));
        }
    }
}
