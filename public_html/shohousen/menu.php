<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireLogin();
$features = enabled_features((int)$user['tenant_id']);
View::header('メニュー');
?>
<section class="user-summary card">
  <div class="avatar">👤</div>
  <div><h1><?= h($user['name']) ?> 様</h1><p><?= h($user['tenant_name']) ?></p></div>
  <button class="icon-btn" aria-label="通知">🔔</button>
</section>
<section class="page-title"><h2>機能メニュー</h2><p>契約済みの機能のみ表示します。</p></section>
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
  <a href="#">ユーザー情報</a><a href="#">ヘルプ</a><a href="<?= h(app_url('/receptions.php')) ?>">検索</a><a href="<?= h(app_url('/logout.php')) ?>">ログアウト</a>
</nav>
<?php View::footer(); ?>
