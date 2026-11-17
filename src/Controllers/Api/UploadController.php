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
        $dir = $this->uploadsDir();
        if ($dir === null) {
            return $this->error('无法创建上传目录', 500);
        }

        $files = $this->collectUploadedFiles();
        if (!$files) {
            $files = $this->collectBase64Files($request);
        }
        if (!$files) {
            return $this->error('请选择文件', 400);
        }

        $imageExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        $videoExt = ['mp4'];
        $items = [];

        foreach (array_slice($files, 0, 35) as $file) {
            $err = (int)($file['error'] ?? UPLOAD_ERR_OK);
            if ($err !== UPLOAD_ERR_OK) {
                continue;
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
                @unlink($dest);
            }

            $moved = false;
            $tmp = (string)($file['tmp_name'] ?? '');
            $raw = $file['bytes'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $moved = @file_put_contents($dest, $raw) !== false;
            } elseif ($tmp !== '' && is_uploaded_file($tmp)) {
                $moved = @move_uploaded_file($tmp, $dest);
            } elseif ($tmp !== '' && is_file($tmp)) {
                $moved = @rename($tmp, $dest) || @copy($tmp, $dest);
            }

            if (!$moved || !is_file($dest)) {
                return $this->error('保存文件失败', 500);
            }
            @chmod($dest, 0644);

            $items[] = [
                'url' => '/uploads/' . $filename,
                'mediaUrl' => '/api/media/' . rawurlencode($filename),
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

    /**
     * 公开读上传文件（不走鉴权）。nginx 对 /uploads 异常时仍可用。
     */
    public function media(Request $request, string $file = ''): Response
    {
        $name = basename(rawurldecode(trim((string)$file)));
        if ($name === '' || !preg_match('/^[\w.\-]+$/', $name)) {
            return $this->error('文件不存在', 404);
        }
        $dir = $this->uploadsDir();
        if ($dir === null) {
            return $this->error('上传目录不可用', 500);
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            return $this->error('文件不存在', 404);
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            default => 'application/octet-stream',
        };
        $size = (int)(@filesize($path) ?: 0);
        return Response::file($path, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string)$size,
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function uploadsDir(): ?string
    {
        $dir = APP_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        return $dir;
    }

    /** @return list<array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int,bytes?:string}> */
    private function collectUploadedFiles(): array
    {
        $out = [];
        $bag = $_FILES['files'] ?? $_FILES['file'] ?? null;
        if (!$bag) {
            return [];
        }
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

    /**
     * Node sync：JSON `{ files:[{ name, contentBase64, type? }] }`，避免 multipart 在部分环境下 $_FILES 为空。
     * @return list<array{name:string,type:string,error:int,size:int,bytes:string}>
     */
    private function collectBase64Files(Request $request): array
    {
        $body = $request->json() ?? $request->all();
        $list = $body['files'] ?? null;
        if (!is_array($list) || !$list) {
            $one = $body['file'] ?? null;
            if (is_array($one)) {
                $list = [$one];
            } elseif (!empty($body['contentBase64']) || !empty($body['content_base64'])) {
                $list = [[
                    'name' => (string)($body['name'] ?? $body['fileName'] ?? 'file.bin'),
                    'contentBase64' => (string)($body['contentBase64'] ?? $body['content_base64'] ?? ''),
                    'type' => (string)($body['type'] ?? ''),
                ]];
            } else {
                return [];
            }
        }

        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = basename((string)($row['name'] ?? $row['fileName'] ?? 'file.bin'));
            $b64 = (string)($row['contentBase64'] ?? $row['content_base64'] ?? $row['data'] ?? '');
            $b64 = preg_replace('#^data:[^;]+;base64,#', '', trim($b64)) ?: '';
            if ($name === '' || $b64 === '') {
                continue;
            }
            $raw = base64_decode($b64, true);
            if ($raw === false || $raw === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'type' => (string)($row['type'] ?? ''),
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($raw),
                'bytes' => $raw,
            ];
        }
        return $out;
    }
}
