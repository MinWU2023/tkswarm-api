<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Database\DB;
use App\Services\Ai\AiPromptHelper;

/**
 * HTML 邮件模板表结构与内置种子。
 */
class MailTemplateCatalog
{
    public static function ensure(): void
    {
        self::ensureTables();
        self::seedTemplates();
    }

    public static function ensureTables(): void
    {
        DB::query(
            "CREATE TABLE IF NOT EXISTS `mail_templates` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `code` VARCHAR(64) NOT NULL DEFAULT '',
              `name` VARCHAR(128) NOT NULL DEFAULT '',
              `subject` VARCHAR(255) NOT NULL DEFAULT '',
              `html_body` MEDIUMTEXT NOT NULL,
              `description` VARCHAR(512) NOT NULL DEFAULT '',
              `variables_json` MEDIUMTEXT NULL,
              `status` TINYINT(1) NOT NULL DEFAULT 1,
              `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_mail_templates_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function seedTemplates(): void
    {
        foreach (self::definitions() as $t) {
            $vars = AiPromptHelper::normalizeVariables($t['variables'], $t['html_body'] . ' ' . $t['subject']);
            $code = (string)$t['code'];
            $exists = DB::selectOne('SELECT id FROM mail_templates WHERE code=?', [$code]);
            $payload = [
                $t['name'],
                $t['subject'],
                $t['html_body'],
                $t['description'],
                json_encode($vars, JSON_UNESCAPED_UNICODE),
            ];
            if ($exists) {
                DB::query(
                    'UPDATE mail_templates SET name=?, subject=?, html_body=?, description=?, variables_json=?, is_builtin=1, status=1
                     WHERE code=? AND is_builtin=1',
                    [...$payload, $code]
                );
                continue;
            }
            DB::query(
                'INSERT INTO mail_templates(code, name, subject, html_body, description, variables_json, status, is_builtin)
                 VALUES (?,?,?,?,?,?,1,1)',
                [$code, ...$payload]
            );
        }
    }

    /** @return array<string,mixed>|null */
    public static function findByCode(string $code): ?array
    {
        self::ensure();
        $row = DB::selectOne('SELECT * FROM mail_templates WHERE code=? AND status=1 LIMIT 1', [$code]);
        return $row ?: null;
    }

    /**
     * @param array<string,scalar|null> $values
     * @return array{subject:string,html:string}
     */
    public static function renderByCode(string $code, array $values): array
    {
        $row = self::findByCode($code);
        if (!$row) {
            throw new \RuntimeException('邮件模板不存在或已停用：' . $code);
        }
        return [
            'subject' => AiPromptHelper::render((string)$row['subject'], $values),
            'html' => AiPromptHelper::render((string)$row['html_body'], $values),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'code' => 'pull_data_analysis_report',
                'name' => '拉取数据 AI 分析报告',
                'description' => 'Studio 拉取数据成功后发送给管理用户的分析报告邮件',
                'subject' => '【TkSwarm】{{account}} 账号数据洞察报告 · {{report_date}}',
                'variables' => [
                    ['key' => 'account', 'label' => '账号', 'required' => true],
                    ['key' => 'nickname', 'label' => '昵称', 'required' => false, 'default' => ''],
                    ['key' => 'report_date', 'label' => '报告日期', 'required' => true],
                    ['key' => 'period', 'label' => '统计周期', 'required' => false, 'default' => '近 28 天'],
                    ['key' => 'metric_cards_html', 'label' => '指标卡片 HTML', 'required' => false, 'default' => ''],
                    ['key' => 'trends_html', 'label' => '数据走势 HTML', 'required' => false, 'default' => ''],
                    ['key' => 'summary_html', 'label' => '概况', 'required' => false, 'default' => ''],
                    ['key' => 'strengths_html', 'label' => '优点', 'required' => false, 'default' => ''],
                    ['key' => 'issues_html', 'label' => '问题与原因', 'required' => false, 'default' => ''],
                    ['key' => 'actions_html', 'label' => '改进方案', 'required' => false, 'default' => ''],
                    ['key' => 'raw_metrics_html', 'label' => '原始指标摘要', 'required' => false, 'default' => ''],
                    ['key' => 'footer_note', 'label' => '页脚说明', 'required' => false, 'default' => '本邮件由 TkSwarm 计划任务自动生成，请勿直接回复。'],
                ],
                'html_body' => self::reportHtmlBody(),
            ],
        ];
    }

    private static function reportHtmlBody(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>账号数据洞察报告</title>
</head>
<body style="margin:0;padding:0;background:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'PingFang SC','Microsoft YaHei',sans-serif;color:#1a1d26;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0f2f5;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,0.08);">
          <tr>
            <td style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 55%,#0ea5e9 100%);padding:28px 32px;">
              <div style="font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:rgba(255,255,255,0.72);font-weight:600;">TkSwarm Insight</div>
              <div style="margin-top:10px;font-size:24px;line-height:1.35;font-weight:700;color:#ffffff;">账号数据洞察报告</div>
              <div style="margin-top:8px;font-size:14px;color:rgba(255,255,255,0.86);">
                {{account}}{{nickname}} · {{period}} · {{report_date}}
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 32px 8px;">
              <div style="font-size:13px;font-weight:600;color:#64748b;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:12px;">关键指标</div>
              {{metric_cards_html}}
            </td>
          </tr>
          <tr>
            <td style="padding:8px 32px 8px;">
              <div style="font-size:13px;font-weight:600;color:#64748b;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:12px;">数据走势</div>
              {{trends_html}}
            </td>
          </tr>
          <tr>
            <td style="padding:8px 32px 8px;">
              <div style="border:1px solid #e8edf3;border-radius:12px;padding:20px 22px;margin-bottom:14px;">
                <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:10px;">概况总览</div>
                <div style="font-size:14px;line-height:1.75;color:#334155;">{{summary_html}}</div>
              </div>
              <div style="border:1px solid #dcfce7;background:#f0fdf4;border-radius:12px;padding:20px 22px;margin-bottom:14px;">
                <div style="font-size:15px;font-weight:700;color:#166534;margin-bottom:10px;">表现亮点</div>
                <div style="font-size:14px;line-height:1.75;color:#14532d;">{{strengths_html}}</div>
              </div>
              <div style="border:1px solid #fee2e2;background:#fef2f2;border-radius:12px;padding:20px 22px;margin-bottom:14px;">
                <div style="font-size:15px;font-weight:700;color:#991b1b;margin-bottom:10px;">问题与原因</div>
                <div style="font-size:14px;line-height:1.75;color:#7f1d1d;">{{issues_html}}</div>
              </div>
              <div style="border:1px solid #dbeafe;background:#eff6ff;border-radius:12px;padding:20px 22px;margin-bottom:14px;">
                <div style="font-size:15px;font-weight:700;color:#1e40af;margin-bottom:10px;">改进方案</div>
                <div style="font-size:14px;line-height:1.75;color:#1e3a8a;">{{actions_html}}</div>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 32px 24px;">
              <div style="font-size:13px;font-weight:600;color:#64748b;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:10px;">数据摘要</div>
              <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;font-size:13px;line-height:1.7;color:#475569;">
                {{raw_metrics_html}}
              </div>
            </td>
          </tr>
          <tr>
            <td style="background:#0f172a;padding:18px 32px;text-align:center;">
              <div style="font-size:12px;color:rgba(255,255,255,0.65);line-height:1.6;">{{footer_note}}</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
