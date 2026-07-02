<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/prescription_scan.php');
}
Csrf::verify();
$post = $_POST;

$knowledgeService = new PrescriptionKnowledgeService();
$fieldPreferences = method_exists($knowledgeService, 'branchFieldPreferenceMap')
    ? $knowledgeService->branchFieldPreferenceMap()
    : [];

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

function select_string(array $source, string $key): string
{
    return trim((string)($source[$key] ?? ''));
}

function normalize_field_key(string $key): string
{
    return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($key)) ?: 'field';
}

/** @return array<int,array<string,mixed>> */
function original_dynamic_fields_from_post(array $post): array
{
    $keys = $post['original_dynamic_key'] ?? [];
    $labels = $post['original_dynamic_label'] ?? [];
    $groups = $post['original_dynamic_group'] ?? [];
    $values = $post['original_dynamic_value'] ?? [];
    $aiValues = $post['original_dynamic_ai_value'] ?? [];
    $sections = $post['original_dynamic_source_section'] ?? [];
    $confidences = $post['original_dynamic_confidence'] ?? [];
    $needs = $post['original_dynamic_needs_human_check'] ?? [];
    $include = $post['original_dynamic_include_default'] ?? [];
    $rows = [];
    foreach ($keys as $i => $key) {
        $label = trim((string)($labels[$i] ?? $key));
        $key = normalize_field_key((string)$key);
        if ($key === 'manual_field') {
            $key = normalize_field_key('manual_' . ($label !== '' ? $label : 'field') . '_' . (string)($i + 1));
        }
        $value = trim((string)($values[$i] ?? ''));
        $aiValue = trim((string)($aiValues[$i] ?? $value));
        $rows[] = [
            'index' => $i,
            'key' => $key,
            'label' => $label !== '' ? $label : $key,
            'group' => trim((string)($groups[$i] ?? 'other')) ?: 'other',
            'value' => $value,
            'ai_value' => $aiValue,
            'section' => trim((string)($sections[$i] ?? '')),
            'confidence' => is_numeric($confidences[$i] ?? null) ? (float)$confidences[$i] : null,
            'needs' => (isset($needs[$i]) && (string)$needs[$i] === '1') || ($aiValue !== '' && $value !== '' && $aiValue !== $value),
            'include_default' => isset($include[$i]) && (string)$include[$i] === '1',
        ];
    }
    return $rows;
}

/** @return array{value:string,index:?int} */
function find_original_med_value(array $originals, int $medIndex, string $type, string $fallback): array
{
    $n = $medIndex + 1;
    $typeWords = match ($type) {
        'drug_name' => ['薬品', '医薬品', '薬名', '処方薬', '名称'],
        'usage_text' => ['用法', '服用', '飲み方', '回', '食後', '食前', '頓服'],
        'days_count' => ['日数', '日分', '日間'],
        'amount_text' => ['数量', '総量', '量', '備考'],
        default => [],
    };
    foreach ($originals as $row) {
        $label = (string)($row['label'] ?? '');
        $key = (string)($row['key'] ?? '');
        $target = $label . ' ' . $key;
        $hasMedNo = str_contains($target, '処方' . $n) || str_contains($target, '薬' . $n) || str_contains($target, 'medication.' . (string)$n) || str_contains($target, 'medications.' . (string)$n);
        if (!$hasMedNo && $medIndex === 0 && (str_contains($target, '処方1') || str_contains($target, '処方 1'))) {
            $hasMedNo = true;
        }
        if (!$hasMedNo) {
            continue;
        }
        foreach ($typeWords as $word) {
            if (str_contains($target, $word)) {
                return ['value' => (string)($row['ai_value'] ?? ($row['value'] ?? $fallback)), 'index' => (int)$row['index']];
            }
        }
    }
    return ['value' => $fallback, 'index' => null];
}

/** @return array<string,mixed> */
function make_field_row(string $key, string $label, string $group, string $value, string $aiValue, string $section, ?float $confidence, bool $needsHumanCheck, array $preferences, bool $defaultSelected = true): array
{
    $key = normalize_field_key($key);
    $selected = array_key_exists($key, $preferences) ? (bool)$preferences[$key] : $defaultSelected;
    return [
        'key' => $key,
        'label' => $label,
        'group' => $group,
        'value' => $value,
        'ai_value' => $aiValue,
        'section' => $section,
        'confidence' => $confidence,
        'needs' => $needsHumanCheck || ($aiValue !== '' && $value !== '' && $aiValue !== $value),
        'selected' => $selected,
        'confirmed' => true,
    ];
}

$originals = original_dynamic_fields_from_post($post);
$usedOriginalIndexes = [];
$rows = [];

$fixedMap = [
    ['patient.name', '患者名', 'patient', 'patient_name', 'ai_patient_name'],
    ['patient.gender', '性別', 'patient', 'gender', 'ai_gender'],
    ['patient.birth_date', '生年月日', 'patient', 'birth_date', 'ai_birth_date'],
    ['insurance.insurance_no', '保険者番号', 'insurance', 'insurance_no', 'ai_insurance_no'],
    ['insurance.insured_symbol_number', '記号番号', 'insurance', 'insured_symbol_number', 'ai_insured_symbol_number'],
    ['insurance.copay_rate', '負担割合', 'insurance', 'copay_rate', 'ai_copay_rate'],
    ['prescription.issued_on', '処方箋発行日', 'prescription', 'issued_on', 'ai_issued_on'],
    ['medical_institution.code', '医療機関コード', 'medical_institution', 'medical_institution_code', 'ai_medical_institution_code'],
    ['medical_institution.name', '医療機関名', 'medical_institution', 'medical_institution_name', 'ai_medical_institution_name'],
];
foreach ($fixedMap as [$key, $label, $group, $postKey, $aiKey]) {
    $value = select_string($post, $postKey);
    $aiValue = select_string($post, $aiKey);
    if ($value === '' && $aiValue === '') {
        continue;
    }
    $rows[] = make_field_row($key, $label, $group, $value, $aiValue, '確定データ', null, false, $fieldPreferences, $value !== '');
}

$drugNames = $post['drug_name'] ?? [];
$usageTexts = $post['usage_text'] ?? [];
$daysCounts = $post['days_count'] ?? [];
$amountTexts = $post['amount_text'] ?? [];
$genericNames = $post['generic_name'] ?? [];
$brandNames = $post['brand_name'] ?? [];
$rawDrugTexts = $post['raw_drug_text'] ?? [];
$relationTypes = $post['drug_name_relation_type'] ?? [];
$stockStatuses = $post['stock_status'] ?? [];
$aiDrugNames = $post['ai_drug_name'] ?? [];
$aiGenericNames = $post['ai_generic_name'] ?? [];
$aiBrandNames = $post['ai_brand_name'] ?? [];
$aiRawDrugTexts = $post['ai_raw_drug_text'] ?? [];

$aiUsageTexts = $post['ai_usage_text'] ?? [];
$aiDaysCounts = $post['ai_days_count'] ?? [];
$aiAmountTexts = $post['ai_amount_text'] ?? [];

$medCount = max(count($drugNames), count($usageTexts), count($daysCounts), count($amountTexts), count($genericNames), count($brandNames), count($rawDrugTexts));
for ($i = 0; $i < $medCount; $i++) {
    $drug = trim((string)($drugNames[$i] ?? ''));
    $usage = trim((string)($usageTexts[$i] ?? ''));
    $days = trim((string)($daysCounts[$i] ?? ''));
    $amount = trim((string)($amountTexts[$i] ?? ''));
    $generic = trim((string)($genericNames[$i] ?? ''));
    $brand = trim((string)($brandNames[$i] ?? ''));
    $rawDrug = trim((string)($rawDrugTexts[$i] ?? ''));
    if ($drug === '' && $usage === '' && $days === '' && $amount === '' && $generic === '' && $brand === '' && $rawDrug === '') {
        continue;
    }
    $n = $i + 1;
    $aiDrug = find_original_med_value($originals, $i, 'drug_name', trim((string)($aiDrugNames[$i] ?? '')));
    $aiUsage = find_original_med_value($originals, $i, 'usage_text', trim((string)($aiUsageTexts[$i] ?? '')));
    $aiDays = find_original_med_value($originals, $i, 'days_count', trim((string)($aiDaysCounts[$i] ?? '')));
    $aiAmount = find_original_med_value($originals, $i, 'amount_text', trim((string)($aiAmountTexts[$i] ?? '')));
    foreach ([$aiDrug, $aiUsage, $aiDays, $aiAmount] as $found) {
        if ($found['index'] !== null) {
            $usedOriginalIndexes[(int)$found['index']] = true;
        }
    }
    $relation = trim((string)($relationTypes[$i] ?? 'unknown'));
    $rows[] = make_field_row("medication.$n.drug_name", "処方{$n}の薬品名", 'medication', $drug, $aiDrug['value'], '確定データ', null, false, $fieldPreferences, $drug !== '');
    $rows[] = make_field_row("medication.$n.generic_name", "処方{$n}の一般名候補", 'medication', $generic, trim((string)($aiGenericNames[$i] ?? '')), '確定データ', null, false, $fieldPreferences, $generic !== '');
    $rows[] = make_field_row("medication.$n.brand_name", "処方{$n}の商品名候補", 'medication', $brand, trim((string)($aiBrandNames[$i] ?? '')), '確定データ', null, false, $fieldPreferences, $brand !== '');
    $rows[] = make_field_row("medication.$n.raw_drug_text", "処方{$n}の薬品名元テキスト", 'medication', $rawDrug, trim((string)($aiRawDrugTexts[$i] ?? '')), '確定データ', null, false, $fieldPreferences, $rawDrug !== '');
    $rows[] = make_field_row("medication.$n.usage_text", "処方{$n}の用法", 'medication', $usage, $aiUsage['value'], '確定データ', null, false, $fieldPreferences, $usage !== '');
    $rows[] = make_field_row("medication.$n.days_count", "処方{$n}の日数", 'medication', $days, $aiDays['value'], '確定データ', null, false, $fieldPreferences, $days !== '');
    $rows[] = make_field_row("medication.$n.amount_text", "処方{$n}の総量/備考", 'medication', $amount, $aiAmount['value'], '確定データ', null, false, $fieldPreferences, $amount !== '');
    $rows[] = make_field_row("medication.$n.relation_type", "処方{$n}の薬品名関係", 'medication', $relation, '', '確定データ', null, false, $fieldPreferences, $relation !== '' && $relation !== 'unknown');
}

$existingKeys = array_fill_keys(array_map(static fn($row) => $row['key'], $rows), true);
foreach ($originals as $orig) {
    if (isset($usedOriginalIndexes[(int)$orig['index']])) {
        continue;
    }
    $key = normalize_field_key((string)$orig['key']);
    if (isset($existingKeys[$key])) {
        continue;
    }
    $group = (string)$orig['group'];
    if (!isset($fieldGroupLabels[$group])) {
        $group = 'other';
    }
    $selected = array_key_exists($key, $fieldPreferences) ? (bool)$fieldPreferences[$key] : (bool)$orig['include_default'];
    $rows[] = [
        'key' => $key,
        'label' => (string)$orig['label'],
        'group' => $group,
        'value' => (string)$orig['value'],
        'ai_value' => (string)($orig['ai_value'] ?? $orig['value']),
        'section' => (string)$orig['section'],
        'confidence' => $orig['confidence'],
        'needs' => (bool)$orig['needs'] || ((string)($orig['ai_value'] ?? '') !== '' && (string)($orig['ai_value'] ?? '') !== (string)$orig['value']),
        'selected' => $selected,
        'confirmed' => false,
    ];
}

$groupOrder = array_keys($fieldGroupLabels);
usort($rows, static function (array $a, array $b) use ($groupOrder): int {
    $ga = array_search((string)$a['group'], $groupOrder, true);
    $gb = array_search((string)$b['group'], $groupOrder, true);
    $ga = $ga === false ? 999 : $ga;
    $gb = $gb === false ? 999 : $gb;
    if ($ga === $gb) {
        return strcmp((string)$a['label'], (string)$b['label']);
    }
    return $ga <=> $gb;
});

$parseJobIdForLearning = (int)select_string($post, 'parse_job_id');
$parseJobIdForLearning = $parseJobIdForLearning > 0 ? $parseJobIdForLearning : null;
$learningSaved = false;
try {
    $learningRows = array_map(static function (array $row): array {
        return [
            'field_key' => $row['key'],
            'field_label' => $row['label'],
            'field_group' => $row['group'],
            'source_ai_value' => $row['ai_value'],
            'field_value' => $row['value'],
            'confidence' => $row['confidence'],
            'needs_human_check' => $row['needs'],
        ];
    }, $rows);
    $knowledgeService->saveConfirmedCorrectionLearning($parseJobIdForLearning, (int)$user['tenant_id'], $learningRows);

    $drugLearningRows = function_exists('medication_name_learning_rows_from_post')
        ? medication_name_learning_rows_from_post($post)
        : [];
    if ($drugLearningRows) {
        $knowledgeService->saveDrugNameLearningEvents($parseJobIdForLearning, (int)$user['tenant_id'], null, $drugLearningRows);
    }
    $learningSaved = true;
} catch (Throwable) {
    $learningSaved = false;
}

View::header('使用項目の選択', ['styles' => ['/assets/css/prescription_field_select.css']]);
?>
<section class="page-title">
  <h1>使用項目の選択</h1>
  <p>前画面で確定した修正内容は補助学習DBへ保存済みです。この画面では、拠点運用としてDB保存・QR作成に使う項目だけを選択します。</p>
</section>

<?php if ($learningSaved): ?>
  <div class="alert success"><strong>補助学習データ保存済み</strong><br>AI読み取り値と人間修正後の差分を、OCR精度改善用の補助学習DBへ保存しました。この画面の使う/使わないは拠点ごとの運用選択です。</div>
<?php else: ?>
  <div class="alert warning"><strong>補助学習データの保存確認ができませんでした</strong><br>画面操作は続行できます。補助学習DBのmigrationまたは接続状態を確認してください。</div>
<?php endif; ?>

<form class="card result-card" method="post" action="<?= h(app_url('/prescription_save.php')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="parse_job_id" value="<?= h(select_string($post, 'parse_job_id')) ?>">
  <input type="hidden" name="ai_confidence" value="<?= h(select_string($post, 'ai_confidence')) ?>">

  <?php foreach (['patient_name','gender','birth_date','insurance_no','insured_symbol_number','copay_rate','issued_on','medical_institution_code','medical_institution_name'] as $name): ?>
    <input type="hidden" name="<?= h($name) ?>" value="<?= h(select_string($post, $name)) ?>">
  <?php endforeach; ?>

  <?php for ($i = 0; $i < $medCount; $i++): ?>
    <?php
      $drug = trim((string)($drugNames[$i] ?? ''));
      $usage = trim((string)($usageTexts[$i] ?? ''));
      $days = trim((string)($daysCounts[$i] ?? ''));
      $amount = trim((string)($amountTexts[$i] ?? ''));
      $generic = trim((string)($genericNames[$i] ?? ''));
      $brand = trim((string)($brandNames[$i] ?? ''));
      $rawDrug = trim((string)($rawDrugTexts[$i] ?? ''));
      if ($drug === '' && $usage === '' && $days === '' && $amount === '' && $generic === '' && $brand === '' && $rawDrug === '') { continue; }
    ?>
    <input type="hidden" name="drug_name[]" value="<?= h($drug) ?>">
    <input type="hidden" name="usage_text[]" value="<?= h($usage) ?>">
    <input type="hidden" name="days_count[]" value="<?= h($days) ?>">
    <input type="hidden" name="amount_text[]" value="<?= h($amount) ?>">
    <input type="hidden" name="generic_name[]" value="<?= h(trim((string)($genericNames[$i] ?? ''))) ?>">
    <input type="hidden" name="brand_name[]" value="<?= h(trim((string)($brandNames[$i] ?? ''))) ?>">
    <input type="hidden" name="raw_drug_text[]" value="<?= h(trim((string)($rawDrugTexts[$i] ?? ''))) ?>">
    <input type="hidden" name="drug_name_relation_type[]" value="<?= h(trim((string)($relationTypes[$i] ?? 'unknown'))) ?>">
    <input type="hidden" name="ai_drug_name[]" value="<?= h(trim((string)($aiDrugNames[$i] ?? ''))) ?>">
    <input type="hidden" name="ai_generic_name[]" value="<?= h(trim((string)($aiGenericNames[$i] ?? ''))) ?>">
    <input type="hidden" name="ai_brand_name[]" value="<?= h(trim((string)($aiBrandNames[$i] ?? ''))) ?>">
    <input type="hidden" name="ai_raw_drug_text[]" value="<?= h(trim((string)($aiRawDrugTexts[$i] ?? ''))) ?>">
    <input type="hidden" name="stock_status[]" value="<?= h((string)($stockStatuses[$i] ?? 'unknown')) ?>">
  <?php endfor; ?>

  <div class="confirm-flow-note">
    <span class="flow-step done">1. 読み取り結果を修正</span>
    <span class="flow-step active">2. 使用項目を選択</span>
    <span class="flow-step">3. DB保存</span>
    <span class="flow-step">4. QR作成</span>
  </div>

  <section class="field-selection-summary">
    <h2>確定データ概要</h2>
    <div class="summary-grid compact">
      <div><span>患者名</span><strong><?= h(select_string($post, 'patient_name')) ?></strong></div>
      <div><span>保険者番号</span><strong><?= h(select_string($post, 'insurance_no')) ?></strong></div>
      <div><span>医療機関</span><strong><?= h(select_string($post, 'medical_institution_name')) ?></strong></div>
      <div><span>処方薬</span><strong><?= h((string)$medCount) ?>件</strong></div>
    </div>
  </section>

  <section class="dynamic-field-card field-select-page">
    <div class="dynamic-field-head">
      <div>
        <h2>保存・出力に使う項目</h2>
        <p>「修正後の値」が保存・QR用の候補です。AI値との比較は前画面確定時点で補助学習DBへ保存済みです。</p>
      </div>
      <div class="field-actions">
        <button class="btn ghost small" type="button" data-field-check="all">全選択</button>
        <button class="btn ghost small" type="button" data-field-check="none">全解除</button>
      </div>
    </div>

    <div class="dynamic-field-grid">
      <?php $currentGroup = null; ?>
      <?php foreach ($rows as $i => $field): ?>
        <?php
          $group = (string)$field['group'];
          if (!isset($fieldGroupLabels[$group])) { $group = 'other'; }
          if ($currentGroup !== $group):
            $currentGroup = $group;
        ?>
          <h3 class="dynamic-field-group"><?= h($fieldGroupLabels[$group]) ?></h3>
        <?php endif; ?>

        <div class="dynamic-field-row learning-field-row <?= !empty($field['needs']) ? 'needs-check' : '' ?>">
          <label class="field-use-check">
            <input type="checkbox" name="dynamic_field_selected[<?= $i ?>]" value="1" <?= !empty($field['selected']) ? 'checked' : '' ?>>
            <span>使う</span>
          </label>

          <div class="field-main">
            <label>
              <span class="field-label"><?= h((string)$field['label']) ?></span>
              <input name="dynamic_field_value[<?= $i ?>]" value="<?= h((string)$field['value']) ?>" placeholder="空欄">
            </label>
            <div class="field-compare">
              <div><span>AI値</span><code><?= h((string)$field['ai_value']) ?></code></div>
              <div><span>修正後</span><strong><?= h((string)$field['value']) ?></strong></div>
            </div>
            <div class="field-meta">
              <?php if (!empty($field['confirmed'])): ?><span>確定データ</span><?php endif; ?>
              <?php if (!empty($field['section'])): ?><span><?= h((string)$field['section']) ?></span><?php endif; ?>
              <?php if ($field['confidence'] !== null): ?><span>信頼度 <?= h((string)round((float)$field['confidence'], 1)) ?>%</span><?php endif; ?>
              <?php if (!empty($field['needs'])): ?><span class="attention">修正済み/要確認</span><?php endif; ?>
            </div>
          </div>

          <input type="hidden" name="dynamic_field_key[<?= $i ?>]" value="<?= h((string)$field['key']) ?>">
          <input type="hidden" name="dynamic_field_label[<?= $i ?>]" value="<?= h((string)$field['label']) ?>">
          <input type="hidden" name="dynamic_field_group[<?= $i ?>]" value="<?= h($group) ?>">
          <input type="hidden" name="dynamic_field_ai_value[<?= $i ?>]" value="<?= h((string)$field['ai_value']) ?>">
          <input type="hidden" name="dynamic_field_source_section[<?= $i ?>]" value="<?= h((string)$field['section']) ?>">
          <input type="hidden" name="dynamic_field_confidence[<?= $i ?>]" value="<?= h((string)($field['confidence'] ?? '')) ?>">
          <input type="hidden" name="dynamic_field_needs_human_check[<?= $i ?>]" value="<?= !empty($field['needs']) ? '1' : '0' ?>">
          <input type="hidden" name="dynamic_field_output_candidate[<?= $i ?>]" value="1">
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="actions sticky-save-actions">
    <button class="btn ghost" type="button" onclick="history.back()">修正画面へ戻る</button>
    <button class="btn primary" type="submit">選択内容でDB保存</button>
  </div>
</form>

<script>
(function () {
  document.addEventListener('click', function (event) {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const mode = target.getAttribute('data-field-check');
    if (!mode) return;
    document.querySelectorAll('input[name^="dynamic_field_selected"]').forEach(function (checkbox) {
      checkbox.checked = mode === 'all';
    });
  });
})();
</script>
<?php View::footer(); ?>
