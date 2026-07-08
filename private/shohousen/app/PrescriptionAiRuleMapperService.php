<?php
declare(strict_types=1);

/**
 * AIが読み取った処方箋JSONを、画面表示・DB保存・QR出力に使いやすい項目へ再配置する。
 *
 * 役割:
 * - 画像読取そのものは OpenAiPrescriptionClient::extractFromImage()
 * - ここでは「読取済みJSON」を、処方箋ルール・拠点テンプレート・過去修正傾向に沿って
 *   display-ready な form_fields へ落とし込む。
 * - AI変換に失敗しても、従来のPHP正規化結果で処理を継続する。
 */
final class PrescriptionAiRuleMapperService
{
    public function __construct(
        private readonly OpenAiPrescriptionClient $openai = new OpenAiPrescriptionClient(),
        private readonly PrescriptionKnowledgeService $knowledge = new PrescriptionKnowledgeService(),
        private readonly PrescriptionTemplateDetector $templateDetector = new PrescriptionTemplateDetector(),
    ) {}

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed>|null $template
     * @param array<string,mixed> $layoutMeta
     * @return array{normalized:array<string,mixed>,mapping:array<string,mixed>,used_ai:bool,error:?string}
     */
    public function mapForDisplay(array $normalized, ?array $template, array $layoutMeta = [], ?int $parseJobId = null, int $tenantId = 0, ?string $modelTier = null): array
    {
        $base = $this->ensureDisplayReadyFields($normalized, null);
        $enabled = (bool)app_config('prescription_ai_mapping.enabled', true);
        if (!$enabled) {
            return [
                'normalized' => $base,
                'mapping' => ['mode' => 'disabled', 'display_fields' => $base['form_fields'] ?? []],
                'used_ai' => false,
                'error' => null,
            ];
        }

        try {
            $ruleContext = $this->ruleContext($base, $layoutMeta, $parseJobId, $tenantId);
            $mapping = $this->openai->withModelTier($modelTier)->mapNormalizedToDisplay($base, $template, $ruleContext);
            $mapped = $this->applyAiMapping($base, $mapping);
            $mapped['_ai_rule_mapping'] = [
                'enabled' => true,
                'used_ai' => true,
                'mapper_model' => (string)($mapping['model'] ?? app_config('openai.model', 'gpt-4o-mini')),
                'model_tier' => OpenAiPrescriptionClient::modelTierSummary($modelTier),
                'warnings' => array_values(array_filter(array_map('strval', (array)($mapping['warnings'] ?? [])))),
                'generated_at' => date('c'),
            ];
            return [
                'normalized' => $mapped,
                'mapping' => $mapping,
                'used_ai' => true,
                'error' => null,
            ];
        } catch (Throwable $e) {
            if (!(bool)app_config('prescription_ai_mapping.fail_open', true)) {
                throw $e;
            }
            $base['_ai_rule_mapping'] = [
                'enabled' => true,
                'used_ai' => false,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'fallback' => 'php_display_ready_fields',
                'generated_at' => date('c'),
            ];
            return [
                'normalized' => $base,
                'mapping' => [
                    'mode' => 'fallback_php',
                    'error' => $e->getMessage(),
                    'display_fields' => $base['form_fields'] ?? [],
                ],
                'used_ai' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed>|null $mapping
     * @return array<string,mixed>
     */
    private function ensureDisplayReadyFields(array $normalized, ?array $mapping): array
    {
        $fields = [];
        foreach (($normalized['form_fields'] ?? []) as $i => $field) {
            if (!is_array($field)) {
                continue;
            }
            $fields[] = $this->normalizeDisplayField($field, $i + 1, 'ai_or_php_normalized');
        }

        if ($mapping && is_array($mapping['display_fields'] ?? null)) {
            $mappedFields = [];
            foreach ($mapping['display_fields'] as $i => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $mappedFields[] = $this->normalizeDisplayField($field, $i + 1, 'ai_rule_mapping');
            }
            if ($mappedFields) {
                $fields = $this->mergeFields($fields, $mappedFields);
            }
        }

        $normalized['form_fields'] = $this->mergeFields([], $fields);
        return $normalized;
    }

    /** @param array<string,mixed> $mapping @param array<string,mixed> $normalized @return array<string,mixed> */
    private function applyAiMapping(array $normalized, array $mapping): array
    {
        $normalized = $this->ensureDisplayReadyFields($normalized, $mapping);
        $fixed = is_array($mapping['fixed'] ?? null) ? $mapping['fixed'] : [];
        foreach ([
            ['patient', 'name'], ['patient', 'kana'], ['patient', 'birth_date'], ['patient', 'gender'],
            ['insurance', 'insurance_no'], ['insurance', 'insured_symbol_number'], ['insurance', 'copay_rate'],
            ['prescription', 'issued_on'], ['prescription', 'expires_on'],
            ['medical_institution', 'code'], ['medical_institution', 'name'], ['medical_institution', 'doctor_name'], ['medical_institution', 'phone'],
        ] as [$section, $key]) {
            if (isset($fixed[$section]) && is_array($fixed[$section]) && array_key_exists($key, $fixed[$section])) {
                $value = trim((string)$fixed[$section][$key]);
                if ($value !== '') {
                    $normalized[$section][$key] = $value;
                }
            }
        }

        $medications = is_array($mapping['medications'] ?? null) ? $mapping['medications'] : [];
        if ($medications) {
            $normalized['medications'] = $this->mergeMedicationMapping((array)($normalized['medications'] ?? []), $medications);
        }

        $warnings = array_values(array_unique(array_filter(array_map('strval', array_merge(
            (array)($normalized['warnings'] ?? []),
            (array)($mapping['warnings'] ?? [])
        )))));
        $normalized['warnings'] = $warnings;
        return $normalized;
    }

    /** @param array<int,array<string,mixed>> $base @param array<int,array<string,mixed>> $mapped @return array<int,array<string,mixed>> */
    private function mergeMedicationMapping(array $base, array $mapped): array
    {
        foreach ($mapped as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($base[$i]) || !is_array($base[$i])) {
                $base[$i] = [];
            }
            foreach (['drug_name','generic_name','brand_name','raw_drug_text','name_relation','dose_text','usage_text','days_count','amount_text','reason'] as $key) {
                if (array_key_exists($key, $row)) {
                    $value = $row[$key];
                    if ($key === 'days_count') {
                        $base[$i][$key] = is_numeric($value) ? (int)$value : null;
                    } else {
                        $text = trim((string)$value);
                        if ($text !== '') {
                            $base[$i][$key] = $text;
                        }
                    }
                }
            }
            if (array_key_exists('confidence', $row) && is_numeric($row['confidence'])) {
                $base[$i]['confidence'] = $this->confidencePercent((float)$row['confidence']);
            }
            if (array_key_exists('needs_human_check', $row)) {
                $base[$i]['needs_human_check'] = (bool)$row['needs_human_check'];
            }
        }
        return array_values($base);
    }

    /** @param array<int,array<string,mixed>> $base @param array<int,array<string,mixed>> $incoming @return array<int,array<string,mixed>> */
    private function mergeFields(array $base, array $incoming): array
    {
        $byKey = [];
        foreach (array_merge($base, $incoming) as $i => $field) {
            $field = $this->normalizeDisplayField($field, $i + 1, (string)($field['source'] ?? 'merged'));
            if ((string)$field['field_group'] === 'medication') {
                continue;
            }
            if ($this->isLearningOnlyField($field)) {
                continue;
            }
            $key = (string)$field['field_key'];
            if ($key === '') {
                continue;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = $field;
                continue;
            }
            $old = $byKey[$key];
            if (trim((string)$old['value']) === '' && trim((string)$field['value']) !== '') {
                $old['value'] = $field['value'];
            }
            if (trim((string)($old['ai_value'] ?? '')) === '' && trim((string)($field['ai_value'] ?? '')) !== '') {
                $old['ai_value'] = $field['ai_value'];
            }
            $old['confidence'] = max((float)($old['confidence'] ?? 0), (float)($field['confidence'] ?? 0));
            $old['needs_human_check'] = !empty($old['needs_human_check']) || !empty($field['needs_human_check']);
            $old['include_default'] = !empty($old['include_default']) || !empty($field['include_default']);
            $old['output_candidate'] = !empty($old['output_candidate']) || !empty($field['output_candidate']);
            $old['display_order'] = min((int)($old['display_order'] ?? 9999), (int)($field['display_order'] ?? 9999));
            if (trim((string)$old['source_section']) === '' && trim((string)$field['source_section']) !== '') {
                $old['source_section'] = $field['source_section'];
            }
            if (($field['source'] ?? '') === 'ai_rule_mapping') {
                $old['ui_template'] = $field['ui_template'];
                $old['value_type'] = $field['value_type'];
                $old['source'] = 'ai_rule_mapping';
            }
            $byKey[$key] = $old;
        }
        $out = array_values($byKey);
        usort($out, static function (array $a, array $b): int {
            $groupOrder = ['patient' => 10, 'insurance' => 20, 'public_expense' => 30, 'prescription' => 40, 'medical_institution' => 50, 'pharmacy' => 70, 'note' => 80, 'qr' => 90, 'other' => 99];
            return (($groupOrder[$a['field_group']] ?? 99) <=> ($groupOrder[$b['field_group']] ?? 99))
                ?: (((int)($a['display_order'] ?? 9999)) <=> ((int)($b['display_order'] ?? 9999)))
                ?: strcmp((string)($a['field_label'] ?? ''), (string)($b['field_label'] ?? ''));
        });
        return $out;
    }

    /** @param array<string,mixed> $field @return array<string,mixed> */
    private function normalizeDisplayField(array $field, int $displayOrder, string $source): array
    {
        $group = (string)($field['field_group'] ?? $field['group'] ?? 'other');
        if (!in_array($group, ['patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other'], true)) {
            $group = 'other';
        }
        $valueType = (string)($field['value_type'] ?? 'text');
        if (!in_array($valueType, ['text','date','number','code','person_name','drug','usage','amount','boolean','unknown'], true)) {
            $valueType = 'text';
        }
        $uiTemplate = (string)($field['ui_template'] ?? $this->uiTemplateForValueType($valueType));
        if (!in_array($uiTemplate, ['input','textarea','date','number','select','checkbox','blank_cell'], true)) {
            $uiTemplate = $this->uiTemplateForValueType($valueType);
        }
        $label = trim((string)($field['field_label'] ?? $field['label'] ?? ''));
        $key = $this->canonicalFieldKey((string)($field['field_key'] ?? $field['canonical_key'] ?? ''), $label, $group);
        if ($key === '') {
            $key = 'field_' . substr(hash('sha1', $label !== '' ? $label : uniqid('', true)), 0, 12);
        }
        $value = trim((string)($field['value'] ?? ''));
        $aiValue = trim((string)($field['ai_value'] ?? $field['source_ai_value'] ?? $value));
        return [
            'field_key' => mb_substr($key, 0, 120),
            'canonical_key' => mb_substr((string)($field['canonical_key'] ?? $key), 0, 120),
            'field_label' => mb_substr($label !== '' ? $label : $key, 0, 160),
            'field_group' => $group,
            'value' => $value,
            'ai_value' => $aiValue,
            'value_type' => $valueType,
            'ui_template' => $uiTemplate,
            'source_section' => mb_substr(trim((string)($field['source_section'] ?? '')), 0, 160),
            'confidence' => $this->confidencePercent($field['confidence'] ?? 0),
            'needs_human_check' => !empty($field['needs_human_check']),
            'include_default' => !empty($field['include_default']),
            'output_candidate' => array_key_exists('output_candidate', $field) ? (bool)$field['output_candidate'] : true,
            'is_empty_cell' => !empty($field['is_empty_cell']) || $uiTemplate === 'blank_cell',
            'display_order' => is_numeric($field['display_order'] ?? null) ? (int)$field['display_order'] : $displayOrder,
            'reason' => mb_substr((string)($field['reason'] ?? ''), 0, 255),
            'source' => $source,
        ];
    }

    private function uiTemplateForValueType(string $valueType): string
    {
        return match ($valueType) {
            'date' => 'date',
            'number', 'code' => 'input',
            'boolean' => 'checkbox',
            default => 'input',
        };
    }

    private function confidencePercent(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }
        $confidence = (float)$value;
        if ($confidence >= 0.0 && $confidence <= 1.0) {
            $confidence *= 100.0;
        }
        return round(max(0.0, min(100.0, $confidence)), 2);
    }

    private function canonicalFieldKey(string $key, string $label, string $group): string
    {
        $key = str_replace('-', '_', preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($key)) ?? '');
        $key = trim($key, '_');
        $target = $label . ' ' . $key;
        if ($group === 'patient') {
            if (str_contains($target, 'フリガナ')) return 'patient.kana';
            if (str_contains($target, '氏名') || str_contains($target, '患者名')) return 'patient.name';
            if (str_contains($target, '生年月日')) return 'patient.birth_date';
            if (str_contains($target, '性別')) return 'patient.gender';
        }
        if ($group === 'insurance') {
            if (str_contains($target, '保険者番号')) return 'insurance.insurance_no';
            if (str_contains($target, '記号') || str_contains($target, '被保険者')) return 'insurance.insured_symbol_number';
            if (str_contains($target, '負担割合')) return 'insurance.copay_rate';
        }
        if ($group === 'public_expense') {
            if (str_contains($target, '公費負担者番号')) return 'public_expense.payer_number';
            if (str_contains($target, '受給者番号')) return 'public_expense.beneficiary_number';
        }
        if ($group === 'prescription') {
            if (str_contains($target, '交付年月日') || str_contains($target, '発行日')) return 'prescription.issued_on';
            if (str_contains($target, '使用期間') || str_contains($target, '有効期限')) return 'prescription.expires_on';
        }
        if ($group === 'medical_institution') {
            if (str_contains($target, '医療機関コード')) return 'medical_institution.code';
            if (str_contains($target, '電話')) return 'medical_institution.phone';
            if (str_contains($target, '保険医') || str_contains($target, '医師')) return 'medical_institution.doctor_name';
            if (str_contains($target, '医療機関名') || str_contains($target, '名称')) return 'medical_institution.name';
        }
        return $key;
    }

    /** @param array<string,mixed> $field */
    private function isLearningOnlyField(array $field): bool
    {
        $text = mb_strtolower(implode(' ', [
            (string)($field['field_key'] ?? ''),
            (string)($field['field_label'] ?? ''),
            (string)($field['reason'] ?? ''),
        ]));
        foreach (['raw_drug_text','薬品名元テキスト','元テキスト','generic_name','一般名候補','brand_name','商品名候補','relation_type','drug_name_relation','辞書候補'] as $needle) {
            if (str_contains($text, mb_strtolower($needle))) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $normalized @param array<string,mixed> $layoutMeta @return array<string,mixed> */
    private function ruleContext(array $normalized, array $layoutMeta, ?int $parseJobId, int $tenantId): array
    {
        $ruleChecks = [];
        try {
            $ruleChecks = (new PrescriptionRuleEngineService())->evaluateNormalized($normalized);
        } catch (Throwable) {
            $ruleChecks = [];
        }
        $layoutProfile = $this->templateDetector->fieldProfileFromNormalized($normalized);
        $learningHints = '';
        try {
            $learningHints = $this->knowledge->buildOpenAiLearningHints((string)($layoutMeta['layout_fingerprint'] ?? ''));
        } catch (Throwable) {
            $learningHints = '';
        }
        return [
            'parse_job_id' => $parseJobId,
            'tenant_id' => $tenantId,
            'company_uid' => current_company_uid(),
            'branch_uid' => current_branch_uid(),
            'layout_fingerprint' => (string)($layoutMeta['layout_fingerprint'] ?? ''),
            'layout_features' => $layoutMeta['features'] ?? [],
            'layout_profile' => $layoutProfile,
            'rule_checks' => array_slice($ruleChecks, 0, 20),
            'learning_hints' => $learningHints,
        ];
    }
}
