<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database\DB;

/**
 * 守护者（dyyemail）视频积分 / Token / 企业查找接口客户端。
 * 对齐 dyyweb ShouhuzheVideoPointService + ShouhuzheCompanySearchController：
 * - POST video/binding/check
 * - POST video/points/balance
 * - POST video/payment-login  （充值登录页）
 * - POST video/points/deduct
 * - POST company/search       （按企业名查是否创建及关联运营）
 */
class ShouhuzheVideoApiService
{
    public static function ensureSettingsSchema(): void
    {
        DB::query(
            "CREATE TABLE IF NOT EXISTS video_delivery_api_settings (
              id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
              base_url VARCHAR(255) NOT NULL DEFAULT 'https://dyyemail.com/api',
              api_token VARCHAR(255) NOT NULL DEFAULT '',
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $row = DB::selectOne('SELECT id FROM video_delivery_api_settings WHERE id=1');
        if (!$row) {
            $base = getenv('SHOUHUZHE_VIDEO_API_BASE_URL') ?: 'https://dyyemail.com/api';
            $token = getenv('SHOUHUZHE_VIDEO_API_TOKEN') ?: 'Hy94^ymjDbqhPd^F^h48';
            DB::query(
                'INSERT INTO video_delivery_api_settings (id, base_url, api_token) VALUES (1, ?, ?)',
                [$base, $token]
            );
        }
    }

    /** @return array{base_url:string,api_token:string,timeout:int} */
    public static function config(): array
    {
        self::ensureSettingsSchema();
        $row = DB::selectOne('SELECT base_url, api_token FROM video_delivery_api_settings WHERE id=1') ?: [];
        $base = trim((string)(getenv('SHOUHUZHE_VIDEO_API_BASE_URL') ?: ($row['base_url'] ?? '')));
        if ($base === '') {
            $base = 'https://dyyemail.com/api';
        }
        $token = (string)(getenv('SHOUHUZHE_VIDEO_API_TOKEN') ?: ($row['api_token'] ?? ''));
        if ($token === '') {
            $token = 'Hy94^ymjDbqhPd^F^h48';
        }
        $timeout = (int)(getenv('SHOUHUZHE_VIDEO_API_TIMEOUT') ?: 15);
        return [
            'base_url' => rtrim($base, '/'),
            'api_token' => $token,
            'timeout' => max(5, $timeout),
        ];
    }

    /** 校验企业视频 Token 绑定，并拉取远程积分 */
    public static function checkBinding(string $companyToken): array
    {
        return self::post('video/binding/check', ['token' => $companyToken]);
    }

    public static function balance(string $companyToken): array
    {
        return self::post('video/points/balance', ['token' => $companyToken]);
    }

    /** 充值：返回守护者支付登录页 login_url */
    public static function paymentLogin(string $companyToken): array
    {
        return self::post('video/payment-login', ['token' => $companyToken]);
    }

    /**
     * 扣除积分。
     * @param int $sourceId 本地交付单 ID（幂等源）
     */
    public static function deduct(
        string $companyToken,
        int $points,
        int $sourceId,
        string $title,
        ?string $remark = null
    ): array {
        return self::post('video/points/deduct', [
            'token' => $companyToken,
            'points' => $points,
            'source_id' => $sourceId,
            'title' => $title,
            'remark' => $remark,
        ]);
    }

    /**
     * 按关键字查找守护者企业（是否已创建、关联运营用户）。
     * 对齐 dyyweb：POST {base}/company/search，header token，form keyword/limit。
     *
     * @return array{status:bool,error_msg?:string,message?:string,data?:list<array>,count?:int}
     */
    public static function searchCompany(string $keyword, int $limit = 20): array
    {
        $keyword = trim($keyword);
        if (mb_strlen($keyword) < 2) {
            return ['status' => false, 'error_msg' => '请输入至少2个字符的企业名称'];
        }
        return self::post('company/search', [
            'keyword' => $keyword,
            'limit' => min(50, max(1, $limit)),
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:bool,error_msg?:string,message?:string,data?:array}
     */
    public static function post(string $path, array $payload): array
    {
        $cfg = self::config();
        $baseUrl = $cfg['base_url'];
        if ($baseUrl === '') {
            return ['status' => false, 'error_msg' => '守护者视频积分接口未配置'];
        }
        $url = $baseUrl . '/' . ltrim($path, '/');
        $body = http_build_query($payload);
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];
        // 平台请求 token（公司搜索等同款 header）；视频接口主体仍用企业 auth_token
        if ($cfg['api_token'] !== '') {
            $headers[] = 'token: ' . $cfg['api_token'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => false, 'error_msg' => '守护者视频积分接口初始化失败'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $cfg['timeout'],
            CURLOPT_CONNECTTIMEOUT => min(10, $cfg['timeout']),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            return ['status' => false, 'error_msg' => '守护者视频积分接口请求失败：' . ($error ?: 'network')];
        }
        if ($http < 200 || $http >= 300) {
            return ['status' => false, 'error_msg' => '守护者视频积分接口响应异常 HTTP ' . $http];
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return ['status' => false, 'error_msg' => '守护者视频积分接口返回格式错误'];
        }
        return $data;
    }
}
