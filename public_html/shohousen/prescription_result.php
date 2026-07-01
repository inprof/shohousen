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
  <div class="button-row end sticky-save-actions">
    <a class="btn ghost" href="<?= h(app_url('/prescription_scan.php')) ?>">再撮影</a>
    <button class="btn primary" type="submit">確定してDB保存</button>
  </div>
</form>
<?php View::footer(); ?>
