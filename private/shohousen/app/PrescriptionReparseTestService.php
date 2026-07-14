<?php
declare(strict_types=1);

/**
 * 人間修正後の補助学習データが、次回AI解析に効くかを検証するための再解析サービス。
 * 通常運用の保存とは分離し、「保存して再解析テスト」または保存済み画面の検証ボタンからのみ実行する。
 */
final class PrescriptionReparseTestService
{
    public function __construct(
        private readonly OpenAiPrescriptionClient $openai = new OpenAiPrescriptionClient(),
        private readonly PrescriptionCorrectionService $correction = new PrescriptionCorrectionService(),
        private readonly PrescriptionAiRuleMapperService $aiRuleMapper = new PrescriptionAiRuleMapperService(),
        private readonly PrescriptionKnowledgeService $knowledge = new PrescriptionKnowledgeService(),
        private readonly PrescriptionIoDebugService $debug = new PrescriptionIoDebugService(),
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function runForPrescription(array $user, int $prescriptionId): array
    {
        $tenantId = (int)$user['tenant_id'];
        $prescription = get_prescription($tenantId, $prescriptionId);
        if (!$prescription) {
            throw new RuntimeException('再解析対象の処方箋が見つかりません。');
        }
        $parseJobId = (int)($prescription['parse_job_id'] ?? 0);
        if ($parseJobId <= 0) {
            throw new RuntimeException('再解析対象の読み取りジョブが紐づいていません。');
        }

        $job = PrescriptionOcrService::getJob($tenantId, $parseJobId);
        if (!$job) {
            throw new RuntimeException('再解析対象の読み取りジョブが見つかりません。');
        }

        $image = $this->resolveImageForReparse($tenantId, $parseJobId);
        $humanAnswer = $this->humanAnswerFromPrescription($prescription);
        $learningContext = $this->learningContextSummary($parseJobId, $prescriptionId, $humanAnswer);

        $this->debug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'human_corrected_answer', '人間修正後: 正解データ', $humanAnswer, [
            'created_by_user_id' => (int)$user['id'],
        ]);
        $this->debug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'learning_saved_summary', '補助学習保存後: 学習コンテキスト', $learningContext, [
            'created_by_user_id' => (int)$user['id'],
        ]);
        $this->knowledge->savePipelineTrace($tenantId, $parseJobId, $prescriptionId, 'human_corrected_answer', 'learning', $humanAnswer, [
            'created_by_user_id' => (int)$user['id'],
        ]);

        $started = microtime(true);
        $singleTaskMode = (bool)app_config('prescription_single_task_analysis.enabled', true)
            && class_exists('PrescriptionSingleTaskExtractionService');
        $ai = $singleTaskMode
            ? (new PrescriptionSingleTaskExtractionService())->extract($this->openai, (string)$image['path'], (string)$image['mime_type'], $this->templateHintFromJob($job))
            : $this->openai->extractFromImage((string)$image['path'], (string)$image['mime_type'], $this->templateHintFromJob($job));
        $this->debug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'ai_reparse_after_learning_raw', '再解析後: OpenAI生レスポンス', $ai['raw'], [
            'model_name' => $ai['model'],
            'created_by_user_id' => (int)$user['id'],
        ]);
        $this->debug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'ai_reparse_after_learning_normalized', '再解析後: AI正規化JSON', $ai['normalized'], [
            'model_name' => $ai['model'],
            'created_by_user_id' => (int)$user['id'],
        ]);

        $normalizedAfterCorrection = $this->correction->applyCandidates($ai['normalized']);
        $this->debug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'ai_reparse_after_learning_php_validated', '再解析後: PHP厳格ルール検証後', $normalizedAfterCorrection, [
            'model_name' => $ai['model'],
            'created_by_user_id' => (int)$user['id'],
        ]);

        if ($singleTaskMode) {
            $mapping = ['used_ai' => false, 'error' => null, 'normalized' => $normalizedAfterCorrection];
        } else {
            $mapping = $this->aiRuleMapper->mapForDisplay($normalizedAfterCorrection, $this->templateHintFromJob($job), ['mode' => 'reparse_after_learning'], $parseJobId, $tenantId);
        }
        $mapped = $mapping['normalized'];
        $this->debug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'ai_reparse_after_learning_mapped_display', '再解析後: AI項目化後JSON', [
            'used_ai' => $mapping['used_ai'],
            'error' => $mapping['error'],
            'normalized' => $mapped,
        ], [
            'model_name' => $ai['model'],
            'created_by_user_id' => (int)$user['id'],
        ]);

        $comparison = $this->compareHumanAnswerToAi($humanAnswer, $mapped);
        $comparison['model_name'] = $ai['model'];
        $comparison['elapsed_ms'] = (int)round((microtime(true) - $started) * 1000);
        $comparison['image_role'] = (string)($image['file_role'] ?? 'original');
        $comparison['parse_job_id'] = $parseJobId;
        $comparison['prescription_id'] = $prescriptionId;

        $this->debug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'reparse_compare_result', '再解析比較: 人間修正値との差分スコア', $comparison, [
            'model_name' => $ai['model'],
            'created_by_user_id' => (int)$user['id'],
        ]);
        $this->knowledge->savePipelineTrace($tenantId, $parseJobId, $prescriptionId, 'reparse_compare_result', 'learning', $comparison, [
            'model_name' => $ai['model'],
            'created_by_user_id' => (int)$user['id'],
        ]);
        $this->saveEvaluation($tenantId, $parseJobId, $prescriptionId, $comparison, (int)$user['id']);

        return $comparison;
    }

    /** @return array{path:string,mime_type:string,file_role:string} */
    private function resolveImageForReparse(int $tenantId, int $parseJobId): array
    {
        $pdo = Db::branch();
        $stmt = $pdo->prepare('SELECT sf.stored_path, sf.mime_type, jf.file_role
            FROM prescription_parse_job_files jf
            INNER JOIN storage_files sf ON sf.id = jf.storage_file_id
            WHERE jf.parse_job_id = :parse_job_id
            ORDER BY CASE jf.file_role WHEN "preprocessed" THEN 1 WHEN "original" THEN 2 ELSE 3 END, jf.id DESC
            LIMIT 1');
        $stmt->execute([':parse_job_id' => $parseJobId]);
        $file = $stmt->fetch();
        if (!$file) {
            $stmt = $pdo->prepare('SELECT sf.stored_path, sf.mime_type, "original" AS file_role
                FROM prescription_parse_jobs j
                INNER JOIN storage_files sf ON sf.id = j.original_storage_file_id
                WHERE j.id = :parse_job_id AND j.tenant_id = :tenant_id
                LIMIT 1');
            $stmt->execute([':parse_job_id' => $parseJobId, ':tenant_id' => $tenantId]);
            $file = $stmt->fetch();
        }

        $path = (string)($file['stored_path'] ?? '');
        $mime = (string)($file['mime_type'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('再解析用の元画像ファイルを読めません。');
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('再解析対象の画像形式に対応していません。');
        }
        return ['path' => $path, 'mime_type' => $mime, 'file_role' => (string)($file['file_role'] ?? 'original')];
    }

    /** @return array<string,mixed>|null */
    private function templateHintFromJob(array $job): ?array
    {
        $templateId = (int)($job['matched_template_id'] ?? 0);
        if ($templateId <= 0) {
            return null;
        }
        try {
            if (!Db::tableExists(Db::knowledge(), 'prescription_templates')) {
                return null;
            }
            $stmt = Db::knowledge()->prepare('SELECT * FROM prescription_templates WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $templateId]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $row['field_map'] = json_decode((string)($row['field_map_json'] ?? '{}'), true) ?: [];
            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function humanAnswerFromPrescription(array $prescription): array
    {
        $answer = [
            'patient' => [
                'name' => (string)($prescription['patient_name'] ?? ''),
                'gender' => $this->genderLabel((string)($prescription['gender'] ?? '')),
                'birth_date' => (string)($prescription['birth_date'] ?? ''),
            ],
            'insurance' => [
                'insurance_no' => (string)($prescription['insurance_no'] ?? ''),
                'insured_symbol_number' => (string)($prescription['insured_symbol_number'] ?? ''),
                'copay_rate' => (string)($prescription['copay_rate'] ?? ''),
            ],
            'prescription' => [
                'issued_on' => (string)($prescription['issued_on'] ?? ''),
            ],
            'medical_institution' => [
                'code' => (string)($prescription['institution_code'] ?? ''),
                'name' => (string)($prescription['medical_name'] ?? ''),
            ],
            'selected_fields' => [],
            'medications' => [],
        ];

        foreach ((array)($prescription['selected_fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $answer['selected_fields'][] = [
                'field_key' => (string)($field['field_key'] ?? ''),
                'field_label' => (string)($field['field_label'] ?? ''),
                'field_group' => (string)($field['field_group'] ?? 'other'),
                'field_value' => (string)($field['field_value'] ?? ''),
                'source_ai_value' => (string)($field['source_ai_value'] ?? ''),
                'include_for_output' => !empty($field['include_for_output']),
            ];
        }
        foreach ((array)($prescription['medications'] ?? []) as $i => $med) {
            if (!is_array($med)) {
                continue;
            }
            $answer['medications'][] = [
                'sort_order' => $i + 1,
                'drug_name' => (string)($med['drug_name'] ?? ''),
                'generic_name' => (string)($med['generic_name'] ?? ''),
                'brand_name' => (string)($med['brand_name'] ?? ''),
                'dose_text' => (string)($med['dose_text'] ?? ''),
                'usage_text' => (string)($med['usage_text'] ?? ''),
                'days_count' => (string)($med['days_count'] ?? ''),
                'amount_text' => (string)($med['amount_text'] ?? ''),
            ];
        }
        return $answer;
    }

    private function genderLabel(string $value): string
    {
        return match ($value) {
            'male' => '男性',
            'female' => '女性',
            default => $value,
        };
    }

    /** @return array<string,mixed> */
    private function learningContextSummary(int $parseJobId, int $prescriptionId, array $humanAnswer): array
    {
        return [
            'parse_job_id' => $parseJobId,
            'prescription_id' => $prescriptionId,
            'saved_at' => date('c'),
            'human_fixed_field_count' => count((array)($humanAnswer['selected_fields'] ?? [])),
            'human_fixed_medication_count' => count((array)($humanAnswer['medications'] ?? [])),
            'purpose' => '人間修正後データを正解として補助学習DBに保存し、同じ画像の再解析で改善傾向を検証する。',
        ];
    }

    /** @return array<string,mixed> */
    private function compareHumanAnswerToAi(array $humanAnswer, array $aiNormalized): array
    {
        $human = $this->flattenHumanAnswer($humanAnswer);
        $ai = $this->flattenAiNormalized($aiNormalized);
        $details = [];
        $matched = 0;
        $mismatch = 0;
        $missing = 0;
        $unchecked = 0;

        foreach ($human as $key => $expected) {
            $actual = $ai[$key] ?? '';
            $expectedNorm = $this->normalizeCompareText($expected);
            $actualNorm = $this->normalizeCompareText($actual);
            if ($expectedNorm === '' && $actualNorm === '') {
                $unchecked++;
                continue;
            }
            if ($expectedNorm !== '' && $actualNorm === '') {
                $missing++;
                $status = 'missing';
            } elseif ($expectedNorm === $actualNorm) {
                $matched++;
                $status = 'matched';
            } else {
                $mismatch++;
                $status = 'mismatch';
            }
            $details[] = [
                'field_key' => $key,
                'expected_human_value' => $expected,
                'reparse_ai_value' => $actual,
                'status' => $status,
            ];
        }

        $extra = [];
        foreach ($ai as $key => $actual) {
            if (!array_key_exists($key, $human) && $this->normalizeCompareText($actual) !== '') {
                $extra[] = ['field_key' => $key, 'reparse_ai_value' => $actual, 'status' => 'extra'];
            }
        }

        $scored = $matched + $mismatch + $missing;
        $matchRate = $scored > 0 ? round(($matched / $scored) * 100, 2) : 0.0;
        return [
            'summary' => [
                'total_scored_fields' => $scored,
                'matched_count' => $matched,
                'mismatch_count' => $mismatch,
                'missing_count' => $missing,
                'unchecked_empty_count' => $unchecked,
                'extra_ai_field_count' => count($extra),
                'match_rate' => $matchRate,
                'learning_effect_label' => $matchRate >= 90 ? '高い' : ($matchRate >= 70 ? '中程度' : '低い'),
            ],
            'details' => $details,
            'extra_ai_fields' => array_slice($extra, 0, 50),
        ];
    }

    /** @return array<string,string> */
    private function flattenHumanAnswer(array $answer): array
    {
        $out = [
            'patient.name' => (string)($answer['patient']['name'] ?? ''),
            'patient.gender' => (string)($answer['patient']['gender'] ?? ''),
            'patient.birth_date' => (string)($answer['patient']['birth_date'] ?? ''),
            'insurance.insurance_no' => (string)($answer['insurance']['insurance_no'] ?? ''),
            'insurance.insured_symbol_number' => (string)($answer['insurance']['insured_symbol_number'] ?? ''),
            'insurance.copay_rate' => (string)($answer['insurance']['copay_rate'] ?? ''),
            'prescription.issued_on' => (string)($answer['prescription']['issued_on'] ?? ''),
            'medical_institution.code' => (string)($answer['medical_institution']['code'] ?? ''),
            'medical_institution.name' => (string)($answer['medical_institution']['name'] ?? ''),
        ];
        foreach ((array)($answer['medications'] ?? []) as $i => $med) {
            $n = $i + 1;
            foreach (['drug_name','generic_name','brand_name','dose_text','usage_text','days_count','amount_text'] as $key) {
                $out['medications.' . $n . '.' . $key] = (string)($med[$key] ?? '');
            }
        }
        foreach ((array)($answer['selected_fields'] ?? []) as $field) {
            $key = trim((string)($field['field_key'] ?? ''));
            if ($key !== '') {
                $out['field.' . $key] = (string)($field['field_value'] ?? '');
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private function flattenAiNormalized(array $normalized): array
    {
        $out = [
            'patient.name' => (string)($normalized['patient']['name'] ?? ''),
            'patient.gender' => (string)($normalized['patient']['gender'] ?? ''),
            'patient.birth_date' => (string)($normalized['patient']['birth_date'] ?? ''),
            'insurance.insurance_no' => (string)($normalized['insurance']['insurance_no'] ?? ''),
            'insurance.insured_symbol_number' => (string)($normalized['insurance']['insured_symbol_number'] ?? ''),
            'insurance.copay_rate' => (string)($normalized['insurance']['copay_rate'] ?? ''),
            'prescription.issued_on' => (string)($normalized['prescription']['issued_on'] ?? ''),
            'medical_institution.code' => (string)($normalized['medical_institution']['code'] ?? ''),
            'medical_institution.name' => (string)($normalized['medical_institution']['name'] ?? ''),
        ];
        foreach ((array)($normalized['medications'] ?? []) as $i => $med) {
            if (!is_array($med)) {
                continue;
            }
            $n = $i + 1;
            foreach (['drug_name','generic_name','brand_name','dose_text','usage_text','days_count','amount_text'] as $key) {
                $out['medications.' . $n . '.' . $key] = (string)($med[$key] ?? '');
            }
        }
        foreach ((array)($normalized['form_fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string)($field['field_key'] ?? ''));
            if ($key !== '') {
                $out['field.' . $key] = (string)($field['value'] ?? '');
            }
        }
        return $out;
    }

    private function normalizeCompareText(string $value): string
    {
        $value = trim(mb_convert_kana($value, 'asKV'));
        $value = preg_replace('/[\s　]+/u', '', $value) ?? $value;
        $value = str_replace(['－','ー','―','ｰ'], '-', $value);
        return mb_strtolower($value);
    }

    private function saveEvaluation(int $tenantId, int $parseJobId, int $prescriptionId, array $comparison, int $userId): void
    {
        try {
            $pdo = Db::knowledge();
            if (!Db::tableExists($pdo, 'prescription_reparse_evaluations')) {
                return;
            }
            $summary = is_array($comparison['summary'] ?? null) ? $comparison['summary'] : [];
            $stmt = $pdo->prepare('INSERT INTO prescription_reparse_evaluations
                (company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, model_name, total_scored_fields, matched_count, mismatch_count, missing_count, extra_ai_field_count, match_rate, payload_json, created_by_user_id, created_at)
                VALUES (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :model_name, :total_scored_fields, :matched_count, :mismatch_count, :missing_count, :extra_ai_field_count, :match_rate, :payload_json, :created_by_user_id, NOW())');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':parse_job_id' => $parseJobId,
                ':prescription_id' => $prescriptionId,
                ':model_name' => (string)($comparison['model_name'] ?? app_config('openai.model', '')),
                ':total_scored_fields' => (int)($summary['total_scored_fields'] ?? 0),
                ':matched_count' => (int)($summary['matched_count'] ?? 0),
                ':mismatch_count' => (int)($summary['mismatch_count'] ?? 0),
                ':missing_count' => (int)($summary['missing_count'] ?? 0),
                ':extra_ai_field_count' => (int)($summary['extra_ai_field_count'] ?? 0),
                ':match_rate' => (float)($summary['match_rate'] ?? 0),
                ':payload_json' => json_encode($comparison, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_by_user_id' => $userId,
            ]);
        } catch (Throwable) {
            // 検証結果の補助学習DB保存失敗で画面遷移を止めない。
        }
    }
}
