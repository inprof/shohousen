<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $saved = get_prescription((int)$user['tenant_id'], $id);
    if (!$saved) { http_response_code(404); exit('データが見つかりません'); }
    $data = [
        'patient_name' => $saved['patient_name'],
        'gender' => $saved['gender'] === 'female' ? '女性' : ($saved['gender'] === 'male' ? '男性' : '不明'),
        'birth_date' => $saved['birth_date'],
        'insurance_no' => $saved['insurance_no'],
        'insured_symbol_number' => $saved['insured_symbol_number'],
        'copay_rate' => $saved['copay_rate'],
        'issued_on' => $saved['issued_on'],
        'medical_institution_code' => $saved['institution_code'],
        'medical_institution_name' => $saved['medical_name'],
        'ai_confidence' => $saved['ai_confidence'],
        'medications' => $saved['medications'],
    ];
} else {
    $data = demo_extracted_prescription();
}
$drugOptions = ['アムロジピンOD錠5mg', 'ムコソルバン錠15mg', 'ムコソルバンL錠45mg', 'ムコソルバンシロップ1.5%', 'ロキソニン錠60mg', 'カロナール錠500mg'];
View::header('修正画面');
?>
<section class="page-title"><h1>修正画面</h1><p>内容を修正して保存してください。薬品名は候補から選択できます。</p></section>
<form class="card result-card" method="post" action="<?= h(app_url('/prescription_save.php')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="ai_confidence" value="<?= h((string)$data['ai_confidence']) ?>">
  <div class="form-grid two">
    <label>患者名<input name="patient_name" value="<?= h($data['patient_name']) ?>" required></label>
    <label>性別<select name="gender"><option <?= $data['gender']==='女性'?'selected':'' ?>>女性</option><option <?= $data['gender']==='男性'?'selected':'' ?>>男性</option><option>不明</option></select></label>
    <label>生年月日<input type="date" name="birth_date" value="<?= h($data['birth_date']) ?>"></label>
    <label>保険者番号<input name="insurance_no" value="<?= h($data['insurance_no']) ?>"></label>
    <label>記号番号<input name="insured_symbol_number" value="<?= h($data['insured_symbol_number']) ?>"></label>
    <label>負担割合<input name="copay_rate" value="<?= h($data['copay_rate']) ?>"></label>
    <label>処方箋発行日<input type="date" name="issued_on" value="<?= h($data['issued_on']) ?>"></label>
    <label>医療機関コード<input name="medical_institution_code" value="<?= h($data['medical_institution_code']) ?>"></label>
    <label class="span2">医療機関名<input name="medical_institution_name" value="<?= h($data['medical_institution_name']) ?>"></label>
  </div>
  <h2>処方薬情報（修正してください）</h2>
  <div class="edit-med-list">
    <?php foreach ($data['medications'] as $i => $med): ?>
      <div class="edit-med-row">
        <span class="row-no"><?= $i + 1 ?></span>
        <label>薬品名
          <select name="drug_name[]">
            <?php foreach ($drugOptions as $option): ?>
              <option value="<?= h($option) ?>" <?= ($med['drug_name'] ?? '') === $option ? 'selected' : '' ?>><?= h($option) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>用法<input name="usage_text[]" value="<?= h($med['usage_text'] ?? '') ?>"></label>
        <label>日数<input type="number" name="days_count[]" value="<?= h((string)($med['days_count'] ?? '')) ?>"></label>
        <label>在庫
          <select name="stock_status[]">
            <?php foreach (['adopted'=>'採用薬','in_stock'=>'在庫あり','low_stock'=>'在庫僅少','not_stocked'=>'未採用','unknown'=>'未確認'] as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= ($med['stock_status'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="legend"><span class="dot adopted"></span>採用薬 <span class="dot stock"></span>在庫あり <span class="dot low"></span>在庫僅少</div>
  <div class="button-row end"><a class="btn ghost" href="<?= h(app_url('/prescription_scan.php')) ?>">キャンセル</a><button class="btn primary" type="submit">保存する</button></div>
</form>
<?php View::footer(); ?>
