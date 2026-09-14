<?php
/* ── HEHMS — Download an e-certificate attached to a released request ──
 * Student-only: the requesting student can re-download their own
 * e-certificate from My Requests → History (it was also emailed to
 * them on release).
 * ───────────────────────────────────────────────────────────────────── */
require(__DIR__ . "/auth.php");
require_once __DIR__ . '/../database/db.php';

if (strtolower($_SESSION['role']) !== 'student') {
    http_response_code(403);
    exit('Forbidden');
}

$student_id = $_SESSION['user_id'];
$req_id     = (int)($_GET['req_id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT e_certificate FROM document_requests WHERE id = ? AND student_id = ?"
);
$stmt->bind_param("ii", $req_id, $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['e_certificate'])) {
    http_response_code(404);
    exit('Not found');
}

// DB paths are web-root-relative ("uploads/...") — resolve to filesystem
$path = dirname(__DIR__) . '/' . ltrim($row['e_certificate'], '/');

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found');
}

$mime = mime_content_type($path);
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
