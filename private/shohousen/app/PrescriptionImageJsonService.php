<?php
declare(strict_types=1);

/**
 * 1画像につき1つの最終JSONドキュメントを拠点側private storageへ保存する。
 * 患者実値を含むため、補助学習DBではなくtenant/branch側だけで管理する。
 */
final class PrescriptionImageJsonService
{
    /**
     * @param array<string,mixed> $sourceImage
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public function save(int $tenantId, int $parseJobId, int $createdBy, array $sourceImage, array $document): array
    {
        $base = rtrim((string)app_config('storage.prescription_image_json_dir', dirname(__DIR__) . '/storage/prescription_image_json'), '/');
        $dir = $base . '/' . current_company_uid() . '/' . current_branch_uid() . '/' . date('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('画像単位JSON保存ディレクトリを作成できません。');
        }

        $imageHash = trim((string)($sourceImage['sha256'] ?? $sourceImage['sha256_hash'] ?? ''));
        if ($imageHash === '') {
            $path = (string)($sourceImage['path'] ?? $sourceImage['stored_path'] ?? '');
            $imageHash = is_file($path) ? (hash_file('sha256', $path) ?: '') : '';
        }
        $safeHash = $imageHash !== '' ? substr($imageHash, 0, 20) : bin2hex(random_bytes(10));
        $filename = sprintf('prescription_%d_%s.json', $parseJobId, $safeHash);
        $path = $dir . '/' . $filename;
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        $document['document_meta'] = [
            'schema_version' => 'prescription_one_image_one_json_v1',
            'parse_job_id' => $parseJobId,
            'tenant_id' => $tenantId,
            'company_uid' => current_company_uid(),
            'branch_uid' => current_branch_uid(),
            'source_image_sha256' => $imageHash,
            'created_at' => date(DATE_ATOM),
            'privacy_scope' => 'tenant_private_storage',
        ];
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            throw new RuntimeException('画像単位JSONの生成に失敗しました。');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('画像単位JSONの保存に失敗しました。');
        }
        @chmod($path, 0660);

        $storageFileId = 0;
        try {
            $pdo = Db::branch();
            $find = $pdo->prepare('SELECT id FROM storage_files
                WHERE tenant_id = :tenant_id AND stored_path = :stored_path AND deleted_at IS NULL
                ORDER BY id DESC LIMIT 1');
            $find->execute([':tenant_id' => $tenantId, ':stored_path' => $path]);
            $existingId = (int)($find->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $update = $pdo->prepare('UPDATE storage_files
                    SET file_size_bytes = :file_size_bytes, sha256_hash = :sha256_hash, original_filename = :original_filename
                    WHERE id = :id');
                $update->execute([
                    ':file_size_bytes' => filesize($path) ?: 0,
                    ':sha256_hash' => hash_file('sha256', $path) ?: '',
                    ':original_filename' => $filename,
                    ':id' => $existingId,
                ]);
                $storageFileId = $existingId;
            } else {
                $stmt = $pdo->prepare('INSERT INTO storage_files
                    (company_uid, branch_uid, tenant_id, file_type, original_filename, stored_path, mime_type, file_size_bytes, sha256_hash, created_by, created_at)
                    VALUES (:company_uid, :branch_uid, :tenant_id, "prescription_analysis_json", :original_filename, :stored_path, "application/json", :file_size_bytes, :sha256_hash, :created_by, NOW())');
                $stmt->execute([
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':tenant_id' => $tenantId,
                    ':original_filename' => $filename,
                    ':stored_path' => $path,
                    ':file_size_bytes' => filesize($path) ?: 0,
                    ':sha256_hash' => hash_file('sha256', $path) ?: '',
                    ':created_by' => $createdBy,
                ]);
                $storageFileId = (int)$pdo->lastInsertId();
            }
            if ($storageFileId > 0 && Db::tableExists($pdo, 'prescription_parse_job_files')) {
                $findLink = $pdo->prepare('SELECT id FROM prescription_parse_job_files
                    WHERE parse_job_id = :parse_job_id AND storage_file_id = :storage_file_id AND file_role = "analysis_json"
                    LIMIT 1');
                $findLink->execute([':parse_job_id' => $parseJobId, ':storage_file_id' => $storageFileId]);
                if (!(int)($findLink->fetchColumn() ?: 0)) {
                    $link = $pdo->prepare('INSERT INTO prescription_parse_job_files
                        (parse_job_id, storage_file_id, file_role, crop_field_key, width, height, created_at)
                        VALUES (:parse_job_id, :storage_file_id, "analysis_json", NULL, NULL, NULL, NOW())');
                    $link->execute([':parse_job_id' => $parseJobId, ':storage_file_id' => $storageFileId]);
                }
            }
        } catch (Throwable) {
            // ファイル保存を正とし、DBメタデータ登録失敗でOCR全体を止めない。
        }

        return [
            'path' => $path,
            'storage_file_id' => $storageFileId,
            'sha256' => hash_file('sha256', $path) ?: '',
            'size' => filesize($path) ?: 0,
            'schema_version' => 'prescription_one_image_one_json_v1',
        ];
    }
}
