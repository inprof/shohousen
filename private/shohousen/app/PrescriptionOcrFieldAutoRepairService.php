<?php
declare(strict_types=1);

/**
 * PHP検証でNG/判定不能になった主要項目だけを、表示前に1回だけAIで再確認する。
 * 初回読取 + 項目別再確認 = 最大2トライとして扱い、再確認後もNGなら赤枠/手入力へ回す。
 */
final class PrescriptionOcrFieldAutoRepairService
{
    /** @var array<string,string> */
    private const REPAIRABLE_FIELD_LABELS = [
        'patient.birth_date' => '生年月日',
        'insurance.insurance_no' => '保険者番号',
        'insurance.insured_symbol_number' => '被保険者証・記号番号',
        'public_expense.payer_no' => '公費負担者番号',
        'public_expense.beneficiary_no' => '公費負担医療の受給者番号',
        'prescription.issued_on' => '交付年月日',
        'prescription.received_on' => '受付年月日',
        'prescription.expires_on' => '処方箋使用期間',
        'medical_institution.code' => '医療機関コード',
        'medical_institution.name' => '保険医療機関名',
        'medical_institution.doctor_name' => '保険医氏名',
    ];

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $ai
     * @param array<string,mixed> $ocrStructured
     * @return array<string,mixed>
     */
    public function repairIfNeeded(OpenAiPrescriptionClient $client, string $imagePath, string $mimeType, array $normalized, array $ai = [], array $ocrStructured = []): array
    {
        if (!(bool)app_config('prescription_auto_field_repair.enabled', true)) {
            return $normalized;
        }
        if ((int)($normalized['_auto_field_retry_count'] ?? 0) >= 1) {
            return $normalized;
        }

        $targets = $this->buildRepairTargets($normalized);
        if (!$targets) {
            $normalized['_auto_field_retry_count'] = 0;
            $normalized['_auto_field_repair'] = [
                'enabled' => true,
                'executed' => false,
                'reason' => 'PHP検証で項目別再確認が必要な主要項目はありません。',
            ];
            return $normalized;
        }

        try {
            $repair = $client->repairCoreFieldsFromImage($imagePath, $mimeType, $normalized, $targets, $ocrStructured);
            $before = $this->fieldSnapshot($normalized);
            $merged = $this->mergeRepairFields($normalized, is_array($repair['fields'] ?? null) ? $repair['fields'] : []);

            if (class_exists('PrescriptionFieldPostProcessorService')) {
                $merged = (new PrescriptionFieldPostProcessorService())->process($merged);
            }

            $after = $this->fieldSnapshot($merged);
            $unresolved = $this->blockingRepairableKeys($merged);
            $merged['_auto_field_retry_count'] = 1;
            $merged['_auto_field_repair'] = [
                'enabled' => true,
                'executed' => true,
                'attempt_no' => 2,
                'target_fields' => $targets,
                'before' => $before,
                'after' => $after,
                'returned_fields' => is_array($repair['fields'] ?? null) ? $repair['fields'] : [],
                'warnings' => array_values(array_map('strval', (array)($repair['warnings'] ?? []))),
                'overall_confidence' => is_numeric($repair['overall_confidence'] ?? null) ? (float)$repair['overall_confidence'] : null,
                'model' => (string)($repair['model'] ?? ''),
                'unresolved_fields' => $unresolved,
                'message' => $unresolved
                    ? '項目別再確認後も解決できない必須項目があります。赤枠の項目は手入力または再撮影してください。'
                    : '項目別再確認で主要項目を補正しました。黄色の項目は原画像で確認してください。',
            ];
            if ($unresolved) {
                $merged['warnings'][] = '項目別再確認後も未解決: ' . implode(' / ', array_values($unresolved));
            }
            $merged['warnings'] = array_values(array_unique(array_filter(array_map('strval', (array)($merged['warnings'] ?? [])))));
            return $merged;
        } catch (Throwable $e) {
            $normalized['_auto_field_retry_count'] = 1;
            $normalized['_auto_field_repair'] = [
                'enabled' => true,
                'executed' => false,
                'error' => $e->getMessage(),
                'target_fields' => $targets,
                'message' => '項目別再確認に失敗しました。赤枠の項目は手入力または再撮影してください。',
            ];
            $normalized['warnings'][] = '項目別再確認エラー: ' . $e->getMessage();
            $normalized['warnings'] = array_values(array_unique(array_filter(array_map('strval', (array)($normalized['warnings'] ?? [])))));
            return $normalized;
        }
    }

    /** @param array<string,mixed> $normalized @return array<int,array<string,mixed>> */
    private function buildRepairTargets(array $normalized): array
    {
        $targets = [];
        foreach ((array)($normalized['field_validations'] ?? []) as $validation) {
            if (!is_array($validation)) {
                continue;
            }
            $key = $this->canonicalKey((string)($validation['field_key'] ?? ''));
            if (!isset(self::REPAIRABLE_FIELD_LABELS[$key])) {
                continue;
            }
            $status = (string)($validation['status'] ?? '');
            $blocks = !empty($validation['blocks_qr']);
            $needs = !empty($validation['needs_human_check']);
            if (!$blocks && !in_array($status, ['ng', 'unknown'], true)) {
                continue;
            }
            $targets[$key] = [
                'field_key' => $key,
                'field_label' => self::REPAIRABLE_FIELD_LABELS[$key],
                'current_value' => (string)$this->getPath($normalized, $key),
                'validation_status' => $status,
                'validation_reason' => (string)($validation['reason'] ?? ''),
                'needs_human_check' => $needs,
                'normalized_candidate' => (string)($validation['normalized_value'] ?? ''),
            ];
        }
        return array_values($targets);
    }

    /** @param array<string,mixed> $normalized @return array<string,string> */
    private function blockingRepairableKeys(array $normalized): array
    {
        $out = [];
        foreach ((array)($normalized['field_validations'] ?? []) as $validation) {
            if (!is_array($validation) || empty($validation['blocks_qr'])) {
                continue;
            }
            $key = $this->canonicalKey((string)($validation['field_key'] ?? ''));
            if (isset(self::REPAIRABLE_FIELD_LABELS[$key])) {
                $out[$key] = self::REPAIRABLE_FIELD_LABELS[$key] . '（' . (string)($validation['status'] ?? '') . '）';
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $normalized @param array<int,mixed> $fields @return array<string,mixed> */
    private function mergeRepairFields(array $normalized, array $fields): array
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = $this->canonicalKey((string)($field['field_key'] ?? ''));
            if (!isset(self::REPAIRABLE_FIELD_LABELS[$key])) {
                continue;
            }
            $value = trim((string)($field['value'] ?? ''));
            $raw = trim((string)($field['raw_text'] ?? ''));
            if ($value === '' && $raw !== '') {
                $value = $raw;
            }
            if ($value === '') {
                continue;
            }

            if (in_array($key, ['patient.birth_date', 'prescription.issued_on', 'prescription.received_on', 'prescription.expires_on'], true) && class_exists('PrescriptionReferenceRuleService')) {
                $date = PrescriptionReferenceRuleService::normalizeDate($value);
                if (is_string($date['normalized'] ?? null) && (string)$date['normalized'] !== '') {
                    $this->setPath($normalized, $key, (string)$date['normalized']);
                    $this->setPath($normalized, $key . '_raw_ai_repair', $raw !== '' ? $raw : $value);
                } else {
                    $this->setPath($normalized, $key, $value);
                }
                continue;
            }

            if (in_array($key, ['insurance.insurance_no', 'public_expense.payer_no', 'public_expense.beneficiary_no', 'medical_institution.code'], true) && class_exists('PrescriptionReferenceRuleService')) {
                $type = match ($key) {
                    'insurance.insurance_no' => 'insurance_no',
                    'public_expense.payer_no' => 'public_payer_no',
                    'public_expense.beneficiary_no' => 'public_beneficiary_no',
                    'medical_institution.code' => 'medical_institution_code',
                    default => '',
                };
                $check = PrescriptionReferenceRuleService::validateCode($type, $value);
                $this->setPath($normalized, $key, !empty($check['valid']) ? (string)$check['digits'] : PrescriptionReferenceRuleService::digitsOnly($value));
                $this->setPath($normalized, $key . '_raw_ai_repair', $raw !== '' ? $raw : $value);
                continue;
            }

            $this->setPath($normalized, $key, $value);
            $this->setPath($normalized, $key . '_raw_ai_repair', $raw !== '' ? $raw : $value);
        }
        return $normalized;
    }

    private function canonicalKey(string $key): string
    {
        $key = str_replace('-', '_', trim($key));
        return match ($key) {
            'patient_birth_date', 'birth_date', 'patient.birth_date' => 'patient.birth_date',
            'insurance_no', 'insurer_number', 'insurance.insurance_no' => 'insurance.insurance_no',
            'insured_symbol_number', 'insurance_symbol_number', 'insurance.insured_symbol_number' => 'insurance.insured_symbol_number',
            'public_payer_no', 'public_expense_payer_no', 'public_expense.payer_no' => 'public_expense.payer_no',
            'public_beneficiary_no', 'beneficiary_no', 'public_expense_beneficiary_no', 'public_expense.beneficiary_no' => 'public_expense.beneficiary_no',
            'issued_on', 'prescription_issued_on', 'prescription.issued_on' => 'prescription.issued_on',
            'received_on', 'prescription_received_on', 'prescription.received_on' => 'prescription.received_on',
            'expires_on', 'valid_until', 'prescription.expires_on', 'prescription.valid_until' => 'prescription.expires_on',
            'medical_institution_code', 'institution_code', 'medical_institution.code' => 'medical_institution.code',
            'medical_institution_name', 'medical_name', 'institution_name', 'medical_institution.name' => 'medical_institution.name',
            'doctor_name', 'medical_institution.doctor_name' => 'medical_institution.doctor_name',
            default => $key,
        };
    }

    /** @param array<string,mixed> $normalized @return array<string,string> */
    private function fieldSnapshot(array $normalized): array
    {
        $out = [];
        foreach (array_keys(self::REPAIRABLE_FIELD_LABELS) as $key) {
            $out[$key] = trim((string)$this->getPath($normalized, $key));
        }
        return $out;
    }

    /** @param array<string,mixed> $array */
    private function getPath(array $array, string $path): mixed
    {
        $cur = $array;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return '';
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    /** @param array<string,mixed> $array */
    private function setPath(array &$array, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $cur =& $array;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $cur[$part] = $value;
                return;
            }
            if (!isset($cur[$part]) || !is_array($cur[$part])) {
                $cur[$part] = [];
            }
            $cur =& $cur[$part];
        }
    }
}
