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
