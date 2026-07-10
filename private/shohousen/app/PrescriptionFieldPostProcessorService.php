<?php
declare(strict_types=1);

/**
 * OpenAIの自己申告confidenceをそのまま使わず、
 * ルール・辞書・必須項目・図形/チェック欄の読取状態から処方箋OCR結果を後処理する。
 *
 * 目的:
 * - gpt-4o-mini等の低価格モデルでも、読取後の検証軸を固定する。
 * - 拠点/地域差で変わる評価は form_fields に残し、全国共通ルールはここで機械判定する。
 * - 画面導線が変わっても normalized_json に評価理由を残す。
 */
final class PrescriptionFieldPostProcessorService
{
    /** @return array<string,mixed> */
    public function process(array $normalized): array
    {
        $normalized = $this->hydrateFixedSectionsFromFormFields($normalized);
        $normalized = $this->applyReferenceValidation($normalized);
        $normalized = $this->enrichMedications($normalized);
        $normalized['form_fields'] = $this->ensureTargetFields((array)($normalized['form_fields'] ?? []), $normalized);

        $validations = $this->buildValidations($normalized);
        $summary = $this->summarizeValidations($validations);
        $normalized['field_validations'] = $validations;
        $normalized['validation_summary'] = $summary;

        // AIの自己申告confidenceは保持しつつ、画面/ルール側で見る値はPHP検証後の点数に寄せる。
        if (!array_key_exists('ai_overall_confidence', $normalized)) {
            $normalized['ai_overall_confidence'] = is_numeric($normalized['overall_confidence'] ?? null) ? (float)$normalized['overall_confidence'] : null;
        }
        $normalized['system_confidence'] = (float)($summary['final_score'] ?? 0.0);
        $normalized['overall_confidence'] = (float)($summary['final_score'] ?? 0.0);
        $normalized['needs_human_check'] = !empty($summary['needs_human_check']);
        $normalized['qr_ready'] = !empty($summary['qr_ready']);
        $normalized['qr_block_reason'] = !empty($summary['qr_ready']) ? '' : '判定不能またはNGの必須項目があります。入力・選択・手修正が終わるまでQR作成へ進めません。';

        return $normalized;
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function hydrateFixedSectionsFromFormFields(array $normalized): array
    {
        $fields = (array)($normalized['form_fields'] ?? []);
        $normalized['public_expense'] = is_array($normalized['public_expense'] ?? null) ? $normalized['public_expense'] : [];
        $normalized['substitution'] = is_array($normalized['substitution'] ?? null) ? $normalized['substitution'] : [];
        $normalized['medical_institution'] = is_array($normalized['medical_institution'] ?? null) ? $normalized['medical_institution'] : [];
        $normalized['patient'] = is_array($normalized['patient'] ?? null) ? $normalized['patient'] : [];
        $normalized['insurance'] = is_array($normalized['insurance'] ?? null) ? $normalized['insurance'] : [];
        $normalized['prescription'] = is_array($normalized['prescription'] ?? null) ? $normalized['prescription'] : [];

        $assignIfEmpty = static function (array &$section, string $key, string $value): void {
            if (trim((string)($section[$key] ?? '')) === '' && trim($value) !== '') {
                $section[$key] = trim($value);
            }
        };

        $assignIfEmpty($normalized['patient'], 'kana', $this->findFieldValue($fields, ['フリガナ', 'ふりがな', 'ふり仮名', 'カナ', 'かな']));
        $assignIfEmpty($normalized['public_expense'], 'payer_no', $this->findFieldValue($fields, ['公費負担者番号', '公費負担番号', '公費負担者']));
        $assignIfEmpty($normalized['public_expense'], 'beneficiary_no', $this->findFieldValue($fields, ['公費負担医療の受給者番号', '受給者番号', '公費受給者番号']));
        $assignIfEmpty($normalized['insurance'], 'insured_symbol_number', $this->findFieldValue($fields, ['被保険者証', '記号・番号', '記号番号', '被保険者手帳']));
        $assignIfEmpty($normalized['medical_institution'], 'address', $this->findFieldValue($fields, ['所在地', '医療機関の所在地', '保険医療機関の所在地']));
        $assignIfEmpty($normalized['medical_institution'], 'prefecture_no', $this->findFieldValue($fields, ['都道府県番号']));
        $assignIfEmpty($normalized['medical_institution'], 'score_table_no', $this->findFieldValue($fields, ['点数表番号']));
        $assignIfEmpty($normalized['medical_institution'], 'code', $this->findFieldValue($fields, ['医療機関コード', '医療機関等コード']));
        $assignIfEmpty($normalized['medical_institution'], 'doctor_name', $this->findFieldValue($fields, ['保険医氏名', '医師氏名', '医師名', '署名']));

        $normalized['substitution']['change_disallowed'] = !empty($normalized['substitution']['change_disallowed']) || $this->fieldLooksChecked($fields, ['変更不可', '後発品変更不可', '医療上必要']);
        $normalized['substitution']['patient_request'] = !empty($normalized['substitution']['patient_request']) || $this->fieldLooksChecked($fields, ['患者希望', '先発希望']);
        $normalized['substitution']['doctor_signature_or_seal'] = !empty($normalized['substitution']['doctor_signature_or_seal'])
            || trim((string)($normalized['medical_institution']['doctor_name'] ?? '')) !== ''
            || $this->fieldLooksPresent($fields, ['保険医署名', '医師署名', '記名押印', '署名', '押印']);
        $normalized['substitution']['mark_check_detected'] = !empty($normalized['substitution']['mark_check_detected']) || $this->fieldLooksChecked($fields, ['✓', '✔', 'チェック', 'レ点', '☑', '有']);
        $normalized['substitution']['mark_x_detected'] = !empty($normalized['substitution']['mark_x_detected']) || $this->fieldLooksChecked($fields, ['×', '✕', 'X', 'バツ']);

        $normalized['public_expense'] += ['payer_no' => '', 'beneficiary_no' => '', 'confidence' => 0.0, 'needs_human_check' => false];
        $normalized['substitution'] += [
            'change_disallowed' => false,
            'patient_request' => false,
            'doctor_signature_or_seal' => false,
            'mark_check_detected' => false,
            'mark_x_detected' => false,
            'confidence' => 0.0,
            'needs_human_check' => false,
        ];
        $normalized['medical_institution'] += ['address' => '', 'prefecture_no' => '', 'score_table_no' => ''];

        return $normalized;
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function applyReferenceValidation(array $normalized): array
    {
        if (!class_exists('PrescriptionReferenceRuleService')) {
            return $normalized;
        }

        foreach ([['patient','birth_date'], ['prescription','issued_on'], ['prescription','expires_on']] as [$section, $key]) {
            $raw = trim((string)($normalized[$section][$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $date = PrescriptionReferenceRuleService::normalizeDate($raw);
            if (is_string($date['normalized'] ?? null) && $date['normalized'] !== '') {
                $normalized[$section][$key] = (string)$date['normalized'];
            }
            if (!empty($date['needs_human_check'])) {
                $normalized[$section]['needs_human_check'] = true;
                $normalized['warnings'][] = $section . '.' . $key . ': ' . (string)($date['message'] ?? '日付確認');
            }
        }

        $codeMap = [
            ['insurance', 'insurance_no', 'insurance_no', '保険者番号'],
            ['public_expense', 'payer_no', 'public_payer_no', '公費負担者番号'],
            ['public_expense', 'beneficiary_no', 'public_beneficiary_no', '公費負担医療の受給者番号'],
            ['medical_institution', 'code', 'medical_institution_code', '医療機関コード'],
        ];
        foreach ($codeMap as [$section, $key, $type, $label]) {
            $raw = trim((string)($normalized[$section][$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $result = PrescriptionReferenceRuleService::validateCode((string)$type, $raw);
            $normalized[$section][$key . '_validation'] = $result;
            if (!empty($result['valid'])) {
                $normalized[$section][$key] = (string)$result['digits'];
            } else {
                $normalized[$section]['needs_human_check'] = true;
                $normalized['warnings'][] = $label . ': ' . (string)($result['message'] ?? '形式確認');
            }
        }

        $normalized['warnings'] = array_values(array_unique(array_filter(array_map('strval', (array)($normalized['warnings'] ?? [])))));
        return $normalized;
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function enrichMedications(array $normalized): array
    {
        $meds = [];
        foreach ((array)($normalized['medications'] ?? []) as $med) {
            if (!is_array($med)) {
                continue;
            }
            $drugName = trim((string)($med['drug_name'] ?? ''));
            $genericName = trim((string)($med['generic_name'] ?? ''));
            $brandName = trim((string)($med['brand_name'] ?? ''));
            $rawDrugText = trim((string)($med['raw_drug_text'] ?? ''));
            $doseText = trim((string)($med['dose_text'] ?? ''));
            $usageText = trim((string)($med['usage_text'] ?? ''));
            $amountText = trim((string)($med['amount_text'] ?? ''));

            if ($rawDrugText === '') {
                $rawDrugText = implode("\n", array_values(array_filter([$drugName, $genericName, $brandName, $doseText, $usageText, $amountText], static fn($v) => trim((string)$v) !== '')));
            }

            $usageSupplement = $this->extractMedicationUsageSupplement($rawDrugText, $drugName, $usageText);
            if ($usageSupplement !== '') {
                $usageText = $usageText === '' ? $usageSupplement : $this->appendUniqueText($usageText, $usageSupplement);
            }

            if ($drugName === '' && ($brandName !== '' || $genericName !== '')) {
                $drugName = $brandName !== '' ? $brandName : $genericName;
            }

            $med['drug_name'] = $drugName;
            $med['generic_name'] = $genericName;
            $med['brand_name'] = $brandName;
            $med['raw_drug_text'] = $rawDrugText;
            $med['dose_text'] = $doseText;
            $med['usage_text'] = $usageText;
            $med['amount_text'] = $amountText;
            $meds[] = $med;
        }
        $normalized['medications'] = $meds;
        return $normalized;
    }

    private function appendUniqueText(string $base, string $addition): string
    {
        $base = trim($base);
        $addition = trim($addition);
        if ($addition === '' || $base === '') {
            return $base !== '' ? $base : $addition;
        }
        $baseCompact = preg_replace('/\s+/u', '', $base) ?? $base;
        $additionCompact = preg_replace('/\s+/u', '', $addition) ?? $addition;
        if ($additionCompact !== '' && str_contains($baseCompact, $additionCompact)) {
            return $base;
        }
        return $base . ' ' . $addition;
    }

    private function extractMedicationUsageSupplement(string $rawDrugText, string $drugName, string $currentUsage): string
    {
        $lines = preg_split('/\R+/u', trim($rawDrugText)) ?: [];
        $out = [];
        $drugCompact = preg_replace('/\s+/u', '', $drugName) ?? $drugName;
        $usageCompact = preg_replace('/\s+/u', '', $currentUsage) ?? $currentUsage;
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $lineCompact = preg_replace('/\s+/u', '', $line) ?? $line;
            if ($drugCompact !== '' && $lineCompact === $drugCompact) {
                continue;
            }
            if ($usageCompact !== '' && str_contains($usageCompact, $lineCompact)) {
                continue;
            }
            if (preg_match('/(頭|頭部|額|顔|頬|口唇|首|胸|腹|背|腕|手|指|足|脚|陰部|患部|乾燥部位|かゆい所|湿疹部位|外用部位|右眼|左眼|両眼|鼻|耳|口腔|舌下|部位|塗布|塗擦|貼付|点眼|点耳|噴霧|吸入|うがい|含嗽|1\s*日|１\s*日|1\s*回|１\s*回|分\s*\d+|毎食|朝|昼|夕|就寝|寝る前|食前|食後|頓服|必要時|疼痛時)/u', $line)) {
                $out[] = $line;
            }
        }
        return implode(' ', array_values(array_unique($out)));
    }

    /** @param array<string,mixed> $normalized @return array<int,array<string,mixed>> */
    private function buildValidations(array $normalized): array
    {
        $v = [];
        $this->addRequired($v, 'patient.name', '氏名', (string)($normalized['patient']['name'] ?? ''), 10);
        $this->addDate($v, 'patient.birth_date', '生年月日', (string)($normalized['patient']['birth_date'] ?? ''), true, 20);
        $this->addRequired($v, 'insurance.insured_symbol_number', '被保険者証の記号・番号', (string)($normalized['insurance']['insured_symbol_number'] ?? ''), 30);
        $this->addCode($v, 'insurance.insurance_no', '保険者番号', (string)($normalized['insurance']['insurance_no'] ?? ''), 'insurance_no', true, 40);
        $this->addCode($v, 'public_expense.payer_no', '公費負担者番号', (string)($normalized['public_expense']['payer_no'] ?? ''), 'public_payer_no', false, 50);
        $this->addCode($v, 'public_expense.beneficiary_no', '公費負担医療の受給者番号', (string)($normalized['public_expense']['beneficiary_no'] ?? ''), 'public_beneficiary_no', false, 60);
        $this->addDate($v, 'prescription.issued_on', '交付年月日', (string)($normalized['prescription']['issued_on'] ?? ''), true, 70);
        $this->addRequired($v, 'medical_institution.name', '保険医療機関の名称', (string)($normalized['medical_institution']['name'] ?? ''), 80);
        $this->addCode($v, 'medical_institution.code', '医療機関コード', (string)($normalized['medical_institution']['code'] ?? ''), 'medical_institution_code', true, 90);
        $this->addRequired($v, 'medical_institution.doctor_name', '保険医氏名', (string)($normalized['medical_institution']['doctor_name'] ?? ''), 100, true);

        $changeDisallowed = !empty($normalized['substitution']['change_disallowed']);
        $doctorSignature = !empty($normalized['substitution']['doctor_signature_or_seal']);
        if ($changeDisallowed && !$doctorSignature) {
            $v[] = $this->validation('substitution.change_disallowed', '変更不可・署名確認', '変更不可あり/署名未確認', 'ng', 20, true, '変更不可欄にチェックまたは×があるのに、保険医署名/記名押印が確認できません。', 110);
        } else {
            $v[] = $this->validation('substitution.change_disallowed', '変更不可・署名確認', $changeDisallowed ? '変更不可あり' : '変更不可なし', 'ok', $changeDisallowed ? 85 : 100, false, $changeDisallowed ? '署名または記名押印候補あり。' : '変更不可欄の有効チェックなし。', 110);
        }

        $meds = (array)($normalized['medications'] ?? []);
        if (!$meds) {
            $v[] = $this->validation('medications', '薬の情報', '', 'ng', 0, true, '処方薬が0件です。OCR失敗または処方欄の読取漏れです。', 120);
        }
        foreach ($meds as $i => $med) {
            if (!is_array($med)) {
                continue;
            }
            $n = $i + 1;
            $drug = trim((string)($med['drug_name'] ?? ''));
            $dictScore = (float)($med['_drug_dictionary_score'] ?? 0.0);
            if ($drug === '') {
                $v[] = $this->validation('medications.' . $n . '.drug_name', '処方' . $n . ' 薬品名', '', 'ng', 0, true, '薬品名が空欄です。', 130 + $n);
            } elseif ($dictScore >= 95.0) {
                $v[] = $this->validation('medications.' . $n . '.drug_name', '処方' . $n . ' 薬品名', $drug, 'ok', 95, false, '薬品辞書に高一致候補があります。', 130 + $n);
            } elseif ($dictScore >= 78.0) {
                $v[] = $this->validation('medications.' . $n . '.drug_name', '処方' . $n . ' 薬品名', $drug, 'review', 75, true, '薬品辞書に候補はありますが自動確定せず確認してください。', 130 + $n);
            } else {
                $v[] = $this->validation('medications.' . $n . '.drug_name', '処方' . $n . ' 薬品名', $drug, 'review', 45, true, '薬品辞書に十分一致する候補がありません。', 130 + $n);
            }
        }

        usort($v, static fn(array $a, array $b): int => ((int)($a['sort_order'] ?? 999) <=> (int)($b['sort_order'] ?? 999)));
        return $v;
    }

    /** @param array<int,array<string,mixed>> $validations @return array<string,mixed> */
    private function summarizeValidations(array $validations): array
    {
        if (!$validations) {
            return ['final_score' => 0.0, 'needs_human_check' => true, 'ng' => 0, 'review' => 0, 'ok' => 0];
        }
        $sum = 0.0;
        $weight = 0.0;
        $ng = $review = $ok = $unknown = 0;
        foreach ($validations as $row) {
            $score = (float)($row['final_score'] ?? 0.0);
            $w = !empty($row['required']) ? 2.0 : 1.0;
            $sum += $score * $w;
            $weight += $w;
            $status = (string)($row['status'] ?? 'review');
            if ($status === 'ng') $ng++;
            elseif ($status === 'unknown') $unknown = ($unknown ?? 0) + 1;
            elseif ($status === 'ok') $ok++;
            else $review++;
        }
        $final = round($sum / max(1.0, $weight), 2);
        $blocksQr = 0;
        foreach ($validations as $row) {
            if (!empty($row['blocks_qr'])) {
                $blocksQr++;
            }
        }
        return [
            'final_score' => $final,
            'needs_human_check' => $ng > 0 || $review > 0 || $unknown > 0 || $final < 85.0,
            'qr_ready' => $blocksQr === 0,
            'blocks_qr' => $blocksQr,
            'ng' => $ng,
            'unknown' => $unknown,
            'review' => $review,
            'ok' => $ok,
        ];
    }

    /** @param array<int,array<string,mixed>> $out */
    private function addRequired(array &$out, string $key, string $label, string $value, int $sortOrder, bool $required = true): void
    {
        $value = trim($value);
        $out[] = $value !== ''
            ? $this->validation($key, $label, $value, 'ok', 95, false, '値あり。', $sortOrder, $required)
            : $this->validation($key, $label, $value, $required ? 'unknown' : 'review', $required ? 0 : 45, $required, '判定不能: ' . $label . 'が空欄またはAI読取不可です。入力しないとQR作成へ進めません。', $sortOrder, $required);
    }

    /** @param array<int,array<string,mixed>> $out */
    private function addDate(array &$out, string $key, string $label, string $value, bool $required, int $sortOrder): void
    {
        $value = trim($value);
        if ($value === '') {
            $out[] = $this->validation($key, $label, '', $required ? 'unknown' : 'ok', $required ? 0 : 100, $required, $required ? '判定不能: ' . $label . 'が空欄またはAI読取不可です。入力しないとQR作成へ進めません。' : '空欄許容。', $sortOrder, $required);
            return;
        }
        if (!class_exists('PrescriptionReferenceRuleService')) {
            $out[] = $this->validation($key, $label, $value, 'review', 60, true, '日付ルールサービス未読込。', $sortOrder, $required);
            return;
        }
        $date = PrescriptionReferenceRuleService::normalizeDate($value);
        $ok = empty($date['needs_human_check']) && is_string($date['normalized'] ?? null) && $date['normalized'] !== '';
        $out[] = $this->validation($key, $label, $value, $ok ? 'ok' : 'review', $ok ? 95 : 35, !$ok, $ok ? '日付として成立。' : (string)($date['message'] ?? '日付確認'), $sortOrder, $required, (string)($date['normalized'] ?? ''));
    }

    /** @param array<int,array<string,mixed>> $out */
    private function addCode(array &$out, string $key, string $label, string $value, string $type, bool $required, int $sortOrder): void
    {
        $value = trim($value);
        if ($value === '') {
            $out[] = $this->validation($key, $label, '', $required ? 'unknown' : 'ok', $required ? 0 : 100, $required, $required ? '判定不能: ' . $label . 'が空欄またはAI読取不可です。入力しないとQR作成へ進めません。' : '空欄許容。', $sortOrder, $required);
            return;
        }
        if (!class_exists('PrescriptionReferenceRuleService')) {
            $out[] = $this->validation($key, $label, $value, 'review', 60, true, 'コードルールサービス未読込。', $sortOrder, $required);
            return;
        }
        $result = PrescriptionReferenceRuleService::validateCode($type, $value);
        $ok = !empty($result['valid']);
        $out[] = $this->validation($key, $label, $value, $ok ? 'ok' : 'ng', $ok ? 96 : 5, !$ok, $ok ? ('形式OK: ' . (string)($result['classification'] ?? '')) : (string)($result['message'] ?? 'コード形式不正'), $sortOrder, $required, (string)($result['digits'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function validation(string $key, string $label, string $rawValue, string $status, float $score, bool $needsHumanCheck, string $reason, int $sortOrder, bool $required = true, string $normalizedValue = ''): array
    {
        $blocksQr = $required && in_array($status, ['unknown', 'ng', 'review'], true);
        return [
            'field_key' => $key,
            'field_label' => $label,
            'raw_value' => $rawValue,
            'normalized_value' => $normalizedValue,
            'status' => $status,
            'required' => $required,
            'final_score' => round(max(0.0, min(100.0, $score)), 2),
            'needs_human_check' => $needsHumanCheck,
            'blocks_qr' => $blocksQr,
            'reason' => $reason,
            'sort_order' => $sortOrder,
        ];
    }

    /** @param array<int,array<string,mixed>> $fields @return array<int,array<string,mixed>> */
    private function ensureTargetFields(array $fields, array $normalized): array
    {
        $add = function (string $key, string $label, string $group, string $value, string $type = 'text', bool $include = true) use (&$fields): void {
            foreach ($fields as $field) {
                if ((string)($field['field_key'] ?? '') === $key) {
                    return;
                }
            }
            $fields[] = [
                'field_key' => $key,
                'field_label' => $label,
                'field_group' => $group,
                'value' => $value,
                'value_type' => $type,
                'source_section' => $group,
                'confidence' => 0.0,
                'needs_human_check' => trim($value) === '',
                'include_default' => $include && trim($value) !== '',
                'output_candidate' => true,
                'reason' => 'php_post_processor',
            ];
        };

        $add('public_payer_no', '公費負担者番号', 'public_expense', (string)($normalized['public_expense']['payer_no'] ?? ''), 'code', true);
        $add('public_beneficiary_no', '公費負担医療の受給者番号', 'public_expense', (string)($normalized['public_expense']['beneficiary_no'] ?? ''), 'code', true);
        $add('medical_institution_address', '保険医療機関の所在地', 'medical_institution', (string)($normalized['medical_institution']['address'] ?? ''), 'text', false);
        $add('medical_institution_prefecture_no', '都道府県番号', 'medical_institution', (string)($normalized['medical_institution']['prefecture_no'] ?? ''), 'code', false);
        $add('medical_institution_score_table_no', '点数表番号', 'medical_institution', (string)($normalized['medical_institution']['score_table_no'] ?? ''), 'code', false);
        $add('substitution.change_disallowed', '変更不可', 'prescription', !empty($normalized['substitution']['change_disallowed']) ? '有' : '無', 'boolean', false);
        $add('substitution.doctor_signature_or_seal', '保険医署名・記名押印', 'prescription', !empty($normalized['substitution']['doctor_signature_or_seal']) ? '有' : '無', 'boolean', false);
        $add('substitution.mark_check_detected', '✅/レ点判定', 'prescription', !empty($normalized['substitution']['mark_check_detected']) ? '有' : '無', 'boolean', false);
        $add('substitution.mark_x_detected', '×判定', 'prescription', !empty($normalized['substitution']['mark_x_detected']) ? '有' : '無', 'boolean', false);

        return $fields;
    }

    /** @param array<int,array<string,mixed>> $fields */
    private function findFieldValue(array $fields, array $needles): string
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string)($field['field_key'] ?? '');
            $label = (string)($field['field_label'] ?? '');
            $text = mb_strtolower($key . ' ' . $label);
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($text, mb_strtolower($needle))) {
                    $value = trim((string)($field['value'] ?? $field['field_value'] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
        return '';
    }

    /** @param array<int,array<string,mixed>> $fields */
    private function fieldLooksPresent(array $fields, array $needles): bool
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string)($field['field_key'] ?? '');
            $label = (string)($field['field_label'] ?? '');
            $value = trim((string)($field['value'] ?? $field['field_value'] ?? ''));
            $text = mb_strtolower($key . ' ' . $label . ' ' . $value);
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($text, mb_strtolower($needle))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $fields */
    private function fieldLooksChecked(array $fields, array $needles): bool
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string)($field['field_key'] ?? '');
            $label = (string)($field['field_label'] ?? '');
            $value = trim((string)($field['value'] ?? $field['field_value'] ?? ''));
            $text = mb_strtolower($key . ' ' . $label . ' ' . $value . ' ' . (string)($field['reason'] ?? ''));
            $matchesLabel = false;
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($text, mb_strtolower($needle))) {
                    $matchesLabel = true;
                    break;
                }
            }
            if (!$matchesLabel) {
                continue;
            }
            if ($value === '') {
                continue;
            }
            if (preg_match('/有|あり|true|1|✓|✔|☑|✅|レ|×|✕|x|X|チェック/u', $value) === 1) {
                return true;
            }
        }
        return false;
    }
}
