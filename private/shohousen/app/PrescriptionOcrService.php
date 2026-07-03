<?php
declare(strict_types=1);

final class PrescriptionOcrService
{
    public function __construct(
        private readonly PrescriptionImagePreprocessor $image = new PrescriptionImagePreprocessor(),
        private readonly OpenAiPrescriptionClient $openai = new OpenAiPrescriptionClient(),
        private readonly PrescriptionKnowledgeService $knowledge = new PrescriptionKnowledgeService(),
        private readonly PrescriptionCorrectionService $correction = new PrescriptionCorrectionService(),
        private readonly PrescriptionTemplateDetector $templateDetector = new PrescriptionTemplateDetector(),
    ) {}

    public function analyzeUploaded(array $file, array $user, string $sourceType): int
    {
        $tenantId = (int)$user['tenant_id'];
        $companyUid = current_company_uid();
        $branchUid = current_branch_uid();
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
            ':model_name' => (string)app_config('openai.model', 'gpt-4o-mini'),
            ':prompt_version' => 'prescription_extract_v1',
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

        $templateStart = microtime(true);
        $detectedTemplateMeta = $this->templateDetector->detectFromStored($stored);
        $detectedTemplateMeta['quality_analysis'] = $imageQuality;
        if (is_array($assistStored)) {
            $detectedTemplateMeta['ocr_assist'] = [
                'storage_file_id' => (int)($assistStored['storage_file_id'] ?? 0),
                'preprocess_profile' => (string)($assistStored['preprocess_profile'] ?? 'assist'),
                'width' => $assistStored['width'] ?? null,
                'height' => $assistStored['height'] ?? null,
            ];
        }
        try {
            $this->knowledge->saveImageQualityLearning($jobId, $tenantId, $stored, $detectedTemplateMeta);
        } catch (Throwable) {
            // 画像品質の補助学習DB保存失敗でOCR処理を止めない。
        }
        $layoutFingerprint = (string)($detectedTemplateMeta['layout_fingerprint'] ?? '');
        $template = $this->knowledge->findTemplate($layoutFingerprint, $detectedTemplateMeta);
        $templateMs = self::elapsedMs($templateStart);
        $matchedTemplateId = $template ? (int)$template['id'] : null;
        $this->knowledge->saveTemplateMatchLog($jobId, $tenantId, $matchedTemplateId, $template ? (float)($template['match_score'] ?? $template['template_score'] ?? 0) : null, $templateMs, $template ? 'matched' : 'unknown');
        if (!$template && $layoutFingerprint !== '') {
            $this->knowledge->saveTemplateCandidate($jobId, $tenantId, $layoutFingerprint, $detectedTemplateMeta);
        }

        $openaiStart = microtime(true);
        try {
            $pdo->prepare('UPDATE prescription_parse_jobs SET status = "analyzing" WHERE id = :id')->execute([':id' => $jobId]);
            $ai = $this->openai->extractFromImage($ocrStored['path'], $ocrStored['mime_type'], $template);
            $openaiMs = self::elapsedMs($openaiStart);
            $correctionStart = microtime(true);
            $normalized = $this->correction->applyCandidates($ai['normalized']);
            $detectedTemplateMeta['ai_layout_profile'] = $this->templateDetector->fieldProfileFromNormalized($normalized);
            if ($layoutFingerprint !== '') {
                $this->knowledge->saveTemplateCandidate($jobId, $tenantId, $layoutFingerprint, $detectedTemplateMeta, false);
            }
            $correctionMs = self::elapsedMs($correctionStart);

            $stmt = $pdo->prepare('UPDATE prescription_parse_jobs
                SET status = "needs_review", model_name = :model_name, raw_response_json = :raw, normalized_json = :normalized,
                    overall_confidence = :confidence, matched_template_id = :matched_template_id, analyzed_at = NOW(), updated_at = NOW()
                WHERE id = :id');
            $stmt->execute([
                ':model_name' => $ai['model'],
                ':raw' => json_encode($ai['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':normalized' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':confidence' => (float)($normalized['overall_confidence'] ?? 0),
                ':matched_template_id' => $matchedTemplateId,
                ':id' => $jobId,
            ]);
            $this->correction->persistCandidates($jobId, $tenantId, $normalized);
            $this->saveMetrics($jobId, $tenantId, $companyUid, $branchUid, [
                'preprocessing_ms' => $preprocessMs,
                'template_detection_ms' => $templateMs,
                'openai_ms' => $openaiMs,
                'correction_ms' => $correctionMs,
                'total_ms' => self::elapsedMs($start),
                'image_bytes_before' => $stored['size'],
                'image_bytes_after' => $ocrStored['size'],
                'openai_model' => $ai['model'],
                'image_detail' => (string)app_config('openai.vision_detail', 'high'),
            ]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE prescription_parse_jobs SET status = "failed", error_message = :message, updated_at = NOW() WHERE id = :id')
                ->execute([':message' => mb_substr($e->getMessage(), 0, 2000), ':id' => $jobId]);
            $this->saveMetrics($jobId, $tenantId, $companyUid, $branchUid, [
                'preprocessing_ms' => $preprocessMs,
                'template_detection_ms' => $templateMs,
                'openai_ms' => self::elapsedMs($openaiStart),
                'correction_ms' => 0,
                'total_ms' => self::elapsedMs($start),
                'image_bytes_before' => $stored['size'],
                'image_bytes_after' => $ocrStored['size'],
                'openai_model' => (string)app_config('openai.model', 'gpt-4o-mini'),
                'image_detail' => (string)app_config('openai.vision_detail', 'high'),
            ]);
            throw $e;
        }

        return $jobId;
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
