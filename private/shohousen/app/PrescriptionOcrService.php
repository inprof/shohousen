<?php
declare(strict_types=1);

final class PrescriptionOcrAnalyzeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $jobId,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

final class PrescriptionOcrService
{
    public function __construct(
        private readonly PrescriptionImagePreprocessor $image = new PrescriptionImagePreprocessor(),
        private readonly OpenAiPrescriptionClient $openai = new OpenAiPrescriptionClient(),
    ) {}

    /**
     * 最小解析モード。
     *
     * 目的:
     * - 補助学習DBの参照/保存を行わない。
     * - テンプレート候補保存、補正候補保存、AI表示マッピング、再解析テストを行わない。
     * - 画像OCRとOCR結果からの処方箋項目JSON化だけを行い、4段階比較用データを拠点DBのIO診断へ保存する。
     */
    public function analyzeUploaded(array $file, array $user, string $sourceType, ?string $modelTier = null): int
    {
        $tenantId = (int)$user['tenant_id'];
        $companyUid = current_company_uid();
        $branchUid = current_branch_uid();
        $modelTier = OpenAiPrescriptionClient::normalizeModelTier($modelTier);
        $modelSummary = OpenAiPrescriptionClient::modelTierSummary($modelTier);
        $modelSummaryText = $modelSummary['summary'];
        $openaiClient = $this->openai->withModelTier($modelTier);
        $start = microtime(true);

        $preprocessStart = microtime(true);
        $stored = $this->image->storeUploadedFile($file, $user);
        $imageQuality = $this->image->analyzeStoredFile($stored);
        $ocrStored = $stored;
        $assistStored = null;
        try {
            $assistStored = $this->image->createOcrAssistImage($stored, $user, $imageQuality);
            if (is_array($assistStored) && !empty($assistStored['path'])) {
                $ocrStored = $assistStored;
            }
        } catch (Throwable) {
            $assistStored = null;
            $ocrStored = $stored;
        }
        $preprocessMs = self::elapsedMs($preprocessStart);

        $pdo = Db::branch();
        $stmt = $pdo->prepare('INSERT INTO prescription_parse_jobs
            (company_uid, branch_uid, tenant_id, status, source_type, original_storage_file_id, model_name, prompt_version, schema_version, created_by, created_at)
            VALUES (:company_uid, :branch_uid, :tenant_id, "uploaded", :source_type, :storage_file_id, :model_name, :prompt_version, :schema_version, :created_by, NOW())');
        $stmt->execute([
            ':company_uid' => $companyUid,
            ':branch_uid' => $branchUid,
            ':tenant_id' => $tenantId,
            ':source_type' => in_array($sourceType, ['camera', 'file'], true) ? $sourceType : 'file',
            ':storage_file_id' => $stored['storage_file_id'],
            ':model_name' => $modelSummaryText,
            ':prompt_version' => 'prescription_minimal_4stage_v1',
            ':schema_version' => 'prescription_schema_v1',
            ':created_by' => (int)$user['id'],
        ]);
        $jobId = (int)$pdo->lastInsertId();

        $fileStmt = $pdo->prepare('INSERT INTO prescription_parse_job_files
            (parse_job_id, storage_file_id, file_role, width, height, created_at)
            VALUES (:parse_job_id, :storage_file_id, "original", :width, :height, NOW())');
        $fileStmt->execute([
            ':parse_job_id' => $jobId,
            ':storage_file_id' => $stored['storage_file_id'],
            ':width' => $stored['width'],
            ':height' => $stored['height'],
        ]);
        if (is_array($assistStored) && (int)($assistStored['storage_file_id'] ?? 0) > 0) {
            $fileStmtAssist = $pdo->prepare('INSERT INTO prescription_parse_job_files
                (parse_job_id, storage_file_id, file_role, width, height, created_at)
                VALUES (:parse_job_id, :storage_file_id, "preprocessed", :width, :height, NOW())');
            $fileStmtAssist->execute([
                ':parse_job_id' => $jobId,
                ':storage_file_id' => (int)$assistStored['storage_file_id'],
                ':width' => $assistStored['width'],
                ':height' => $assistStored['height'],
            ]);
        }

        $stored['quality_analysis'] = $imageQuality;
        if (is_array($assistStored)) {
            $stored['ocr_assist_storage_file_id'] = (int)($assistStored['storage_file_id'] ?? 0);
            $stored['ocr_assist_preprocess_profile'] = (string)($assistStored['preprocess_profile'] ?? 'assist');
        }
        $this->saveCaptureQuality($jobId, $tenantId, $companyUid, $branchUid, $stored);

        $openaiStart = microtime(true);
        try {
            $pdo->prepare('UPDATE prescription_parse_jobs SET status = "analyzing" WHERE id = :id')->execute([':id' => $jobId]);

            // 最小解析では、補助学習/テンプレートヒントは渡さない。
            $ai = $openaiClient->extractFromImage($ocrStored['path'], $ocrStored['mime_type'], null);
            $openaiMs = self::elapsedMs($openaiStart);
            $normalized = is_array($ai['normalized'] ?? null) ? $ai['normalized'] : [];
            $normalized['_ocr_raw_text'] = (string)($ai['ocr_raw_text'] ?? '');
            $normalized['_ocr_structured_json'] = is_array($ai['ocr_structured_json'] ?? null) ? $ai['ocr_structured_json'] : [];
            $aiNormalized = $normalized;
            if (class_exists('PrescriptionFieldPostProcessorService')) {
                $normalized = (new PrescriptionFieldPostProcessorService())->process($normalized);
            }
            if (class_exists('PrescriptionOcrFieldAutoRepairService')) {
                $normalized = (new PrescriptionOcrFieldAutoRepairService())->repairIfNeeded(
                    $openaiClient,
                    $ocrStored['path'],
                    $ocrStored['mime_type'],
                    $normalized,
                    $ai,
                    is_array($ai['ocr_structured_json'] ?? null) ? $ai['ocr_structured_json'] : []
                );
            }
            if (class_exists('PrescriptionOcrAttemptService')) {
                $normalized = (new PrescriptionOcrAttemptService())->attachFirstAttempt($normalized, $ai, [
                    'model_name' => $modelSummaryText,
                    'ocr_storage_file_id' => (int)($ocrStored['storage_file_id'] ?? 0),
                    'ocr_image_bytes' => (int)($ocrStored['size'] ?? 0),
                    'auto_field_retry_count' => (int)($normalized['_auto_field_retry_count'] ?? 0),
                ]);
            }
            if (class_exists('PrescriptionOcrDatasetService')) {
                (new PrescriptionOcrDatasetService())->saveEvent($tenantId, $jobId, null, 'ai_read_attempt_1', [
                    'original_storage_file' => $stored,
                    'ocr_storage_file' => $ocrStored,
                    'ocr_raw_text' => (string)($ai['ocr_raw_text'] ?? ''),
                    'ocr_structured_json' => $ai['ocr_structured_json'] ?? [],
                    'prescription_item_json' => $ai['prescription_item_json'] ?? [],
                    'ai_normalized_json' => $aiNormalized,
                    'php_validated_json' => $normalized,
                ], ['policy' => 'gpt4o-mini first attempt; legacy learning disabled']);
            }
            $debug = new PrescriptionIoDebugService();

            $debug->saveSnapshot($tenantId, $jobId, null, 'ocr_raw_text', '1. OCR生テキスト: 画像から起こした文字', (string)($ai['ocr_raw_text'] ?? ''), [
                'model_name' => (string)($ai['models']['ocr'] ?? $ai['model'] ?? $modelSummaryText),
                'model_tier' => $modelSummary,
                'content_type' => 'text',
                'minimal_analysis' => true,
                'created_by_user_id' => (int)$user['id'],
            ]);
            $debug->saveSnapshot($tenantId, $jobId, null, 'ocr_structured_json', '2. OCR構造JSON: 行・ブロック・信頼度', $ai['ocr_structured_json'] ?? [], [
                'model_name' => (string)($ai['models']['ocr'] ?? $ai['model'] ?? $modelSummaryText),
                'model_tier' => $modelSummary,
                'minimal_analysis' => true,
                'created_by_user_id' => (int)$user['id'],
            ]);
            $debug->saveSnapshot($tenantId, $jobId, null, 'prescription_item_json', '3. 処方箋項目JSON: OCRから患者・保険・薬品へ項目分け', $ai['prescription_item_json'] ?? $normalized, [
                'model_name' => (string)($ai['models']['structure'] ?? $ai['model'] ?? $modelSummaryText),
                'model_tier' => $modelSummary,
                'minimal_analysis' => true,
                'created_by_user_id' => (int)$user['id'],
            ]);
            $debug->saveSnapshot($tenantId, $jobId, null, 'display_output_data', '4. 表示・出力用データ: 最小解析では処方箋項目JSONをそのまま表示用に利用', [
                'used_ai_mapping' => false,
                'minimal_analysis' => true,
                'normalized' => $normalized,
            ], [
                'model_name' => (string)($ai['models']['structure'] ?? $ai['model'] ?? $modelSummaryText),
                'model_tier' => $modelSummary,
                'minimal_analysis' => true,
                'created_by_user_id' => (int)$user['id'],
            ]);
            $debug->saveSnapshot($tenantId, $jobId, null, 'openai_raw_response', 'OpenAI生レスポンス: 最小4段階パイプライン', $ai['raw'] ?? [], [
                'model_name' => (string)($ai['model'] ?? $modelSummaryText),
                'model_tier' => $modelSummary,
                'minimal_analysis' => true,
                'created_by_user_id' => (int)$user['id'],
            ]);
            $debug->saveSnapshot($tenantId, $jobId, null, 'openai_normalized', 'OpenAI項目JSON正規化後（AI自己confidenceを含む）', $aiNormalized, [
                'model_name' => (string)($ai['model'] ?? $modelSummaryText),
                'model_tier' => $modelSummary,
                'minimal_analysis' => true,
                'created_by_user_id' => (int)$user['id'],
            ]);
            $debug->saveSnapshot($tenantId, $jobId, null, 'php_validated_output', 'PHP検証後: ルール・辞書・図形判定・final score', $normalized, [
                'model_name' => (string)($ai['model'] ?? $modelSummaryText),
                'model_tier' => $modelSummary,
                'minimal_analysis' => true,
                'post_processor' => 'PrescriptionFieldPostProcessorService',
                'created_by_user_id' => (int)$user['id'],
            ]);
            if (!empty($normalized['_auto_field_repair'])) {
                $debug->saveSnapshot($tenantId, $jobId, null, 'auto_field_repair', '項目別AI再確認: PHP検証NG項目だけ2回目確認', $normalized['_auto_field_repair'], [
                    'model_name' => (string)($normalized['_auto_field_repair']['model'] ?? $modelSummaryText),
                    'model_tier' => $modelSummary,
                    'minimal_analysis' => true,
                    'post_processor' => 'PrescriptionOcrFieldAutoRepairService',
                    'created_by_user_id' => (int)$user['id'],
                ]);
            }

            $rawResponse = is_array($ai['raw'] ?? null) ? $ai['raw'] : [];
            $rawResponse['minimal_analysis'] = true;
            $rawResponse['model_tier'] = $modelSummary;
            $rawResponse['auto_field_repair'] = $normalized['_auto_field_repair'] ?? null;

            $stmt = $pdo->prepare('UPDATE prescription_parse_jobs
                SET status = "needs_review", model_name = :model_name, raw_response_json = :raw, normalized_json = :normalized,
                    overall_confidence = :confidence, matched_template_id = NULL, analyzed_at = NOW(), updated_at = NOW()
                WHERE id = :id');
            $stmt->execute([
                ':model_name' => $modelSummaryText,
                ':raw' => json_encode($rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':normalized' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':confidence' => (float)($normalized['overall_confidence'] ?? 0),
                ':id' => $jobId,
            ]);

            $this->saveMetrics($jobId, $tenantId, $companyUid, $branchUid, [
                'preprocessing_ms' => $preprocessMs,
                'template_detection_ms' => 0,
                'openai_ms' => $openaiMs,
                'correction_ms' => 0,
                'total_ms' => self::elapsedMs($start),
                'image_bytes_before' => $stored['size'],
                'image_bytes_after' => $ocrStored['size'],
                'openai_model' => $modelSummaryText,
                'image_detail' => (string)app_config('openai.vision_detail', 'high'),
            ]);
        } catch (Throwable $e) {
            if ($e instanceof OpenAiPrescriptionJsonParseException) {
                $debug = new PrescriptionIoDebugService();
                $debug->saveSnapshot($tenantId, $jobId, null, 'openai_raw_response_failed', '失敗時: OpenAI生レスポンス', $e->rawResponse(), [
                    'model_name' => $modelSummaryText,
                    'model_tier' => $modelSummary,
                    'minimal_analysis' => true,
                    'created_by_user_id' => (int)$user['id'],
                ]);
                $debug->saveSnapshot($tenantId, $jobId, null, 'openai_output_text_failed', '失敗時: output_text（JSON解析失敗）', $e->outputText(), [
                    'model_name' => $modelSummaryText,
                    'model_tier' => $modelSummary,
                    'content_type' => 'text',
                    'minimal_analysis' => true,
                    'created_by_user_id' => (int)$user['id'],
                ]);
                $debug->saveSnapshot($tenantId, $jobId, null, 'openai_parse_error', '失敗時: JSON解析エラー情報', [
                    'json_error' => $e->jsonError(),
                    'output_length' => strlen($e->outputText()),
                    'output_preview' => mb_substr($e->outputText(), 0, 500),
                ], [
                    'model_name' => $modelSummaryText,
                    'model_tier' => $modelSummary,
                    'minimal_analysis' => true,
                    'created_by_user_id' => (int)$user['id'],
                ]);
                $pdo->prepare('UPDATE prescription_parse_jobs SET raw_response_json = :raw WHERE id = :id')
                    ->execute([
                        ':raw' => json_encode($e->rawResponse(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':id' => $jobId,
                    ]);
            }
            $pdo->prepare('UPDATE prescription_parse_jobs SET status = "failed", error_message = :message, updated_at = NOW() WHERE id = :id')
                ->execute([':message' => mb_substr($e->getMessage(), 0, 2000), ':id' => $jobId]);
            $this->saveMetrics($jobId, $tenantId, $companyUid, $branchUid, [
                'preprocessing_ms' => $preprocessMs,
                'template_detection_ms' => 0,
                'openai_ms' => self::elapsedMs($openaiStart),
                'correction_ms' => 0,
                'total_ms' => self::elapsedMs($start),
                'image_bytes_before' => $stored['size'],
                'image_bytes_after' => $ocrStored['size'],
                'openai_model' => $modelSummaryText,
                'image_detail' => (string)app_config('openai.vision_detail', 'high'),
            ]);
            throw new PrescriptionOcrAnalyzeException($e->getMessage(), $jobId, 0, $e);
        }

        return $jobId;
    }

    public function retryJob(array $user, int $jobId): int
    {
        $tenantId = (int)$user['tenant_id'];
        $pdo = Db::branch();
        $job = self::getJob($tenantId, $jobId);
        if (!$job) {
            throw new RuntimeException('再読み込み対象の解析ジョブが見つかりません。');
        }
        $current = is_array($job['normalized'] ?? null) ? $job['normalized'] : [];
        $attemptService = new PrescriptionOcrAttemptService();
        $current = $attemptService->refreshAttemptSummary($current);
        if (empty($current['_can_retry_ocr'])) {
            throw new RuntimeException((string)($current['_ocr_retry_disabled_reason'] ?? '再読み込みは2回までです。以降は手入力で修正してください。'));
        }
        $attemptNo = ((int)($current['_ocr_attempt_count'] ?? 1)) + 1;
        if ($attemptNo > PrescriptionOcrAttemptService::MAX_ATTEMPTS) {
            throw new RuntimeException('再読み込みは2回までです。以降は手入力で修正してください。');
        }

        $fileStmt = $pdo->prepare('SELECT * FROM storage_files WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $fileStmt->execute([':id' => (int)($job['original_storage_file_id'] ?? 0), ':tenant_id' => $tenantId]);
        $sf = $fileStmt->fetch();
        if (!$sf || !is_file((string)$sf['stored_path'])) {
            throw new RuntimeException('原本画像が見つからないため再読み込みできません。');
        }
        $stored = [
            'storage_file_id' => (int)$sf['id'],
            'path' => (string)$sf['stored_path'],
            'mime_type' => (string)($sf['mime_type'] ?? 'image/jpeg'),
            'width' => null,
            'height' => null,
            'size' => (int)($sf['file_size_bytes'] ?? 0),
            'sha256' => (string)($sf['sha256_hash'] ?? ''),
            'original_filename' => (string)($sf['original_filename'] ?? ''),
        ];
        $imgSize = @getimagesize($stored['path']);
        if (is_array($imgSize)) {
            $stored['width'] = (int)$imgSize[0];
            $stored['height'] = (int)$imgSize[1];
        }

        $modelTier = 'low';
        $modelSummary = OpenAiPrescriptionClient::modelTierSummary($modelTier);
        $modelSummaryText = $modelSummary['summary'];
        $openaiClient = $this->openai->withModelTier($modelTier);
        $start = microtime(true);
        $pdo->prepare('UPDATE prescription_parse_jobs SET status = "analyzing", updated_at = NOW() WHERE id = :id')->execute([':id' => $jobId]);

        try {
            $ai = $openaiClient->extractFromImage($stored['path'], $stored['mime_type'], null);
            $aiNormalized = is_array($ai['normalized'] ?? null) ? $ai['normalized'] : [];
            $newNormalized = $aiNormalized;
            $newNormalized['_ocr_raw_text'] = (string)($ai['ocr_raw_text'] ?? '');
            $newNormalized['_ocr_structured_json'] = is_array($ai['ocr_structured_json'] ?? null) ? $ai['ocr_structured_json'] : [];
            if (class_exists('PrescriptionFieldPostProcessorService')) {
                $newNormalized = (new PrescriptionFieldPostProcessorService())->process($newNormalized);
            }
            $merged = $attemptService->mergeRetryAttempt($current, $newNormalized, $ai, [
                'model_name' => $modelSummaryText,
                'retry_of_parse_job_id' => $jobId,
                'ocr_storage_file_id' => (int)$stored['storage_file_id'],
                'ocr_image_bytes' => (int)$stored['size'],
            ]);

            $debug = new PrescriptionIoDebugService();
            $debug->saveSnapshot($tenantId, $jobId, null, 'ai_retry_attempt_' . $attemptNo, 'AI再読み込み' . $attemptNo . '回目: OCR/項目JSON/PHP検証', [
                'attempt_no' => $attemptNo,
                'ocr_raw_text' => (string)($ai['ocr_raw_text'] ?? ''),
                'ocr_structured_json' => $ai['ocr_structured_json'] ?? [],
                'prescription_item_json' => $ai['prescription_item_json'] ?? [],
                'ai_normalized_json' => $aiNormalized,
                'php_validated_json' => $newNormalized,
                'merged_display_json' => $merged,
            ], [
                'model_name' => $modelSummaryText,
                'model_tier' => $modelSummary,
                'created_by_user_id' => (int)$user['id'],
                'minimal_analysis' => true,
            ]);

            if (class_exists('PrescriptionOcrDatasetService')) {
                (new PrescriptionOcrDatasetService())->saveEvent($tenantId, $jobId, null, 'ai_read_attempt_' . $attemptNo, [
                    'source_storage_file' => $stored,
                    'ocr_raw_text' => (string)($ai['ocr_raw_text'] ?? ''),
                    'ocr_structured_json' => $ai['ocr_structured_json'] ?? [],
                    'prescription_item_json' => $ai['prescription_item_json'] ?? [],
                    'ai_normalized_json' => $aiNormalized,
                    'php_validated_json' => $newNormalized,
                    'merged_display_json' => $merged,
                ], ['policy' => 'manual retry; max 2 attempts']);
            }

            $rawResponse = is_array($job['raw_response_json'] ?? null) ? $job['raw_response_json'] : json_decode((string)($job['raw_response_json'] ?? '{}'), true);
            if (!is_array($rawResponse)) {
                $rawResponse = [];
            }
            $rawResponse['retry_attempt_' . $attemptNo] = is_array($ai['raw'] ?? null) ? $ai['raw'] : [];
            $rawResponse['model_tier'] = $modelSummary;
            $rawResponse['retry_policy'] = ['max_attempts' => PrescriptionOcrAttemptService::MAX_ATTEMPTS];

            $stmt = $pdo->prepare('UPDATE prescription_parse_jobs
                SET status = "needs_review", model_name = :model_name, raw_response_json = :raw, normalized_json = :normalized,
                    overall_confidence = :confidence, updated_at = NOW()
                WHERE id = :id');
            $stmt->execute([
                ':model_name' => $modelSummaryText,
                ':raw' => json_encode($rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ':normalized' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ':confidence' => (float)($merged['overall_confidence'] ?? 0),
                ':id' => $jobId,
            ]);

            $this->saveMetrics($jobId, $tenantId, current_company_uid(), current_branch_uid(), [
                'preprocessing_ms' => 0,
                'template_detection_ms' => 0,
                'openai_ms' => self::elapsedMs($start),
                'correction_ms' => 0,
                'total_ms' => self::elapsedMs($start),
                'image_bytes_before' => (int)$stored['size'],
                'image_bytes_after' => (int)$stored['size'],
                'openai_model' => $modelSummaryText . ' retry_attempt_' . $attemptNo,
                'image_detail' => (string)app_config('openai.vision_detail', 'high'),
            ]);
            return $jobId;
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE prescription_parse_jobs SET status = "failed", error_message = :error, updated_at = NOW() WHERE id = :id')
                ->execute([':error' => mb_substr($e->getMessage(), 0, 4000), ':id' => $jobId]);
            throw $e;
        }
    }

    public static function getJob(int $tenantId, int $jobId): ?array
    {
        $stmt = Db::branch()->prepare('SELECT * FROM prescription_parse_jobs WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            return null;
        }
        $job['normalized'] = json_decode((string)($job['normalized_json'] ?? '{}'), true) ?: [];
        return $job;
    }

    private function saveCaptureQuality(int $jobId, int $tenantId, string $companyUid, string $branchUid, array $stored): void
    {
        $width = $stored['width'];
        $height = $stored['height'];
        $paperScore = ($width && $height) ? min(100, max(0, (min($width, $height) / max($width, $height)) * 100)) : null;
        $stmt = Db::branch()->prepare('INSERT INTO prescription_capture_quality
            (parse_job_id, company_uid, branch_uid, tenant_id, width, height, file_size_bytes, paper_detect_score, device_label, user_agent, retake_count, created_at)
            VALUES (:parse_job_id, :company_uid, :branch_uid, :tenant_id, :width, :height, :file_size_bytes, :paper_detect_score, :device_label, :user_agent, 0, NOW())');
        $stmt->execute([
            ':parse_job_id' => $jobId,
            ':company_uid' => $companyUid,
            ':branch_uid' => $branchUid,
            ':tenant_id' => $tenantId,
            ':width' => $width,
            ':height' => $height,
            ':file_size_bytes' => $stored['size'],
            ':paper_detect_score' => $paperScore,
            ':device_label' => null,
            ':user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    private function saveMetrics(int $jobId, int $tenantId, string $companyUid, string $branchUid, array $m): void
    {
        $stmt = Db::branch()->prepare('INSERT INTO prescription_parse_metrics
            (parse_job_id, company_uid, branch_uid, tenant_id, preprocessing_ms, template_detection_ms, openai_ms, correction_ms, total_ms,
             image_bytes_before, image_bytes_after, openai_model, image_detail, created_at)
            VALUES (:parse_job_id, :company_uid, :branch_uid, :tenant_id, :preprocessing_ms, :template_detection_ms, :openai_ms, :correction_ms, :total_ms,
             :image_bytes_before, :image_bytes_after, :openai_model, :image_detail, NOW())');
        $stmt->execute([
            ':parse_job_id' => $jobId,
            ':company_uid' => $companyUid,
            ':branch_uid' => $branchUid,
            ':tenant_id' => $tenantId,
            ':preprocessing_ms' => (int)$m['preprocessing_ms'],
            ':template_detection_ms' => (int)$m['template_detection_ms'],
            ':openai_ms' => (int)$m['openai_ms'],
            ':correction_ms' => (int)$m['correction_ms'],
            ':total_ms' => (int)$m['total_ms'],
            ':image_bytes_before' => (int)$m['image_bytes_before'],
            ':image_bytes_after' => (int)$m['image_bytes_after'],
            ':openai_model' => $m['openai_model'],
            ':image_detail' => $m['image_detail'],
        ]);
    }

    private static function elapsedMs(float $start): int
    {
        return (int)round((microtime(true) - $start) * 1000);
    }
}
