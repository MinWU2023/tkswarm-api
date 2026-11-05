-- 开发管理 · NoCaptchaAI 密钥（列表管理，同一时间仅一条启用）

CREATE TABLE IF NOT EXISTS `nocaptcha_keys` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL DEFAULT '',
  `username` VARCHAR(100) NOT NULL DEFAULT '',
  `password` VARCHAR(255) NOT NULL DEFAULT '',
  `api_key` VARCHAR(255) NOT NULL DEFAULT '',
  `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=启用 0=不启用（全表仅允许一条为1）',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_nocaptcha_keys_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 默认数据（幂等：按邮箱去重）
INSERT INTO `nocaptcha_keys` (`email`, `username`, `password`, `api_key`, `status`)
SELECT 'piwwuvnspt@imxwe.com', 'piwwuvnsp', 'piwwuvnspt@imxwe.com', 'nocap_XrWSZYi3dyxKzr2zvtUxLzbM', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `nocaptcha_keys` WHERE `email` = 'piwwuvnspt@imxwe.com');

INSERT IGNORE INTO admin_permissions (id, module, code, name, sort_order)
VALUES (174, 'system', 'system.nocaptcha', '管理NoCaptchaAI密钥(仅超管)', 174);

INSERT IGNORE INTO navigation_menus (id, parent_id, title, icon, path, view, subview, sort_order, visible)
VALUES (760, 820, 'NoCaptchaAI密钥', 'key', '', 'nocaptchaKeys', '', 6, 1);

UPDATE navigation_menus
SET parent_id=820, title='NoCaptchaAI密钥', icon='key', path='', view='nocaptchaKeys', subview='', sort_order=6, visible=1
WHERE id=760;
