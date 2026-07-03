<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    delete_prescription((int)$user['tenant_id'], (int)$user['id'], $id);
    redirect('/receptions.php');
}
$p = get_prescription((int)$user['tenant_id'], $id);
if (!$p) { http_response_code(404); exit('データが見つかりません'); }
View::header('受付データ詳細');
?>
<section class="page-title"><h1>受付データ詳細</h1><p>受付データ・患者情報・処方薬情報を確認できます。</p></section>
<div class="card result-card">
  <h2>受付情報</h2>
  <div class="info-table">
    <div><span>受付日時</span><strong><?= h(substr($p['received_at'], 0, 16)) ?></strong></div>
    <div><span>処方箋発行日</span><strong><?= h($p['issued_on'] ?? '') ?></strong></div>
    <div><span>医療機関</span><strong><?= h(($p['medical_name'] ?? '-') . '（' . ($p['institution_code'] ?? '-') . '）') ?></strong></div>
  </div>
  <h2>患者情報</h2>
  <div class="info-table">
    <div><span>患者名</span><strong><?= h($p['patient_name']) ?></strong></div>
    <div><span>性別</span><strong><?= h($p['gender']) ?></strong></div>
    <div><span>生年月日</span><strong><?= h($p['birth_date'] ?? '') ?></strong></div>
  </div>
  <?php $ruleChecks = (array)($p['rule_checks'] ?? []); $ruleSummary = PrescriptionRuleEngineService::summarize($ruleChecks); ?>
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

  <h2>処方薬情報</h2>
  <table class="data-table compact">
    <thead><tr><th>No</th><th>薬品名</th><th>用法</th><th>日数</th></tr></thead>
    <tbody><?php foreach ($p['medications'] as $med): ?><tr><td><?= h((string)$med['sort_order']) ?></td><td><?= h($med['drug_name']) ?></td><td><?= h($med['usage_text'] ?? '') ?></td><td><?= h((string)$med['days_count']) ?>日分</td></tr><?php endforeach; ?></tbody>
  </table>
  <form class="button-row end" method="post">
    <?= Csrf::field() ?>
    <a class="btn ghost" href="<?= h(app_url('/prescription_scan.php')) ?>">再読み込み</a>
    <a class="btn ghost" href="<?= h(app_url('/prescription_edit.php?id=' . (string)$id)) ?>">修正する</a>
    <button class="btn danger" type="submit" onclick="return confirm('削除しますか？')">削除する</button>
  </form>
</div>
<?php View::footer(); ?>
