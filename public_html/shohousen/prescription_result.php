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
$knowledgeService = new PrescriptionKnowledgeService();
// private側の反映漏れや古いキャッシュがあっても解析結果画面をFatalで止めない。
// branchFieldPreferenceMap() は補助学習DBから拠点ごとの初期チェック状態を取得するだけなので、
// 未反映時は空配列として扱い、画面表示と保存処理を優先する。
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
    'other' => 'その他',
];
$fieldGroupOrder = array_keys($fieldGroupLabels);
usort($dynamicFields, static function (array $a, array $b) use ($fieldGroupOrder): int {
    $ga = array_search((string)($a['field_group'] ?? 'other'), $fieldGroupOrder, true);
    $gb = array_search((string)($b['field_group'] ?? 'other'), $fieldGroupOrder, true);
    $ga = $ga === false ? 999 : $ga;
    $gb = $gb === false ? 999 : $gb;
    if ($ga === $gb) {
        return strcmp((string)($a['field_label'] ?? ''), (string)($b['field_label'] ?? ''));
    }
    return $ga <=> $gb;
});
View::header('解析結果確認');
?>
<section class="page-title"><h1>解析結果確認</h1><p>AI解析結果と補正候補を確認し、必要に応じて修正してから確定保存してください。QRは保存完了後に作成します。</p></section>
<?php if (!empty($data['warnings'])): ?>
  <div class="alert info"><strong>解析メモ</strong><br><?= h(implode(' / ', array_map('strval', $data['warnings']))) ?></div>
<?php endif; ?>
<?php if ($jobId > 0): ?>
  <section class="card ocr-source-preview">
    <div>
      <h2>撮影画像</h2>
      <p>画像は画面内に収まる大きさで表示します。元画像が大きい場合でも、確認ボタンが隠れにくいように調整しています。</p>
    </div>
    <a class="btn ghost" href="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" target="_blank" rel="noopener">画像を別画面で開く</a>
    <img src="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" alt="撮影した処方箋画像" loading="lazy">
  </section>
<?php endif; ?>
<form class="card result-card" method="post" action="<?= h(app_url('/prescription_save.php')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="parse_job_id" value="<?= h((string)$jobId) ?>">
  <input type="hidden" name="ai_confidence" value="<?= h((string)($data['overall_confidence'] ?? '')) ?>">
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
        <p>医療情報のため、補正候補は自動確定せず、人間確認後に保存します。</p>
      </div>
    </section>
  </div>
  <h2>処方薬情報（<?= count($medications) ?>件）</h2>
  <div class="edit-med-list">
    <?php foreach ($medications as $i => $med): $drugCandidates = $candidates['medications'][$i]['drug_name'] ?? []; ?>
      <div class="edit-med-row ocr-med-row">
        <span class="row-no"><?= $i + 1 ?></span>
        <label>薬品名
          <input name="drug_name[]" value="<?= h((string)($med['drug_name'] ?? '')) ?>" list="drugCandidates<?= $i ?>">
          <?php if ($drugCandidates): ?>
            <datalist id="drugCandidates<?= $i ?>">
              <?php foreach ($drugCandidates as $candidate): ?><option value="<?= h((string)$candidate['candidate_value']) ?>"><?php endforeach; ?>
            </datalist>
            <small class="candidate-note">候補: <?= h(implode(' / ', array_map(fn($c) => $c['candidate_value'] . '（' . $c['score'] . '）', $drugCandidates))) ?></small>
          <?php endif; ?>
        </label>
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
      </div>
    <?php endforeach; ?>
  </div>
  <section class="dynamic-field-card">
    <div class="dynamic-field-head">
      <div>
        <h2>読み取り項目の選択</h2>
        <p>画像内でAIが読み取った項目をすべて表示します。QRや後続出力に使う項目だけチェックを入れてください。未チェックでも確認画面上では残るため、不要項目の判断履歴として補助学習DBに反映されます。</p>
      </div>
      <div class="field-actions">
        <button class="btn ghost small" type="button" data-field-check="all">全選択</button>
        <button class="btn ghost small" type="button" data-field-check="none">全解除</button>
      </div>
    </div>

    <?php if (!$dynamicFields): ?>
      <div class="alert warning">AIが動的項目を返していません。固定項目だけを保存します。プロンプトまたはOpenAI応答を確認してください。</div>
    <?php else: ?>
      <div class="dynamic-field-grid">
        <?php $currentGroup = null; ?>
        <?php foreach ($dynamicFields as $i => $field): ?>
          <?php
            $group = (string)($field['field_group'] ?? 'other');
            if (!isset($fieldGroupLabels[$group])) { $group = 'other'; }
            $key = (string)($field['field_key'] ?? ('field_' . $i));
            $label = (string)($field['field_label'] ?? $key);
            $value = (string)($field['value'] ?? '');
            $confidence = is_numeric($field['confidence'] ?? null) ? (float)$field['confidence'] : null;
            $includeDefault = array_key_exists($key, $fieldPreferences) ? (bool)$fieldPreferences[$key] : (bool)($field['include_default'] ?? false);
            if ($currentGroup !== $group):
              $currentGroup = $group;
          ?>
            <h3 class="dynamic-field-group"><?= h($fieldGroupLabels[$group]) ?></h3>
          <?php endif; ?>

          <div class="dynamic-field-row <?= !empty($field['needs_human_check']) ? 'needs-check' : '' ?>">
            <label class="field-use-check">
              <input type="checkbox" name="dynamic_field_selected[<?= $i ?>]" value="1" <?= $includeDefault ? 'checked' : '' ?>>
              <span>使う</span>
            </label>

            <div class="field-main">
              <label>
                <span class="field-label"><?= h($label) ?></span>
                <input name="dynamic_field_value[<?= $i ?>]" value="<?= h($value) ?>" placeholder="空欄">
              </label>
              <div class="field-meta">
                <span><?= h((string)($field['source_section'] ?? '')) ?></span>
                <?php if ($confidence !== null): ?><span>信頼度 <?= h((string)round($confidence, 1)) ?>%</span><?php endif; ?>
                <?php if (!empty($field['needs_human_check'])): ?><span class="attention">要確認</span><?php endif; ?>
              </div>
            </div>

            <input type="hidden" name="dynamic_field_key[<?= $i ?>]" value="<?= h($key) ?>">
            <input type="hidden" name="dynamic_field_label[<?= $i ?>]" value="<?= h($label) ?>">
            <input type="hidden" name="dynamic_field_group[<?= $i ?>]" value="<?= h($group) ?>">
            <input type="hidden" name="dynamic_field_ai_value[<?= $i ?>]" value="<?= h($value) ?>">
            <input type="hidden" name="dynamic_field_source_section[<?= $i ?>]" value="<?= h((string)($field['source_section'] ?? '')) ?>">
            <input type="hidden" name="dynamic_field_confidence[<?= $i ?>]" value="<?= h((string)($confidence ?? '')) ?>">
            <input type="hidden" name="dynamic_field_needs_human_check[<?= $i ?>]" value="<?= !empty($field['needs_human_check']) ? '1' : '0' ?>">
            <input type="hidden" name="dynamic_field_output_candidate[<?= $i ?>]" value="<?= !empty($field['output_candidate']) ? '1' : '0' ?>">
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div class="button-row end sticky-save-actions">
    <a class="btn ghost" href="<?= h(app_url('/prescription_scan.php')) ?>">再撮影</a>
    <button class="btn primary" type="submit">選択項目を含めてDB保存</button>
  </div>
</form>
<script>
document.addEventListener('click', function (event) {
  var target = event.target;
  if (!(target instanceof HTMLElement)) return;
  var mode = target.getAttribute('data-field-check');
  if (!mode) return;
  document.querySelectorAll('.dynamic-field-row input[type="checkbox"]').forEach(function (checkbox) {
    checkbox.checked = mode === 'all';
  });
});
</script>
<?php View::footer(); ?>
