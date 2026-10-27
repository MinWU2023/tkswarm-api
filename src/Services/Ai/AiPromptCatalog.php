<?php
declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Database\DB;

/**
 * AI 提示词模板表结构与内置种子。
 */
class AiPromptCatalog
{
    public static function ensure(): void
    {
        self::ensureTables();
        self::seedTemplates();
    }

    public static function ensureTables(): void
    {
        DB::query("CREATE TABLE IF NOT EXISTS `ai_prompt_templates` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `code` VARCHAR(64) NOT NULL DEFAULT '',
          `name` VARCHAR(128) NOT NULL,
          `category` VARCHAR(64) NOT NULL DEFAULT 'general',
          `description` VARCHAR(512) NOT NULL DEFAULT '',
          `content` MEDIUMTEXT NOT NULL,
          `variables_json` MEDIUMTEXT NOT NULL,
          `enabled` TINYINT(1) NOT NULL DEFAULT 1,
          `sort_order` INT NOT NULL DEFAULT 0,
          `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
          `owner_id` INT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `uk_ai_prompt_code` (`code`),
          KEY `idx_ai_prompt_cat` (`category`),
          KEY `idx_ai_prompt_owner` (`owner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function seedTemplates(): void
    {
        foreach (self::definitions() as $i => $t) {
            $vars = AiPromptHelper::normalizeVariables($t['variables'], $t['content']);
            $code = (string)$t['code'];
            $exists = DB::selectOne('SELECT id FROM ai_prompt_templates WHERE code=?', [$code]);
            if ($exists) {
                // 仅刷新内置模板的变量/内容说明，不覆盖用户已改名称外的业务字段：内置可更新 content+variables
                DB::query(
                    'UPDATE ai_prompt_templates SET name=?, category=?, description=?, content=?, variables_json=?, sort_order=?, is_builtin=1 WHERE code=? AND is_builtin=1',
                    [
                        $t['name'],
                        $t['category'],
                        $t['description'],
                        $t['content'],
                        json_encode($vars, JSON_UNESCAPED_UNICODE),
                        $i + 1,
                        $code,
                    ]
                );
                continue;
            }
            DB::query(
                'INSERT INTO ai_prompt_templates(code, name, category, description, content, variables_json, enabled, sort_order, is_builtin)
                 VALUES (?,?,?,?,?,?,1,?,1)',
                [
                    $code,
                    $t['name'],
                    $t['category'],
                    $t['description'],
                    $t['content'],
                    json_encode($vars, JSON_UNESCAPED_UNICODE),
                    $i + 1,
                ]
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'code' => 'translate',
                'name' => '多语言翻译',
                'category' => 'translate',
                'description' => '将文本翻译为目标语言，不同变量：text / target_lang',
                'content' => "你是专业翻译。请将下列内容翻译为 {{target_lang}}，只输出译文，不要解释。\n\n原文：\n{{text}}",
                'variables' => [
                    ['key' => 'text', 'label' => '原文', 'required' => true, 'type' => 'textarea', 'example' => 'Hello, how are you?'],
                    ['key' => 'target_lang', 'label' => '目标语言', 'required' => true, 'default' => '中文', 'example' => '中文'],
                ],
            ],
            [
                'code' => 'customer_reply',
                'name' => '客服回复生成',
                'category' => 'chat',
                'description' => '根据客户消息生成回复，变量：customer_msg / persona / product',
                'content' => "你是 {{persona}}，正在为产品「{{product}}」提供客服支持。\n请针对客户消息给出礼貌、简洁的回复（不超过 120 字）。\n\n客户消息：\n{{customer_msg}}",
                'variables' => [
                    ['key' => 'customer', 'label' => '人设', 'required' => true, 'default' => '专业友好的客服助手', 'type' => 'text'],
                    ['key' => 'product', 'label' => '产品名称', 'required' => true, 'default' => 'TkSwarm', 'type' => 'text'],
                    ['key' => 'customer_msg', 'label' => '客户消息', 'required' => true, 'type' => 'textarea', 'example' => '你们支持批量养号吗？'],
                ],
            ],
            [
                'code' => 'video_caption',
                'name' => '视频文案生成',
                'category' => 'publish',
                'description' => '短视频标题/文案，变量：topic / style / hashtags',
                'content' => "请为短视频写 3 条{{style}}风格文案，主题是「{{topic}}」。\n要求：口语化、适合 TikTok，每条单独一行。\n标签要求：{{hashtags}}",
                'variables' => [
                    ['key' => 'topic', 'label' => '主题', 'required' => true, 'type' => 'text', 'example' => '夏季防晒'],
                    ['key' => 'style', 'label' => '风格', 'required' => false, 'default' => '活泼种草', 'type' => 'text'],
                    ['key' => 'hashtags', 'label' => '标签要求', 'required' => false, 'default' => '附带 3 个相关 hashtag', 'type' => 'text'],
                ],
            ],
            [
                'code' => 'summarize',
                'name' => '内容摘要',
                'category' => 'general',
                'description' => '长文摘要，变量：content / max_words',
                'content' => "请把下面内容概括为不超过 {{max_words}} 字的中文摘要，保留关键事实：\n\n{{content}}",
                'variables' => [
                    ['key' => 'content', 'label' => '原文内容', 'required' => true, 'type' => 'textarea'],
                    ['key' => 'max_words', 'label' => '字数上限', 'required' => false, 'default' => '120', 'type' => 'number', 'example' => '120'],
                ],
            ],
            [
                'code' => 'lead_comment_filter',
                'name' => '拓客评论筛选',
                'category' => 'lead',
                'description' => '筛买家意愿评论（含颜文字喜欢），返回 intent_score 1-10、comment_text_zh、中文 reason。变量：comments_json',
                'content' => "你是 B2B 拓客助手。以下是从 TikTok 视频评论中提取的用户列表（JSON 数组）：\n{{comments_json}}\n\n"
                    . "目标：只保留「买家意愿」评论（含明确求购文案，以及表示喜欢/心动的颜文字、emoji）。\n"
                    . "保留（买家信号）：\n"
                    . "1) 文案：how much / what's the price / MOQ / quote / 多少钱 / 询价 / 要采购 / where to buy / "
                    . "do you ship / I need / looking to buy / can I get a catalog 等求购、询价、问交付；\n"
                    . "2) 颜文字/emoji 表喜欢：❤️ 🔥 😍 🥰 👀 ✨ (◕‿◕) ♡ 等表达喜欢、心动、想要（非嘲讽）。\n"
                    . "必须排除（卖家/广告）：factory/manufacturer/supplier/wholesale、sold directly、we can offer、"
                    . "DM me for price（推销自己）、affiliate、招代理、代运营/独立站推销、欢迎合作（服务商获客）。\n"
                    . "也排除：无关闲聊、嘲讽/负面、创作者自己回复。\n"
                    . "判断原则：主语是「我要买/问价/喜欢想要」才保留；主语是「我有货/我能供」一律丢弃。\n\n"
                    . "对每条保留评论打分 intent_score（整数 1～10）：\n"
                    . "- 明确询价/求购/问 MOQ/交付的文案：7～10（文案分必须明显高于纯颜文字）；\n"
                    . "- 文案含兴趣但仍含糊：5～7；\n"
                    . "- 仅颜文字/emoji 表喜欢：3～5（不得超过同场景下明确文案的分数）；\n"
                    . "- 不要给卖家广告打分（直接丢弃）。\n\n"
                    . "对每条保留评论还必须提供：\n"
                    . "- comment_text_zh：评论的简体中文译文（emoji/颜文字可保留；若原文已是中文可复述或略写）；\n"
                    . "- reason：必须用简体中文说明为何判定为买家意向。\n\n"
                    . "仅返回 JSON 数组，每项字段：username, nickname, profile_url, comment_text, comment_text_zh, reason, intent_score\n"
                    . "reason 与 comment_text_zh 必须为简体中文；intent_score 为 1～10 整数。若无符合项，返回 []。",
                'variables' => [
                    ['key' => 'comments_json', 'label' => '评论 JSON', 'required' => true, 'type' => 'textarea', 'example' => '[{"username":"user1","comment_text":"how much?"}]'],
                ],
            ],
            [
                'code' => 'lead_dm_opener',
                'name' => '拓客私信开场白',
                'category' => 'lead',
                'description' => '根据意向评论生成合规 TikTok 私信开场白。变量：username / nickname / comment_text / product',
                'content' => "你是 B2B 外贸拓客助手，需要给 TikTok 用户写一条私信开场白。\n"
                    . "对方用户名：{{username}}\n昵称：{{nickname}}\n对方评论：{{comment_text}}\n产品/业务：{{product}}\n\n"
                    . "要求：\n"
                    . "1. 结合对方评论自然回应，语气友好专业，像真人，不要像群发广告；\n"
                    . "2. 用英语为主（对方是 TikTok 国际用户），可夹少量简单词；长度 1～3 句，不超过 220 字符；\n"
                    . "3. 严格遵守 TikTok 私信规范：禁止色情/暴力/仇恨/骚扰；禁止诱导站外支付诈骗；"
                    . "禁止承诺暴利、保证赚钱；禁止索要密码/验证码；不要刷屏式硬广；\n"
                    . "4. 不要使用敏感词：WhatsApp/Telegram/微信/加我/最低价包邮/免费送/点击链接领奖 等引流站外诱导语；"
                    . "如需继续沟通，只说可以继续聊聊需求；\n"
                    . "5. 不要使用全大写恐吓语、过多感叹号与 emoji；不要虚构折扣数字；\n"
                    . "6. 只输出最终私信正文纯文本，不要引号、不要 markdown、不要解释。",
                'variables' => [
                    ['key' => 'username', 'label' => '对方用户名', 'required' => true, 'type' => 'text', 'example' => 'buyer123'],
                    ['key' => 'nickname', 'label' => '对方昵称', 'required' => false, 'default' => '', 'type' => 'text'],
                    ['key' => 'comment_text', 'label' => '评论内容', 'required' => true, 'type' => 'textarea', 'example' => 'How much for MOQ 100?'],
                    ['key' => 'product', 'label' => '产品/业务', 'required' => false, 'default' => 'our products', 'type' => 'text'],
                ],
            ],
            [
                'code' => 'account_ban_assess',
                'name' => '账号违规封停判定',
                'category' => 'account',
                'description' => '根据 TikTok 系统通知判断账号是否被违规封停，返回 is_banned / trigger_time / recover_time / reason。变量：notices_json / account / now',
                'content' => "你是 TikTok 账号风控分析助手。下面是该账号收到的系统通知（JSON 数组，含通知时间与正文）：\n"
                    . "{{notices_json}}\n\n"
                    . "账号：{{account}}\n当前时间：{{now}}\n\n"
                    . "任务：判断这些通知是否表示该账号已被 TikTok 违规封停 / 禁用 / 限权，以及预计何时恢复。\n"
                    . "判定要求：\n"
                    . "1. 只有通知明确表示账号被 ban / suspended / 停用 / 封禁 / 永久封停 / 严重违反社区规范导致账号不可用时，"
                    . "is_banned 才为 true；\n"
                    . "2. 单纯的警告、单条内容被删除、单条视频被限流（账号仍可用）→ is_banned=false；\n"
                    . "3. recover_time：通知给出解封/恢复时间（如 suspended until 2024-05-08、将于 2024-05-08 恢复）就按该时间输出；"
                    . "只给时长（suspended for 7 days、7 天后恢复）则以通知时间推算；没有给出则输出空字符串；\n"
                    . "4. trigger_time：触发封停的时间（通知时间或通知中写明的时间），无法确定输出空字符串；\n"
                    . "5. 信息不足、无法判断时 is_banned=false。\n\n"
                    . "仅输出一个 JSON 对象，不要 markdown、不要任何解释文字，字段固定为：\n"
                    . "{\"is_banned\": true|false, \"trigger_time\": \"YYYY-MM-DD HH:MM\" 或 \"\", "
                    . "\"recover_time\": \"YYYY-MM-DD HH:MM\" 或 \"\", \"reason\": \"简体中文原因解释\"}",
                'variables' => [
                    ['key' => 'notices_json', 'label' => '通知 JSON', 'required' => true, 'type' => 'textarea', 'example' => '[{"time":"2024-05-01 10:00","title":"Account suspended","content":"Your account was suspended for violating Community Guidelines..."}]'],
                    ['key' => 'account', 'label' => '账号', 'required' => false, 'default' => '', 'type' => 'text', 'example' => 'kenziealiya61'],
                    ['key' => 'now', 'label' => '当前时间', 'required' => false, 'default' => '', 'type' => 'text'],
                ],
            ],
            [
                'code' => 'role_system',
                'name' => '智能体系统提示',
                'category' => 'agent',
                'description' => '拼装智能体 system prompt，变量：role / mission / rules / language',
                'content' => "角色：{{role}}\n任务：{{mission}}\n规则：{{rules}}\n回复语言：{{language}}\n请始终遵守以上设定。",
                'variables' => [
                    ['key' => 'role', 'label' => '角色', 'required' => true, 'type' => 'text', 'example' => '资深电商顾问'],
                    ['key' => 'mission', 'label' => '任务目标', 'required' => true, 'type' => 'textarea'],
                    ['key' => 'rules', 'label' => '规则', 'required' => false, 'default' => '不编造事实；语气礼貌', 'type' => 'textarea'],
                    ['key' => 'language', 'label' => '语言', 'required' => false, 'default' => '中文', 'type' => 'text'],
                ],
            ],
        ];
    }

    /** @return list<array{code:string,label:string}> */
    public static function categories(): array
    {
        return [
            ['code' => 'general', 'label' => '通用'],
            ['code' => 'translate', 'label' => '翻译'],
            ['code' => 'chat', 'label' => '客服'],
            ['code' => 'publish', 'label' => '发布文案'],
            ['code' => 'agent', 'label' => '智能体'],
            ['code' => 'lead', 'label' => '拓客'],
            ['code' => 'account', 'label' => '账号'],
        ];
    }
}
