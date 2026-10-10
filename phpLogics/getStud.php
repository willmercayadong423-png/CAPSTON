<?php
require(__DIR__ . "/auth.php");

header('Content-Type: application/json');

// ── Admin only ────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../database/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Explicit column list — NEVER expose password hashes
    // Schema v5: role name via roles, academic data via student_profiles
    // (aliases keep the JSON keys the dashboard JS expects).
    $stmt = $pdo->query("
        SELECT u.id, sp.student_id, u.first_name, u.last_name,
               r.role_name AS role, u.email, u.contact,
               u.status, u.created_at, u.profile_photo,
               sp.lrn
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN roles r            ON r.role_id  = u.role_id
        ORDER BY u.id DESC
    ");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($students);

} catch (PDOException $e) {
    error_log('getStud DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
}
