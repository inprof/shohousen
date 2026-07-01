<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$tenantId = (int)$user['tenant_id'];
$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    http_response_code(404);
    exit;
}
$stmt = Db::branch()->prepare('SELECT sf.stored_path, sf.mime_type
    FROM prescription_parse_jobs j
    INNER JOIN storage_files sf ON sf.id = j.original_storage_file_id
    WHERE j.id = :job_id AND j.tenant_id = :tenant_id
    LIMIT 1');
$stmt->execute([':job_id' => $jobId, ':tenant_id' => $tenantId]);
$file = $stmt->fetch();
$path = (string)($file['stored_path'] ?? '');
$mime = (string)($file['mime_type'] ?? 'application/octet-stream');
if ($path === '' || !is_file($path) || !is_readable($path) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);
