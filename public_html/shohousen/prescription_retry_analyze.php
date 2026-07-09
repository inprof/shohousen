<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/prescription_scan.php');
}
Csrf::verify();
$jobId = (int)($_POST['job_id'] ?? 0);
if ($jobId <= 0) {
    redirect('/prescription_scan.php');
}
try {
    (new PrescriptionOcrService())->retryJob($user, $jobId);
    redirect('/prescription_result.php?job_id=' . $jobId . '&retry_done=1');
} catch (Throwable $e) {
    $_SESSION['prescription_retry_error'] = $e->getMessage();
    redirect('/prescription_result.php?job_id=' . $jobId . '&retry_error=1');
}
