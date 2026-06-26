<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireAdmin();
View::header('管理者ホーム');
?>
<section class="page-title">
  <h1>管理者ページ</h1>
  <p><?= h($user['tenant_name']) ?> の契約・ユーザーを管理します。</p>
</section>
<div class="menu-grid admin-grid">
  <a class="feature-card" href="<?= h(app_url('/admin_users.php')) ?>"><span>👥</span><strong>ユーザー管理</strong><em>作成 / パスワード生成 / 修正 / 削除 / OTP解除</em></a>
  <a class="feature-card disabled" href="#"><span>🧩</span><strong>契約機能管理</strong><em>契約済み機能の表示制御</em></a>
  <a class="feature-card disabled" href="#"><span>📜</span><strong>監査ログ</strong><em>操作履歴の確認</em></a>
</div>
<?php View::footer(); ?>
