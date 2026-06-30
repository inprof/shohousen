<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireLogin();
$error = '';
$branches = Auth::availableBranches($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $branchUid = trim((string)($_POST['branch_uid'] ?? ''));
    if (Auth::selectBranch($user, $branchUid)) {
        redirect('/menu.php');
    }
    $error = '拠点DBの割当が見つからないか、選択できない拠点です。管理者DBの割当を確認してください。';
}

// 1拠点のみなら自動選択する。
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && count($branches) === 1) {
    if (Auth::selectBranch($user, (string)$branches[0]['branch_uid'])) {
        redirect('/menu.php');
    }
}

View::header('拠点選択');
?>
<section class="page-title">
  <h1>拠点選択</h1>
  <p><?= h($user['company_name'] ?? $user['tenant_name'] ?? '') ?> で利用する拠点を選択してください。</p>
</section>
<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
<div class="card table-card">
  <?php if (!$branches): ?>
    <p>このアカウントに利用可能な拠点が割り当てられていません。</p>
    <p>管理者DBの <code>user_locations</code>、<code>tenant_db_connections</code>、<code>tenant_db_pool</code> または <code>admin_branch_db_assignments</code> を確認してください。</p>
  <?php else: ?>
    <form method="post">
      <?= Csrf::field() ?>
      <table class="data-table">
        <thead><tr><th>拠点</th><th>権限</th><th>DB名</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($branches as $branch): ?>
          <tr>
            <td><?= h((string)$branch['branch_name']) ?><br><small><?= h((string)$branch['branch_uid']) ?></small></td>
            <td><?= h((string)($branch['role_at_location'] ?? '-')) ?></td>
            <td><?= h((string)($branch['branch_db_name'] ?? '未割当')) ?></td>
            <td><button class="btn primary" type="submit" name="branch_uid" value="<?= h((string)$branch['branch_uid']) ?>">この拠点で開始</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </form>
  <?php endif; ?>
</div>
<?php View::footer(); ?>
