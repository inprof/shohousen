<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$tenantId = (int)$user['tenant_id'];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $prescriptionId = (int)($_POST['prescription_id'] ?? 0);
    if ($prescriptionId <= 0) {
        http_response_code(400);
        exit('prescription_id がありません。解析結果確認画面からDB保存後に進んでください。');
    }
    $prescription = get_prescription($tenantId, $prescriptionId);
    if (!$prescription) {
        http_response_code(404);
        exit('保存済みデータが見つかりません');
    }
    update_prescription_selected_fields_from_post($tenantId, $prescriptionId, $_POST);
    redirect('/prescription_saved.php?id=' . $prescriptionId);
}

$prescriptionId = (int)($_GET['id'] ?? 0);
$prescription = $prescriptionId ? get_prescription($tenantId, $prescriptionId) : null;
if (!$prescription) {
    http_response_code(404);
    exit('保存済みデータが見つかりません。解析結果確認画面で「修正内容をDB保存して次へ」を押してください。');
}

$selectedFields = ensure_prescription_selected_fields_for_prescription($tenantId, $prescriptionId, $prescription);

function field_select_canonical_key(array $row): string
{
    $key = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim((string)($row['field_key'] ?? ''))) ?: 'field';
    $label = (string)($row['field_label'] ?? '');
    $group = (string)($row['field_group'] ?? 'other');
    $map = [
        'patient_name' => 'patient.name',
        'patient_birth_date' => 'patient.birth_date',
        'patient_gender' => 'patient.gender',
        'insurance_no' => 'insurance.insurance_no',
        'insured_symbol_number' => 'insurance.insured_symbol_number',
        'copay_rate' => 'insurance.copay_rate',
        'issued_on' => 'prescription.issued_on',
        'expires_on' => 'prescription.expires_on',
        'medical_institution_code' => 'medical_institution.code',
        'medical_institution_name' => 'medical_institution.name',
        'doctor_name' => 'medical_institution.doctor_name',
        'medical_institution_phone' => 'medical_institution.phone',
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    if ($group === 'medical_institution' && str_contains($label, '医療機関名')) {
        return 'medical_institution.name';
    }
    return $key;
}

function field_select_confidence_label(mixed $confidence): string
{
    if (!is_numeric($confidence)) {
        return '実績信頼度 未評価';
    }
    $value = (float)$confidence;
    if ($value >= 0.0 && $value <= 1.0) {
        $value *= 100.0;
    }
    $value = min(max($value, 0.0), 25.0);
    return '確認スコア ' . rtrim(rtrim((string)round($value, 1), '0'), '.') . '%';
}
$rows = [];
$seenDisplayKeys = [];
foreach ($selectedFields as $row) {
    $key = (string)($row['field_key'] ?? '');
    $group = (string)($row['field_group'] ?? '');
    if ($group === 'medication'
        || preg_match('/\.(generic_name|brand_name|raw_drug_text|relation_type)$/', $key)
        || str_contains($key, 'raw_drug_text')
        || str_contains($key, 'drug_name_relation')
        || str_contains($key, 'name_relation')) {
        continue;
    }
    $displayKey = field_select_canonical_key($row);
    if (isset($seenDisplayKeys[$displayKey])) {
        continue;
    }
    $seenDisplayKeys[$displayKey] = true;
    $row['field_key'] = $displayKey;
    $rows[] = $row;
}

$groupOrder = array_keys($fieldGroupLabels);
usort($rows, static function (array $a, array $b) use ($groupOrder): int {
    $ga = array_search((string)($a['field_group'] ?? 'other'), $groupOrder, true);
    $gb = array_search((string)($b['field_group'] ?? 'other'), $groupOrder, true);
    $ga = $ga === false ? 999 : $ga;
    $gb = $gb === false ? 999 : $gb;
    if ($ga === $gb) {
        return ((int)($a['display_order'] ?? 9999) <=> (int)($b['display_order'] ?? 9999)) ?: strcmp((string)($a['field_label'] ?? ''), (string)($b['field_label'] ?? ''));
    }
    return $ga <=> $gb;
});

View::header('使用項目の選択', ['styles' => ['/assets/css/prescription_field_select.css']]);
?>
<section class="page-title">
  <h1>使用項目の選択</h1>
  <p>修正内容はすでに拠点DBへ保存済みです。この画面では、拠点運用としてQR・後続出力に使う項目だけを選択します。</p>
</section>

<?php if (($_GET['saved'] ?? '') === '1'): ?>
  <div class="alert success"><strong>DB保存済み</strong><br>前画面で修正した内容を拠点DBに保存し、項目名・分類・順序を拠点ひな型候補へ反映しました。</div>
<?php endif; ?>

<form class="card result-card" method="post" action="<?= h(app_url('/prescription_field_select.php')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="prescription_id" value="<?= h((string)$prescriptionId) ?>">

  <div class="confirm-flow-note">
    <span class="flow-step done">1. 読み取り結果を修正</span>
    <span class="flow-step done">2. DB保存</span>
    <span class="flow-step active">3. 使用項目を選択</span>
    <span class="flow-step">4. QR作成</span>
  </div>

  <section class="field-selection-summary">
    <h2>保存済みデータ概要</h2>
    <div class="summary-grid compact">
      <div><span>保存ID</span><strong><?= h((string)$prescription['id']) ?></strong></div>
      <div><span>患者名</span><strong><?= h((string)$prescription['patient_name']) ?></strong></div>
      <div><span>医療機関</span><strong><?= h((string)($prescription['medical_name'] ?? '')) ?></strong></div>
      <div><span>処方薬</span><strong><?= h((string)count((array)($prescription['medications'] ?? []))) ?>件</strong></div>
    </div>
  </section>

  <section class="dynamic-field-card field-select-page">
    <div class="dynamic-field-head">
      <div>
        <h2>保存・出力に使う項目</h2>
        <p>ここではQRや後続出力に使う項目だけを選びます。値の修正は前画面で確定済みのため、この画面では編集できません。</p>
      </div>
      <div class="field-actions">
        <button class="btn ghost small" type="button" data-field-check="all">全選択</button>
        <button class="btn ghost small" type="button" data-field-check="none">全解除</button>
      </div>
    </div>

    <?php if (!$rows): ?>
      <div class="alert warning">保存済みの読み取り項目がありません。解析結果確認画面で項目が保存されたか確認してください。</div>
    <?php endif; ?>

    <div class="dynamic-field-grid">
      <?php $currentGroup = null; ?>
      <?php foreach ($rows as $i => $field): ?>
        <?php
          $group = (string)($field['field_group'] ?? 'other');
          if (!isset($fieldGroupLabels[$group])) { $group = 'other'; }
          if ($currentGroup !== $group):
            $currentGroup = $group;
        ?>
          <h3 class="dynamic-field-group"><?= h($fieldGroupLabels[$group]) ?></h3>
        <?php endif; ?>

        <?php
          $isSelected = !empty($field['is_selected']);
          $fieldValue = (string)($field['field_value'] ?? '');
          $aiValue = (string)($field['source_ai_value'] ?? '');
          $needs = !empty($field['needs_human_check']) || ($aiValue !== '' && $fieldValue !== '' && $aiValue !== $fieldValue);
        ?>
        <div class="dynamic-field-row learning-field-row <?= $needs ? 'needs-check' : '' ?>">
          <label class="field-use-check">
            <input type="checkbox" name="dynamic_field_selected[<?= $i ?>]" value="1" <?= $isSelected ? 'checked' : '' ?>>
            <span>使う</span>
          </label>

          <div class="field-main">
            <label>
              <span class="field-label"><?= h((string)$field['field_label']) ?></span>
              <input name="dynamic_field_value[<?= $i ?>]" value="<?= h($fieldValue) ?>" placeholder="空欄" readonly>
            </label>
            <div class="field-meta">
              <span>DB保存済み</span>
              <span>ひな型候補</span>
              <?php if (!empty($field['source_section'])): ?><span><?= h((string)$field['source_section']) ?></span><?php endif; ?>
              <?php if ($fieldValue === ''): ?><span class="attention">空欄</span><?php elseif ($needs): ?><span class="attention">要確認</span><?php endif; ?>
            </div>
          </div>

          <input type="hidden" name="dynamic_field_id[<?= $i ?>]" value="<?= h((string)($field['id'] ?? '')) ?>">
          <input type="hidden" name="dynamic_field_key[<?= $i ?>]" value="<?= h((string)$field['field_key']) ?>">
          <input type="hidden" name="dynamic_field_label[<?= $i ?>]" value="<?= h((string)$field['field_label']) ?>">
          <input type="hidden" name="dynamic_field_group[<?= $i ?>]" value="<?= h($group) ?>">
          <input type="hidden" name="dynamic_field_ai_value[<?= $i ?>]" value="<?= h($aiValue) ?>">
          <input type="hidden" name="dynamic_field_output_candidate[<?= $i ?>]" value="1">
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="actions sticky-save-actions">
    <a class="btn ghost" href="<?= h(app_url('/prescription_saved.php?id=' . (string)$prescriptionId)) ?>">保存内容確認へ</a>
    <button class="btn primary" type="submit">使用項目を保存</button>
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
