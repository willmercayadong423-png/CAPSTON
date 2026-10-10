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

$student_id = db_utf8(trim($_POST['student_id'] ?? ''));
$first      = db_utf8(trim($_POST['first_name'] ?? ''));
$last       = db_utf8(trim($_POST['last_name']  ?? ''));
$role       = trim($_POST['role']       ?? '');
$email      = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$contact    = trim($_POST['contact']    ?? '');
$password   = $_POST['password']        ?? '';

$lrn        = trim($_POST['lrn']        ?? '');

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
if ($password !== '' && strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
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
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 🔍 Resolve the account — by school number (schema v5: student_profiles)
    // for students, or by "uid:N" for STAFF accounts (they have no school
    // number). Without the uid path, registrar/admin accounts could never
    // be edited once created — the admin UI keys every row action on this id.
    $find = null;
    $byUid = (bool)preg_match('/^uid:(\d+)$/', $student_id, $uidMatch);
    if ($byUid) {
        $find = $pdo->prepare("
            SELECT u.id, r.role_name AS role, u.profile_photo, sp.student_id AS school_no
            FROM users u
            LEFT JOIN roles r ON r.role_id = u.role_id
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.id = ?
        ");
        $find->execute([(int)$uidMatch[1]]);
    } else {
        $find = $pdo->prepare("
            SELECT u.id, r.role_name AS role, u.profile_photo, sp.student_id AS school_no
            FROM users u
            JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN roles r ON r.role_id = u.role_id
            WHERE sp.student_id = ?
        ");
        $find->execute([$student_id]);
    }
    $row = $find->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    $user_id = (int)$row['id'];
    // Remember the OLD role — the audit must show role changes explicitly
    $oldRole = (string)($row['role'] ?? '');

    /* ── Role-change safety guards ────────────────────────────────
     * 1. An admin must never re-role their OWN account through this
     *    endpoint — a self-demotion instantly locks the current session
     *    out of the admin area (and, with no other admin, strands the
     *    whole system). Own-account edits belong in "My Account",
     *    which has no role field.
     * 2. Demoting the LAST active Admin is always refused — otherwise no
     *    administrator would remain to manage accounts and settings. */
    if ($user_id === (int)($_SESSION['user_id'] ?? 0) && $role !== 'Admin') {
        echo json_encode(['success' => false, 'message' => 'You cannot change your own account\'s role here. Ask another administrator to make this change.']);
        exit;
    }
    if ($oldRole === 'Admin' && $role !== 'Admin') {
        $others = $pdo->prepare(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE LOWER(r.role_name) = 'admin' AND LOWER(u.status) = 'active' AND u.id != ?"
        );
        $others->execute([$user_id]);
        if ((int)$others->fetchColumn() === 0) {
            echo json_encode(['success' => false, 'message' => 'This is the last active Administrator account — it cannot be demoted. Create another Admin first.']);
            exit;
        }
    }

    // 🔍 Duplicate email check (exclude the account being edited)
    $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $dup->execute([$email, $user_id]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already used by another account']);
        exit;
    }

    // Resolve the new role to its lookup id
    $roleLookup = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ?");
    $roleLookup->execute([$role]);
    $role_id = (int)$roleLookup->fetchColumn();

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

            // NOTE: the file is MOVED FIRST and the old photo deleted only
            // after the move succeeded — the previous order (delete, then
            // move) lost the old photo whenever the move failed.
            // The filename uses the numeric user id: "uid:2"-style ids
            // contain ':' which is ILLEGAL in NTFS filenames and made
            // every staff photo upload fail silently on Windows.
            $filename = 'account_' . $user_id . '_' . time() . '.' . $ext;

            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dir . $filename)) {
                // New file is safely on disk — now retire the old one
                if (!empty($existing_photo)) {
                    $oldPhotoFs = __DIR__ . '/../' . $existing_photo;
                    if (file_exists($oldPhotoFs) && $oldPhotoFs !== $dir . $filename) unlink($oldPhotoFs);
                }
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
                role_id       = ?,
                email         = ?,
                contact       = ?,
                password      = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $new_photo,
            $first,
            $last,
            $role_id,
            $email,
            $contact,
            $hash,
            $user_id
        ]);

    } else {
        $stmt = $pdo->prepare("
            UPDATE users
            SET profile_photo = ?,
                first_name    = ?,
                last_name     = ?,
                role_id       = ?,
                email         = ?,
                contact       = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $new_photo,
            $first,
            $last,
            $role_id,
            $email,
            $contact,
            $user_id
        ]);
    }

    // Keep the academic profile in sync (LRN lives in student_profiles)
    $pdo->prepare("UPDATE student_profiles SET lrn = ? WHERE user_id = ?")
        ->execute([($lrn !== '' ? $lrn : null), $user_id]);

    // ── Role-change housekeeping ──────────────────────────────────
    // A school number (student_profiles row) belongs to STUDENT accounts
    // only. When an account is converted to Registrar/Admin its profile row
    // is removed — otherwise staff would keep a student ID that the student
    // self-service flows (Forgot Credentials, archive/unarchive by school
    // number, …) could still match against.
    $reissuedId = null;
    if ($role !== 'Student') {
        $pdo->prepare("DELETE FROM student_profiles WHERE user_id = ?")
            ->execute([$user_id]);
    } elseif (empty($row['school_no'])) {
        // Converted (back) TO Student but has no school number (e.g. a staff
        // account being re-roled, or a student whose number was removed in a
        // previous conversion) — issue a fresh one so the account is fully
        // functional and stays reachable by school number everywhere.
        $year = date('Y');
        $lastStudent = $pdo->prepare(
            "SELECT student_id FROM student_profiles
             WHERE student_id LIKE ?
             ORDER BY CAST(SUBSTRING(student_id, 6) AS UNSIGNED) DESC
             LIMIT 1"
        );
        $lastStudent->execute([$year . '-%']);
        $lastId = $lastStudent->fetchColumn();
        $seq    = $lastId ? (int)substr((string)$lastId, 5) + 1 : 1;

        $exists = $pdo->prepare("SELECT 1 FROM student_profiles WHERE student_id = ?");
        do {
            $newNo = $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            $exists->execute([$newNo]);
            $taken = $exists->fetchColumn() !== false;
            if ($taken) $seq++;
        } while ($taken && $seq < 100000);

        $pdo->prepare("INSERT INTO student_profiles (user_id, student_id) VALUES (?, ?)")
            ->execute([$user_id, $newNo]);
        $reissuedId = $newNo;
    }

    // ── Audit: admin updated an account record ──
    audit_log($pdo, 'USER_UPDATED', 'user', $student_id,
        "Account '{$student_id}' updated"
        . (($oldRole !== '' && $oldRole !== $role) ? " — role: {$oldRole} → {$role}" : '')
        . ($reissuedId !== null ? " — new school number issued: {$reissuedId}" : '')
        . ($password !== '' ? ' (password reset)' : ''));

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('editStud DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
