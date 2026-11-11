<?php
declare(strict_types=1);

namespace App\Services\YouTube;

use App\Services\UserSettingsService;

/**
 * 全局 YouTube OAuth 客户端配置（存 settings 表 youtube.oauth.*）。
 */
final class YouTubeOAuthConfigService
{
    public const SETTING_KEYS = [
        'youtube.oauth.clientId',
        'youtube.oauth.clientSecret',
        'youtube.oauth.redirectUri',
        'youtube.oauth.scopes',
    ];

    /** @return array{clientId:string,clientSecret:string,redirectUri:string,scopes:string} */
    public static function get(): array
    {
        $clientId = trim((string)UserSettingsService::setting('youtube.oauth.clientId', ''));
        $clientSecret = trim((string)UserSettingsService::setting('youtube.oauth.clientSecret', ''));
        $redirectUri = trim((string)UserSettingsService::setting('youtube.oauth.redirectUri', ''));
        $scopes = trim((string)UserSettingsService::setting('youtube.oauth.scopes', ''));
        if ($scopes === '') {
            $scopes = implode(' ', YouTubeApiService::DEFAULT_SCOPES);
        }
        return [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $redirectUri,
            'scopes' => $scopes,
        ];
    }

    /** @return array<string,string> settings 键值（含 youtube.oauth.*） */
    public static function getSettingsMap(bool $maskSecret = true): array
    {
        $cfg = self::get();
        $secret = $cfg['clientSecret'];
        if ($maskSecret && $secret !== '') {
            $secret = '******';
        }
        return [
            'youtube.oauth.clientId' => $cfg['clientId'],
            'youtube.oauth.clientSecret' => $secret,
            'youtube.oauth.redirectUri' => $cfg['redirectUri'],
            'youtube.oauth.scopes' => $cfg['scopes'],
        ];
    }

    public static function isConfigured(): bool
    {
        $cfg = self::get();
        return $cfg['clientId'] !== '' && $cfg['clientSecret'] !== '' && $cfg['redirectUri'] !== '';
    }

    /**
     * @param array<string,mixed> $input
     * @return array{clientId:string,clientSecret:string,redirectUri:string,scopes:string}
     */
    public static function save(array $input): array
    {
        $current = self::get();
        $payload = [];

        if (array_key_exists('youtube.oauth.clientId', $input) || array_key_exists('clientId', $input)) {
            $payload['youtube.oauth.clientId'] = trim((string)($input['youtube.oauth.clientId'] ?? $input['clientId'] ?? ''));
        }
        if (array_key_exists('youtube.oauth.clientSecret', $input) || array_key_exists('clientSecret', $input)) {
            $secret = trim((string)($input['youtube.oauth.clientSecret'] ?? $input['clientSecret'] ?? ''));
            if ($secret !== '' && $secret !== '******') {
                $payload['youtube.oauth.clientSecret'] = $secret;
            }
        }
        if (array_key_exists('youtube.oauth.redirectUri', $input) || array_key_exists('redirectUri', $input)) {
            $payload['youtube.oauth.redirectUri'] = trim((string)($input['youtube.oauth.redirectUri'] ?? $input['redirectUri'] ?? ''));
        }
        if (array_key_exists('youtube.oauth.scopes', $input) || array_key_exists('scopes', $input)) {
            $scopes = trim((string)($input['youtube.oauth.scopes'] ?? $input['scopes'] ?? ''));
            if ($scopes === '') {
                $scopes = implode(' ', YouTubeApiService::DEFAULT_SCOPES);
            }
            $payload['youtube.oauth.scopes'] = $scopes;
        }

        if ($payload !== []) {
            UserSettingsService::saveForUser($payload);
        }

        return self::get();
    }

    /**
     * 把全局 OAuth 客户端写入 youtube_accounts 行（供 YouTubeApiService 使用）。
     *
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public static function applyToAccountRow(array $account): array
    {
        $cfg = self::get();
        if ($cfg['clientId'] !== '') {
            $account['oauth_client_id'] = $cfg['clientId'];
        }
        if ($cfg['clientSecret'] !== '') {
            $account['oauth_client_secret'] = $cfg['clientSecret'];
        }
        if ($cfg['redirectUri'] !== '') {
            $account['oauth_redirect_uri'] = $cfg['redirectUri'];
        }
        if ($cfg['scopes'] !== '') {
            $account['oauth_scopes'] = $cfg['scopes'];
        }
        return $account;
    }
}
