<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $user = Auth::login(trim((string)($_POST['email'] ?? '')), (string)($_POST['password'] ?? ''), 'pharmacy_user');
    if ($user) {
        Auth::completeLogin($user);
        redirect('/menu.php');
    }
    $error = 'メールアドレスまたはパスワードが違います。';
}
View::header('ユーザーログイン', ['body_class' => 'auth-page']);
?>
<section class="auth-wrap">
  <form class="card auth-card" method="post">
    <?= Csrf::field() ?>
    <h1>ユーザーログイン</h1>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <label>メールアドレス<input type="email" name="email" value="demo.user@pharma.local" required></label>
    <label>パスワード<input type="password" name="password" value="demo1234" required></label>
    <label class="check-row"><input type="checkbox" checked>ログイン状態を保持する</label>
    <button class="btn primary wide" type="submit">ログイン</button>
    <a class="text-link" href="<?= h(app_url('/admin_login.php')) ?>">管理者ログインはこちら</a>
    <div class="notice"><strong>セキュリティについて</strong><br>業務利用では、必要に応じてOTP連携を追加してください。</div>
  </form>
</section>
<?php View::footer(); ?>
