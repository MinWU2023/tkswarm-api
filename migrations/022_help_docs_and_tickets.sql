-- 022: 帮助文档（分类+文档）与工单反馈
CREATE TABLE IF NOT EXISTS `help_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `visible` TINYINT(1) NOT NULL DEFAULT 1,
  `owner_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_help_cat_sort` (`visible`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `help_documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL DEFAULT 0,
  `title` VARCHAR(191) NOT NULL,
  `summary` VARCHAR(500) NOT NULL DEFAULT '',
  `content` MEDIUMTEXT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
  `sort_order` INT NOT NULL DEFAULT 0,
  `view_count` INT NOT NULL DEFAULT 0,
  `owner_id` INT NULL,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_help_doc_cat` (`category_id`, `status`, `sort_order`),
  KEY `idx_help_doc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_no` VARCHAR(32) NOT NULL DEFAULT '',
  `contact_name` VARCHAR(100) NOT NULL DEFAULT '',
  `contact_phone` VARCHAR(64) NOT NULL DEFAULT '',
  `contact_email` VARCHAR(191) NOT NULL DEFAULT '',
  `subject` VARCHAR(191) NOT NULL,
  `content` TEXT NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'open',
  `priority` VARCHAR(32) NOT NULL DEFAULT 'normal',
  `reply` TEXT NULL,
  `replied_at` DATETIME NULL,
  `client_platform` VARCHAR(32) NOT NULL DEFAULT '',
  `client_version` VARCHAR(64) NOT NULL DEFAULT '',
  `owner_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_ticket_status` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `navigation_menus`
  (`id`, `parent_id`, `title`, `icon`, `path`, `view`, `subview`, `sort_order`, `visible`)
VALUES
  (680, 600, '帮助文档', 'book', '', 'helpDocs', '', 8, 1),
  (690, 600, '工单处理', 'ticket', '', 'tickets', '', 9, 1);

INSERT IGNORE INTO `admin_permissions` (`id`, `module`, `code`, `name`, `sort_order`)
VALUES
  (71, 'system', 'system.help', '管理帮助文档', 71),
  (72, 'system', 'system.tickets', '处理工单反馈', 72);

INSERT IGNORE INTO `help_categories` (`id`, `name`, `slug`, `sort_order`, `visible`)
VALUES
  (1, '快速入门', 'getting-started', 1, 1),
  (2, '环境安装', 'environment', 2, 1),
  (3, '常见问题', 'faq', 3, 1);

INSERT IGNORE INTO `help_documents` (`id`, `category_id`, `title`, `summary`, `content`, `status`, `sort_order`, `published_at`)
VALUES
  (1, 1, '如何安装客户端', '下载并安装 Dyy TKSwarm Client', '## 安装步骤\n\n1. 从官网或管理员处获取安装包\n2. Windows 运行安装程序或绿色版 EXE\n3. macOS 打开 DMG 后拖入应用程序\n4. 启动后先安装 Node.js 与比特浏览器', 'published', 1, NOW()),
  (2, 2, 'Node.js 与比特浏览器', '一键检测与安装本机环境', '## 环境准备\n\n在首页选择推荐版本后点击「安装」。\n安装完成后请启动比特浏览器并开启本地 API（默认 54345）。', 'published', 1, NOW());
