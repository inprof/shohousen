<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$id = (int)($_GET['id'] ?? 0);
$prescription = $id ? get_prescription((int)$user['tenant_id'], $id) : null;
if (!$prescription) {
    http_response_code(404);
    exit('保存済みデータが見つかりません');
}
View::header('確定保存完了');
?>
<section class="page-title"><h1>確定保存完了</h1><p>修正後の読み取り内容をDBに保存しました。内容を確認してからQR作成へ進んでください。</p></section>
<section class="card saved-confirm-card">
  <div class="saved-status">
    <span class="saved-status-icon">✓</span>
    <div>
      <h2>DB保存済み</h2>
      <p>受付番号：<strong><?= h((string)$prescription['reception_no']) ?></strong></p>
      <p>保存ID：<?= h((string)$prescription['id']) ?> / 患者名：<?= h((string)$prescription['patient_name']) ?></p>
    </div>
  </div>

  <div class="info-table saved-summary">
    <div><span>患者名</span><strong><?= h((string)$prescription['patient_name']) ?></strong></div>
    <div><span>生年月日</span><strong><?= h((string)($prescription['birth_date'] ?? '')) ?></strong></div>
    <div><span>医療機関</span><strong><?= h((string)($prescription['medical_name'] ?? '')) ?></strong></div>
    <div><span>処方箋発行日</span><strong><?= h((string)($prescription['issued_on'] ?? '')) ?></strong></div>
  </div>

  <h2>保存された処方薬</h2>
  <div class="table-card saved-meds-table">
    <table class="data-table compact">
      <thead><tr><th>#</th><th>薬品名</th><th>一般名</th><th>商品名</th><th>用量</th><th>用法</th><th>日数</th><th>総量/備考</th></tr></thead>
      <tbody>
      <?php foreach (($prescription['medications'] ?? []) as $med): ?>
        <tr>
          <td><?= h((string)$med['sort_order']) ?></td>
          <td><?= h((string)$med['drug_name']) ?></td>
          <td><?= h((string)($med['generic_name'] ?? '')) ?></td>
          <td><?= h((string)($med['brand_name'] ?? '')) ?></td>
          <td><?= h((string)($med['dose_text'] ?? '')) ?></td>
          <td><?= h((string)($med['usage_text'] ?? '')) ?></td>
          <td><?= h((string)($med['days_count'] ?? '')) ?></td>
          <td><?= h((string)($med['amount_text'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($prescription['selected_fields'])): ?>
    <h2>保存された読み取り項目</h2>
    <div class="table-card saved-fields-table">
      <table class="data-table compact">
        <thead><tr><th>使用</th><th>項目</th><th>値</th><th>区分</th><th>信頼度</th></tr></thead>
        <tbody>
        <?php foreach (($prescription['selected_fields'] ?? []) as $field): ?>
          <tr class="<?= !empty($field['include_for_output']) ? '' : 'muted-row' ?>">
            <td><?= !empty($field['include_for_output']) ? '使用' : '未使用' ?></td>
            <td><?= h((string)$field['field_label']) ?></td>
            <td><?= h((string)($field['field_value'] ?? '')) ?></td>
            <td><?= h((string)$field['field_group']) ?></td>
            <td><?= ($field['confidence'] ?? '') !== '' ? '確認済み' : '未評価' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php $ruleChecks = (array)($prescription['rule_checks'] ?? []); $ruleSummary = PrescriptionRuleEngineService::summarize($ruleChecks); ?>
  <?php if ($ruleChecks): ?>
    <h2>処方箋受付ルール判定</h2>
    <div class="rule-summary saved-rule-summary">
      <span class="rule-badge danger">重要 <?= h((string)($ruleSummary['block'] + $ruleSummary['danger'])) ?></span>
      <span class="rule-badge warning">確認 <?= h((string)$ruleSummary['warning']) ?></span>
      <span class="rule-badge info">参考 <?= h((string)$ruleSummary['info']) ?></span>
      <?php if ($ruleSummary['requires_inquiry'] > 0): ?><span class="rule-badge inquiry">疑義照会候補 <?= h((string)$ruleSummary['requires_inquiry']) ?></span><?php endif; ?>
    </div>
    <div class="rule-check-list compact">
      <?php foreach ($ruleChecks as $check): ?>
        <?php $sev = (string)($check['severity'] ?? 'info'); ?>
        <article class="rule-check-item <?= h($sev) ?>">
          <strong><?= h((string)($check['title'] ?? '確認項目')) ?></strong>
          <p><?= h((string)($check['message'] ?? '')) ?></p>
          <div class="rule-check-meta">
            <?php if (!empty($check['recommended_action'])): ?><span>対応: <?= h((string)$check['recommended_action']) ?></span><?php endif; ?>
            <?php if (!empty($check['requires_inquiry'])): ?><span class="attention">疑義照会候補</span><?php endif; ?>
            <?php if (!empty($check['blocks_qr'])): ?><span class="attention">QR前確認</span><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="alert info saved-qr-note">
    QRはまだ作成していません。下の「QR作成へ進む」を押すと、保存済みDBデータと「使用」にした読み取り項目からQR用中間データを生成します。重要判定がある場合はQR作成前に内容を確認してください。
  </div>

  <div class="button-row end sticky-save-actions">
    <a class="btn ghost" href="<?= h(app_url('/reception_detail.php?id=' . (string)$id)) ?>">保存内容を詳細確認</a>
    <a class="btn ghost" href="<?= h(app_url('/prescription_io_debug.php?id=' . (string)$id)) ?>">IO診断を見る</a>
    <a class="btn primary" href="<?= h(app_url('/qr.php?id=' . (string)$id)) ?>">QR作成へ進む</a>
  </div>
</section>
<?php View::footer(); ?>
