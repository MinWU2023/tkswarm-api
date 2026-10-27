-- 回填 AI 拓客 / 总分任务归属（从账号 owner_id 推导）
UPDATE ai_leads l
INNER JOIN accounts a ON a.id = l.scrape_account_id
SET l.owner_id = a.owner_id
WHERE l.owner_id = 0 AND a.owner_id > 0;

UPDATE account_work_batches b
INNER JOIN accounts a ON a.id = b.account_id
SET b.owner_id = a.owner_id
WHERE b.owner_id = 0 AND a.owner_id > 0;

UPDATE account_work_items i
INNER JOIN account_work_batches b ON b.id = i.batch_id
SET i.owner_id = b.owner_id
WHERE i.owner_id = 0 AND b.owner_id > 0;

UPDATE account_work_items i
INNER JOIN accounts a ON a.id = i.account_id
SET i.owner_id = a.owner_id
WHERE i.owner_id = 0 AND a.owner_id > 0;
