<?php
declare(strict_types=1);

final class PrescriptionImagePreprocessor
{
    /** @return array{storage_file_id:int,path:string,mime_type:string,width:?int,height:?int,size:int,sha256:string,original_filename:string} */
    public function storeUploadedFile(array $file, array $user): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('処方箋画像のアップロードに失敗しました。');
        }
        $tmp = (string)$file['tmp_name'];
        $mime = self::detectMime($tmp);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('対応ファイルは JPG / PNG / WEBP です。PDFは画像化後に取り込んでください。');
        }

        $baseDir = rtrim((string)app_config('storage.prescription_dir', dirname(__DIR__) . '/storage/prescriptions'), '/');
        $companyUid = current_company_uid();
        $branchUid = current_branch_uid();
        $ym = date('Y/m');
        $dir = $baseDir . '/' . $companyUid . '/' . $branchUid . '/' . $ym;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('保存先フォルダを作成できません。');
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = $dir . '/' . $filename;
        if (!move_uploaded_file($tmp, $path)) {
            throw new RuntimeException('処方箋画像の保存に失敗しました。');
        }

        [$width, $height] = self::imageSize($path);
        $size = filesize($path) ?: 0;
        $sha = hash_file('sha256', $path) ?: '';
        $original = mb_substr((string)($file['name'] ?? ''), 0, 255);

        $stmt = Db::branch()->prepare('INSERT INTO storage_files
            (company_uid, branch_uid, tenant_id, file_type, original_filename, stored_path, mime_type, file_size_bytes, sha256_hash, created_by, created_at)
            VALUES (:company_uid, :branch_uid, :tenant_id, :file_type, :original_filename, :stored_path, :mime_type, :file_size_bytes, :sha256_hash, :created_by, NOW())');
        $stmt->execute([
            ':company_uid' => $companyUid,
            ':branch_uid' => $branchUid,
            ':tenant_id' => (int)$user['tenant_id'],
            ':file_type' => 'prescription_original',
            ':original_filename' => $original,
            ':stored_path' => $path,
            ':mime_type' => $mime,
            ':file_size_bytes' => $size,
            ':sha256_hash' => $sha,
            ':created_by' => (int)$user['id'],
        ]);

        return [
            'storage_file_id' => (int)Db::branch()->lastInsertId(),
            'path' => $path,
            'mime_type' => $mime,
            'width' => $width,
            'height' => $height,
            'size' => $size,
            'sha256' => $sha,
            'original_filename' => $original,
        ];
    }

    /** @return array{0:?int,1:?int} */
    public static function imageSize(string $path): array
    {
        $size = @getimagesize($path);
        if (!is_array($size)) {
            return [null, null];
        }
        return [(int)$size[0], (int)$size[1]];
    }

    public static function detectMime(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return (string)$finfo->file($path);
    }
}
