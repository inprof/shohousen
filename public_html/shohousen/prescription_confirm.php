<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/prescription_scan.php');
}
Csrf::verify();

try {
    $prescriptionId = create_prescription_from_post($user, $_POST);
    redirect('/prescription_field_select.php?id=' . $prescriptionId . '&saved=1');
} catch (Throwable $e) {
    http_response_code(500);
    View::header('DB保存エラー');
    ?>
    <section class="page-title">
      <h1>DB保存エラー</h1>
      <p>修正内容をDB保存できませんでした。内容を確認して再実行してください。</p>
    </section>
    <section class="card">
      <div class="alert danger">
        <strong><?= h($e->getMessage()) ?></strong><br>
        <span>発生箇所：<?= h($e->getFile() . ':' . (string)$e->getLine()) ?></span>
      </div>
      <div class="button-row">
        <button class="btn ghost" type="button" onclick="history.back()">修正画面へ戻る</button>
        <a class="btn" href="<?= h(app_url('/prescription_scan.php')) ?>">再撮影</a>
      </div>
    </section>
    <?php
    View::footer();
}
