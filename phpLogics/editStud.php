<?php
require("auth.php");
header('Content-Type: application/json');

// ── Registrar only ────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session. Reload the page and try again.']);
    exit;
}

require_once __DIR__ . '/../database/config.php';

$student_id = trim($_POST['student_id'] ?? '');
$first      = trim($_POST['first_name'] ?? '');
$last       = trim($_POST['last_name']  ?? '');
$role       = trim($_POST['role']       ?? '');
$email      = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$contact    = trim($_POST['contact']    ?? '');
$password   = $_POST['password']        ?? '';

$lrn    = trim($_POST['lrn']                       ?? '');
$dob    = trim($_POST['date_of_birth']             ?? '');
$grade  = trim($_POST['grade_level']               ?? '');
$strand = trim($_POST['strand']                    ?? '');
$syear  = trim($_POST['school_year_last_attended'] ?? '');

$allowedRoles = ['Student', 'Registrar', 'Admin'];

if (!$student_id) {
    echo json_encode(['success' => false, 'message' => 'Missing ID']);
    exit;
}
if (!$first || !$last || !$contact) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}
if (!in_array($role, $allowedRoles, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid role']);
    exit;
}
if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 🔍 Duplicate email check (exclude the student being edited)
    $dup = $pdo->prepare("SELECT id FROM students WHERE email = ? AND student_id != ?");
    $dup->execute([$email, $student_id]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already used by another account']);
        exit;
    }

    // ── Fetch existing photo ───────────────────────────────────────
    $stmt = $pdo->prepare("SELECT profile_photo FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    $existing_photo = $row['profile_photo'] ?? null;
    $new_photo      = $existing_photo;

    // ── Handle profile photo upload ────────────────────────────────
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime    = mime_content_type($_FILES['profile_photo']['tmp_name']);
        $size    = $_FILES['profile_photo']['size'];

        if (in_array($mime, $allowed) && $size <= 3 * 1024 * 1024) {
            $dir = __DIR__ . '/../uploads/profile_photos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Delete old photo
            if (!empty($existing_photo)) {
                $old = __DIR__ . '/../' . $existing_photo;
                if (file_exists($old)) unlink($old);
            }

            $ext      = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $filename = 'student_' . $student_id . '_' . time() . '.' . $ext;

            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dir . $filename)) {
                $new_photo = 'uploads/profile_photos/' . $filename;
            }
        }
    }

    // ── Update query ───────────────────────────────────────────────
    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // New password set → also clear any stored plain_password
        $stmt = $pdo->prepare("
            UPDATE students
            SET profile_photo = ?,
                first_name    = ?,
                last_name     = ?,
                role          = ?,
                email         = ?,
                contact       = ?,
                lrn           = ?,
                date_of_birth = ?,
                grade_level   = ?,
                strand        = ?,
                school_year_last_attended = ?,
                password      = ?,
                plain_password = NULL
            WHERE student_id = ?
        ");
        $stmt->execute([
            $new_photo,
            $first,
            $last,
            $role,
            $email,
            $contact,
            $lrn,
            $dob    ?: null,
            $grade,
            $strand,
            $syear,
            $hash,
            $student_id
        ]);

    } else {
        $stmt = $pdo->prepare("
            UPDATE students
            SET profile_photo = ?,
                first_name    = ?,
                last_name     = ?,
                role          = ?,
                email         = ?,
                contact       = ?,
                lrn           = ?,
                date_of_birth = ?,
                grade_level   = ?,
                strand        = ?,
                school_year_last_attended = ?
            WHERE student_id = ?
        ");
        $stmt->execute([
            $new_photo,
            $first,
            $last,
            $role,
            $email,
            $contact,
            $lrn,
            $dob    ?: null,
            $grade,
            $strand,
            $syear,
            $student_id
        ]);
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('editStud DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
