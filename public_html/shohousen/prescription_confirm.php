<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/prescription_scan.php');
}
Csrf::verify();

try {
    $prescriptionId = create_prescription_from_post($user, $_POST);
    $afterSaveAction = (string)($_POST['after_save_action'] ?? 'normal');
    if ($afterSaveAction === 'reparse_test') {
        try {
            (new PrescriptionReparseTestService())->runForPrescription($user, $prescriptionId);
            redirect('/prescription_io_debug.php?id=' . $prescriptionId . '&reparse_done=1');
        } catch (Throwable $reparseError) {
            $_SESSION['prescription_reparse_error'] = $reparseError->getMessage();
            redirect('/prescription_io_debug.php?id=' . $prescriptionId . '&reparse_error=1');
        }
    }
    redirect('/prescription_field_select.php?id=' . $prescriptionId . '&saved=1');
} catch (Throwable $e) {
    $message = $e->getMessage();
    if ($e instanceof RuntimeException && (str_contains($message, '判定不能:') || str_contains($message, 'NG:'))) {
        $_SESSION['prescription_validation_errors'] = array_values(array_filter(array_map('trim', preg_split('/\\R/u', $message) ?: [])));
        $_SESSION['prescription_validation_old_post'] = $_POST;
        $jobId = (int)($_POST['parse_job_id'] ?? 0);
        $url = $jobId > 0 ? '/prescription_result.php?job_id=' . $jobId . '&validation_error=1' : '/prescription_scan.php';
        redirect($url);
    }

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
