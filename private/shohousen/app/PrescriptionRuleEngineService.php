<?php
declare(strict_types=1);

/**
 * 処方箋受付時に薬局側で確認すべき運用ルールを、OCR結果/人間修正後データから機械判定する。
 * ここでの判定は薬剤師確認を代替しない。要確認・疑義照会候補・QR作成前の警告を出すための補助機能。
 */
final class PrescriptionRuleEngineService
{
    /** @return array<int,array<string,mixed>> */
    public function evaluateNormalized(array $normalized): array
    {
        return $this->evaluate(self::contextFromNormalized($normalized));
    }

    /** @return array<int,array<string,mixed>> */
    public function evaluatePostData(array $post): array
    {
        return $this->evaluate(self::contextFromPost($post));
    }

    /** @return array<int,array<string,mixed>> */
    public function evaluateSavedPrescription(array $prescription): array
    {
        return $this->evaluate(self::contextFromSavedPrescription($prescription));
    }

    /** @return array<string,int> */
    public static function summarize(array $checks): array
    {
        $summary = ['total' => 0, 'block' => 0, 'danger' => 0, 'warning' => 0, 'info' => 0, 'requires_inquiry' => 0, 'blocks_qr' => 0];
        foreach ($checks as $check) {
            $summary['total']++;
            $severity = (string)($check['severity'] ?? 'info');
            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }
            if (!empty($check['requires_inquiry'])) {
                $summary['requires_inquiry']++;
            }
            if (!empty($check['blocks_qr'])) {
                $summary['blocks_qr']++;
            }
        }
        return $summary;
    }

    public static function hasOpenBlockingChecks(array $checks): bool
    {
        foreach ($checks as $check) {
            $status = (string)($check['status'] ?? 'open');
            if (!empty($check['blocks_qr']) && !in_array($status, ['resolved', 'dismissed'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,array<string,mixed>> */
    private function evaluate(array $ctx): array
    {
        $checks = [];
        $this->ruleValidity($ctx, $checks);
        $this->ruleRequiredFields($ctx, $checks);
        $this->ruleMedicationCompleteness($ctx, $checks);
        $this->ruleConfidence($ctx, $checks);
        $this->ruleChangeDisallowedAndPatientRequest($ctx, $checks);
        $this->ruleGenericPrescription($ctx, $checks);
        $this->ruleReadableValues($ctx, $checks);

        usort($checks, static function (array $a, array $b): int {
            $rank = ['block' => 1, 'danger' => 2, 'warning' => 3, 'info' => 4];
            $ar = $rank[(string)($a['severity'] ?? 'info')] ?? 9;
            $br = $rank[(string)($b['severity'] ?? 'info')] ?? 9;
            return ($ar <=> $br) ?: ((int)($a['sort_order'] ?? 9999) <=> (int)($b['sort_order'] ?? 9999));
        });

        $seen = [];
        $out = [];
        foreach ($checks as $check) {
            $key = implode('|', [
                (string)($check['rule_code'] ?? ''),
                (string)($check['field_key'] ?? ''),
                (string)($check['detected_value'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $check;
        }
        return $out;
    }

    private function ruleValidity(array $ctx, array &$checks): void
    {
        $issuedRaw = (string)($ctx['prescription']['issued_on'] ?? '');
        $validUntilRaw = self::findFieldValue($ctx['fields'], ['使用期間', '有効期限', '処方箋使用期間', 'expires', 'valid_until']);
        if ($validUntilRaw === '') {
            $validUntilRaw = (string)($ctx['prescription']['expires_on'] ?? '');
        }

        $issued = self::parseDate($issuedRaw);
        $validUntil = self::parseDate($validUntilRaw);
        if (!$issued) {
            $this->add($checks, 'RX_VALIDITY_ISSUED_MISSING', 'warning', '処方箋発行日が確認できません', '交付年月日/処方箋発行日が読めないため、使用期間を自動判定できません。', 'prescription', 'prescription.issued_on', $issuedRaw, '発行日を人間確認してください。', false, true, 10);
            return;
        }

        $deadline = $validUntil ?: (clone $issued)->modify('+3 days');
        $today = new DateTimeImmutable('today', new DateTimeZone((string)app_config('app.timezone', 'Asia/Tokyo')));
        if ($today > $deadline) {
            $this->add($checks, 'RX_VALIDITY_EXPIRED', 'block', '処方箋の使用期間切れ候補', '原則は交付日を含め4日以内です。別途使用期間の記載がなければ、期限切れ候補として薬剤師確認が必要です。', 'prescription', 'prescription.issued_on', $issued->format('Y-m-d'), '期限切れの場合は受付/調剤を進めず、薬剤師判断または疑義照会等の運用へ回してください。', true, true, 20);
        } else {
            $this->add($checks, 'RX_VALIDITY_OK', 'info', '処方箋使用期間は範囲内候補', '交付日/使用期間から見る限り、期限切れ候補ではありません。', 'prescription', 'prescription.issued_on', $issued->format('Y-m-d'), '日付読取が正しいかだけ確認してください。', false, false, 900);
        }
    }

    private function ruleRequiredFields(array $ctx, array &$checks): void
    {
        $required = [
            ['patient.name', '患者名', 'patient', (string)($ctx['patient']['name'] ?? '')],
            ['patient.birth_date', '生年月日', 'patient', (string)($ctx['patient']['birth_date'] ?? '')],
            ['insurance.insurance_no', '保険者番号', 'insurance', (string)($ctx['insurance']['insurance_no'] ?? '')],
            ['insurance.insured_symbol_number', '記号番号', 'insurance', (string)($ctx['insurance']['insured_symbol_number'] ?? '')],
            ['medical_institution.name', '医療機関名', 'medical_institution', (string)($ctx['medical_institution']['name'] ?? '')],
            ['medical_institution.doctor_name', '保険医氏名', 'medical_institution', (string)($ctx['medical_institution']['doctor_name'] ?? self::findFieldValue($ctx['fields'], ['保険医氏名', '医師氏名', '医師名']))],
        ];
        foreach ($required as [$key, $label, $group, $value]) {
            if (trim((string)$value) !== '') {
                continue;
            }
            $severity = in_array($key, ['patient.name', 'medical_institution.name'], true) ? 'danger' : 'warning';
            $this->add($checks, 'RX_REQUIRED_FIELD_MISSING', $severity, $label . 'が未確認です', $label . 'が空欄または読取不可です。処方箋受付・QR出力前に人間確認してください。', $group, $key, '', '画像を確認し、読めない場合は手入力または薬剤師確認へ回してください。', false, $severity === 'danger', 100);
        }
    }

    private function ruleMedicationCompleteness(array $ctx, array &$checks): void
    {
        $meds = (array)($ctx['medications'] ?? []);
        if (!$meds) {
            $this->add($checks, 'RX_MEDICATION_EMPTY', 'block', '処方薬が読めていません', '処方内容が0件です。OCR失敗または処方欄の読取漏れの可能性があります。', 'medication', 'medications', '', '再撮影または手入力してください。', false, true, 50);
            return;
        }
        foreach ($meds as $i => $med) {
            $n = $i + 1;
            $drug = trim((string)($med['drug_name'] ?? ''));
            $usage = trim((string)($med['usage_text'] ?? ''));
            $days = trim((string)($med['days_count'] ?? ''));
            $amount = trim((string)($med['amount_text'] ?? ''));
            if ($drug === '') {
                $this->add($checks, 'RX_MEDICATION_DRUG_MISSING', 'block', '処方' . $n . 'の医薬品名が読めていません', '医薬品名の読取不可は重大事故につながるため、QR作成前に必ず修正してください。', 'medication', 'medications.' . $n . '.drug_name', '', '薬品名を手入力し、辞書候補と照合してください。', false, true, 60 + $n);
            }
            if ($usage === '') {
                $this->add($checks, 'RX_MEDICATION_USAGE_MISSING', 'warning', '処方' . $n . 'の用法が未確認です', '用法が空欄または読取不可です。', 'medication', 'medications.' . $n . '.usage_text', $drug, '用法を画像確認または手入力してください。', false, false, 90 + $n);
            }
            if ($days === '' && $amount === '') {
                $this->add($checks, 'RX_MEDICATION_DAYS_AMOUNT_MISSING', 'warning', '処方' . $n . 'の日数/数量が未確認です', '日数または数量が読めていません。', 'medication', 'medications.' . $n . '.days_count', $drug, '日数・総量を確認してください。', false, false, 100 + $n);
            }
        }
    }

    private function ruleConfidence(array $ctx, array &$checks): void
    {
        $overall = $ctx['overall_confidence'] ?? null;
        if (is_numeric($overall)) {
            $score = (float)$overall;
            if ($score < 80.0) {
                $this->add($checks, 'RX_CONFIDENCE_OVERALL_LOW', 'danger', '全体信頼度が低いです', 'OCR全体信頼度が80%未満です。手入力候補として扱ってください。', 'other', 'overall_confidence', (string)$score, '画像品質・読取値を全体確認してください。人間確認後であればQR作成自体は止めません。', false, false, 150);
            } elseif ($score < 90.0) {
                $this->add($checks, 'RX_CONFIDENCE_OVERALL_REVIEW', 'warning', '全体信頼度が要確認です', 'OCR全体信頼度が90%未満です。重要項目を重点確認してください。', 'other', 'overall_confidence', (string)$score, '薬品名・用法・日数・保険番号を重点確認してください。', false, false, 151);
            } elseif ($score < 95.0) {
                $this->add($checks, 'RX_CONFIDENCE_OVERALL_NOTICE', 'info', '全体信頼度は確認推奨です', 'OCR全体信頼度が95%未満です。重要項目は人間確認してください。', 'other', 'overall_confidence', (string)$score, '確認後に保存してください。', false, false, 920);
            }
        }

        foreach ((array)$ctx['fields'] as $field) {
            $conf = $field['confidence'] ?? null;
            if (!is_numeric($conf)) {
                continue;
            }
            $conf = (float)$conf;
            if ($conf >= 90.0) {
                continue;
            }
            $label = (string)($field['field_label'] ?? $field['field_key'] ?? '項目');
            $sev = $conf < 80.0 ? 'danger' : 'warning';
            $this->add($checks, 'RX_FIELD_CONFIDENCE_LOW', $sev, $label . 'の信頼度が低いです', $label . 'の信頼度が' . round($conf, 1) . '%です。', (string)($field['field_group'] ?? 'other'), (string)($field['field_key'] ?? ''), (string)($field['field_value'] ?? $field['value'] ?? ''), '原画像で確認し、必要なら修正してください。', false, false, 170);
        }
    }

    private function ruleChangeDisallowedAndPatientRequest(array $ctx, array &$checks): void
    {
        $fields = (array)$ctx['fields'];
        $changeDisallowed = self::hasCheckedField($fields, ['変更不可', '医療上必要', '不可']);
        $doctorSignature = self::hasAnyValueField($fields, ['医師署名', '保険医署名', '記名押印', '署名', '押印', '保険医氏名']);
        $patientRequest = self::hasCheckedField($fields, ['患者希望', '先発希望', '患者が希望']);

        if ($changeDisallowed && !$doctorSignature) {
            $this->add($checks, 'RX_NO_SUBSTITUTION_SIGNATURE_MISSING', 'danger', '変更不可欄に対する医師署名/記名押印が未確認です', '変更不可（医療上必要）があるのに、医師の署名または記名押印が確認できません。疑義照会候補です。', 'prescription', 'change_disallowed', '変更不可あり / 署名未確認', '処方医へ確認する運用へ回してください。', true, true, 210);
        } elseif ($changeDisallowed) {
            $this->add($checks, 'RX_NO_SUBSTITUTION_CONFIRMED', 'info', '変更不可欄あり', '変更不可（医療上必要）と署名/記名押印らしき項目が確認されています。変更不可扱い候補です。', 'prescription', 'change_disallowed', '変更不可あり', '署名読取が正しいか人間確認してください。', false, false, 930);
        }

        if ($changeDisallowed && $patientRequest) {
            $this->add($checks, 'RX_NO_SUBSTITUTION_AND_PATIENT_REQUEST', 'danger', '変更不可欄と患者希望欄が同時に存在します', '変更不可（医療上必要）と患者希望は通常同時にチェックされないため、疑義照会候補です。', 'prescription', 'change_disallowed_patient_request', '変更不可あり / 患者希望あり', '処方医へ確認する運用へ回してください。', true, true, 220);
        }

        if ($patientRequest) {
            $this->add($checks, 'RX_PATIENT_REQUEST_LONG_LISTED_CHECK', 'warning', '患者希望欄あり：選定療養判定が必要です', '後発医薬品のある先発医薬品を患者希望で選ぶ場合、選定療養の対象となる可能性があります。長期収載品マスタ照合が必要です。', 'prescription', 'patient_request', '患者希望あり', '長期収載品マスタと照合し、対象なら会計/説明運用へ回してください。', false, false, 230);
        }
    }

    private function ruleGenericPrescription(array $ctx, array &$checks): void
    {
        $hasGeneric = false;
        foreach ((array)$ctx['medications'] as $med) {
            $text = implode(' ', [
                (string)($med['drug_name'] ?? ''),
                (string)($med['generic_name'] ?? ''),
                (string)($med['raw_drug_text'] ?? ''),
                (string)($med['name_relation'] ?? $med['drug_name_relation_type'] ?? ''),
            ]);
            if (str_contains($text, '【般】') || str_contains($text, '一般名') || str_contains($text, 'generic_brand_pair')) {
                $hasGeneric = true;
                break;
            }
        }
        foreach ((array)$ctx['fields'] as $field) {
            $text = (string)($field['field_label'] ?? '') . ' ' . (string)($field['field_value'] ?? $field['value'] ?? '');
            if (str_contains($text, '【般】') || str_contains($text, '一般名')) {
                $hasGeneric = true;
                break;
            }
        }
        if (!$hasGeneric) {
            return;
        }

        $fields = (array)$ctx['fields'];
        if (self::hasCheckedField($fields, ['変更不可', '医療上必要', '不可'])) {
            $this->add($checks, 'RX_GENERIC_WITH_CHANGE_DISALLOWED', 'danger', '一般名処方と変更不可欄の組み合わせを確認してください', '一般名処方なのに変更不可欄がある可能性があります。疑義照会候補として確認してください。', 'medication', 'generic_change_disallowed', '一般名処方 + 変更不可', '処方医へ確認する運用へ回してください。', true, true, 240);
        }
        if (self::hasCheckedField($fields, ['患者希望', '先発希望', '患者が希望'])) {
            $this->add($checks, 'RX_GENERIC_WITH_PATIENT_REQUEST', 'warning', '一般名処方と患者希望欄の組み合わせを確認してください', '一般名処方では患者希望欄に通常チェックを付けないため、確認候補です。', 'medication', 'generic_patient_request', '一般名処方 + 患者希望', '原画像と処方意図を確認してください。', true, false, 250);
        }
    }

    private function ruleReadableValues(array $ctx, array &$checks): void
    {
        foreach ((array)$ctx['fields'] as $field) {
            $needs = !empty($field['needs_human_check']);
            $label = (string)($field['field_label'] ?? $field['field_key'] ?? '項目');
            $value = (string)($field['field_value'] ?? $field['value'] ?? '');
            $reason = (string)($field['reason'] ?? $field['source_section'] ?? '');
            if (!$needs && $value !== '') {
                continue;
            }
            $text = $label . ' ' . $value . ' ' . $reason;
            if (!preg_match('/読取不可|不明|判読|手書き|ぼけ|滲|にじ|薄い|小さい|影|要確認/u', $text)) {
                continue;
            }
            $this->add($checks, 'RX_UNREADABLE_FIELD', 'warning', $label . 'が要確認です', $label . 'に読取不可/手書き/低画質などの要確認理由があります。', (string)($field['field_group'] ?? 'other'), (string)($field['field_key'] ?? ''), $value, '原画像を確認し、必要なら手入力してください。', false, false, 300);
        }
    }

    private function add(array &$checks, string $code, string $severity, string $title, string $message, string $group, string $key, string $detected, string $action, bool $requiresInquiry, bool $blocksQr, int $sort): void
    {
        $checks[] = [
            'rule_code' => $code,
            'severity' => in_array($severity, ['info', 'warning', 'danger', 'block'], true) ? $severity : 'info',
            'title' => $title,
            'message' => $message,
            'field_group' => $group,
            'field_key' => $key,
            'detected_value' => $detected,
            'recommended_action' => $action,
            'requires_inquiry' => $requiresInquiry ? 1 : 0,
            'blocks_qr' => $blocksQr ? 1 : 0,
            'status' => 'open',
            'sort_order' => $sort,
        ];
    }

    /** @return array<string,mixed> */
    private static function contextFromNormalized(array $data): array
    {
        return [
            'patient' => (array)($data['patient'] ?? []),
            'insurance' => (array)($data['insurance'] ?? []),
            'prescription' => (array)($data['prescription'] ?? []),
            'medical_institution' => (array)($data['medical_institution'] ?? []),
            'medications' => array_values((array)($data['medications'] ?? [])),
            'fields' => self::fieldsFromNormalized($data),
            'overall_confidence' => $data['overall_confidence'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private static function contextFromPost(array $post): array
    {
        $fields = function_exists('selected_prescription_fields_from_post') ? selected_prescription_fields_from_post($post) : [];
        $meds = [];
        foreach ((array)($post['drug_name'] ?? []) as $i => $drugName) {
            $meds[] = [
                'drug_name' => trim((string)$drugName),
                'generic_name' => trim((string)(($post['generic_name'] ?? [])[$i] ?? '')),
                'brand_name' => trim((string)(($post['brand_name'] ?? [])[$i] ?? '')),
                'raw_drug_text' => trim((string)(($post['raw_drug_text'] ?? [])[$i] ?? '')),
                'name_relation' => trim((string)(($post['drug_name_relation_type'] ?? [])[$i] ?? '')),
                'usage_text' => trim((string)(($post['usage_text'] ?? [])[$i] ?? '')),
                'days_count' => trim((string)(($post['days_count'] ?? [])[$i] ?? '')),
                'amount_text' => trim((string)(($post['amount_text'] ?? [])[$i] ?? '')),
            ];
        }
        return [
            'patient' => ['name' => $post['patient_name'] ?? '', 'birth_date' => $post['birth_date'] ?? '', 'gender' => $post['gender'] ?? ''],
            'insurance' => ['insurance_no' => $post['insurance_no'] ?? '', 'insured_symbol_number' => $post['insured_symbol_number'] ?? '', 'copay_rate' => $post['copay_rate'] ?? ''],
            'prescription' => ['issued_on' => $post['issued_on'] ?? '', 'expires_on' => self::findFieldValue($fields, ['使用期間', '有効期限', '処方箋使用期間'])],
            'medical_institution' => ['code' => $post['medical_institution_code'] ?? '', 'name' => $post['medical_institution_name'] ?? '', 'doctor_name' => self::findFieldValue($fields, ['保険医氏名', '医師氏名', '医師名'])],
            'medications' => $meds,
            'fields' => $fields,
            'overall_confidence' => $post['ai_confidence'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private static function contextFromSavedPrescription(array $p): array
    {
        $fields = array_map(static function (array $row): array {
            return [
                'field_key' => (string)($row['field_key'] ?? ''),
                'field_label' => (string)($row['field_label'] ?? ''),
                'field_group' => (string)($row['field_group'] ?? 'other'),
                'field_value' => (string)($row['field_value'] ?? ''),
                'source_ai_value' => (string)($row['source_ai_value'] ?? ''),
                'confidence' => $row['confidence'] ?? null,
                'needs_human_check' => !empty($row['needs_human_check']),
                'source_section' => (string)($row['source_section'] ?? ''),
            ];
        }, (array)($p['selected_fields'] ?? []));

        return [
            'patient' => ['name' => $p['patient_name'] ?? '', 'birth_date' => $p['birth_date'] ?? '', 'gender' => $p['gender'] ?? ''],
            'insurance' => ['insurance_no' => $p['insurance_no'] ?? '', 'insured_symbol_number' => $p['insured_symbol_number'] ?? '', 'copay_rate' => $p['copay_rate'] ?? ''],
            'prescription' => ['issued_on' => $p['issued_on'] ?? '', 'expires_on' => self::findFieldValue($fields, ['使用期間', '有効期限', '処方箋使用期間'])],
            'medical_institution' => ['code' => $p['institution_code'] ?? '', 'name' => $p['medical_name'] ?? '', 'doctor_name' => self::findFieldValue($fields, ['保険医氏名', '医師氏名', '医師名'])],
            'medications' => array_values((array)($p['medications'] ?? [])),
            'fields' => $fields,
            'overall_confidence' => $p['ai_confidence'] ?? null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function fieldsFromNormalized(array $data): array
    {
        $out = [];
        foreach ((array)($data['form_fields'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'field_key' => (string)($row['field_key'] ?? ''),
                'field_label' => (string)($row['field_label'] ?? ''),
                'field_group' => (string)($row['field_group'] ?? 'other'),
                'field_value' => (string)($row['value'] ?? ''),
                'value' => (string)($row['value'] ?? ''),
                'confidence' => $row['confidence'] ?? null,
                'needs_human_check' => !empty($row['needs_human_check']),
                'reason' => (string)($row['reason'] ?? ''),
                'source_section' => (string)($row['source_section'] ?? ''),
            ];
        }
        return $out;
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        $normalized = function_exists('normalize_prescription_date_value') ? normalize_prescription_date_value($value) : null;
        if (!$normalized && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            $normalized = trim($value);
        }
        if (!$normalized) {
            return null;
        }
        try {
            return new DateTimeImmutable($normalized, new DateTimeZone((string)app_config('app.timezone', 'Asia/Tokyo')));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int,array<string,mixed>> $fields */
    private static function findFieldValue(array $fields, array $needles): string
    {
        foreach ($fields as $field) {
            $key = (string)($field['field_key'] ?? '');
            $label = (string)($field['field_label'] ?? '');
            $text = mb_strtolower($key . ' ' . $label);
            foreach ($needles as $needle) {
                if (str_contains($text, mb_strtolower((string)$needle))) {
                    $value = trim((string)($field['field_value'] ?? $field['value'] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
        return '';
    }

    /** @param array<int,array<string,mixed>> $fields */
    private static function hasCheckedField(array $fields, array $needles): bool
    {
        foreach ($fields as $field) {
            $key = (string)($field['field_key'] ?? '');
            $label = (string)($field['field_label'] ?? '');
            $text = mb_strtolower($key . ' ' . $label);
            $matches = false;
            foreach ($needles as $needle) {
                if (str_contains($text, mb_strtolower((string)$needle))) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            $value = self::normalizeCheckValue((string)($field['field_value'] ?? $field['value'] ?? ''));
            if (in_array($value, ['1', 'true', 'yes', '有', 'あり', 'はい', '○', '〇', '✓', '✔', 'レ', 'チェック', 'x', '×', '不可', '変更不可'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $fields */
    private static function hasAnyValueField(array $fields, array $needles): bool
    {
        foreach ($fields as $field) {
            $key = (string)($field['field_key'] ?? '');
            $label = (string)($field['field_label'] ?? '');
            $text = mb_strtolower($key . ' ' . $label);
            foreach ($needles as $needle) {
                if (str_contains($text, mb_strtolower((string)$needle)) && trim((string)($field['field_value'] ?? $field['value'] ?? '')) !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    private static function normalizeCheckValue(string $value): string
    {
        $value = trim(mb_convert_kana($value, 'asKV'));
        $value = str_replace([' ', '　', '\r', '\n', '\t'], '', $value);
        return mb_strtolower($value);
    }

    /** @param array<int,array<string,mixed>> $checks */
    public function saveRuleChecks(int $tenantId, int $prescriptionId, ?int $parseJobId, array $checks): void
    {
        $pdo = Db::branch();
        if (!Db::tableExists($pdo, 'prescription_rule_checks')) {
            return;
        }
        $pdo->prepare('DELETE FROM prescription_rule_checks WHERE prescription_id = :prescription_id AND tenant_id = :tenant_id')
            ->execute([':prescription_id' => $prescriptionId, ':tenant_id' => $tenantId]);
        if (!$checks) {
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO prescription_rule_checks
            (prescription_id, parse_job_id, company_uid, branch_uid, tenant_id, rule_code, severity, title, message, field_group, field_key, detected_value, recommended_action, requires_inquiry, blocks_qr, status, sort_order, created_at, updated_at)
            VALUES
            (:prescription_id, :parse_job_id, :company_uid, :branch_uid, :tenant_id, :rule_code, :severity, :title, :message, :field_group, :field_key, :detected_value, :recommended_action, :requires_inquiry, :blocks_qr, "open", :sort_order, NOW(), NOW())');
        foreach ($checks as $check) {
            $stmt->execute([
                ':prescription_id' => $prescriptionId,
                ':parse_job_id' => $parseJobId,
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':rule_code' => mb_substr((string)($check['rule_code'] ?? ''), 0, 80),
                ':severity' => in_array((string)($check['severity'] ?? ''), ['info','warning','danger','block'], true) ? (string)$check['severity'] : 'info',
                ':title' => mb_substr((string)($check['title'] ?? ''), 0, 160),
                ':message' => (string)($check['message'] ?? ''),
                ':field_group' => mb_substr((string)($check['field_group'] ?? 'other'), 0, 64),
                ':field_key' => mb_substr((string)($check['field_key'] ?? ''), 0, 120),
                ':detected_value' => (string)($check['detected_value'] ?? ''),
                ':recommended_action' => (string)($check['recommended_action'] ?? ''),
                ':requires_inquiry' => !empty($check['requires_inquiry']) ? 1 : 0,
                ':blocks_qr' => !empty($check['blocks_qr']) ? 1 : 0,
                ':sort_order' => (int)($check['sort_order'] ?? 9999),
            ]);
        }
        $this->saveKnowledgeRuleScores($tenantId, $parseJobId, $checks);
    }

    /** @return array<int,array<string,mixed>> */
    public function loadRuleChecks(int $tenantId, int $prescriptionId): array
    {
        $pdo = Db::branch();
        if (!Db::tableExists($pdo, 'prescription_rule_checks')) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM prescription_rule_checks WHERE prescription_id = :prescription_id AND tenant_id = :tenant_id ORDER BY sort_order, id');
        $stmt->execute([':prescription_id' => $prescriptionId, ':tenant_id' => $tenantId]);
        return $stmt->fetchAll();
    }

    /** @param array<int,array<string,mixed>> $checks */
    private function saveKnowledgeRuleScores(int $tenantId, ?int $parseJobId, array $checks): void
    {
        try {
            $pdo = Db::knowledge();
            if (!Db::tableExists($pdo, 'prescription_rule_learning_scores')) {
                return;
            }
            $stmt = $pdo->prepare('INSERT INTO prescription_rule_learning_scores
                (company_uid, branch_uid, tenant_id, rule_code, severity, trigger_count, inquiry_count, qr_block_count, last_parse_job_id, last_seen_at, created_at, updated_at)
                VALUES (:company_uid, :branch_uid, :tenant_id, :rule_code, :severity, 1, :inquiry_count, :qr_block_count, :last_parse_job_id, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    trigger_count = trigger_count + 1,
                    inquiry_count = inquiry_count + VALUES(inquiry_count),
                    qr_block_count = qr_block_count + VALUES(qr_block_count),
                    last_parse_job_id = VALUES(last_parse_job_id),
                    last_seen_at = NOW(),
                    updated_at = NOW()');
            foreach ($checks as $check) {
                $stmt->execute([
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':tenant_id' => $tenantId,
                    ':rule_code' => mb_substr((string)($check['rule_code'] ?? ''), 0, 80),
                    ':severity' => in_array((string)($check['severity'] ?? ''), ['info','warning','danger','block'], true) ? (string)$check['severity'] : 'info',
                    ':inquiry_count' => !empty($check['requires_inquiry']) ? 1 : 0,
                    ':qr_block_count' => !empty($check['blocks_qr']) ? 1 : 0,
                    ':last_parse_job_id' => $parseJobId,
                ]);
            }
        } catch (Throwable) {
            // ルール学習スコア保存失敗で本処理を止めない。
        }
    }
}
