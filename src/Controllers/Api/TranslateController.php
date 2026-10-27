<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Translator;

class TranslateController extends Controller
{
    public function config(Request $request): Response
    {
        $cfg = Translator::config();
        $cfg['hasApiKey'] = $cfg['translationApiKey'] !== '';
        $cfg['translationApiKey'] = $cfg['hasApiKey'] ? '******' : '';
        $cfg['remaining'] = $cfg['translationQuota'] > 0
            ? max(0, $cfg['translationQuota'] - $cfg['translationUsed'])
            : null;
        return $this->success($cfg);
    }

    public function translate(Request $request): Response
    {
        $text = trim((string)$request->input('text', ''));
        $from = trim((string)$request->input('from', 'auto')) ?: 'auto';
        $to = trim((string)$request->input('to', 'zh')) ?: 'zh';
        if ($text === '' || mb_strlen($text) > 5000) {
            return $this->error('翻译内容无效', 422);
        }
        try {
            $result = Translator::translate($text, $from, mb_substr($to, 0, 20));
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage() ?: '翻译失败', 422);
        }
    }

    public function detect(Request $request): Response
    {
        $text = trim((string)$request->input('text', ''));
        if ($text === '') {
            return $this->error('没有可检测的文本', 422);
        }
        try {
            return $this->success(Translator::detectLanguage($text));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage() ?: '检测失败', 422);
        }
    }

    /** 聊天记录即时翻译：自动识别语种 → 中文，无需翻译设置 */
    public function instant(Request $request): Response
    {
        $text = trim((string)$request->input('text', ''));
        $to = trim((string)$request->input('to', 'zh')) ?: 'zh';
        if ($text === '' || mb_strlen($text) > 5000) {
            return $this->error('翻译内容无效', 422);
        }
        try {
            return $this->success(Translator::translateInstant($text, mb_substr($to, 0, 20)));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage() ?: '翻译失败', 422);
        }
    }
}
