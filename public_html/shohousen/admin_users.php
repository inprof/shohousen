<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireAdmin();
$stmt = Db::pdo()->prepare('SELECT id, role, name, email, status, otp_enabled, last_login_at FROM users WHERE tenant_id = :tenant_id ORDER BY id');
$stmt->execute([':tenant_id' => $user['tenant_id']]);
$users = $stmt->fetchAll();
View::header('ユーザー管理');
?>
<section class="page-title with-action">
  <div><h1>ユーザー管理</h1><p>デモでは一覧表示のみです。本番では作成・修正・削除フォームを追加します。</p></div>
  <button class="btn primary" type="button">ユーザー作成</button>
</section>
<div class="card table-card">
  <table class="data-table">
    <thead><tr><th>ID</th><th>氏名</th><th>メール</th><th>権限</th><th>OTP</th><th>状態</th><th>最終ログイン</th></tr></thead>
    <tbody>
      <?php foreach ($users as $row): ?>
        <tr>
          <td><?= h((string)$row['id']) ?></td><td><?= h($row['name']) ?></td><td><?= h($row['email']) ?></td><td><?= h($row['role']) ?></td>
          <td><?= $row['otp_enabled'] ? '有効' : '無効' ?></td><td><?= h($row['status']) ?></td><td><?= h($row['last_login_at'] ?? '-') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php View::footer(); ?>
