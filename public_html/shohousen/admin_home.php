<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireAdmin();
View::header('会社管理者ホーム');
?>
<section class="page-title">
  <h1>会社管理者ページ</h1>
  <p><?= h($user['company_name'] ?? $user['tenant_name'] ?? '') ?> のユーザー・拠点・補助学習データを管理します。</p>
</section>
<div class="menu-grid admin-grid">
  <a class="feature-card" href="<?= h(app_url('/admin_users.php')) ?>"><span>👥</span><strong>ユーザー管理</strong><em>管理者DBの会社ユーザーを確認</em></a>
  <a class="feature-card" href="<?= h(app_url('/branch_select.php')) ?>"><span>🏥</span><strong>拠点切替</strong><em>管理者DBの拠点DB割当を確認</em></a>
  <a class="feature-card disabled" href="#"><span>🧠</span><strong>補助学習データ管理</strong><em>テンプレート/補正ルール管理画面は次段階で追加</em></a>
  <a class="feature-card disabled" href="#"><span>📜</span><strong>監査ログ</strong><em>操作履歴の確認</em></a>
</div>
<?php View::footer(); ?>
