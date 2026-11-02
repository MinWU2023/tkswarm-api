-- 现有「拉取数据 / 修改资料」分任务默认开启省流（payload.saveTraffic = true）

UPDATE `account_work_items`
SET `payload_json` = JSON_SET(
  CASE
    WHEN `payload_json` IS NULL OR TRIM(`payload_json`) = '' THEN '{}'
    WHEN JSON_VALID(`payload_json`) THEN `payload_json`
    ELSE '{}'
  END,
  '$.saveTraffic',
  TRUE
)
WHERE `task_type` IN ('pull_stats', 'modify_profile', 'profile')
  AND (
    `payload_json` IS NULL
    OR TRIM(`payload_json`) = ''
    OR NOT JSON_VALID(`payload_json`)
    OR (
      -- 未设置 saveTraffic / save_traffic
      JSON_EXTRACT(`payload_json`, '$.saveTraffic') IS NULL
      AND JSON_EXTRACT(`payload_json`, '$.save_traffic') IS NULL
    )
    OR JSON_EXTRACT(`payload_json`, '$.saveTraffic') IN (CAST('false' AS JSON), CAST('0' AS JSON))
    OR LOWER(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`payload_json`, '$.saveTraffic')), '')) IN ('false', '0', 'off', 'no', '关闭')
    OR JSON_EXTRACT(`payload_json`, '$.save_traffic') IN (CAST('false' AS JSON), CAST('0' AS JSON))
    OR LOWER(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`payload_json`, '$.save_traffic')), '')) IN ('false', '0', 'off', 'no', '关闭')
  );
