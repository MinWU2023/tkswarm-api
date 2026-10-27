<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\SeedMeta;

class MetaController extends Controller
{
    private const FORMAT_OPTIONS = [
        ['id' => 'auto', 'label' => '自动识别'],
        ['id' => 'user_pass', 'label' => '账号----密码'],
        ['id' => 'user_pass_2fa', 'label' => '账号----密码----2FA'],
        ['id' => 'user_pass_ck', 'label' => '账号----密码----CK'],
        ['id' => 'user_ck', 'label' => '账号----CK'],
        ['id' => 'ck_only', 'label' => '仅 CK'],
        ['id' => 'email_pass_user', 'label' => '邮箱----密码----用户名----国家'],
        ['id' => 'json', 'label' => 'JSON 全参（每行）'],
    ];

    public function index(Request $request): Response
    {
        SeedMeta::ensure();

        $viewPermissions = [];
        foreach (DB::select('SELECT `view`, codes_json FROM view_permissions') as $row) {
            $codes = json_decode($row['codes_json'] ?? '[]', true);
            $viewPermissions[$row['view']] = is_array($codes) ? $codes : [];
        }

        $dicts = [];
        $domains = DB::select('SELECT DISTINCT domain FROM app_dicts ORDER BY domain');
        foreach ($domains as $d) {
            $domain = $d['domain'];
            $dicts[$domain] = $this->dictDomain($domain);
        }

        $statusLabels = [];
        foreach (['account_login', 'proxy_status', 'task_status', 'badge_extra', 'material_status'] as $domain) {
            foreach ($dicts[$domain] ?? [] as $item) {
                $statusLabels[$item['code']] = $item['label'];
            }
        }

        $viewTitles = [];
        foreach ($dicts['view_page'] ?? [] as $item) {
            $viewTitles[$item['code']] = [
                'title' => $item['label'],
                'description' => $item['description'] ?? '',
            ];
        }

        $taskTypes = [];
        foreach ($dicts['task_type'] ?? [] as $x) {
            $taskTypes[$x['code']] = $x['label'];
        }

        $loginRows = $dicts['account_login'] ?? [];
        $editable = array_values(array_filter($loginRows, fn($x) => ($x['editable'] ?? false) === true));
        if (!$editable) {
            $editable = array_values(array_filter(
                $loginRows,
                fn($x) => in_array($x['code'], ['offline', 'online', 'expired'], true)
            ));
        }

        return $this->success([
            'viewPermissions' => $viewPermissions,
            'viewTitles' => $viewTitles,
            'importFormats' => self::FORMAT_OPTIONS,
            'dicts' => $dicts,
            'statusLabels' => $statusLabels,
            'taskTypes' => $taskTypes,
            'proxyProtocols' => $dicts['proxy_protocol'] ?? [],
            'groupTypes' => $dicts['group_type'] ?? [],
            'chatFilters' => $dicts['chat_filter'] ?? [],
            'languages' => $dicts['lang'] ?? [],
            'uiLanguages' => $dicts['ui_lang'] ?? [],
            'replyTones' => $dicts['reply_tone'] ?? [],
            'permModules' => $dicts['perm_module'] ?? [],
            'permSubgroups' => $dicts['perm_subgroup'] ?? [],
            'accountLoginStatuses' => $editable,
            'chatEmojis' => array_map(fn($x) => $x['code'], $dicts['chat_emoji'] ?? []),
            'materialStatuses' => $dicts['material_status'] ?? [],
            'modelTypes' => $dicts['model_type'] ?? [],
            'translationModes' => $dicts['translation_mode'] ?? [],
            'notificationSounds' => $dicts['notification_sound'] ?? [],
            'browserWindowModes' => $dicts['browser_window_mode'] ?? [],
            'browserTypes' => $dicts['browser_type'] ?? [],
            // 仅暴露可真实发私信的比特通道（API/WS 已下线）
            'massChannels' => array_values(array_filter(
                $dicts['mass_channel'] ?? [],
                static fn($x) => ($x['code'] ?? '') === 'bit'
            )),
            'massTargets' => $dicts['mass_target'] ?? [],
            'taskStatuses' => $dicts['task_status'] ?? [],
            'proxyStrategies' => $dicts['proxy_strategy'] ?? [],
            'exportScopes' => $dicts['export_scope'] ?? [],
            'exportFormats' => $dicts['export_format'] ?? [],
            'exportFields' => $dicts['export_field'] ?? [],
            'profileModes' => $dicts['profile_mode'] ?? [],
            'videoDeleteModes' => $dicts['video_delete_mode'] ?? [],
            'distributionStrategies' => $dicts['distribution_strategy'] ?? [],
            'publishStrategies' => $dicts['publish_strategy'] ?? [],
            'materialSources' => $dicts['material_source'] ?? [],
            'materialCopyModes' => $dicts['material_copy'] ?? [],
            'chatConnectScopes' => $dicts['chat_connect_scope'] ?? [],
            'keywordMatches' => $dicts['keyword_match'] ?? [],
            'pollResources' => $dicts['poll_resource'] ?? [],
            'logLevels' => $dicts['log_level'] ?? [],
            // 群发已并入视频任务，不再单独 Tab
            'matrixTabs' => array_values(array_filter(
                $dicts['matrix_tab'] ?? [],
                static fn($x) => ($x['code'] ?? '') !== 'mass'
            )),
            'settingsSections' => $dicts['settings_section'] ?? [],
            'agentTabs' => $dicts['agent_tab'] ?? [],
            'roleStatuses' => $dicts['role_status'] ?? [],
            'userStatuses' => $dicts['user_status'] ?? [],
            'menuVisibilities' => $dicts['menu_visible'] ?? [],
            'templateEnabled' => $dicts['template_enabled'] ?? [],
        ]);
    }

    public function dicts(Request $request, string $domain): Response
    {
        SeedMeta::ensure();
        return $this->success($this->dictDomain($domain));
    }

    private function dictDomain(string $domain): array
    {
        $rows = DB::select(
            'SELECT code, label, sort_order, meta_json FROM app_dicts WHERE domain=? ORDER BY sort_order, code',
            [$domain]
        );
        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode($row['meta_json'] ?? '{}', true);
            if (!is_array($meta)) {
                $meta = [];
            }
            $out[] = array_merge([
                'code' => $row['code'],
                'label' => $row['label'],
                'sortOrder' => (int)$row['sort_order'],
            ], $meta);
        }
        return $out;
    }
}
