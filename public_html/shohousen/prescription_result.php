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

$candidates = $data['_correction_candidates'] ?? [];
$patient = $data['patient'] ?? [];
$insurance = $data['insurance'] ?? [];
$prescription = $data['prescription'] ?? [];
$medical = $data['medical_institution'] ?? [];
$medications = is_array($data['medications'] ?? null) ? $data['medications'] : [];
$dynamicFields = is_array($data['form_fields'] ?? null) ? $data['form_fields'] : [];
$knowledgeService = new PrescriptionKnowledgeService();
$fieldPreferences = method_exists($knowledgeService, 'branchFieldPreferenceMap')
    ? $knowledgeService->branchFieldPreferenceMap()
    : [];
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
    'other' => 'その他AI項目',
];

function ocr_string(mixed $value): string
{
    return trim((string)$value);
}

function normalize_review_key(string $key): string
{
    return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($key)) ?: 'field';
}

function canonical_review_key(array $field): string
{
    $key = normalize_review_key((string)($field['field_key'] ?? ''));
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
        if (str_contains($target, '医療機関') && (str_contains($target, '名称') || str_contains($target, '所在'))) return 'medical_institution.name';
        if (str_contains($target, '医師')) return 'medical_institution.doctor_name';
        if (str_contains($target, '電話')) return 'medical_institution.phone';
    }
    return $key !== 'field' ? $key : normalize_review_key($label);
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
        'confidence' => is_numeric($field['confidence'] ?? null) ? (float)$field['confidence'] : null,
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
    if ($rows[$key]['confidence'] === null && $row['confidence'] !== null) {
        $rows[$key]['confidence'] = $row['confidence'];
    }
    $rows[$key]['needs_human_check'] = $rows[$key]['needs_human_check'] || $row['needs_human_check'];
    $rows[$key]['include_default'] = $rows[$key]['include_default'] || $row['include_default'];
    if ($row['source_section'] !== '' && $rows[$key]['source_section'] === '') {
        $rows[$key]['source_section'] = $row['source_section'];
    }
    $rows[$key]['display_order'] = min((int)$rows[$key]['display_order'], (int)$row['display_order']);
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
    ['patient.name', '患者名', 'patient', $patient['name'] ?? '', 'person_name', 'input', 10],
    ['patient.gender', '性別', 'patient', $patient['gender'] ?? '', 'text', 'select', 20],
    ['patient.birth_date', '生年月日', 'patient', $patient['birth_date'] ?? '', 'date', 'date', 30],
    ['insurance.insurance_no', '保険者番号', 'insurance', $insurance['insurance_no'] ?? '', 'code', 'input', 40],
    ['insurance.insured_symbol_number', '記号番号', 'insurance', $insurance['insured_symbol_number'] ?? '', 'code', 'input', 50],
    ['insurance.copay_rate', '負担割合', 'insurance', $insurance['copay_rate'] ?? '', 'text', 'input', 60],
    ['prescription.issued_on', '処方箋発行日', 'prescription', $prescription['issued_on'] ?? '', 'date', 'date', 70],
    ['prescription.expires_on', '処方箋使用期間', 'prescription', $prescription['expires_on'] ?? '', 'date', 'date', 75],
    ['medical_institution.code', '医療機関コード', 'medical_institution', $medical['code'] ?? '', 'code', 'input', 80],
    ['medical_institution.name', '医療機関名', 'medical_institution', $medical['name'] ?? '', 'text', 'input', 90],
];
foreach ($fixedDefinitions as [$key, $label, $group, $value, $valueType, $uiTemplate, $order]) {
    $value = ocr_string($value);
    if ($value === '') {
        continue;
    }
    upsert_review_field($reviewRowsByKey, [
        'field_key' => $key,
        'field_label' => $label,
        'field_group' => $group,
        'value' => $value,
        'ai_value' => $value,
        'source_section' => '標準項目',
        'confidence' => $data['overall_confidence'] ?? null,
        'needs_human_check' => false,
        'include_default' => true,
        'ui_template' => $uiTemplate,
        'value_type' => $valueType,
        'display_order' => $order,
        'source' => 'normalized',
    ]);
}
foreach ($dynamicFields as $i => $field) {
    if (!is_array($field)) {
        continue;
    }
    $field['display_order'] = $field['display_order'] ?? (1000 + $i);
    upsert_review_field($reviewRowsByKey, $field);
}
$reviewRows = array_values($reviewRowsByKey);
$fieldGroupOrder = array_keys($fieldGroupLabels);
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
  <p>AIが読み取った項目を一覧で表示しています。ここでは使う/使わないは選ばず、値の修正と不足項目の追加だけを行います。</p>
</section>
<?php if (!empty($data['warnings'])): ?>
  <div class="alert info"><strong>解析メモ</strong><br><?= h(implode(' / ', array_map('strval', $data['warnings']))) ?></div>
<?php endif; ?>
<?php if ($jobId > 0): ?>
  <section class="card ocr-source-preview">
    <div>
      <h2>撮影画像</h2>
      <p>画像は画面内に収まる大きさで表示します。必要に応じて別画面で確認できます。</p>
    </div>
    <a class="btn ghost" href="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" target="_blank" rel="noopener">画像を別画面で開く</a>
    <img src="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" alt="撮影した処方箋画像" loading="lazy">
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

  <input type="hidden" name="ai_patient_name" value="<?= h((string)($patient['name'] ?? '')) ?>">
  <input type="hidden" name="ai_gender" value="<?= h((string)($patient['gender'] ?? '')) ?>">
  <input type="hidden" name="ai_birth_date" value="<?= h((string)($patient['birth_date'] ?? '')) ?>">
  <input type="hidden" name="ai_insurance_no" value="<?= h((string)($insurance['insurance_no'] ?? '')) ?>">
  <input type="hidden" name="ai_insured_symbol_number" value="<?= h((string)($insurance['insured_symbol_number'] ?? '')) ?>">
  <input type="hidden" name="ai_copay_rate" value="<?= h((string)($insurance['copay_rate'] ?? '')) ?>">
  <input type="hidden" name="ai_issued_on" value="<?= h((string)($prescription['issued_on'] ?? '')) ?>">
  <input type="hidden" name="ai_medical_institution_code" value="<?= h((string)($medical['code'] ?? '')) ?>">
  <input type="hidden" name="ai_medical_institution_name" value="<?= h((string)($medical['name'] ?? '')) ?>">

  <div class="confirm-flow-note">
    <span class="flow-step active">1. 読み取り項目を修正</span>
    <span class="flow-step">2. DB保存</span>
    <span class="flow-step">3. 使用項目を選択</span>
    <span class="flow-step">4. QR作成</span>
  </div>

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
        <h2>AI読み取り項目の修正</h2>
        <p>帳票上にある項目を一覧で修正します。固定テンプレートにない項目や空欄枠も、必要ならここで追加してください。</p>
      </div>
      <div class="field-actions dynamic-add-controls">
        <label class="field-add-target">
          <span class="sr-only">追加先分類</span>
          <select data-add-dynamic-group>
            <?php foreach ($fieldGroupLabels as $g => $gLabel): ?>
              <option value="<?= h($g) ?>"><?= h($gLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn ghost small" type="button" data-add-dynamic-field data-use-selected-group="1">選択した分類に項目を追加</button>
      </div>
    </div>

    <?php if (!$reviewRows): ?>
      <div class="alert warning">AIが読み取り項目を返していません。必要な項目は「項目を追加」から入力してください。</div>
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
          $confidence = is_numeric($field['confidence'] ?? null) ? (float)$field['confidence'] : null;
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
                  <?php foreach ($fieldGroupLabels as $g => $gLabel): ?>
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
              <span>AI読取値（自動保持）: <?= h($aiValue !== '' ? $aiValue : '空欄') ?></span>
              <?php if (!empty($field['source_section'])): ?><span><?= h((string)$field['source_section']) ?></span><?php endif; ?>
              <?php if ($confidence !== null): ?><span>信頼度 <?= h((string)round($confidence, 1)) ?>%</span><?php endif; ?>
              <?php if (!empty($field['needs_human_check'])): ?><span class="attention">要確認</span><?php endif; ?>
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
            <label><span class="field-label">分類</span><select name="original_dynamic_group[]" data-manual-group-select><?php foreach ($fieldGroupLabels as $g => $gLabel): ?><option value="<?= h($g) ?>"><?= h($gLabel) ?></option><?php endforeach; ?></select></label>
            <label class="review-field-value"><span class="field-label">修正後の値</span><textarea name="original_dynamic_value[]" rows="2" placeholder="追加で読み取り・入力した値" data-review-value data-field-key="manual_field"></textarea></label>
          </div>
          <div class="field-meta"><span>人間追加項目</span><span class="attention">AI未検出</span></div>
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
  <p class="form-help">一般名・商品名・元の読み取り行も補助学習対象として保存します。商品名と一般名が同じ処方内に併記されている場合は、1つの薬品行にまとめてください。</p>
  <div class="edit-med-list" data-med-list>
    <?php foreach ($medications as $i => $med): $drugCandidates = $candidates['medications'][$i]['drug_name'] ?? []; ?>
      <div class="edit-med-row ocr-med-row">
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
        <label>一般名候補<input name="generic_name[]" value="<?= h((string)($med['generic_name'] ?? '')) ?>" placeholder="例: アンブロキソール塩酸塩"></label>
        <label>商品名候補<input name="brand_name[]" value="<?= h((string)($med['brand_name'] ?? '')) ?>" placeholder="例: ムコソルバン錠"></label>
        <details class="med-learning-details">
          <summary>補助学習用の薬品名元テキストを確認</summary>
          <label class="med-field-raw">薬品名元テキスト
            <textarea name="raw_drug_text[]" rows="3" placeholder="AIが読んだ薬品名行。一般名・商品名を改行で残せます。\n例: ムコソルバン錠15mg\n【般】アンブロキソール塩酸塩錠15mg"><?= h((string)($med['raw_drug_text'] ?? ($med['drug_name'] ?? ''))) ?></textarea>
          </label>
          <?php if (!empty($med['_generic_master_candidates'])): ?>
            <div class="generic-master-hints">
              <strong>一般名処方マスタ候補</strong>
              <?php foreach ((array)$med['_generic_master_candidates'] as $candidate): ?>
                <span><?= h((string)($candidate['generic_prescription_name'] ?? '')) ?><?= !empty($candidate['ingredient_name']) ? ' / ' . h((string)$candidate['ingredient_name']) : '' ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </details>
        <label>用法<input name="usage_text[]" value="<?= h((string)($med['usage_text'] ?? '')) ?>"></label>
        <label>日数<input type="number" name="days_count[]" value="<?= h((string)($med['days_count'] ?? '')) ?>"></label>
        <label>総量/備考<input name="amount_text[]" value="<?= h((string)($med['amount_text'] ?? '')) ?>"></label>
        <label>在庫
          <select name="stock_status[]">
            <?php foreach (['adopted'=>'採用薬','in_stock'=>'在庫あり','low_stock'=>'在庫僅少','not_stocked'=>'未採用','unknown'=>'未確認'] as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $key === 'unknown' ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>薬品名の関係
          <select name="drug_name_relation_type[]">
            <?php $rel = (string)($med['name_relation'] ?? 'unknown'); ?>
            <?php foreach (['single'=>'単独薬品名','generic_brand_pair'=>'一般名・商品名の併記','multiple_candidates'=>'複数候補/要整理','unknown'=>'不明'] as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $key === $rel ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
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
      <label>一般名候補<input name="generic_name[]" value="" placeholder="一般名候補"></label>
      <label>商品名候補<input name="brand_name[]" value="" placeholder="商品名候補"></label>
      <details class="med-learning-details"><summary>補助学習用の薬品名元テキストを確認</summary><label class="med-field-raw">薬品名元テキスト<textarea name="raw_drug_text[]" rows="3" placeholder="AI読み取り行や追加入力を残します"></textarea></label></details>
      <label>用法<input name="usage_text[]" value=""></label>
      <label>日数<input type="number" name="days_count[]" value=""></label>
      <label>総量/備考<input name="amount_text[]" value=""></label>
      <label>在庫<select name="stock_status[]"><option value="unknown" selected>未確認</option><option value="adopted">採用薬</option><option value="in_stock">在庫あり</option><option value="low_stock">在庫僅少</option><option value="not_stocked">未採用</option></select></label>
      <label>薬品名の関係<select name="drug_name_relation_type[]"><option value="unknown" selected>不明</option><option value="single">単独薬品名</option><option value="generic_brand_pair">一般名・商品名の併記</option><option value="multiple_candidates">複数候補/要整理</option></select></label>
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

  <section class="prescription-confirm-preview" aria-label="修正内容の確認プレビュー">
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
    <button class="btn primary" type="submit">修正内容をDB保存して次へ</button>
  </div>
</form>
<script>
(function () {
  var FIELD_GROUP_LABELS = <?= json_encode($fieldGroupLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  function renumberMedicationRows() {
    document.querySelectorAll('[data-med-list] .ocr-med-row').forEach(function (row, index) {
      var no = row.querySelector('.row-no');
      if (no) no.textContent = String(index + 1);
    });
  }

  function normalizeTextForDetect(value) {
    return String(value || '').replace(/\s+/g, '').toLowerCase();
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
    title.textContent = FIELD_GROUP_LABELS[group] || FIELD_GROUP_LABELS.other || 'その他AI項目';

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

  function detectMedicationIndex(row) {
    var label = row.querySelector('[name="original_dynamic_label[]"]')?.value || '';
    var key = row.querySelector('[name="original_dynamic_key[]"]')?.value || '';
    var text = label + ' ' + key;
    var m = text.match(/(?:処方|薬|薬品|medication|med|drug)[^0-9０-９]*(\d+|[０-９]+)/i);
    if (!m) return 0;
    var num = String(m[1]).replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); });
    var index = parseInt(num, 10);
    return Number.isFinite(index) && index > 0 ? index - 1 : 0;
  }

  function detectMedicationTarget(row) {
    var label = row.querySelector('[name="original_dynamic_label[]"]')?.value || '';
    var key = row.querySelector('[name="original_dynamic_key[]"]')?.value || '';
    var group = row.querySelector('[name="original_dynamic_group[]"]')?.value || '';
    var text = normalizeTextForDetect(label + ' ' + key);

    if (group !== 'medication' && !/(処方|薬品|薬剤|医薬品|用法|用量|日数|総量|数量|備考|drug|medication|usage|days|amount|qty|quantity)/i.test(text)) {
      return '';
    }
    if (/(一般名|generic)/i.test(text)) return 'generic_name[]';
    if (/(商品名|brand)/i.test(text)) return 'brand_name[]';
    if (/(用法|服用|使用法|usage)/i.test(text)) return 'usage_text[]';
    if (/(日数|日分|days|duration)/i.test(text)) return 'days_count[]';
    if (/(総量|数量|用量|備考|amount|qty|quantity)/i.test(text)) return 'amount_text[]';
    if (/(薬品名|薬剤名|医薬品名|薬名|drugname|drug_name|medication)/i.test(text)) return 'drug_name[]';
    return '';
  }

  function ensureMedicationRow(index) {
    var list = document.querySelector('[data-med-list]');
    var tmpl = document.getElementById('medicationRowTemplate');
    if (!list) return null;
    while (list.querySelectorAll('.ocr-med-row').length <= index) {
      if (!tmpl) break;
      var html = tmpl.innerHTML.replace(/__NO__/g, String(list.querySelectorAll('.ocr-med-row').length + 1));
      var wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      list.appendChild(wrap.firstElementChild);
    }
    renumberMedicationRows();
    return list.querySelectorAll('.ocr-med-row')[index] || null;
  }

  function syncMedicationFromDynamicControl(control) {
    var row = control.closest('.review-field-row');
    if (!row) return;
    var targetName = detectMedicationTarget(row);
    if (!targetName) return;
    var index = detectMedicationIndex(row);
    var medRow = ensureMedicationRow(index);
    if (!medRow) return;
    var target = medRow.querySelector('[name="' + targetName + '"]');
    if (!target) return;
    target.value = control.value || '';
    target.classList.add('synced-from-dynamic');
    setTimeout(function () { target.classList.remove('synced-from-dynamic'); }, 700);
  }

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
      if (event.target.matches('[data-review-value]')) {
        syncMedicationFromDynamicControl(event.target);
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
      if (event.target.matches('[data-review-value]')) {
        syncMedicationFromDynamicControl(event.target);
      }
      syncPreview();
    }
  });

  document.addEventListener('submit', function (event) {
    if (event.target instanceof HTMLFormElement && event.target.classList.contains('result-card')) {
      syncFixedHiddenFields();
      syncPreview();
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
      list.appendChild(wrap.firstElementChild);
      renumberMedicationRows();
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
})();
</script>
<?php View::footer(); ?>
