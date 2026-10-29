<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database\DB;
use App\Services\AccountImport;
use App\Services\AccountBanService;
use App\Services\AccountWorkBatchService;
use App\Services\CompanyService;
use App\Services\DataScope;
use App\Services\IndustryDataService;
use App\Services\PlanTask\PullDataAnalysisService;
use App\Services\SecretStore;
use PDOException;

class AccountController extends Controller
{
    /** 单个代理最多绑定账号数 */
    private const PROXY_BIND_LIMIT = 3;

    private const SELECT = "
      SELECT a.*,
             CASE
               WHEN pg.name IS NOT NULL AND pg.name<>'' THEN CONCAT(pg.name, ' / ', g.name)
               ELSE g.name
             END AS group_name,
             p.name AS proxy_name,
             CASE WHEN p.host IS NULL THEN '' ELSE CONCAT(p.protocol, '://', p.host, ':', p.port) END AS proxy_address,
             COALESCE(NULLIF(TRIM(p.country), ''), '') AS proxy_country,
             COALESCE(NULLIF(TRIM(p.region), ''), '') AS proxy_region,
             COALESCE(NULLIF(TRIM(p.city), ''), '') AS proxy_city,
             CASE WHEN s.account_id IS NULL THEN 0 ELSE 1 END AS session_saved,
             CASE WHEN sec.totp_secret_encrypted IS NOT NULL AND sec.totp_secret_encrypted<>'' THEN 1 ELSE 0 END AS has_totp
      FROM accounts a
      LEFT JOIN `groups` g ON g.id = a.group_id
      LEFT JOIN `groups` pg ON pg.id = g.parent_id
      LEFT JOIN proxies p ON p.id = a.proxy_id
      LEFT JOIN account_sessions s ON s.account_id = a.id
      LEFT JOIN account_secrets sec ON sec.account_id = a.id";

    public function list(Request $request): Response
    {
        $this->ensureSchema();
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(200, max(1, (int)$request->get('pageSize', 20)));
        // 兜底：到了解封时间的账号先恢复，避免必须等到定时任务才生效
        if ($page === 1) {
            try {
                AccountBanService::restoreExpired();
            } catch (\Throwable $e) {
            }
        }
        $offset = ($page - 1) * $pageSize;
        [$where, $params] = $this->filters($request);
        $total = (int)(DB::selectOne("SELECT COUNT(*) AS c FROM accounts a LEFT JOIN account_sessions s ON s.account_id=a.id {$where}", $params)['c'] ?? 0);
        $rows = DB::select(self::SELECT . " {$where} ORDER BY a.id DESC LIMIT {$pageSize} OFFSET {$offset}", $params);
        $rows = $this->enrichOwnerMeta($rows);
        $rows = $this->enrichPickerCardMeta($rows);
        $pages = max(1, (int)ceil($total / $pageSize));
        return $this->success([
            'items' => array_map([$this, 'publicRow'], $rows),
            'total' => $total,
            'page' => min($page, $pages),
            'pages' => $pages,
            'pageSize' => $pageSize,
        ]);
    }

    public function stats(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ownerFilterId = $this->resolveAccountListOwnerFilterId($request);
        if ($ownerFilterId !== null) {
            $ownSql = ' AND owner_id = ?';
            $ownSqlA = ' AND a.owner_id = ?';
            $ownParams = [$ownerFilterId];
        } else {
            [$ownSql, $ownParams] = DataScope::accountListScopeSql('owner_id');
            [$ownSqlA] = DataScope::accountListScopeSql('a.owner_id');
        }
        $base = DB::selectOne("SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN login_status='online' THEN 1 ELSE 0 END) AS online,
            SUM(CASE WHEN login_status='offline' THEN 1 ELSE 0 END) AS offline,
            SUM(CASE WHEN login_status='expired' THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN has_session=1 THEN 1 ELSE 0 END) AS with_session,
            SUM(CASE WHEN capcut_authorized=1 THEN 1 ELSE 0 END) AS capcut,
            SUM(CASE WHEN account_status='banned' THEN 1 ELSE 0 END) AS banned,
            SUM(CASE WHEN browser_profile_id IS NOT NULL AND browser_profile_id<>'' THEN 1 ELSE 0 END) AS with_browser
          FROM accounts WHERE 1=1{$ownSql}", $ownParams);
        $followers = 0;
        $following = 0;
        $likes = 0;
        $videos = 0;
        try {
            $social = DB::selectOne(
                "SELECT COALESCE(SUM(p.followers_count),0) AS followers,
                        COALESCE(SUM(p.following_count),0) AS following,
                        COALESCE(SUM(p.likes_count),0) AS likes,
                        COALESCE(SUM(p.videos_count),0) AS videos
                 FROM tiktok_profiles p
                 INNER JOIN accounts a ON a.id=p.account_id
                 WHERE 1=1{$ownSqlA}",
                $ownParams
            );
            $followers = (int)($social['followers'] ?? 0);
            $following = (int)($social['following'] ?? 0);
            $likes = (int)($social['likes'] ?? 0);
            $videos = (int)($social['videos'] ?? 0);
        } catch (\Throwable $e) {
        }
        return $this->success([
            'total' => (int)($base['total'] ?? 0),
            'online' => (int)($base['online'] ?? 0),
            'offline' => (int)($base['offline'] ?? 0),
            'expired' => (int)($base['expired'] ?? 0),
            'with_session' => (int)($base['with_session'] ?? 0),
            'capcut' => (int)($base['capcut'] ?? 0),
            'banned' => (int)($base['banned'] ?? 0),
            'with_browser' => (int)($base['with_browser'] ?? 0),
            'followers' => $followers,
            'following' => $following,
            'likes' => $likes,
            'videos' => $videos,
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $row = DB::selectOne(self::SELECT . ' WHERE a.id=?', [(int)$id]);
        if ($err = $this->assertAccountRowAccess($row)) {
            return $err;
        }
        $enriched = $this->enrichOwnerMeta([$row]);
        $enriched = $this->enrichPickerCardMeta($enriched);
        return $this->success($this->publicRow($enriched[0] ?? $row));
    }

    public function export(Request $request): Response
    {
        if ($err = $this->assertCanExportAccounts()) {
            return $err;
        }
        $this->ensureSchema();
        DataScope::ensureColumns();
        $format = strtolower((string)$request->get('format', 'csv'));
        if (!in_array($format, ['txt', 'csv', 'json'], true)) {
            $format = 'csv';
        }

        $fieldDefs = $this->exportFieldDefs();
        $fieldsRaw = trim((string)$request->get('fields', ''));
        $selected = $fieldsRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $fieldsRaw))))
            : ['username', 'nickname', 'country', 'group', 'proxy', 'login', 'session', 'capcut', 'status'];
        $fields = [];
        foreach ($selected as $f) {
            if (isset($fieldDefs[$f])) {
                $fields[] = $f;
            }
        }
        if (!$fields) {
            $fields = array_keys($fieldDefs);
        }

        $headersRaw = trim((string)$request->get('headers', ''));
        $customHeaders = [];
        if ($headersRaw !== '') {
            $decoded = json_decode($headersRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $customHeaders[(string)$k] = trim((string)$v);
                }
            } else {
                $parts = array_map('trim', explode(',', $headersRaw));
                foreach ($fields as $i => $f) {
                    if (isset($parts[$i]) && $parts[$i] !== '') {
                        $customHeaders[$f] = $parts[$i];
                    }
                }
            }
        }
        $cols = [];
        foreach ($fields as $f) {
            $cols[] = [
                'key' => $f,
                'label' => (isset($customHeaders[$f]) && $customHeaders[$f] !== '')
                    ? $customHeaders[$f]
                    : $fieldDefs[$f],
            ];
        }

        $filters = [];
        $params = [];
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$request->get('ids', '')))));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $filters[] = "a.id IN ({$ph})";
            foreach ($ids as $id) {
                $params[] = $id;
            }
        } else {
            $groupId = $request->get('groupId');
            if ($groupId !== null && $groupId !== '' && $groupId !== 'none') {
                $gids = GroupController::expandAccountGroupIds((int)$groupId);
                if ($gids) {
                    $ph = implode(',', array_fill(0, count($gids), '?'));
                    $filters[] = "a.group_id IN ({$ph})";
                    foreach ($gids as $gid) {
                        $params[] = $gid;
                    }
                }
            } elseif ($groupId === 'none') {
                $filters[] = 'a.group_id IS NULL';
            }
            $keyword = trim((string)$request->get('keyword', ''));
            if ($keyword !== '') {
                $filters[] = '(a.username LIKE ? OR a.nickname LIKE ? OR a.notes LIKE ?)';
                $params[] = "%{$keyword}%";
                $params[] = "%{$keyword}%";
                $params[] = "%{$keyword}%";
            }
            $status = trim((string)$request->get('status', $request->get('loginStatus', '')));
            if ($status !== '') {
                $filters[] = 'a.login_status=?';
                $params[] = $status;
            }
            $accountStatus = trim((string)$request->get('accountStatus', ''));
            if ($accountStatus !== '') {
                $filters[] = 'a.account_status=?';
                $params[] = $accountStatus;
            }
            $country = trim((string)$request->get('country', ''));
            if ($country !== '') {
                $filters[] = '(COALESCE(NULLIF(TRIM(p.country), \'\'), \'\') = ? OR COALESCE(NULLIF(TRIM(a.country), \'\'), \'\') = ?)';
                $params[] = $country;
                $params[] = $country;
            }
            $capcut = $request->get('capcut');
            if ($capcut === '1' || $capcut === 1 || $capcut === true) {
                $filters[] = 'a.capcut_authorized=1';
            } elseif ($capcut === '0' || $capcut === 0) {
                $filters[] = '(a.capcut_authorized=0 OR a.capcut_authorized IS NULL)';
            }
            $session = $request->get('session');
            if ($session === '1' || $session === 1) {
                $filters[] = '(a.has_session=1 OR s.account_id IS NOT NULL)';
            }
            $idMin = (int)$request->get('idMin', $request->get('id_min', 0));
            $idMax = (int)$request->get('idMax', $request->get('id_max', 0));
            if ($idMin > 0) {
                $filters[] = 'a.id >= ?';
                $params[] = $idMin;
            }
            if ($idMax > 0) {
                $filters[] = 'a.id <= ?';
                $params[] = $idMax;
            }
        }
        $this->applyOwnerFilter('a.owner_id', $filters, $params);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $rows = DB::select(self::SELECT . " {$where} ORDER BY a.id DESC LIMIT 5000", $params);

        $pickMap = function (array $row) use ($fieldDefs): array {
            return [
                'id' => $row['id'] ?? '',
                'username' => $row['username'] ?? '',
                'nickname' => $row['nickname'] ?? '',
                'country' => $row['country'] ?? ($row['proxy_country'] ?? ''),
                'group' => $row['group_name'] ?? '',
                'proxy' => $row['proxy_address'] ?? '',
                'login' => $row['login_status'] ?? '',
                'session' => (!empty($row['has_session']) || !empty($row['session_saved'])) ? 'ck' : 'nock',
                'capcut' => !empty($row['capcut_authorized']) ? 'capcut' : 'nocapcut',
                'status' => $row['account_status'] ?? 'normal',
                'chat' => $row['chat_status'] ?? '',
                'notes' => $row['notes'] ?? '',
                'created_at' => $row['created_at'] ?? '',
                'browser' => $row['browser_profile_id'] ?? '',
            ];
        };

        if ($format === 'json') {
            $data = [];
            foreach ($rows as $row) {
                $map = $pickMap($row);
                $item = [];
                foreach ($cols as $c) {
                    $item[$c['key']] = $map[$c['key']] ?? '';
                }
                $data[] = $item;
            }
            $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $type = 'application/json; charset=utf-8';
            $name = 'accounts-export.json';
        } elseif ($format === 'csv') {
            $esc = static fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"';
            $lines = [implode(',', array_map(static fn($c) => '"' . str_replace('"', '""', $c['label']) . '"', $cols))];
            foreach ($rows as $row) {
                $map = $pickMap($row);
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $esc($map[$c['key']] ?? '');
                }
                $lines[] = implode(',', $line);
            }
            $body = "\xEF\xBB\xBF" . implode("\r\n", $lines);
            $type = 'text/csv; charset=utf-8';
            $name = 'accounts-export.csv';
        } else {
            $lines = [];
            foreach ($rows as $row) {
                $map = $pickMap($row);
                $lines[] = implode('----', array_map(static fn($c) => (string)($map[$c['key']] ?? ''), $cols));
            }
            $body = implode("\n", $lines);
            $type = 'text/plain; charset=utf-8';
            $name = 'accounts-export.txt';
        }
        return Response::make((string)$body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** @return array<string,string> */
    private function exportFieldDefs(): array
    {
        return [
            'id' => 'ID',
            'username' => '账号',
            'nickname' => '昵称',
            'country' => '国家',
            'group' => '分组',
            'proxy' => '代理',
            'login' => '登录状态',
            'session' => '会话',
            'capcut' => 'CapCut',
            'status' => '账号状态',
            'chat' => '客服状态',
            'notes' => '备注',
            'created_at' => '创建时间',
            'browser' => '浏览器环境ID',
        ];
    }

    public function create(Request $request): Response
    {
        if ($err = $this->assertCanCreateAccounts()) {
            return $err;
        }
        $this->ensureSchema();
        $username = trim((string)$request->input('username', ''));
        if ($username === '') {
            return $this->error('账号名称不能为空');
        }
        try {
            [, $hasIndustryInput, $industryErr] = $this->parseIndustryBinding($request);
            if ($industryErr) {
                return $industryErr;
            }
            $id = $this->insertAccount($this->bodyToRow($request));
            $row = DB::selectOne(self::SELECT . ' WHERE a.id=?', [$id]);
            return $this->success($this->publicRow($row), '账号已创建');
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return $this->error('该账号用户名已存在', 409);
            }
            throw $e;
        }
    }

    public function update(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        \App\Services\AccountWorkBatchService::ensureUsernamePrevColumn();
        if ($err = $this->assertCanEditAccounts()) {
            return $err;
        }
        $old = DB::selectOne('SELECT id, owner_id, username, username_prev, country FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($old)) {
            return $err;
        }
        [$overrideOwnerId, $overrideCompanyId, $ownerErr] = $this->parseAssignableOwner($request);
        if ($ownerErr) {
            return $ownerErr;
        }
        [$explicitCompanyId, $hasCompanyInput, $companyErr] = $this->parseCompanyBinding($request);
        if ($companyErr) {
            return $companyErr;
        }
        $row = $this->bodyToRow($request);
        if (!empty($row['proxy_id'])) {
            $check = $this->evaluateProxyBindLimit((int)$row['proxy_id'], [(int)$id]);
            if (empty($check['allowed'])) {
                return $this->error((string)($check['message'] ?? '该代理绑定账号数将超过上限'), 422);
            }
        }
        try {
            // 请求里没带 country 字段（局部更新/旧客户端）时沿用库里的值，避免编辑其它项把国家清空
            $payloadAll = $request->all();
            $hasCountryInput = array_key_exists('country', $payloadAll) || array_key_exists('country_code', $payloadAll)
                || array_key_exists('countryCode', $payloadAll);
            $countryParam = $hasCountryInput
                ? trim((string)$request->input('country', $request->input('countryCode', $request->input('country_code', $row['country']))))
                : (string)($old['country'] ?? '');
            $baseSets = 'username=?, nickname=?, country=?, group_id=?, proxy_id=?, browser_type=?, browser_profile_id=?, browser_backend=?, login_status=?, chat_status=?, dm_channel=?, enabled=?, notes=?';
            $baseParams = [
                $row['username'], $row['nickname'], $countryParam, $row['group_id'], $row['proxy_id'],
                $row['browser_type'], $row['browser_profile_id'], $row['browser_backend'], $row['login_status'], $row['chat_status'],
                $row['dm_channel'], $row['enabled'], $row['notes'],
            ];
            $extraSets = [];
            $extraParams = [];
            if ($overrideOwnerId !== null) {
                // 0 = 清除关联人员（同时清空企业绑定）
                if ((int)$overrideOwnerId <= 0) {
                    $extraSets[] = 'owner_id=?';
                    $extraParams[] = null;
                    $extraSets[] = 'company_id=?';
                    $extraParams[] = null;
                } else {
                    $finalCompanyId = $overrideCompanyId;
                    if ($hasCompanyInput && ($overrideCompanyId === null || $overrideCompanyId <= 0)) {
                        $finalCompanyId = $explicitCompanyId;
                    }
                    $extraSets[] = 'owner_id=?';
                    $extraParams[] = $overrideOwnerId;
                    $extraSets[] = 'company_id=?';
                    $extraParams[] = $finalCompanyId;
                }
            } elseif ($hasCompanyInput) {
                $extraSets[] = 'company_id=?';
                $extraParams[] = $explicitCompanyId;
            }
            [$explicitIndustryId, $hasIndustryInput, $industryErr] = $this->parseIndustryBinding($request);
            if ($industryErr) {
                return $industryErr;
            }
            if ($hasIndustryInput) {
                $extraSets[] = 'industry_id=?';
                $extraParams[] = $explicitIndustryId;
            }
            // 账号名被改动 → 记下原名：TikTok 上仍是原名，登录必须用原名
            $prevU = ltrim(trim((string)($old['username'] ?? '')), '@');
            $nextU = ltrim(trim((string)($row['username'] ?? '')), '@');
            if ($prevU !== '' && $nextU !== '' && strcasecmp($prevU, $nextU) !== 0) {
                $keptPrev = ltrim(trim((string)($old['username_prev'] ?? '')), '@');
                if ($keptPrev !== '' && strcasecmp($keptPrev, $nextU) === 0) {
                    $extraSets[] = 'username_prev=NULL';
                } else {
                    $extraSets[] = 'username_prev=?';
                    $extraParams[] = $prevU;
                }
            }
            $sql = 'UPDATE accounts SET ' . $baseSets
                . ($extraSets ? ', ' . implode(', ', $extraSets) : '')
                . ', updated_at=CURRENT_TIMESTAMP WHERE id=?';
            DB::query($sql, array_merge($baseParams, $extraParams, [(int)$id]));
            // 按所选后端把环境 ID 落到对应列（browser_profile_id / tkswarm_browser_profile_id / linux_browser_profile_id）
            // 并同步 account_browser_envs，保证「编辑页切后端」与任务里的「执行浏览器」一致。
            // 仅在请求确实带了绑定相关字段时执行，避免其他调用方 PUT 账号时误清环境。
            $payload = $request->all();
            $hasBindingInput = array_key_exists('browserBackend', $payload)
                || array_key_exists('browser_backend', $payload)
                || array_key_exists('browserProfileId', $payload)
                || array_key_exists('browser_profile_id', $payload)
                || array_key_exists('browserType', $payload);
            if ($hasBindingInput) {
                try {
                    \App\Services\AccountBrowserEnvService::applyAccountBackend(
                        (int)$id,
                        (string)($row['browser_backend'] ?? 'bit'),
                        (string)($row['browser_profile_id'] ?? '')
                    );
                } catch (\Throwable $bindErr) {
                    return $this->error($bindErr->getMessage() ?: '保存浏览器环境绑定失败', 422);
                }
            }
            $this->syncLegacyStatus((int)$id, $row['login_status'], $row['browser_profile_id']);
            $fresh = DB::selectOne(self::SELECT . ' WHERE a.id=?', [(int)$id]);
            return $this->success($this->publicRow($fresh), '账号已更新');
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return $this->error('该账号用户名已存在', 409);
            }
            throw $e;
        }
    }

    public function secrets(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        if ($err = $this->assertCanEditAccounts()) {
            return $err;
        }
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $password = (string)$request->input('password', '');
        $totp = strtoupper(preg_replace('/\s+/', '', (string)$request->input('totpSecret', '')) ?? '');
        if (trim($password) === '' && $totp === '') {
            return $this->error('请至少填写新密码或新 2FA 密钥', 422);
        }
        if ($totp !== '' && (!preg_match('/^[A-Z2-7]+=*$/', $totp) || strlen($totp) < 16)) {
            return $this->error('2FA 密钥不是有效的 Base32 格式', 422);
        }
        $existing = DB::selectOne('SELECT password_encrypted, totp_secret_encrypted FROM account_secrets WHERE account_id=?', [(int)$id]) ?: [];
        $passEnc = $password !== '' ? SecretStore::encrypt($password) : (string)($existing['password_encrypted'] ?? '');
        $totpEnc = $totp !== '' ? SecretStore::encrypt($totp) : (string)($existing['totp_secret_encrypted'] ?? '');
        DB::query(
            'INSERT INTO account_secrets(account_id, password_encrypted, totp_secret_encrypted) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE password_encrypted=VALUES(password_encrypted), totp_secret_encrypted=VALUES(totp_secret_encrypted), updated_at=CURRENT_TIMESTAMP',
            [(int)$id, $passEnc, $totpEnc]
        );
        return $this->success(['passwordUpdated' => $password !== '', 'totpUpdated' => $totp !== ''], '账号凭据已加密更新');
    }

    public function patchStatus(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT id, owner_id, company_id FROM accounts WHERE id=?', [(int)$id]);
        if (!$old) {
            return $this->error('资源不存在', 404);
        }
        if ($err = $this->assertAccountOwns($old)) {
            // 共用浏览器环境：账号已绑定环境时，允许任何登录用户回写「运行时登录状态」。
            // 场景：A 建环境并绑定账号，B 打开该环境，浏览器检测到的登录态必须能写回，
            // 否则账号状态永远停在旧值。注意只放开状态回写，账号资料仍按原权限校验。
            $bound = DB::selectOne(
                'SELECT 1 AS ok FROM account_browser_envs WHERE account_id=? LIMIT 1',
                [(int)$id]
            );
            if (!$bound) {
                return $err;
            }
        }
        $status = (string)$request->input('loginStatus', '');
        if (!in_array($status, ['offline', 'online', 'expired', 'checking'], true)) {
            return $this->error('登录状态无效', 422);
        }
        DB::query('UPDATE accounts SET login_status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$status, (int)$id]);
        // 退出/标为离线：删除该 TikTok 唯一会话日志（下次登录重新表单或重新写入）
        if ($status === 'offline' || $status === 'expired') {
            DB::query('DELETE FROM account_sessions WHERE account_id=?', [(int)$id]);
            DB::query('UPDATE accounts SET has_session=0, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
        }
        $this->syncLegacyStatus((int)$id, $status, null);
        return $this->success(null, '账号状态已更新');
    }

    public function patchNotes(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        if ($err = $this->assertCanEditAccounts()) {
            return $err;
        }
        $old = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($old)) {
            return $err;
        }
        $notes = trim((string)$request->input('notes', ''));
        DB::query('UPDATE accounts SET notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$notes, (int)$id]);
        return $this->success(['id' => (int)$id, 'notes' => $notes], '备注已更新');
    }

    public function patchNickname(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        if ($err = $this->assertCanEditAccounts()) {
            return $err;
        }
        $old = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($old)) {
            return $err;
        }
        $nickname = trim((string)$request->input('nickname', ''));
        DB::query('UPDATE accounts SET nickname=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$nickname, (int)$id]);
        return $this->success(['id' => (int)$id, 'nickname' => $nickname], '昵称已更新（仅本系统）');
    }

    /**
     * 保存系统侧待同步到 TikTok 的资料（Username/Name/Bio/头像）。
     * 昵称若仍在 7 天冷却内则跳过写入并返回 nicknameLocked。
     */
    public function patchProfileSettings(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        \App\Services\AccountWorkBatchService::ensureUsernamePrevColumn();
        if ($err = $this->assertCanEditAccounts()) {
            return $err;
        }
        $old = DB::selectOne(
            'SELECT id, owner_id, username, username_prev, nickname, profile_bio, profile_avatar, nickname_changed_at FROM accounts WHERE id=?',
            [(int)$id]
        );
        if ($err = $this->assertAccountOwns($old)) {
            return $err;
        }
        $all = $request->all();
        $sets = [];
        $bind = [];
        $nicknameLocked = false;
        $nicknameSkipped = false;
        $cooldown = $this->nicknameCooldownInfo($old['nickname_changed_at'] ?? null);
        // 系统里的 7 天记录可能来自历史误判，允许用户显式覆盖
        $forceNickname = (bool)$request->input('forceNickname', $request->input('force_nickname', false));

        if (array_key_exists('username', $all)) {
            $username = ltrim(trim((string)$request->input('username', '')), '@');
            if ($username === '') {
                return $this->error('账号名称不能为空', 422);
            }
            $dup = DB::selectOne(
                'SELECT id FROM accounts WHERE username=? AND id<>? LIMIT 1',
                [$username, (int)$id]
            );
            if ($dup) {
                return $this->error('该账号用户名已存在', 409);
            }
            $prevUsername = ltrim(trim((string)($old['username'] ?? '')), '@');
            $sets[] = 'username=?';
            $bind[] = $username;
            if ($prevUsername !== '' && strcasecmp($prevUsername, $username) !== 0) {
                $keptPrev = ltrim(trim((string)($old['username_prev'] ?? '')), '@');
                if ($keptPrev !== '' && strcasecmp($keptPrev, $username) === 0) {
                    $sets[] = 'username_prev=NULL';
                } else {
                    // 系统名先改了、TikTok 还没改：留住原名，登录仍用原名（否则必然登不上）
                    $sets[] = 'username_prev=?';
                    $bind[] = $prevUsername;
                }
            }
        }

        if (array_key_exists('nickname', $all) || array_key_exists('displayName', $all)) {
            $nickname = trim((string)$request->input('nickname', $request->input('displayName', '')));
            $prevNick = trim((string)($old['nickname'] ?? ''));
            if ($nickname !== '' && $nickname !== $prevNick && $cooldown['locked'] && !$forceNickname) {
                $nicknameLocked = true;
                $nicknameSkipped = true;
            } else {
                $sets[] = 'nickname=?';
                $bind[] = $nickname;
            }
        }
        if (array_key_exists('signature', $all) || array_key_exists('bio', $all) || array_key_exists('profileBio', $all)) {
            $sets[] = 'profile_bio=?';
            $bind[] = trim((string)$request->input('signature', $request->input('bio', $request->input('profileBio', ''))));
        }
        if (array_key_exists('avatarUrl', $all) || array_key_exists('avatarPath', $all) || array_key_exists('profileAvatar', $all)) {
            $sets[] = 'profile_avatar=?';
            $bind[] = trim((string)$request->input('avatarUrl', $request->input('avatarPath', $request->input('profileAvatar', ''))));
        }
        if (!$sets && !$nicknameSkipped) {
            return $this->error('请至少提供账号名称、昵称、签名或头像', 422);
        }
        if ($sets) {
            $sets[] = 'updated_at=CURRENT_TIMESTAMP';
            $bind[] = (int)$id;
            DB::query('UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id=?', $bind);
        }
        $row = DB::selectOne(
            'SELECT id, username, nickname, profile_bio, profile_avatar, nickname_changed_at FROM accounts WHERE id=?',
            [(int)$id]
        );
        $cool = $this->nicknameCooldownInfo($row['nickname_changed_at'] ?? null);
        return $this->success([
            'id' => (int)$id,
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
            'profileBio' => (string)($row['profile_bio'] ?? ''),
            'profileAvatar' => (string)($row['profile_avatar'] ?? ''),
            'nicknameChangedAt' => $row['nickname_changed_at'] ?? null,
            'nicknameLocked' => $cool['locked'] || $nicknameLocked,
            'nicknameLockHint' => $cool['hint'],
            'nicknameSkipped' => $nicknameSkipped,
        ], $nicknameSkipped
            ? '系统资料已保存（昵称仍在 7 天冷却内，未改昵称）'
            : '系统资料已保存，可点「同步资料」推送到 TikTok');
    }

    /**
     * 浏览器执行修改资料后的回写：恢复 Username/昵称、记录昵称锁定时间等。
     */
    public function patchProfileOutcome(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        \App\Services\AccountWorkBatchService::ensureUsernamePrevColumn();
        $old = DB::selectOne(
            'SELECT id, owner_id, username, nickname, nickname_changed_at FROM accounts WHERE id=?',
            [(int)$id]
        );
        if ($err = $this->assertAccountOwns($old)) {
            return $err;
        }
        $all = $request->all();
        $sets = [];
        $bind = [];

        if (array_key_exists('restoreUsername', $all) || array_key_exists('username', $all)) {
            $username = ltrim(trim((string)$request->input(
                'restoreUsername',
                $request->input('username', '')
            )), '@');
            if ($username !== '') {
                $dup = DB::selectOne(
                    'SELECT id FROM accounts WHERE username=? AND id<>? LIMIT 1',
                    [$username, (int)$id]
                );
                if (!$dup) {
                    $sets[] = 'username=?';
                    $bind[] = $username;
                    // 已还原成 TikTok 上的原名，备用原名不再需要
                    $sets[] = 'username_prev=NULL';
                }
            }
        }
        if (array_key_exists('restoreNickname', $all) || array_key_exists('nickname', $all)) {
            $nick = trim((string)$request->input(
                'restoreNickname',
                $request->input('nickname', '')
            ));
            $sets[] = 'nickname=?';
            $bind[] = $nick;
        }
        $markNickChanged = filter_var($request->input('markNicknameChanged', false), FILTER_VALIDATE_BOOLEAN)
            || filter_var($request->input('nicknameLocked', false), FILTER_VALIDATE_BOOLEAN)
            || filter_var($request->input('nicknameSuccess', false), FILTER_VALIDATE_BOOLEAN);
        if ($markNickChanged) {
            $sets[] = 'nickname_changed_at=CURRENT_TIMESTAMP';
        }
        // 新 Username 已在 TikTok 生效（非任务通道的“同步资料”）：原名作废，登录改用新名
        $usernameApplied = filter_var(
            $request->input('usernameApplied', $request->input('username_applied', $request->input('usernameSuccess', false))),
            FILTER_VALIDATE_BOOLEAN
        );
        if ($usernameApplied) {
            $sets[] = 'username_prev=NULL';
        }
        if (!$sets) {
            return $this->error('无更新内容', 422);
        }
        $sets[] = 'updated_at=CURRENT_TIMESTAMP';
        $bind[] = (int)$id;
        DB::query('UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id=?', $bind);
        $row = DB::selectOne(
            'SELECT id, username, nickname, nickname_changed_at FROM accounts WHERE id=?',
            [(int)$id]
        );
        $cool = $this->nicknameCooldownInfo($row['nickname_changed_at'] ?? null);
        return $this->success([
            'id' => (int)$id,
            'username' => (string)($row['username'] ?? ''),
            'nickname' => (string)($row['nickname'] ?? ''),
            'nicknameChangedAt' => $row['nickname_changed_at'] ?? null,
            'nicknameLocked' => $cool['locked'],
            'nicknameLockHint' => $cool['hint'],
        ], '资料回写已保存');
    }

    /** @return array{locked:bool,hint:string,remainDays:int,changedAt:?string} */
    private function nicknameCooldownInfo(mixed $changedAt): array
    {
        $raw = trim((string)($changedAt ?? ''));
        if ($raw === '' || $raw === '0000-00-00 00:00:00') {
            return ['locked' => false, 'hint' => '', 'remainDays' => 0, 'changedAt' => null];
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return ['locked' => false, 'hint' => '', 'remainDays' => 0, 'changedAt' => null];
        }
        $elapsed = time() - $ts;
        $window = 7 * 24 * 3600;
        if ($elapsed >= $window) {
            return ['locked' => false, 'hint' => '', 'remainDays' => 0, 'changedAt' => $raw];
        }
        $remain = (int)ceil(($window - $elapsed) / 86400);
        return [
            'locked' => true,
            'hint' => '距离上次修改未超过7天暂时无法修改',
            'remainDays' => max(1, $remain),
            'changedAt' => $raw,
        ];
    }

    public function delete(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $old = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($old)) {
            return $err;
        }
        DB::query('DELETE FROM accounts WHERE id=?', [(int)$id]);
        return $this->success(null, '账号已删除');
    }

    public function batchImport(Request $request): Response
    {
        if ($err = $this->assertCanImportAccounts()) {
            return $err;
        }
        $this->ensureSchema();
        $content = (string)$request->input('content', '');
        $format = (string)$request->input('format', 'auto');
        $lines = preg_split('/\r?\n/', $content) ?: [];
        if (count($lines) > 20000) {
            return $this->error('单次最多导入 20000 行', 422);
        }
        $strategy = strtolower(trim((string)$request->input('proxyStrategy', 'none')));
        if (!in_array($strategy, ['none', 'sequential', 'single'], true)) {
            $strategy = 'none';
        }
        $proxyId = (int)$request->input('proxyId', 0);
        $proxyGroupId = (int)$request->input('proxyGroupId', 0);
        $groupId = $request->input('groupId');
        $groupId = ($groupId === null || $groupId === '') ? null : (int)$groupId;
        $country = trim((string)$request->input('country', ''));
        $browserType = trim((string)$request->input('browserType', 'bit')) ?: 'bit';

        // none：明确不绑定，忽略传入的 proxyId / proxyGroupId
        $proxyIds = [];
        if ($strategy === 'single') {
            if ($proxyId < 1) {
                return $this->error('选择“统一代理”时必须指定代理', 422);
            }
            $proxyIds = [$proxyId];
        } elseif ($strategy === 'sequential') {
            $proxyIds = $this->proxyIdsForAccountImport($proxyGroupId);
            $proxyIds = array_map('intval', $proxyIds);
            if (!$proxyIds) {
                return $this->error('没有可用于顺序绑定的代理，请先导入代理或选择其他绑定方式', 422);
            }
        }

        $result = [
            'total' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'secretsUpdated' => 0,
            'ignored' => 0,
            'errors' => [],
        ];
        $assigned = 0;
        $importBindings = DataScope::accountInsertBindings();
        $dedupOwnerId = $importBindings ? (int)$importBindings['owner_id'] : null;
        foreach ($lines as $index => $source) {
            // 去掉尾注如「（已用）」，避免污染 2FA 字段
            $line = trim((string)preg_replace('/[（(]\s*已用\s*[）)]/u', '', (string)$source));
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                $result['ignored']++;
                continue;
            }
            $result['total']++;
            try {
                $parsed = AccountImport::parseLine($line, $format);
                if (!$parsed || ($parsed['username'] ?? '') === '') {
                    throw new \InvalidArgumentException('未能解析出账号');
                }
                if (mb_strlen($parsed['username']) > 100) {
                    throw new \InvalidArgumentException('账号不能超过 100 个字符');
                }

                $password = (string)($parsed['password'] ?? '');
                $totpSecret = (string)($parsed['totpSecret'] ?? '');
                $cookie = (string)($parsed['cookie'] ?? '');

                // 先加密，密钥目录异常时不要先插账号再失败留下“无密码账号”
                $passEnc = $password !== '' ? SecretStore::encrypt($password) : '';
                $totpEnc = $totpSecret !== '' ? SecretStore::encrypt($totpSecret) : '';
                $cookieEnc = $cookie !== '' ? SecretStore::encrypt($cookie) : '';

                $existing = $dedupOwnerId !== null && $dedupOwnerId > 0
                    ? DB::selectOne(
                        'SELECT id FROM accounts WHERE username=? AND owner_id=? LIMIT 1',
                        [$parsed['username'], $dedupOwnerId]
                    )
                    : DB::selectOne(
                        'SELECT id FROM accounts WHERE username=? AND (owner_id IS NULL OR owner_id=0) LIMIT 1',
                        [$parsed['username']]
                    );

                if ($existing) {
                    $accountId = (int)$existing['id'];
                    $updatedSecret = false;
                    if ($country !== '') {
                        DB::query(
                            'UPDATE accounts SET country=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                            [$country, $accountId]
                        );
                    }
                    if ($passEnc !== '' || $totpEnc !== '') {
                        $old = DB::selectOne(
                            'SELECT password_encrypted, totp_secret_encrypted FROM account_secrets WHERE account_id=?',
                            [$accountId]
                        ) ?: [];
                        $nextPass = $passEnc !== '' ? $passEnc : (string)($old['password_encrypted'] ?? '');
                        $nextTotp = $totpEnc !== '' ? $totpEnc : (string)($old['totp_secret_encrypted'] ?? '');
                        DB::query(
                            'INSERT INTO account_secrets(account_id, password_encrypted, totp_secret_encrypted) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE password_encrypted=VALUES(password_encrypted), totp_secret_encrypted=VALUES(totp_secret_encrypted), updated_at=CURRENT_TIMESTAMP',
                            [$accountId, $nextPass, $nextTotp]
                        );
                        $updatedSecret = true;
                    }
                    if ($cookieEnc !== '') {
                        DB::query(
                            'INSERT INTO account_sessions(account_id, cookie_encrypted) VALUES (?,?)
                             ON DUPLICATE KEY UPDATE cookie_encrypted=VALUES(cookie_encrypted), updated_at=CURRENT_TIMESTAMP',
                            [$accountId, $cookieEnc]
                        );
                        DB::query('UPDATE accounts SET has_session=1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$accountId]);
                        $updatedSecret = true;
                    }
                    $result['duplicates']++;
                    if ($updatedSecret) {
                        $result['secretsUpdated']++;
                    }
                    continue;
                }

                $assignedProxy = null;
                if ($strategy !== 'none' && $proxyIds) {
                    $assignedProxy = (int)$proxyIds[$assigned % count($proxyIds)];
                }
                $id = $this->insertAccount([
                    'username' => $parsed['username'],
                    'nickname' => $parsed['nickname'] ?? '',
                    'country' => $country !== '' ? $country : ($parsed['country'] ?? ''),
                    'group_id' => $groupId,
                    'proxy_id' => $assignedProxy,
                    'browser_type' => $browserType,
                    'browser_profile_id' => '',
                    'login_status' => 'offline',
                    'chat_status' => 'offline',
                    'enabled' => 1,
                    'notes' => '',
                    'has_session' => $cookie !== '' ? 1 : 0,
                ]);
                if ($passEnc !== '' || $totpEnc !== '') {
                    DB::query(
                        'INSERT INTO account_secrets(account_id, password_encrypted, totp_secret_encrypted) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE password_encrypted=VALUES(password_encrypted), totp_secret_encrypted=VALUES(totp_secret_encrypted), updated_at=CURRENT_TIMESTAMP',
                        [$id, $passEnc, $totpEnc]
                    );
                }
                if ($cookieEnc !== '') {
                    DB::query(
                        'INSERT INTO account_sessions(account_id, cookie_encrypted) VALUES (?,?)
                         ON DUPLICATE KEY UPDATE cookie_encrypted=VALUES(cookie_encrypted), updated_at=CURRENT_TIMESTAMP',
                        [$id, $cookieEnc]
                    );
                }
                $assigned++;
                $result['imported']++;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $result['duplicates']++;
                } elseif (count($result['errors']) < 100) {
                    $result['errors'][] = ['line' => $index + 1, 'reason' => $e->getMessage()];
                }
            } catch (\Throwable $e) {
                if (count($result['errors']) < 100) {
                    $result['errors'][] = ['line' => $index + 1, 'reason' => $e->getMessage()];
                }
            }
        }
        $msg = "导入完成：成功 {$result['imported']}，重复 {$result['duplicates']}";
        if ($result['secretsUpdated'] > 0) {
            $msg .= "，已补写密码/2FA {$result['secretsUpdated']}";
        }
        $msg .= '，错误 ' . count($result['errors']);
        return $this->success($result, $msg);
    }

    public function batchDelete(Request $request): Response
    {
        $this->ensureSchema();
        $ids = $this->idList($request->input('accountIds', []));
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        DataScope::ensureColumns();
        [$ownSql, $ownParams] = $this->accountScopeSql('owner_id');
        $existing = array_map('intval', array_column(
            DB::select("SELECT id FROM accounts WHERE id IN ({$ph}){$ownSql}", array_merge($ids, $ownParams)),
            'id'
        ));
        if (!$existing) {
            return $this->error('没有找到要删除的账号', 404);
        }
        $ph2 = implode(',', array_fill(0, count($existing), '?'));
        DB::query("DELETE FROM accounts WHERE id IN ({$ph2})", $existing);
        return $this->success([
            'requested' => count($ids),
            'deleted' => count($existing),
            'notFound' => count($ids) - count($existing),
        ], '批量删除完成');
    }

    public function batchDeleteBanned(Request $request): Response
    {
        $this->ensureSchema();
        $groupId = (int)$request->input('groupId', 0);
        $sql = "SELECT id FROM accounts WHERE account_status='banned'";
        $params = [];
        DataScope::ensureColumns();
        [$ownSql, $ownParams] = $this->accountScopeSql('owner_id');
        $sql .= $ownSql;
        $params = array_merge($params, $ownParams);
        if ($groupId > 0) {
            $gids = GroupController::expandAccountGroupIds($groupId);
            $ph = implode(',', array_fill(0, count($gids), '?'));
            $sql .= " AND group_id IN ({$ph})";
            $params = array_merge($params, $gids);
        }
        $rows = DB::select($sql, $params);
        if (!$rows) {
            return $this->success(['deleted' => 0], '没有封号账号');
        }
        $ids = array_column($rows, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        DB::query("DELETE FROM accounts WHERE id IN ({$ph})", $ids);
        return $this->success(['deleted' => count($ids)], '已删除 ' . count($ids) . ' 个封号账号');
    }

    public function batchMoveGroup(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('accountIds', []));
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }
        $groupId = $request->input('groupId');
        $groupId = ($groupId === null || $groupId === '') ? null : (int)$groupId;
        if ($groupId) {
            $g = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='account'", [$groupId]);
            if (!$g) {
                return $this->error('账号分组不存在', 404);
            }
            if ($err = DataScope::assertGroupOwns($g)) {
                return $err;
            }
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->accountScopeSql('owner_id');
        $existing = array_map('intval', array_column(
            DB::select("SELECT id FROM accounts WHERE id IN ({$ph}){$ownSql}", array_merge($ids, $ownParams)),
            'id'
        ));
        if (!$existing) {
            return $this->error('没有可转移的账号', 404);
        }
        $ph2 = implode(',', array_fill(0, count($existing), '?'));
        $stmt = DB::query(
            "UPDATE accounts SET group_id=?, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph2})",
            array_merge([$groupId], $existing)
        );
        return $this->success(['updated' => $stmt->rowCount()], '分组已更新');
    }

    /** 批量按字段更新账号：仅更新请求中提供的字段，未传的字段不改 */
    public function batchUpdate(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        if ($err = $this->assertCanBatchModifyAccounts()) {
            return $err;
        }
        $ids = $this->idList($request->input('accountIds', []));
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }

        $fields = $request->input('fields', []);
        if (!is_array($fields) || !$fields) {
            return $this->error('请至少选择一项要修改的内容', 422);
        }

        $sets = [];
        $bind = [];
        $changed = [];

        if (array_key_exists('groupId', $fields) || array_key_exists('group_id', $fields)) {
            // proxyId/groupId 清空时前端传 null；`a ?? b` 在 a 为 null 时会读 b，需落到 ?? null 避免未定义键告警
            $groupId = $fields['groupId'] ?? $fields['group_id'] ?? null;
            $groupId = ($groupId === null || $groupId === '' || $groupId === '__clear__') ? null : (int)$groupId;
            if ($groupId) {
                $g = DB::selectOne("SELECT id, owner_id FROM `groups` WHERE id=? AND type='account'", [$groupId]);
                if (!$g) {
                    return $this->error('账号分组不存在', 404);
                }
                if ($err = DataScope::assertGroupOwns($g)) {
                    return $err;
                }
            }
            $sets[] = 'group_id=?';
            $bind[] = $groupId;
            $changed[] = 'group';
        }

        $pendingProxyIds = null;
        if (array_key_exists('proxyIds', $fields) && is_array($fields['proxyIds'])) {
            $pendingProxyIds = array_values(array_unique(array_filter(array_map('intval', $fields['proxyIds']))));
            if (!$pendingProxyIds) {
                return $this->error('请选择至少一个代理', 422);
            }
            foreach ($pendingProxyIds as $proxyId) {
                $p = DB::selectOne('SELECT id FROM proxies WHERE id=?', [$proxyId]);
                if (!$p) {
                    return $this->error('代理不存在', 404);
                }
            }
            $changed[] = 'proxy';
        }

        if ($pendingProxyIds === null && (array_key_exists('proxyId', $fields) || array_key_exists('proxy_id', $fields))) {
            $proxyId = $fields['proxyId'] ?? $fields['proxy_id'] ?? null;
            $proxyId = ($proxyId === null || $proxyId === '' || $proxyId === '__clear__') ? null : (int)$proxyId;
            if ($proxyId) {
                $p = DB::selectOne('SELECT id FROM proxies WHERE id=?', [$proxyId]);
                if (!$p) {
                    return $this->error('代理不存在', 404);
                }
                $check = $this->evaluateProxyBindLimit($proxyId, $ids);
                if (empty($check['allowed'])) {
                    return $this->error((string)($check['message'] ?? '该代理绑定账号数将超过上限'), 422);
                }
            }
            $sets[] = 'proxy_id=?';
            $bind[] = $proxyId;
            $changed[] = 'proxy';
        }

        if (array_key_exists('country', $fields) || array_key_exists('countryCode', $fields)) {
            $country = trim((string)($fields['country'] ?? $fields['countryCode'] ?? ''));
            if ($country === '__clear__') {
                $country = '';
            }
            $sets[] = 'country=?';
            $bind[] = $country !== '' ? $country : null;
            $changed[] = 'country';
        }

        if (array_key_exists('ownerId', $fields) || array_key_exists('owner_id', $fields)) {
            $rawOwner = $fields['ownerId'] ?? $fields['owner_id'] ?? null;
            $clearOwner = $rawOwner === '__clear__'
                || $rawOwner === ''
                || $rawOwner === null
                || (int)$rawOwner === 0;
            if ($clearOwner) {
                if (!DataScope::canAssignAccountOwners()) {
                    return $this->error('当前角色无权清除账号关联人员', 403);
                }
                $sets[] = 'owner_id=?';
                $bind[] = null;
                $sets[] = 'company_id=?';
                $bind[] = null;
                $changed[] = 'owner';
                $changed[] = 'company';
            } else {
                $targetOwnerId = (int)$rawOwner;
                $companyId = isset($fields['companyId']) || isset($fields['company_id'])
                    ? (int)($fields['companyId'] ?? $fields['company_id'] ?? 0)
                    : null;
                [$resolvedOwner, $resolvedCompany, $assignErr] = $this->resolveOwnerAssignment($targetOwnerId, $companyId);
                if ($assignErr) {
                    return $assignErr;
                }
                $sets[] = 'owner_id=?';
                $bind[] = $resolvedOwner;
                $sets[] = 'company_id=?';
                $bind[] = $resolvedCompany;
                $changed[] = 'owner';
                if ($resolvedCompany !== null && $resolvedCompany > 0) {
                    $changed[] = 'company';
                }
            }
        }

        if (array_key_exists('companyId', $fields) || array_key_exists('company_id', $fields)) {
            if (!DataScope::canAssignAccountOwners()) {
                return $this->error('当前角色无权绑定企业', 403);
            }
            $companyRaw = $fields['companyId'] ?? $fields['company_id'] ?? null;
            $companyId = ($companyRaw === null || $companyRaw === '' || $companyRaw === '__clear__') ? null : (int)$companyRaw;
            if ($companyId !== null && $companyId > 0) {
                CompanyService::ensureSchema();
                if (!CompanyService::find($companyId)) {
                    return $this->error('企业不存在', 404);
                }
            } else {
                $companyId = null;
            }
            $sets[] = 'company_id=?';
            $bind[] = $companyId;
            $changed[] = 'company';
        }

        if (array_key_exists('industryId', $fields) || array_key_exists('industry_id', $fields)) {
            $raw = $fields['industryId'] ?? $fields['industry_id'] ?? null;
            $industryId = null;
            if ($raw !== null && $raw !== '' && $raw !== '__clear__' && $raw !== 0 && $raw !== '0') {
                $industryId = (int)$raw;
                if ($industryId < 1) {
                    return $this->error('行业分类无效', 422);
                }
                IndustryDataService::ensureSchema();
                $node = DB::selectOne('SELECT id FROM industry_data WHERE id=? AND enabled=1', [$industryId]);
                if (!$node) {
                    return $this->error('行业分类不存在', 404);
                }
            }
            $sets[] = 'industry_id=?';
            $bind[] = $industryId;
            $changed[] = 'industry';
        }

        if (array_key_exists('enabled', $fields)) {
            $sets[] = 'enabled=?';
            $bind[] = (int)$fields['enabled'] ? 1 : 0;
            $changed[] = 'enabled';
        }

        $batchMailPush = null;
        if (array_key_exists('dataMailPush', $fields) || array_key_exists('data_mail_push', $fields)) {
            PullDataAnalysisService::ensureAccountDataMailPushColumn();
            $rawPush = $fields['dataMailPush'] ?? $fields['data_mail_push'];
            $batchMailPush = ((int)$rawPush) ? 1 : 0;
            $sets[] = 'data_mail_push=?';
            $bind[] = $batchMailPush;
            $changed[] = 'dataMailPush';
        }

        if (array_key_exists('dmChannel', $fields) || array_key_exists('dm_channel', $fields)) {
            $ch = trim((string)($fields['dmChannel'] ?? $fields['dm_channel'] ?? ''));
            if (!in_array($ch, ['auto', 'api', 'browser'], true)) {
                return $this->error('私信通道无效', 422);
            }
            $sets[] = 'dm_channel=?';
            $bind[] = $ch;
            $changed[] = 'dmChannel';
        }

        if (array_key_exists('nickname', $fields)) {
            $sets[] = 'nickname=?';
            $bind[] = trim((string)$fields['nickname']);
            $changed[] = 'nickname';
        }

        if (array_key_exists('notes', $fields)) {
            $sets[] = 'notes=?';
            $bind[] = (string)$fields['notes'];
            $changed[] = 'notes';
        }

        if (!$sets && !$changed) {
            return $this->error('请至少选择一项要修改的内容', 422);
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->accountScopeSql('owner_id');
        $existing = array_map('intval', array_column(
            DB::select("SELECT id FROM accounts WHERE id IN ({$ph}){$ownSql}", array_merge($ids, $ownParams)),
            'id'
        ));
        if (!$existing) {
            return $this->error('没有可更新的账号（可能不在当前数据范围内）', 404);
        }

        if ($pendingProxyIds !== null) {
            try {
                $this->assignProxiesRoundRobin($existing, $pendingProxyIds);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 422);
            }
        }

        $updated = 0;
        if ($sets) {
            $ph2 = implode(',', array_fill(0, count($existing), '?'));
            $sets[] = 'updated_at=CURRENT_TIMESTAMP';
            $stmt = DB::query(
                'UPDATE accounts SET ' . implode(', ', $sets) . " WHERE id IN ({$ph2})",
                array_merge($bind, $existing)
            );
            $updated = $stmt->rowCount();
        } elseif ($pendingProxyIds !== null) {
            $updated = count($existing);
        }

        $cancelledPending = 0;
        if ($batchMailPush === 0) {
            foreach ($existing as $aid) {
                $cancelledPending += PullDataAnalysisService::cancelPendingForAccount(
                    (int)$aid,
                    '账号已批量关闭数据邮件推送'
                );
            }
        }

        return $this->success([
            'requested' => count($ids),
            'updated' => $updated,
            'fields' => $changed,
            'cancelledPending' => $cancelledPending,
        ], '账号已批量更新');
    }

    /**
     * 维护：清空全部账号的关联用户/企业/分组，并删除所有账号分组（仅登录超管）。
     * body: { confirm: 'CLEAR_ALL' }
     */
    public function maintenanceClearAllMeta(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        if (!DataScope::isLoginSuper()) {
            return $this->error('仅超级管理员（登录账号）可执行此操作', 403);
        }
        if ((string)$request->input('confirm', '') !== 'CLEAR_ALL') {
            return $this->error('请传入 confirm=CLEAR_ALL 以确认', 422);
        }

        try {
            $stmt = DB::query('UPDATE accounts SET owner_id=NULL, company_id=NULL, group_id=NULL, updated_at=CURRENT_TIMESTAMP');
            $accountUpdated = $stmt->rowCount();
        } catch (\Throwable $e) {
            return $this->error('清空账号关联失败: ' . $e->getMessage(), 500);
        }

        $groupsDeleted = 0;
        try {
            $hasParent = false;
            try {
                $cols = array_column(DB::select('SHOW COLUMNS FROM `groups`'), 'Field');
                $hasParent = in_array('parent_id', $cols, true);
            } catch (\Throwable $e) {
            }
            if ($hasParent) {
                $stmtChild = DB::query("DELETE FROM `groups` WHERE type='account' AND parent_id IS NOT NULL AND parent_id > 0");
                $groupsDeleted += $stmtChild->rowCount();
            }
            $stmtParent = DB::query("DELETE FROM `groups` WHERE type='account'");
            $groupsDeleted += $stmtParent->rowCount();
        } catch (\Throwable $e) {
            return $this->error('删除账号分组失败: ' . $e->getMessage(), 500);
        }

        return $this->success([
            'accountsUpdated' => $accountUpdated,
            'groupsDeleted' => $groupsDeleted,
        ], '已清空账号关联与账号分组');
    }

    /**
     * 预检：将指定账号绑定到某代理后，是否超过「每代理最多 3 个账号」上限。
     * body: { proxyId, accountIds }
     */
    public function proxyBindCheck(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $proxyId = (int)$request->input('proxyId', $request->input('proxy_id', 0));
        $ids = $this->idList($request->input('accountIds', []));
        if ($proxyId < 1) {
            return $this->error('请选择代理', 422);
        }
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }
        $p = DB::selectOne('SELECT id, name FROM proxies WHERE id=?', [$proxyId]);
        if (!$p) {
            return $this->error('代理不存在', 404);
        }
        $check = $this->evaluateProxyBindLimit($proxyId, $ids);
        $check['proxyId'] = $proxyId;
        $check['proxyName'] = (string)($p['name'] ?? '');
        return $this->success($check);
    }

    /** 批量校验多个代理的剩余绑定额度是否足够分配所选账号 */
    public function proxyBindCheckBatch(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('accountIds', []));
        $proxyIds = $this->idList($request->input('proxyIds', []));
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }
        if (!$proxyIds) {
            return $this->error('请选择代理', 422);
        }
        foreach ($proxyIds as $pid) {
            $p = DB::selectOne('SELECT id, name FROM proxies WHERE id=?', [$pid]);
            if (!$p) {
                return $this->error('代理不存在', 404);
            }
        }
        $check = $this->evaluateMultiProxyBindLimit($proxyIds, $ids);
        return $this->success($check);
    }

    /**
     * @param list<int> $accountIds 即将绑定到该代理的账号 ID
     * @return array{allowed:bool,limit:int,current:int,already:int,selected:int,after:int,message:string}
     */
    private function evaluateProxyBindLimit(int $proxyId, array $accountIds): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        $limit = self::PROXY_BIND_LIMIT;
        $current = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM accounts WHERE proxy_id=?', [$proxyId])['c'] ?? 0);
        $already = 0;
        if ($accountIds) {
            $ph = implode(',', array_fill(0, count($accountIds), '?'));
            $already = (int)(DB::selectOne(
                "SELECT COUNT(*) AS c FROM accounts WHERE proxy_id=? AND id IN ({$ph})",
                array_merge([$proxyId], $accountIds)
            )['c'] ?? 0);
        }
        $selected = count($accountIds);
        $after = $current - $already + $selected;
        $allowed = $after <= $limit;
        $message = '';
        if (!$allowed) {
            $message = "该代理当前已绑定 {$current} 个账号（本次勾选中已占用 {$already} 个）。"
                . "每个代理最多绑定 {$limit} 个账号；若将本次勾选的 {$selected} 个账号全部绑定，将达到 {$after} 个，超出限制。"
                . '请减少勾选账号或更换代理。';
        }
        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'current' => $current,
            'already' => $already,
            'selected' => $selected,
            'after' => $after,
            'message' => $message,
        ];
    }

    /**
     * @param list<int> $proxyIds
     * @param list<int> $accountIds
     * @return array{allowed:bool,limit:int,needed:int,available:int,proxies:list<array<string,mixed>>,message:string}
     */
    private function evaluateMultiProxyBindLimit(array $proxyIds, array $accountIds): array
    {
        $proxyIds = array_values(array_unique(array_filter(array_map('intval', $proxyIds))));
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        $limit = self::PROXY_BIND_LIMIT;
        $needed = count($accountIds);
        $capacity = $this->proxyCapacityForBatch($proxyIds, $accountIds);
        $available = array_sum($capacity);
        $proxies = [];
        foreach ($proxyIds as $pid) {
            $row = DB::selectOne('SELECT id, name, country FROM proxies WHERE id=?', [$pid]) ?: [];
            $external = (int)(DB::selectOne(
                'SELECT COUNT(*) AS c FROM accounts WHERE proxy_id=?' . ($accountIds ? ' AND id NOT IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')' : ''),
                $accountIds ? array_merge([$pid], $accountIds) : [$pid]
            )['c'] ?? 0);
            $proxies[] = [
                'proxyId' => $pid,
                'proxyName' => (string)($row['name'] ?? ''),
                'country' => (string)($row['country'] ?? ''),
                'current' => $external,
                'remaining' => (int)($capacity[$pid] ?? 0),
                'limit' => $limit,
            ];
        }
        $allowed = $available >= $needed;
        $message = '';
        if (!$allowed) {
            $message = "所选 {$needed} 个账号需要绑定代理，但已选 " . count($proxyIds) . " 个代理的剩余额度合计仅 {$available} 个（每个代理最多 {$limit} 个账号）。请增加代理或减少账号。";
        }
        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'needed' => $needed,
            'available' => $available,
            'proxies' => $proxies,
            'message' => $message,
        ];
    }

    /**
     * @param list<int> $proxyIds
     * @param list<int> $accountIds
     * @return array<int,int> proxyId => remaining slots
     */
    private function proxyCapacityForBatch(array $proxyIds, array $accountIds): array
    {
        $limit = self::PROXY_BIND_LIMIT;
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        $out = [];
        foreach ($proxyIds as $pid) {
            $pid = (int)$pid;
            if ($pid < 1) {
                continue;
            }
            if ($accountIds) {
                $ph = implode(',', array_fill(0, count($accountIds), '?'));
                $external = (int)(DB::selectOne(
                    "SELECT COUNT(*) AS c FROM accounts WHERE proxy_id=? AND id NOT IN ({$ph})",
                    array_merge([$pid], $accountIds)
                )['c'] ?? 0);
            } else {
                $external = (int)(DB::selectOne('SELECT COUNT(*) AS c FROM accounts WHERE proxy_id=?', [$pid])['c'] ?? 0);
            }
            $out[$pid] = max(0, $limit - $external);
        }
        return $out;
    }

    /**
     * @param list<int> $accountIds
     * @param list<int> $proxyIds
     */
    private function assignProxiesRoundRobin(array $accountIds, array $proxyIds): void
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        $proxyIds = array_values(array_unique(array_filter(array_map('intval', $proxyIds))));
        if (!$accountIds || !$proxyIds) {
            return;
        }
        $capacity = $this->proxyCapacityForBatch($proxyIds, $accountIds);
        $check = $this->evaluateMultiProxyBindLimit($proxyIds, $accountIds);
        if (empty($check['allowed'])) {
            throw new \InvalidArgumentException((string)($check['message'] ?? '代理剩余额度不足'));
        }
        $idx = 0;
        $order = $proxyIds;
        $used = [];
        foreach ($accountIds as $aid) {
            $assigned = false;
            for ($try = 0; $try < count($order); $try++) {
                $pid = (int)$order[($idx + $try) % count($order)];
                if (($capacity[$pid] ?? 0) <= 0) {
                    continue;
                }
                DB::query('UPDATE accounts SET proxy_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$pid, $aid]);
                $capacity[$pid]--;
                $used[$pid] = ($used[$pid] ?? 0) + 1;
                $idx = ($idx + $try + 1) % count($order);
                $assigned = true;
                break;
            }
            if (!$assigned) {
                throw new \InvalidArgumentException('代理剩余额度不足，无法完成分配');
            }
        }
        foreach ($used as $pid => $cnt) {
            DB::query('UPDATE proxies SET use_count=use_count+?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$cnt, (int)$pid]);
        }
    }

    /** 将账号归属分配给指定管理用户（超管任意；有子级者仅可分给本人或直接子级）；clear=true 或 ownerId=__clear__ 时清除关联 */
    public function batchAssignOwner(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('accountIds', []));
        $rawOwner = $request->input('ownerId', $request->input('userId', $request->input('owner_id')));
        $clear = (bool)$request->input('clear', false) || $rawOwner === '__clear__';
        $companyId = $request->input('companyId', $request->input('company_id'));
        $companyId = ($companyId === null || $companyId === '') ? null : (int)$companyId;
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->accountScopeSql('owner_id');
        $existing = array_map('intval', array_column(
            DB::select("SELECT id FROM accounts WHERE id IN ({$ph}){$ownSql}", array_merge($ids, $ownParams)),
            'id'
        ));
        if (!$existing) {
            return $this->error('没有可分配的账号（可能不在当前数据范围内）', 404);
        }

        $ph2 = implode(',', array_fill(0, count($existing), '?'));

        if ($clear) {
            if (!DataScope::canAssignAccountOwners()) {
                return $this->error('当前角色无权清除账号关联人员', 403);
            }
            $stmt = DB::query(
                "UPDATE accounts SET owner_id=NULL, company_id=NULL, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph2})",
                $existing
            );
            return $this->success([
                'requested' => count($ids),
                'updated' => $stmt->rowCount(),
                'cleared' => true,
                'ownerId' => null,
                'companyId' => null,
            ], '已清除账号关联人员');
        }

        $targetOwnerId = (int)$rawOwner;
        [$resolvedOwner, $resolvedCompany, $assignErr] = $this->resolveOwnerAssignment($targetOwnerId, $companyId);
        if ($assignErr) {
            return $assignErr;
        }

        $target = DB::table('admin_users')->where('id', $resolvedOwner)->first();

        $stmt = DB::query(
            "UPDATE accounts SET owner_id=?, company_id=?, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph2})",
            array_merge([$resolvedOwner, $resolvedCompany], $existing)
        );

        return $this->success([
            'requested' => count($ids),
            'updated' => $stmt->rowCount(),
            'ownerId' => $resolvedOwner,
            'companyId' => $resolvedCompany,
            'ownerName' => $target['nickname'] ?: $target['username'],
        ], '账号已分配给 ' . ($target['nickname'] ?: $target['username']));
    }

    public function batchAssignProxy(Request $request): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('accountIds', []));
        $groupId = (int)$request->input('groupId', 0);
        [$ownSql, $ownParams] = $this->accountScopeSql('owner_id');
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $accounts = DB::select("SELECT id FROM accounts WHERE id IN ({$ph}){$ownSql} ORDER BY id", array_merge($ids, $ownParams));
        } elseif ($groupId) {
            $accounts = DB::select("SELECT id FROM accounts WHERE group_id=? AND enabled=1{$ownSql} ORDER BY id", array_merge([$groupId], $ownParams));
        } else {
            return $this->error('请选择账号或账号分组', 400);
        }
        if (!$accounts) {
            return $this->error('没有可分配的账号（可能不在当前数据范围内）', 404);
        }
        $proxyId = (int)$request->input('proxyId', 0);
        $proxyGroupId = (int)$request->input('proxyGroupId', 0);
        if ($proxyId) {
            $one = DB::selectOne('SELECT id FROM proxies WHERE id=?', [$proxyId]);
            if (!$one) {
                return $this->error('代理不存在', 404);
            }
            $accountIds = array_map(static fn($a) => (int)$a['id'], $accounts);
            $check = $this->evaluateProxyBindLimit($proxyId, $accountIds);
            if (empty($check['allowed'])) {
                return $this->error((string)($check['message'] ?? '该代理绑定账号数将超过上限'), 422);
            }
            $proxies = [$one];
        } else {
            $sql = "SELECT id FROM proxies WHERE status<>'unavailable'";
            $params = [];
            if ($proxyGroupId) {
                $sql .= ' AND group_id=?';
                $params[] = $proxyGroupId;
            }
            $sql .= ' ORDER BY use_count ASC, id ASC';
            $proxies = DB::select($sql, $params);
            if (!$proxies) {
                return $this->error('没有可用代理', 404);
            }
        }
        $assigned = 0;
        foreach ($accounts as $i => $account) {
            $proxy = $proxies[$i % count($proxies)];
            DB::query('UPDATE accounts SET proxy_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$proxy['id'], (int)$account['id']]);
            DB::query('UPDATE proxies SET use_count=use_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$proxy['id']]);
            $assigned++;
        }
        return $this->success(['assigned' => $assigned, 'proxies' => count($proxies)], "已为 {$assigned} 个账号分配代理");
    }

    public function batchSetProxyString(Request $request): Response
    {
        if ($err = $this->assertCanBatchModifyAccounts()) {
            return $err;
        }
        $this->ensureSchema();
        DataScope::ensureColumns();
        $ids = $this->idList($request->input('accountIds', []));
        if (!$ids) {
            return $this->error('请选择账号', 400);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        [$ownSql, $ownParams] = $this->accountScopeSql('owner_id');
        $accounts = DB::select("SELECT id FROM accounts WHERE id IN ({$ph}){$ownSql}", array_merge($ids, $ownParams));
        if (!$accounts) {
            return $this->error('没有可更新的账号（可能不在当前数据范围内）', 404);
        }
        $raw = trim((string)$request->input('proxyString', ''));
        $clear = filter_var($request->input('clear', false), FILTER_VALIDATE_BOOLEAN);
        if ($clear || $raw === '-' || strtolower($raw) === 'none' || $raw === '') {
            if (!$clear && $raw === '') {
                return $this->error('请填写代理串，或使用「-」清空', 400);
            }
            $stmt = DB::query("UPDATE accounts SET proxy_id=NULL, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph})", array_column($accounts, 'id'));
            return $this->success(['updated' => $stmt->rowCount(), 'cleared' => true], '已清空 ' . $stmt->rowCount() . ' 个账号的代理');
        }
        $parsed = ProxyController::parseLine($raw, 'socks5');
        $existing = DB::selectOne(
            'SELECT id FROM proxies WHERE protocol=? AND host=? AND port=? AND username=? LIMIT 1',
            [$parsed['protocol'], $parsed['host'], $parsed['port'], $parsed['username']]
        );
        if ($existing) {
            $pid = (int)$existing['id'];
            if ($parsed['password'] !== '') {
                DB::query(
                    "UPDATE proxies SET password='', password_encrypted=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                    [SecretStore::encrypt($parsed['password']), $pid]
                );
            }
        } else {
            DB::query(
                "INSERT INTO proxies(name,protocol,host,port,username,password,password_encrypted,country) VALUES (?,?,?,?,?,'',?,?)",
                [
                    substr($parsed['host'] . ':' . $parsed['port'], 0, 100),
                    $parsed['protocol'], $parsed['host'], $parsed['port'], $parsed['username'],
                    SecretStore::encrypt($parsed['password']), '',
                ]
            );
            $pid = (int)DB::getPdo()->lastInsertId();
        }
        $accountIds = array_map(static fn($a) => (int)$a['id'], $accounts);
        $check = $this->evaluateProxyBindLimit($pid, $accountIds);
        if (empty($check['allowed'])) {
            return $this->error((string)($check['message'] ?? '该代理绑定账号数将超过上限'), 422);
        }
        $stmt = DB::query("UPDATE accounts SET proxy_id=?, updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ph})", array_merge([$pid], array_column($accounts, 'id')));
        DB::query('UPDATE proxies SET use_count=use_count+?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$stmt->rowCount(), $pid]);
        return $this->success(['updated' => $stmt->rowCount(), 'proxyId' => $pid, 'cleared' => false], '已绑定代理');
    }

    public function monitor(Request $request): Response
    {
        $this->ensureSchema();
        $cfg = DB::selectOne('SELECT * FROM account_monitor_config WHERE id=1');
        $ids = json_decode((string)($cfg['account_ids_json'] ?? '[]'), true);
        $result = json_decode((string)($cfg['last_result_json'] ?? '{}'), true);
        return $this->success([
            'enabled' => !empty($cfg['enabled']),
            'intervalMinutes' => (int)($cfg['interval_minutes'] ?? 60),
            'syncProfile' => !isset($cfg['sync_profile']) || (int)$cfg['sync_profile'] !== 0,
            'syncVideos' => !empty($cfg['sync_videos']),
            'accountIds' => is_array($ids) ? $ids : [],
            'lastRunAt' => $cfg['last_run_at'] ?? null,
            'lastResult' => is_array($result) ? $result : [],
        ]);
    }

    public function saveMonitor(Request $request): Response
    {
        $this->ensureSchema();
        $enabled = filter_var($request->input('enabled', false), FILTER_VALIDATE_BOOLEAN);
        $interval = (int)$request->input('intervalMinutes', 60);
        if ($interval < 5 || $interval > 1440) {
            return $this->error('间隔需在 5 到 1440 分钟之间', 422);
        }
        $syncProfile = filter_var($request->input('syncProfile', true), FILTER_VALIDATE_BOOLEAN);
        $syncVideos = filter_var($request->input('syncVideos', false), FILTER_VALIDATE_BOOLEAN);
        $accountIds = $request->input('accountIds', []);
        if (!is_array($accountIds)) {
            $accountIds = [];
        }
        $accountIds = array_values(array_filter(array_map('intval', $accountIds), fn($id) => $id > 0));
        if (count($accountIds) > 500) {
            return $this->error('一次最多选择 500 个账号', 422);
        }
        DB::query(
            'UPDATE account_monitor_config SET enabled=?, interval_minutes=?, sync_profile=?, sync_videos=?, account_ids_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=1',
            [$enabled ? 1 : 0, $interval, $syncProfile ? 1 : 0, $syncVideos ? 1 : 0, json_encode($accountIds)]
        );
        return $this->success([
            'enabled' => $enabled,
            'intervalMinutes' => $interval,
            'syncProfile' => $syncProfile,
            'syncVideos' => $syncVideos,
            'accountIds' => $accountIds,
        ], '监控配置已保存');
    }

    public function tiktokProfile(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM tiktok_profiles WHERE account_id=?', [(int)$id]);
        return $this->success($row);
    }

    public function tiktokVideos(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $rows = DB::select('SELECT * FROM tiktok_videos WHERE account_id=? ORDER BY last_synced_at DESC, id DESC', [(int)$id]);
        return $this->success($rows);
    }

    /**
     * 查看数据弹框：AI 分析当前账号已拉取指标并发送邮件通知。
     */
    public function sendDataAnalysisMail(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        if (DataScope::isEnterpriseCustomer()) {
            return $this->error('企业客户无权发送邮件通知', 403);
        }
        $aid = (int)$id;
        $account = DB::selectOne('SELECT id, owner_id, company_id FROM accounts WHERE id=?', [$aid]);
        if ($err = $this->assertAccountRowAccess($account)) {
            return $err;
        }
        try {
            @set_time_limit(180);
            $result = PullDataAnalysisService::runManualForAccount($aid);
        } catch (\Throwable $e) {
            return $this->error('发送失败：' . $e->getMessage(), 500);
        }
        if (empty($result['ok'])) {
            return $this->error((string)($result['error'] ?? '发送失败'), 422, $result);
        }
        $emails = $result['emails'] ?? [];
        $mailCount = is_array($result['mails'] ?? null) ? count($result['mails']) : count($emails);
        $msg = 'AI 分析完成，已向 ' . $mailCount . ' 个邮箱发送通知'
            . ($emails ? ('（' . implode('、', $emails) . '）') : '');
        return $this->success($result, $msg);
    }

    /**
     * 查看数据弹框：开启/关闭「拉取数据后自动邮件推送」计划任务开关。
     */
    public function patchDataMailPush(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        if (DataScope::isEnterpriseCustomer()) {
            return $this->error('企业客户无权操作邮件推送', 403);
        }
        $aid = (int)$id;
        $account = DB::selectOne('SELECT id, owner_id, company_id, data_mail_push FROM accounts WHERE id=?', [$aid]);
        if ($err = $this->assertAccountRowAccess($account)) {
            return $err;
        }
        PullDataAnalysisService::ensureAccountDataMailPushColumn();
        $enabledRaw = $request->input('enabled', $request->input('dataMailPush', $request->input('data_mail_push')));
        if ($enabledRaw === null) {
            return $this->error('请传入 enabled', 422);
        }
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            $enabled = in_array(strtolower((string)$enabledRaw), ['1', 'true', 'on', 'yes'], true);
        }
        $val = $enabled ? 1 : 0;
        DB::query(
            'UPDATE accounts SET data_mail_push=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$val, $aid]
        );
        $cancelled = 0;
        if ($val === 0) {
            $cancelled = PullDataAnalysisService::cancelPendingForAccount($aid, '账号已关闭数据邮件推送');
        }
        return $this->success([
            'accountId' => $aid,
            'dataMailPush' => $val,
            'data_mail_push' => $val,
            'cancelledPending' => $cancelled,
        ], $val ? '已开启数据邮件推送' : '已关闭数据邮件推送');
    }

    public function tiktokStats(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $aid = (int)$id;
        $snapshots = DB::select('SELECT * FROM tiktok_stat_snapshots WHERE account_id=? ORDER BY captured_at DESC LIMIT 30', [$aid]);
        $daily = [];
        $tabs = [];
        $metrics = [];
        $content = [];
        $sync = null;
        try {
            $daily = DB::select(
                'SELECT * FROM tiktok_analytics_28d WHERE account_id=? ORDER BY metric_date ASC',
                [$aid]
            ) ?: [];
        } catch (\Throwable $e) {
            $daily = [];
        }
        try {
            $tabs = DB::select(
                'SELECT tab_key, tab_label, page_url, metric_count, captured_at FROM tiktok_studio_tabs WHERE account_id=? ORDER BY id ASC',
                [$aid]
            ) ?: [];
        } catch (\Throwable $e) {
            $tabs = [];
        }
        try {
            $metrics = DB::select(
                'SELECT tab_key, metric_key, metric_label, value_num, value_text FROM tiktok_studio_metrics WHERE account_id=? ORDER BY tab_key ASC, id ASC',
                [$aid]
            ) ?: [];
        } catch (\Throwable $e) {
            $metrics = [];
        }
        try {
            $content = DB::select(
                'SELECT row_no, title, views_num, likes_num, comments_num, shares_num, posted_at,
                        video_id, video_url, cover_url, material_id
                 FROM tiktok_studio_content WHERE account_id=? ORDER BY row_no ASC',
                [$aid]
            ) ?: [];
        } catch (\Throwable $e) {
            try {
                $content = DB::select(
                    'SELECT row_no, title, views_num, likes_num, comments_num, shares_num, posted_at FROM tiktok_studio_content WHERE account_id=? ORDER BY row_no ASC',
                    [$aid]
                ) ?: [];
            } catch (\Throwable $e2) {
                $content = [];
            }
        }
        try {
            $sync = DB::selectOne('SELECT page_url, tab_count, api_count, status, message, meta_json, captured_at FROM tiktok_studio_sync WHERE account_id=?', [$aid]);
        } catch (\Throwable $e) {
            $sync = null;
        }
        // 按页签分组，前端直接渲染
        $byTab = [];
        foreach ($metrics as $m) {
            $key = (string)($m['tab_key'] ?? '');
            if (!isset($byTab[$key])) {
                $byTab[$key] = [];
            }
            $byTab[$key][] = [
                'key' => (string)($m['metric_key'] ?? ''),
                'label' => (string)($m['metric_label'] ?? ''),
                'value' => (int)($m['value_num'] ?? 0),
                'text' => (string)($m['value_text'] ?? ''),
            ];
        }
        $dataMailPush = 0;
        try {
            PullDataAnalysisService::ensureAccountDataMailPushColumn();
            $pushRow = DB::selectOne('SELECT data_mail_push FROM accounts WHERE id=? LIMIT 1', [$aid]);
            $dataMailPush = (int)($pushRow['data_mail_push'] ?? 0) === 1 ? 1 : 0;
        } catch (\Throwable $e) {
            $dataMailPush = 0;
        }

        return $this->success([
            'snapshots' => $snapshots,
            'daily28' => $daily,
            'studioTabs' => $tabs,
            'studioMetrics' => $byTab,
            'studioContent' => $content,
            'studioSync' => $sync,
            'dataMailPush' => $dataMailPush,
            'data_mail_push' => $dataMailPush,
        ]);
    }

    /** 入队账号诊断（TikTok Studio → Account Check → 截图） */
    public function triggerDiagnose(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id, browser_profile_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        if (!$account || empty($account['browser_profile_id'])) {
            return $this->error('账号尚未绑定浏览器环境', 422);
        }
        try {
            $out = \App\Services\AccountWorkBatchService::enqueueItem(
                (int)$id,
                'diagnose',
                [
                    'source' => 'manual',
                    'accountId' => (int)$id,
                ],
                0,
                $this->writeOwnerId()
            );
            return $this->success($out, '诊断任务已创建，将打开 TikTok Studio 账号检测并截图');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    /** 最近诊断截图记录（最多 3 条） */
    public function listDiagnosisRecords(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $rows = DB::select(
            'SELECT id, account_id, screenshot_path, label, source, created_at
             FROM account_diagnosis_records
             WHERE account_id=?
             ORDER BY created_at DESC, id DESC
             LIMIT 3',
            [(int)$id]
        ) ?: [];
        $items = array_map(static function (array $row) {
            $name = basename((string)($row['screenshot_path'] ?? ''));
            return [
                'id' => (int)$row['id'],
                'accountId' => (int)$row['account_id'],
                'screenshotPath' => $name,
                'label' => (string)($row['label'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'createdAt' => (string)($row['created_at'] ?? ''),
                'download' => $name !== '' ? '/api/browser/diagnostics/' . rawurlencode($name) : '',
            ];
        }, $rows);
        return $this->success(['items' => $items, 'list' => $items]);
    }

    /** 修改资料执行记录（用户名/昵称/签名/头像 各自结果与原因） */
    public function profileChangeLogs(Request $request, string $id): Response
    {
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $limit = (int)$request->input('limit', 50);
        $items = AccountWorkBatchService::listProfileChangeLogs((int)$id, $limit);
        return $this->success(['items' => $items, 'list' => $items]);
    }

    /** 写入一条诊断截图（Node / UI），并裁剪为每账号最多 3 条 */
    public function saveDiagnosisRecord(Request $request, string $id): Response
    {
        $this->ensureSchema();
        DataScope::ensureColumns();
        $account = DB::selectOne('SELECT id, owner_id FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($account)) {
            return $err;
        }
        $path = trim((string)$request->input(
            'screenshotPath',
            $request->input('screenshot_path', $request->input('name', ''))
        ));
        $path = basename($path);
        if ($path === '' || !preg_match('/^\d+[-a-zA-Z0-9_.]+\.png$/', $path)) {
            return $this->error('截图文件名无效', 422);
        }
        $label = trim((string)$request->input('label', 'account-check')) ?: 'account-check';
        $source = trim((string)$request->input('source', 'manual')) ?: 'manual';
        if (strlen($label) > 64) {
            $label = substr($label, 0, 64);
        }
        if (strlen($source) > 32) {
            $source = substr($source, 0, 32);
        }

        DB::query(
            'INSERT INTO account_diagnosis_records(account_id, screenshot_path, label, source)
             VALUES (?,?,?,?)',
            [(int)$id, $path, $label, $source]
        );
        $newId = (int)DB::getPdo()->lastInsertId();

        $keep = DB::select(
            'SELECT id FROM account_diagnosis_records WHERE account_id=? ORDER BY created_at DESC, id DESC LIMIT 3',
            [(int)$id]
        ) ?: [];
        $keepIds = array_map(static fn($r) => (int)$r['id'], $keep);
        $deletedPaths = [];
        if ($keepIds) {
            $ph = implode(',', array_fill(0, count($keepIds), '?'));
            $old = DB::select(
                "SELECT id, screenshot_path FROM account_diagnosis_records
                 WHERE account_id=? AND id NOT IN ({$ph})",
                array_merge([(int)$id], $keepIds)
            ) ?: [];
            foreach ($old as $row) {
                $deletedPaths[] = basename((string)($row['screenshot_path'] ?? ''));
            }
            DB::query(
                "DELETE FROM account_diagnosis_records WHERE account_id=? AND id NOT IN ({$ph})",
                array_merge([(int)$id], $keepIds)
            );
        }

        return $this->success([
            'id' => $newId,
            'accountId' => (int)$id,
            'screenshotPath' => $path,
            'label' => $label,
            'source' => $source,
            'download' => '/api/browser/diagnostics/' . rawurlencode($path),
            'deletedPaths' => array_values(array_filter($deletedPaths)),
        ], '诊断截图已保存');
    }

    public function getSession(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $account = DB::selectOne('SELECT id, username, fingerprint FROM accounts WHERE id=?', [(int)$id]);
        if (!$account) {
            return $this->error('账号不存在', 404);
        }
        $session = DB::selectOne(
            'SELECT cookie_encrypted, storage_encrypted, fingerprint, updated_at FROM account_sessions WHERE account_id=?',
            [(int)$id]
        );
        if (empty($session['cookie_encrypted'])) {
            return $this->error('该账号没有已保存的会话', 404);
        }
        $cookie = SecretStore::decrypt((string)$session['cookie_encrypted']);
        if ($cookie === '') {
            return $this->error('会话 Cookie 解密失败', 422);
        }
        $storageRaw = trim((string)($session['storage_encrypted'] ?? ''));
        $storage = '';
        if ($storageRaw !== '') {
            $storage = SecretStore::decrypt($storageRaw);
        }
        return $this->success([
            'accountId' => (int)$account['id'],
            'username' => $account['username'],
            'cookie' => $cookie,
            'storage' => $storage,
            'hasStorage' => $storage !== '',
            'fingerprint' => $session['fingerprint'] ?: ($account['fingerprint'] ?? ''),
            'updatedAt' => $session['updated_at'] ?? null,
        ]);
    }

    public function saveSession(Request $request, string $id): Response
    {
        $this->ensureSchema();
        if ($err = $this->assertCanEditAccounts()) {
            return $err;
        }
        $account = DB::selectOne('SELECT id FROM accounts WHERE id=?', [(int)$id]);
        if (!$account) {
            return $this->error('账号不存在', 404);
        }
        $cookie = trim((string)$request->input('cookie', ''));
        $fingerprint = trim((string)$request->input('fingerprint', ''));
        $storage = trim((string)$request->input('storage', $request->input('localStorage', '')));
        if ($cookie === '' || strlen($cookie) > 500000) {
            return $this->error('Cookie 不能为空', 422);
        }
        if (!preg_match('/sessionid|sid_tt/i', $cookie)) {
            return $this->error('Cookie 中未找到 sessionid', 422);
        }
        if (strlen($storage) > 2000000) {
            return $this->error('LocalStorage 过大', 422);
        }
        // 每 TikTok 账号仅保留一条最新会话（主键 account_id）
        DB::query(
            'INSERT INTO account_sessions(account_id, cookie_encrypted, storage_encrypted, fingerprint, updated_at)
             VALUES (?,?,?,?,CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
               cookie_encrypted=VALUES(cookie_encrypted),
               storage_encrypted=VALUES(storage_encrypted),
               fingerprint=VALUES(fingerprint),
               updated_at=CURRENT_TIMESTAMP',
            [
                (int)$id,
                SecretStore::encrypt($cookie),
                $storage !== '' ? SecretStore::encrypt($storage) : null,
                mb_substr($fingerprint, 0, 2000),
            ]
        );
        DB::query(
            "UPDATE accounts SET has_session=1, fingerprint=IF(?='', fingerprint, ?), login_status='online', updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$fingerprint, mb_substr($fingerprint, 0, 255), (int)$id]
        );
        return $this->success(['accountId' => (int)$id, 'hasSession' => true, 'hasStorage' => $storage !== ''], '登录会话已加密保存（仅保留最新一条）');
    }

    /** 退出登录：删除该 TikTok 唯一会话日志 */
    public function clearSession(Request $request, string $id): Response
    {
        $this->ensureSchema();
        if ($err = $this->assertCanEditAccounts()) {
            return $err;
        }
        $account = DB::selectOne('SELECT id FROM accounts WHERE id=?', [(int)$id]);
        if (!$account) {
            return $this->error('账号不存在', 404);
        }
        DB::query('DELETE FROM account_sessions WHERE account_id=?', [(int)$id]);
        DB::query(
            "UPDATE accounts SET has_session=0, login_status='offline', updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [(int)$id]
        );
        return $this->success(['accountId' => (int)$id, 'hasSession' => false], '会话日志已删除');
    }

    public function capcutAuthorize(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $account = DB::selectOne('SELECT id, browser_profile_id, login_status FROM accounts WHERE id=?', [(int)$id]);
        if (!$account) {
            return $this->error('账号不存在', 404);
        }
        if (empty($account['browser_profile_id'])) {
            return $this->error('请先绑定浏览器环境', 422);
        }
        if (($account['login_status'] ?? '') !== 'online') {
            return $this->error('请先完成 TikTok 登录', 422);
        }
        DB::query('UPDATE accounts SET capcut_authorized=1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
        return $this->success(['accountId' => (int)$id, 'capcutAuthorized' => true], '已标记 CapCut 授权（需在浏览器中完成实际授权流程）');
    }

    public function capcutRevoke(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $exists = DB::selectOne('SELECT id FROM accounts WHERE id=?', [(int)$id]);
        if (!$exists) {
            return $this->error('账号不存在', 404);
        }
        DB::query('UPDATE accounts SET capcut_authorized=0, updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$id]);
        return $this->success(null, '已取消 CapCut 授权标记');
    }

    public function batchCalibrateCountry(Request $request): Response
    {
        $this->ensureSchema();
        $ids = $request->input('accountIds', []);
        if (!is_array($ids) || !$ids) {
            return $this->error('请选择账号', 422);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (count($ids) > 500) {
            return $this->error('一次最多校准 500 个账号', 422);
        }
        $updated = 0;
        foreach ($ids as $id) {
            $row = DB::selectOne('SELECT a.id, p.country FROM accounts a LEFT JOIN proxies p ON p.id=a.proxy_id WHERE a.id=?', [$id]);
            if (!empty($row['country'])) {
                DB::query('UPDATE accounts SET country=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$row['country'], $id]);
                $updated++;
            }
        }
        return $this->success(['requested' => count($ids), 'updated' => $updated], "已校准 {$updated} 个账号国家");
    }

    public function bindSocks(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $account = DB::selectOne('SELECT id, username FROM accounts WHERE id=?', [(int)$id]);
        if (!$account) {
            return $this->error('账号不存在', 404);
        }
        $host = trim((string)$request->input('host', ''));
        $port = (int)$request->input('port', 0);
        $username = trim((string)$request->input('username', ''));
        $password = (string)$request->input('password', '');
        $country = trim((string)$request->input('country', ''));
        if ($host === '' || $port < 1 || $port > 65535) {
            return $this->error('请填写有效的主机和端口', 422);
        }
        $name = mb_substr('SOCKS-' . $account['username'], 0, 80);
        $existing = DB::selectOne(
            'SELECT id FROM proxies WHERE protocol=? AND host=? AND port=? AND username=?',
            ['socks5', $host, $port, $username]
        );
        if ($existing) {
            $proxyId = (int)$existing['id'];
        } else {
            DB::query(
                "INSERT INTO proxies(name,protocol,host,port,username,password,password_encrypted,country) VALUES (?,?,?,?,?,'',?,?)",
                [$name, 'socks5', $host, $port, $username, SecretStore::encrypt($password), $country]
            );
            $proxyId = (int)DB::getPdo()->lastInsertId();
        }
        DB::query('UPDATE accounts SET proxy_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$proxyId, (int)$id]);
        return $this->success(['proxyId' => $proxyId, 'accountId' => (int)$id], 'SOCKS5 已绑定到该账号');
    }

    private function filters(Request $request): array
    {
        $filters = [];
        $params = [];
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') {
            $filters[] = '(a.username LIKE ? OR a.nickname LIKE ? OR CAST(a.id AS CHAR) LIKE ?)';
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }
        $groupId = $request->get('groupId');
        if ($groupId !== null && $groupId !== '') {
            if ($groupId === 'none' || $groupId === '0') {
                $filters[] = 'a.group_id IS NULL';
            } else {
                $gids = GroupController::expandAccountGroupIds((int)$groupId);
                if (count($gids) === 1) {
                    $filters[] = 'a.group_id=?';
                    $params[] = $gids[0];
                } elseif ($gids) {
                    $ph = implode(',', array_fill(0, count($gids), '?'));
                    $filters[] = "a.group_id IN ({$ph})";
                    array_push($params, ...$gids);
                }
            }
        }
        $status = trim((string)$request->get('status', ''));
        if ($status !== '') {
            $filters[] = 'a.login_status=?';
            $params[] = $status;
        }
        $capcut = (string)$request->get('capcut', '');
        if ($capcut === '1' || $capcut === '0') {
            $filters[] = 'a.capcut_authorized=?';
            $params[] = (int)$capcut;
        }
        if ((string)$request->get('session', '') === '1') {
            $filters[] = '(a.has_session=1 OR s.account_id IS NOT NULL)';
        }
        // 是否绑定浏览器环境（任一后端列或 account_browser_envs）
        $hasEnv = (string)$request->get('hasEnv', $request->get('envBound', ''));
        if ($hasEnv === '1' || $hasEnv === '0') {
            $boundSql = "(
                TRIM(COALESCE(a.browser_profile_id, '')) <> ''
                OR TRIM(COALESCE(a.tkswarm_browser_profile_id, '')) <> ''
                OR TRIM(COALESCE(a.linux_browser_profile_id, '')) <> ''
                OR EXISTS (
                    SELECT 1 FROM account_browser_envs e
                    WHERE e.account_id = a.id AND TRIM(COALESCE(e.browser_profile_id, '')) <> ''
                )
            )";
            $filters[] = $hasEnv === '1' ? $boundSql : ('NOT ' . $boundSql);
        }
        if ((string)$request->get('banned', '') === '1') {
            $filters[] = "a.account_status='banned'";
        }
        DataScope::ensureColumns();
        $ownerFilterId = $this->resolveAccountListOwnerFilterId($request);
        if ($ownerFilterId !== null) {
            $filters[] = 'a.owner_id = ?';
            $params[] = $ownerFilterId;
        } else {
            DataScope::applyAccountListScope('a.owner_id', $filters, $params);
        }
        $this->applyAccountAdvancedFilters($request, $filters, $params, $ownerFilterId !== null);
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        return [$where, $params];
    }

    /**
     * 侧栏/高级筛选的归属用户：超管运营可见范围内可筛；有下级可筛本人或直属下级。
     * 用于只筛列表，不依赖 Acting-As 切换身份。
     */
    private function resolveAccountListOwnerFilterId(Request $request): ?int
    {
        $ownerId = (int)$request->get('ownerId', $request->get('owner_id', 0));
        if ($ownerId <= 0) {
            return null;
        }
        if (DataScope::canUseAccountOwnerFilter() && DataScope::isAccountOwnerVisible($ownerId)) {
            return $ownerId;
        }
        if (!DataScope::isEnterpriseCustomer() && !DataScope::isOperator()) {
            $loginId = (int)(DataScope::loginUser()['id'] ?? 0);
            if ($loginId > 0 && ($ownerId === $loginId || in_array($ownerId, DataScope::childIdsOf($loginId), true))) {
                return $ownerId;
            }
        }
        return null;
    }

    /** 账号系统通知列表（分页，供「查看通知」弹框） */
    public function notifications(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountRowAccess($row)) {
            return $err;
        }
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->get('pageSize', 10)));
        $filter = (string)$request->get('filter', '');
        $out = AccountBanService::listForAccount((int)$id, $page, $pageSize, $filter);
        $out['ban'] = AccountBanService::banStateOf($row);
        $out['accountStatus'] = (string)($row['account_status'] ?? 'normal');
        return $this->success($out);
    }

    /** 单条通知详情 */
    public function notificationDetail(Request $request, string $id, string $noticeId): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountRowAccess($row)) {
            return $err;
        }
        $notice = AccountBanService::find((int)$id, (int)$noticeId);
        if (!$notice) {
            return $this->error('通知不存在', 404);
        }
        return $this->success($notice);
    }

    /** 人工标记封禁 / 解除封禁（ban_source=manual，AI 不会自动覆盖） */
    public function patchBanStatus(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($row)) {
            return $err;
        }
        $status = (string)$request->input('status', '');
        if (!in_array($status, ['normal', 'banned'], true)) {
            return $this->error('status 只能是 normal 或 banned', 422);
        }
        $reason = trim((string)$request->input('reason', ''));
        $untilRaw = trim((string)$request->input('banUntil', $request->input('ban_until', '')));
        $untilSql = null;
        if ($untilRaw !== '') {
            $ts = strtotime($untilRaw);
            if ($ts === false) {
                return $this->error('解封时间格式不正确', 422);
            }
            $untilSql = date('Y-m-d H:i:s', $ts);
        }
        $banned = $status === 'banned';
        DB::query(
            'UPDATE accounts SET account_status=?, ban_reason=?, ban_until=?, ban_source=?,
                ban_checked_at=NOW(), updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $banned ? 'banned' : 'normal',
                $banned ? ($reason !== '' ? $reason : null) : null,
                $banned ? $untilSql : null,
                $banned ? 'manual' : '',
                (int)$id,
            ]
        );
        $fresh = DB::selectOne('SELECT * FROM accounts WHERE id=?', [(int)$id]);
        return $this->success(AccountBanService::banStateOf($fresh), $banned ? '已标记封禁' : '已恢复为正常');
    }

    /** 手动触发一次 AI 违规判定（复用提示词模板 account_ban_assess） */
    public function banAssess(Request $request, string $id): Response
    {
        $this->ensureSchema();
        $row = DB::selectOne('SELECT * FROM accounts WHERE id=?', [(int)$id]);
        if ($err = $this->assertAccountOwns($row)) {
            return $err;
        }
        $out = AccountBanService::assess((int)$id);
        $fresh = DB::selectOne('SELECT * FROM accounts WHERE id=?', [(int)$id]);
        $out['ban'] = AccountBanService::banStateOf($fresh);
        return $this->success($out);
    }

    private function assertAccountRowAccess(?array $row): ?Response
    {
        if (!$row) {
            return $this->error('资源不存在', 404);
        }
        if (!DataScope::isAccountRowVisible($row)) {
            return $this->error('无权访问该资源', 403);
        }
        return null;
    }

    private function assertCanEditAccounts(): ?Response
    {
        if (!DataScope::canEditAccounts()) {
            return $this->error('当前角色无权编辑账号', 403);
        }
        return null;
    }

    private function assertCanCreateAccounts(): ?Response
    {
        if (!DataScope::canCreateAccounts()) {
            return $this->error('当前角色无权创建账号', 403);
        }
        return null;
    }

    private function assertCanImportAccounts(): ?Response
    {
        if (!DataScope::canImportAccounts()) {
            return $this->error('当前角色无权导入账号', 403);
        }
        return null;
    }

    private function assertCanExportAccounts(): ?Response
    {
        if (!DataScope::canExportAccounts()) {
            return $this->error('当前角色无权导出账号', 403);
        }
        return null;
    }

    private function assertCanBatchModifyAccounts(): ?Response
    {
        if (!DataScope::canBatchModifyAccounts()) {
            return $this->error('当前角色无权批量修改账号', 403);
        }
        return null;
    }

    private function applyAccountAdvancedFilters(Request $request, array &$filters, array &$params, bool $skipOwner = false): void
    {
        if (!DataScope::canUseAccountAdvancedFilter()) {
            return;
        }
        if (!$skipOwner) {
            $ownerId = (int)$request->get('ownerId', $request->get('owner_id', 0));
            if ($ownerId > 0 && DataScope::canUseAccountOwnerFilter()) {
                if (!DataScope::isAccountOwnerVisible($ownerId)) {
                    $filters[] = '1=0';
                    return;
                }
                $filters[] = 'a.owner_id = ?';
                $params[] = $ownerId;
            }
        }
        $companyId = (int)$request->get('companyId', $request->get('company_id', 0));
        if ($companyId > 0) {
            $filters[] = 'a.company_id = ?';
            $params[] = $companyId;
        }
        $industryId = (int)$request->get('industryId', $request->get('industry_id', 0));
        if ($industryId > 0 && DataScope::canUseAccountIndustryFilter()) {
            IndustryDataService::ensureSchema();
            $ids = IndustryDataService::descendantIds($industryId);
            if (!$ids) {
                $filters[] = '1=0';
                return;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $filters[] = "EXISTS (
                SELECT 1 FROM companies c
                WHERE c.id = a.company_id AND c.industry_id IN ({$ph})
            )";
            array_push($params, ...$ids);
        }
    }

    private function bodyToRow(Request $request): array
    {
        $login = (string)$request->input('loginStatus', 'offline');
        if (!in_array($login, ['offline', 'online', 'expired', 'checking'], true)) {
            $login = 'offline';
        }
        $chat = (string)$request->input('chatStatus', 'offline');
        if (!in_array($chat, ['offline', 'online', 'error'], true)) {
            $chat = 'offline';
        }
        $groupId = $request->input('groupId', $request->input('group_id'));
        $proxyId = $request->input('proxyId', $request->input('proxy_id'));
        $dmChannel = strtolower(trim((string)$request->input('dmChannel', $request->input('dm_channel', 'auto'))));
        if (!in_array($dmChannel, ['auto', 'api', 'browser'], true)) {
            $dmChannel = 'auto';
        }
        $payload = $request->all();
        $row = [
            'username' => trim((string)$request->input('username', '')),
            'nickname' => trim((string)$request->input('nickname', '')),
            'country' => trim((string)$request->input('country', '')),
            'group_id' => ($groupId === null || $groupId === '') ? null : (int)$groupId,
            'proxy_id' => ($proxyId === null || $proxyId === '') ? null : (int)$proxyId,
            'browser_type' => trim((string)$request->input('browserType', 'bit')) ?: 'bit',
            'browser_profile_id' => trim((string)$request->input('browserProfileId', '')),
            // 执行后端：bit / tkswarm / linux；未传时跟随 browserType，保持旧前端兼容
            'browser_backend' => \App\Services\AccountBrowserEnvService::normalizeBackend(
                (string)$request->input(
                    'browserBackend',
                    $request->input('browser_backend', $request->input('browserType', 'bit'))
                )
            ),
            'login_status' => $login,
            'chat_status' => $chat,
            'dm_channel' => $dmChannel,
            'enabled' => filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'notes' => trim((string)$request->input('notes', '')),
            'has_session' => 0,
        ];
        if (array_key_exists('industryId', $payload) || array_key_exists('industry_id', $payload)) {
            $rawIndustry = $request->input('industryId', $request->input('industry_id'));
            if ($rawIndustry === null || $rawIndustry === '' || $rawIndustry === '__clear__' || $rawIndustry === 0 || $rawIndustry === '0') {
                $row['industry_id'] = null;
            } else {
                $parsed = (int)$rawIndustry;
                $row['industry_id'] = $parsed > 0 ? $parsed : null;
            }
        }
        return $row;
    }

    /**
     * 解析可分配的后台用户与企业。
     * 未传 ownerId 时返回 [null, null, null]（创建走默认归属，更新不改归属）。
     * 传 __clear__ / 空 / 0 时返回 [0, null, null]（清除关联人员）。
     * @return array{0:?int,1:?int,2:?Response}
     */
    private function parseAssignableOwner(Request $request): array
    {
        $all = $request->all();
        if (!array_key_exists('ownerId', $all) && !array_key_exists('owner_id', $all)) {
            return [null, null, null];
        }
        $raw = $request->input('ownerId', $request->input('owner_id'));
        if ($raw === '__clear__' || $raw === '' || $raw === null || (int)$raw === 0) {
            if (!DataScope::canAssignAccountOwners()) {
                return [null, null, $this->error('当前角色无权清除账号关联人员', 403)];
            }
            return [0, null, null];
        }
        $targetOwnerId = (int)$raw;
        $companyRaw = $request->input('companyId', $request->input('company_id'));
        $companyId = ($companyRaw === null || $companyRaw === '') ? null : (int)$companyRaw;
        return $this->resolveOwnerAssignment($targetOwnerId, $companyId);
    }

    /**
     * 解析账号企业绑定（可单独更新，不随 owner 变更）。
     * @return array{0:?int,1:bool,2:?Response} [companyId, hasInput, error]
     */
    /**
     * 解析账号行业绑定（四级联动单独写入，不随企业自动同步）。
     * @return array{0:?int,1:bool,2:?Response} [industryId|null, hasInput, error]
     */
    private function parseIndustryBinding(Request $request): array
    {
        $all = $request->all();
        if (!array_key_exists('industryId', $all) && !array_key_exists('industry_id', $all)) {
            return [null, false, null];
        }
        $raw = $request->input('industryId', $request->input('industry_id'));
        if ($raw === null || $raw === '' || $raw === '__clear__' || $raw === 0 || $raw === '0') {
            return [null, true, null];
        }
        $industryId = (int)$raw;
        if ($industryId < 1) {
            return [null, true, $this->error('行业分类无效', 422)];
        }
        IndustryDataService::ensureSchema();
        $node = DB::selectOne('SELECT id FROM industry_data WHERE id=? AND enabled=1', [$industryId]);
        if (!$node) {
            return [null, true, $this->error('行业分类不存在', 404)];
        }
        return [$industryId, true, null];
    }

    private function parseCompanyBinding(Request $request): array
    {
        $all = $request->all();
        if (!array_key_exists('companyId', $all) && !array_key_exists('company_id', $all)) {
            return [null, false, null];
        }
        if (!DataScope::canAssignAccountOwners()) {
            return [null, true, $this->error('当前角色无权绑定企业', 403)];
        }
        $raw = $request->input('companyId', $request->input('company_id'));
        if ($raw === null || $raw === '' || $raw === '__clear__') {
            return [null, true, null];
        }
        $companyId = (int)$raw;
        if ($companyId < 1) {
            return [null, true, $this->error('企业无效', 422)];
        }
        CompanyService::ensureSchema();
        if (!CompanyService::find($companyId)) {
            return [null, true, $this->error('企业不存在', 404)];
        }
        return [$companyId, true, null];
    }

    /**
     * @return array{0:int,1:?int,2:?Response} [ownerId, companyId|null, error]
     */
    private function resolveOwnerAssignment(int $targetOwnerId, ?int $companyId): array
    {
        if ($targetOwnerId < 1) {
            return [0, null, $this->error('请选择关联人员', 422)];
        }
        if (!DataScope::canAssignAccountOwners()) {
            return [0, null, $this->error('当前角色无权分配账号归属', 403)];
        }
        $target = DB::table('admin_users')->where('id', $targetOwnerId)->first();
        if (!$target || (int)($target['status'] ?? 0) !== 1) {
            return [0, null, $this->error('目标管理用户不存在或已停用', 404)];
        }
        $auth = DataScope::effectiveUser();
        if (!$auth) {
            return [0, null, $this->error('未登录', 401)];
        }
        $authId = (int)($auth['id'] ?? 0);
        if (!DataScope::isSuper() && !DataScope::isOpsAdmin()) {
            $allowed = $targetOwnerId === $authId || DataScope::isDirectChild($authId, $targetOwnerId);
            if (!$allowed) {
                return [0, null, $this->error('只能分配给本人或自己的子级管理员', 403)];
            }
        }

        $roleName = DataScope::roleNameOfUser($target);
        $isEnterprise = str_contains($roleName, '企业客户');
        $isOperator = str_contains($roleName, '运营专员');
        if (!$isEnterprise && !$isOperator) {
            return [0, null, $this->error('只能分配给运营专员或企业客户', 422)];
        }
        if ($isOperator) {
            return [$targetOwnerId, null, null];
        }

        CompanyService::ensureSchema();
        $companyIds = CompanyService::companyIdsOfUser($targetOwnerId);
        if (!$companyIds) {
            return [0, null, $this->error('该企业客户未关联企业，请先在企业数据中绑定', 422)];
        }
        $resolvedCompany = ($companyId !== null && $companyId > 0) ? $companyId : (int)$companyIds[0];
        if (!in_array($resolvedCompany, $companyIds, true)) {
            return [0, null, $this->error('所选企业不属于该企业客户', 422)];
        }
        return [$targetOwnerId, $resolvedCompany, null];
    }

    private function insertAccount(array $row, ?int $overrideOwnerId = null, ?int $overrideCompanyId = null): int
    {
        DataScope::ensureColumns();
        $this->ensureSchema();
        $requestedIndustry = array_key_exists('industry_id', $row) ? $row['industry_id'] : false;
        if ($overrideOwnerId !== null && $overrideOwnerId > 0) {
            $row['owner_id'] = $overrideOwnerId;
            $row['company_id'] = $overrideCompanyId;
        } else {
            DataScope::applyAccountInsertBindings($row);
        }
        if ($requestedIndustry !== false) {
            $row['industry_id'] = $requestedIndustry;
        }
        DB::query(
            'INSERT INTO accounts(username,nickname,country,group_id,proxy_id,browser_type,browser_profile_id,login_status,chat_status,dm_channel,enabled,notes,has_session,owner_id,company_id,industry_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $row['username'], $row['nickname'], $row['country'], $row['group_id'], $row['proxy_id'],
                $row['browser_type'], $row['browser_profile_id'], $row['login_status'], $row['chat_status'],
                $row['dm_channel'] ?? 'auto', $row['enabled'], $row['notes'], $row['has_session'] ?? 0, $row['owner_id'],
                $row['company_id'] ?? null,
                $row['industry_id'] ?? null,
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        // 新建/导入时若指定了执行后端或环境 ID，一并落位到对应列与环境表
        try {
            $newBackend = (string)($row['browser_backend'] ?? 'bit');
            $newProfileId = trim((string)($row['browser_profile_id'] ?? ''));
            if ($newBackend !== 'bit' || $newProfileId !== '') {
                \App\Services\AccountBrowserEnvService::applyAccountBackend($id, $newBackend, $newProfileId);
            }
        } catch (\Throwable $bindErr) {
            // 绑定失败不阻断账号创建，可在环境页重新绑定
        }
        $this->syncLegacyStatus($id, $row['login_status'], $row['browser_profile_id']);
        return $id;
    }

    private function syncLegacyStatus(int $id, string $login, ?string $profile): void
    {
        if ($this->hasColumn('accounts', 'status')) {
            $legacy = in_array($login, ['online', 'offline', 'expired'], true) ? $login : 'offline';
            try {
                DB::query('UPDATE accounts SET status=? WHERE id=?', [$legacy, $id]);
            } catch (\Throwable $e) {
            }
        }
        if ($profile !== null && $profile !== '' && $this->hasColumn('accounts', 'browser_id')) {
            try {
                DB::query('UPDATE accounts SET browser_id=? WHERE id=?', [$profile, $id]);
            } catch (\Throwable $e) {
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function enrichOwnerMeta(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        DataScope::ensureColumns();
        CompanyService::ensureSchema();
        $ownerIds = [];
        foreach ($rows as $r) {
            $oid = (int)($r['owner_id'] ?? 0);
            if ($oid > 0) {
                $ownerIds[] = $oid;
            }
        }
        $ownerIds = array_values(array_unique($ownerIds));
        if (!$ownerIds) {
            return $rows;
        }
        $ph = implode(',', array_fill(0, count($ownerIds), '?'));
        $users = DB::select(
            "SELECT id, username, nickname, company_id FROM admin_users WHERE id IN ({$ph})",
            $ownerIds
        ) ?: [];
        $userMap = [];
        foreach ($users as $u) {
            $userMap[(int)$u['id']] = $u;
        }
        $accountCompanyIds = [];
        foreach ($rows as $r) {
            $cid = (int)($r['company_id'] ?? 0);
            if ($cid > 0) {
                $accountCompanyIds[] = $cid;
            }
        }
        $companies = CompanyService::mapByIds(array_values(array_unique($accountCompanyIds)));

        foreach ($rows as &$row) {
            $oid = (int)($row['owner_id'] ?? 0);
            $u = $userMap[$oid] ?? null;
            if ($u) {
                $row['owner_username'] = (string)($u['username'] ?? '');
                $nick = trim((string)($u['nickname'] ?? ''));
                $row['owner_nickname'] = $nick;
                $row['owner_label'] = $nick !== '' ? $nick : (string)($u['username'] ?? '');
            } else {
                $row['owner_username'] = '';
                $row['owner_nickname'] = '';
                $row['owner_label'] = '';
            }

            // 仅展示账号表显式绑定的企业/行业；运营专员分配时 company_id 为空，不从用户档案推断
            $cid = (int)($row['company_id'] ?? 0);
            $company = ($cid > 0 && isset($companies[$cid])) ? $companies[$cid] : null;
            $row['company_name'] = $company ? (string)($company['name'] ?? '') : '';
            $iid = (int)($row['industry_id'] ?? 0);
            $path = $iid > 0 ? IndustryDataService::pathLabel($iid) : '';
            $row['industry_path'] = $path !== '' ? str_replace(' / ', ' - ', $path) : '';
        }
        unset($row);
        return $rows;
    }

    /**
     * 选账号卡片所需统计：粉丝/视频/最近同步 + 当日私信配额。
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function enrichPickerCardMeta(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $ids = [];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return $rows;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $profileMap = [];
        try {
            $profiles = DB::select(
                "SELECT account_id, followers_count, videos_count, last_synced_at
                 FROM tiktok_profiles WHERE account_id IN ({$ph})",
                $ids
            ) ?: [];
            foreach ($profiles as $p) {
                $profileMap[(int)$p['account_id']] = $p;
            }
        } catch (\Throwable $e) {
        }
        $videoSyncMap = [];
        try {
            $videoSyncs = DB::select(
                "SELECT account_id, MAX(last_synced_at) AS last_video_at
                 FROM tiktok_videos WHERE account_id IN ({$ph}) GROUP BY account_id",
                $ids
            ) ?: [];
            foreach ($videoSyncs as $v) {
                $videoSyncMap[(int)$v['account_id']] = (string)($v['last_video_at'] ?? '');
            }
        } catch (\Throwable $e) {
        }
        $dmMap = [];
        try {
            $today = date('Y-m-d');
            $dailies = DB::select(
                "SELECT account_id, times, daily_limit
                 FROM ai_lead_account_daily WHERE date=? AND account_id IN ({$ph})",
                array_merge([$today], $ids)
            ) ?: [];
            foreach ($dailies as $d) {
                $dmMap[(int)$d['account_id']] = $d;
            }
        } catch (\Throwable $e) {
        }

        foreach ($rows as &$row) {
            $id = (int)($row['id'] ?? 0);
            $p = $profileMap[$id] ?? null;
            $row['followers_count'] = (int)($p['followers_count'] ?? 0);
            $row['videos_count'] = (int)($p['videos_count'] ?? 0);
            $profileSynced = trim((string)($p['last_synced_at'] ?? ''));
            $row['profile_synced_at'] = $profileSynced;
            $videoAt = trim((string)($videoSyncMap[$id] ?? ''));
            $row['last_video_at'] = $videoAt !== '' ? $videoAt : $profileSynced;
            $dm = $dmMap[$id] ?? null;
            $row['dm_used'] = (int)($dm['times'] ?? 0);
            $row['dm_limit'] = (int)($dm['daily_limit'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    private function publicRow(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        unset($row['cookie_encrypted'], $row['storage_encrypted'], $row['password_encrypted'], $row['totp_secret_encrypted']);
        // 账号国家与代理国家分开：country / account_country = 账号绑定；proxy_country = 代理出口
        $accountCountry = trim((string)($row['country'] ?? ''));
        $proxyCountry = trim((string)($row['proxy_country'] ?? ''));
        $row['account_country'] = $accountCountry;
        $row['country'] = $accountCountry;
        $row['proxy_country'] = $proxyCountry;
        $row['proxy_region'] = trim((string)($row['proxy_region'] ?? ''));
        $row['proxy_city'] = trim((string)($row['proxy_city'] ?? ''));
        $row['has_totp'] = !empty($row['has_totp']) ? 1 : 0;
        $row['has_fingerprint'] = trim((string)($row['fingerprint'] ?? '')) !== '' ? 1 : 0;
        if (!empty($row['has_fingerprint'])) {
            $row['fingerprint'] = '1';
        }
        $row['followers_count'] = (int)($row['followers_count'] ?? 0);
        $row['videos_count'] = (int)($row['videos_count'] ?? 0);
        $row['last_video_at'] = (string)($row['last_video_at'] ?? '');
        $row['dm_used'] = (int)($row['dm_used'] ?? 0);
        $row['dm_limit'] = (int)($row['dm_limit'] ?? 0);
        // 查看数据：拉取成功后是否自动入队邮件计划任务（缺省关闭）
        $mailPush = array_key_exists('data_mail_push', $row) ? (int)$row['data_mail_push'] : 0;
        $row['data_mail_push'] = $mailPush === 1 ? 1 : 0;
        $row['dataMailPush'] = $row['data_mail_push'];
        return $row;
    }

    /** @return list<int> */
    private function proxyIdsForAccountImport(int $proxyGroupId): array
    {
        $bindings = DataScope::accountInsertBindings();
        if ($bindings !== null) {
            $ownerId = (int)$bindings['owner_id'];
            if ($proxyGroupId > 0) {
                return array_column(
                    DB::select(
                        'SELECT id FROM proxies WHERE group_id=? AND owner_id=? ORDER BY id ASC',
                        [$proxyGroupId, $ownerId]
                    ),
                    'id'
                );
            }
            return array_column(
                DB::select('SELECT id FROM proxies WHERE owner_id=? ORDER BY id ASC', [$ownerId]),
                'id'
            );
        }
        if ($proxyGroupId > 0) {
            return array_column(
                DB::select(
                    'SELECT id FROM proxies WHERE group_id=? AND (owner_id IS NULL OR owner_id=0) ORDER BY id ASC',
                    [$proxyGroupId]
                ),
                'id'
            );
        }
        return array_column(
            DB::select('SELECT id FROM proxies WHERE owner_id IS NULL OR owner_id=0 ORDER BY id ASC'),
            'id'
        );
    }

    private function industryIdOfCompany(?int $companyId): ?int
    {
        if ($companyId === null || $companyId <= 0) {
            return null;
        }
        CompanyService::ensureSchema();
        $company = CompanyService::find($companyId);
        $industryId = (int)($company['industryId'] ?? 0);
        return $industryId > 0 ? $industryId : null;
    }

    private function idList(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        return array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 500);
    }

    /** @var array<string, list<string>> */
    private static array $columnCache = [];

    private function hasColumn(string $table, string $column): bool
    {
        if (!isset(self::$columnCache[$table])) {
            self::$columnCache[$table] = array_column(DB::select("SHOW COLUMNS FROM `{$table}`") ?: [], 'Field');
        }
        return in_array($column, self::$columnCache[$table], true);
    }

    private static function bustColumnCache(string $table): void
    {
        unset(self::$columnCache[$table]);
    }

    private function ensureSchema(): void
    {
        // 修改资料冷却字段：每次校验，避免常驻 PHP 进程 static 跳过迁移
        if (!$this->hasColumn('accounts', 'nickname_changed_at')) {
            try {
                DB::query('ALTER TABLE accounts ADD COLUMN nickname_changed_at DATETIME NULL DEFAULT NULL');
                self::bustColumnCache('accounts');
            } catch (\Throwable $e) {
            }
        }
        // 数据邮件推送开关：同样每次校验，避免常驻进程漏迁移（默认开启，兼容原自动发信）
        if (!$this->hasColumn('accounts', 'data_mail_push')) {
            try {
                DB::query('ALTER TABLE accounts ADD COLUMN data_mail_push TINYINT NOT NULL DEFAULT 0');
                self::bustColumnCache('accounts');
            } catch (\Throwable $e) {
            }
        }
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            \App\Services\AccountBrowserEnvService::ensureSchema();
        } catch (\Throwable $e) {
        }
        if (!$this->hasColumn('groups', 'parent_id')) {
            try {
                DB::query('ALTER TABLE `groups` ADD COLUMN `parent_id` INT NULL DEFAULT NULL');
            } catch (\Throwable $e) {
            }
            try {
                DB::query('ALTER TABLE `groups` ADD KEY `idx_groups_parent` (`parent_id`, `type`)');
            } catch (\Throwable $e) {
            }
        }
        $alters = [
            'accounts' => [
                'country' => "ALTER TABLE accounts ADD COLUMN country VARCHAR(64) NOT NULL DEFAULT ''",
                'browser_type' => "ALTER TABLE accounts ADD COLUMN browser_type VARCHAR(32) NOT NULL DEFAULT 'bit'",
                'browser_profile_id' => "ALTER TABLE accounts ADD COLUMN browser_profile_id VARCHAR(128) NOT NULL DEFAULT ''",
                'browser_backend' => "ALTER TABLE accounts ADD COLUMN browser_backend VARCHAR(16) NOT NULL DEFAULT 'bit'",
                'tkswarm_browser_profile_id' => "ALTER TABLE accounts ADD COLUMN tkswarm_browser_profile_id VARCHAR(128) NOT NULL DEFAULT ''",
                'linux_browser_profile_id' => "ALTER TABLE accounts ADD COLUMN linux_browser_profile_id VARCHAR(128) NOT NULL DEFAULT ''",
                'login_status' => "ALTER TABLE accounts ADD COLUMN login_status VARCHAR(32) NOT NULL DEFAULT 'offline'",
                'chat_status' => "ALTER TABLE accounts ADD COLUMN chat_status VARCHAR(32) NOT NULL DEFAULT 'offline'",
                'dm_channel' => "ALTER TABLE accounts ADD COLUMN dm_channel VARCHAR(16) NOT NULL DEFAULT 'auto'",
                'enabled' => 'ALTER TABLE accounts ADD COLUMN enabled TINYINT NOT NULL DEFAULT 1',
                'notes' => 'ALTER TABLE accounts ADD COLUMN notes TEXT NULL',
                'has_session' => 'ALTER TABLE accounts ADD COLUMN has_session TINYINT NOT NULL DEFAULT 0',
                'capcut_authorized' => 'ALTER TABLE accounts ADD COLUMN capcut_authorized TINYINT NOT NULL DEFAULT 0',
                'fingerprint' => "ALTER TABLE accounts ADD COLUMN fingerprint VARCHAR(255) NOT NULL DEFAULT ''",
                'company_id' => 'ALTER TABLE accounts ADD COLUMN company_id INT NULL DEFAULT NULL',
                'industry_id' => 'ALTER TABLE accounts ADD COLUMN industry_id INT NULL DEFAULT NULL',
                'account_status' => "ALTER TABLE accounts ADD COLUMN account_status VARCHAR(32) NOT NULL DEFAULT 'normal'",
                'profile_bio' => 'ALTER TABLE accounts ADD COLUMN profile_bio TEXT NULL',
                'profile_avatar' => "ALTER TABLE accounts ADD COLUMN profile_avatar VARCHAR(512) NOT NULL DEFAULT ''",
                'nickname_changed_at' => 'ALTER TABLE accounts ADD COLUMN nickname_changed_at DATETIME NULL DEFAULT NULL',
                // 拉取数据分析邮件计划任务开关：1=允许自动入队发信，0=关闭（默认关闭）
                'data_mail_push' => 'ALTER TABLE accounts ADD COLUMN data_mail_push TINYINT NOT NULL DEFAULT 0',
            ],
            'proxies' => [
                'use_count' => 'ALTER TABLE proxies ADD COLUMN use_count INT NOT NULL DEFAULT 0',
                'password_encrypted' => 'ALTER TABLE proxies ADD COLUMN password_encrypted TEXT NULL',
                'egress_ip' => "ALTER TABLE proxies ADD COLUMN egress_ip VARCHAR(64) NOT NULL DEFAULT ''",
                'region' => "ALTER TABLE proxies ADD COLUMN region VARCHAR(64) NOT NULL DEFAULT ''",
                'city' => "ALTER TABLE proxies ADD COLUMN city VARCHAR(64) NOT NULL DEFAULT ''",
            ],
        ];
        foreach ($alters as $table => $cols) {
            foreach ($cols as $col => $sql) {
                if (!$this->hasColumn($table, $col)) {
                    try {
                        DB::query($sql);
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
        if ($this->hasColumn('accounts', 'status') && $this->hasColumn('accounts', 'login_status')) {
            try {
                DB::query("UPDATE accounts SET login_status=status WHERE (login_status='' OR login_status IS NULL) AND status<>''");
            } catch (\Throwable $e) {
            }
        }
        DB::query("CREATE TABLE IF NOT EXISTS account_secrets (
          account_id INT PRIMARY KEY,
          password_encrypted TEXT NOT NULL,
          totp_secret_encrypted TEXT NOT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS account_sessions (
          account_id INT PRIMARY KEY,
          cookie_encrypted MEDIUMTEXT NOT NULL,
          storage_encrypted MEDIUMTEXT NULL,
          fingerprint VARCHAR(255) NOT NULL DEFAULT '',
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            DB::query('ALTER TABLE account_sessions MODIFY cookie_encrypted MEDIUMTEXT NOT NULL');
        } catch (\Throwable $e) {
        }
        DB::query("CREATE TABLE IF NOT EXISTS account_monitor_config (
          id INT PRIMARY KEY,
          enabled TINYINT NOT NULL DEFAULT 0,
          interval_minutes INT NOT NULL DEFAULT 60,
          sync_profile TINYINT NOT NULL DEFAULT 1,
          sync_videos TINYINT NOT NULL DEFAULT 0,
          account_ids_json MEDIUMTEXT NOT NULL,
          last_run_at DATETIME NULL,
          last_result_json MEDIUMTEXT NOT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query('INSERT IGNORE INTO account_monitor_config(id,enabled,interval_minutes,account_ids_json,last_result_json) VALUES (1,0,60,?,?)', ['[]', '{}']);
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_profiles (
          account_id INT PRIMARY KEY,
          handle VARCHAR(191) NOT NULL DEFAULT '',
          display_name VARCHAR(191) NOT NULL DEFAULT '',
          bio TEXT NULL,
          avatar_url TEXT NULL,
          followers_count INT NULL,
          following_count INT NULL,
          likes_count INT NULL,
          videos_count INT NULL,
          verified TINYINT NOT NULL DEFAULT 0,
          source_url TEXT NULL,
          last_synced_at DATETIME NULL,
          sync_status VARCHAR(32) NOT NULL DEFAULT 'never',
          sync_error TEXT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_videos (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          video_id VARCHAR(191) NOT NULL,
          video_url TEXT NOT NULL,
          description TEXT NULL,
          thumbnail_url TEXT NULL,
          views_count INT NULL,
          likes_count INT NULL,
          last_synced_at DATETIME NULL,
          KEY idx_tiktok_videos_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_stat_snapshots (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          followers_count INT NULL,
          following_count INT NULL,
          likes_count INT NULL,
          videos_count INT NULL,
          captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_tiktok_stats_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_analytics_28d (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          metric_date DATE NOT NULL,
          video_views BIGINT NOT NULL DEFAULT 0,
          profile_views BIGINT NOT NULL DEFAULT 0,
          likes_count BIGINT NOT NULL DEFAULT 0,
          comments_count BIGINT NOT NULL DEFAULT 0,
          shares_count BIGINT NOT NULL DEFAULT 0,
          est_rewards DECIMAL(14,4) NOT NULL DEFAULT 0,
          pulled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_tt_analytics_acc_date (account_id, metric_date),
          KEY idx_tt_analytics_acc (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Studio 四个页签（Overview / Content / Followers / Viewer）原始内容
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_studio_tabs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          tab_key VARCHAR(64) NOT NULL,
          tab_label VARCHAR(191) NOT NULL DEFAULT '',
          page_url TEXT NULL,
          metric_count INT NOT NULL DEFAULT 0,
          raw_text MEDIUMTEXT NULL,
          captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_tt_studio_tab (account_id, tab_key),
          KEY idx_tt_studio_tab_acc (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // 归一化后的指标：一项一行，便于前端直接展示
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_studio_metrics (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          tab_key VARCHAR(64) NOT NULL,
          metric_key VARCHAR(96) NOT NULL,
          metric_label VARCHAR(191) NOT NULL DEFAULT '',
          value_num BIGINT NOT NULL DEFAULT 0,
          value_text VARCHAR(64) NOT NULL DEFAULT '',
          captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_tt_studio_metric (account_id, tab_key, metric_key),
          KEY idx_tt_studio_metric_acc (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Content 页签的逐条内容数据
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_studio_content (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          row_no INT NOT NULL DEFAULT 0,
          title VARCHAR(512) NOT NULL DEFAULT '',
          views_num BIGINT NOT NULL DEFAULT 0,
          likes_num BIGINT NOT NULL DEFAULT 0,
          comments_num BIGINT NOT NULL DEFAULT 0,
          shares_num BIGINT NOT NULL DEFAULT 0,
          posted_at VARCHAR(64) NOT NULL DEFAULT '',
          video_id VARCHAR(191) NOT NULL DEFAULT '',
          video_url TEXT NULL,
          cover_url TEXT NULL,
          material_id INT NOT NULL DEFAULT 0,
          captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_tt_studio_content_acc (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            $contentCols = array_column(DB::select('SHOW COLUMNS FROM tiktok_studio_content') ?: [], 'Field');
            $contentAlters = [
                'video_id' => "ALTER TABLE tiktok_studio_content ADD COLUMN video_id VARCHAR(191) NOT NULL DEFAULT ''",
                'video_url' => 'ALTER TABLE tiktok_studio_content ADD COLUMN video_url TEXT NULL',
                'cover_url' => 'ALTER TABLE tiktok_studio_content ADD COLUMN cover_url TEXT NULL',
                'material_id' => 'ALTER TABLE tiktok_studio_content ADD COLUMN material_id INT NOT NULL DEFAULT 0',
            ];
            foreach ($contentAlters as $col => $sql) {
                if (!in_array($col, $contentCols, true)) {
                    DB::query($sql);
                }
            }
        } catch (\Throwable $e) {
        }
        // 每次拉取的现场信息（页面地址/页签数/接口数），排错用
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_studio_sync (
          account_id INT PRIMARY KEY,
          page_url TEXT NULL,
          tab_count INT NOT NULL DEFAULT 0,
          api_count INT NOT NULL DEFAULT 0,
          status VARCHAR(32) NOT NULL DEFAULT 'ok',
          message TEXT NULL,
          meta_json MEDIUMTEXT NULL,
          captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // 网络原始 JSON（排错/回补解析用，只留最近 20 条）
        DB::query("CREATE TABLE IF NOT EXISTS tiktok_studio_api (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          url TEXT NULL,
          payload MEDIUMTEXT NULL,
          captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_tt_studio_api_acc (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            $cols = array_column(DB::select('SHOW COLUMNS FROM tiktok_profiles') ?: [], 'Field');
            $extra = [
                'comments_count' => 'ALTER TABLE tiktok_profiles ADD COLUMN comments_count INT NOT NULL DEFAULT 0',
                'shares_count' => 'ALTER TABLE tiktok_profiles ADD COLUMN shares_count INT NOT NULL DEFAULT 0',
                'period_video_views' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_video_views BIGINT NOT NULL DEFAULT 0',
                'period_profile_views' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_profile_views BIGINT NOT NULL DEFAULT 0',
                'period_likes' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_likes BIGINT NOT NULL DEFAULT 0',
                'period_comments' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_comments BIGINT NOT NULL DEFAULT 0',
                'period_shares' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_shares BIGINT NOT NULL DEFAULT 0',
                'period_est_rewards' => 'ALTER TABLE tiktok_profiles ADD COLUMN period_est_rewards DECIMAL(14,4) NOT NULL DEFAULT 0',
            ];
            foreach ($extra as $col => $sql) {
                if (!in_array($col, $cols, true)) {
                    DB::query($sql);
                }
            }
        } catch (\Throwable $e) {
        }
        DB::query("CREATE TABLE IF NOT EXISTS account_diagnosis_records (
          id INT AUTO_INCREMENT PRIMARY KEY,
          account_id INT NOT NULL,
          screenshot_path VARCHAR(512) NOT NULL,
          label VARCHAR(64) NOT NULL DEFAULT 'account-check',
          source VARCHAR(32) NOT NULL DEFAULT 'manual',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_adr_account_created (account_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // 系统通知表 + 账号封禁字段（ban_reason / ban_until / ban_checked_at / ban_source / ban_ai_json）
        try {
            AccountBanService::ensureSchema();
        } catch (\Throwable $e) {
        }
    }
}
