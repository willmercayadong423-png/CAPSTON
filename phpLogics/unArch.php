<?php
require '../phpLogics/auth.php';
include '../database/db.php';
require_once __DIR__ . '/audit.php';
header('Content-Type: application/json');

// ── Admin only ────────────────────────────────────────────────
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

// Schema v5: school numbers live in student_profiles — update by id.
// STAFF accounts have no school number and arrive as "uid:N".
if (preg_match('/^uid:(\d+)$/', $id, $uidMatch)) {
    $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
    $uid = (int)$uidMatch[1];          // bind_param takes args by reference
    $stmt->bind_param("i", $uid);
} else {
    $stmt = $conn->prepare("
        UPDATE users u
        JOIN student_profiles sp ON sp.user_id = u.id
        SET u.status = 'active'
        WHERE sp.student_id = ?
    ");
    $stmt->bind_param("s", $id);
}

if (!$stmt->execute()) {
    error_log("unArch execute failed: " . $stmt->error);
    echo json_encode(["success" => false, "message" => "Database error."]);
    exit;
}

// ── Audit: admin restored an archived account ──
audit_log($conn, 'USER_UNARCHIVED', 'user', $id, "Account '{$id}' restored to active");

echo json_encode(["success" => true]);
exit;
