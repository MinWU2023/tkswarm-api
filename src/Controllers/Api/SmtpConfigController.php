<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DataScope;
use App\Services\Mail\MailService;
use App\Services\UserSettingsService;

/**
 * 开发管理 · SMTP 配置（仅登录超管可访问）
 * 配置存 settings 表 smtp.* 键，与 MailService 共用。
 */
class SmtpConfigController extends Controller
{
    private function denyUnlessLoginSuper(): ?Response
    {
        DataScope::bootFromGlobals();
        if (!DataScope::isLoginSuper()) {
            return $this->error('仅超级管理员可管理 SMTP 配置', 403);
        }
        return null;
    }

    public function get(Request $request): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        $cfg = MailService::config();
        $masked = $cfg;
        if (trim((string)($masked['smtp.password'] ?? '')) !== '') {
            $masked['smtp.password'] = '******';
        }
        return $this->success([
            'config' => $masked,
            'configured' => MailService::isConfigured(),
            'keys' => MailService::SETTING_KEYS,
        ]);
    }

    public function save(Request $request): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        $all = $request->all();
        $payload = [];
        foreach (MailService::SETTING_KEYS as $key) {
            if (!array_key_exists($key, $all)) {
                continue;
            }
            $val = $all[$key];
            if ($key === 'smtp.password' && ($val === '' || $val === '******')) {
                continue;
            }
            if ($key === 'smtp.port') {
                $val = (string)max(1, min(65535, (int)$val ?: 465));
            }
            if ($key === 'smtp.encryption') {
                $enc = strtolower(trim((string)$val));
                $val = in_array($enc, ['ssl', 'tls', 'none'], true) ? $enc : 'ssl';
            }
            if (is_string($val)) {
                $val = trim($val);
            }
            $payload[$key] = $val;
        }
        if ($payload === []) {
            return $this->error('没有可保存的 SMTP 字段');
        }
        UserSettingsService::saveForUser($payload);
        $cfg = MailService::config();
        if (trim((string)($cfg['smtp.password'] ?? '')) !== '') {
            $cfg['smtp.password'] = '******';
        }
        return $this->success([
            'config' => $cfg,
            'configured' => MailService::isConfigured(),
        ], 'SMTP 配置已保存');
    }

    /** 发送一封测试邮件（校验当前配置） */
    public function test(Request $request): Response
    {
        if ($deny = $this->denyUnlessLoginSuper()) {
            return $deny;
        }
        $to = trim((string)$request->input('to', $request->input('email', '')));
        if ($to === '') {
            $login = DataScope::loginUser();
            $to = strtolower(trim((string)($login['email'] ?? '')));
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->error('请填写有效的测试收件邮箱');
        }
        if (!MailService::isConfigured()) {
            return $this->error('请先完善并保存 SMTP 配置');
        }
        $subject = '【TkSwarm】SMTP 配置测试';
        $html = '<div style="font-family:sans-serif;padding:24px;color:#0f172a">'
            . '<h2 style="margin:0 0 12px">SMTP 测试成功</h2>'
            . '<p style="margin:0;line-height:1.6;color:#475569">这是一封由开发管理 · SMTP 配置发出的测试邮件。'
            . '时间：' . htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') . '</p></div>';
        $result = MailService::sendHtml($to, $subject, $html);
        if (empty($result['ok'])) {
            return $this->error('发送失败：' . ($result['error'] ?? 'unknown'), 500);
        }
        return $this->success(['to' => $to], '测试邮件已发送至 ' . $to);
    }
}
