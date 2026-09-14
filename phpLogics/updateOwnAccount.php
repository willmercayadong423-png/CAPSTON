<?php
require __DIR__ . "/auth.php";
include __DIR__ . "/../database/db.php";
header('Content-Type: application/json');

// ── Admin or Registrar (own profile only) ─────────────────────────
$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'admin' && $role !== 'registrar') {
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

$user_id     = (int)$_SESSION['user_id'];
$first       = trim($_POST['first_name'] ?? '');
$last        = trim($_POST['last_name'] ?? '');
$email       = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$contact     = trim($_POST['contact'] ?? '');
$newPassword = $_POST['password'] ?? '';

if ($first === '' || $last === '') {
    echo json_encode(['success' => false, 'message' => 'First and last name are required.']);
    exit;
}
if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if ($contact !== '' && !preg_match('/^[0-9]{7,11}$/', $contact)) {
    echo json_encode(['success' => false, 'message' => 'Contact number must be 7–11 digits, numbers only.']);
    exit;
}
if ($newPassword !== '' && strlen($newPassword) < 5) {
    echo json_encode(['success' => false, 'message' => 'New password must be at least 5 characters.']);
    exit;
}

// ── Duplicate email check ─────────────────────────────────────────
$chk = $conn->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
$chk->bind_param("si", $email, $user_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows > 0) {
    $chk->close();
    echo json_encode(['success' => false, 'message' => 'That email is already used by another account.']);
    exit;
}
$chk->close();

// ── Fetch current row (photo) ─────────────────────────────────────
$s = $conn->prepare("SELECT profile_photo FROM students WHERE id = ?");
$s->bind_param("i", $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();

if (!$me) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}

$new_photo = $me['profile_photo'] ?? null;

// ── Optional profile photo upload ─────────────────────────────────
if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['profile_photo'];

    $allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_ext  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime         = mime_content_type($file['tmp_name']);

    if (!in_array($ext, $allowed_ext, true) || !in_array($mime, $allowed_mime, true)) {
        echo json_encode(['success' => false, 'message' => 'Profile photo must be a JPG, PNG, GIF, or WEBP image.']);
        exit;
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Profile photo must be under 3 MB.']);
        exit;
    }

    $fs_dir = dirname(__DIR__) . '/uploads/profile_photos/';
    if (!is_dir($fs_dir)) mkdir($fs_dir, 0755, true);

    $filename = 'staff_' . $user_id . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $fs_dir . $filename)) {
        if (!empty($me['profile_photo'])) {
            $oldFs = dirname(__DIR__) . '/' . ltrim($me['profile_photo'], '/');
            if (file_exists($oldFs)) unlink($oldFs);
        }
        $new_photo = 'uploads/profile_photos/' . $filename;
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save the photo. Please try again.']);
        exit;
    }
}

// ── Update ────────────────────────────────────────────────────────
if ($newPassword !== '') {
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upd = $conn->prepare(
        "UPDATE students
         SET first_name = ?, last_name = ?, email = ?, contact = ?,
             profile_photo = ?, password = ?, plain_password = NULL
         WHERE id = ?"
    );
    $upd->bind_param("ssssssi", $first, $last, $email, $contact, $new_photo, $hash, $user_id);
} else {
    $upd = $conn->prepare(
        "UPDATE students
         SET first_name = ?, last_name = ?, email = ?, contact = ?, profile_photo = ?
         WHERE id = ?"
    );
    $upd->bind_param("sssssi", $first, $last, $email, $contact, $new_photo, $user_id);
}

if ($upd->execute()) {
    $_SESSION['email'] = $email; // keep session in sync with the new email
    echo json_encode(['success' => true]);
} else {
    error_log('updateOwnAccount error: ' . $upd->error);
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
$upd->close();
