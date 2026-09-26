<?php
require_once __DIR__ . '/auth.php';
$viewer = requireLogin();
$ref = trim($_GET['ref'] ?? '');
$stmt = $pdo->prepare('SELECT user_id, attachment_path FROM leave_requests WHERE ref_no = ?');
$stmt->execute([$ref]);
$request = $stmt->fetch();
if (!$request || !$request['attachment_path'] || ($viewer['role'] !== 'admin' && (int)$request['user_id'] !== (int)$viewer['id'])) {
    http_response_code(404);
    exit('Attachment unavailable.');
}
$base = realpath(LEAVE_PRIVATE_DIR . '/attachments');
$path = realpath(LEAVE_PRIVATE_DIR . '/attachments/' . basename($request['attachment_path']));
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Attachment unavailable.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
