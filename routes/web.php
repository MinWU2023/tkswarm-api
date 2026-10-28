<?php
declare(strict_types=1);

use App\Core\Router;
use App\Middlewares\CorsMiddleware;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\PermissionMiddleware;
use App\Controllers\Api\AccountController;
use App\Controllers\Api\ProxyController;
use App\Controllers\Api\MenuController;
use App\Controllers\Api\AuthController;
use App\Controllers\Api\TaskController;
use App\Controllers\Api\MaterialController;
use App\Controllers\Api\AgentController;
use App\Controllers\Api\SystemController;
use App\Controllers\Api\ChatController;
use App\Controllers\Api\GroupController;
use App\Controllers\Api\MetaController;
use App\Controllers\Api\MessageTemplateController;
use App\Controllers\Api\InteractionTemplateController;
use App\Controllers\Api\UidController;
use App\Controllers\Api\TranslateController;
use App\Controllers\Api\ClientVersionController;
use App\Controllers\Api\HelpDocController;
use App\Controllers\Api\TicketController;
use App\Controllers\Api\ContactInquiryController;
use App\Controllers\Api\EnvInstallerController;
use App\Controllers\Api\AiPlatformController;
use App\Controllers\Api\SmtpConfigController;
use App\Controllers\Api\AiPromptTemplateController;
use App\Controllers\Api\AiCallLogController;
use App\Controllers\Api\NodeBridgeController;
use App\Controllers\Api\AccountWorkController;
use App\Controllers\Api\PullCommentController;
use App\Controllers\Api\AutoFollowController;
use App\Controllers\Api\AiLeadController;
use App\Controllers\Api\AiLeadClueController;
use App\Controllers\Api\IndustryDataController;
use App\Controllers\Api\CountryDataController;
use App\Controllers\Api\CompanyController;
use App\Controllers\Admin\PermissionController;
use App\Controllers\Admin\ConsoleController;
use App\Controllers\Admin\TicketManageController;
use App\Controllers\Admin\ContactManageController;
use App\Controllers\Admin\VersionManageController;
use App\Controllers\Admin\HelpManageController;
use App\Controllers\Admin\EnvInstallerManageController;
use App\Controllers\Web\LandingController;

// 公开：登录相关 + 客户端版本目录/下载（供 EXE 使用）
Router::group(['middleware' => [CorsMiddleware::class]], function () {
    // 官网落地页
    Router::get('/', LandingController::class . '@spa');

    Router::get('/api/auth/captcha', AuthController::class . '@captcha');
    Router::get('/api/auth/status', AuthController::class . '@status');
    Router::post('/api/auth/login', AuthController::class . '@login');
    Router::post('/api/auth/setup', AuthController::class . '@setup');
    Router::post('/api/auth/logout', AuthController::class . '@logout');

    Router::get('/api/client-versions/catalog', ClientVersionController::class . '@catalog');
    Router::get('/api/client-versions/latest', ClientVersionController::class . '@latest');
    Router::get('/api/client-versions/{id}/download', ClientVersionController::class . '@download');
    Router::get('/api/client-versions/{id}', ClientVersionController::class . '@detail');

    // 客户端：Node / 比特安装包目录与下载（无需登录）
    Router::get('/api/env-installers/catalog', EnvInstallerController::class . '@catalog');
    Router::get('/api/env-installers/recommended', EnvInstallerController::class . '@recommended');
    Router::get('/api/env-installers/{id}/download', EnvInstallerController::class . '@download');

    // 客户端：帮助文档 / 工单提交（无需登录）
    Router::get('/api/help/categories', HelpDocController::class . '@publicCategories');
    Router::get('/api/help/docs', HelpDocController::class . '@publicDocs');
    Router::get('/api/help/docs/{id}', HelpDocController::class . '@publicDetail');
    Router::post('/api/tickets', TicketController::class . '@submit');
    Router::post('/api/contact-inquiries', ContactInquiryController::class . '@submit');
    Router::get('/api/public/site-config', SystemController::class . '@publicSiteConfig');

    // 运维审计：查 proxies 表缺密码记录
    Router::get('/api/debug/proxies-missing-password', ProxyController::class . '@missingPasswordAudit');

    // 运营控制台 SPA（静态资源走 public/admin，深链回落）
    Router::get('/admin', ConsoleController::class . '@spa');
    Router::get('/admin/', ConsoleController::class . '@spa');
    Router::get('/admin/app', ConsoleController::class . '@spa');
    Router::get('/admin/login', ConsoleController::class . '@spa');
    Router::get('/admin/dashboard', ConsoleController::class . '@spa');
    Router::get('/admin/tickets', ConsoleController::class . '@spa');
    Router::get('/admin/contact-inquiries', ConsoleController::class . '@spa');
    Router::get('/admin/versions', ConsoleController::class . '@spa');
    Router::get('/admin/help-categories', ConsoleController::class . '@spa');
    Router::get('/admin/help-docs', ConsoleController::class . '@spa');
    Router::get('/admin/env-installers', ConsoleController::class . '@spa');
    Router::get('/admin/site-settings', ConsoleController::class . '@spa');
    Router::get('/admin/task-reset', ConsoleController::class . '@spa');
    Router::get('/admin/task-clear', ConsoleController::class . '@spa');
});

// 需登录（authEnabled=false 时 AuthMiddleware 放行 guest 超管）
Router::group(['middleware' => [CorsMiddleware::class, AuthMiddleware::class, PermissionMiddleware::class]], function () {
    Router::get('/api/auth/switchable-users', AuthController::class . '@switchableUsers');
    Router::get('/api/auth/account-filter-options', AuthController::class . '@accountFilterOptions');
    Router::get('/api/auth/account-assign-targets', AuthController::class . '@accountAssignTargets');
    Router::get('/api/auth/proxy-assign-targets', AuthController::class . '@proxyAssignTargets');
    Router::put('/api/auth/password', AuthController::class . '@updatePassword');

    Router::get('/api/dashboard/stats', SystemController::class . '@dashboardStats');
    Router::get('/api/dashboard/activity', SystemController::class . '@dashboardActivity');
    Router::get('/api/settings', SystemController::class . '@getSettings');
    Router::post('/api/settings', SystemController::class . '@saveSettings');
    Router::put('/api/settings', SystemController::class . '@saveSettings');

    // 开发管理 · SMTP（仅超管，控制器内二次校验）
    Router::get('/api/smtp-config', SmtpConfigController::class . '@get');
    Router::put('/api/smtp-config', SmtpConfigController::class . '@save');
    Router::post('/api/smtp-config', SmtpConfigController::class . '@save');
    Router::post('/api/smtp-config/test', SmtpConfigController::class . '@test');

    Router::get('/api/meta', MetaController::class . '@index');
    Router::get('/api/meta/dicts/{domain}', MetaController::class . '@dicts');

    Router::get('/api/groups', GroupController::class . '@list');
    Router::post('/api/groups', GroupController::class . '@create');
    Router::post('/api/groups/{id}/assign-owner', GroupController::class . '@assignOwner');
    Router::put('/api/groups/{id}', GroupController::class . '@update');
    Router::delete('/api/groups/{id}', GroupController::class . '@delete');

    Router::get('/api/message-templates', MessageTemplateController::class . '@list');
    Router::post('/api/message-templates', MessageTemplateController::class . '@create');
    Router::put('/api/message-templates/{id}', MessageTemplateController::class . '@update');
    Router::delete('/api/message-templates/{id}', MessageTemplateController::class . '@delete');

    Router::get('/api/interaction-templates', InteractionTemplateController::class . '@list');
    Router::post('/api/interaction-templates', InteractionTemplateController::class . '@create');
    Router::post('/api/interaction-templates/generate-comment', InteractionTemplateController::class . '@generateComment');
    Router::get('/api/interaction-templates/{id}', InteractionTemplateController::class . '@detail');
    Router::put('/api/interaction-templates/{id}', InteractionTemplateController::class . '@update');
    Router::patch('/api/interaction-templates/{id}/status', InteractionTemplateController::class . '@patchStatus');
    Router::delete('/api/interaction-templates/{id}', InteractionTemplateController::class . '@delete');

    Router::get('/api/ai-leads', AiLeadController::class . '@list');
    Router::get('/api/ai-leads/video-weight-tiers', AiLeadController::class . '@videoWeightTiers');
    Router::put('/api/ai-leads/video-weight-tiers', AiLeadController::class . '@saveVideoWeightTiers');
    Router::get('/api/accounts/{id}/dm-weight', AiLeadController::class . '@accountDmWeight');
    Router::put('/api/accounts/{id}/dm-settings', AiLeadController::class . '@saveAccountDmSettings');
    Router::post('/api/ai-leads', AiLeadController::class . '@create');
    Router::get('/api/ai-leads/{id}', AiLeadController::class . '@detail');
    Router::put('/api/ai-leads/{id}', AiLeadController::class . '@update');
    Router::patch('/api/ai-leads/{id}/status', AiLeadController::class . '@patchStatus');
    Router::post('/api/ai-leads/{id}/test-dm', AiLeadController::class . '@testDm');
    Router::delete('/api/ai-leads/{id}', AiLeadController::class . '@delete');
    Router::post('/api/ai-leads/{id}/logs', AiLeadController::class . '@createLog');
    Router::post('/api/ai-leads/{id}/records', AiLeadController::class . '@createRecord');
    Router::post('/api/ai-leads/{id}/creators', AiLeadController::class . '@createCreator');

    Router::get('/api/auto-follow-tasks', AutoFollowController::class . '@list');
    Router::post('/api/auto-follow-tasks', AutoFollowController::class . '@create');
    Router::get('/api/auto-follow-tasks/eligible-count', AutoFollowController::class . '@eligibleCount');
    Router::get('/api/auto-follow-tasks/target-search', AutoFollowController::class . '@targetSearch');
    Router::get('/api/auto-follow-tasks/{id}', AutoFollowController::class . '@detail');
    Router::put('/api/auto-follow-tasks/{id}', AutoFollowController::class . '@update');
    Router::post('/api/auto-follow-tasks/{id}/cancel', AutoFollowController::class . '@cancel');
    Router::delete('/api/auto-follow-tasks/{id}', AutoFollowController::class . '@delete');

    Router::get('/api/ai-lead-clues', AiLeadClueController::class . '@list');
    Router::get('/api/ai-lead-clues/share-users', AiLeadClueController::class . '@shareRoleUsers');
    Router::post('/api/ai-lead-clues/batch-share', AiLeadClueController::class . '@batchShare');
    Router::get('/api/ai-lead-clues/export.csv', AiLeadClueController::class . '@exportCsv');
    Router::get('/api/ai-lead-clue-data', AiLeadClueController::class . '@listData');
    Router::get('/api/ai-lead-clue-data/{key}', AiLeadClueController::class . '@detailData');
    Router::get('/api/ai-lead-clues/{key}', AiLeadClueController::class . '@detail');
    Router::patch('/api/ai-lead-clues/{key}/account', AiLeadClueController::class . '@patchAccount');

    Router::get('/api/uids/groups', UidController::class . '@groups');
    Router::get('/api/uids/export', UidController::class . '@export');
    Router::get('/api/uids', UidController::class . '@list');
    Router::post('/api/uids/import', UidController::class . '@import');
    Router::post('/api/uids/dedupe', UidController::class . '@dedupe');
    Router::post('/api/uids/cleanup', UidController::class . '@cleanup');
    Router::post('/api/uids/keep-unused', UidController::class . '@keepUnused');
    Router::delete('/api/uids/group/{groupId}', UidController::class . '@clearGroup');
    Router::delete('/api/uids/{id}', UidController::class . '@delete');

    Router::get('/api/chat/accounts', ChatController::class . '@accounts');
    Router::post('/api/chat/accounts/batch-connect', ChatController::class . '@batchConnect');
    Router::post('/api/chat/accounts/batch-disconnect', ChatController::class . '@batchDisconnect');
    Router::post('/api/chat/accounts/batch-sync', ChatController::class . '@batchSync');
    Router::post('/api/chat/accounts/{id}/connect', ChatController::class . '@connect');
    Router::post('/api/chat/accounts/{id}/disconnect', ChatController::class . '@disconnect');
    Router::post('/api/chat/accounts/{id}/read-all', ChatController::class . '@readAll');
    Router::post('/api/chat/accounts/{id}/ai-hosting', ChatController::class . '@aiHosting');
    Router::post('/api/chat/accounts/{id}/refresh-fans', ChatController::class . '@refreshFans');
    Router::get('/api/chat/accounts/{id}/refresh-fans/status', ChatController::class . '@refreshFansStatus');
    Router::post('/api/chat/accounts/{id}/refresh-following', ChatController::class . '@refreshFollowing');
    Router::get('/api/chat/accounts/{id}/refresh-following/status', ChatController::class . '@refreshFollowingStatus');
    Router::get('/api/chat/accounts/{id}/stats', ChatController::class . '@accountStats');
    Router::get('/api/chat/search-user', ChatController::class . '@searchUser');
    Router::get('/api/chat/friends', ChatController::class . '@friends');
    Router::post('/api/chat/friends', ChatController::class . '@createFriend');
    Router::patch('/api/chat/friends/{id}', ChatController::class . '@patchFriend');
    Router::delete('/api/chat/friends/{id}', ChatController::class . '@deleteFriend');
    Router::post('/api/chat/friends/{id}/read', ChatController::class . '@readFriend');
    Router::post('/api/chat/friends/{id}/apply-translate', ChatController::class . '@applyTranslate');
    Router::post('/api/chat/friends/{id}/auto-check-lang', ChatController::class . '@autoCheckLang');
    Router::get('/api/chat/conversations', ChatController::class . '@conversations');
    Router::get('/api/chat/messages', ChatController::class . '@messages');
    Router::post('/api/chat/messages', ChatController::class . '@send');
    Router::post('/api/chat/messages/ai-reply', ChatController::class . '@aiReply');
    Router::post('/api/chat/messages/simulate-in', ChatController::class . '@simulateIn');
    Router::get('/api/chat/tags', ChatController::class . '@tags');
    Router::post('/api/chat/tags', ChatController::class . '@createTag');
    Router::delete('/api/chat/tags/{id}', ChatController::class . '@deleteTag');

    Router::get('/api/translate/config', TranslateController::class . '@config');
    Router::post('/api/translate', TranslateController::class . '@translate');
    Router::post('/api/translate/instant', TranslateController::class . '@instant');
    Router::post('/api/translate/detect', TranslateController::class . '@detect');

    Router::get('/api/menus/tree', MenuController::class . '@tree');
    Router::post('/api/menus/sync-permissions', MenuController::class . '@syncPermissions');
    Router::get('/api/menus', MenuController::class . '@list');
    Router::put('/api/menus/{id}', MenuController::class . '@updateById');
    Router::delete('/api/menus/{id}', MenuController::class . '@deleteById');

    Router::get('/api/accounts/stats', AccountController::class . '@stats');
    Router::get('/api/accounts/export', AccountController::class . '@export');
    Router::get('/api/accounts', AccountController::class . '@list');
    Router::post('/api/accounts/batch-import', AccountController::class . '@batchImport');
    Router::post('/api/accounts/batch-delete', AccountController::class . '@batchDelete');
    Router::post('/api/accounts/batch-delete-banned', AccountController::class . '@batchDeleteBanned');
    Router::post('/api/accounts/batch-move-group', AccountController::class . '@batchMoveGroup');
    Router::post('/api/accounts/batch-update', AccountController::class . '@batchUpdate');
    Router::post('/api/accounts/maintenance/clear-all-meta', AccountController::class . '@maintenanceClearAllMeta');
    Router::post('/api/accounts/proxy-bind-check', AccountController::class . '@proxyBindCheck');
    Router::post('/api/accounts/proxy-bind-check-batch', AccountController::class . '@proxyBindCheckBatch');
    Router::post('/api/accounts/batch-assign-owner', AccountController::class . '@batchAssignOwner');
    Router::post('/api/accounts/batch-assign-proxy', AccountController::class . '@batchAssignProxy');
    Router::post('/api/accounts/batch-set-proxy-string', AccountController::class . '@batchSetProxyString');
    Router::post('/api/accounts/batch-calibrate-country', AccountController::class . '@batchCalibrateCountry');
    Router::get('/api/accounts/monitor', AccountController::class . '@monitor');
    Router::put('/api/accounts/monitor', AccountController::class . '@saveMonitor');
    Router::post('/api/accounts', AccountController::class . '@create');
    Router::get('/api/accounts/{id}', AccountController::class . '@show');
    Router::put('/api/accounts/{id}', AccountController::class . '@update');
    Router::put('/api/accounts/{id}/secrets', AccountController::class . '@secrets');
    Router::patch('/api/accounts/{id}/status', AccountController::class . '@patchStatus');
    Router::patch('/api/accounts/{id}/notes', AccountController::class . '@patchNotes');
    Router::patch('/api/accounts/{id}/nickname', AccountController::class . '@patchNickname');
    Router::patch('/api/accounts/{id}/profile-settings', AccountController::class . '@patchProfileSettings');
    Router::patch('/api/accounts/{id}/profile-outcome', AccountController::class . '@patchProfileOutcome');
    Router::get('/api/accounts/{id}/profile-change-logs', AccountController::class . '@profileChangeLogs');
    Router::get('/api/accounts/{id}/tiktok-profile', AccountController::class . '@tiktokProfile');
    Router::get('/api/accounts/{id}/tiktok-videos', AccountController::class . '@tiktokVideos');
    Router::get('/api/accounts/{id}/tiktok-stats', AccountController::class . '@tiktokStats');
    Router::post('/api/accounts/{id}/send-data-analysis-mail', AccountController::class . '@sendDataAnalysisMail');
    Router::get('/api/accounts/{id}/notifications', AccountController::class . '@notifications');
    Router::get('/api/accounts/{id}/notifications/{noticeId}', AccountController::class . '@notificationDetail');
    Router::patch('/api/accounts/{id}/ban-status', AccountController::class . '@patchBanStatus');
    Router::post('/api/accounts/{id}/ban-assess', AccountController::class . '@banAssess');
    Router::post('/api/accounts/{id}/diagnose', AccountController::class . '@triggerDiagnose');
    Router::get('/api/accounts/{id}/diagnosis-records', AccountController::class . '@listDiagnosisRecords');
    Router::post('/api/accounts/{id}/diagnosis-records', AccountController::class . '@saveDiagnosisRecord');
    Router::get('/api/accounts/{id}/session', AccountController::class . '@getSession');
    Router::put('/api/accounts/{id}/session', AccountController::class . '@saveSession');
    Router::post('/api/accounts/{id}/capcut-authorize', AccountController::class . '@capcutAuthorize');
    Router::post('/api/accounts/{id}/capcut-revoke', AccountController::class . '@capcutRevoke');
    Router::post('/api/accounts/{id}/socks', AccountController::class . '@bindSocks');
    Router::delete('/api/accounts/{id}', AccountController::class . '@delete');

    Router::get('/api/proxies/geo', ProxyController::class . '@geo');
    Router::get('/api/proxies', ProxyController::class . '@list');
    Router::get('/api/proxies/export.csv', ProxyController::class . '@exportCsv');
    Router::get('/api/proxies/import-template.csv', ProxyController::class . '@importTemplate');
    Router::post('/api/proxies/import', ProxyController::class . '@import');
    Router::post('/api/proxies/batch-import', ProxyController::class . '@batchImport');
    Router::post('/api/proxies/batch-move', ProxyController::class . '@batchMove');
    Router::post('/api/proxies/batch-delete', ProxyController::class . '@batchDelete');
    Router::post('/api/proxies/batch-assign-owner', ProxyController::class . '@batchAssignOwner');
    Router::post('/api/proxies/batch-unassign-owner', ProxyController::class . '@batchUnassignOwner');
    Router::post('/api/proxies/advanced-delete', ProxyController::class . '@advancedDelete');
    Router::post('/api/proxies/batch-test', ProxyController::class . '@batchTest');
    Router::post('/api/proxies/export-strings', ProxyController::class . '@exportStrings');
    Router::post('/api/proxies/fetch', ProxyController::class . '@fetch');
    Router::post('/api/proxies', ProxyController::class . '@create');
    Router::put('/api/proxies/{id}', ProxyController::class . '@update');
    Router::get('/api/proxies/{id}/accounts', ProxyController::class . '@accounts');
    Router::post('/api/proxies/{id}/bind-accounts', ProxyController::class . '@bindAccounts');
    Router::post('/api/proxies/{id}/unbind-accounts', ProxyController::class . '@unbindAccounts');
    Router::post('/api/proxies/{id}/test', ProxyController::class . '@test');
    Router::delete('/api/proxies/{id}', ProxyController::class . '@delete');

    Router::get('/api/tasks/export.csv', TaskController::class . '@exportCsv');
    Router::get('/api/tasks/import-template.csv', TaskController::class . '@importTemplate');
    Router::post('/api/tasks/import', TaskController::class . '@import');
    Router::post('/api/tasks/batch-retry', TaskController::class . '@batchRetry');
    Router::post('/api/tasks/batch-action', TaskController::class . '@batchAction');
    Router::post('/api/tasks/batch-delete', TaskController::class . '@batchDelete');
    Router::get('/api/tasks', TaskController::class . '@list');
    Router::post('/api/tasks', TaskController::class . '@create');
    Router::put('/api/tasks/{id}', TaskController::class . '@update');
    Router::delete('/api/tasks/{id}', TaskController::class . '@delete');
    Router::post('/api/tasks/{id}/start', TaskController::class . '@start');
    Router::post('/api/tasks/{id}/pause', TaskController::class . '@pause');
    Router::post('/api/tasks/{id}/cancel', TaskController::class . '@cancel');
    Router::post('/api/tasks/{id}/retry', TaskController::class . '@retry');
    Router::post('/api/tasks/{id}/preflight', TaskController::class . '@preflight');
    Router::post('/api/tasks/{id}/prepare', TaskController::class . '@prepare');
    Router::get('/api/tasks/{id}/plans', TaskController::class . '@plans');
    Router::post('/api/tasks/{id}/plans/{planId}/confirm', TaskController::class . '@confirmPlan');
    Router::get('/api/tasks/{id}/preview', TaskController::class . '@preview');
    Router::get('/api/tasks/{id}/events', TaskController::class . '@events');
    Router::get('/api/tasks/{id}/runs', TaskController::class . '@runs');
    Router::get('/api/tasks/{id}/results', TaskController::class . '@results');
    Router::get('/api/tasks/{id}/items', TaskController::class . '@items');
    Router::post('/api/tasks/{id}/items/retry-failed', TaskController::class . '@retryFailedItems');
    Router::post('/api/tasks/{id}/items/batch-update', TaskController::class . '@batchUpdateItems');
    Router::put('/api/tasks/{id}/items/{itemId}', TaskController::class . '@updateItem');

    Router::get('/api/account-work-batches', AccountWorkController::class . '@listBatches');
    Router::get('/api/account-work-batches/{id}/items', AccountWorkController::class . '@batchItems');
    Router::get('/api/account-work-batches/{id}/logs', AccountWorkController::class . '@batchLogs');
    Router::get('/api/account-work-batches/{id}/items/{itemId}/logs', AccountWorkController::class . '@itemLogs');
    Router::post('/api/account-work-batches/enqueue', AccountWorkController::class . '@enqueue');
    Router::post('/api/account-work-batches/clear', AccountWorkController::class . '@clearBatches');
    Router::post('/api/account-work-batches/{id}/pause', AccountWorkController::class . '@pauseBatch');
    Router::post('/api/account-work-batches/{id}/resume', AccountWorkController::class . '@resumeBatch');
    Router::post('/api/account-work-batches/{id}/reset', AccountWorkController::class . '@resetBatch');
    Router::post('/api/account-work-batches/{id}/delete', AccountWorkController::class . '@deleteBatch');
    Router::post('/api/account-work-batches/{id}/force-fail', AccountWorkController::class . '@forceFailBatch');
    Router::post('/api/account-work-batches/{id}/items/{itemId}/pause', AccountWorkController::class . '@pauseItem');
    Router::post('/api/account-work-batches/{id}/items/{itemId}/resume', AccountWorkController::class . '@resumeItem');
    Router::post('/api/account-work-batches/{id}/items/{itemId}/reset', AccountWorkController::class . '@resetItem');

    Router::get('/api/pull-comment-tasks', PullCommentController::class . '@list');
    Router::post('/api/pull-comment-tasks', PullCommentController::class . '@create');
    Router::get('/api/pull-comment-tasks/published-materials', PullCommentController::class . '@publishedMaterials');
    Router::get('/api/pull-comment-tasks/{id}', PullCommentController::class . '@detail');
    Router::put('/api/pull-comment-tasks/{id}', PullCommentController::class . '@update');
    Router::post('/api/pull-comment-tasks/{id}/cancel', PullCommentController::class . '@cancel');
    Router::delete('/api/pull-comment-tasks/{id}', PullCommentController::class . '@delete');
    Router::get('/api/materials/{id}/detail', PullCommentController::class . '@materialDetail');

    Router::get('/api/materials', MaterialController::class . '@list');
    Router::post('/api/materials', MaterialController::class . '@create');
    Router::post('/api/materials/upload', MaterialController::class . '@upload');
    Router::post('/api/materials/check-size', MaterialController::class . '@checkSizeDuplicates');
    Router::post('/api/materials/check-folder', MaterialController::class . '@checkFolder');
    Router::post('/api/materials/import-folder', MaterialController::class . '@importFolder');
    Router::get('/api/materials/import-template', MaterialController::class . '@importTemplate');
    Router::post('/api/materials/import-zip', MaterialController::class . '@importZip');
    Router::get('/api/materials/{id}/stream', MaterialController::class . '@stream');
    Router::put('/api/materials/{id}', MaterialController::class . '@update');
    Router::post('/api/materials/{id}/mark-published', MaterialController::class . '@markPublished');
    Router::put('/api/materials/{id}/rename', MaterialController::class . '@rename');
    Router::put('/api/materials/{id}/cover', MaterialController::class . '@setCover');
    Router::post('/api/materials/{id}/check', MaterialController::class . '@check');
    Router::delete('/api/materials/{id}', MaterialController::class . '@delete');

    Router::get('/api/agents/templates', AgentController::class . '@templates');
    Router::get('/api/agents', AgentController::class . '@list');
    Router::post('/api/agents', AgentController::class . '@create');
    Router::post('/api/agents/batch-delete', AgentController::class . '@batchDelete');
    Router::get('/api/agents/{id}', AgentController::class . '@detail');
    Router::put('/api/agents/{id}', AgentController::class . '@update');
    Router::patch('/api/agents/{id}/status', AgentController::class . '@patchStatus');
    Router::post('/api/agents/{id}/test-chat', AgentController::class . '@testChat');
    Router::delete('/api/agents/{id}', AgentController::class . '@delete');

    // AI 开放平台
    Router::get('/api/ai-platforms', AiPlatformController::class . '@platforms');
    Router::get('/api/ai-platforms/active', AiPlatformController::class . '@active');
    Router::post('/api/ai-platforms/test', AiPlatformController::class . '@test');
    Router::get('/api/ai-platforms/{id}', AiPlatformController::class . '@platformDetail');
    Router::put('/api/ai-platforms/{id}', AiPlatformController::class . '@updatePlatform');
    Router::get('/api/ai-platforms/{id}/configs', AiPlatformController::class . '@configs');
    Router::get('/api/ai-platforms/{id}/models', AiPlatformController::class . '@models');
    Router::post('/api/ai-platform-models', AiPlatformController::class . '@createModel');
    Router::put('/api/ai-platform-models/{id}', AiPlatformController::class . '@updateModel');
    Router::patch('/api/ai-platform-models/{id}/status', AiPlatformController::class . '@patchModelStatus');
    Router::delete('/api/ai-platform-models/{id}', AiPlatformController::class . '@deleteModel');
    Router::post('/api/ai-platform-configs', AiPlatformController::class . '@createConfig');
    Router::put('/api/ai-platform-configs/{id}', AiPlatformController::class . '@updateConfig');
    Router::post('/api/ai-platform-configs/{id}/activate', AiPlatformController::class . '@activateConfig');
    Router::delete('/api/ai-platform-configs/{id}', AiPlatformController::class . '@deleteConfig');

    // AI 提示词模板
    Router::get('/api/ai-prompt-templates', AiPromptTemplateController::class . '@list');
    Router::post('/api/ai-prompt-templates', AiPromptTemplateController::class . '@create');
    Router::post('/api/ai-prompt-templates/parse-variables', AiPromptTemplateController::class . '@parseVariables');
    Router::get('/api/ai-prompt-templates/{id}', AiPromptTemplateController::class . '@detail');
    Router::put('/api/ai-prompt-templates/{id}', AiPromptTemplateController::class . '@update');
    Router::patch('/api/ai-prompt-templates/{id}/status', AiPromptTemplateController::class . '@patchStatus');
    Router::post('/api/ai-prompt-templates/{id}/render', AiPromptTemplateController::class . '@render');
    Router::delete('/api/ai-prompt-templates/{id}', AiPromptTemplateController::class . '@delete');

    // AI 调用记录
    Router::get('/api/ai-call-logs', AiCallLogController::class . '@list');
    Router::post('/api/ai-call-logs/batch-delete', AiCallLogController::class . '@batchDelete');
    Router::post('/api/ai-call-logs/clear', AiCallLogController::class . '@clear');
    Router::get('/api/ai-call-logs/{id}', AiCallLogController::class . '@detail');
    Router::delete('/api/ai-call-logs/{id}', AiCallLogController::class . '@delete');

    Router::get('/api/permissions', PermissionController::class . '@permissions');
    Router::post('/api/permissions', PermissionController::class . '@savePermission');
    Router::post('/api/permissions/delete', PermissionController::class . '@deletePermission');
    Router::delete('/api/permissions', PermissionController::class . '@deletePermission');

    Router::get('/api/roles', PermissionController::class . '@roles');
    Router::post('/api/roles', PermissionController::class . '@saveRole');
    Router::post('/api/roles/delete', PermissionController::class . '@deleteRole');
    Router::delete('/api/roles', PermissionController::class . '@deleteRole');

    Router::get('/api/admin-users', PermissionController::class . '@users');
    Router::post('/api/admin-users', PermissionController::class . '@saveUser');
    Router::post('/api/admin-users/delete', PermissionController::class . '@deleteUser');
    Router::delete('/api/admin-users', PermissionController::class . '@deleteUser');

    Router::get('/api/admin-logs', PermissionController::class . '@logs');
    Router::post('/api/admin-logs/clear', PermissionController::class . '@clearLogs');

    Router::get('/api/country-data/options', CountryDataController::class . '@options');
    Router::get('/api/country-data', CountryDataController::class . '@list');
    Router::post('/api/country-data/seed', CountryDataController::class . '@reseed');
    Router::get('/api/country-data/{id}', CountryDataController::class . '@show');
    Router::post('/api/country-data', CountryDataController::class . '@create');
    Router::put('/api/country-data/{id}', CountryDataController::class . '@update');
    Router::delete('/api/country-data/{id}', CountryDataController::class . '@delete');

    Router::get('/api/industry-data', IndustryDataController::class . '@list');
    Router::get('/api/industry-data/parents', IndustryDataController::class . '@parents');
    Router::post('/api/industry-data/seed', IndustryDataController::class . '@reseed');
    Router::get('/api/industry-data/{id}', IndustryDataController::class . '@show');
    Router::post('/api/industry-data', IndustryDataController::class . '@create');
    Router::put('/api/industry-data/{id}', IndustryDataController::class . '@update');
    Router::delete('/api/industry-data/{id}', IndustryDataController::class . '@delete');

    Router::get('/api/companies', CompanyController::class . '@list');
    Router::post('/api/companies', CompanyController::class . '@create');
    Router::get('/api/companies/{id}', CompanyController::class . '@show');
    Router::put('/api/companies/{id}', CompanyController::class . '@update');
    Router::delete('/api/companies/{id}', CompanyController::class . '@delete');

    Router::post('/api/menus', MenuController::class . '@save');
    Router::post('/api/menus/delete', MenuController::class . '@delete');
    Router::delete('/api/menus', MenuController::class . '@delete');

    Router::get('/api/client-versions', ClientVersionController::class . '@list');
    Router::post('/api/client-versions', ClientVersionController::class . '@create');
    Router::put('/api/client-versions/{id}', ClientVersionController::class . '@update');
    Router::post('/api/client-versions/{id}/upload', ClientVersionController::class . '@upload');
    Router::post('/api/client-versions/{id}/publish', ClientVersionController::class . '@publish');
    Router::post('/api/client-versions/{id}/latest', ClientVersionController::class . '@setLatest');
    Router::delete('/api/client-versions/{id}', ClientVersionController::class . '@delete');

    // 帮助文档管理
    Router::get('/api/help-categories', HelpDocController::class . '@listCategories');
    Router::post('/api/help-categories', HelpDocController::class . '@createCategory');
    Router::put('/api/help-categories/{id}', HelpDocController::class . '@updateCategory');
    Router::delete('/api/help-categories/{id}', HelpDocController::class . '@deleteCategory');
    Router::get('/api/help-documents', HelpDocController::class . '@listDocuments');
    Router::post('/api/help-documents', HelpDocController::class . '@createDocument');
    Router::put('/api/help-documents/{id}', HelpDocController::class . '@updateDocument');
    Router::delete('/api/help-documents/{id}', HelpDocController::class . '@deleteDocument');

    // 工单处理
    Router::get('/api/tickets', TicketController::class . '@list');
    Router::get('/api/tickets/{id}', TicketController::class . '@detail');
    Router::put('/api/tickets/{id}', TicketController::class . '@update');
    Router::delete('/api/tickets/{id}', TicketController::class . '@delete');

    // 客服端 Node 无 MySQL：自动化读写中心库
    Router::get('/api/node/settings', NodeBridgeController::class . '@settingsForNode');
    Router::get('/api/node/monitor/config', NodeBridgeController::class . '@monitorConfig');
    Router::get('/api/node/materials/{id}', NodeBridgeController::class . '@materialForNode');
    Router::post('/api/node/materials/{id}/mark-published', NodeBridgeController::class . '@markMaterialPublished');
    Router::get('/api/node/accounts/bindings', NodeBridgeController::class . '@accountBindings');
    Router::get('/api/node/accounts/by-profile/{profileId}', NodeBridgeController::class . '@accountByProfile');
    Router::get('/api/node/browser-profiles/{profileId}/proxy-bundle', NodeBridgeController::class . '@profileProxyBundle');
    Router::get('/api/node/accounts/{id}/bundle', NodeBridgeController::class . '@accountBundle');
    Router::post('/api/node/accounts/{id}/browser-binding', NodeBridgeController::class . '@browserBinding');
    Router::post('/api/node/accounts/{id}/fingerprint', NodeBridgeController::class . '@fingerprint');
    Router::post('/api/node/accounts/{id}/tiktok-sync', NodeBridgeController::class . '@tiktokSync');
    Router::post('/api/node/accounts/{id}/tiktok-analytics-sync', NodeBridgeController::class . '@tiktokAnalyticsSync');
    Router::post('/api/node/accounts/{id}/notifications-sync', NodeBridgeController::class . '@notificationsSync');
    Router::post('/api/node/maintenance/restore-bans', NodeBridgeController::class . '@maintenanceRestoreBans');
    Router::post('/api/node/accounts/{id}/tiktok-videos/delete', NodeBridgeController::class . '@deleteTikTokVideo');
    Router::post('/api/node/accounts/{id}/diagnosis-result', NodeBridgeController::class . '@diagnosisResult');
    Router::post('/api/node/accounts/qr-complete', NodeBridgeController::class . '@qrComplete');
    Router::post('/api/node/monitor/result', NodeBridgeController::class . '@monitorResult');
    Router::post('/api/node/chat/dm-ingest', NodeBridgeController::class . '@dmIngest');
    Router::post('/api/node/chat/bit-send-context', NodeBridgeController::class . '@bitSendContext');
    Router::post('/api/node/chat/bit-send-result', NodeBridgeController::class . '@bitSendResult');
    Router::get('/api/node/proxies/{id}/bundle', NodeBridgeController::class . '@proxyBundle');
    Router::post('/api/node/proxies/{id}/probe-result', NodeBridgeController::class . '@proxyProbeResult');
    Router::post('/api/node/work-logs', NodeBridgeController::class . '@appendWorkLog');
    Router::post('/api/node/accounts/{id}/profile-change-log', NodeBridgeController::class . '@profileChangeLog');
    Router::post('/api/node/tasks/claim', NodeBridgeController::class . '@claimTask');
    Router::post('/api/node/tasks/{id}/account-result', NodeBridgeController::class . '@taskAccountResult');
    Router::post('/api/node/tasks/{id}/finish', NodeBridgeController::class . '@finishTask');
    Router::get('/api/node/tasks/{id}/items/{itemId}', NodeBridgeController::class . '@taskItem');
    Router::post('/api/node/tasks/{id}/items/{itemId}/status', NodeBridgeController::class . '@taskItemStatus');
    Router::post('/api/node/tasks/{id}/items/{itemId}/result', NodeBridgeController::class . '@taskItemResult');
    Router::post('/api/node/tasks/{id}/items/{itemId}/confirm-published', NodeBridgeController::class . '@confirmPublished');
    Router::post('/api/node/work-batches/claim', NodeBridgeController::class . '@claimWorkBatch');
    Router::post('/api/node/work-batches/claim-one', NodeBridgeController::class . '@claimSpecificWorkBatch');
    Router::post('/api/node/work-batches/reclaim', NodeBridgeController::class . '@reclaimWorkBatches');
    Router::post('/api/node/work-batches/enqueue', NodeBridgeController::class . '@enqueueWorkItem');
    Router::post('/api/node/work-batches/{id}/heartbeat', NodeBridgeController::class . '@workBatchHeartbeat');
    Router::post('/api/node/work-batches/{id}/claim-item', NodeBridgeController::class . '@claimWorkItem');
    Router::post('/api/node/work-batches/{id}/items/{itemId}/result', NodeBridgeController::class . '@workItemResult');
    Router::post('/api/node/work-batches/{id}/finish', NodeBridgeController::class . '@finishWorkBatch');
    Router::post('/api/node/work-batches/{id}/release', NodeBridgeController::class . '@releaseWorkBatch');
    Router::post('/api/node/work-batches/{id}/force-fail', NodeBridgeController::class . '@forceFailWorkBatch');
    Router::post('/api/node/work-batches/{id}/mark-executing', NodeBridgeController::class . '@markWorkBatchExecuting');
    Router::post('/api/node/warm/ai-comment', NodeBridgeController::class . '@warmAiComment');
    Router::post('/api/node/comment-reply/ai', NodeBridgeController::class . '@commentReplyAi');
    Router::post('/api/node/ai-leads/filter-comments', NodeBridgeController::class . '@aiLeadFilterComments');
    Router::post('/api/node/ai-leads/generate-dm', NodeBridgeController::class . '@aiLeadGenerateDm');
    Router::post('/api/node/ai-leads/{id}/creators', NodeBridgeController::class . '@aiLeadUpsertCreator');
    Router::post('/api/node/ai-leads/{id}/videos', NodeBridgeController::class . '@aiLeadUpsertVideo');
    Router::post('/api/node/ai-leads/{id}/comments', NodeBridgeController::class . '@aiLeadSaveComments');
    Router::post('/api/node/ai-leads/{id}/progress', NodeBridgeController::class . '@aiLeadProgress');
    Router::post('/api/node/ai-leads/{id}/finish', NodeBridgeController::class . '@aiLeadFinish');
    Router::get('/api/node/ai-leads/{id}/dm-plan', NodeBridgeController::class . '@aiLeadDmPlan');
    Router::post('/api/node/ai-leads/{id}/dm-plan', NodeBridgeController::class . '@aiLeadDmPlan');
    Router::post('/api/node/ai-leads/{id}/dm-records', NodeBridgeController::class . '@aiLeadDmRecord');
    Router::post('/api/node/ai-leads/{id}/dm-already-sent', NodeBridgeController::class . '@aiLeadDmAlreadySent');
    Router::post('/api/node/pull-comment-tasks/{id}/start', NodeBridgeController::class . '@pullCommentStart');
    Router::post('/api/node/pull-comment-tasks/{id}/ingest', NodeBridgeController::class . '@pullCommentIngest');
    Router::post('/api/node/pull-comment-tasks/{id}/finish', NodeBridgeController::class . '@pullCommentFinish');
    Router::post('/api/node/auto-follow-items/{id}/finish', NodeBridgeController::class . '@autoFollowItemFinish');

    Router::get('/admin/rbac', PermissionController::class . '@index');
    Router::get('/admin/permissions/data', PermissionController::class . '@permissions');
    Router::get('/admin/roles/data', PermissionController::class . '@roles');
    Router::post('/admin/roles/save', PermissionController::class . '@saveRole');
    Router::get('/admin/users/data', PermissionController::class . '@users');
    Router::get('/admin/logs/data', PermissionController::class . '@logs');

    // 运营控制台专用 API（多控制器 / 多方法）
    Router::get('/admin/api/overview', ConsoleController::class . '@overview');
    Router::get('/admin/api/site-settings', ConsoleController::class . '@siteSettings');
    Router::put('/admin/api/site-settings', ConsoleController::class . '@saveSiteSettings');

    Router::get('/admin/api/tickets/stats', TicketManageController::class . '@stats');
    Router::get('/admin/api/tickets', TicketManageController::class . '@list');
    Router::get('/admin/api/tickets/{id}', TicketManageController::class . '@detail');
    Router::put('/admin/api/tickets/{id}', TicketManageController::class . '@update');
    Router::delete('/admin/api/tickets/{id}', TicketManageController::class . '@delete');

    Router::get('/admin/api/contact-inquiries/stats', ContactManageController::class . '@stats');
    Router::get('/admin/api/contact-inquiries', ContactManageController::class . '@list');
    Router::get('/admin/api/contact-inquiries/{id}', ContactManageController::class . '@detail');
    Router::put('/admin/api/contact-inquiries/{id}', ContactManageController::class . '@update');
    Router::delete('/admin/api/contact-inquiries/{id}', ContactManageController::class . '@delete');

    Router::get('/admin/api/versions/stats', VersionManageController::class . '@stats');
    Router::get('/admin/api/versions', VersionManageController::class . '@list');
    Router::post('/admin/api/versions', VersionManageController::class . '@create');
    Router::put('/admin/api/versions/{id}', VersionManageController::class . '@update');
    Router::post('/admin/api/versions/{id}/upload', VersionManageController::class . '@upload');
    Router::post('/admin/api/versions/{id}/publish', VersionManageController::class . '@publish');
    Router::post('/admin/api/versions/{id}/latest', VersionManageController::class . '@setLatest');
    Router::delete('/admin/api/versions/{id}', VersionManageController::class . '@delete');

    Router::get('/admin/api/help/stats', HelpManageController::class . '@stats');
    Router::get('/admin/api/help/categories', HelpManageController::class . '@listCategories');
    Router::post('/admin/api/help/categories', HelpManageController::class . '@createCategory');
    Router::put('/admin/api/help/categories/{id}', HelpManageController::class . '@updateCategory');
    Router::delete('/admin/api/help/categories/{id}', HelpManageController::class . '@deleteCategory');
    Router::get('/admin/api/help/documents', HelpManageController::class . '@listDocuments');
    Router::post('/admin/api/help/documents', HelpManageController::class . '@createDocument');
    Router::put('/admin/api/help/documents/{id}', HelpManageController::class . '@updateDocument');
    Router::delete('/admin/api/help/documents/{id}', HelpManageController::class . '@deleteDocument');

    Router::get('/admin/api/env-installers', EnvInstallerManageController::class . '@list');
    Router::post('/admin/api/env-installers', EnvInstallerManageController::class . '@create');
    Router::put('/admin/api/env-installers/{id}', EnvInstallerManageController::class . '@update');
    Router::post('/admin/api/env-installers/{id}/upload', EnvInstallerManageController::class . '@upload');
    Router::post('/admin/api/env-installers/{id}/publish', EnvInstallerManageController::class . '@publish');
    Router::post('/admin/api/env-installers/{id}/recommended', EnvInstallerManageController::class . '@setRecommended');
    Router::delete('/admin/api/env-installers/{id}', EnvInstallerManageController::class . '@delete');
});
