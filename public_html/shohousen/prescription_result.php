<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$jobId = (int)($_GET['job_id'] ?? 0);
$job = $jobId ? PrescriptionOcrService::getJob((int)$user['tenant_id'], $jobId) : null;
if (!$job) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::verify();
        $data = OpenAiPrescriptionClient::demoNormalized();
        $jobId = 0;
    } else {
        redirect('/prescription_scan.php');
    }
} else {
    $data = $job['normalized'];
}

$serverValidationErrors = [];
$restorePost = null;
if (!empty($_SESSION['prescription_validation_errors']) && is_array($_SESSION['prescription_validation_errors'])) {
    $serverValidationErrors = array_values(array_filter(array_map('strval', $_SESSION['prescription_validation_errors'])));
    unset($_SESSION['prescription_validation_errors']);
}
if (!empty($_SESSION['prescription_validation_old_post']) && is_array($_SESSION['prescription_validation_old_post'])) {
    $candidatePost = $_SESSION['prescription_validation_old_post'];
    unset($_SESSION['prescription_validation_old_post']);
    $candidateJobId = (int)($candidatePost['parse_job_id'] ?? 0);
    if ($candidateJobId === $jobId || $jobId === 0) {
        $restorePost = $candidatePost;
    }
}

$minimalAnalysisMode = (bool)app_config('prescription_minimal_analysis.enabled', true);
$validationSummary = is_array($data['validation_summary'] ?? null) ? $data['validation_summary'] : [];
$fieldValidations = is_array($data['field_validations'] ?? null) ? $data['field_validations'] : [];
$ocrAttempts = is_array($data['_ocr_attempts'] ?? null) ? array_values($data['_ocr_attempts']) : [];
$canRetryOcr = $jobId > 0 && !empty($data['_can_retry_ocr']);
$retryDisabledReason = (string)($data['_ocr_retry_disabled_reason'] ?? '');
$qrReady = !empty($data['qr_ready']) && (int)($validationSummary['blocks_qr'] ?? 0) === 0;
$candidates = $minimalAnalysisMode ? [] : ($data['_correction_candidates'] ?? []);
$patient = $data['patient'] ?? [];
$insurance = $data['insurance'] ?? [];
$prescription = $data['prescription'] ?? [];
$medical = $data['medical_institution'] ?? [];
$publicExpense = is_array($data['public_expense'] ?? null) ? $data['public_expense'] : [];
$substitution = is_array($data['substitution'] ?? null) ? $data['substitution'] : [];
$medications = is_array($data['medications'] ?? null) ? $data['medications'] : [];
$dynamicFields = is_array($data['form_fields'] ?? null) ? $data['form_fields'] : [];
if ($restorePost) {
    $patient['name'] = trim((string)($restorePost['patient_name'] ?? ($patient['name'] ?? '')));
    $patient['gender'] = trim((string)($restorePost['gender'] ?? ($patient['gender'] ?? '')));
    $patient['birth_date'] = trim((string)($restorePost['birth_date'] ?? ($patient['birth_date'] ?? '')));
    $insurance['insurance_no'] = trim((string)($restorePost['insurance_no'] ?? ($insurance['insurance_no'] ?? '')));
    $insurance['insured_symbol_number'] = trim((string)($restorePost['insured_symbol_number'] ?? ($insurance['insured_symbol_number'] ?? '')));
    $insurance['copay_rate'] = trim((string)($restorePost['copay_rate'] ?? ($insurance['copay_rate'] ?? '')));
    $publicExpense['payer_no'] = trim((string)($restorePost['public_payer_no'] ?? ($publicExpense['payer_no'] ?? '')));
    $publicExpense['beneficiary_no'] = trim((string)($restorePost['public_beneficiary_no'] ?? ($publicExpense['beneficiary_no'] ?? '')));
    $prescription['issued_on'] = trim((string)($restorePost['issued_on'] ?? ($prescription['issued_on'] ?? '')));
    $medical['code'] = trim((string)($restorePost['medical_institution_code'] ?? ($medical['code'] ?? '')));
    $medical['name'] = trim((string)($restorePost['medical_institution_name'] ?? ($medical['name'] ?? '')));
    $medical['address'] = trim((string)($restorePost['medical_institution_address'] ?? ($medical['address'] ?? '')));
    $medical['phone'] = trim((string)($restorePost['medical_institution_phone'] ?? ($medical['phone'] ?? '')));
    $medical['doctor_name'] = trim((string)($restorePost['doctor_name'] ?? ($medical['doctor_name'] ?? '')));

    $restoredMeds = [];
    $drugNames = is_array($restorePost['drug_name'] ?? null) ? $restorePost['drug_name'] : [];
    foreach ($drugNames as $i => $drugName) {
        $restoredMeds[] = [
            'drug_name' => trim((string)$drugName),
            'generic_name' => trim((string)(($restorePost['generic_name'] ?? [])[$i] ?? '')),
            'brand_name' => trim((string)(($restorePost['brand_name'] ?? [])[$i] ?? '')),
            'raw_drug_text' => trim((string)(($restorePost['raw_drug_text'] ?? [])[$i] ?? '')),
            'dose_text' => trim((string)(($restorePost['dose_text'] ?? [])[$i] ?? '')),
            'usage_text' => trim((string)(($restorePost['usage_text'] ?? [])[$i] ?? '')),
            'days_count' => trim((string)(($restorePost['days_count'] ?? [])[$i] ?? '')),
            'amount_text' => trim((string)(($restorePost['amount_text'] ?? [])[$i] ?? '')),
            'stock_status' => trim((string)(($restorePost['stock_status'] ?? [])[$i] ?? 'unknown')),
            'name_relation' => trim((string)(($restorePost['drug_name_relation_type'] ?? [])[$i] ?? 'unknown')),
        ];
    }
    if ($restoredMeds) {
        $medications = $restoredMeds;
    }

    if (!empty($restorePost['original_dynamic_key']) && is_array($restorePost['original_dynamic_key'])) {
        $dynamicFields = [];
        foreach ($restorePost['original_dynamic_key'] as $i => $key) {
            $dynamicFields[] = [
                'field_key' => (string)$key,
                'field_label' => (string)(($restorePost['original_dynamic_label'] ?? [])[$i] ?? $key),
                'field_group' => (string)(($restorePost['original_dynamic_group'] ?? [])[$i] ?? 'other'),
                'value' => (string)(($restorePost['original_dynamic_value'] ?? [])[$i] ?? ''),
                'ai_value' => (string)(($restorePost['original_dynamic_ai_value'] ?? [])[$i] ?? ''),
                'source_section' => (string)(($restorePost['original_dynamic_source_section'] ?? [])[$i] ?? ''),
                'confidence' => (string)(($restorePost['original_dynamic_confidence'] ?? [])[$i] ?? ''),
                'needs_human_check' => (string)(($restorePost['original_dynamic_needs_human_check'] ?? [])[$i] ?? '') === '1',
                'include_default' => true,
                'ui_template' => (string)(($restorePost['original_dynamic_ui_template'] ?? [])[$i] ?? 'input'),
                'display_order' => (int)(($restorePost['original_dynamic_display_order'] ?? [])[$i] ?? (1000 + $i)),
                'is_empty_cell' => (string)(($restorePost['original_dynamic_is_empty_cell'] ?? [])[$i] ?? '') === '1',
                'source' => 'restored_post',
            ];
        }
    }
}
$fieldPreferences = [];
if (!$minimalAnalysisMode) {
    try {
        $knowledgeService = new PrescriptionKnowledgeService();
        $fieldPreferences = method_exists($knowledgeService, 'branchFieldPreferenceMap')
            ? $knowledgeService->branchFieldPreferenceMap()
            : [];
    } catch (Throwable) {
        $fieldPreferences = [];
    }
}
$ruleEngine = new PrescriptionRuleEngineService();
$ruleChecks = $ruleEngine->evaluateNormalized($data);
$ruleSummary = PrescriptionRuleEngineService::summarize($ruleChecks);

$fieldGroupLabels = [
    'patient' => '患者情報',
    'insurance' => '保険情報',
    'public_expense' => '公費情報',
    'prescription' => '処方箋情報',
    'medical_institution' => '医療機関情報',
    'medication' => '処方薬情報',
    'pharmacy' => '薬局記入欄',
    'note' => '備考・注意',
    'qr' => 'QR・コード',
    'other' => 'その他項目',
];
$dynamicFieldGroupLabels = $fieldGroupLabels;
unset($dynamicFieldGroupLabels['medication']);

function ocr_string(mixed $value): string
{
    return trim((string)$value);
}

function ocr_confidence_percent(mixed $value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $confidence = (float)$value;
    if ($confidence >= 0.0 && $confidence <= 1.0) {
        $confidence *= 100.0;
    }
    return round(max(0.0, min(100.0, $confidence)), 2);
}

function normalize_review_key(string $key): string
{
    return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($key)) ?: 'field';
}

function standard_review_key_alias(string $key): string
{
    $normalized = str_replace('-', '_', normalize_review_key($key));
    return match ($normalized) {
        'patient_name', 'name', 'patient.name' => 'patient.name',
        'patient_kana', 'kana', 'patient.kana' => 'patient.kana',
        'patient_birth_date', 'birth_date', 'patient.birth_date' => 'patient.birth_date',
        'patient_gender', 'gender', 'patient.gender' => 'patient.gender',
        'insurance_no', 'insurer_number', 'insurance.insurance_no' => 'insurance.insurance_no',
        'insured_symbol_number', 'insurance_symbol_number', 'insurance.insured_symbol_number' => 'insurance.insured_symbol_number',
        'copay_rate', 'copay_ratio', 'insurance.copay_rate' => 'insurance.copay_rate',
        'issued_on', 'prescription_issued_on', 'prescription.issued_on' => 'prescription.issued_on',
        'expires_on', 'valid_until', 'prescription.expires_on', 'prescription.valid_until' => 'prescription.expires_on',
        'medical_institution_code', 'institution_code', 'medical_institution.code' => 'medical_institution.code',
        'medical_institution_name', 'medical_name', 'institution_name', 'medical_institution.name' => 'medical_institution.name',
        'doctor_name', 'medical_institution.doctor_name' => 'medical_institution.doctor_name',
        'medical_institution_phone', 'phone', 'medical_institution.phone' => 'medical_institution.phone',
        default => $normalized,
    };
}

function canonical_review_key(array $field): string
{
    $key = standard_review_key_alias((string)($field['field_key'] ?? ''));
    $label = (string)($field['field_label'] ?? '');
    $group = (string)($field['field_group'] ?? 'other');
    $target = $label . ' ' . $key;

    if ($group === 'patient') {
        if (str_contains($target, 'フリガナ')) return 'patient.kana';
        if (str_contains($target, '氏名') || str_contains($target, '患者名')) return 'patient.name';
        if (str_contains($target, '性別') || str_contains($target, '男') || str_contains($target, '女')) return 'patient.gender';
        if (str_contains($target, '生年月日')) return 'patient.birth_date';
    }
    if ($group === 'insurance') {
        if (str_contains($target, '保険者番号')) return 'insurance.insurance_no';
        if (str_contains($target, '記号') || str_contains($target, '番号') || str_contains($target, '被保険者')) return 'insurance.insured_symbol_number';
        if (str_contains($target, '負担割合')) return 'insurance.copay_rate';
    }
    if ($group === 'prescription') {
        if (str_contains($target, '交付年月日') || str_contains($target, '発行日')) return 'prescription.issued_on';
        if (str_contains($target, '使用期間')) return 'prescription.valid_until';
    }
    if ($group === 'medical_institution') {
        if (str_contains($target, '医療機関コード')) return 'medical_institution.code';
        if (str_contains($target, '医療機関名') || (str_contains($target, '医療機関') && (str_contains($target, '名称') || str_contains($target, '所在')))) return 'medical_institution.name';
        if (str_contains($target, '医師')) return 'medical_institution.doctor_name';
        if (str_contains($target, '電話')) return 'medical_institution.phone';
    }
    return $key !== 'field' ? $key : normalize_review_key($label);
}

function is_learning_only_review_field(array $field): bool
{
    $text = mb_strtolower(implode(' ', [
        (string)($field['field_key'] ?? ''),
        (string)($field['field_label'] ?? ''),
        (string)($field['source_section'] ?? ''),
        (string)($field['reason'] ?? ''),
    ]));
    foreach (['raw_drug_text', '薬品名元テキスト', '元テキスト', 'generic_name', '一般名候補', 'brand_name', '商品名候補', 'relation_type', '薬品名の関係', 'drug_name_relation', 'name_relation', '辞書候補'] as $needle) {
        if (str_contains($text, mb_strtolower($needle))) {
            return true;
        }
    }
    return false;
}

function review_confidence_badge(mixed $confidence, array $field): string
{
    $raw = ocr_confidence_percent($confidence);
    if ($raw === null) {
        return '実績信頼度 未評価';
    }
    $source = (string)($field['source'] ?? '');
    $sourceSection = (string)($field['source_section'] ?? '');
    $needs = !empty($field['needs_human_check']);
    $isFirstPass = $needs || in_array($source, ['normalized', 'structured_field', 'ai'], true) || str_contains($sourceSection, '標準項目');
    // ここで出すのはAIの自己申告値ではなく、人間確認前の確認優先度。
    // 実績学習が十分に溜まるまでは高い数字を出さず、過信を防ぐ。
    $display = $isFirstPass ? min($raw, 25.0) : min($raw, 60.0);
    return '確認スコア ' . rtrim(rtrim((string)round($display, 1), '0'), '.') . '%';
}

/** @param array<string,array<string,mixed>> $rows */
function upsert_review_field(array &$rows, array $field): void
{
    $key = canonical_review_key($field);
    $group = (string)($field['field_group'] ?? 'other');
    if (!in_array($group, ['patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other'], true)) {
        $group = 'other';
    }
    $row = [
        'field_key' => $key,
        'field_label' => ocr_string($field['field_label'] ?? $key) ?: $key,
        'field_group' => $group,
        'value' => ocr_string($field['value'] ?? ''),
        'ai_value' => ocr_string($field['ai_value'] ?? ($field['value'] ?? '')),
        'source_section' => ocr_string($field['source_section'] ?? ''),
        'confidence' => ocr_confidence_percent($field['confidence'] ?? null),
        'needs_human_check' => !empty($field['needs_human_check']),
        'include_default' => !empty($field['include_default']),
        'ui_template' => ocr_string($field['ui_template'] ?? 'input') ?: 'input',
        'value_type' => ocr_string($field['value_type'] ?? 'text') ?: 'text',
        'display_order' => (int)($field['display_order'] ?? 9999),
        'is_empty_cell' => !empty($field['is_empty_cell']),
        'source' => ocr_string($field['source'] ?? 'ai'),
    ];

    if (!isset($rows[$key])) {
        $rows[$key] = $row;
        return;
    }

    // 同じ項目が固定抽出と動的抽出の両方にある場合は、値が入っている方・信頼度がある方を優先しつつ重複表示しない。
    if ($rows[$key]['value'] === '' && $row['value'] !== '') {
        $rows[$key]['value'] = $row['value'];
    }
    if ($rows[$key]['ai_value'] === '' && $row['ai_value'] !== '') {
        $rows[$key]['ai_value'] = $row['ai_value'];
    }
    if ($row['confidence'] !== null) {
        // 標準項目の全体信頼度より、AIが返した個別項目の信頼度を優先する。
        if ($rows[$key]['confidence'] === null || ($rows[$key]['source'] ?? '') === 'normalized' || (($row['source'] ?? '') === 'ai' && (float)$row['confidence'] !== (float)$rows[$key]['confidence'])) {
            $rows[$key]['confidence'] = $row['confidence'];
        }
    }
    $rows[$key]['needs_human_check'] = $rows[$key]['needs_human_check'] || $row['needs_human_check'];
    $rows[$key]['include_default'] = $rows[$key]['include_default'] || $row['include_default'];
    if ($row['source_section'] !== '' && $rows[$key]['source_section'] === '') {
        $rows[$key]['source_section'] = $row['source_section'];
    }
    $rows[$key]['display_order'] = min((int)$rows[$key]['display_order'], (int)$row['display_order']);
}

function medication_visible_support_value(array $med, string $key): string
{
    $value = trim((string)($med[$key] ?? ''));
    if ($value === '') {
        return '';
    }
    $raw = trim((string)($med['raw_drug_text'] ?? ''));
    $relation = (string)($med['name_relation'] ?? 'unknown');
    $joined = mb_strtolower($raw . "\n" . (string)($med['drug_name'] ?? ''));
    $needle = mb_strtolower($value);

    // 候補辞書から推定しただけの一般名/商品名を確定値として保存しない。
    // 処方箋画像上にその文字列・【般】・一般名表記が見えている場合だけ保持する。
    if ($raw !== '' && ($needle !== '' && str_contains($joined, $needle))) {
        return $value;
    }
    if ($key === 'generic_name' && preg_match('/【般】|\[般\]|一般名|般名/u', $raw)) {
        return $value;
    }
    if ($relation === 'generic_brand_pair' && $raw !== '') {
        return $value;
    }
    return '';
}

function render_dynamic_value_control(string $name, string $value, string $uiTemplate, string $valueType, string $fieldKey): string
{
    $uiTemplate = in_array($uiTemplate, ['input','textarea','date','number','select','checkbox','drug_line','blank_cell','unknown'], true) ? $uiTemplate : 'input';
    $valueType = in_array($valueType, ['text','date','number','code','person_name','drug','usage','amount','boolean','unknown'], true) ? $valueType : 'text';
    $escapedName = h($name);
    $escapedValue = h($value);
    $dataAttr = ' data-review-value data-field-key="' . h($fieldKey) . '"';
    if ($uiTemplate === 'textarea' || $uiTemplate === 'drug_line' || str_contains($value, "\n")) {
        return '<textarea name="' . $escapedName . '" rows="2" placeholder="空欄"' . $dataAttr . '>' . $escapedValue . '</textarea>';
    }
    if ($uiTemplate === 'checkbox' || $valueType === 'boolean') {
        $yesSelected = in_array($value, ['1', 'true', '有', 'あり', 'はい', '○', '✓'], true) ? ' selected' : '';
        $noSelected = in_array($value, ['0', 'false', '無', 'なし', 'いいえ', '×'], true) ? ' selected' : '';
        return '<select name="' . $escapedName . '"' . $dataAttr . '><option value="">空欄</option><option value="有"' . $yesSelected . '>有</option><option value="無"' . $noSelected . '>無</option></select>';
    }
    if ($uiTemplate === 'date' || $valueType === 'date') {
        $type = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? 'date' : 'text';
        return '<input type="' . $type . '" name="' . $escapedName . '" value="' . $escapedValue . '" placeholder="年/月/日"' . $dataAttr . '>';
    }
    if ($uiTemplate === 'number' || $valueType === 'number') {
        return '<input type="text" inputmode="numeric" name="' . $escapedName . '" value="' . $escapedValue . '" placeholder="空欄"' . $dataAttr . '>';
    }
    $placeholder = $uiTemplate === 'blank_cell' ? '空欄枠' : '空欄';
    return '<input name="' . $escapedName . '" value="' . $escapedValue . '" placeholder="' . h($placeholder) . '"' . $dataAttr . '>';
}

$reviewRowsByKey = [];
$fixedDefinitions = [
    ['patient.name', '患者名', 'patient', $patient['name'] ?? '', 'person_name', 'input', 10, $patient['confidence'] ?? null],
    ['patient.gender', '性別', 'patient', $patient['gender'] ?? '', 'text', 'select', 20, $patient['confidence'] ?? null],
    ['patient.birth_date', '生年月日', 'patient', $patient['birth_date'] ?? '', 'date', 'date', 30, $patient['confidence'] ?? null],
    ['insurance.insurance_no', '保険者番号', 'insurance', $insurance['insurance_no'] ?? '', 'code', 'input', 40, $insurance['confidence'] ?? null],
    ['insurance.insured_symbol_number', '記号番号', 'insurance', $insurance['insured_symbol_number'] ?? '', 'code', 'input', 50, $insurance['confidence'] ?? null],
    ['insurance.copay_rate', '負担割合', 'insurance', $insurance['copay_rate'] ?? '', 'text', 'input', 60, $insurance['confidence'] ?? null],
    ['public_expense.payer_no', '公費負担者番号', 'public_expense', $publicExpense['payer_no'] ?? '', 'code', 'input', 62, $publicExpense['confidence'] ?? null],
    ['public_expense.beneficiary_no', '公費負担医療の受給者番号', 'public_expense', $publicExpense['beneficiary_no'] ?? '', 'code', 'input', 64, $publicExpense['confidence'] ?? null],
    ['prescription.issued_on', '交付年月日', 'prescription', $prescription['issued_on'] ?? '', 'date', 'date', 70, $prescription['confidence'] ?? null],
    ['prescription.expires_on', '処方箋使用期間', 'prescription', $prescription['expires_on'] ?? '', 'date', 'date', 75, $prescription['confidence'] ?? null],
    ['medical_institution.code', '医療機関コード', 'medical_institution', $medical['code'] ?? '', 'code', 'input', 80, $medical['confidence'] ?? null],
    ['medical_institution.name', '保険医療機関名', 'medical_institution', $medical['name'] ?? '', 'text', 'input', 90, $medical['confidence'] ?? null],
    ['medical_institution.address', '保険医療機関所在地', 'medical_institution', $medical['address'] ?? '', 'text', 'input', 91, $medical['confidence'] ?? null],
    ['medical_institution.phone', '電話番号', 'medical_institution', $medical['phone'] ?? '', 'text', 'input', 92, $medical['confidence'] ?? null],
    ['medical_institution.doctor_name', '保険医氏名', 'medical_institution', $medical['doctor_name'] ?? '', 'person_name', 'input', 93, $medical['confidence'] ?? null],
    ['substitution.change_disallowed', '変更不可', 'prescription', !empty($substitution['change_disallowed']) ? '有' : '', 'boolean', 'select', 94, $substitution['confidence'] ?? null],
    ['substitution.doctor_signature_or_seal', '保険医署名・記名押印', 'prescription', !empty($substitution['doctor_signature_or_seal']) ? '有' : '', 'boolean', 'select', 95, $substitution['confidence'] ?? null],
];
foreach ($fixedDefinitions as [$key, $label, $group, $value, $valueType, $uiTemplate, $order, $confidence]) {
    $value = ocr_string($value);
    upsert_review_field($reviewRowsByKey, [
        'field_key' => $key,
        'field_label' => $label,
        'field_group' => $group,
        'value' => $value,
        'ai_value' => $value,
        'source_section' => '標準項目',
        'confidence' => $confidence,
        'needs_human_check' => $value === '',
        'include_default' => $value !== '',
        'ui_template' => $uiTemplate,
        'value_type' => $valueType,
        'display_order' => $order,
        'source' => 'normalized',
    ]);
}
foreach ($dynamicFields as $i => $field) {
    if (!is_array($field) || is_learning_only_review_field($field)) {
        continue;
    }
    // 薬品名・用法・日数・総量は下の処方薬カードで修正・保存する。動的項目側に重複表示しない。
    if ((string)($field['field_group'] ?? '') === 'medication') {
        continue;
    }
    $field['display_order'] = $field['display_order'] ?? (1000 + $i);
    upsert_review_field($reviewRowsByKey, $field);
}
$reviewRows = array_values($reviewRowsByKey);
$fieldGroupOrder = array_keys($dynamicFieldGroupLabels);
usort($reviewRows, static function (array $a, array $b) use ($fieldGroupOrder): int {
    $ga = array_search((string)($a['field_group'] ?? 'other'), $fieldGroupOrder, true);
    $gb = array_search((string)($b['field_group'] ?? 'other'), $fieldGroupOrder, true);
    $ga = $ga === false ? 999 : $ga;
    $gb = $gb === false ? 999 : $gb;
    if ($ga === $gb) {
        return (((int)($a['display_order'] ?? 9999)) <=> ((int)($b['display_order'] ?? 9999))) ?: strcmp((string)($a['field_label'] ?? ''), (string)($b['field_label'] ?? ''));
    }
    return $ga <=> $gb;
});

View::header('解析結果確認', ['styles' => ['/assets/css/prescription_result.css']]);
?>
<section class="page-title">
  <h1>解析結果確認</h1>
  <p>読み取った項目を確認し、値の修正と不足項目の追加を行います。未入力の必須項目がある場合は赤く表示し、DB保存には進みません。</p>
</section>
<?php if ($serverValidationErrors): ?>
  <div class="alert danger validation-alert" data-server-validation-alert>
    <strong>未入力または修正が必要な項目があります。</strong><br>
    <?= h(implode(' / ', $serverValidationErrors)) ?>
  </div>
<?php endif; ?>
<div class="alert danger validation-alert" data-validation-alert hidden>
  <strong>未入力または修正が必要な項目があります。</strong><br>
  <span data-validation-alert-text>赤く表示された項目を入力してください。</span>
</div>
<?php if (!empty($_SESSION['prescription_retry_error'])): ?>
  <div class="alert danger"><strong>再読み込みエラー</strong><br><?= h((string)$_SESSION['prescription_retry_error']) ?></div>
  <?php unset($_SESSION['prescription_retry_error']); ?>
<?php endif; ?>
<?php if (isset($_GET['retry_done'])): ?>
  <div class="alert info"><strong>再読み込み完了</strong><br>1回目と2回目の読取結果を項目ごとに表示しています。採用する値を選んでください。</div>
<?php endif; ?>
<?php if (!empty($data['warnings'])): ?>
  <div class="alert info"><strong>解析メモ</strong><br><?= h(implode(' / ', array_map('strval', $data['warnings']))) ?></div>
<?php endif; ?>
<?php if (false && !empty($data['_ai_rule_mapping'])): ?>
  <?php $mapInfo = is_array($data['_ai_rule_mapping']) ? $data['_ai_rule_mapping'] : []; ?>
  <div class="alert info">
    <strong>AI項目化</strong><br>
    <?= !empty($mapInfo['used_ai']) ? 'AIが処方箋ルール・拠点テンプレートに沿って表示フォーム用項目へ再配置しました。' : 'AI項目化はフォールバックでPHP正規化結果を使っています。' ?>
    <?php if (!empty($mapInfo['error'])): ?><br>理由: <?= h((string)$mapInfo['error']) ?><?php endif; ?>
  </div>
<?php endif; ?>
<?php if (false && $jobId > 0): ?>
  <section class="card ocr-source-preview">
    <div>
      <h2>撮影画像</h2>
      <p>画像は画面内に収まる大きさで表示します。必要に応じて別画面で確認できます。</p>
    </div>
    <a class="btn ghost" href="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" target="_blank" rel="noopener">画像を別画面で開く</a>
    <a class="btn ghost" href="<?= h(app_url('/prescription_io_debug.php?job_id=' . (string)$jobId)) ?>">IO診断を見る</a>
    <img src="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" alt="撮影した処方箋画像" loading="lazy">
    <div class="button-row">
      <?php if ($canRetryOcr): ?>
        <form method="post" action="<?= h(app_url('/prescription_retry_analyze.php')) ?>" onsubmit="return confirm('同じ画像をもう一度gpt-4o-miniで読み込みます。2回目以降は再読み込みできません。実行しますか？');">
          <?= Csrf::field() ?>
          <input type="hidden" name="job_id" value="<?= h((string)$jobId) ?>">
          <button class="btn ghost" type="submit">再読み込みする（2回目まで）</button>
        </form>
      <?php else: ?>
        <span class="form-help"><?= h($retryDisabledReason !== '' ? $retryDisabledReason : '再読み込みは未実行または上限到達です。') ?></span>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>
<?php if (false && $ocrAttempts): ?>
  <section class="card rule-check-panel" aria-label="AI読取比較">
    <div class="rule-check-head">
      <div>
        <h2>AI読取比較</h2>
        <p>1回目と2回目の読み取り内容を項目ごとに比較します。採用する値を選ぶと、下の修正欄と保存用の値へ反映されます。3回目以降は再読込せず手入力で修正します。</p>
      </div>
      <div class="rule-summary">
        <span class="rule-badge info">読取 <?= h((string)count($ocrAttempts)) ?>回</span>
        <?php if (!$canRetryOcr): ?><span class="rule-badge warning">再読込不可</span><?php endif; ?>
      </div>
    </div>
    <?php $labels = class_exists('PrescriptionOcrAttemptService') ? PrescriptionOcrAttemptService::coreFieldLabels() : []; ?>
    <div class="rule-check-list ocr-attempt-compare-list">
      <?php foreach ($labels as $fieldKey => $fieldLabel): ?>
        <?php
          $hasAny = false;
          foreach ($ocrAttempts as $attempt) {
              $v = trim((string)($attempt['values'][$fieldKey] ?? ''));
              if ($v !== '') { $hasAny = true; break; }
          }
        ?>
        <?php if (!$hasAny && !in_array($fieldKey, ['patient.name','patient.birth_date','insurance.insurance_no','insurance.insured_symbol_number','prescription.issued_on','medical_institution.name','medical_institution.doctor_name'], true)) continue; ?>
        <article class="rule-check-item <?= $hasAny ? 'info' : 'warning' ?>" data-attempt-field="<?= h($fieldKey) ?>">
          <strong><?= h($fieldLabel) ?></strong>
          <div class="attempt-choice-row">
            <?php foreach ($ocrAttempts as $attempt): ?>
              <?php $attemptNo = (int)($attempt['attempt_no'] ?? 0); $v = trim((string)($attempt['values'][$fieldKey] ?? '')); ?>
              <label class="attempt-choice">
                <input type="radio" name="attempt_choice_<?= h($fieldKey) ?>" value="<?= h($v) ?>" data-attempt-choice data-field-key="<?= h($fieldKey) ?>" <?= $attemptNo === 1 ? 'checked' : '' ?>>
                <span><?= h((string)$attemptNo) ?>回目</span>
                <code><?= h($v !== '' ? $v : '判定不能') ?></code>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if (!$hasAny): ?><p>AIでは読めていません。入力しないとQR作成へ進めません。</p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
<form class="card result-card" method="post" action="<?= h(app_url('/prescription_confirm.php')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="parse_job_id" value="<?= h((string)$jobId) ?>">
  <input type="hidden" name="ai_confidence" value="<?= h((string)($data['overall_confidence'] ?? '')) ?>">

  <input type="hidden" name="patient_name" data-fixed-field="patient.name" value="<?= h((string)($patient['name'] ?? '')) ?>">
  <input type="hidden" name="gender" data-fixed-field="patient.gender" value="<?= h((string)($patient['gender'] ?? '')) ?>">
  <input type="hidden" name="birth_date" data-fixed-field="patient.birth_date" value="<?= h((string)($patient['birth_date'] ?? '')) ?>">
  <input type="hidden" name="insurance_no" data-fixed-field="insurance.insurance_no" value="<?= h((string)($insurance['insurance_no'] ?? '')) ?>">
  <input type="hidden" name="insured_symbol_number" data-fixed-field="insurance.insured_symbol_number" value="<?= h((string)($insurance['insured_symbol_number'] ?? '')) ?>">
  <input type="hidden" name="copay_rate" data-fixed-field="insurance.copay_rate" value="<?= h((string)($insurance['copay_rate'] ?? '')) ?>">
  <input type="hidden" name="issued_on" data-fixed-field="prescription.issued_on" value="<?= h((string)($prescription['issued_on'] ?? '')) ?>">
  <input type="hidden" name="medical_institution_code" data-fixed-field="medical_institution.code" value="<?= h((string)($medical['code'] ?? '')) ?>">
  <input type="hidden" name="medical_institution_name" data-fixed-field="medical_institution.name" value="<?= h((string)($medical['name'] ?? '')) ?>">
  <input type="hidden" name="medical_institution_address" data-fixed-field="medical_institution.address" value="<?= h((string)($medical['address'] ?? '')) ?>">
  <input type="hidden" name="medical_institution_phone" data-fixed-field="medical_institution.phone" value="<?= h((string)($medical['phone'] ?? '')) ?>">
  <input type="hidden" name="doctor_name" data-fixed-field="medical_institution.doctor_name" value="<?= h((string)($medical['doctor_name'] ?? '')) ?>">
  <input type="hidden" name="public_payer_no" data-fixed-field="public_expense.payer_no" value="<?= h((string)($publicExpense['payer_no'] ?? '')) ?>">
  <input type="hidden" name="public_beneficiary_no" data-fixed-field="public_expense.beneficiary_no" value="<?= h((string)($publicExpense['beneficiary_no'] ?? '')) ?>">

  <input type="hidden" name="ai_patient_name" value="<?= h((string)($patient['name'] ?? '')) ?>">
  <input type="hidden" name="ai_gender" value="<?= h((string)($patient['gender'] ?? '')) ?>">
  <input type="hidden" name="ai_birth_date" value="<?= h((string)($patient['birth_date'] ?? '')) ?>">
  <input type="hidden" name="ai_insurance_no" value="<?= h((string)($insurance['insurance_no'] ?? '')) ?>">
  <input type="hidden" name="ai_insured_symbol_number" value="<?= h((string)($insurance['insured_symbol_number'] ?? '')) ?>">
  <input type="hidden" name="ai_copay_rate" value="<?= h((string)($insurance['copay_rate'] ?? '')) ?>">
  <input type="hidden" name="ai_issued_on" value="<?= h((string)($prescription['issued_on'] ?? '')) ?>">
  <input type="hidden" name="ai_medical_institution_code" value="<?= h((string)($medical['code'] ?? '')) ?>">
  <input type="hidden" name="ai_medical_institution_name" value="<?= h((string)($medical['name'] ?? '')) ?>">

  <div class="confirm-flow-note" hidden style="display:none;">
    <span class="flow-step active">1. 読み取り項目を修正</span>
    <span class="flow-step">2. DB保存・補助学習</span>
    <span class="flow-step">3. 必要時だけ再解析テスト</span>
    <span class="flow-step">4. 使用項目選択・QR作成</span>
  </div>

  <?php if (false && ($validationSummary || $fieldValidations)): ?>
    <section class="rule-check-panel" aria-label="PHP検証スコア">
      <div class="rule-check-head">
        <div>
          <h2>PHP検証スコア</h2>
          <p>AIの自己申告confidenceではなく、番号ルール・日付・薬品辞書・変更不可/図形判定から作った実測寄りのスコアです。</p>
        </div>
        <div class="rule-summary">
          <span class="rule-badge info">最終 <?= h((string)($validationSummary['final_score'] ?? '0')) ?>%</span>
          <span class="rule-badge danger">NG <?= h((string)($validationSummary['ng'] ?? '0')) ?></span>
          <span class="rule-badge warning">判定不能 <?= h((string)($validationSummary['unknown'] ?? '0')) ?></span>
          <span class="rule-badge warning">要確認 <?= h((string)($validationSummary['review'] ?? '0')) ?></span>
          <span class="rule-badge info">OK <?= h((string)($validationSummary['ok'] ?? '0')) ?></span>
          <?php if (!$qrReady): ?><span class="rule-badge danger">QR不可 <?= h((string)($validationSummary['blocks_qr'] ?? '0')) ?></span><?php endif; ?>
        </div>
      </div>
      <?php if ($fieldValidations): ?>
        <div class="rule-check-list">
          <?php foreach (array_slice($fieldValidations, 0, 12) as $validation): ?>
            <?php $status = (string)($validation['status'] ?? 'review'); $sev = ($status === 'ng' || $status === 'unknown') ? 'danger' : ($status === 'ok' ? 'info' : 'warning'); ?>
            <article class="rule-check-item <?= h($sev) ?>">
              <strong><?= h((string)($validation['field_label'] ?? '検証項目')) ?> / <?= h((string)($validation['final_score'] ?? '0')) ?>%</strong>
              <p><?= h((string)($validation['reason'] ?? '')) ?></p>
              <div class="rule-check-meta">
                <?php if (trim((string)($validation['raw_value'] ?? '')) !== ''): ?><span>読取: <?= h((string)$validation['raw_value']) ?></span><?php endif; ?>
                <?php if (trim((string)($validation['normalized_value'] ?? '')) !== ''): ?><span>正規化: <?= h((string)$validation['normalized_value']) ?></span><?php endif; ?>
                <?php if (!empty($validation['needs_human_check'])): ?><span class="attention">人間確認</span><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($ruleChecks): ?>
    <section class="rule-check-panel" aria-label="処方箋受付ルール判定">
      <div class="rule-check-head">
        <div>
          <h2>処方箋受付ルール判定</h2>
          <p>期限、必須項目、変更不可、患者希望、一般名処方、信頼度を確認します。判定は薬剤師確認の補助で、保存時に修正後データで再判定されます。</p>
        </div>
        <div class="rule-summary">
          <span class="rule-badge danger">重要 <?= h((string)($ruleSummary['block'] + $ruleSummary['danger'])) ?></span>
          <span class="rule-badge warning">確認 <?= h((string)$ruleSummary['warning']) ?></span>
          <span class="rule-badge info">参考 <?= h((string)$ruleSummary['info']) ?></span>
          <?php if ($ruleSummary['requires_inquiry'] > 0): ?><span class="rule-badge inquiry">疑義照会候補 <?= h((string)$ruleSummary['requires_inquiry']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="rule-check-list">
        <?php foreach ($ruleChecks as $check): ?>
          <?php $sev = (string)($check['severity'] ?? 'info'); ?>
          <article class="rule-check-item <?= h($sev) ?>">
            <strong><?= h((string)($check['title'] ?? '確認項目')) ?></strong>
            <p><?= h((string)($check['message'] ?? '')) ?></p>
            <div class="rule-check-meta">
              <?php if (!empty($check['detected_value'])): ?><span>検出: <?= h((string)$check['detected_value']) ?></span><?php endif; ?>
              <?php if (!empty($check['recommended_action'])): ?><span>対応: <?= h((string)$check['recommended_action']) ?></span><?php endif; ?>
              <?php if (!empty($check['requires_inquiry'])): ?><span class="attention">疑義照会候補</span><?php endif; ?>
              <?php if (!empty($check['blocks_qr'])): ?><span class="attention">QR前確認</span><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="dynamic-field-card dynamic-field-review-card">
    <div class="dynamic-field-head">
      <div>
        <h2>読み取り項目の修正</h2>
        <p>患者・保険・公費・医療機関など、処方薬以外の項目を修正します。項目名と分類は拠点ひな型の候補として保存します。処方薬は下の専用カードだけで修正・保存します。</p>
      </div>
      <div class="field-actions dynamic-add-controls">
        <label class="field-add-target">
          <span class="sr-only">追加先分類</span>
          <select data-add-dynamic-group>
            <?php foreach ($dynamicFieldGroupLabels as $g => $gLabel): ?>
              <option value="<?= h($g) ?>"><?= h($gLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn ghost small" type="button" data-add-dynamic-field data-use-selected-group="1">選択した分類に項目を追加</button>
      </div>
    </div>

    <?php if (!$reviewRows): ?>
      <div class="alert warning">読み取り項目がありません。必要な項目は「項目を追加」から入力してください。</div>
    <?php endif; ?>

    <div class="dynamic-field-grid" data-dynamic-field-list>
      <?php $currentGroup = null; ?>
      <?php foreach ($reviewRows as $i => $field): ?>
        <?php
          $group = (string)($field['field_group'] ?? 'other');
          if (!isset($fieldGroupLabels[$group])) { $group = 'other'; }
          $key = (string)($field['field_key'] ?? ('field_' . $i));
          $label = (string)($field['field_label'] ?? $key);
          $value = (string)($field['value'] ?? '');
          $aiValue = (string)($field['ai_value'] ?? $value);
          $confidence = ocr_confidence_percent($field['confidence'] ?? null);
          $includeDefault = array_key_exists($key, $fieldPreferences) ? (bool)$fieldPreferences[$key] : (bool)($field['include_default'] ?? ($value !== ''));
          if ($currentGroup !== $group):
            $currentGroup = $group;
        ?>
          <div class="dynamic-field-group-row" data-group-header="<?= h($group) ?>">
            <h3 class="dynamic-field-group"><?= h($fieldGroupLabels[$group]) ?></h3>
            <button class="btn ghost small" type="button" data-add-dynamic-field data-group="<?= h($group) ?>"><?= h($fieldGroupLabels[$group]) ?>に追加</button>
          </div>
        <?php endif; ?>

        <div class="dynamic-field-row review-field-row <?= !empty($field['needs_human_check']) ? 'needs-check' : '' ?>" data-field-group="<?= h($group) ?>">
          <div class="field-main full">
            <div class="review-field-grid">
              <label>
                <span class="field-label">項目名</span>
                <input name="original_dynamic_label[]" value="<?= h($label) ?>" placeholder="例: 公費負担者番号">
              </label>
              <label>
                <span class="field-label">分類</span>
                <select name="original_dynamic_group[]">
                  <?php foreach ($dynamicFieldGroupLabels as $g => $gLabel): ?>
                    <option value="<?= h($g) ?>" <?= $g === $group ? 'selected' : '' ?>><?= h($gLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="review-field-value">
                <span class="field-label">修正後の値</span>
                <?= render_dynamic_value_control('original_dynamic_value[]', $value, (string)($field['ui_template'] ?? 'input'), (string)($field['value_type'] ?? 'text'), $key) ?>
              </label>
            </div>
            <div class="field-meta">
              <?php if (!empty($field['source_section'])): ?><span><?= h((string)$field['source_section']) ?></span><?php endif; ?>
              <?php if (trim($value) === ''): ?><span class="attention">未入力</span><?php elseif (!empty($field['needs_human_check'])): ?><span class="attention">要確認</span><?php endif; ?>
            </div>
          </div>

          <input type="hidden" name="original_dynamic_key[]" value="<?= h($key) ?>">
          <input type="hidden" name="original_dynamic_ai_value[]" value="<?= h($aiValue) ?>">
          <input type="hidden" name="original_dynamic_source_section[]" value="<?= h((string)($field['source_section'] ?? '')) ?>">
          <input type="hidden" name="original_dynamic_confidence[]" value="<?= h((string)($confidence ?? '')) ?>">
          <input type="hidden" name="original_dynamic_needs_human_check[]" value="<?= !empty($field['needs_human_check']) ? '1' : '0' ?>">
          <input type="hidden" name="original_dynamic_include_default[]" value="<?= $includeDefault ? '1' : '0' ?>">
          <input type="hidden" name="original_dynamic_ui_template[]" value="<?= h((string)($field['ui_template'] ?? 'input')) ?>">
          <input type="hidden" name="original_dynamic_display_order[]" value="<?= h((string)($field['display_order'] ?? (string)($i + 1))) ?>">
          <input type="hidden" name="original_dynamic_is_empty_cell[]" value="<?= !empty($field['is_empty_cell']) ? '1' : '0' ?>">
        </div>
      <?php endforeach; ?>
    </div>

    <template id="dynamicFieldRowTemplate">
      <div class="dynamic-field-row review-field-row manual-added-field" data-field-group="other">
        <div class="field-main full">
          <div class="review-field-grid">
            <label><span class="field-label">項目名</span><input name="original_dynamic_label[]" value="" placeholder="例: 保険医氏名"></label>
            <label><span class="field-label">分類</span><select name="original_dynamic_group[]" data-manual-group-select><?php foreach ($dynamicFieldGroupLabels as $g => $gLabel): ?><option value="<?= h($g) ?>"><?= h($gLabel) ?></option><?php endforeach; ?></select></label>
            <label class="review-field-value"><span class="field-label">修正後の値</span><textarea name="original_dynamic_value[]" rows="2" placeholder="追加で読み取り・入力した値" data-review-value data-field-key="manual_field"></textarea></label>
          </div>
          <div class="field-meta"><span>手入力追加項目</span><span class="attention">読取なし</span></div>
        </div>
        <input type="hidden" name="original_dynamic_key[]" value="manual_field">
        <input type="hidden" name="original_dynamic_ai_value[]" value="">
        <input type="hidden" name="original_dynamic_source_section[]" value="人間追加">
        <input type="hidden" name="original_dynamic_confidence[]" value="">
        <input type="hidden" name="original_dynamic_needs_human_check[]" value="1">
        <input type="hidden" name="original_dynamic_include_default[]" value="0">
        <input type="hidden" name="original_dynamic_ui_template[]" value="input">
        <input type="hidden" name="original_dynamic_display_order[]" value="9999">
        <input type="hidden" name="original_dynamic_is_empty_cell[]" value="0">
      </div>
    </template>
  </section>

  <h2>処方薬情報（<?= count($medications) ?>件）</h2>
  <p class="form-help">処方薬はこのカードだけで修正・DB保存します。総量は用法と日数から計算できる場合に自動更新します。一般名候補・商品名候補・薬品名元テキストなどの補助学習用データは画面に出さず、内部で保持します。</p>
  <div class="edit-med-list" data-med-list>
    <?php foreach ($medications as $i => $med):
      $drugCandidates = $candidates['medications'][$i]['drug_name'] ?? [];
      $doseText = (string)($med['dose_text'] ?? '');
      $amountCalc = class_exists('MedicationDosageCalculator')
          ? MedicationDosageCalculator::calculate((string)($med['drug_name'] ?? ''), $doseText, (string)($med['usage_text'] ?? ''), $med['days_count'] ?? null)
          : ['amount_text' => '', 'note' => '', 'rule_code' => ''];
      $displayAmount = (string)($med['amount_text'] ?? '');
      if (!empty($amountCalc['amount_text']) && class_exists('MedicationDosageCalculator') && MedicationDosageCalculator::shouldReplaceAmountText($displayAmount, (string)$amountCalc['amount_text'])) {
          $displayAmount = (string)$amountCalc['amount_text'];
      }
    ?>
      <div class="edit-med-row ocr-med-row" data-med-row>
        <span class="row-no"><?= $i + 1 ?></span>
        <label class="med-field-main">薬品名（代表名）
          <textarea name="drug_name[]" rows="2" list="drugCandidates<?= $i ?>" placeholder="保存する代表薬品名"><?= h((string)($med['drug_name'] ?? '')) ?></textarea>
          <?php if ($drugCandidates): ?>
            <datalist id="drugCandidates<?= $i ?>">
              <?php foreach ($drugCandidates as $candidate): ?><option value="<?= h((string)$candidate['candidate_value']) ?>"><?php endforeach; ?>
            </datalist>
            <small class="candidate-note">候補: <?= h(implode(' / ', array_map(fn($c) => $c['candidate_value'] . '（' . $c['score'] . '）', $drugCandidates))) ?></small>
          <?php endif; ?>
        </label>
        <input type="hidden" name="generic_name[]" value="<?= h(medication_visible_support_value($med, 'generic_name')) ?>">
        <input type="hidden" name="brand_name[]" value="<?= h(medication_visible_support_value($med, 'brand_name')) ?>">
        <input type="hidden" name="raw_drug_text[]" value="<?= h((string)($med['raw_drug_text'] ?? ($med['drug_name'] ?? ''))) ?>">
        <label>用量（1回量）<input name="dose_text[]" value="<?= h($doseText) ?>" data-med-dose placeholder="例: 1錠 / 1回5mL"></label>
        <label>用法<input name="usage_text[]" value="<?= h((string)($med['usage_text'] ?? '')) ?>" data-med-usage></label>
        <label>日数<input type="number" name="days_count[]" value="<?= h((string)($med['days_count'] ?? '')) ?>" data-med-days></label>
        <label>総量/備考
          <input name="amount_text[]" value="<?= h($displayAmount) ?>" data-med-amount data-auto-amount="<?= !empty($amountCalc['amount_text']) ? '1' : '0' ?>">
          <small class="calculated-amount-help" data-amount-rule-note><?= h((string)($amountCalc['note'] ?? '')) ?></small>
        </label>
        <label>在庫
          <select name="stock_status[]">
            <?php foreach (['adopted'=>'採用薬','in_stock'=>'在庫あり','low_stock'=>'在庫僅少','not_stocked'=>'未採用','unknown'=>'未確認'] as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $key === 'unknown' ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php $rel = (string)($med['name_relation'] ?? 'unknown'); ?>
        <input type="hidden" name="drug_name_relation_type[]" value="<?= h(in_array($rel, ['single','generic_brand_pair','multiple_candidates','unknown'], true) ? $rel : 'unknown') ?>">
        <input type="hidden" name="ai_drug_name[]" value="<?= h((string)($med['drug_name'] ?? '')) ?>">
        <input type="hidden" name="ai_generic_name[]" value="<?= h((string)($med['generic_name'] ?? '')) ?>">
        <input type="hidden" name="ai_brand_name[]" value="<?= h((string)($med['brand_name'] ?? '')) ?>">
        <input type="hidden" name="ai_raw_drug_text[]" value="<?= h((string)($med['raw_drug_text'] ?? ($med['drug_name'] ?? ''))) ?>">
        <input type="hidden" name="ai_usage_text[]" value="<?= h((string)($med['usage_text'] ?? '')) ?>">
        <input type="hidden" name="ai_days_count[]" value="<?= h((string)($med['days_count'] ?? '')) ?>">
        <input type="hidden" name="ai_amount_text[]" value="<?= h((string)($med['amount_text'] ?? '')) ?>">
        <button class="btn danger ghost med-delete-button" type="button" data-delete-med>この薬を削除</button>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="button-row med-list-actions"><button class="btn ghost" type="button" data-add-med>薬品行を追加</button></div>
  <template id="medicationRowTemplate">
    <div class="edit-med-row ocr-med-row">
      <span class="row-no">__NO__</span>
      <label class="med-field-main">薬品名（代表名）<textarea name="drug_name[]" rows="2" placeholder="保存する代表薬品名"></textarea></label>
      <input type="hidden" name="generic_name[]" value="">
      <input type="hidden" name="brand_name[]" value="">
      <input type="hidden" name="raw_drug_text[]" value="">
      <label>用量（1回量）<input name="dose_text[]" value="" data-med-dose placeholder="例: 1錠 / 1回5mL"></label>
      <label>用法<input name="usage_text[]" value="" data-med-usage></label>
      <label>日数<input type="number" name="days_count[]" value="" data-med-days></label>
      <label>総量/備考<input name="amount_text[]" value="" data-med-amount data-auto-amount="0"><small class="calculated-amount-help" data-amount-rule-note></small></label>
      <label>在庫<select name="stock_status[]"><option value="unknown" selected>未確認</option><option value="adopted">採用薬</option><option value="in_stock">在庫あり</option><option value="low_stock">在庫僅少</option><option value="not_stocked">未採用</option></select></label>
      <input type="hidden" name="drug_name_relation_type[]" value="unknown">
      <input type="hidden" name="ai_drug_name[]" value="">
      <input type="hidden" name="ai_generic_name[]" value="">
      <input type="hidden" name="ai_brand_name[]" value="">
      <input type="hidden" name="ai_raw_drug_text[]" value="">
      <input type="hidden" name="ai_usage_text[]" value="">
      <input type="hidden" name="ai_days_count[]" value="">
      <input type="hidden" name="ai_amount_text[]" value="">
      <button class="btn danger ghost med-delete-button" type="button" data-delete-med>この薬を削除</button>
    </div>
  </template>

  <section class="prescription-confirm-preview" aria-label="修正内容の確認プレビュー" hidden style="display:none;">
    <div class="preview-head">
      <h2>修正内容の確認プレビュー</h2>
      <p>上で修正した内容を、保存前に処方箋風にまとめて確認します。入力を変更すると自動で反映されます。</p>
    </div>
    <div class="preview-sheet">
      <div class="preview-title">処 方 箋</div>
      <div class="preview-grid">
        <div><span>患者氏名</span><strong data-preview="patient.name"><?= h((string)($patient['name'] ?? '')) ?></strong></div>
        <div><span>性別</span><strong data-preview="patient.gender"><?= h((string)($patient['gender'] ?? '')) ?></strong></div>
        <div><span>生年月日</span><strong data-preview="patient.birth_date"><?= h((string)($patient['birth_date'] ?? '')) ?></strong></div>
        <div><span>保険者番号</span><strong data-preview="insurance.insurance_no"><?= h((string)($insurance['insurance_no'] ?? '')) ?></strong></div>
        <div><span>記号番号</span><strong data-preview="insurance.insured_symbol_number"><?= h((string)($insurance['insured_symbol_number'] ?? '')) ?></strong></div>
        <div><span>交付年月日</span><strong data-preview="prescription.issued_on"><?= h((string)($prescription['issued_on'] ?? '')) ?></strong></div>
        <div><span>医療機関コード</span><strong data-preview="medical_institution.code"><?= h((string)($medical['code'] ?? '')) ?></strong></div>
        <div><span>医療機関名</span><strong data-preview="medical_institution.name"><?= h((string)($medical['name'] ?? '')) ?></strong></div>
      </div>
      <div class="preview-medications">
        <h3>処方薬</h3>
        <div data-preview-meds>
          <?php foreach ($medications as $i => $med): ?>
            <div class="preview-med-row">
              <strong><?= h((string)($med['drug_name'] ?? '')) ?></strong>
              <span><?= h((string)($med['usage_text'] ?? '')) ?></span>
              <span><?= h((string)($med['days_count'] ?? '')) ?>日</span>
              <span><?= h((string)($med['amount_text'] ?? '')) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <div class="button-row end sticky-save-actions">
    <a class="btn ghost" href="<?= h(app_url('/prescription_scan.php')) ?>">再撮影</a>
    <?php if (!$qrReady): ?><span class="form-help attention">判定不能/NGの必須項目があります。保存時にも再判定し、未入力のままQR作成には進めません。</span><?php endif; ?>
    <button class="btn primary" type="submit" name="after_save_action" value="normal">修正内容をDB保存して次へ</button>
  </div>
</form>
<script>
(function () {
  var FIELD_GROUP_LABELS = <?= json_encode($dynamicFieldGroupLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  document.addEventListener('change', function (event) {
    var choice = event.target.closest('[data-attempt-choice]');
    if (!choice) return;
    var key = choice.getAttribute('data-field-key') || '';
    var value = choice.value || '';
    var fixed = document.querySelector('[data-fixed-field=\"' + CSS.escape(key) + '\"]');
    if (fixed) fixed.value = value;
    document.querySelectorAll('[data-review-value][data-field-key=\"' + CSS.escape(key) + '\"]').forEach(function (input) { input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); });
    var preview = document.querySelector('[data-preview=\"' + CSS.escape(key) + '\"]');
    if (preview) preview.textContent = value;
  });

  function renumberMedicationRows() {
    document.querySelectorAll('[data-med-list] .ocr-med-row').forEach(function (row, index) {
      var no = row.querySelector('.row-no');
      if (no) no.textContent = String(index + 1);
    });
  }

  function normalizeDosageText(value) {
    return String(value || '')
      .replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); })
      .replace(/[．]/g, '.')
      .replace(/[ｍＭ][ｌＬ]|㎖/g, 'mL')
      .replace(/㏄/g, 'cc')
      .trim();
  }

  function formatAmountNumber(value) {
    var rounded = Math.round(value * 1000) / 1000;
    if (Math.abs(rounded - Math.round(rounded)) < 0.00001) return String(Math.round(rounded));
    return String(rounded).replace(/0+$/, '').replace(/\.$/, '');
  }

  function normalizeUnit(unit) {
    var u = String(unit || '').trim();
    var lower = u.toLowerCase();
    if (['tablet', 'tablets', 'tab'].indexOf(lower) >= 0) return '錠';
    if (['cap', 'capsule'].indexOf(lower) >= 0) return 'カプセル';
    if (['ml', 'cc'].indexOf(lower) >= 0) return 'mL';
    return u;
  }

  function inferUnitFromDrugName(drugName) {
    var name = normalizeDosageText(drugName);
    if (/錠|OD錠|口腔内崩壊錠/.test(name)) return '錠';
    if (/カプセル|cap/i.test(name)) return 'カプセル';
    if (/包|顆粒|散|細粒|ドライシロップ/.test(name)) return '包';
    if (/シロップ|液|内用液|懸濁|mL|ml/.test(name)) return 'mL';
    if (/貼付|テープ|パップ|湿布/.test(name)) return '枚';
    return '';
  }

  function extractDays(daysValue, usageText) {
    var days = parseInt(normalizeDosageText(daysValue), 10);
    if (Number.isFinite(days) && days > 0) return days;
    var m = normalizeDosageText(usageText).match(/(\d+)\s*日\s*分/);
    return m ? parseInt(m[1], 10) : null;
  }

  function isAsNeededUsage(text) {
    return /頓服|屯服|疼痛時|発作時|必要時|不眠時|便秘時|嘔気時|適宜|随時/.test(normalizeDosageText(text));
  }

  function isLikelyDrugStrength(number, unit, source, drugName) {
    var normalizedUnit = normalizeUnit(unit);
    if (['mg', 'g'].indexOf(normalizedUnit) < 0) return false;
    var cleanSource = normalizeDosageText(source).replace(/\s+/g, '');
    var cleanDrug = normalizeDosageText(drugName).replace(/\s+/g, '');
    var token = String(number) + normalizedUnit;
    if (/1回|一回|毎回|使用量|塗布量/.test(cleanSource)) return false;
    return cleanSource === token && cleanDrug.indexOf(token) >= 0;
  }

  function extractPerDose(doseText, usageText, drugName) {
    var sources = [doseText, usageText];
    for (var i = 0; i < sources.length; i++) {
      var source = normalizeDosageText(sources[i]);
      if (!source) continue;
      var m = source.match(/(\d+(?:\.\d+)?)\s*(錠|tablet|tablets|tab|カプセル|cap|capsule|包|袋|mL|ml|cc|g|mg|滴|枚|本|個)/i);
      if (m) {
        if (isLikelyDrugStrength(m[1], m[2], source, drugName)) continue;
        return { value: parseFloat(m[1]), unit: normalizeUnit(m[2]) };
      }
      m = source.match(/(?:^|[^0-9.])(\d+(?:\.\d+)?)\s*[x×]\s*(?:朝|昼|夕|毎食|食後|食前|就寝|寝る|起床)/);
      if (m) {
        var unit = inferUnitFromDrugName(drugName);
        if (unit) return { value: parseFloat(m[1]), unit: unit };
      }
    }
    return null;
  }

  function extractFrequencyPerDay(usageText) {
    var text = normalizeDosageText(usageText);
    if (!text) return null;
    if (/1\s*日\s*\d+(?:\.\d+)?\s*[〜～~\-]\s*\d+(?:\.\d+)?\s*回/.test(text)) return null;
    var m = text.match(/1\s*日\s*(\d+(?:\.\d+)?)\s*回/);
    if (m) return parseFloat(m[1]);
    m = text.match(/分\s*(\d+(?:\.\d+)?)/);
    if (m) return parseFloat(m[1]);
    if (/毎食/.test(text)) return 3;
    var count = 0;
    [/朝|朝食後|朝食前|起床時/, /昼|昼食後|昼食前/, /夕|夕食後|夕食前/, /就寝|寝る前/].forEach(function (pattern) {
      if (pattern.test(text)) count += 1;
    });
    return count > 0 ? count : null;
  }

  function calculateMedicationAmount(drugName, doseText, usageText, daysValue) {
    var days = extractDays(daysValue, usageText);
    if (!days) return { amount: '', note: '日数が未入力のため総量を計算できません。' };
    if (isAsNeededUsage(usageText)) return { amount: '', note: '頓服・必要時の用法は総量を自動確定できません。' };
    var dose = extractPerDose(doseText, usageText, drugName);
    if (!dose) return { amount: '', note: '1回量が読めないため総量を計算できません。' };
    var freq = extractFrequencyPerDay(usageText);
    if (!freq) return { amount: '', note: '服薬回数が読めないため総量を計算できません。' };
    var total = dose.value * freq * days;
    var amount = formatAmountNumber(total) + dose.unit;
    return { amount: amount, note: formatAmountNumber(dose.value) + dose.unit + ' × ' + formatAmountNumber(freq) + '回/日 × ' + days + '日' };
  }

  function recalculateMedicationRowAmount(row, force) {
    if (!row) return;
    var amountInput = row.querySelector('[name="amount_text[]"]');
    if (!amountInput) return;
    if (!force && amountInput.dataset.userEdited === '1') return;
    var drug = row.querySelector('[name="drug_name[]"]')?.value || '';
    var dose = row.querySelector('[name="dose_text[]"]')?.value || '';
    var usage = row.querySelector('[name="usage_text[]"]')?.value || '';
    var days = row.querySelector('[name="days_count[]"]')?.value || '';
    var result = calculateMedicationAmount(drug, dose, usage, days);
    var note = row.querySelector('[data-amount-rule-note]');
    if (note) note.textContent = result.note || '';
    if (result.amount) {
      amountInput.value = result.amount;
      amountInput.dataset.autoAmount = '1';
    }
  }

  function recalculateAllMedicationAmounts() {
    document.querySelectorAll('[data-med-list] .ocr-med-row').forEach(function (row) {
      recalculateMedicationRowAmount(row, false);
    });
  }

  function uniqueManualFieldKey(group) {
    return 'manual.' + String(group || 'other').replace(/[^a-zA-Z0-9_.-]+/g, '_') + '.' + Date.now() + '.' + Math.floor(Math.random() * 10000);
  }

  function ensureGroupHeader(group) {
    var fieldList = document.querySelector('[data-dynamic-field-list]');
    if (!fieldList) return null;
    var existing = fieldList.querySelector('[data-group-header="' + group + '"]');
    if (existing) return existing;

    var row = document.createElement('div');
    row.className = 'dynamic-field-group-row';
    row.setAttribute('data-group-header', group);

    var title = document.createElement('h3');
    title.className = 'dynamic-field-group';
    title.textContent = FIELD_GROUP_LABELS[group] || FIELD_GROUP_LABELS.other || 'その他項目';

    var button = document.createElement('button');
    button.className = 'btn ghost small';
    button.type = 'button';
    button.setAttribute('data-add-dynamic-field', '');
    button.setAttribute('data-group', group);
    button.textContent = title.textContent + 'に追加';

    row.appendChild(title);
    row.appendChild(button);
    fieldList.appendChild(row);
    return row;
  }

  function insertFieldRowIntoGroup(row, group) {
    var fieldList = document.querySelector('[data-dynamic-field-list]');
    if (!fieldList || !row) return;
    var header = ensureGroupHeader(group);
    row.setAttribute('data-field-group', group);

    var select = row.querySelector('[name="original_dynamic_group[]"]');
    if (select) select.value = group;

    var keyInput = row.querySelector('[name="original_dynamic_key[]"]');
    if (keyInput && (!keyInput.value || keyInput.value === 'manual_field' || keyInput.value.indexOf('manual.') === 0)) {
      keyInput.value = uniqueManualFieldKey(group);
    }
    row.querySelectorAll('[data-review-value]').forEach(function (control) {
      if (keyInput) control.setAttribute('data-field-key', keyInput.value);
    });

    var source = row.querySelector('[name="original_dynamic_source_section[]"]');
    if (source) source.value = '人間追加:' + (FIELD_GROUP_LABELS[group] || group);

    var lastInGroup = null;
    fieldList.querySelectorAll('[data-field-group="' + group + '"]').forEach(function (candidate) {
      if (candidate !== row) lastInGroup = candidate;
    });

    if (lastInGroup) {
      lastInGroup.after(row);
    } else if (header) {
      header.after(row);
    } else {
      fieldList.appendChild(row);
    }
  }

  function addDynamicFieldToGroup(group) {
    var tmpl = document.getElementById('dynamicFieldRowTemplate');
    if (!tmpl) return;
    var wrap = document.createElement('div');
    wrap.innerHTML = tmpl.innerHTML.trim();
    var row = wrap.firstElementChild;
    insertFieldRowIntoGroup(row, group || 'other');
    var labelInput = row.querySelector('[name="original_dynamic_label[]"]');
    if (labelInput) labelInput.focus();
  }

  // 処方薬は専用カードだけを正とする。旧dynamic-field同期ロジックは二重管理の原因になるため削除。

  function findReviewValueByExactKey(key) {
    return document.querySelector('[data-review-value][data-field-key="' + key + '"]');
  }

  function findReviewValueByLabel(group, patterns) {
    var matched = null;
    document.querySelectorAll('.review-field-row').forEach(function (row) {
      if (matched) return;
      var rowGroup = row.querySelector('[name="original_dynamic_group[]"]')?.value || row.getAttribute('data-field-group') || '';
      if (group && rowGroup !== group) return;
      var label = row.querySelector('[name="original_dynamic_label[]"]')?.value || '';
      var key = row.querySelector('[name="original_dynamic_key[]"]')?.value || '';
      var text = label + ' ' + key;
      var ok = patterns.some(function (pattern) { return pattern.test(text); });
      if (!ok) return;
      matched = row.querySelector('[data-review-value]');
    });
    return matched;
  }

  function fixedFieldFallbackControl(key) {
    switch (key) {
      case 'patient.name': return findReviewValueByLabel('patient', [/氏名/, /患者名/, /name/i]);
      case 'patient.gender': return findReviewValueByLabel('patient', [/性別/, /男|女/, /gender/i]);
      case 'patient.birth_date': return findReviewValueByLabel('patient', [/生年月日/, /birth/i]);
      case 'insurance.insurance_no': return findReviewValueByLabel('insurance', [/保険者番号/, /insurance.*no/i]);
      case 'insurance.insured_symbol_number': return findReviewValueByLabel('insurance', [/記号/, /番号/, /被保険者/]);
      case 'insurance.copay_rate': return findReviewValueByLabel('insurance', [/負担割合/, /負担/]);
      case 'prescription.issued_on': return findReviewValueByLabel('prescription', [/交付年月日/, /発行日/, /issued/i]);
      case 'medical_institution.code': return findReviewValueByLabel('medical_institution', [/医療機関コード/, /コード/]);
      case 'medical_institution.name': return findReviewValueByLabel('medical_institution', [/医療機関名/, /医療機関.*名称/, /所在.*名称/]);
      case 'medical_institution.address': return findReviewValueByLabel('medical_institution', [/所在地/, /住所/]);
      case 'medical_institution.phone': return findReviewValueByLabel('medical_institution', [/電話/]);
      case 'medical_institution.doctor_name': return findReviewValueByLabel('medical_institution', [/保険医氏名/, /医師/, /署名/]);
      case 'public_expense.payer_no': return findReviewValueByLabel('public_expense', [/公費負担者番号/]);
      case 'public_expense.beneficiary_no': return findReviewValueByLabel('public_expense', [/受給者番号/, /公費負担医療/]);
      default: return null;
    }
  }

  function syncFixedHiddenFields() {
    document.querySelectorAll('[data-fixed-field]').forEach(function (hidden) {
      var key = hidden.getAttribute('data-fixed-field');
      var control = findReviewValueByExactKey(key) || fixedFieldFallbackControl(key);
      if (control) hidden.value = control.value || '';
    });
  }

  function syncPreview() {
    recalculateAllMedicationAmounts();
    syncFixedHiddenFields();
    document.querySelectorAll('[data-preview]').forEach(function (node) {
      var key = node.getAttribute('data-preview');
      var hidden = document.querySelector('[data-fixed-field="' + key + '"]');
      node.textContent = hidden && hidden.value ? hidden.value : '—';
    });

    var meds = document.querySelector('[data-preview-meds]');
    if (meds) {
      meds.innerHTML = '';
      document.querySelectorAll('[data-med-list] .ocr-med-row').forEach(function (row) {
        var drug = row.querySelector('[name="drug_name[]"]')?.value || '';
        var usage = row.querySelector('[name="usage_text[]"]')?.value || '';
        var days = row.querySelector('[name="days_count[]"]')?.value || '';
        var amount = row.querySelector('[name="amount_text[]"]')?.value || '';
        if (!drug && !usage && !days && !amount) return;
        var div = document.createElement('div');
        div.className = 'preview-med-row';
        div.innerHTML = '<strong></strong><span></span><span></span><span></span>';
        div.children[0].textContent = drug || '薬品名未入力';
        div.children[1].textContent = usage || '用法未入力';
        div.children[2].textContent = days ? days + '日' : '日数未入力';
        div.children[3].textContent = amount || '';
        meds.appendChild(div);
      });
      if (!meds.children.length) {
        meds.innerHTML = '<p class="muted">処方薬が未入力です。</p>';
      }
    }
  }

  document.addEventListener('input', function (event) {
    if (event.target instanceof HTMLElement && event.target.closest('.result-card')) {
      if (event.target.matches('[name="amount_text[]"]')) {
        event.target.dataset.userEdited = '1';
      }
      if (event.target.matches('[name="drug_name[]"], [name="dose_text[]"], [name="usage_text[]"], [name="days_count[]"]')) {
        var medRow = event.target.closest('.ocr-med-row');
        if (medRow) {
          var amount = medRow.querySelector('[name="amount_text[]"]');
          if (amount && amount.dataset.autoAmount === '1') amount.dataset.userEdited = '0';
          recalculateMedicationRowAmount(medRow, false);
        }
      }
      syncPreview();
    }
  });
  document.addEventListener('change', function (event) {
    if (event.target instanceof HTMLElement && event.target.matches('[data-manual-group-select]')) {
      var row = event.target.closest('.manual-added-field');
      if (row) insertFieldRowIntoGroup(row, event.target.value || 'other');
    }
    if (event.target instanceof HTMLElement && event.target.closest('.result-card')) {
      syncPreview();
    }
  });

  function clearValidationHighlights() {
    document.querySelectorAll('.validation-missing').forEach(function (node) { node.classList.remove('validation-missing'); });
    document.querySelectorAll('[aria-invalid="true"]').forEach(function (node) { node.removeAttribute('aria-invalid'); });
    document.querySelectorAll('[data-validation-message]').forEach(function (node) { node.remove(); });
  }

  function appendValidationMessage(container, message) {
    if (!container) return;
    var msg = document.createElement('div');
    msg.className = 'validation-message';
    msg.setAttribute('data-validation-message', '1');
    msg.textContent = message;
    container.appendChild(msg);
  }

  function markControlInvalid(control, message) {
    if (!control) return;
    control.classList.add('validation-missing');
    control.setAttribute('aria-invalid', 'true');
    var row = control.closest('.review-field-row') || control.closest('.ocr-med-row') || control.closest('label') || control.parentElement;
    if (row) {
      row.classList.add('validation-missing');
      appendValidationMessage(row, message);
    }
  }

  function markFieldInvalid(key, label, message) {
    var control = findReviewValueByExactKey(key) || fixedFieldFallbackControl(key);
    markControlInvalid(control, message || (label + 'を入力してください。'));
  }

  function fixedValue(key) {
    var control = findReviewValueByExactKey(key) || fixedFieldFallbackControl(key);
    if (control) return String(control.value || '').trim();
    var hidden = document.querySelector('[data-fixed-field="' + key + '"]');
    return hidden ? String(hidden.value || '').trim() : '';
  }

  function showValidationAlert(messages) {
    var alert = document.querySelector('[data-validation-alert]');
    var text = document.querySelector('[data-validation-alert-text]');
    if (!alert || !text) return;
    text.textContent = messages.length ? messages.join(' / ') : '赤く表示された項目を入力してください。';
    alert.hidden = false;
    alert.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function hideValidationAlert() {
    var alert = document.querySelector('[data-validation-alert]');
    if (alert) alert.hidden = true;
  }

  function markMedicationFieldInvalid(index, fieldName, message) {
    var rows = Array.from(document.querySelectorAll('[data-med-list] .ocr-med-row'));
    var row = rows[index] || null;
    if (!row) return;
    markControlInvalid(row.querySelector('[name="' + fieldName + '[]"]'), message);
  }

  function markServerValidationErrors(messages) {
    if (!Array.isArray(messages) || !messages.length) return;
    messages.forEach(function (message) {
      var text = String(message || '');
      if (text.includes('氏名') && !text.includes('保険医')) markFieldInvalid('patient.name', '患者名', text);
      if (text.includes('生年月日')) markFieldInvalid('patient.birth_date', '生年月日', text);
      if (text.includes('保険者番号')) markFieldInvalid('insurance.insurance_no', '保険者番号', text);
      if (text.includes('被保険者証') || text.includes('記号・番号') || text.includes('記号番号')) markFieldInvalid('insurance.insured_symbol_number', '被保険者証・記号番号', text);
      if (text.includes('交付年月日')) markFieldInvalid('prescription.issued_on', '交付年月日', text);
      if (text.includes('医療機関コード')) markFieldInvalid('medical_institution.code', '医療機関コード', text);
      if (text.includes('保険医療機関名')) markFieldInvalid('medical_institution.name', '保険医療機関名', text);
      if (text.includes('保険医氏名')) markFieldInvalid('medical_institution.doctor_name', '保険医氏名', text);
      if (text.includes('公費負担者番号')) markFieldInvalid('public_expense.payer_no', '公費負担者番号', text);
      if (text.includes('公費負担医療の受給者番号') || text.includes('公費受給者番号')) markFieldInvalid('public_expense.beneficiary_no', '公費負担医療の受給者番号', text);
      var medMatch = text.match(/処方(\d+)の(.+?)が/);
      if (medMatch) {
        var medIndex = parseInt(medMatch[1], 10) - 1;
        if (text.includes('薬品名')) markMedicationFieldInvalid(medIndex, 'drug_name', text);
        if (text.includes('用法')) markMedicationFieldInvalid(medIndex, 'usage_text', text);
        if (text.includes('日数') || text.includes('総量')) {
          markMedicationFieldInvalid(medIndex, 'days_count', text);
          markMedicationFieldInvalid(medIndex, 'amount_text', text);
        }
      }
    });
    var first = document.querySelector('[aria-invalid="true"]');
    if (first && typeof first.scrollIntoView === 'function') first.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function validateResultBeforeSubmit(shouldFocus) {
    syncFixedHiddenFields();
    recalculateAllMedicationAmounts();
    clearValidationHighlights();
    var messages = [];
    var requiredFields = [
      ['patient.name', '患者名'],
      ['patient.birth_date', '生年月日'],
      ['insurance.insurance_no', '保険者番号'],
      ['insurance.insured_symbol_number', '被保険者証・記号番号'],
      ['prescription.issued_on', '交付年月日'],
      ['medical_institution.code', '医療機関コード'],
      ['medical_institution.name', '保険医療機関名'],
      ['medical_institution.doctor_name', '保険医氏名']
    ];
    requiredFields.forEach(function (item) {
      if (!fixedValue(item[0])) {
        var message = item[1] + 'が未入力です。';
        messages.push(message);
        markFieldInvalid(item[0], item[1], message);
      }
    });

    var publicPayer = fixedValue('public_expense.payer_no');
    var publicBeneficiary = fixedValue('public_expense.beneficiary_no');
    if (publicPayer || publicBeneficiary) {
      if (!publicPayer) {
        var payerMessage = '公費負担者番号が未入力です。';
        messages.push(payerMessage);
        markFieldInvalid('public_expense.payer_no', '公費負担者番号', payerMessage);
      }
      if (!publicBeneficiary) {
        var beneficiaryMessage = '公費負担医療の受給者番号が未入力です。';
        messages.push(beneficiaryMessage);
        markFieldInvalid('public_expense.beneficiary_no', '公費負担医療の受給者番号', beneficiaryMessage);
      }
    }

    var hasMedication = false;
    document.querySelectorAll('[data-med-list] .ocr-med-row').forEach(function (row, index) {
      var drug = row.querySelector('[name="drug_name[]"]');
      var usage = row.querySelector('[name="usage_text[]"]');
      var days = row.querySelector('[name="days_count[]"]');
      var amount = row.querySelector('[name="amount_text[]"]');
      var drugValue = String(drug?.value || '').trim();
      var usageValue = String(usage?.value || '').trim();
      var daysValue = String(days?.value || '').trim();
      var amountValue = String(amount?.value || '').trim();
      if (!drugValue && !usageValue && !daysValue && !amountValue) return;
      hasMedication = true;
      if (!drugValue) {
        var drugMessage = '処方' + (index + 1) + 'の薬品名が未入力です。';
        messages.push(drugMessage);
        markControlInvalid(drug, drugMessage);
      }
      if (!usageValue) {
        var usageMessage = '処方' + (index + 1) + 'の用法が未入力です。';
        messages.push(usageMessage);
        markControlInvalid(usage, usageMessage);
      }
      if (!daysValue && !amountValue) {
        var daysMessage = '処方' + (index + 1) + 'の日数または総量が未入力です。';
        messages.push(daysMessage);
        markControlInvalid(days, daysMessage);
        markControlInvalid(amount, daysMessage);
      }
    });
    if (!hasMedication) {
      var medList = document.querySelector('[data-med-list]');
      if (medList) {
        medList.classList.add('validation-missing');
        appendValidationMessage(medList, '薬の情報が0件です。薬品行を追加して入力してください。');
      }
      messages.push('薬の情報が0件です。');
    }

    if (messages.length) {
      showValidationAlert(Array.from(new Set(messages)));
      if (shouldFocus !== false) {
        var first = document.querySelector('[aria-invalid="true"]');
        if (first && typeof first.focus === 'function') first.focus({ preventScroll: true });
      }
      return false;
    }
    hideValidationAlert();
    return true;
  }

  document.addEventListener('submit', function (event) {
    if (event.target instanceof HTMLFormElement && event.target.classList.contains('result-card')) {
      syncFixedHiddenFields();
      syncPreview();
      if (!validateResultBeforeSubmit(true)) {
        event.preventDefault();
      }
    }
  });

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (target.hasAttribute('data-delete-med')) {
      var row = target.closest('.ocr-med-row');
      if (!row) return;
      row.querySelectorAll('input, textarea').forEach(function (input) { input.value = ''; });
      row.querySelectorAll('select').forEach(function (select) { select.value = 'unknown'; });
      row.remove();
      renumberMedicationRows();
      syncPreview();
      return;
    }

    if (target.hasAttribute('data-add-med')) {
      var list = document.querySelector('[data-med-list]');
      var tmpl = document.getElementById('medicationRowTemplate');
      if (!list || !tmpl) return;
      var html = tmpl.innerHTML.replace(/__NO__/g, String(list.querySelectorAll('.ocr-med-row').length + 1));
      var wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      var addedRow = wrap.firstElementChild;
      list.appendChild(addedRow);
      renumberMedicationRows();
      recalculateMedicationRowAmount(addedRow, true);
      syncPreview();
      return;
    }

    if (target.hasAttribute('data-add-dynamic-field')) {
      var group = target.getAttribute('data-group');
      if (!group && target.getAttribute('data-use-selected-group') === '1') {
        var groupSelect = document.querySelector('[data-add-dynamic-group]');
        group = groupSelect ? groupSelect.value : 'other';
      }
      addDynamicFieldToGroup(group || 'other');
      syncPreview();
      return;
    }
  });

  document.addEventListener('keydown', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (event.key !== 'Enter') return;
    if (target.tagName === 'TEXTAREA') return;
    if (target.closest('.result-card') && target.tagName === 'INPUT') {
      event.preventDefault();
    }
  });

  syncPreview();
  var SERVER_VALIDATION_ERRORS = <?= json_encode($serverValidationErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var HAS_SERVER_VALIDATION_ERROR = SERVER_VALIDATION_ERRORS.length > 0;
  if (HAS_SERVER_VALIDATION_ERROR) {
    validateResultBeforeSubmit(false);
    markServerValidationErrors(SERVER_VALIDATION_ERRORS);
  }
})();
</script>
<?php View::footer(); ?>
