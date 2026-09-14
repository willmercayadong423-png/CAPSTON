<?php
require(__DIR__ . "/auth.php");

header('Content-Type: application/json');

// ── Registrar only ────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../database/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Explicit column list — NEVER expose password hashes
    $stmt = $pdo->query("
        SELECT id, student_id, first_name, last_name, role, email, contact,
               status, created_at, profile_photo,
               lrn, date_of_birth, grade_level, strand, school_year_last_attended
        FROM users
        ORDER BY id DESC
    ");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($students);

} catch (PDOException $e) {
    error_log('getStud DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
}
