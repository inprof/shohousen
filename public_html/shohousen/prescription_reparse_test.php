<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/receptions.php');
}
Csrf::verify();
$id = (int)($_POST['id'] ?? $_POST['prescription_id'] ?? 0);
if ($id <= 0) {
    redirect('/receptions.php');
}
try {
    (new PrescriptionReparseTestService())->runForPrescription($user, $id);
    redirect('/prescription_io_debug.php?id=' . $id . '&reparse_done=1');
} catch (Throwable $e) {
    $_SESSION['prescription_reparse_error'] = $e->getMessage();
    redirect('/prescription_io_debug.php?id=' . $id . '&reparse_error=1');
}
