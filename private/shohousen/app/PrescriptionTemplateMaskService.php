<?php
declare(strict_types=1);

/**
 * 患者実値をSV共通補助学習DBへ持ち込まないためのテンプレート素材生成。
 * 1) 枠だけ画像(frame_only): 罫線/欄構造だけを残す
 * 2) 固定ラベルJSON(label_only): ホワイトリスト一致ラベルだけを保存する
 * 3) 補正パターンログ: 実値ではなく桁数/文字種/補正種別だけを保存する
 */
final class PrescriptionTemplateMaskService
{
    /** @var array<string,string> */
    private const LABEL_WHITELIST = [
        '保険者番号' => 'insurance.insurance_no',
        '記号' => 'insurance.insured_symbol_number',
        '番号' => 'insurance.insured_symbol_number',
        '公費負担者番号' => 'public_expense.payer_no',
        '公費負担医療の受給者番号' => 'public_expense.beneficiary_no',
        '公費受給者番号' => 'public_expense.beneficiary_no',
        '氏名' => 'patient.name',
        'フリガナ' => 'patient.kana',
        'ふりがな' => 'patient.kana',
        'ふり仮名' => 'patient.kana',
        '生年月日' => 'patient.birth_date',
        '性別' => 'patient.gender',
        '交付年月日' => 'prescription.issued_on',
        '受付年月日' => 'prescription.received_on',
        '処方箋の使用期間' => 'prescription.expires_on',
        '保険医療機関' => 'medical_institution.name',
        '医療機関コード' => 'medical_institution.code',
        '医療機関等コード' => 'medical_institution.code',
        '所在地' => 'medical_institution.address',
        '電話番号' => 'medical_institution.phone',
        '保険医氏名' => 'medical_institution.doctor_name',
        '変更不可' => 'substitution.change_disallowed',
        '医療上必要' => 'substitution.change_disallowed',
        '患者希望' => 'substitution.patient_request',
        '署名' => 'substitution.doctor_signature_or_seal',
        '記名押印' => 'substitution.doctor_signature_or_seal',
    ];


    /**
     * 初回画像読取時に、個人値を含まないテンプレート候補だけを補助学習DBへ保存する。
     *
     * @param array<string,mixed> $sourceImage
     * @param array<string,mixed> $templateObservation
     * @param array<string,mixed> $ocrStructured
     * @return array<string,mixed>
     */
    public function saveInitialTemplateLearningAssets(
        int $tenantId,
        int $parseJobId,
        array $sourceImage,
        array $templateObservation,
        array $ocrStructured = []
    ): array {
        if ($parseJobId <= 0 || !(bool)app_config('prescription_template_learning.enabled', true)) {
            return ['saved' => false, 'reason' => 'disabled_or_invalid_job'];
        }

        $sourcePath = (string)($sourceImage['path'] ?? $sourceImage['stored_path'] ?? '');
        $source = [
            'stored_path' => $sourcePath,
            'mime_type' => (string)($sourceImage['mime_type'] ?? ''),
            'sha256_hash' => (string)($sourceImage['sha256'] ?? $sourceImage['sha256_hash'] ?? ''),
            'width' => $sourceImage['width'] ?? null,
            'height' => $sourceImage['height'] ?? null,
        ];
        $labelsFromOcr = $this->extractFixedLabels($ocrStructured);
        $labelsFromTemplate = $this->sanitizeTemplateFixedLabels($templateObservation);
        $labels = $this->mergeTemplateLabels($labelsFromOcr, $labelsFromTemplate);
        $context = $this->templateContext($tenantId, $parseJobId, 0, $source, $ocrStructured);
        $context['label_signature'] = hash('sha256', implode('|', array_map(
            static fn(array $label): string => (string)($label['field_key'] ?? '') . ':' . (string)($label['label_text'] ?? ''),
            $labels
        )));
        $context['template_hash'] = hash('sha256', (string)($context['line_signature'] ?? '') . '|' . (string)$context['label_signature']);
        $context['template_ai_confidence'] = is_numeric($templateObservation['overall_confidence'] ?? null)
            ? (float)$templateObservation['overall_confidence']
            : null;
        $context['learning_status'] = 'candidate';
        $context['privacy_level'] = 'frame_and_fixed_labels_only_no_patient_values';

        $assets = [];
        if ($sourcePath !== '' && is_file($sourcePath)) {
            $frame = $this->createFrameOnlyImage($sourcePath, (string)$source['mime_type']);
            if ($frame) {
                $assets[] = $this->saveAssetRow($tenantId, $parseJobId, 0, 'frame_only_candidate', $frame, $context + [
                    'privacy_level' => 'no_text_frame_only',
                    'source_image_hash' => (string)$source['sha256_hash'],
                ]);
            }
        }

        if ($labels) {
            $labelPath = $this->writeJsonAsset('label_only_candidate', $tenantId, $parseJobId, 0, [
                'schema_version' => 'prescription_label_only_candidate_v1',
                'status' => 'candidate',
                'privacy_level' => 'fixed_labels_only_no_values',
                'labels' => $labels,
                'sections' => array_values((array)($templateObservation['sections'] ?? [])),
                'frame_notes' => array_values(array_map('strval', (array)($templateObservation['frame_notes'] ?? []))),
                'context' => $context,
            ]);
            $assets[] = $this->saveAssetRow($tenantId, $parseJobId, 0, 'label_only_candidate_json', [
                'path' => $labelPath,
                'mime_type' => 'application/json',
                'sha256' => hash_file('sha256', $labelPath) ?: '',
                'size' => filesize($labelPath) ?: 0,
            ], $context + ['label_count' => count($labels), 'privacy_level' => 'fixed_labels_only_no_values']);
            $this->saveLabelObservations($tenantId, $parseJobId, 0, $labels, $context);
            $this->saveCandidateRegions($tenantId, $parseJobId, $labels, $context);
        }

        $summary = [
            'saved' => true,
            'status' => 'candidate',
            'template_hash' => (string)($context['template_hash'] ?? ''),
            'asset_count' => count($assets),
            'fixed_label_count' => count($labels),
            'candidate_region_count' => count(array_filter($labels, static fn(array $row): bool => (float)($row['w_ratio'] ?? 0) > 0 && (float)($row['h_ratio'] ?? 0) > 0)),
            'assets' => $assets,
            'privacy_policy' => 'no patient values in knowledge DB',
        ];
        $this->trace($tenantId, $parseJobId, 0, 'initial_template_candidate_saved', $summary, [
            'source_stage' => 'first_image_read',
            'privacy_policy' => 'frame_only + fixed_labels + candidate_coordinates',
        ]);
        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $selectedFields
     * @param array<string,mixed> $post
     */
    public function saveConfirmedTemplateLearningAssets(int $tenantId, ?int $parseJobId, int $prescriptionId, array $post, array $selectedFields = []): void
    {
        if (!$parseJobId || !(bool)app_config('prescription_template_learning.enabled', true)) {
            return;
        }

        try {
            $source = $this->findOriginalImageForJob($parseJobId);
            $ocrStructured = $this->findOcrStructuredForJob($tenantId, $parseJobId, $prescriptionId);
            $context = $this->templateContext($tenantId, $parseJobId, $prescriptionId, $source, $ocrStructured);

            $assets = [];
            if ($source && !empty($source['stored_path'])) {
                $frame = $this->createFrameOnlyImage((string)$source['stored_path'], (string)($source['mime_type'] ?? ''));
                if ($frame) {
                    $assets[] = $this->saveAssetRow($tenantId, $parseJobId, $prescriptionId, 'frame_only', $frame, $context + [
                        'privacy_level' => 'no_text_frame_only',
                        'source_image_hash' => (string)($source['sha256_hash'] ?? ''),
                    ]);
                }
            }

            $labels = $this->extractFixedLabels($ocrStructured);
            if ($labels) {
                $labelPath = $this->writeJsonAsset('label_only', $tenantId, $parseJobId, $prescriptionId, [
                    'schema_version' => 'prescription_label_only_v1',
                    'privacy_level' => 'fixed_labels_only_no_values',
                    'labels' => $labels,
                    'context' => $context,
                ]);
                $assets[] = $this->saveAssetRow($tenantId, $parseJobId, $prescriptionId, 'label_only_json', [
                    'path' => $labelPath,
                    'mime_type' => 'application/json',
                    'sha256' => hash_file('sha256', $labelPath) ?: '',
                    'size' => filesize($labelPath) ?: 0,
                ], $context + ['label_count' => count($labels)]);
                $this->saveLabelObservations($tenantId, $parseJobId, $prescriptionId, $labels, $context);
            }

            $patterns = $this->buildCorrectionPatterns($selectedFields);
            if ($patterns) {
                $this->saveCorrectionPatternEvents($tenantId, $parseJobId, $prescriptionId, $patterns, $context);
            }

            $this->trace($tenantId, $parseJobId, $prescriptionId, 'masked_template_learning_saved', [
                'assets' => $assets,
                'fixed_label_count' => count($labels),
                'correction_pattern_count' => count($patterns),
                'context' => $context,
            ], ['privacy_policy' => 'frame_only + fixed_labels + pattern_only; no patient value in knowledge DB']);
        } catch (Throwable $e) {
            try {
                (new PrescriptionKnowledgeService())->savePipelineTrace($tenantId, $parseJobId, $prescriptionId, 'masked_template_learning_error', 'write', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ], ['privacy_policy' => 'no patient value']);
            } catch (Throwable) {
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function findOriginalImageForJob(int $parseJobId): ?array
    {
        try {
            $pdo = Db::branch();
            $stmt = $pdo->prepare('SELECT sf.*
                FROM prescription_parse_jobs j
                INNER JOIN storage_files sf ON sf.id = j.original_storage_file_id
                WHERE j.id = :id
                LIMIT 1');
            $stmt->execute([':id' => $parseJobId]);
            $row = $stmt->fetch();
            if (is_array($row) && is_file((string)($row['stored_path'] ?? ''))) {
                return $row;
            }

            if (Db::tableExists($pdo, 'prescription_parse_job_files')) {
                $stmt = $pdo->prepare('SELECT sf.*
                    FROM prescription_parse_job_files jf
                    INNER JOIN storage_files sf ON sf.id = jf.storage_file_id
                    WHERE jf.parse_job_id = :id AND jf.file_role = "original"
                    ORDER BY jf.id ASC
                    LIMIT 1');
                $stmt->execute([':id' => $parseJobId]);
                $row = $stmt->fetch();
                return is_array($row) && is_file((string)($row['stored_path'] ?? '')) ? $row : null;
            }
        } catch (Throwable) {
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function findOcrStructuredForJob(int $tenantId, int $parseJobId, int $prescriptionId): array
    {
        try {
            $pdo = Db::branch();
            if (Db::tableExists($pdo, 'prescription_io_debug_snapshots')) {
                $stmt = $pdo->prepare('SELECT snapshot_json, snapshot_text
                    FROM prescription_io_debug_snapshots
                    WHERE tenant_id = :tenant_id AND parse_job_id = :parse_job_id AND stage = "ocr_structured_json"
                    ORDER BY id DESC LIMIT 1');
                $stmt->execute([':tenant_id' => $tenantId, ':parse_job_id' => $parseJobId]);
                $row = $stmt->fetch();
                if (is_array($row)) {
                    $json = (string)($row['snapshot_json'] ?? $row['snapshot_text'] ?? '');
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        } catch (Throwable) {
        }
        return [];
    }

    /** @return array<string,mixed> */
    private function templateContext(int $tenantId, int $parseJobId, int $prescriptionId, ?array $source, array $ocrStructured): array
    {
        $rawText = (string)($ocrStructured['raw_text'] ?? '');
        $labels = $this->extractFixedLabels($ocrStructured);
        $lineSignature = $this->lineSignature($source);
        $labelSignature = hash('sha256', implode('|', array_map(static fn(array $l): string => (string)$l['field_key'] . ':' . (string)$l['label_text'], $labels)));
        $templateHash = hash('sha256', $lineSignature . '|' . $labelSignature);
        return [
            'tenant_id' => $tenantId,
            'parse_job_id' => $parseJobId,
            'prescription_id' => $prescriptionId,
            'scope' => 'global_knowledge_db',
            'db_role_note' => 'superuser=inprof3_prescription, company=parent company DB, tenants=branch/subsidiary DB, knowledge=inprof3_assistantdata',
            'template_hash' => $templateHash,
            'line_signature' => $lineSignature,
            'label_signature' => $labelSignature,
            'raw_text_hash' => $rawText !== '' ? hash('sha256', $rawText) : '',
            'source_width' => is_numeric($source['width'] ?? null) ? (int)$source['width'] : null,
            'source_height' => is_numeric($source['height'] ?? null) ? (int)$source['height'] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function createFrameOnlyImage(string $sourcePath, string $mime): ?array
    {
        if (!extension_loaded('gd') || !is_file($sourcePath)) {
            return null;
        }
        $src = $this->loadImage($sourcePath, $mime);
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($src);
            return null;
        }

        $scale = min(1.0, 1800 / max(1, max($w, $h)));
        $tw = max(1, (int)round($w * $scale));
        $th = max(1, (int)round($h * $scale));
        $img = imagecreatetruecolor($tw, $th);
        imagecopyresampled($img, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);
        imagefilter($img, IMG_FILTER_GRAYSCALE);

        $canvas = imagecreatetruecolor($tw, $th);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $black = imagecolorallocate($canvas, 0, 0, 0);
        imagefilledrectangle($canvas, 0, 0, $tw, $th, $white);

        $threshold = 185;
        $minH = max(24, (int)round($tw * 0.035));
        $minV = max(24, (int)round($th * 0.025));

        for ($y = 0; $y < $th; $y++) {
            $start = null;
            for ($x = 0; $x < $tw; $x++) {
                $dark = $this->grayAt($img, $x, $y) < $threshold;
                if ($dark && $start === null) {
                    $start = $x;
                }
                if ((!$dark || $x === $tw - 1) && $start !== null) {
                    $end = $dark && $x === $tw - 1 ? $x : $x - 1;
                    if (($end - $start + 1) >= $minH) {
                        imageline($canvas, $start, $y, $end, $y, $black);
                    }
                    $start = null;
                }
            }
        }
        for ($x = 0; $x < $tw; $x++) {
            $start = null;
            for ($y = 0; $y < $th; $y++) {
                $dark = $this->grayAt($img, $x, $y) < $threshold;
                if ($dark && $start === null) {
                    $start = $y;
                }
                if ((!$dark || $y === $th - 1) && $start !== null) {
                    $end = $dark && $y === $th - 1 ? $y : $y - 1;
                    if (($end - $start + 1) >= $minV) {
                        imageline($canvas, $x, $start, $x, $end, $black);
                    }
                    $start = null;
                }
            }
        }
        imagedestroy($img);

        $dir = $this->assetDir('frame_only');
        $path = $dir . '/frame_only_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (!imagejpeg($canvas, $path, 92)) {
            imagedestroy($canvas);
            return null;
        }
        imagedestroy($canvas);
        return [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'width' => $tw,
            'height' => $th,
            'sha256' => hash_file('sha256', $path) ?: '',
            'size' => filesize($path) ?: 0,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function extractFixedLabels(array $ocrStructured): array
    {
        $labels = [];
        $seen = [];
        foreach ((array)($ocrStructured['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $text = trim((string)($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            foreach (self::LABEL_WHITELIST as $label => $fieldKey) {
                if (!str_contains($text, $label)) {
                    continue;
                }
                $key = $fieldKey . '|' . $label . '|' . (string)($line['line_no'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $labels[] = [
                    'field_key' => $fieldKey,
                    'label_text' => $label,
                    'source_section' => mb_substr((string)($line['source_section'] ?? ''), 0, 80),
                    'line_no' => is_numeric($line['line_no'] ?? null) ? (int)$line['line_no'] : null,
                    'confidence_bucket' => $this->confidenceBucket($line['confidence'] ?? null),
                    'source' => 'ocr_line_whitelist',
                    'value_saved' => false,
                ];
            }
        }
        return $labels;
    }


    /** @param array<string,mixed> $templateObservation @return array<int,array<string,mixed>> */
    private function sanitizeTemplateFixedLabels(array $templateObservation): array
    {
        $labels = [];
        foreach ((array)($templateObservation['fixed_labels'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $labelText = trim((string)($row['label_text'] ?? ''));
            if ($labelText === '') {
                continue;
            }
            $fieldKey = $this->whitelistedFieldKey($labelText, (string)($row['canonical_field_key'] ?? ''));
            if ($fieldKey === '') {
                continue;
            }
            $labels[] = [
                'field_key' => $fieldKey,
                'label_text' => mb_substr($labelText, 0, 160),
                'source_section' => mb_substr((string)($row['source_section'] ?? ''), 0, 80),
                'line_no' => null,
                'confidence_bucket' => $this->confidenceBucket($row['confidence'] ?? null),
                'confidence_score' => is_numeric($row['confidence'] ?? null) ? (float)$row['confidence'] : 0.0,
                'source' => 'template_image_single_task_whitelist',
                'value_saved' => false,
                'label_x_ratio' => $this->ratio($row['label_x_ratio'] ?? 0),
                'label_y_ratio' => $this->ratio($row['label_y_ratio'] ?? 0),
                'label_w_ratio' => $this->ratio($row['label_w_ratio'] ?? 0),
                'label_h_ratio' => $this->ratio($row['label_h_ratio'] ?? 0),
                // PHP GD切り出し候補には固定ラベルではなく、対応する値欄の領域を保存する。
                'x_ratio' => $this->ratio($row['value_x_ratio'] ?? $row['x_ratio'] ?? 0),
                'y_ratio' => $this->ratio($row['value_y_ratio'] ?? $row['y_ratio'] ?? 0),
                'w_ratio' => $this->ratio($row['value_w_ratio'] ?? $row['w_ratio'] ?? 0),
                'h_ratio' => $this->ratio($row['value_h_ratio'] ?? $row['h_ratio'] ?? 0),
            ];
        }
        return $labels;
    }

    private function whitelistedFieldKey(string $labelText, string $candidateKey): string
    {
        $candidateKey = trim($candidateKey);
        $allowedKeys = array_values(self::LABEL_WHITELIST);
        if ($candidateKey !== '' && in_array($candidateKey, $allowedKeys, true)) {
            return $candidateKey;
        }
        $matches = [];
        foreach (self::LABEL_WHITELIST as $label => $fieldKey) {
            if ($labelText === $label || mb_stripos($labelText, $label) !== false) {
                $matches[mb_strlen($label)] = $fieldKey;
            }
        }
        if (!$matches) {
            return '';
        }
        krsort($matches);
        return (string)reset($matches);
    }

    /** @param array<int,array<string,mixed>> $a @param array<int,array<string,mixed>> $b @return array<int,array<string,mixed>> */
    private function mergeTemplateLabels(array $a, array $b): array
    {
        $merged = [];
        foreach (array_merge($a, $b) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string)($row['field_key'] ?? '') . '|' . (string)($row['label_text'] ?? '') . '|' . (string)($row['source_section'] ?? '');
            if ($key === '||') {
                continue;
            }
            if (!isset($merged[$key]) || (float)($row['confidence_score'] ?? 0) > (float)($merged[$key]['confidence_score'] ?? 0)) {
                $merged[$key] = $row;
            }
        }
        return array_values($merged);
    }

    /** @param array<int,array<string,mixed>> $labels @param array<string,mixed> $context */
    private function saveCandidateRegions(int $tenantId, int $parseJobId, array $labels, array $context): void
    {
        try {
            $pdo = Db::knowledge();
            if (!Db::tableExists($pdo, 'prescription_global_template_regions')) {
                return;
            }
            $stmt = $pdo->prepare('INSERT INTO prescription_global_template_regions
                (template_hash, line_signature, label_signature, field_key, field_label,
                 x_ratio, y_ratio, w_ratio, h_ratio, margin_ratio, confidence_score,
                 sample_count, status, enabled, source_stage, company_uid, branch_uid, tenant_id, parse_job_id, created_at, updated_at)
                VALUES
                (:template_hash, :line_signature, :label_signature, :field_key, :field_label,
                 :x_ratio, :y_ratio, :w_ratio, :h_ratio, 0.010000, :confidence_score,
                 1, "candidate", 0, "first_image_read", :company_uid, :branch_uid, :tenant_id, :parse_job_id, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    x_ratio = ((x_ratio * sample_count) + VALUES(x_ratio)) / (sample_count + 1),
                    y_ratio = ((y_ratio * sample_count) + VALUES(y_ratio)) / (sample_count + 1),
                    w_ratio = ((w_ratio * sample_count) + VALUES(w_ratio)) / (sample_count + 1),
                    h_ratio = ((h_ratio * sample_count) + VALUES(h_ratio)) / (sample_count + 1),
                    confidence_score = GREATEST(confidence_score, VALUES(confidence_score)),
                    sample_count = sample_count + 1,
                    updated_at = NOW()');
            foreach ($labels as $label) {
                $w = $this->ratio($label['w_ratio'] ?? 0);
                $h = $this->ratio($label['h_ratio'] ?? 0);
                if ($w <= 0 || $h <= 0) {
                    continue;
                }
                $stmt->execute([
                    ':template_hash' => (string)($context['template_hash'] ?? ''),
                    ':line_signature' => (string)($context['line_signature'] ?? ''),
                    ':label_signature' => (string)($context['label_signature'] ?? ''),
                    ':field_key' => (string)($label['field_key'] ?? ''),
                    ':field_label' => (string)($label['label_text'] ?? ''),
                    ':x_ratio' => $this->ratio($label['x_ratio'] ?? 0),
                    ':y_ratio' => $this->ratio($label['y_ratio'] ?? 0),
                    ':w_ratio' => $w,
                    ':h_ratio' => $h,
                    ':confidence_score' => is_numeric($label['confidence_score'] ?? null) ? (float)$label['confidence_score'] : 0.0,
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':tenant_id' => $tenantId,
                    ':parse_job_id' => $parseJobId,
                ]);
            }
        } catch (Throwable) {
        }
    }

    private function ratio(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float)$value)) : 0.0;
    }

    /** @param array<int,array<string,mixed>> $selectedFields @return array<int,array<string,mixed>> */
    private function buildCorrectionPatterns(array $selectedFields): array
    {
        $patterns = [];
        foreach ($selectedFields as $row) {
            $fieldKey = (string)($row['field_key'] ?? '');
            $fieldLabel = (string)($row['field_label'] ?? $fieldKey);
            $fieldGroup = (string)($row['field_group'] ?? 'other');
            $ai = trim((string)($row['source_ai_value'] ?? ''));
            $final = trim((string)($row['field_value'] ?? ''));
            if ($ai === '' && $final === '') {
                continue;
            }
            $type = $this->correctionType($ai, $final);
            if ($type === 'confirmed' && empty($row['needs_human_check'])) {
                continue;
            }
            $patterns[] = [
                'field_key' => mb_substr($fieldKey, 0, 120),
                'field_label' => mb_substr($fieldLabel, 0, 160),
                'field_group' => $fieldGroup,
                'correction_type' => $type,
                'ai_length' => mb_strlen($ai),
                'final_length' => mb_strlen($final),
                'ai_char_type' => $this->charType($ai),
                'final_char_type' => $this->charType($final),
                'length_delta' => mb_strlen($final) - mb_strlen($ai),
                'value_hash_pair' => $this->nonReversiblePatternHash($fieldKey, $ai, $final),
                'needs_human_check' => !empty($row['needs_human_check']),
                'actual_values_saved' => false,
            ];
        }
        return $patterns;
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $meta @return array<string,mixed> */
    private function saveAssetRow(int $tenantId, int $parseJobId, int $prescriptionId, string $assetType, array $asset, array $meta): array
    {
        $row = [
            'asset_type' => $assetType,
            'asset_path' => (string)($asset['path'] ?? ''),
            'mime_type' => (string)($asset['mime_type'] ?? ''),
            'sha256' => (string)($asset['sha256'] ?? ''),
            'size' => (int)($asset['size'] ?? 0),
        ];
        try {
            $pdo = Db::knowledge();
            if (Db::tableExists($pdo, 'prescription_template_assets')) {
                $stmt = $pdo->prepare('INSERT INTO prescription_template_assets
                    (template_hash, company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, asset_type, asset_path,
                     mime_type, file_size_bytes, sha256_hash, privacy_level, meta_json, created_at)
                    VALUES
                    (:template_hash, :company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :asset_type, :asset_path,
                     :mime_type, :file_size_bytes, :sha256_hash, :privacy_level, :meta_json, NOW())');
                $stmt->execute([
                    ':template_hash' => (string)($meta['template_hash'] ?? ''),
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':tenant_id' => $tenantId,
                    ':parse_job_id' => $parseJobId,
                    ':prescription_id' => $prescriptionId,
                    ':asset_type' => $assetType,
                    ':asset_path' => mb_substr((string)($asset['path'] ?? ''), 0, 1000),
                    ':mime_type' => mb_substr((string)($asset['mime_type'] ?? ''), 0, 120),
                    ':file_size_bytes' => (int)($asset['size'] ?? 0),
                    ':sha256_hash' => mb_substr((string)($asset['sha256'] ?? ''), 0, 64),
                    ':privacy_level' => mb_substr((string)($meta['privacy_level'] ?? 'masked_no_patient_value'), 0, 80),
                    ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
                $row['asset_id'] = (int)$pdo->lastInsertId();
            }
        } catch (Throwable) {
        }
        return $row;
    }

    /** @param array<int,array<string,mixed>> $labels @param array<string,mixed> $context */
    private function saveLabelObservations(int $tenantId, int $parseJobId, int $prescriptionId, array $labels, array $context): void
    {
        try {
            $pdo = Db::knowledge();
            if (!Db::tableExists($pdo, 'prescription_template_label_observations')) {
                return;
            }
            $stmt = $pdo->prepare('INSERT INTO prescription_template_label_observations
                (template_hash, company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, field_key, label_text,
                 source_section, line_no, confidence_bucket, source, created_at)
                VALUES
                (:template_hash, :company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :field_key, :label_text,
                 :source_section, :line_no, :confidence_bucket, :source, NOW())');
            foreach ($labels as $label) {
                $stmt->execute([
                    ':template_hash' => (string)($context['template_hash'] ?? ''),
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':tenant_id' => $tenantId,
                    ':parse_job_id' => $parseJobId,
                    ':prescription_id' => $prescriptionId,
                    ':field_key' => (string)($label['field_key'] ?? ''),
                    ':label_text' => (string)($label['label_text'] ?? ''),
                    ':source_section' => (string)($label['source_section'] ?? ''),
                    ':line_no' => $label['line_no'] ?? null,
                    ':confidence_bucket' => (string)($label['confidence_bucket'] ?? 'unknown'),
                    ':source' => (string)($label['source'] ?? 'ocr_line_whitelist'),
                ]);
            }
        } catch (Throwable) {
        }
    }

    /** @param array<int,array<string,mixed>> $patterns @param array<string,mixed> $context */
    private function saveCorrectionPatternEvents(int $tenantId, int $parseJobId, int $prescriptionId, array $patterns, array $context): void
    {
        try {
            $pdo = Db::knowledge();
            if (Db::tableExists($pdo, 'prescription_ocr_correction_pattern_events')) {
                $stmt = $pdo->prepare('INSERT INTO prescription_ocr_correction_pattern_events
                    (template_hash, company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, field_key, field_label, field_group,
                     correction_type, ai_length, final_length, ai_char_type, final_char_type, length_delta, value_hash_pair,
                     needs_human_check, source_stage, created_at)
                    VALUES
                    (:template_hash, :company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :field_key, :field_label, :field_group,
                     :correction_type, :ai_length, :final_length, :ai_char_type, :final_char_type, :length_delta, :value_hash_pair,
                     :needs_human_check, "human_confirmed", NOW())');
                foreach ($patterns as $pattern) {
                    $stmt->execute([
                        ':template_hash' => (string)($context['template_hash'] ?? ''),
                        ':company_uid' => current_company_uid(),
                        ':branch_uid' => current_branch_uid(),
                        ':tenant_id' => $tenantId,
                        ':parse_job_id' => $parseJobId,
                        ':prescription_id' => $prescriptionId,
                        ':field_key' => (string)$pattern['field_key'],
                        ':field_label' => (string)$pattern['field_label'],
                        ':field_group' => (string)$pattern['field_group'],
                        ':correction_type' => (string)$pattern['correction_type'],
                        ':ai_length' => (int)$pattern['ai_length'],
                        ':final_length' => (int)$pattern['final_length'],
                        ':ai_char_type' => (string)$pattern['ai_char_type'],
                        ':final_char_type' => (string)$pattern['final_char_type'],
                        ':length_delta' => (int)$pattern['length_delta'],
                        ':value_hash_pair' => (string)$pattern['value_hash_pair'],
                        ':needs_human_check' => !empty($pattern['needs_human_check']) ? 1 : 0,
                    ]);
                }
            }
            $this->trace($tenantId, $parseJobId, $prescriptionId, 'correction_pattern_learning_saved', [
                'patterns' => $patterns,
                'actual_values_saved' => false,
                'context' => $context,
            ], ['privacy_policy' => 'pattern only; no raw patient value']);
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $meta */
    private function trace(int $tenantId, int $parseJobId, int $prescriptionId, string $stage, array $payload, array $meta = []): void
    {
        try {
            (new PrescriptionKnowledgeService())->savePipelineTrace($tenantId, $parseJobId, $prescriptionId, $stage, 'write', $payload, $meta);
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $payload */
    private function writeJsonAsset(string $type, int $tenantId, int $parseJobId, int $prescriptionId, array $payload): string
    {
        $dir = $this->assetDir($type);
        $path = $dir . '/' . $type . '_' . date('Ymd_His') . '_job' . $parseJobId . '_rx' . $prescriptionId . '_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
        return $path;
    }

    private function assetDir(string $type): string
    {
        $base = rtrim((string)app_config('storage.prescription_template_assets_dir', dirname(__DIR__) . '/storage/prescription_template_assets'), '/');
        $dir = $base . '/' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $type) . '/' . date('Y/m');
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir;
    }

    private function loadImage(string $path, string $mime)
    {
        return match ($mime) {
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => @imagecreatefromjpeg($path),
        } ?: false;
    }

    private function grayAt($img, int $x, int $y): int
    {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return (int)round($r * 0.299 + $g * 0.587 + $b * 0.114);
    }

    private function lineSignature(?array $source): string
    {
        if (!$source || empty($source['stored_path']) || !is_file((string)$source['stored_path']) || !extension_loaded('gd')) {
            return '';
        }
        try {
            $asset = $this->createFrameOnlyImage((string)$source['stored_path'], (string)($source['mime_type'] ?? ''));
            if ($asset && is_file((string)$asset['path'])) {
                $hash = (string)($asset['sha256'] ?? '');
                @unlink((string)$asset['path']);
                return $hash;
            }
        } catch (Throwable) {
        }
        return '';
    }

    private function confidenceBucket(mixed $confidence): string
    {
        if (!is_numeric($confidence)) {
            return 'unknown';
        }
        $c = (float)$confidence;
        if ($c <= 1.0) {
            $c *= 100.0;
        }
        return $c < 50 ? '<50' : ($c < 75 ? '50-74' : '75-100');
    }

    private function charType(string $value): string
    {
        if ($value === '') {
            return 'empty';
        }
        if (preg_match('/^\d+$/u', mb_convert_kana($value, 'n'))) {
            return 'numeric';
        }
        if (preg_match('/^[A-Za-z0-9\-_.]+$/u', $value)) {
            return 'ascii_mixed';
        }
        if (preg_match('/^[\p{Hiragana}\p{Katakana}ー\s]+$/u', $value)) {
            return 'kana';
        }
        if (preg_match('/\p{Han}/u', $value)) {
            return 'jp_text';
        }
        return 'mixed';
    }

    private function correctionType(string $ai, string $final): string
    {
        if ($ai === '' && $final !== '') {
            return 'added';
        }
        if ($ai !== '' && $final === '') {
            return 'emptied';
        }
        $nAi = preg_replace('/\s+/u', '', mb_convert_kana($ai, 'asKV')) ?? $ai;
        $nFinal = preg_replace('/\s+/u', '', mb_convert_kana($final, 'asKV')) ?? $final;
        return $nAi === $nFinal ? 'confirmed' : 'edited';
    }

    private function nonReversiblePatternHash(string $fieldKey, string $ai, string $final): string
    {
        $secret = (string)app_config('app.learning_hash_salt', php_uname('n'));
        return hash_hmac('sha256', $fieldKey . '|' . $ai . '|' . $final, $secret);
    }
}
