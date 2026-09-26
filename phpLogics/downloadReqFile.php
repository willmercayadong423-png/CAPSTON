<?php
/* ── HEHMS — Registrar/Admin: fetch a request's attached files ─────────
 * Serves requirement files (student valid IDs) and e-certificates that
 * are blocked from direct /uploads/ access by .htaccess.
 *
 *   GET ?req_id=N&field=id_photo     → the student's valid ID
 *   GET ?req_id=N&field=e_certificate→ the released e-certificate
 *
 * Registrar and Admin roles only. The file must belong to an existing
 * request; ownership is implied by the registrar's authority to view
 * any request.
 * ────────────────────────────────────────────────────────────────────── */
require __DIR__ . "/auth.php";
require_once __DIR__ . '/../database/db.php';

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'registrar' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$req_id = (int)($_GET['req_id'] ?? 0);
$field  = $_GET['field'] ?? '';
$allowed = ['id_photo', 'e_certificate'];

if (!in_array($field, $allowed, true)) {
    http_response_code(400);
    exit('Invalid field');
}

$stmt = $conn->prepare("SELECT $field AS filepath FROM document_requests WHERE id = ?");
$stmt->bind_param("i", $req_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Check the row BEFORE touching it (fetch_assoc returns null when no row)
if (!$row || empty($row['filepath'])) {
    http_response_code(404);
    exit('Not found');
}

// DB paths are web-root-relative ("uploads/...") — resolve to filesystem
$path = dirname(__DIR__) . '/' . ltrim($row['filepath'], '/');

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found');
}

$mime = mime_content_type($path);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
