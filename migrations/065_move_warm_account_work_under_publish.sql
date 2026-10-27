-- 自动养号、任务汇总：从账号池管理(110)移到 AI 矩阵(400)下
UPDATE `navigation_menus`
SET `parent_id`=400, `sort_order`=5, `visible`=1
WHERE `id`=115 OR (`view`='warm' AND `parent_id`=110);

UPDATE `navigation_menus`
SET `parent_id`=400, `sort_order`=6, `visible`=1
WHERE `id`=116 OR (`view`='accountWork' AND `parent_id`=110);
