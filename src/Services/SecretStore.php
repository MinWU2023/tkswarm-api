<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 与 Node secret-store 相同：data/.secret-key + AES-256-GCM，密文 v1.iv.tag.ct（base64url）
 */
class SecretStore
{
    public static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '');
        if ($cipher === false) {
            throw new \RuntimeException('加密失败');
        }
        return 'v1.' . self::b64($iv) . '.' . self::b64($tag) . '.' . self::b64($cipher);
    }

    public static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $parts = explode('.', $value);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            return '';
        }
        $iv = self::b64decode($parts[1]);
        $tag = self::b64decode($parts[2]);
        $cipher = self::b64decode($parts[3]);
        if ($iv === '' || $tag === '' || $cipher === '') {
            return '';
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        // SecretStore 位于 src/Services → 上两级为 tkswarm-api 根目录（勿用 3，会落到站点上一级触发 open_basedir）
        $path = getenv('SECRET_KEY_PATH') ?: (dirname(__DIR__, 2) . '/data/.secret-key');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (!is_file($path)) {
            file_put_contents($path, random_bytes(32));
        }
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) !== 32) {
            throw new \RuntimeException('密钥文件格式错误');
        }
        $key = $raw;
        return $key;
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64decode(string $text): string
    {
        $pad = strlen($text) % 4;
        if ($pad) {
            $text .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($text, '-_', '+/'), true);
        return $raw === false ? '' : $raw;
    }
}
