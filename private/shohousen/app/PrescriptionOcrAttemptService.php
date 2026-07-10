<?php
declare(strict_types=1);

/**
 * 同一処方箋のAI読取は最大2回まで。
 * 1回目と2回目の値を項目ごとに並べ、3回目以降は手入力へ誘導する。
 */
final class PrescriptionOcrAttemptService
{
    public const MAX_ATTEMPTS = 2;

    /** @return array<string,string> */
    public static function coreFieldLabels(): array
    {
        return [
            'patient.name' => '氏名',
            'patient.gender' => '性別',
            'patient.birth_date' => '生年月日',
            'insurance.insurance_no' => '保険者番号',
            'insurance.insured_symbol_number' => '被保険者証・手帳の記号・番号',
            'insurance.copay_rate' => '負担割合',
            'public_expense.payer_no' => '公費負担者番号',
            'public_expense.beneficiary_no' => '公費負担医療の受給者番号',
            'prescription.issued_on' => '交付年月日',
            'prescription.received_on' => '受付年月日',
            'prescription.expires_on' => '処方箋使用期間',
            'medical_institution.code' => '医療機関コード',
            'medical_institution.prefecture_no' => '都道府県番号',
            'medical_institution.score_table_no' => '点数表番号',
            'medical_institution.name' => '保険医療機関名',
            'medical_institution.address' => '保険医療機関所在地',
            'medical_institution.phone' => '電話番号',
            'medical_institution.doctor_name' => '保険医氏名',
            'substitution.change_disallowed' => '変更不可',
            'substitution.doctor_signature_or_seal' => '保険医署名・記名押印',
            'substitution.mark_check_detected' => '✅/レ点',
            'substitution.mark_x_detected' => '×',
        ];
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    public function attachFirstAttempt(array $normalized, array $ai = [], array $meta = []): array
    {
        $attempt = $this->buildAttempt(1, $normalized, $ai, $meta);
        $normalized['_ocr_attempts'] = [$attempt];
        return $this->refreshAttemptSummary($normalized);
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $newNormalized @return array<string,mixed> */
    public function mergeRetryAttempt(array $base, array $newNormalized, array $ai = [], array $meta = []): array
    {
        $attempts = is_array($base['_ocr_attempts'] ?? null) ? array_values($base['_ocr_attempts']) : [];
        if (!$attempts) {
            $attempts[] = $this->buildAttempt(1, $base, [], ['restored_from_existing_normalized' => true]);
        }
        if (count($attempts) >= self::MAX_ATTEMPTS) {
            throw new RuntimeException('再読み込みは2回までです。以降は手入力で修正してください。');
        }
        $attempts[] = $this->buildAttempt(count($attempts) + 1, $newNormalized, $ai, $meta);

        $merged = $base;
        $merged['_ocr_attempts'] = $attempts;
        $merged['_retry_policy'] = [
            'max_attempts' => self::MAX_ATTEMPTS,
            'message' => '1回目と2回目を比較して選択してください。3回目以降は再読込不可です。',
        ];

        // 1回目が空欄で2回目に値がある項目だけ、自動で2回目の値を表示初期値に補助する。
        foreach (array_keys(self::coreFieldLabels()) as $path) {
            $current = trim((string)$this->getPath($merged, $path));
            $new = trim((string)$this->getPath($newNormalized, $path));
            if ($current === '' && $new !== '') {
                $this->setPath($merged, $path, $new);
            }
        }

        if (empty($merged['medications']) && !empty($newNormalized['medications'])) {
            $merged['medications'] = $newNormalized['medications'];
        }

        // 再評価して、最新の表示値に対する判定不能/NGを作り直す。
        if (class_exists('PrescriptionFieldPostProcessorService')) {
            $merged = (new PrescriptionFieldPostProcessorService())->process($merged);
        }
        $merged['_ocr_attempts'] = $attempts;
        return $this->refreshAttemptSummary($merged);
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function buildAttempt(int $attemptNo, array $normalized, array $ai, array $meta): array
    {
        return [
            'attempt_no' => $attemptNo,
            'read_at' => date('c'),
            'model' => (string)($meta['model_name'] ?? ($ai['model'] ?? '')),
            'meta' => $meta,
            'values' => $this->fieldValues($normalized),
            'medications' => $this->medicationValues($normalized),
            'validation_summary' => is_array($normalized['validation_summary'] ?? null) ? $normalized['validation_summary'] : [],
            'warnings' => array_values(array_map('strval', (array)($normalized['warnings'] ?? []))),
        ];
    }

    /** @param array<string,mixed> $normalized @return array<string,string> */
    private function fieldValues(array $normalized): array
    {
        $values = [];
        foreach (array_keys(self::coreFieldLabels()) as $path) {
            $v = $this->getPath($normalized, $path);
            if (is_bool($v)) {
                $values[$path] = $v ? '有' : '無';
            } else {
                $values[$path] = trim((string)$v);
            }
        }
        return $values;
    }

    /** @param array<string,mixed> $normalized @return array<int,array<string,string>> */
    private function medicationValues(array $normalized): array
    {
        $out = [];
        foreach ((array)($normalized['medications'] ?? []) as $med) {
            if (!is_array($med)) {
                continue;
            }
            $out[] = [
                'drug_name' => trim((string)($med['drug_name'] ?? '')),
                'dose_text' => trim((string)($med['dose_text'] ?? '')),
                'usage_text' => trim((string)($med['usage_text'] ?? '')),
                'days_count' => trim((string)($med['days_count'] ?? '')),
                'amount_text' => trim((string)($med['amount_text'] ?? '')),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    public function refreshAttemptSummary(array $normalized): array
    {
        $attempts = is_array($normalized['_ocr_attempts'] ?? null) ? array_values($normalized['_ocr_attempts']) : [];
        $normalized['_ocr_attempt_count'] = count($attempts);
        $normalized['_can_retry_ocr'] = count($attempts) > 0 && count($attempts) < self::MAX_ATTEMPTS;
        $normalized['_ocr_retry_disabled_reason'] = count($attempts) >= self::MAX_ATTEMPTS ? '再読み込みは2回までです。以降は手入力で修正してください。' : '';
        return $normalized;
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
