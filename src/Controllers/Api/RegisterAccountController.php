<?php

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountWorkBatchService;
use App\Services\DataScope;
use App\Services\SecretStore;
use App\Services\UserSettingsService;

/**
 * 账号向导·注册账号：
 *  - proxy-match：按账号类型 + 国家异步查询是否有合适代理（主体号=0 次，运营号<3 次）
 *  - mailbox / mailbox/messages：服务端代调邮箱接口（密钥只在后端，来自管理后台「官网设置」）
 *  - enqueue：建 registering 占位账号 + 绑定代理 + 挂 register_account 分任务到总任务
 */
class RegisterAccountController extends Controller
{
    private const OPERATION_USE_LIMIT = 3;

    /** 最近一次邮箱上游的非 2xx 响应（状态码 + 解析后的 body），供上层决定返回码 */
    private array $lastUpstream = [];

    public function proxyMatch(Request $request): Response
    {
        $type = AccountController::normalizeAccountType($request->get('accountType', $request->get('account_type', 'main')));
        $countryRaw = trim((string)$request->get('country', ''));
        if ($countryRaw === '') {
            return $this->error('请先选择国家', 422);
        }
        $rows = $this->matchProxies($type, $countryRaw, 200);
        $count = count($rows);
        $rule = $type === 'operation' ? '使用次数小于 3 次' : '从未使用过（0 次）';
        return $this->success([
            'accountType' => $type,
            'available' => $count > 0,
            'count' => $count,
            'sample' => array_slice(array_map(static fn ($r) => [
                'id' => (int)$r['id'],
                'name' => (string)($r['name'] ?? ''),
                'country' => (string)($r['country'] ?? ''),
                'used' => (int)($r['used'] ?? 0),
            ], $rows), 0, 5),
            'message' => $count > 0
                ? "找到 {$count} 个符合条件的代理（{$rule}）"
                : "不可创建：缺少合适的代理数据（需要{$rule}的该国家代理）",
        ]);
    }

    /** 生成邮箱 */
    public function mailbox(Request $request): Response
    {
        $cfg = UserSettingsService::getMailboxConfig(false);
        $ref = trim((string)$request->input('providerRef', $request->input('provider_ref', '')));
        $data = $this->mailboxCall('/icloud-mailboxes', [
            'provider_ref' => $ref !== '' ? $ref : $cfg['providerRef'],
            'username' => trim((string)$request->input('username', '')),
        ], $err);
        if ($data === null) {
            if ((int)($this->lastUpstream['status'] ?? 0) === 429) {
                return $this->error($err ?: '邮箱服务繁忙，请稍后重试', 429, [
                    'retryAfterSeconds' => (int)($this->lastUpstream['data']['retry_after_seconds'] ?? 0),
                ]);
            }
            return $this->error($err ?: '获取邮箱失败', 502);
        }
        $address = $this->extractAddress($data);
        if ($address === '') {
            return $this->error('邮箱接口未返回有效邮箱地址', 502, $data);
        }
        return $this->success(['address' => $address, 'raw' => $data], '邮箱已获取');
    }

    /** 读取邮件（验证码等） */
    public function mailboxMessages(Request $request): Response
    {
        $address = trim((string)$request->input('address', ''));
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return $this->error('邮箱地址无效', 422);
        }
        $limit = max(1, min(50, (int)$request->input('limit', 20)));
        $data = $this->mailboxCall('/messages', [
            'address' => $address,
            'icloud' => trim((string)$request->input('icloud', '')),
            'limit' => $limit,
            'refresh' => $request->boolInput('refresh', true),
        ], $err);
        if ($data === null) {
            return $this->error($err ?: '读取邮件失败', 502);
        }
        return $this->success($data);
    }

    public function enqueue(Request $request): Response
    {
        if (!DataScope::canEditAccounts()) {
            return $this->error('当前角色无权创建账号', 403);
        }
        DataScope::ensureColumns();
        AccountWorkBatchService::ensureSchema();
        $this->ensureAccountColumns();

        $type = AccountController::normalizeAccountType($request->input('accountType', $request->input('account_type', 'main')));
        $country = trim((string)$request->input('country', ''));
        $countryMatch = trim((string)$request->input('countryMatch', $country));
        if ($country === '') {
            return $this->error('请选择国家', 422);
        }
        $email = trim((string)$request->input('email', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('请先获取有效的邮箱', 422);
        }
        $username = trim((string)$request->input('username', ''));
        if (!preg_match('/^[A-Za-z0-9_.]{8,24}$/', $username)) {
            return $this->error('账号名需 8~24 位，仅限字母、数字、下划线、点', 422);
        }
        $nickname = trim((string)$request->input('nickname', ''));
        if ($nickname === '') {
            return $this->error('昵称不能为空', 422);
        }
        $password = (string)$request->input('password', '');
        $pwLen = strlen($password);
        if ($pwLen < 16 || $pwLen > 20
            || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            return $this->error('密码需 16~20 位，且同时包含字母、数字和特殊字符', 422);
        }
        $birthday = trim((string)$request->input('birthday', ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthday, $m)) {
            return $this->error('生日格式应为 YYYY-MM-DD', 422);
        }
        [$by, $bm, $bd] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($by <= 1980 || $by >= 2000 || !checkdate($bm, $bd, $by)) {
            return $this->error('生日年份必须大于 1980 且小于 2000', 422);
        }
        if (DB::selectOne('SELECT id FROM accounts WHERE username=?', [$username])) {
            return $this->error('该账号名已存在', 409);
        }

        // 代理：显式指定则校验规则，否则自动取使用次数最少的合格代理
        $proxyId = (int)$request->input('proxyId', $request->input('proxy_id', 0));
        if ($proxyId > 0) {
            $proxy = DB::selectOne('SELECT * FROM proxies WHERE id=?', [$proxyId]);
            if ($e = DataScope::assertProxyVisible($proxy)) {
                return $e;
            }
            $used = RegisterEnvController::proxyUsage($proxyId, 0);
            if (($type === 'main' && $used > 0) || ($type === 'operation' && $used >= self::OPERATION_USE_LIMIT)) {
                return $this->error('所选代理不符合当前账号类型的使用次数规则', 422);
            }
        } else {
            $rows = $this->matchProxies($type, $countryMatch, 1);
            if (!$rows) {
                return $this->error('不可创建：缺少合适的代理数据', 422);
            }
            $proxyId = (int)$rows[0]['id'];
        }

        $row = [];
        DataScope::applyAccountInsertBindings($row);
        $groupId = $request->input('groupId', $request->input('group_id'));
        $groupId = ($groupId === null || $groupId === '') ? null : (int)$groupId;
        $accountId = 0;
        try {
            DB::query(
                'INSERT INTO accounts(username,nickname,country,group_id,proxy_id,browser_type,browser_profile_id,login_status,chat_status,dm_channel,enabled,notes,has_session,owner_id,company_id,industry_id,account_type)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $username, $nickname, $country, $groupId, $proxyId, 'tkswarm', '', 'registering', 'offline', 'auto', 1,
                    '注册中 · 邮箱 ' . $email, 0, $row['owner_id'] ?? null, $row['company_id'] ?? null, $row['industry_id'] ?? null, $type,
                ]
            );
            $accountId = (int)DB::getPdo()->lastInsertId();
            DB::query(
                'INSERT INTO account_secrets(account_id, password_encrypted, totp_secret_encrypted) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE password_encrypted=VALUES(password_encrypted), updated_at=CURRENT_TIMESTAMP',
                [$accountId, SecretStore::encrypt($password), '']
            );
            try {
                \App\Services\AccountBrowserEnvService::applyAccountBackend($accountId, 'tkswarm', '');
            } catch (\Throwable $e) {
                // 后端绑定失败不阻断，执行时再建环境
            }
            $out = AccountWorkBatchService::enqueueItem($accountId, 'register_account', [
                'source' => 'account_wizard_register',
                'accountType' => $type,
                'email' => $email,
                'username' => $username,
                'nickname' => $nickname,
                'birthday' => $birthday,
                'country' => $country,
                'proxyId' => $proxyId,
                'browserBackend' => 'tkswarm',
            ], 0, $this->writeOwnerId());
        } catch (\Throwable $e) {
            if ($accountId > 0) {
                DB::query('DELETE FROM account_secrets WHERE account_id=?', [$accountId]);
                DB::query('DELETE FROM accounts WHERE id=?', [$accountId]);
            }
            return $this->error('创建注册任务失败：' . $e->getMessage(), 400);
        }
        return $this->success([
            'accountId' => $accountId,
            'proxyId' => $proxyId,
            'batchId' => (int)($out['batch_id'] ?? 0),
            'itemId' => (int)($out['item_id'] ?? 0),
        ], '注册任务已挂入总任务');
    }

    /** @return list<array<string,mixed>> */
    private function matchProxies(string $type, string $countryRaw, int $limit): array
    {
        $names = [];
        foreach (explode('|', $countryRaw) as $n) {
            $n = trim($n);
            if ($n !== '') {
                $names[mb_strtolower($n)] = true;
            }
        }
        $names = array_slice(array_keys($names), 0, 40);
        if (!$names) {
            return [];
        }
        DataScope::ensureColumns();
        $filters = ["p.status<>'unavailable'", 'LOWER(p.country) IN (' . implode(',', array_fill(0, count($names), '?')) . ')'];
        $params = $names;
        DataScope::applyProxyListScope('p.owner_id', $filters, $params);
        $envSql = $this->hasRegisterEnvs()
            ? "(SELECT COUNT(*) FROM register_envs e WHERE e.proxy_id=p.id AND e.status='draft')"
            : '0';
        $limit = max(1, min(200, $limit));
        $cond = $type === 'operation' ? 't.used < ' . self::OPERATION_USE_LIMIT : 't.used = 0';
        $sql = 'SELECT t.* FROM (SELECT p.id, p.name, p.host, p.port, p.country, p.status,'
            . ' (SELECT COUNT(*) FROM accounts a WHERE a.proxy_id=p.id) + ' . $envSql . ' AS used'
            . ' FROM proxies p WHERE ' . implode(' AND ', $filters) . ") t WHERE {$cond} ORDER BY t.used ASC, t.id ASC LIMIT {$limit}";
        return DB::select($sql, $params) ?: [];
    }

    private function hasRegisterEnvs(): bool
    {
        try {
            return (bool)DB::select("SHOW TABLES LIKE 'register_envs'");
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function ensureAccountColumns(): void
    {
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM accounts') ?: [], 'Field');
            if (!in_array('account_type', $cols, true)) {
                DB::query("ALTER TABLE accounts ADD COLUMN account_type VARCHAR(16) NOT NULL DEFAULT 'main'");
            }
        } catch (\Throwable $e) {
        }
    }

    /** @return array<string,mixed>|null */
    private function mailboxCall(string $path, array $body, ?string &$error = null): ?array
    {
        $error = null;
        $cfg = UserSettingsService::getMailboxConfig(false);
        if (!$cfg['configured']) {
            $error = '未配置邮箱 API 密钥，请到管理后台「官网设置」中填写';
            return null;
        }
        if (!function_exists('curl_init')) {
            $error = '服务器未启用 curl 扩展';
            return null;
        }
        $ch = curl_init($cfg['apiUrl'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['apiKey'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            $error = '邮箱服务无响应' . ($curlErr !== '' ? '：' . $curlErr : '');
            return null;
        }
        $data = json_decode((string)$raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = '';
            if (is_array($data)) {
                $m = $data['message'] ?? $data['error'] ?? $data['detail'] ?? '';
                // detail / error 可能是数组（校验错误列表），不能直接转字符串
                $msg = is_scalar($m) ? (string)$m : (string)json_encode($m, JSON_UNESCAPED_UNICODE);
            } elseif (is_string($raw)) {
                $msg = trim(strip_tags($raw));
            }
            $this->lastUpstream = ['status' => $code, 'data' => is_array($data) ? $data : []];
            $retry = is_array($data) ? (int)($data['retry_after_seconds'] ?? 0) : 0;
            if ($code === 429 && is_array($data) && ($data['code'] ?? '') === 'icloud_hme_provider_unavailable') {
                $ref = (string)($data['provider_ref'] ?? '');
                $error = '邮箱通道' . ($ref !== '' ? "（{$ref}）" : '') . '正在冷却中，'
                    . ($retry > 0 ? "约 {$retry} 秒后可重试" : '请稍后重试')
                    . '；也可在管理后台「官网设置」换一个 provider_ref';
            } elseif ($code === 429) {
                $error = '邮箱服务请求过于频繁' . ($retry > 0 ? "，约 {$retry} 秒后可重试" : '，请稍后重试');
            } else {
                $error = "邮箱服务返回 {$code}" . ($msg !== '' ? '：' . mb_substr($msg, 0, 300) : '');
            }
            return null;
        }
        if (!is_array($data)) {
            $error = '邮箱服务返回了非 JSON 内容';
            return null;
        }
        return $data;
    }

    /** 在响应里（含嵌套）找第一个有效邮箱地址 */
    private function extractAddress(array $data): string
    {
        $queue = [$data];
        $guard = 0;
        while ($queue && $guard++ < 200) {
            $cur = array_shift($queue);
            foreach (['address', 'email', 'mailbox', 'hme', 'alias'] as $k) {
                if (isset($cur[$k]) && is_string($cur[$k]) && filter_var($cur[$k], FILTER_VALIDATE_EMAIL)) {
                    return $cur[$k];
                }
            }
            foreach ($cur as $v) {
                if (is_array($v)) {
                    $queue[] = $v;
                }
            }
        }
        return '';
    }
}
