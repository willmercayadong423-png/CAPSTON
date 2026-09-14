// ── editReq.php ─────────────────────────────────────────────────────────────
<?php
require("auth.php");
include("../database/db.php");

if (strtolower($_SESSION['role']) !== 'student') {
    header("Location: ../registrar/registrarMainPage.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['edit_request'])) {
    header("Location: ../student/dashboard.php");
    exit();
}

// ── CSRF check ──────────────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    header("Location: ../student/dashboard.php?error=csrf_fail");
    exit();
}



$student_id = (int)$_SESSION['user_id'];
$req_id     = (int)($_POST['req_id'] ?? 0);
$doc_type   = trim($_POST['document_type'] ?? '');
$purpose    = trim($_POST['purpose'] ?? '');

// ── Validate basic fields ──────────────────────────────────────────
if (!$req_id || empty($doc_type) || empty($purpose)) {
    header("Location: ../student/dashboard.php?error=missing_fields");
    exit();
}

// ── Validate against the SHARED document list (phpLogics/document_types.php)
// — same list the request form uses, so edits can never fail on a stale copy.
$documentTypes = require __DIR__ . '/document_types.php';

if (!in_array($doc_type, $documentTypes, true)) {
    header("Location: ../student/dashboard.php?error=invalid_doc_type");
    exit();
}




// ── Verify this request belongs to the student and is still Pending ─
$chk = $conn->prepare(
    "SELECT id, id_photo, auth_letter
     FROM document_requests
     WHERE id = ? AND user_id = ? AND status = 'Pending'"
);
$chk->bind_param("ii", $req_id, $student_id);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$existing) {
    header("Location: ../student/dashboard.php?error=not_found");
    exit();
}


// ── File upload helpers ────────────────────────────────────────────
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$allowed_ext   = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
$max_size      = 5 * 1024 * 1024; // 5 MB

// Use absolute path for move_uploaded_file; store web-root-relative path in DB
$upload_fs  = __DIR__ . '/../uploads/request_requirements/'; // filesystem path
$upload_web = 'uploads/request_requirements/';               // path stored in DB & used in href

if (!is_dir($upload_fs)) {
    mkdir($upload_fs, 0755, true);
}

$upload_errors = [];
$new_id_photo   = $existing['id_photo'];    // keep existing by default
$new_auth_letter = $existing['auth_letter']; // keep existing by default




// ── Process Valid ID upload (optional replacement) ─────────────────
if (isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] === UPLOAD_ERR_OK) {
    $file     = $_FILES['id_photo'];
    $mimetype = mime_content_type($file['tmp_name']);
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
   $allowed_ext   = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];


    if (!in_array($ext, $allowed_ext, true)) {
        $upload_errors[] = "Valid ID: file extension not allowed.";
    } elseif (!in_array($mimetype, $allowed_types)) {
        $upload_errors[] = "Valid ID: invalid file type. Use JPG, PNG, GIF, WEBP, or PDF.";
    } elseif ($file['size'] > $max_size) {
        $upload_errors[] = "Valid ID: file exceeds 5 MB limit.";
    } else {
        $filename = 'id_photo_' . $student_id . '_' . time() . '_' . rand(100, 999) . '.' . $ext;

        if (move_uploaded_file($file['tmp_name'], $upload_fs . $filename)) {
            if (!empty($existing['id_photo'])) {
                $old = __DIR__ . '/../' . $existing['id_photo'];
                if (file_exists($old)) unlink($old);
            }
            $new_id_photo = $upload_web . $filename;
        } else {
            $upload_errors[] = "Valid ID: failed to save. Please try again.";
        }
    }
}



// ── Process Authorization Letter upload (optional replacement) ─────
if (isset($_FILES['auth_letter']) && $_FILES['auth_letter']['error'] === UPLOAD_ERR_OK) {
    $file     = $_FILES['auth_letter'];
    $mimetype = mime_content_type($file['tmp_name']);
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
   

    if (!in_array($ext, $allowed_ext, true)) {
        $upload_errors[] = "Authorization Letter: file extension not allowed.";
    } elseif (!in_array($mimetype, $allowed_types)) {
        $upload_errors[] = "Authorization Letter: invalid file type.";
    } elseif ($file['size'] > $max_size) {
        $upload_errors[] = "Authorization Letter: file exceeds 5 MB limit.";
    } else {
        $filename = 'auth_letter_' . $student_id . '_' . time() . '_' . rand(100, 999) . '.' . $ext;

        if (move_uploaded_file($file['tmp_name'], $upload_fs . $filename)) {
            if (!empty($existing['auth_letter'])) {
                $old = __DIR__ . '/../' . $existing['auth_letter'];
                if (file_exists($old)) unlink($old);
            }
            $new_auth_letter = $upload_web . $filename;
        } else {
            $upload_errors[] = "Authorization Letter: failed to save. Please try again.";
        }
    }
}
// ── If upload errors, redirect back with error ─────────────────────
if (!empty($upload_errors)) {
    $msg = urlencode(implode(' ', $upload_errors));
    header("Location: ../student/dashboard.php?upload_error=" . $msg);
    exit();
}

// ── Update the request in DB ───────────────────────────────────────
$upd = $conn->prepare(
    "UPDATE document_requests
     SET document_type = ?, purpose = ?, id_photo = ?, auth_letter = ?
     WHERE id = ? AND user_id = ? AND status = 'Pending'"
);
$upd->bind_param(
    "ssssii",
    $doc_type,
    $purpose,
    $new_id_photo,
    $new_auth_letter,
    $req_id,
    $student_id
);

if ($upd->execute()) {
    header("Location: ../student/dashboard.php?edit_success=1&view=requests");
} else {
    header("Location: ../student/dashboard.php?error=db_fail");
}

$upd->close();
$conn->close();
exit();
