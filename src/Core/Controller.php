<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\DataScope;

class Controller
{
    protected function json(mixed $data, int $statusCode = 200): Response
    {
        return Response::json($data, $statusCode);
    }

    protected function success(mixed $data = null, string $message = '操作成功'): Response
    {
        return Response::success($data, $message);
    }

    protected function error(string $message = '操作失败', int $code = 400, mixed $data = null): Response
    {
        return Response::error($message, $code, $data);
    }

    protected function view(string $template, array $data = []): Response
    {
        return View::render($template, $data);
    }

    protected function applyOwnerFilter(string $column, array &$filters, array &$params): void
    {
        DataScope::applyOwnerFilter($column, $filters, $params);
    }

    /** @return array{0:string,1:list<mixed>} */
    protected function ownerSql(string $column = 'owner_id'): array
    {
        return DataScope::ownerSql($column);
    }

    /** 账号列表/批量操作可见范围（含公海），与 accountListScopeSql 一致 */
    protected function accountScopeSql(string $column = 'owner_id'): array
    {
        return DataScope::accountListScopeSql($column);
    }

    /** 代理列表/批量操作可见范围 */
    protected function proxyScopeSql(string $column = 'owner_id'): array
    {
        return DataScope::proxyListScopeSql($column);
    }

    /** 本人 + 直属下级（超管未切换不过滤） */
    protected function visibleOwnerSql(string $column = 'owner_id'): array
    {
        return DataScope::visibleOwnerSql($column);
    }

    protected function stampOwner(array &$row): void
    {
        DataScope::stamp($row);
    }

    protected function writeOwnerId(): int
    {
        return DataScope::writeOwnerId();
    }

    protected function assertOwns(?array $row, string $field = 'owner_id'): ?Response
    {
        return DataScope::assertOwns($row, $field);
    }

    /** 账号行写权限：沿用列表/详情的可见范围（超管等不再「看得见但保存 403」） */
    protected function assertAccountOwns(?array $account): ?Response
    {
        return DataScope::assertAccountOwns($account);
    }

    /** Node 自动化调用用：无身份（服务调用）放行，有身份按可见范围 */
    protected function assertNodeAccountRow(?array $account): ?Response
    {
        return DataScope::assertNodeAccount($account);
    }

    /** 账号会话总任务：按 batch 归属或关联账号可见范围 */
    protected function assertWorkBatchOwns(?array $batch): ?Response
    {
        return DataScope::assertWorkBatchOwns($batch);
    }

    /** AI 拓客：按任务归属或关联账号可见范围 */
    protected function assertAiLeadOwns(?array $lead): ?Response
    {
        return DataScope::assertAiLeadOwns($lead);
    }

    protected function applyMaterialOwnerFilter(string $column, array &$filters, array &$params): void
    {
        DataScope::applyMaterialOwnerFilter($column, $filters, $params);
    }

    /** @return array{0:string,1:list<mixed>} */
    protected function materialOwnerSql(string $column = 'owner_id'): array
    {
        return DataScope::materialOwnerSql($column);
    }

    protected function writeMaterialOwnerId(): int
    {
        return DataScope::writeMaterialOwnerId();
    }

    protected function assertMaterialOwns(?array $row, string $field = 'owner_id'): ?Response
    {
        return DataScope::assertMaterialOwns($row, $field);
    }

    /** 素材只读（详情/预览/拉取视频数据选片） */
    protected function assertMaterialVisible(?array $row, string $field = 'owner_id'): ?Response
    {
        return DataScope::assertMaterialVisible($row, $field);
    }
}
