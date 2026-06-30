<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$error = '';
$demoCode = '';
$step = $_GET['step'] ?? 'password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (($_POST['step'] ?? '') === 'password') {
        $user = Auth::login(trim((string)($_POST['email'] ?? '')), (string)($_POST['password'] ?? ''), 'company_admin');
        if ($user) {
            $_SESSION['pending_admin_user'] = $user;
            $demoCode = Auth::issueAdminCode((int)$user['id']);
            $step = 'code';
        } else {
            $error = '管理者IDまたはパスワードが違います。';
        }
    } else {
        $pending = $_SESSION['pending_admin_user'] ?? null;
        if ($pending && Auth::verifyAdminCode((int)$pending['id'], trim((string)($_POST['code'] ?? '')))) {
            Auth::completeLogin($pending);
            unset($_SESSION['pending_admin_user']);
            redirect('/branch_select.php');
        }
        $error = '認証コードが違うか、有効期限切れです。';
        $step = 'code';
    }
}
View::header('会社管理者ログイン', ['body_class' => 'auth-page']);
?>
<section class="auth-wrap">
  <form class="card auth-card" method="post">
    <?= Csrf::field() ?>
    <?php if ($step === 'password'): ?>
      <input type="hidden" name="step" value="password">
      <h1>会社管理者ログイン</h1>
      <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
      <label>メールアドレス<input type="email" name="email" value="admin@pharma.local" required></label>
      <label>パスワード<input type="password" name="password" value="demo1234" required></label>
      <button class="btn primary wide" type="submit">認証コードを送信</button>
      <a class="text-link" href="<?= h(app_url('/login.php')) ?>">ユーザーログインへ戻る</a>
    <?php else: ?>
      <input type="hidden" name="step" value="code">
      <h1>認証コード入力</h1>
      <?php if ($demoCode): ?><div class="alert info">デモ認証コード：<strong><?= h($demoCode) ?></strong></div><?php endif; ?>
      <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
      <label>認証コード<input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="123456" required></label>
      <button class="btn primary wide" type="submit">ログインして拠点選択へ</button>
    <?php endif; ?>
  </form>
</section>
<?php View::footer(); ?>
