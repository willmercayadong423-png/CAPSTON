<?php
require '../phpLogics/auth.php';
include '../database/db.php';
header('Content-Type: application/json');

// ── Registrar only ────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
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

$stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE student_id = ?");

if (!$stmt) {
    error_log("unArch prepare failed: " . $conn->error);
    echo json_encode(["success" => false, "message" => "Database error."]);
    exit;
}

$stmt->bind_param("s", $id);

if (!$stmt->execute()) {
    error_log("unArch execute failed: " . $stmt->error);
    echo json_encode(["success" => false, "message" => "Database error."]);
    exit;
}

echo json_encode(["success" => true]);
exit;
