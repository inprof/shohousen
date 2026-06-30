<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $user = Auth::login(trim((string)($_POST['email'] ?? '')), (string)($_POST['password'] ?? ''), 'pharmacy_user');
    if ($user) {
        Auth::completeLogin($user);
        redirect('/branch_select.php');
    }
    $error = 'メールアドレスまたはパスワードが違います。';
}
View::header('会社ログイン', ['body_class' => 'auth-page']);
?>
<section class="auth-wrap">
  <form class="card auth-card" method="post">
    <?= Csrf::field() ?>
    <h1>会社ログイン</h1>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <label>メールアドレス<input type="email" name="email" value="demo.user@pharma.local" required></label>
    <label>パスワード<input type="password" name="password" value="demo1234" required></label>
    <button class="btn primary wide" type="submit">ログインして拠点選択へ</button>
    <a class="text-link" href="<?= h(app_url('/admin_login.php')) ?>">会社管理者ログインはこちら</a>
    <div class="notice"><strong>SaaS接続</strong><br>ログイン後、管理者DBの割当情報から会社DB/拠点DBを解決します。</div>
  </form>
</section>
<?php View::footer(); ?>
