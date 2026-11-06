<?php
/**
 * 定制视频 / 视频交付计价配置
 * - types.*.points 为配置分；实际扣除 = points × points_multiplier
 * - 英文 key 写入数据库 settle_type，勿随意改名
 */
declare(strict_types=1);

return [
    'points_multiplier' => (int)(getenv('CUSTOM_VIDEO_POINTS_MULTIPLIER') ?: 50) ?: 50,

    'types' => [
        'cover' => [
            'title' => '视频封面',
            'settlement_title' => '视频封面',
            'description' => '视频未播放前展示的那张“门面图”，会制作一张与视频内容配套的专业封面，直接上传就能用。',
            'points' => 200,
            'unit' => '积分 / 张',
            'guide_group' => 'basic',
        ],
        'standard_voice_subtitle' => [
            'title' => 'AI标准配音+字幕',
            'settlement_title' => 'AI标准配音+字幕',
            'description' => '给视频配上标准的英文AI发音和英文字幕。',
            'points' => 250,
            'unit' => '积分 / 条',
            'guide_group' => 'basic',
        ],
        'minor_language_voice_subtitle' => [
            'title' => '小语种配音+字幕',
            'settlement_title' => '小语种配音+字幕',
            'description' => '把视频的声音和字幕换成其他国家语言（如韩语、俄语等）。',
            'points' => 350,
            'unit' => '积分 / 条',
            'guide_group' => 'basic',
        ],
        'add_language' => [
            'title' => '增加语种',
            'settlement_title' => '增加语种',
            'description' => '在现有基础上，再多加一种外语版本。',
            'points' => 350,
            'unit' => '积分 / 条',
            'guide_group' => 'basic',
        ],
        'creative_template' => [
            'title' => 'AI短视频创意模板（默认5条）',
            'settlement_title' => 'AI短视频创意模板（默认5条）',
            'description' => '相当于打造一个“视频模具”。定好模板样式后，我们会为您替换视频素材和文字，像流水线一样批量制作出套餐内的5条视频。',
            'points' => 3000,
            'unit' => '积分 / 套',
            'guide_group' => 'creative',
        ],
        'ai_video_material' => [
            'title' => 'AI视频素材',
            'settlement_title' => 'AI视频素材',
            'display_title' => 'AI视频素材',
            'settlement_display_title' => 'AI视频素材 600积分/10秒',
            'description' => '自己拍的素材不够时，用AI生成相关画面来补充。',
            'points' => 600,
            'unit' => '积分/10秒',
            'guide_group' => 'creative',
        ],
        'matrix_batch_increase' => [
            'title' => 'AI视频矩阵批量生成数量增加',
            'settlement_title' => 'AI视频矩阵批量生成',
            'description' => '需要比默认5条更多的视频，进一步增加批量生成的数量。同时我们会配套扩充视频素材池，确保大量生成的视频画面丰富且不重复。',
            'points' => 600,
            'unit' => '积分 / 条',
            'guide_group' => 'creative',
        ],
        'video_revision' => [
            'title' => '视频修正（仅限部分文字和标题内容）',
            'settlement_title' => '视频修正（仅限部分文字和标题内容）',
            'description' => '仅限修改错别字、Logo或产品名等小细节（不含推翻重做）。',
            'points' => 200,
            'unit' => '积分 / 次',
            'guide_group' => 'revision',
        ],
        'video_regeneration' => [
            'title' => '视频重生成服务',
            'settlement_title' => '视频重生成服务',
            'description' => '相当于“推倒重来”，可更换脚本、素材或整体风格重新剪辑。',
            'points' => 300,
            'unit' => '积分 / 次',
            'guide_group' => 'revision',
        ],
        'ai_avatar' => [
            'title' => 'AI数字人口播视频（30秒内）',
            'settlement_title' => 'AI数字人口播视频（30秒内）',
            'description' => '使用AI虚拟人出镜介绍产品。',
            'points' => 650,
            'unit' => '积分 / 条',
            'guide_group' => 'basic',
        ],
        'delivery' => [
            'title' => '交付',
            'settlement_title' => '交付',
            'points' => null,
            'unit' => '',
            'guide_group' => null,
        ],
        'other' => [
            'title' => '其他需求',
            'settlement_title' => '其他需求',
            'points' => null,
            'unit' => '',
            'guide_group' => null,
        ],
    ],

    'guide_groups' => [
        'basic' => '基础视频制作',
        'revision' => '视频修改类',
        'creative' => '创意与批量生成',
    ],
];
