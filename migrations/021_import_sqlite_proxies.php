<?php
declare(strict_types=1);

/**
 * 021_import_sqlite_proxies.php
 *
 * 将本地 SQLite（tkswarm-node/data/tkswarm.db）导出的代理数据导入 MySQL。
 * 数据文件：同目录 _proxies_dump.json / _proxy_groups_dump.json
 *
 * 密码：用 Node 的 data/.secret-key 解密后，再按 PHP SecretStore 重新加密写入。
 * 幂等：按 protocol+host+port+username 去重；已存在则跳过。
 *
 * @param PDO $pdo
 * @return string 结果说明
 */
return static function (PDO $pdo): string {
    $dir = __DIR__;
    $proxiesFile = $dir . '/_proxies_dump.json';
    $groupsFile = $dir . '/_proxy_groups_dump.json';

    if (!is_file($proxiesFile)) {
        throw new RuntimeException('缺少代理数据文件：_proxies_dump.json');
    }

    $proxies = json_decode((string)file_get_contents($proxiesFile), true);
    $groups = is_file($groupsFile)
        ? json_decode((string)file_get_contents($groupsFile), true)
        : [];
    if (!is_array($proxies)) {
        throw new RuntimeException('_proxies_dump.json 解析失败');
    }
    if (!is_array($groups)) {
        $groups = [];
    }

    // 确保列齐全
    $ensureCols = [
        'use_count' => 'ALTER TABLE `proxies` ADD COLUMN `use_count` INT NOT NULL DEFAULT 0',
        'password_encrypted' => 'ALTER TABLE `proxies` ADD COLUMN `password_encrypted` TEXT NULL',
        'egress_ip' => "ALTER TABLE `proxies` ADD COLUMN `egress_ip` VARCHAR(64) NOT NULL DEFAULT ''",
        'latency_ms' => 'ALTER TABLE `proxies` ADD COLUMN `latency_ms` INT NULL',
        'group_id' => 'ALTER TABLE `proxies` ADD COLUMN `group_id` INT NULL',
        'rotate_minutes' => 'ALTER TABLE `proxies` ADD COLUMN `rotate_minutes` INT NOT NULL DEFAULT 0',
        'region' => "ALTER TABLE `proxies` ADD COLUMN `region` VARCHAR(64) NOT NULL DEFAULT ''",
        'city' => "ALTER TABLE `proxies` ADD COLUMN `city` VARCHAR(64) NOT NULL DEFAULT ''",
        'owner_id' => 'ALTER TABLE `proxies` ADD COLUMN `owner_id` INT NULL DEFAULT NULL',
        'last_checked_at' => 'ALTER TABLE `proxies` ADD COLUMN `last_checked_at` DATETIME NULL',
    ];
    $existing = array_column($pdo->query('SHOW COLUMNS FROM `proxies`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach ($ensureCols as $col => $sql) {
        if (!in_array($col, $existing, true)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // 幂等忽略
            }
        }
    }
    try {
        $pdo->exec('ALTER TABLE `groups` ADD COLUMN `owner_id` INT NULL DEFAULT NULL');
    } catch (Throwable $e) {
    }

    $nodeKeyPath = dirname(__DIR__, 2) . '/tkswarm-node/data/.secret-key';
    if (!is_file($nodeKeyPath)) {
        // 兼容仓库平级/同机路径
        $alt = dirname(__DIR__) . '/../tkswarm-node/data/.secret-key';
        if (is_file($alt)) {
            $nodeKeyPath = $alt;
        }
    }
    if (!is_file($nodeKeyPath)) {
        throw new RuntimeException('找不到 Node 密钥文件 tkswarm-node/data/.secret-key，无法解密代理密码');
    }
    $nodeKey = file_get_contents($nodeKeyPath);
    if ($nodeKey === false || strlen($nodeKey) !== 32) {
        throw new RuntimeException('Node 密钥文件无效（需 32 字节）');
    }

    // 同步密钥到 PHP SecretStore 默认位置，保证后续解密一致
    $syncTargets = [
        dirname(__DIR__) . '/data/.secret-key',
        dirname(__DIR__, 2) . '/data/.secret-key',
    ];
    foreach ($syncTargets as $target) {
        $td = dirname($target);
        if (!is_dir($td)) {
            @mkdir($td, 0700, true);
        }
        if (!is_file($target)) {
            @copy($nodeKeyPath, $target);
        }
    }
    if (!getenv('SECRET_KEY_PATH')) {
        putenv('SECRET_KEY_PATH=' . $nodeKeyPath);
        $_ENV['SECRET_KEY_PATH'] = $nodeKeyPath;
    }

    $b64decode = static function (string $text): string {
        $pad = strlen($text) % 4;
        if ($pad) {
            $text .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($text, '-_', '+/'), true);
        return $raw === false ? '' : $raw;
    };
    $b64 = static function (string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    };
    $decryptNode = static function (string $value) use ($nodeKey, $b64decode): string {
        if ($value === '') {
            return '';
        }
        $parts = explode('.', $value);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            return '';
        }
        $iv = $b64decode($parts[1]);
        $tag = $b64decode($parts[2]);
        $cipher = $b64decode($parts[3]);
        if ($iv === '' || $tag === '' || $cipher === '') {
            return '';
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $nodeKey, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    };
    $encryptPhp = static function (string $value) use ($b64): string {
        if ($value === '') {
            return '';
        }
        if (class_exists(\App\Services\SecretStore::class)) {
            return \App\Services\SecretStore::encrypt($value);
        }
        // 无自动加载时：写入 API 侧密钥（与 SecretStore 默认路径兼容）
        $apiKeyPath = getenv('SECRET_KEY_PATH') ?: (dirname(__DIR__) . '/data/.secret-key');
        $dir = dirname($apiKeyPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (!is_file($apiKeyPath)) {
            // 优先复用 Node 密钥，避免两套密码体系
            $node = dirname(__DIR__, 2) . '/tkswarm-node/data/.secret-key';
            if (is_file($node) && strlen((string)file_get_contents($node)) === 32) {
                copy($node, $apiKeyPath);
            } else {
                file_put_contents($apiKeyPath, random_bytes(32));
            }
        }
        $key = file_get_contents($apiKeyPath);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('PHP 侧密钥无效');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '');
        if ($cipher === false) {
            throw new RuntimeException('加密失败');
        }
        return 'v1.' . $b64($iv) . '.' . $b64($tag) . '.' . $b64($cipher);
    };

    $ownerId = 1;
    $groupInserted = 0;
    $groupSkipped = 0;

    $insGroup = $pdo->prepare(
        'INSERT INTO `groups` (`id`, `name`, `type`, `description`, `owner_id`, `created_at`, `updated_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           `name`=VALUES(`name`),
           `description`=VALUES(`description`),
           `owner_id`=COALESCE(`owner_id`, VALUES(`owner_id`))'
    );

    foreach ($groups as $g) {
        if (!is_array($g)) {
            continue;
        }
        $type = (string)($g['type'] ?? 'proxy');
        if ($type !== 'proxy' && $type !== 'proxies') {
            continue;
        }
        $id = (int)($g['id'] ?? 0);
        $name = trim((string)($g['name'] ?? ''));
        if ($id < 1 || $name === '') {
            continue;
        }
        // 名称+类型唯一：若 id 冲突但名字不同，改用按名查找
        $existsById = $pdo->prepare('SELECT id, name FROM `groups` WHERE id=?');
        $existsById->execute([$id]);
        $row = $existsById->fetch(PDO::FETCH_ASSOC);
        if ($row && (string)$row['name'] !== $name) {
            // 保留已有 id=2「默认代理组」，美国组若 id 被占用则按名 upsert
            $byName = $pdo->prepare('SELECT id FROM `groups` WHERE name=? AND type=? LIMIT 1');
            $byName->execute([$name, 'proxy']);
            $found = $byName->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $groupSkipped++;
                continue;
            }
            $pdo->prepare(
                'INSERT INTO `groups` (`name`, `type`, `description`, `owner_id`, `created_at`, `updated_at`)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $name,
                'proxy',
                (string)($g['description'] ?? ''),
                $ownerId,
                $g['created_at'] ?? date('Y-m-d H:i:s'),
                $g['updated_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $groupInserted++;
            continue;
        }
        $insGroup->execute([
            $id,
            $name,
            'proxy',
            (string)($g['description'] ?? ''),
            $ownerId,
            $g['created_at'] ?? date('Y-m-d H:i:s'),
            $g['updated_at'] ?? date('Y-m-d H:i:s'),
        ]);
        $groupInserted += $row ? 0 : 1;
        if ($row) {
            $groupSkipped++;
        }
    }

    // 建立 sqlite group_id → mysql group_id 映射（按名称）
    $groupMap = [];
    foreach ($groups as $g) {
        if (!is_array($g)) {
            continue;
        }
        $sid = (int)($g['id'] ?? 0);
        $name = trim((string)($g['name'] ?? ''));
        if ($sid < 1 || $name === '') {
            continue;
        }
        $st = $pdo->prepare('SELECT id FROM `groups` WHERE name=? AND type=? LIMIT 1');
        $st->execute([$name, 'proxy']);
        $hit = $st->fetch(PDO::FETCH_ASSOC);
        if ($hit) {
            $groupMap[$sid] = (int)$hit['id'];
        }
    }

    $findProxy = $pdo->prepare(
        'SELECT id FROM `proxies` WHERE protocol=? AND host=? AND port=? AND username=? LIMIT 1'
    );
    $insProxy = $pdo->prepare(
        'INSERT INTO `proxies` (
            `name`,`protocol`,`host`,`port`,`username`,`password`,`password_encrypted`,
            `country`,`group_id`,`status`,`latency_ms`,`last_checked_at`,
            `egress_ip`,`rotate_minutes`,`region`,`city`,`use_count`,`owner_id`,
            `created_at`,`updated_at`
         ) VALUES (?,?,?,?,?,\'\',?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $inserted = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];

    foreach ($proxies as $p) {
        if (!is_array($p)) {
            continue;
        }
        $protocol = strtolower(trim((string)($p['protocol'] ?? 'http')));
        if (!in_array($protocol, ['http', 'https', 'socks5'], true)) {
            $protocol = 'http';
        }
        $host = trim((string)($p['host'] ?? ''));
        $port = (int)($p['port'] ?? 0);
        $username = (string)($p['username'] ?? '');
        if ($host === '' || $port < 1 || $port > 65535) {
            $failed++;
            continue;
        }

        $findProxy->execute([$protocol, $host, $port, $username]);
        if ($findProxy->fetch(PDO::FETCH_ASSOC)) {
            $skipped++;
            continue;
        }

        $plain = '';
        $enc = (string)($p['password_encrypted'] ?? '');
        $legacy = (string)($p['password'] ?? '');
        if ($enc !== '') {
            $plain = $decryptNode($enc);
        }
        if ($plain === '' && $legacy !== '') {
            $plain = $legacy;
        }
        try {
            $reEnc = $encryptPhp($plain);
        } catch (Throwable $e) {
            $failed++;
            if (count($errors) < 5) {
                $errors[] = "{$host}:{$port} 加密失败：" . $e->getMessage();
            }
            continue;
        }

        $sgid = (int)($p['group_id'] ?? 0);
        $mgid = $sgid > 0 ? ($groupMap[$sgid] ?? null) : null;
        $status = (string)($p['status'] ?? 'unchecked');
        if (!in_array($status, ['unchecked', 'available', 'unavailable'], true)) {
            $status = 'unchecked';
        }
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            $name = $host . ':' . $port;
        }

        try {
            $insProxy->execute([
                mb_substr($name, 0, 100),
                $protocol,
                mb_substr($host, 0, 100),
                $port,
                mb_substr($username, 0, 100),
                $reEnc,
                mb_substr((string)($p['country'] ?? ''), 0, 50),
                $mgid,
                $status,
                $p['latency_ms'] !== null && $p['latency_ms'] !== '' ? (int)$p['latency_ms'] : null,
                $p['last_checked_at'] ?: null,
                mb_substr((string)($p['egress_ip'] ?? ''), 0, 64),
                (int)($p['rotate_minutes'] ?? 0),
                mb_substr((string)($p['region'] ?? ''), 0, 64),
                mb_substr((string)($p['city'] ?? ''), 0, 64),
                (int)($p['use_count'] ?? 0),
                $ownerId,
                $p['created_at'] ?? date('Y-m-d H:i:s'),
                $p['updated_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $inserted++;
        } catch (Throwable $e) {
            $failed++;
            if (count($errors) < 5) {
                $errors[] = "{$host}:{$port} " . $e->getMessage();
            }
        }
    }

    $msg = "代理导入完成：新增 {$inserted}，跳过已存在 {$skipped}，失败 {$failed}；分组写入 {$groupInserted}，分组跳过 {$groupSkipped}";
    if ($errors) {
        $msg .= '；样例错误：' . implode(' | ', $errors);
    }
    return $msg;
};
