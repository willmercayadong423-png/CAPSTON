<?php
require("auth.php");
include("../database/db.php");
require_once __DIR__ . '/audit.php';

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
$doc_type   = db_utf8(trim($_POST['document_type'] ?? ''));
$purpose    = db_utf8(trim($_POST['purpose'] ?? ''));
if ($purpose === 'Other') {
    // "Other — please specify" → use the free-text detail
    $purpose = db_utf8(trim($_POST['purpose_other'] ?? ''));
}
$syInput    = db_utf8(trim($_POST['school_year_last_attended'] ?? ''));
$glInput    = db_utf8(trim($_POST['grade_level'] ?? ''));

// Every request needs the SY; the Grade Level requirement is per document
// type (document_types.requires_grade_level — admin-managed).

// ── Validate basic fields ──────────────────────────────────────────
if (!$req_id || empty($doc_type) || empty($purpose)) {
    header("Location: ../student/dashboard.php?error=missing_fields");
    exit();
}

// ── Verify this request belongs to the student and is still Pending ─
// (fetched BEFORE the type checks so a removed current type stays editable)
// Schema v5: the type is stored as document_type_id; the NAME is joined
// back in as `document_type` so the checks below keep comparing names.
$chk = $conn->prepare(
    "SELECT dr.id, dr.document_type_id, dt.name AS document_type, dr.purpose,
           dr.grade_level, dr.school_year_last_attended, dr.id_photo, dr.registrar_viewed_at
     FROM document_requests dr
     LEFT JOIN document_types dt ON dt.id = dr.document_type_id
     WHERE dr.id = ? AND dr.user_id = ? AND dr.status = 'Pending'"
);
$chk->bind_param("ii", $req_id, $student_id);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$existing) {
    header("Location: ../student/dashboard.php?error=not_found");
    exit();
}

// ── Review lock: once the registrar has opened this request's details,
// the student may no longer edit it (the office is verifying these
// exact fields). Cancelling is still allowed on the dashboard.
if (!empty($existing['registrar_viewed_at'])) {
    header("Location: ../student/dashboard.php?error=locked");
    exit();
}

// ── Validate against the SHARED document list (DB, admin-managed) ──
// Same list the request form uses. Removed (inactive) types are NOT
// selectable — EXCEPT the request's own current type, which stays allowed
// so a pending request can still be edited after its type was removed
// from the request form.
require_once __DIR__ . '/document_types.php';
$activeTypes = get_document_types($conn);         // currently requestable
$allTypes    = get_document_types($conn, false);  // incl. removed/legacy
$glMapAll    = get_document_type_map($conn, false);

$typeKnown   = in_array($doc_type, $allTypes, true);
$typeAllowed = in_array($doc_type, $activeTypes, true)
            || ($doc_type === $existing['document_type']);

if (!$typeKnown || !$typeAllowed) {
    header("Location: ../student/dashboard.php?error=invalid_doc_type");
    exit();
}

if ($syInput === '') {
    header("Location: ../student/dashboard.php?error=sy_required");
    exit();
}
if ((int)($glMapAll[$doc_type] ?? 0) === 1 && $glInput === '') {
    header("Location: ../student/dashboard.php?error=gl_required");
    exit();
}

// ── Dropdown format guards (the form now submits selects) ─────────
// A request's own LEGACY free-text value may pass through unchanged;
// only NEW values must come from the dropdown format.
if ($syInput !== ($existing['school_year_last_attended'] ?? '')
    && !preg_match('/^[0-9]{4}-[0-9]{4}$/', $syInput)) {
    header("Location: ../student/dashboard.php?error=sy_invalid");
    exit();
}
if ($glInput !== ''
    && $glInput !== ($existing['grade_level'] ?? '')
    && !preg_match('/^Grade [0-9]{1,2}$/', $glInput)) {
    header("Location: ../student/dashboard.php?error=gl_invalid");
    exit();
}
if (mb_strlen($purpose) < 3 || mb_strlen($purpose) > 255) {
    header("Location: ../student/dashboard.php?error=purpose_invalid");
    exit();
}


// ── Resolve the (validated) document name to its catalog id ──────
// The name was just checked against the admin-managed list, so it always
// resolves — the existing-id fallback only guards legacy edge cases.
$typeIdStmt = $conn->prepare("SELECT id FROM document_types WHERE name = ? LIMIT 1");
$typeIdStmt->bind_param("s", $doc_type);
$typeIdStmt->execute();
$newTypeId = $typeIdStmt->get_result()->fetch_row()[0] ?? null;
$typeIdStmt->close();

if ($newTypeId === null && $doc_type === ($existing['document_type'] ?? '')) {
    $newTypeId = (int)$existing['document_type_id'];   // keep current type
}
if ($newTypeId === null) {
    header("Location: ../student/dashboard.php?error=invalid_doc_type");
    exit();
}

// ── Guard: changing the document type must not create a second pending
// request of the same type (the new-request flow enforces the same rule). ──
if ((int)$newTypeId !== (int)$existing['document_type_id']) {
    // One active request per document type: switching this request to a type
    // the student already has Pending / Processing / Ready for Pickup /
    // Released is refused (the request being edited is excluded).
    $dup = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM document_requests
         WHERE user_id = ? AND document_type_id = ?
           AND status IN ('Pending','Processing','Ready for Pickup','Released')
           AND id != ?"
    );
    $dup->bind_param("iii", $student_id, $newTypeId, $req_id);
    $dup->execute();
    $dupCnt = (int)$dup->get_result()->fetch_assoc()['cnt'];
    $dup->close();

    if ($dupCnt > 0) {
        header("Location: ../student/dashboard.php?error=dup_pending");
        exit();
    }
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



// ── If upload errors, redirect back with error ─────────────────────
if (!empty($upload_errors)) {
    $msg = urlencode(implode(' ', $upload_errors));
    header("Location: ../student/dashboard.php?upload_error=" . $msg);
    exit();
}

// ── Update the request in DB ───────────────────────────────────────
$upd = $conn->prepare(
    "UPDATE document_requests
     SET document_type_id = ?, purpose = ?, grade_level = ?, school_year_last_attended = ?, id_photo = ?
     WHERE id = ? AND user_id = ? AND status = 'Pending'"
);
$glValue = ($glInput !== '') ? $glInput : null;   // bind_param needs variables
$syValue = ($syInput !== '') ? $syInput : null;
$upd->bind_param(
    "issssii",
    $newTypeId,
    $purpose,
    $glValue,
    $syValue,
    $new_id_photo,
    $req_id,
    $student_id
);

if ($upd->execute()) {
    // ── Audit: student edited their pending request ──
    $changes = [];
    if (($existing['document_type'] ?? '') !== $doc_type) {
        $changes[] = "{$existing['document_type']} → {$doc_type}";
    }
    if (($existing['purpose'] ?? '') !== $purpose) {
        $changes[] = 'purpose updated';
    }
    if (trim((string)($existing['school_year_last_attended'] ?? '')) !== $syInput) {
        $changes[] = 'school year updated';
    }
    if (trim((string)($existing['grade_level'] ?? '')) !== $glInput) {
        $changes[] = 'grade level updated';
    }
    if (!empty($_FILES['id_photo']['name'])) {
        $changes[] = 'new Valid ID attached';
    }
    audit_log($conn, 'REQUEST_UPDATED', 'document_request',
        'REQ-' . str_pad((string) $req_id, 4, '0', STR_PAD_LEFT),
        'Updated request' . ($changes ? ': ' . implode('; ', $changes) : ''));

    header("Location: ../student/dashboard.php?edit_success=1&view=requests");
} else {
    header("Location: ../student/dashboard.php?error=db_fail");
}

$upd->close();
$conn->close();
exit();
