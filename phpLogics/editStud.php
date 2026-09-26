<?php
require("auth.php");
header('Content-Type: application/json');

// ── Admin only ────────────────────────────────────────────────
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
require_once __DIR__ . '/audit.php';

$student_id = trim($_POST['student_id'] ?? '');
$first      = trim($_POST['first_name'] ?? '');
$last       = trim($_POST['last_name']  ?? '');
$role       = trim($_POST['role']       ?? '');
$email      = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$contact    = trim($_POST['contact']    ?? '');
$password   = $_POST['password']        ?? '';

$lrn    = trim($_POST['lrn']                       ?? '');

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
// Server-side enforcement of the same rules the admin form checks client-side
// (client validation is bypassable — the API must be the last line of defense).
if ($password !== '' && strlen($password) < 5) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 5 characters.']);
    exit;
}
if (!preg_match('/^[0-9]{7,11}$/', $contact)) {
    echo json_encode(['success' => false, 'message' => 'Contact number must be 7–11 digits, numbers only.']);
    exit;
}
if ($lrn !== '' && !preg_match('/^[0-9]{1,12}$/', $lrn)) {
    echo json_encode(['success' => false, 'message' => 'LRN must be up to 12 digits, numbers only.']);
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
    $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? AND student_id != ?");
    $dup->execute([$email, $student_id]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already used by another account']);
        exit;
    }

    // ── Remember the OLD role — the audit must show role changes explicitly ──
    $old = $pdo->prepare("SELECT role FROM users WHERE student_id = ?");
    $old->execute([$student_id]);
    $oldRole = (string)($old->fetchColumn() ?: '');

    // ── Fetch existing photo ───────────────────────────────────────
    $stmt = $pdo->prepare("SELECT profile_photo FROM users WHERE student_id = ?");
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
        $allowed     = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $mime        = mime_content_type($_FILES['profile_photo']['tmp_name']);
        $size        = $_FILES['profile_photo']['size'];
        $ext         = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));

        if (in_array($mime, $allowed) && in_array($ext, $allowed_ext, true) && $size <= 3 * 1024 * 1024) {
            $dir = __DIR__ . '/../uploads/profile_photos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Delete old photo
            if (!empty($existing_photo)) {
                $oldPhotoFs = __DIR__ . '/../' . $existing_photo;
                if (file_exists($oldPhotoFs)) unlink($oldPhotoFs);
            }

            $filename = 'student_' . $student_id . '_' . time() . '.' . $ext;

            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dir . $filename)) {
                $new_photo = 'uploads/profile_photos/' . $filename;
            }
        }
    }

    // ── Update query ───────────────────────────────────────────────
    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            UPDATE users
            SET profile_photo = ?,
                first_name    = ?,
                last_name     = ?,
                role          = ?,
                email         = ?,
                contact       = ?,
                lrn           = ?,
                password      = ?
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
            $hash,
            $student_id
        ]);

    } else {
        $stmt = $pdo->prepare("
            UPDATE users
            SET profile_photo = ?,
                first_name    = ?,
                last_name     = ?,
                role          = ?,
                email         = ?,
                contact       = ?,
                lrn           = ?
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
            $student_id
        ]);
    }

    // ── Audit: admin updated an account record ──
    audit_log($pdo, 'USER_UPDATED', 'user', $student_id,
        "Account '{$student_id}' updated"
        . (($oldRole !== '' && $oldRole !== $role) ? " — role: {$oldRole} → {$role}" : '')
        . ($password !== '' ? ' (password reset)' : ''));

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('editStud DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
