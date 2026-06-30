<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/menu.php');
}
Csrf::verify();
$id = create_prescription_from_post($user, $_POST);
redirect('/qr.php?id=' . $id);
