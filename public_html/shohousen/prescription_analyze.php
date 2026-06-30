<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/prescription_scan.php');
}
Csrf::verify();
try {
    $file = $_FILES['prescription_file'] ?? null;
    if (!is_array($file)) {
        throw new RuntimeException('処方箋画像が選択されていません。');
    }
    $jobId = (new PrescriptionOcrService())->analyzeUploaded($file, $user, (string)($_POST['source_type'] ?? 'camera'));
    redirect('/prescription_result.php?job_id=' . $jobId);
} catch (Throwable $e) {
    View::header('解析エラー');
    ?>
    <section class="page-title"><h1>解析に失敗しました</h1><p>撮影画像またはAPI設定を確認してください。</p></section>
    <div class="alert error"><?= h($e->getMessage()) ?></div>
    <div class="button-row"><a class="btn ghost" href="<?= h(app_url('/prescription_scan.php')) ?>">撮影画面へ戻る</a></div>
    <?php
    View::footer();
}
