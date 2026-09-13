<?php
require '../phpLogics/auth.php';
include '../database/db.php';
header('Content-Type: application/json');

// ── Registrar only ────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'registrar') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Invalid or expired session. Reload the page and try again."]);
    exit;
}

if (!isset($_POST['student_id'])) {
    echo json_encode(["success" => false, "message" => "No ID"]);
    exit;
}

$id = trim($_POST['student_id']);

// Never archive your own account (would lock out the registrar)
$chk = $conn->prepare("SELECT id FROM students WHERE student_id = ?");
$chk->bind_param("s", $id);
$chk->execute();
$target = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$target) {
    echo json_encode(["success" => false, "message" => "Student not found."]);
    exit;
}
if ((int)$target['id'] === (int)($_SESSION['user_id'] ?? 0)) {
    echo json_encode(["success" => false, "message" => "You cannot archive your own account."]);
    exit;
}

$stmt = $conn->prepare("UPDATE students SET status = 'archived' WHERE student_id = ?");

if (!$stmt) {
    error_log("archStud prepare failed: " . $conn->error);
    echo json_encode(["success" => false, "message" => "Database error."]);
    exit;
}

$stmt->bind_param("s", $id);

if (!$stmt->execute()) {
    error_log("archStud execute failed: " . $stmt->error);
    echo json_encode(["success" => false, "message" => "Database error."]);
    exit;
}

echo json_encode(["success" => true]);
exit;
