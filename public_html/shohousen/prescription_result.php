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
$medications = $data['medications'] ?? [];
$dynamicFields = is_array($data['form_fields'] ?? null) ? $data['form_fields'] : [];
if (!$medications) {
    $medications = [['drug_name' => '', 'usage_text' => '', 'days_count' => '', 'amount_text' => '']];
}
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
    'other' => 'その他',
];
View::header('解析結果確認');
?>
<section class="page-title">
  <h1>解析結果確認</h1>
  <p>AIが読み取った結果を人間が修正します。ここではまだDB保存しません。次の画面で「どの項目を使うか」を選択してから確定保存します。</p>
</section>
<?php if (!empty($data['warnings'])): ?>
  <div class="alert info"><strong>解析メモ</strong><br><?= h(implode(' / ', array_map('strval', $data['warnings']))) ?></div>
<?php endif; ?>
<?php if ($jobId > 0): ?>
  <section class="card ocr-source-preview">
    <div>
      <h2>撮影画像</h2>
      <p>元画像は別画面で確認できます。画面内の入力欄を優先するため、ここでは縮小表示しています。</p>
    </div>
    <a class="btn ghost" href="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" target="_blank" rel="noopener">画像を別画面で開く</a>
    <img src="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" alt="撮影した処方箋画像" loading="lazy">
  </section>
<?php endif; ?>
<form class="card result-card" method="post" action="<?= h(app_url('/prescription_field_select.php')) ?>" id="prescriptionConfirmForm">
  <?= Csrf::field() ?>
  <input type="hidden" name="parse_job_id" value="<?= h((string)$jobId) ?>">
  <input type="hidden" name="ai_confidence" value="<?= h((string)($data['overall_confidence'] ?? '')) ?>">

  <input type="hidden" name="ai_patient_name" value="<?= h((string)($patient['name'] ?? '')) ?>">
  <input type="hidden" name="ai_gender" value="<?= h((string)($patient['gender'] ?? '')) ?>">
  <input type="hidden" name="ai_birth_date" value="<?= h((string)($patient['birth_date'] ?? '')) ?>">
  <input type="hidden" name="ai_insurance_no" value="<?= h((string)($insurance['insurance_no'] ?? '')) ?>">
  <input type="hidden" name="ai_insured_symbol_number" value="<?= h((string)($insurance['insured_symbol_number'] ?? '')) ?>">
  <input type="hidden" name="ai_copay_rate" value="<?= h((string)($insurance['copay_rate'] ?? '')) ?>">
  <input type="hidden" name="ai_issued_on" value="<?= h((string)($prescription['issued_on'] ?? '')) ?>">
  <input type="hidden" name="ai_medical_institution_code" value="<?= h((string)($medical['code'] ?? '')) ?>">
  <input type="hidden" name="ai_medical_institution_name" value="<?= h((string)($medical['name'] ?? '')) ?>">

  <?php foreach ($dynamicFields as $i => $field): ?>
    <input type="hidden" name="original_dynamic_key[]" value="<?= h((string)($field['field_key'] ?? ('field_' . $i))) ?>">
    <input type="hidden" name="original_dynamic_label[]" value="<?= h((string)($field['field_label'] ?? ($field['field_key'] ?? ('field_' . $i)))) ?>">
    <input type="hidden" name="original_dynamic_group[]" value="<?= h((string)($field['field_group'] ?? 'other')) ?>">
    <input type="hidden" name="original_dynamic_value[]" value="<?= h((string)($field['value'] ?? '')) ?>">
    <input type="hidden" name="original_dynamic_source_section[]" value="<?= h((string)($field['source_section'] ?? '')) ?>">
    <input type="hidden" name="original_dynamic_confidence[]" value="<?= h((string)($field['confidence'] ?? '')) ?>">
    <input type="hidden" name="original_dynamic_needs_human_check[]" value="<?= !empty($field['needs_human_check']) ? '1' : '0' ?>">
    <input type="hidden" name="original_dynamic_include_default[]" value="<?= !empty($field['include_default']) ? '1' : '0' ?>">
  <?php endforeach; ?>

  <div class="confirm-flow-note">
    <span class="flow-step active">1. 読み取り結果を修正</span>
    <span class="flow-step">2. 使用項目を選択</span>
    <span class="flow-step">3. DB保存</span>
    <span class="flow-step">4. QR作成</span>
  </div>

  <div class="info-columns">
    <section>
      <h2>患者情報</h2>
      <div class="definition-list">
        <label>患者名<input name="patient_name" value="<?= h((string)($patient['name'] ?? '')) ?>" required></label>
        <label>性別<select name="gender"><option <?= ($patient['gender'] ?? '')==='女性'?'selected':'' ?>>女性</option><option <?= ($patient['gender'] ?? '')==='男性'?'selected':'' ?>>男性</option><option <?= !in_array(($patient['gender'] ?? ''), ['女性','男性'], true)?'selected':'' ?>>不明</option></select></label>
        <label>生年月日<input type="date" name="birth_date" value="<?= h((string)($patient['birth_date'] ?? '')) ?>"></label>
      </div>
    </section>
    <section>
      <h2>保険情報</h2>
      <div class="definition-list">
        <label>保険者番号<input name="insurance_no" value="<?= h((string)($insurance['insurance_no'] ?? '')) ?>"></label>
        <label>記号番号<input name="insured_symbol_number" value="<?= h((string)($insurance['insured_symbol_number'] ?? '')) ?>"></label>
        <label>負担割合<input name="copay_rate" value="<?= h((string)($insurance['copay_rate'] ?? '')) ?>"></label>
      </div>
    </section>
  </div>
  <div class="info-columns">
    <section>
      <h2>処方箋情報</h2>
      <div class="definition-list">
        <label>処方箋発行日<input type="date" name="issued_on" value="<?= h((string)($prescription['issued_on'] ?? '')) ?>"></label>
        <label>医療機関コード<input name="medical_institution_code" value="<?= h((string)($medical['code'] ?? '')) ?>"></label>
        <label>医療機関名<input name="medical_institution_name" value="<?= h((string)($medical['name'] ?? '')) ?>"></label>
      </div>
    </section>
    <section>
      <h2>確認方針</h2>
      <div class="definition-list check-note">
        <p>AI信頼度：<?= h((string)($data['overall_confidence'] ?? '')) ?>%</p>
        <p>薬品が1行にまとまった場合や、一般名・商品名が別薬として分かれた場合は、この画面で行を追加・削除してから次へ進みます。</p>
      </div>
    </section>
  </div>

  <div class="section-title-row">
    <h2>処方薬情報（<span id="medCount"><?= count($medications) ?></span>件）</h2>
    <button class="btn ghost small" type="button" id="addMedicationRow">薬品行を追加</button>
  </div>
  <div class="edit-med-list" id="medicationList">
    <?php foreach ($medications as $i => $med): $drugCandidates = $candidates['medications'][$i]['drug_name'] ?? []; ?>
      <div class="edit-med-row ocr-med-row" data-med-row>
        <div class="row-no" data-row-no><?= $i + 1 ?></div>
        <label>薬品名
          <input name="drug_name[]" value="<?= h((string)($med['drug_name'] ?? '')) ?>" list="drugCandidates<?= $i ?>">
          <input type="hidden" name="ai_drug_name[]" value="<?= h((string)($med['drug_name'] ?? '')) ?>">
          <?php if ($drugCandidates): ?>
            <datalist id="drugCandidates<?= $i ?>">
              <?php foreach ($drugCandidates as $candidate): ?><option value="<?= h((string)$candidate['candidate_value']) ?>"><?php endforeach; ?>
            </datalist>
            <small class="candidate-note">候補: <?= h(implode(' / ', array_map(fn($c) => $c['candidate_value'] . '（' . $c['score'] . '）', $drugCandidates))) ?></small>
          <?php endif; ?>
        </label>
        <label>用法
          <input name="usage_text[]" value="<?= h((string)($med['usage_text'] ?? '')) ?>">
          <input type="hidden" name="ai_usage_text[]" value="<?= h((string)($med['usage_text'] ?? '')) ?>">
        </label>
        <label>日数
          <input type="number" name="days_count[]" value="<?= h((string)($med['days_count'] ?? '')) ?>">
          <input type="hidden" name="ai_days_count[]" value="<?= h((string)($med['days_count'] ?? '')) ?>">
        </label>
        <label>総量/備考
          <input name="amount_text[]" value="<?= h((string)($med['amount_text'] ?? '')) ?>">
          <input type="hidden" name="ai_amount_text[]" value="<?= h((string)($med['amount_text'] ?? '')) ?>">
        </label>
        <label>在庫
          <select name="stock_status[]">
            <?php foreach (['adopted'=>'採用薬','in_stock'=>'在庫あり','low_stock'=>'在庫僅少','not_stocked'=>'未採用','unknown'=>'未確認'] as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $key === 'unknown' ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn danger ghost small med-remove" type="button" data-remove-med>この薬を削除</button>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="actions sticky-save-actions">
    <a class="btn ghost" href="<?= h(app_url('/prescription_scan.php')) ?>">撮り直す</a>
    <button class="btn primary" type="submit">使用項目の選択へ進む</button>
  </div>
</form>

<template id="medicationRowTemplate">
  <div class="edit-med-row ocr-med-row" data-med-row>
    <div class="row-no" data-row-no></div>
    <label>薬品名
      <input name="drug_name[]" value="">
      <input type="hidden" name="ai_drug_name[]" value="">
    </label>
    <label>用法
      <input name="usage_text[]" value="">
      <input type="hidden" name="ai_usage_text[]" value="">
    </label>
    <label>日数
      <input type="number" name="days_count[]" value="">
      <input type="hidden" name="ai_days_count[]" value="">
    </label>
    <label>総量/備考
      <input name="amount_text[]" value="">
      <input type="hidden" name="ai_amount_text[]" value="">
    </label>
    <label>在庫
      <select name="stock_status[]">
        <option value="adopted">採用薬</option>
        <option value="in_stock">在庫あり</option>
        <option value="low_stock">在庫僅少</option>
        <option value="not_stocked">未採用</option>
        <option value="unknown" selected>未確認</option>
      </select>
    </label>
    <button class="btn danger ghost small med-remove" type="button" data-remove-med>この薬を削除</button>
  </div>
</template>

<script>
(function () {
  const list = document.getElementById('medicationList');
  const template = document.getElementById('medicationRowTemplate');
  const addButton = document.getElementById('addMedicationRow');
  const count = document.getElementById('medCount');

  function refreshNumbers() {
    const rows = Array.from(list.querySelectorAll('[data-med-row]'));
    rows.forEach((row, index) => {
      const no = row.querySelector('[data-row-no]');
      if (no) no.textContent = String(index + 1);
    });
    if (count) count.textContent = String(rows.length);
  }

  addButton?.addEventListener('click', () => {
    const node = template.content.firstElementChild.cloneNode(true);
    list.appendChild(node);
    refreshNumbers();
    const first = node.querySelector('input[name="drug_name[]"]');
    if (first) first.focus();
  });

  list?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || !target.matches('[data-remove-med]')) return;
    const row = target.closest('[data-med-row]');
    if (!row) return;
    const rows = list.querySelectorAll('[data-med-row]');
    if (rows.length <= 1) {
      row.querySelectorAll('input').forEach((input) => input.value = '');
      row.querySelectorAll('select').forEach((select) => select.value = 'unknown');
    } else {
      row.remove();
    }
    refreshNumbers();
  });
})();
</script>
<?php View::footer(); ?>
