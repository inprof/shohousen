<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$filters = [
    'from' => $_GET['from'] ?? '2024-05-01',
    'to' => $_GET['to'] ?? '2024-05-31',
    'patient_name' => $_GET['patient_name'] ?? '',
    'institution_code' => $_GET['institution_code'] ?? '',
];
$rows = list_prescriptions((int)$user['tenant_id'], $filters);
View::header('受付データ一覧');
?>
<section class="page-title"><h1>受付データ一覧</h1><p>受付日付・患者名・医療機関コードで絞り込みできます。</p></section>
<form class="card search-panel" method="get">
  <label>受付日<input type="date" name="from" value="<?= h($filters['from']) ?>"></label>
  <span>〜</span>
  <label><span class="sr-only">終了日</span><input type="date" name="to" value="<?= h($filters['to']) ?>"></label>
  <label>患者名<input name="patient_name" value="<?= h($filters['patient_name']) ?>" placeholder="例）山田 花子"></label>
  <label>医療機関コード<input name="institution_code" value="<?= h($filters['institution_code']) ?>" placeholder="例）1312345"></label>
  <button class="btn primary" type="submit">検索</button>
  <a class="btn ghost" href="<?= h(app_url('/receptions.php')) ?>">クリア</a>
</form>
<div class="card table-card">
  <table class="data-table">
    <thead><tr><th>受付日時</th><th>患者名</th><th>処方箋発行日</th><th>医療機関</th><th>状態</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= h(substr($row['received_at'], 0, 16)) ?></td>
        <td><?= h($row['patient_name']) ?></td>
        <td><?= h($row['issued_on'] ?? '') ?></td>
        <td><?= h(($row['medical_name'] ?? '-') . '（' . ($row['institution_code'] ?? '-') . '）') ?></td>
        <td><span class="status <?= h($row['status']) ?>"><?= $row['status'] === 'completed' ? '完了' : '修正中' ?></span></td>
        <td><a class="detail-link" href="<?= h(app_url('/reception_detail.php?id=' . (string)$row['id'])) ?>">›</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php View::footer(); ?>
