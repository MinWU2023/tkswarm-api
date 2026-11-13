-- 将全部账号统一改为运营号（account_type=operation）。
-- main=主体号，operation=运营号。
UPDATE `accounts`
SET `account_type` = 'operation'
WHERE `account_type` IS NULL
   OR `account_type` = ''
   OR `account_type` <> 'operation';
