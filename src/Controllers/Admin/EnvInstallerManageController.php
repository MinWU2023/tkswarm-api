<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Controllers\Api\EnvInstallerController;

/**
 * 环境安装包管理（Node / 比特 · Win / Mac）
 */
class EnvInstallerManageController extends Controller
{
    private function api(): EnvInstallerController
    {
        return new EnvInstallerController();
    }

    public function list(Request $request): Response
    {
        return $this->api()->list($request);
    }

    public function create(Request $request): Response
    {
        return $this->api()->create($request);
    }

    public function update(Request $request, string $id): Response
    {
        return $this->api()->update($request, $id);
    }

    public function upload(Request $request, string $id): Response
    {
        return $this->api()->upload($request, $id);
    }

    public function publish(Request $request, string $id): Response
    {
        return $this->api()->publish($request, $id);
    }

    public function setRecommended(Request $request, string $id): Response
    {
        return $this->api()->setRecommended($request, $id);
    }

    public function delete(Request $request, string $id): Response
    {
        return $this->api()->delete($request, $id);
    }
}
