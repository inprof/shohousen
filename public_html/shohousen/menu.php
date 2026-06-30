<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$features = enabled_features((int)$user['tenant_id'], current_location_id());
View::header('メニュー');
?>
<section class="user-summary card">
  <div class="avatar">👤</div>
  <div><h1><?= h($user['name']) ?> 様</h1><p><?= h(($user['company_name'] ?? $user['tenant_name'] ?? '') . ' / ' . ($_SESSION['branch_name'] ?? '')) ?></p></div>
  <a class="btn ghost" href="<?= h(app_url('/branch_select.php')) ?>">拠点切替</a>
</section>
<section class="page-title"><h2>機能メニュー</h2><p>選択中拠点のDBに接続しています。</p></section>
<div class="menu-grid">
  <?php foreach ($features as $feature): ?>
    <a class="feature-card <?= $feature['route_path'] === '#' ? 'disabled' : '' ?>" href="<?= h($feature['route_path']) ?>">
      <span><?= h($feature['icon'] ?: '□') ?></span>
      <strong><?= h($feature['name']) ?></strong>
      <em><?= h($feature['description'] ?? '') ?></em>
    </a>
  <?php endforeach; ?>
</div>
<nav class="bottom-actions card">
  <a href="<?= h(app_url('/branch_select.php')) ?>">拠点切替</a><a href="#">ヘルプ</a><a href="<?= h(app_url('/receptions.php')) ?>">検索</a><a href="<?= h(app_url('/logout.php')) ?>">ログアウト</a>
</nav>
<?php View::footer(); ?>
