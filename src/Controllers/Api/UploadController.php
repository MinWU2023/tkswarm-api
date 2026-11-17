<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * 通用图片/短视频上传：落到 public/uploads，供 Node 与企业门户同源访问。
 */
class UploadController extends Controller
{
    public function images(Request $request): Response
    {
        $dir = APP_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->error('无法创建上传目录', 500);
        }

        $files = $this->collectUploadedFiles();
        if (!$files) {
            return $this->error('请选择文件', 400);
        }

        $imageExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        $videoExt = ['mp4'];
        $items = [];

        foreach (array_slice($files, 0, 35) as $file) {
            $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                // Node sync 可能走 curl 临时文件，允许 is_file
                if ($tmp === '' || !is_file($tmp)) {
                    continue;
                }
            }

            $orig = (string)($file['name'] ?? 'file.bin');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $mime = strtolower((string)($file['type'] ?? ''));
            $isImage = in_array($ext, $imageExt, true) || str_starts_with($mime, 'image/');
            $isVideo = in_array($ext, $videoExt, true) || str_contains($mime, 'mp4') || $mime === 'video/quicktime';
            if (!$isImage && !$isVideo) {
                return $this->error('仅支持 JPG/PNG/WEBP/GIF 图片，或 MP4 视频', 422);
            }
            if (!in_array($ext, array_merge($imageExt, $videoExt), true)) {
                $ext = $isVideo ? 'mp4' : 'jpg';
            }

            // 优先保留客户端给定文件名（Node 本机同步同名，便于两边路径一致）
            $preferred = basename(preg_replace('/[^\w.\-]+/', '_', $orig) ?: '');
            if ($preferred !== '' && preg_match('/^\d{10,}-[a-z0-9]+\.[a-z0-9]+$/i', $preferred)) {
                $filename = $preferred;
            } else {
                $filename = date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
            }
            $dest = $dir . DIRECTORY_SEPARATOR . $filename;
            if (is_file($dest)) {
                // 同名已存在则覆盖（同步场景）
                @unlink($dest);
            }
            $moved = @move_uploaded_file($tmp, $dest);
            if (!$moved) {
                $moved = @rename($tmp, $dest) || @copy($tmp, $dest);
            }
            if (!$moved || !is_file($dest)) {
                return $this->error('保存文件失败', 500);
            }
            @chmod($dest, 0644);

            $items[] = [
                'url' => '/uploads/' . $filename,
                'name' => $orig,
                'size' => (int)(@filesize($dest) ?: ($file['size'] ?? 0)),
                'type' => $isVideo ? 'video' : 'image',
            ];
        }

        if (!$items) {
            return $this->error('没有成功上传的文件', 400);
        }

        return $this->success(['items' => $items], '文件已上传');
    }

    /** @return list<array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}> */
    private function collectUploadedFiles(): array
    {
        $out = [];
        $bag = $_FILES['files'] ?? $_FILES['file'] ?? null;
        if (!$bag) {
            return [];
        }
        // 单文件
        if (!is_array($bag['name'] ?? null)) {
            return [$bag];
        }
        $n = count($bag['name']);
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'name' => $bag['name'][$i] ?? '',
                'type' => $bag['type'][$i] ?? '',
                'tmp_name' => $bag['tmp_name'][$i] ?? '',
                'error' => (int)($bag['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($bag['size'][$i] ?? 0),
            ];
        }
        return $out;
    }
}
