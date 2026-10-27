-- 043_rename_ai_agent_menu.sql
-- 一级菜单「AI 机器人 / AI机器人」更名为「AI智能体」

UPDATE `navigation_menus`
SET title='AI智能体'
WHERE id=300
   OR title IN ('AI 机器人', 'AI机器人', 'AI 智能体')
   OR (parent_id IS NULL AND view='agent');
