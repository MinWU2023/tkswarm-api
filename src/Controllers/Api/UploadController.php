<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * 通用图片/短视频上传。
 * 优先 public/uploads；不可写时回退 storage/uploads，统一经 /api/media 读取。
 */
class UploadController extends Controller
{
    public function images(Request $request): Response
    {
        [$dir, $dirError] = $this->resolveUploadsDir();
        if ($dir === null) {
            return $this->error($dirError ?: '无法创建上传目录（请检查 public/uploads 与 storage/uploads 写权限）', 500);
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

            $saveError = '';
            $moved = $this->writeUploadFile($file, $dest, $saveError);
            if (!$moved || !is_file($dest)) {
                $hint = $saveError !== '' ? $saveError : ('目录=' . $dir . ' writable=' . (is_writable($dir) ? '1' : '0'));
                return $this->error('保存文件失败：' . $hint, 500);
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

    /** 公开读上传文件（不鉴权） */
    public function media(Request $request, string $file = ''): Response
    {
        $name = basename(rawurldecode(trim((string)$file)));
        if ($name === '' || !preg_match('/^[\w.\-]+$/', $name)) {
            return $this->error('文件不存在', 404);
        }
        $path = $this->findUploadFile($name);
        if ($path === null) {
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

    /**
     * @return array{0:?string,1:string} [dir, error]
     */
    private function resolveUploadsDir(): array
    {
        $candidates = [
            APP_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads',
            APP_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads',
        ];
        $errors = [];
        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    $errors[] = $dir . ' 无法创建';
                    continue;
                }
            }
            if (!is_writable($dir)) {
                @chmod($dir, 0775);
            }
            if (is_dir($dir) && is_writable($dir)) {
                // 探针：确认 PHP 进程真能写
                $probe = $dir . DIRECTORY_SEPARATOR . '.write_probe_' . bin2hex(random_bytes(2));
                if (@file_put_contents($probe, '1') !== false) {
                    @unlink($probe);
                    return [$dir, ''];
                }
                $errors[] = $dir . ' 探针写入失败';
                continue;
            }
            $errors[] = $dir . ' 不可写';
        }
        return [null, implode('；', $errors)];
    }

    private function findUploadFile(string $name): ?string
    {
        foreach ([
            APP_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $name,
            APP_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $name,
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /** @param array{tmp_name?:string,bytes?:string} $file */
    private function writeUploadFile(array $file, string $dest, string &$error): bool
    {
        $tmp = (string)($file['tmp_name'] ?? '');
        $raw = $file['bytes'] ?? null;

        if (is_string($raw) && $raw !== '') {
            $n = @file_put_contents($dest, $raw, LOCK_EX);
            if ($n !== false) {
                return true;
            }
            $error = 'file_put_contents 失败';
            return false;
        }

        if ($tmp !== '' && is_uploaded_file($tmp)) {
            if (@move_uploaded_file($tmp, $dest)) {
                return true;
            }
            // 部分环境 move 失败但仍可读临时文件
            if (@copy($tmp, $dest) && is_file($dest)) {
                @unlink($tmp);
                return true;
            }
            $error = 'move_uploaded_file/copy 失败';
            return false;
        }

        if ($tmp !== '' && is_file($tmp)) {
            if (@rename($tmp, $dest) || (@copy($tmp, $dest) && is_file($dest))) {
                return true;
            }
            $error = 'rename/copy 临时文件失败';
            return false;
        }

        $error = '没有可用的文件内容';
        return false;
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
