<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Github\GithubReleaseCdnService;
use App\Services\SecretStore;

/**
 * 运营控制台：GitHub Release CDN 配置
 */
class GithubConfigManageController extends Controller
{
    public function list(Request $request): Response
    {
        GithubReleaseCdnService::ensureSchema();
        $rows = GithubReleaseCdnService::listConfigs();
        return $this->success([
            'items' => array_map([$this, 'mapRow'], $rows),
        ]);
    }

    public function create(Request $request): Response
    {
        GithubReleaseCdnService::ensureSchema();
        $data = $this->parseBody($request);
        if ($data instanceof Response) {
            return $data;
        }
        $token = (string)($data['token'] ?? '');
        if ($token === '') {
            return $this->error('请填写 GitHub Token', 422);
        }
        DB::query(
            'INSERT INTO github_cdn_configs(name, owner, repo, token_enc, tag_prefix, url_template, cdn_prefix, is_enabled, notes)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $data['name'],
                $data['owner'],
                $data['repo'],
                SecretStore::encrypt($token),
                $data['tag_prefix'],
                $data['url_template'],
                $data['cdn_prefix'],
                0,
                $data['notes'],
            ]
        );
        $id = (int)DB::getPdo()->lastInsertId();
        if (!empty($data['enable_now'])) {
            $this->enableById($id);
        }
        $row = DB::selectOne('SELECT * FROM github_cdn_configs WHERE id=?', [$id]);
        return $this->success($this->mapRow($row ?: ['id' => $id]), '已创建');
    }

    public function update(Request $request, string $id): Response
    {
        GithubReleaseCdnService::ensureSchema();
        $cid = (int)$id;
        $old = DB::selectOne('SELECT * FROM github_cdn_configs WHERE id=?', [$cid]);
        if (!$old) {
            return $this->error('配置不存在', 404);
        }
        $data = $this->parseBody($request, false);
        if ($data instanceof Response) {
            return $data;
        }
        $tokenEnc = (string)($old['token_enc'] ?? '');
        $token = (string)($data['token'] ?? '');
        if ($token !== '' && $token !== '******') {
            $tokenEnc = SecretStore::encrypt($token);
        }
        DB::query(
            'UPDATE github_cdn_configs SET name=?, owner=?, repo=?, token_enc=?, tag_prefix=?, url_template=?, cdn_prefix=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $data['name'],
                $data['owner'],
                $data['repo'],
                $tokenEnc,
                $data['tag_prefix'],
                $data['url_template'],
                $data['cdn_prefix'],
                $data['notes'],
                $cid,
            ]
        );
        $row = DB::selectOne('SELECT * FROM github_cdn_configs WHERE id=?', [$cid]);
        return $this->success($this->mapRow($row ?: $old), '已保存');
    }

    public function enable(Request $request, string $id): Response
    {
        GithubReleaseCdnService::ensureSchema();
        $cid = (int)$id;
        $old = DB::selectOne('SELECT id FROM github_cdn_configs WHERE id=?', [$cid]);
        if (!$old) {
            return $this->error('配置不存在', 404);
        }
        $this->enableById($cid);
        return $this->success(['id' => $cid, 'isEnabled' => true], '已设为当前上传启用项');
    }

    public function delete(Request $request, string $id): Response
    {
        GithubReleaseCdnService::ensureSchema();
        $cid = (int)$id;
        DB::query('DELETE FROM github_cdn_configs WHERE id=?', [$cid]);
        return $this->success(['id' => $cid], '已删除');
    }

    private function enableById(int $id): void
    {
        DB::query('UPDATE github_cdn_configs SET is_enabled=0 WHERE is_enabled=1');
        DB::query('UPDATE github_cdn_configs SET is_enabled=1, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$id]);
    }

    /**
     * @return array<string,mixed>|Response
     */
    private function parseBody(Request $request, bool $requireToken = true)
    {
        $name = trim((string)$request->input('name', ''));
        $owner = trim((string)$request->input('owner', ''));
        $repo = trim((string)$request->input('repo', ''));
        $token = trim((string)$request->input('token', ''));
        $tagPrefix = trim((string)$request->input('tagPrefix', $request->input('tag_prefix', 'videos')));
        $urlTemplate = trim((string)$request->input('urlTemplate', $request->input('url_template', '')));
        $cdnPrefix = trim((string)$request->input('cdnPrefix', $request->input('cdn_prefix', '')));
        $notes = trim((string)$request->input('notes', ''));
        $enableNow = filter_var($request->input('enableNow', $request->input('enable_now', false)), FILTER_VALIDATE_BOOLEAN);

        if ($name === '') {
            $name = $owner !== '' && $repo !== '' ? ($owner . '/' . $repo) : 'GitHub CDN';
        }
        if ($owner === '' || $repo === '') {
            return $this->error('请填写 owner 与 repo', 422);
        }
        if ($requireToken && $token === '') {
            return $this->error('请填写 GitHub Token', 422);
        }
        if ($tagPrefix === '') {
            $tagPrefix = 'videos';
        }
        if ($urlTemplate === '') {
            $urlTemplate = 'https://github.com/{owner}/{repo}/releases/download/{tag}/{name}';
        }
        return [
            'name' => mb_substr($name, 0, 100),
            'owner' => mb_substr($owner, 0, 100),
            'repo' => mb_substr($repo, 0, 150),
            'token' => $token,
            'tag_prefix' => mb_substr($tagPrefix, 0, 64),
            'url_template' => mb_substr($urlTemplate, 0, 500),
            'cdn_prefix' => mb_substr($cdnPrefix, 0, 300),
            'notes' => mb_substr($notes, 0, 500),
            'enable_now' => $enableNow,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapRow(array $row): array
    {
        $hasToken = trim((string)($row['token_enc'] ?? '')) !== '';
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'owner' => (string)($row['owner'] ?? ''),
            'repo' => (string)($row['repo'] ?? ''),
            'tagPrefix' => (string)($row['tag_prefix'] ?? 'videos'),
            'urlTemplate' => (string)($row['url_template'] ?? ''),
            'cdnPrefix' => (string)($row['cdn_prefix'] ?? ''),
            'isEnabled' => (int)($row['is_enabled'] ?? 0) === 1,
            'hasToken' => $hasToken,
            'token' => $hasToken ? '******' : '',
            'notes' => (string)($row['notes'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }
}
