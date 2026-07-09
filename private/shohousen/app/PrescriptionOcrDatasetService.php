<?php
declare(strict_types=1);

/**
 * OCR運用データ蓄積サービス。
 *
 * 画像そのもの、AI読取1回目/2回目、PHP検証後、人間修正後をJSONとして残す。
 * 外部SV転送はURL/トークン未設定なら行わず、まずは自社サーバー内のprivate storageと拠点DBへ保存する。
 */
final class PrescriptionOcrDatasetService
{
    /** @param array<string,mixed> $payload */
    public function saveEvent(
        int $tenantId,
        ?int $parseJobId,
        ?int $prescriptionId,
        string $eventType,
        array $payload,
        array $meta = []
    ): void {
        $payload = $this->wrapPayload($tenantId, $parseJobId, $prescriptionId, $eventType, $payload, $meta);
        $jsonPath = $this->writeJsonFile($tenantId, $parseJobId, $prescriptionId, $eventType, $payload);
        $this->insertDbEvent($tenantId, $parseJobId, $prescriptionId, $eventType, $payload, $jsonPath);
        $this->postToExternalServer($payload, $jsonPath);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function wrapPayload(int $tenantId, ?int $parseJobId, ?int $prescriptionId, string $eventType, array $payload, array $meta): array
    {
        return [
            'schema_version' => 'prescription_ocr_dataset_v1',
            'event_type' => $eventType,
            'company_uid' => current_company_uid(),
            'branch_uid' => current_branch_uid(),
            'tenant_id' => $tenantId,
            'parse_job_id' => $parseJobId,
            'prescription_id' => $prescriptionId,
            'captured_at' => date('c'),
            'meta' => $meta,
            'payload' => $payload,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function writeJsonFile(int $tenantId, ?int $parseJobId, ?int $prescriptionId, string $eventType, array $payload): string
    {
        $baseDir = rtrim((string)app_config('storage.prescription_ocr_dataset_dir', dirname(__DIR__) . '/storage/prescription_ocr_dataset'), '/');
        $dir = $baseDir . '/' . current_company_uid() . '/' . current_branch_uid() . '/' . date('Y/m');
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $safeEvent = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $eventType) ?: 'event';
        $filename = date('Ymd_His') . '_job' . (string)($parseJobId ?? 0) . '_rx' . (string)($prescriptionId ?? 0) . '_' . $safeEvent . '_' . bin2hex(random_bytes(4)) . '.json';
        $path = $dir . '/' . $filename;
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
        return $path;
    }

    /** @param array<string,mixed> $payload */
    private function insertDbEvent(int $tenantId, ?int $parseJobId, ?int $prescriptionId, string $eventType, array $payload, string $jsonPath): void
    {
        try {
            $pdo = Db::branch();
            if (!Db::tableExists($pdo, 'prescription_ocr_dataset_events')) {
                return;
            }
            $stmt = $pdo->prepare('INSERT INTO prescription_ocr_dataset_events
                (company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, event_type, payload_json, json_storage_path, created_at)
                VALUES (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :event_type, :payload_json, :json_storage_path, NOW())');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':parse_job_id' => $parseJobId,
                ':prescription_id' => $prescriptionId,
                ':event_type' => $eventType,
                ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ':json_storage_path' => $jsonPath,
            ]);
        } catch (Throwable) {
            // 蓄積失敗でOCR本処理は止めない。JSONファイル保存を主、DBは補助とする。
        }
    }

    /** @param array<string,mixed> $payload */
    private function postToExternalServer(array $payload, string $jsonPath): void
    {
        $endpoint = trim((string)app_config('prescription_ocr_dataset.endpoint_url', ''));
        $token = trim((string)app_config('prescription_ocr_dataset.token', ''));
        if ($endpoint === '') {
            return;
        }
        try {
            $ch = curl_init($endpoint);
            if ($ch === false) {
                return;
            }
            $headers = ['Content-Type: application/json'];
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
            $payload['_local_json_storage_path'] = $jsonPath;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_TIMEOUT => (int)app_config('prescription_ocr_dataset.timeout_seconds', 10),
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable) {
        }
    }
}
