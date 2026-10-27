-- 040_dashscope_bailian_free_models.sql
-- 将阿里云百炼（华北2/北京）文档中标注「100万 Token」免费额度的 Chat 可用模型
-- 关联到通义千问平台（ai_platforms.code = dashscope）
-- 数据来源：help.aliyun.com/zh/model-studio/model-pricing（新人免费额度）
-- 已排除：TTS / ASR / Embedding / Rerank / 生图视频等非 Chat Completions 主路径模型
-- 幂等：可重复执行

SET @dashscope_pid := (SELECT id FROM ai_platforms WHERE code = 'dashscope' LIMIT 1);

-- 平台不存在则跳过后续（避免空 platform_id）
SET @dashscope_pid := IFNULL(@dashscope_pid, 0);

UPDATE ai_platform_models SET is_default = 0 WHERE platform_id = @dashscope_pid AND @dashscope_pid > 0;

INSERT INTO ai_platform_models (platform_id, code, name, enabled, is_default, sort_order)
SELECT @dashscope_pid, x.code, x.name, 1, x.is_default, x.sort_order
FROM (
  SELECT 'qwen-plus' AS code, '通义千问 Plus' AS name, 1 AS is_default, 0 AS sort_order
  UNION ALL
  SELECT 'qwen-turbo' AS code, '通义千问 Turbo' AS name, 0 AS is_default, 1 AS sort_order
  UNION ALL
  SELECT 'qwen-flash' AS code, '通义千问 Flash' AS name, 0 AS is_default, 2 AS sort_order
  UNION ALL
  SELECT 'qwen3.8-2.4t-a95b' AS code, 'qwen3.8-2.4t-a95b' AS name, 0 AS is_default, 3 AS sort_order
  UNION ALL
  SELECT 'qwen3.8-27b' AS code, 'qwen3.8-27b' AS name, 0 AS is_default, 4 AS sort_order
  UNION ALL
  SELECT 'qwen3.8-flash' AS code, 'Qwen3.8 Flash' AS name, 0 AS is_default, 5 AS sort_order
  UNION ALL
  SELECT 'qwen3.8-max' AS code, 'Qwen3.8 Max' AS name, 0 AS is_default, 6 AS sort_order
  UNION ALL
  SELECT 'qwen3.8-max-0902' AS code, 'qwen3.8-max-0902' AS name, 0 AS is_default, 7 AS sort_order
  UNION ALL
  SELECT 'qwen3.7-flash' AS code, 'qwen3.7-flash' AS name, 0 AS is_default, 8 AS sort_order
  UNION ALL
  SELECT 'qwen3.7-flash-2026-07-15' AS code, 'qwen3.7-flash-2026-07-15' AS name, 0 AS is_default, 9 AS sort_order
  UNION ALL
  SELECT 'qwen3.7-max' AS code, 'qwen3.7-max' AS name, 0 AS is_default, 10 AS sort_order
  UNION ALL
  SELECT 'qwen3.7-max-2026-05-17' AS code, 'qwen3.7-max-2026-05-17' AS name, 0 AS is_default, 11 AS sort_order
  UNION ALL
  SELECT 'qwen3.7-max-2026-05-20' AS code, 'qwen3.7-max-2026-05-20' AS name, 0 AS is_default, 12 AS sort_order
  UNION ALL
  SELECT 'qwen3.7-max-2026-06-08' AS code, 'qwen3.7-max-2026-06-08' AS name, 0 AS is_default, 13 AS sort_order
  UNION ALL
  SELECT 'qwen3.7-max-preview' AS code, 'qwen3.7-max-preview' AS name, 0 AS is_default, 14 AS sort_order
  UNION ALL
  SELECT 'qwen3.7-plus' AS code, 'Qwen3.7 Plus' AS name, 0 AS is_default, 15 AS sort_order
  UNION ALL
  SELECT 'qwen3.7-plus-2026-05-26' AS code, 'qwen3.7-plus-2026-05-26' AS name, 0 AS is_default, 16 AS sort_order
  UNION ALL
  SELECT 'qwen3.6-27b' AS code, 'qwen3.6-27b' AS name, 0 AS is_default, 17 AS sort_order
  UNION ALL
  SELECT 'qwen3.6-35b-a3b' AS code, 'qwen3.6-35b-a3b' AS name, 0 AS is_default, 18 AS sort_order
  UNION ALL
  SELECT 'qwen3.6-flash' AS code, 'qwen3.6-flash' AS name, 0 AS is_default, 19 AS sort_order
  UNION ALL
  SELECT 'qwen3.6-flash-2026-04-16' AS code, 'qwen3.6-flash-2026-04-16' AS name, 0 AS is_default, 20 AS sort_order
  UNION ALL
  SELECT 'qwen3.6-max-preview' AS code, 'qwen3.6-max-preview' AS name, 0 AS is_default, 21 AS sort_order
  UNION ALL
  SELECT 'qwen3.6-plus' AS code, 'qwen3.6-plus' AS name, 0 AS is_default, 22 AS sort_order
  UNION ALL
  SELECT 'qwen3.6-plus-2026-04-02' AS code, 'qwen3.6-plus-2026-04-02' AS name, 0 AS is_default, 23 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-122b-a10b' AS code, 'qwen3.5-122b-a10b' AS name, 0 AS is_default, 24 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-27b' AS code, 'qwen3.5-27b' AS name, 0 AS is_default, 25 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-35b-a3b' AS code, 'qwen3.5-35b-a3b' AS name, 0 AS is_default, 26 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-397b-a17b' AS code, 'qwen3.5-397b-a17b' AS name, 0 AS is_default, 27 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-flash' AS code, 'Qwen3.5 Flash' AS name, 0 AS is_default, 28 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-flash-2026-02-23' AS code, 'qwen3.5-flash-2026-02-23' AS name, 0 AS is_default, 29 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-omni-flash' AS code, 'qwen3.5-omni-flash' AS name, 0 AS is_default, 30 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-omni-flash-2026-03-15' AS code, 'qwen3.5-omni-flash-2026-03-15' AS name, 0 AS is_default, 31 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-omni-plus' AS code, 'qwen3.5-omni-plus' AS name, 0 AS is_default, 32 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-omni-plus-2026-03-15' AS code, 'qwen3.5-omni-plus-2026-03-15' AS name, 0 AS is_default, 33 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-plus' AS code, 'qwen3.5-plus' AS name, 0 AS is_default, 34 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-plus-2026-02-15' AS code, 'qwen3.5-plus-2026-02-15' AS name, 0 AS is_default, 35 AS sort_order
  UNION ALL
  SELECT 'qwen3.5-plus-2026-04-20' AS code, 'qwen3.5-plus-2026-04-20' AS name, 0 AS is_default, 36 AS sort_order
  UNION ALL
  SELECT 'qwen3-14b' AS code, 'qwen3-14b' AS name, 0 AS is_default, 37 AS sort_order
  UNION ALL
  SELECT 'qwen3-235b-a22b' AS code, 'qwen3-235b-a22b' AS name, 0 AS is_default, 38 AS sort_order
  UNION ALL
  SELECT 'qwen3-235b-a22b-instruct-2507' AS code, 'qwen3-235b-a22b-instruct-2507' AS name, 0 AS is_default, 39 AS sort_order
  UNION ALL
  SELECT 'qwen3-235b-a22b-thinking-2507' AS code, 'qwen3-235b-a22b-thinking-2507' AS name, 0 AS is_default, 40 AS sort_order
  UNION ALL
  SELECT 'qwen3-30b-a3b' AS code, 'qwen3-30b-a3b' AS name, 0 AS is_default, 41 AS sort_order
  UNION ALL
  SELECT 'qwen3-30b-a3b-instruct-2507' AS code, 'qwen3-30b-a3b-instruct-2507' AS name, 0 AS is_default, 42 AS sort_order
  UNION ALL
  SELECT 'qwen3-30b-a3b-thinking-2507' AS code, 'qwen3-30b-a3b-thinking-2507' AS name, 0 AS is_default, 43 AS sort_order
  UNION ALL
  SELECT 'qwen3-32b' AS code, 'qwen3-32b' AS name, 0 AS is_default, 44 AS sort_order
  UNION ALL
  SELECT 'qwen3-8b' AS code, 'qwen3-8b' AS name, 0 AS is_default, 45 AS sort_order
  UNION ALL
  SELECT 'qwen3-coder-30b-a3b-instruct' AS code, 'qwen3-coder-30b-a3b-instruct' AS name, 0 AS is_default, 46 AS sort_order
  UNION ALL
  SELECT 'qwen3-coder-480b-a35b-instruct' AS code, 'qwen3-coder-480b-a35b-instruct' AS name, 0 AS is_default, 47 AS sort_order
  UNION ALL
  SELECT 'qwen3-coder-flash' AS code, 'qwen3-coder-flash' AS name, 0 AS is_default, 48 AS sort_order
  UNION ALL
  SELECT 'qwen3-coder-flash-2025-07-28' AS code, 'qwen3-coder-flash-2025-07-28' AS name, 0 AS is_default, 49 AS sort_order
  UNION ALL
  SELECT 'qwen3-coder-next' AS code, 'qwen3-coder-next' AS name, 0 AS is_default, 50 AS sort_order
  UNION ALL
  SELECT 'qwen3-coder-plus' AS code, 'qwen3-coder-plus' AS name, 0 AS is_default, 51 AS sort_order
  UNION ALL
  SELECT 'qwen3-coder-plus-2025-07-22' AS code, 'qwen3-coder-plus-2025-07-22' AS name, 0 AS is_default, 52 AS sort_order
  UNION ALL
  SELECT 'qwen3-coder-plus-2025-09-23' AS code, 'qwen3-coder-plus-2025-09-23' AS name, 0 AS is_default, 53 AS sort_order
  UNION ALL
  SELECT 'qwen3-livetranslate-flash' AS code, 'qwen3-livetranslate-flash' AS name, 0 AS is_default, 54 AS sort_order
  UNION ALL
  SELECT 'qwen3-livetranslate-flash-2025-12-01' AS code, 'qwen3-livetranslate-flash-2025-12-01' AS name, 0 AS is_default, 55 AS sort_order
  UNION ALL
  SELECT 'qwen3-max' AS code, 'qwen3-max' AS name, 0 AS is_default, 56 AS sort_order
  UNION ALL
  SELECT 'qwen3-max-2025-09-23' AS code, 'qwen3-max-2025-09-23' AS name, 0 AS is_default, 57 AS sort_order
  UNION ALL
  SELECT 'qwen3-max-2026-01-23' AS code, 'qwen3-max-2026-01-23' AS name, 0 AS is_default, 58 AS sort_order
  UNION ALL
  SELECT 'qwen3-max-preview' AS code, 'qwen3-max-preview' AS name, 0 AS is_default, 59 AS sort_order
  UNION ALL
  SELECT 'qwen3-next-80b-a3b-instruct' AS code, 'qwen3-next-80b-a3b-instruct' AS name, 0 AS is_default, 60 AS sort_order
  UNION ALL
  SELECT 'qwen3-next-80b-a3b-thinking' AS code, 'qwen3-next-80b-a3b-thinking' AS name, 0 AS is_default, 61 AS sort_order
  UNION ALL
  SELECT 'qwen3-omni-30b-a3b-captioner' AS code, 'qwen3-omni-30b-a3b-captioner' AS name, 0 AS is_default, 62 AS sort_order
  UNION ALL
  SELECT 'qwen3-omni-flash' AS code, 'qwen3-omni-flash' AS name, 0 AS is_default, 63 AS sort_order
  UNION ALL
  SELECT 'qwen3-omni-flash-2025-09-15' AS code, 'qwen3-omni-flash-2025-09-15' AS name, 0 AS is_default, 64 AS sort_order
  UNION ALL
  SELECT 'qwen3-omni-flash-2025-12-01' AS code, 'qwen3-omni-flash-2025-12-01' AS name, 0 AS is_default, 65 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-235b-a22b-instruct' AS code, 'qwen3-vl-235b-a22b-instruct' AS name, 0 AS is_default, 66 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-235b-a22b-thinking' AS code, 'qwen3-vl-235b-a22b-thinking' AS name, 0 AS is_default, 67 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-30b-a3b-instruct' AS code, 'qwen3-vl-30b-a3b-instruct' AS name, 0 AS is_default, 68 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-30b-a3b-thinking' AS code, 'qwen3-vl-30b-a3b-thinking' AS name, 0 AS is_default, 69 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-32b-instruct' AS code, 'qwen3-vl-32b-instruct' AS name, 0 AS is_default, 70 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-32b-thinking' AS code, 'qwen3-vl-32b-thinking' AS name, 0 AS is_default, 71 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-8b-instruct' AS code, 'qwen3-vl-8b-instruct' AS name, 0 AS is_default, 72 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-8b-thinking' AS code, 'qwen3-vl-8b-thinking' AS name, 0 AS is_default, 73 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-flash' AS code, 'qwen3-vl-flash' AS name, 0 AS is_default, 74 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-flash-2025-10-15' AS code, 'qwen3-vl-flash-2025-10-15' AS name, 0 AS is_default, 75 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-flash-2026-01-22' AS code, 'qwen3-vl-flash-2026-01-22' AS name, 0 AS is_default, 76 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-plus' AS code, 'qwen3-vl-plus' AS name, 0 AS is_default, 77 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-plus-2025-09-23' AS code, 'qwen3-vl-plus-2025-09-23' AS name, 0 AS is_default, 78 AS sort_order
  UNION ALL
  SELECT 'qwen3-vl-plus-2025-12-19' AS code, 'qwen3-vl-plus-2025-12-19' AS name, 0 AS is_default, 79 AS sort_order
  UNION ALL
  SELECT 'qwen-coder-plus' AS code, 'qwen-coder-plus' AS name, 0 AS is_default, 80 AS sort_order
  UNION ALL
  SELECT 'qwen-coder-turbo' AS code, 'qwen-coder-turbo' AS name, 0 AS is_default, 81 AS sort_order
  UNION ALL
  SELECT 'qwen-flash-2025-07-28' AS code, 'qwen-flash-2025-07-28' AS name, 0 AS is_default, 82 AS sort_order
  UNION ALL
  SELECT 'qwen-flash-character' AS code, 'qwen-flash-character' AS name, 0 AS is_default, 83 AS sort_order
  UNION ALL
  SELECT 'qwen-flash-character-2026-02-26' AS code, 'qwen-flash-character-2026-02-26' AS name, 0 AS is_default, 84 AS sort_order
  UNION ALL
  SELECT 'qwen-long' AS code, '通义千问 Long' AS name, 0 AS is_default, 85 AS sort_order
  UNION ALL
  SELECT 'qwen-long-2025-01-25' AS code, 'qwen-long-2025-01-25' AS name, 0 AS is_default, 86 AS sort_order
  UNION ALL
  SELECT 'qwen-long-latest' AS code, '通义千问 Long Latest' AS name, 0 AS is_default, 87 AS sort_order
  UNION ALL
  SELECT 'qwen-math-plus' AS code, 'qwen-math-plus' AS name, 0 AS is_default, 88 AS sort_order
  UNION ALL
  SELECT 'qwen-max' AS code, '通义千问 Max' AS name, 0 AS is_default, 89 AS sort_order
  UNION ALL
  SELECT 'qwen-mt-flash' AS code, 'qwen-mt-flash' AS name, 0 AS is_default, 90 AS sort_order
  UNION ALL
  SELECT 'qwen-mt-lite' AS code, 'qwen-mt-lite' AS name, 0 AS is_default, 91 AS sort_order
  UNION ALL
  SELECT 'qwen-mt-plus' AS code, 'qwen-mt-plus' AS name, 0 AS is_default, 92 AS sort_order
  UNION ALL
  SELECT 'qwen-mt-turbo' AS code, 'qwen-mt-turbo' AS name, 0 AS is_default, 93 AS sort_order
  UNION ALL
  SELECT 'qwen-mt-uni' AS code, 'qwen-mt-uni' AS name, 0 AS is_default, 94 AS sort_order
  UNION ALL
  SELECT 'qwen-omni-turbo' AS code, 'qwen-omni-turbo' AS name, 0 AS is_default, 95 AS sort_order
  UNION ALL
  SELECT 'qwen-omni-turbo-2025-01-19' AS code, 'qwen-omni-turbo-2025-01-19' AS name, 0 AS is_default, 96 AS sort_order
  UNION ALL
  SELECT 'qwen-omni-turbo-2025-03-26' AS code, 'qwen-omni-turbo-2025-03-26' AS name, 0 AS is_default, 97 AS sort_order
  UNION ALL
  SELECT 'qwen-omni-turbo-latest' AS code, 'qwen-omni-turbo-latest' AS name, 0 AS is_default, 98 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-2024-12-20' AS code, 'qwen-plus-2024-12-20' AS name, 0 AS is_default, 99 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-2025-01-12' AS code, 'qwen-plus-2025-01-12' AS name, 0 AS is_default, 100 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-2025-01-25' AS code, 'qwen-plus-2025-01-25' AS name, 0 AS is_default, 101 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-2025-04-28' AS code, 'qwen-plus-2025-04-28' AS name, 0 AS is_default, 102 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-2025-07-14' AS code, 'qwen-plus-2025-07-14' AS name, 0 AS is_default, 103 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-2025-07-28' AS code, 'qwen-plus-2025-07-28' AS name, 0 AS is_default, 104 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-2025-09-11' AS code, 'qwen-plus-2025-09-11' AS name, 0 AS is_default, 105 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-2025-12-01' AS code, 'qwen-plus-2025-12-01' AS name, 0 AS is_default, 106 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-character' AS code, 'qwen-plus-character' AS name, 0 AS is_default, 107 AS sort_order
  UNION ALL
  SELECT 'qwen-plus-latest' AS code, '通义千问 Plus Latest' AS name, 0 AS is_default, 108 AS sort_order
  UNION ALL
  SELECT 'qwen-turbo-latest' AS code, '通义千问 Turbo Latest' AS name, 0 AS is_default, 109 AS sort_order
  UNION ALL
  SELECT 'qwen-vl-max' AS code, 'qwen-vl-max' AS name, 0 AS is_default, 110 AS sort_order
  UNION ALL
  SELECT 'qwen-vl-plus' AS code, 'qwen-vl-plus' AS name, 0 AS is_default, 111 AS sort_order
  UNION ALL
  SELECT 'deepseek-r1' AS code, 'DeepSeek R1' AS name, 0 AS is_default, 112 AS sort_order
  UNION ALL
  SELECT 'deepseek-r1-0528' AS code, 'deepseek-r1-0528' AS name, 0 AS is_default, 113 AS sort_order
  UNION ALL
  SELECT 'deepseek-r1-distill-llama-70b' AS code, 'deepseek-r1-distill-llama-70b' AS name, 0 AS is_default, 114 AS sort_order
  UNION ALL
  SELECT 'deepseek-r1-distill-qwen-14b' AS code, 'deepseek-r1-distill-qwen-14b' AS name, 0 AS is_default, 115 AS sort_order
  UNION ALL
  SELECT 'deepseek-r1-distill-qwen-32b' AS code, 'deepseek-r1-distill-qwen-32b' AS name, 0 AS is_default, 116 AS sort_order
  UNION ALL
  SELECT 'deepseek-r1-distill-qwen-7b' AS code, 'deepseek-r1-distill-qwen-7b' AS name, 0 AS is_default, 117 AS sort_order
  UNION ALL
  SELECT 'deepseek-v3' AS code, 'DeepSeek V3' AS name, 0 AS is_default, 118 AS sort_order
  UNION ALL
  SELECT 'deepseek-v3.1' AS code, 'deepseek-v3.1' AS name, 0 AS is_default, 119 AS sort_order
  UNION ALL
  SELECT 'deepseek-v3.2' AS code, 'deepseek-v3.2' AS name, 0 AS is_default, 120 AS sort_order
  UNION ALL
  SELECT 'deepseek-v3.2-exp' AS code, 'deepseek-v3.2-exp' AS name, 0 AS is_default, 121 AS sort_order
  UNION ALL
  SELECT 'deepseek-v4-flash' AS code, 'deepseek-v4-flash' AS name, 0 AS is_default, 122 AS sort_order
  UNION ALL
  SELECT 'deepseek-v4-flash-0731' AS code, 'deepseek-v4-flash-0731' AS name, 0 AS is_default, 123 AS sort_order
  UNION ALL
  SELECT 'deepseek-v4-pro' AS code, 'deepseek-v4-pro' AS name, 0 AS is_default, 124 AS sort_order
  UNION ALL
  SELECT 'deepseek-v4-pro-0813' AS code, 'deepseek-v4-pro-0813' AS name, 0 AS is_default, 125 AS sort_order
  UNION ALL
  SELECT 'deepseek-v4.1-flash' AS code, 'deepseek-v4.1-flash' AS name, 0 AS is_default, 126 AS sort_order
  UNION ALL
  SELECT 'Moonshot-Kimi-K2-Instruct' AS code, 'Moonshot-Kimi-K2-Instruct' AS name, 0 AS is_default, 127 AS sort_order
  UNION ALL
  SELECT 'kimi-k2-thinking' AS code, 'kimi-k2-thinking' AS name, 0 AS is_default, 128 AS sort_order
  UNION ALL
  SELECT 'kimi-k2.5' AS code, 'kimi-k2.5' AS name, 0 AS is_default, 129 AS sort_order
  UNION ALL
  SELECT 'kimi-k2.6' AS code, 'kimi-k2.6' AS name, 0 AS is_default, 130 AS sort_order
  UNION ALL
  SELECT 'kimi-k2.7-code' AS code, 'kimi-k2.7-code' AS name, 0 AS is_default, 131 AS sort_order
  UNION ALL
  SELECT 'kimi-k3' AS code, 'Kimi K3' AS name, 0 AS is_default, 132 AS sort_order
  UNION ALL
  SELECT 'glm-4.5' AS code, 'glm-4.5' AS name, 0 AS is_default, 133 AS sort_order
  UNION ALL
  SELECT 'glm-4.5-air' AS code, 'glm-4.5-air' AS name, 0 AS is_default, 134 AS sort_order
  UNION ALL
  SELECT 'glm-4.6' AS code, 'glm-4.6' AS name, 0 AS is_default, 135 AS sort_order
  UNION ALL
  SELECT 'glm-4.7' AS code, 'glm-4.7' AS name, 0 AS is_default, 136 AS sort_order
  UNION ALL
  SELECT 'glm-5' AS code, 'glm-5' AS name, 0 AS is_default, 137 AS sort_order
  UNION ALL
  SELECT 'glm-5.1' AS code, 'glm-5.1' AS name, 0 AS is_default, 138 AS sort_order
  UNION ALL
  SELECT 'glm-5.2' AS code, 'GLM-5.2' AS name, 0 AS is_default, 139 AS sort_order
  UNION ALL
  SELECT 'glm-5.3' AS code, 'glm-5.3' AS name, 0 AS is_default, 140 AS sort_order
  UNION ALL
  SELECT 'MiniMax-M2.5' AS code, 'MiniMax-M2.5' AS name, 0 AS is_default, 141 AS sort_order
  UNION ALL
  SELECT 'gui-plus' AS code, 'gui-plus' AS name, 0 AS is_default, 142 AS sort_order
  UNION ALL
  SELECT 'qvq-max' AS code, 'qvq-max' AS name, 0 AS is_default, 143 AS sort_order
  UNION ALL
  SELECT 'qvq-plus' AS code, 'qvq-plus' AS name, 0 AS is_default, 144 AS sort_order
  UNION ALL
  SELECT 'qwen2.5-omni-7b' AS code, 'qwen2.5-omni-7b' AS name, 0 AS is_default, 145 AS sort_order
  UNION ALL
  SELECT 'qwq-plus' AS code, 'qwq-plus' AS name, 0 AS is_default, 146 AS sort_order
  UNION ALL
  SELECT 'tongyi-intent-detect-v3' AS code, 'tongyi-intent-detect-v3' AS name, 0 AS is_default, 147 AS sort_order
  UNION ALL
  SELECT 'tongyi-xiaomi-analysis-flash' AS code, 'tongyi-xiaomi-analysis-flash' AS name, 0 AS is_default, 148 AS sort_order
  UNION ALL
  SELECT 'tongyi-xiaomi-analysis-pro' AS code, 'tongyi-xiaomi-analysis-pro' AS name, 0 AS is_default, 149 AS sort_order
) AS x
WHERE @dashscope_pid > 0
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  enabled = 1,
  is_default = VALUES(is_default),
  sort_order = VALUES(sort_order);

