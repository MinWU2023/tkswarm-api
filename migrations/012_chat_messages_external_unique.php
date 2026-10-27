<?php
declare(strict_types=1);

/**
 * 012_chat_messages_external_unique.php
 *
 * 旧 install.sql 的 chat_messages 可能没有 account_id / external_id。
 * 先按需补列并回填，再创建 (account_id, external_id) 唯一索引。
 *
 * @param PDO $pdo
 * @return string
 */
return static function (PDO $pdo): string {
    $cols = array_column($pdo->query('SHOW COLUMNS FROM `chat_messages`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $added = [];

    if (!in_array('account_id', $cols, true)) {
        $pdo->exec('ALTER TABLE `chat_messages` ADD COLUMN `account_id` INT NOT NULL DEFAULT 0');
        $added[] = 'account_id';
        $cols[] = 'account_id';
    }
    if (!in_array('external_id', $cols, true)) {
        $pdo->exec('ALTER TABLE `chat_messages` ADD COLUMN `external_id` VARCHAR(191) NULL DEFAULT NULL');
        $added[] = 'external_id';
        $cols[] = 'external_id';
    }

    // 从好友表回填 account_id
    if (in_array('friend_id', $cols, true)) {
        $pdo->exec(
            'UPDATE `chat_messages` AS m
             INNER JOIN `chat_friends` AS f ON f.id = m.friend_id
             SET m.account_id = f.account_id
             WHERE m.account_id = 0 OR m.account_id IS NULL'
        );
    }

    $indexes = $pdo->query('SHOW INDEX FROM `chat_messages`')->fetchAll(PDO::FETCH_ASSOC);
    $hasUk = false;
    foreach ($indexes as $idx) {
        if (($idx['Key_name'] ?? '') === 'uk_chat_msg_ext') {
            $hasUk = true;
            break;
        }
    }
    if (!$hasUk) {
        // 再次确认列存在后再建索引（避免 1072）
        $cols = array_column($pdo->query('SHOW COLUMNS FROM `chat_messages`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('account_id', $cols, true) || !in_array('external_id', $cols, true)) {
            throw new RuntimeException('chat_messages 缺少 account_id 或 external_id，无法创建 uk_chat_msg_ext');
        }
        $pdo->exec('ALTER TABLE `chat_messages` ADD UNIQUE KEY `uk_chat_msg_ext` (`account_id`, `external_id`)');
        $added[] = 'uk_chat_msg_ext';
    }

    return $added
        ? ('已补齐：' . implode(', ', $added))
        : 'account_id / external_id / uk_chat_msg_ext 均已就绪';
};
