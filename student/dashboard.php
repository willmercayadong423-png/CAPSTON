
<!--dashboard.php -->
<?php






require __DIR__ . "/../phpLogics/auth.php";
include(__DIR__ . "/../database/db.php");
include __DIR__ . "/../phpLogics/site_config.php";
require_once __DIR__ . '/../phpLogics/icons.php';
require_once __DIR__ . "/../phpLogics/mailer.php";
require_once __DIR__ . "/../phpLogics/audit.php";


header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");

require_role('student');

$student_id = $_SESSION['user_id'];

// ── Path anchors ──────────────────────────────────────────────────
// This page lives in /student, but uploads/ sits at the project root and
// the DB stores web-root-relative paths (e.g. "uploads/profile_photos/x.png").
// So: use $uploads_fs for filesystem calls, $uploads_web for anything
// stored in the DB or rendered into an href/src.
$uploads_fs  = dirname(__DIR__) . '/uploads';
$uploads_web = 'uploads';

// ── CSRF token (one per session) ───────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool
{
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// ── Shared list of requestable document types ────────────────────
// Single source of truth (phpLogics/document_types.php → DB table) — the
// same list validates edits in editReq.php, and the Admin manages it in
// Information → Requestable Documents.
require_once __DIR__ . '/../phpLogics/document_types.php';
$documentTypes = get_document_types($conn);            // active only
$docGlMap      = get_document_type_map($conn, false);  // ALL types (the edit
                                                        // modal may hold a
                                                        // removed current type)


// ── Fetch student info ─────────────────────────────────────────────
// Schema v5: role name via roles, academic data via student_profiles —
// the aliases keep every $student[...] key below identical.
$s = $conn->prepare("
    SELECT u.*,
           r.role_name                  AS role,
           sp.student_id                AS student_id,
           sp.lrn                       AS lrn,
           sp.grade_level               AS grade_level,
           sp.school_year_last_attended AS school_year_last_attended
    FROM users u
    LEFT JOIN roles r            ON r.role_id  = u.role_id
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = ?
");
$s->bind_param("i", $student_id);
$s->execute();
$student = $s->get_result()->fetch_assoc();
$s->close();

$studentName = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);

// ── Latest request's academic details (display-only on My Account) ──
// Grade Level and Last School Year Attended are per-request fields
// (document_requests), so the account page shows the values from the
// student's most recent request.
$lastAcad = $conn->prepare(
    "SELECT grade_level, school_year_last_attended
     FROM document_requests
     WHERE user_id = ?
     ORDER BY date_requested DESC
     LIMIT 1"
);
$lastAcad->bind_param("i", $student_id);
$lastAcad->execute();
$lastAcadRow    = $lastAcad->get_result()->fetch_assoc();
$lastAcad->close();
$displayGradeLevel = trim((string)($lastAcadRow['grade_level'] ?? ''));
$displaySchoolYear = trim((string)($lastAcadRow['school_year_last_attended'] ?? ''));

// ── Dropdown option sets (Request a Document + edit modal) ──────
$gradeOptions = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];

$purposeOptions = [
    'College Application',
    'Scholarship Application',
    'Employment / Job Application',
    'Transfer to Another School',
    'Board Examination Requirement',
    'Passport / Travel Requirement',
    'Personal Copy',
];

// School Years, newest first (Philippine SY runs June–March)
$syLatest = (int)date('n') >= 6 ? (int)date('Y') : (int)date('Y') - 1;
$syOptions = [];
for ($y = $syLatest; $y >= 2015; $y--) {
    $syOptions[] = $y . '-' . ($y + 1);
}

// Prefill from the account (fallback: latest request values)
$presetGrade = trim((string)($student['grade_level'] ?? '')) ?: $displayGradeLevel;
$presetSy    = trim((string)($student['school_year_last_attended'] ?? '')) ?: $displaySchoolYear;

// Render <option>s; a saved value outside the list (legacy free-text)
// is kept as an extra option so nothing is silently lost.
function render_select_options(array $options, string $current = '', string $placeholder = ''): string
{
    $html = '';
    if ($placeholder !== '') {
        $html .= '<option value="">' . htmlspecialchars($placeholder) . '</option>';
    }
    if ($current !== '' && !in_array($current, $options, true)) {
        $html .= '<option value="' . htmlspecialchars($current, ENT_QUOTES) . '" selected>'
               . htmlspecialchars($current, ENT_QUOTES) . '</option>';
    }
    foreach ($options as $opt) {
        $sel = ($opt === $current && $current !== '') ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($opt, ENT_QUOTES) . '"' . $sel . '>'
               . htmlspecialchars($opt) . '</option>';
    }
    return $html;
}

// ── Handle: Update Account Info ────────────────────────────────────
$updateSuccess = "";
$updateError   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $new_email   = trim($_POST['email'] ?? '');
    $new_contact = trim($_POST['contact'] ?? '');

    if (!verifyCsrf()) {
        $updateError = "Your session expired. Please try again.";
    } elseif (empty($new_email)) {
        $updateError = "Email address is required.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $updateError = "Please enter a valid email address.";
    } elseif ($new_contact !== '' && !preg_match('/^[0-9]{7,11}$/', $new_contact)) {
        $updateError = "Contact number must be 7–11 digits, numbers only.";
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->bind_param("si", $new_email, $student_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $updateError = "That email is already used by another account.";
        } else {
            // ── Optional password change ──
            // (The confirm-password step was removed from the form — the
            // current password alone authorizes the change.)
            $curPass  = (string)($_POST['current_password'] ?? '');
            $newPass  = (string)($_POST['new_password'] ?? '');
            $changePw = ($newPass !== '' || $curPass !== '');

            if ($changePw && !password_verify($curPass, $student['password'] ?? '')) {
                $updateError = "Current password is incorrect.";
            } elseif ($changePw && $newPass === '') {
                // current password typed but new left empty
                $updateError = "Please enter your new password.";
            } elseif ($changePw && strlen($newPass) < 8) {
                $updateError = "New password must be at least 8 characters.";
            }

            $new_photo = $student['profile_photo'] ?? null;

            // ── Student-editable profile fields ────────────────────────
            // The combined Name display / Student ID / LRN stay read-only
            // (registrar-managed); First & Last name, Grade Level and Last
            // School Year Attended are editable on the account.
            // VALIDATED BEFORE the photo upload below — the upload used to
            // persist first and then get rejected by these checks, leaving
            // an orphaned photo file on disk that nothing referenced.
            $new_first_name = db_utf8(trim($_POST['first_name'] ?? ''));
            $new_last_name  = db_utf8(trim($_POST['last_name'] ?? ''));
            $new_grade      = db_utf8(trim($_POST['grade_level'] ?? ''));
            $new_sy         = db_utf8(trim($_POST['school_year_last_attended'] ?? ''));

            /* ── Validate (HTML "required" is bypassable — enforce server-side).
               db_utf8() above strips invalid UTF-8 bytes so a stray bad byte
               can't abort the UPDATE with an uncaught mysqli exception. ── */
            if ($new_first_name === '' || $new_last_name === '') {
                $updateError = "First and last name are required.";
            } elseif (mb_strlen($new_first_name) > 100 || mb_strlen($new_last_name) > 100) {
                $updateError = "First and last name must be 100 characters or fewer.";
            } elseif (mb_strlen($new_grade) > 20) {
                $updateError = "Grade level must be 20 characters or fewer.";
            } elseif (mb_strlen($new_sy) > 20) {
                $updateError = "School year must be 20 characters or fewer.";
            }

            if (empty($updateError) && isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $img_allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $img_allowed_ext  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $img_type = mime_content_type($_FILES['profile_photo']['tmp_name']);
                $img_ext  = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                $img_size = $_FILES['profile_photo']['size'];

                if (!in_array($img_ext, $img_allowed_ext, true)) {
                    $updateError = "Profile photo must be a JPG, PNG, GIF, or WEBP file.";
                } elseif (!in_array($img_type, $img_allowed_mime, true)) {
                    $updateError = "Profile photo must be JPG, PNG, GIF, or WEBP.";
                } elseif ($img_size > 3 * 1024 * 1024) {
                    $updateError = "Profile photo must be under 3 MB.";
                } else {
                    $photo_fs_dir = $uploads_fs . '/profile_photos/';
                    if (!is_dir($photo_fs_dir)) mkdir($photo_fs_dir, 0755, true);
                    $photo_fn   = 'student_' . $student_id . '_' . time() . '_' . rand(100, 999) . '.' . $img_ext;
                    $photo_dest = $uploads_web . '/profile_photos/' . $photo_fn;
                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $photo_fs_dir . $photo_fn)) {
                        // NOTE: the PREVIOUS photo file is deleted only after the
                        // DB update succeeds (below). Deleting it here would leave
                        // a dangling DB path if a later validation fails.
                        $new_photo = $photo_dest;
                    } else {
                        $updateError = "Failed to save profile photo. Please try again.";
                    }
                }
            }






            if (empty($updateError)) {
    $newGradeSql = ($new_grade !== '') ? $new_grade : null;   // bind_param needs variables
    $newSySql    = ($new_sy    !== '') ? $new_sy    : null;
    $upd = $conn->prepare("UPDATE users
        SET email=?, contact=?, profile_photo=?,
            first_name=?, last_name=?
        WHERE id=?");
    $upd->bind_param(
        "sssssi",
        $new_email, $new_contact, $new_photo,
        $new_first_name, $new_last_name,
        $student_id
    );
    // Academic data lives in student_profiles (schema v5) — kept in sync
    // in the same flow right after the identity update.
    $updProfile = $conn->prepare("
        INSERT INTO student_profiles (user_id, grade_level, school_year_last_attended)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            grade_level               = COALESCE(VALUES(grade_level), grade_level),
            school_year_last_attended = COALESCE(VALUES(school_year_last_attended), school_year_last_attended)
    ");
    $updProfile->bind_param("iss", $student_id, $newGradeSql, $newSySql);
                if ($upd->execute()) {
                    $updProfile->execute();
                    $updProfile->close();

                    // ── Remove the previous photo only now that the DB points at the new one ──
                    $oldPhoto = (string)($student['profile_photo'] ?? '');
                    if ($oldPhoto !== '' && $new_photo !== $oldPhoto) {
                        $oldFs = $uploads_fs . '/' . ltrim($oldPhoto, '/');
                        if (file_exists($oldFs)) unlink($oldFs);
                    }
                    // ── Apply the optional password change ──
                    if ($changePw && $newPass !== '') {
                        $hash = password_hash($newPass, PASSWORD_DEFAULT);
                        $pw = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $pw->bind_param("si", $hash, $student_id);
                        $pw->execute();
                        $pw->close();
                    }

                    audit_log($conn, 'PROFILE_UPDATED', 'user', (string)$student_id,
                        'Own profile updated' . (($changePw && $newPass !== '') ? ' (password changed)' : ''));

                    $updateSuccess = "Account information updated successfully.";
                    $s2 = $conn->prepare("
                        SELECT u.*,
                               r.role_name                  AS role,
                               sp.student_id                AS student_id,
                               sp.lrn                       AS lrn,
                               sp.grade_level               AS grade_level,
                               sp.school_year_last_attended AS school_year_last_attended
                        FROM users u
                        LEFT JOIN roles r            ON r.role_id  = u.role_id
                        LEFT JOIN student_profiles sp ON sp.user_id = u.id
                        WHERE u.id = ?
                    ");
                    $s2->bind_param("i", $student_id);
                    $s2->execute();
                    $student     = $s2->get_result()->fetch_assoc();
                    $s2->close();
                    $studentName = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
                    $_SESSION['email'] = $student['email'];
                } else {
                    $updateError = "Something went wrong. Please try again.";
                }
                $upd->close();
            }
        }
        $chk->close();
    }
}

// ── Handle: Cancel request ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request'])) {
    // CSRF failure must be VISIBLE — a silent no-op made users believe
    // the cancel worked (every other flow redirects with ?error=…).
    if (!verifyCsrf()) {
        header("Location: dashboard.php?view=requests&error=csrf_fail");
        exit();
    }
    $req_id = (int)($_POST['req_id'] ?? 0);
    $cancel = $conn->prepare(
        "UPDATE document_requests
         SET status = 'Cancelled', cancelled_by = 'student'
         WHERE id = ? AND user_id = ? AND status = 'Pending'"
    );
    $cancel->bind_param("ii", $req_id, $student_id);
    $cancel->execute();
    $cancelled = $cancel->affected_rows > 0;
    if ($cancelled) {
        audit_log($conn, 'REQUEST_CANCELLED', 'document_request',
            'REQ-' . str_pad((string)$req_id, 4, '0', STR_PAD_LEFT),
            "Request cancelled by student");
    }
    $cancel->close();
    // Only a REAL cancel lands on the archive tab — anything else
    // (cross-user id, already-processed row) gets an explanatory flash
    // instead of a misleading success view.
    header("Location: dashboard.php?view=requests" . ($cancelled ? "&tab=archived" : "&error=cancel_failed"));
    exit();
}

// ── Handle: Submit new request ─────────────────────────────────────
$successMsg = isset($_GET['success'])         ? "Your request has been submitted successfully!" : "";
$successMsg = isset($_GET['edit_success'])    ? "Request updated successfully."                 : $successMsg;
$successMsg = isset($_GET['restore_success']) ? "Request restored to active."                   : $successMsg;
$errorMsg   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $doc_type = db_utf8(trim($_POST['document_type'] ?? ''));
    $purpose  = db_utf8(trim($_POST['purpose'] ?? ''));
    if ($purpose === 'Other') {
        // "Other — please specify" → use the free-text detail
        $purpose = db_utf8(trim($_POST['purpose_other'] ?? ''));
    }
    $syInput  = trim($_POST['school_year_last_attended'] ?? '');
    $glInput  = trim($_POST['grade_level'] ?? '');

    // Which documents also require the Grade Level now comes from the DB
    // (document_types.requires_grade_level — admin-managed per document).
    $glRequired = (int)($docGlMap[$doc_type] ?? 0) === 1;

    if (!verifyCsrf()) {
        $errorMsg = "Your session expired. Please try again.";
    } elseif (empty($doc_type) || empty($purpose)) {
        $errorMsg = "Please fill in all required fields.";
    } elseif (!in_array($doc_type, $documentTypes, true)) {
        $errorMsg = "Please select a valid document type.";
    } elseif (!in_array($purpose, $purposeOptions, true)
        && (mb_strlen($purpose) < 3 || mb_strlen($purpose) > 255)) {
        $errorMsg = "Please select a purpose (or specify it under Other).";
    } elseif ($syInput === '') {
        $errorMsg = "Please select the School Year you last attended.";
    } elseif (!preg_match('/^[0-9]{4}-[0-9]{4}$/', $syInput)) {
        $errorMsg = "Please select a valid School Year.";
    } elseif ($glRequired && $glInput === '') {
        $errorMsg = "Please select your Grade Level.";
    } elseif ($glInput !== '' && !preg_match('/^Grade [0-9]{1,2}$/', $glInput)) {
        $errorMsg = "Please select a valid Grade Level.";
    } else {

        // ── Limit: max 3 pending requests at once ──
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM document_requests WHERE user_id = ? AND status = 'Pending'"
        );
        $countStmt->bind_param("i", $student_id);
        $countStmt->execute();
        $pendingCount = $countStmt->get_result()->fetch_assoc()['cnt'];
        $countStmt->close();

        // ── Duplicate check: one active request per document type ─────
        // A student cannot request the same document again while an earlier
        // request for it is Pending, Processing, Ready for Pickup (legacy)
        // or Released. Only Cancelled / Rejected requests free the type up.
        $dupStmt = $conn->prepare(
            "SELECT dr.status
             FROM document_requests dr
             JOIN document_types dt ON dt.id = dr.document_type_id
             WHERE dr.user_id = ? AND dt.name = ?
               AND dr.status IN ('Pending','Processing','Ready for Pickup','Released')
             LIMIT 1"
        );
        $dupStmt->bind_param("is", $student_id, $doc_type);
        $dupStmt->execute();
        $dupRow   = $dupStmt->get_result()->fetch_assoc();
        $dupStmt->close();

        if ($pendingCount >= 3) {
            $errorMsg = "You already have 3 pending requests. Please wait for one to be processed before submitting a new one.";
        } elseif ($dupRow) {
            $errorMsg = "You already have a {$dupRow['status']} request for \"$doc_type\". "
                      . 'You can request this document again only after that request is cancelled or rejected.';
        } elseif (!isset($_FILES['id_photo']) || $_FILES['id_photo']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = "Please upload your Valid ID.";
        } else {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $max_size      = 5 * 1024 * 1024;
        $req_fs_dir    = $uploads_fs . '/request_requirements/';   // filesystem
        $req_web_dir   = $uploads_web . '/request_requirements/';  // stored in DB
        if (!is_dir($req_fs_dir)) mkdir($req_fs_dir, 0755, true);

        $upload_errors = [];
        $saved_files   = ['id_photo' => null];

      $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

$id_file = $_FILES['id_photo'];
$id_type = mime_content_type($id_file['tmp_name']);
$id_ext  = strtolower(pathinfo($id_file['name'], PATHINFO_EXTENSION));

if (!in_array($id_ext, $allowed_ext, true)) {
    $upload_errors[] = "Valid ID: file extension not allowed.";
} elseif (!in_array($id_type, $allowed_types)) {
    $upload_errors[] = "Valid ID: invalid file type. Use JPG, PNG, GIF, WEBP, or PDF.";
} elseif ($id_file['size'] > $max_size) {
    $upload_errors[] = "Valid ID: file exceeds 5 MB limit.";
} else {
    $filename = 'id_photo_' . $student_id . '_' . time() . '_' . rand(100, 999) . '.' . $id_ext;
    if (move_uploaded_file($id_file['tmp_name'], $req_fs_dir . $filename)) {
        $saved_files['id_photo'] = $req_web_dir . $filename;
    } else {
        $upload_errors[] = "Valid ID: failed to save. Please try again.";
    }
}

        // ── Payment removed from the student flow ──
        // Payment is settled in person at the Registrar's Office during pickup.

        if (!empty($upload_errors)) {
            $errorMsg = implode(' ', $upload_errors);
        } else {
            // Resolve the (already-validated) document name to its catalog id
            $typeIdStmt = $conn->prepare("SELECT id FROM document_types WHERE name = ? LIMIT 1");
            $typeIdStmt->bind_param("s", $doc_type);
            $typeIdStmt->execute();
            $docTypeId = (int)($typeIdStmt->get_result()->fetch_row()[0] ?? 0);
            $typeIdStmt->close();

            $ins = $conn->prepare(
                "INSERT INTO document_requests
                    (user_id, document_type_id, purpose, grade_level, school_year_last_attended, id_photo)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $glValue = ($glInput !== '') ? $glInput : null;      // bind_param needs variables
            $syValue = ($syInput !== '') ? $syInput : null;
            $ins->bind_param(
                "iissss",
                $student_id,
                $docTypeId,
                $purpose,
                $glValue,
                $syValue,
                $saved_files['id_photo']
            );
               if ($ins->execute()) {
                // ── Tell the registrar accounts a new request is waiting ──
                $newReqNo = 'REQ-' . str_pad((string) $conn->insert_id, 4, '0', STR_PAD_LEFT);
                audit_log($conn, 'REQUEST_SUBMITTED', 'document_request', $newReqNo,
                    "{$doc_type} — Purpose: {$purpose}");

                // ── Keep My Account's academic info in sync with this request ──
                // The dropdowns prefill FROM the account; submitting with new
                // values (e.g. Grade 10) updates the account automatically, so
                // the student never has to edit My Account by hand. Grade Level
                // only overwrites when the document actually asked for it.
                $acct = $conn->prepare("
                    INSERT INTO student_profiles (user_id, grade_level, school_year_last_attended)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        grade_level               = COALESCE(VALUES(grade_level), grade_level),
                        school_year_last_attended = VALUES(school_year_last_attended)
                ");
                $acct->bind_param('iss', $student_id, $glValue, $syValue);
                $acct->execute();
                $acct->close();

                // ── Email the student a confirmation that the request was received ──
                $conf = send_status_email(
                    $student['email'],
                    trim($student['first_name'] . ' ' . $student['last_name']),
                    $newReqNo,
                    $doc_type,
                    'Pending'
                );
                if (!$conf['ok']) {
                    error_log("Submission confirmation mail failed ({$newReqNo}): " . $conf['error']);
                }
                try {
                    $regs = $conn->query(
                        "SELECT u.email, u.first_name, u.last_name FROM users u
                         JOIN roles r ON r.role_id = u.role_id
                         WHERE LOWER(r.role_name) = 'registrar' AND LOWER(u.status) != 'archived'
                           AND u.email IS NOT NULL AND u.email <> ''"
                    );
                    while ($regs && ($rg = $regs->fetch_assoc())) {
                        $res = send_registrar_request_email(
                            $rg['email'],
                            trim($rg['first_name'] . ' ' . $rg['last_name']),
                            $newReqNo,
                            $student['first_name'] . ' ' . $student['last_name'],
                            $doc_type,
                            $purpose
                        );
                        if (!$res['ok']) {
                            error_log("Registrar new-request notice failed ({$newReqNo}): " . $res['error']);
                        }
                    }
                } catch (Throwable $e) {
                    error_log("Registrar new-request notice error ({$newReqNo}): " . $e->getMessage());
                }

                header("Location: dashboard.php?success=1&view=requests");
                exit();
            } else {
                $errorMsg = "Something went wrong saving your request. Please try again.";
            }
            $ins->close();
        }
        }
    }
}

// ── Announcements (admin-managed) ──────────────────────────────
$annStmt = $conn->query(
    "SELECT a.id, a.title, a.message, a.created_at,
            CONCAT(u.first_name, ' ', u.last_name) AS author_name
     FROM announcements a
     LEFT JOIN users u ON a.created_by = u.id
     WHERE a.is_active = 1
     ORDER BY a.created_at DESC LIMIT 20"
);
$announcements = $annStmt ? $annStmt->fetch_all(MYSQLI_ASSOC) : [];

// ── Surface endpoint error redirects (edit/restore failures) as alerts ──
if (empty($errorMsg) && isset($_GET['upload_error'])) {
    $errorMsg = (string)$_GET['upload_error'];   // detailed message from editReq.php (escaped at output below)
}
if (empty($errorMsg) && isset($_GET['error'])) {
    $errorMsg = match ($_GET['error']) {
        'csrf_fail'        => 'Your session expired. Please try again.',
        'missing_fields'   => 'Please fill in all required fields.',
        'invalid_doc_type' => 'Please select a valid document type.',
        'sy_required'      => 'Please enter the School Year you last attended (e.g. 2024-2025).',
        'gl_required'      => 'Please enter your Grade Level.',
        'dup_pending'      => 'You already have an active request for that document type (pending, processing, or released). You can request it again only after it is cancelled or rejected.',
        'pending_limit'    => 'Restoring would exceed the 3-pending-request limit. Please wait for one to be processed.',
        'restore_failed'   => 'This request cannot be restored — it was rejected by the registrar and only they can reopen it.',
        'locked'           => "This request was already reviewed by the Registrar — it can no longer be edited. You may still cancel it or visit the Registrar's Office.",
        'sy_invalid'       => 'Please select a valid School Year.',
        'gl_invalid'       => 'Please select a valid Grade Level.',
        'purpose_invalid'  => 'Please select a purpose (or specify it under Other).',
        'not_found'        => 'That request can no longer be edited — it may have already been processed.',
        'cancel_failed'    => 'That request could not be cancelled — it may have already been processed or no longer belongs to you.',
        'db_fail'          => 'Something went wrong. Please try again.',
        default            => 'An error occurred. Please try again.',
    };
}

// ── Fetch requests ─────────────────────────────────────────────────
$req = $conn->prepare("
    SELECT dr.*, dt.name AS document_type
    FROM document_requests dr
    LEFT JOIN document_types dt ON dt.id = dr.document_type_id
    WHERE dr.user_id = ?
    ORDER BY dr.date_requested DESC
");
$req->bind_param("i", $student_id);
$req->execute();
$myRequests = $req->get_result()->fetch_all(MYSQLI_ASSOC);
$req->close();

$mainRequests     = [];
$archivedRequests = [];
foreach ($myRequests as $r) {
    if (in_array($r['status'], ['Released', 'Cancelled'])) {
        $archivedRequests[] = $r;
    } else {
        $mainRequests[] = $r;
    }
}

$cntTotal   = count($myRequests);

// ── Document types this student already has an ACTIVE request for ──
// Pending / Processing / Ready for Pickup (legacy) / Released lock the
// document type: the request-form card and the edit-modal option render
// highlighted-but-read-only, mirroring the server-side duplicate rule.
$activeDocTypes = [];
foreach ($myRequests as $r) {
    if (in_array($r['status'], ['Pending', 'Processing', 'Ready for Pickup', 'Released'], true)) {
        $activeDocTypes[$r['document_type']] = $r['status'];
    }
}
$cntPending = 0;
$cntReleased = 0;
$cntProcessing = 0;
$cntCancelled = 0;   // cancelled BY THE STUDENT
$cntRejected  = 0;   // cancelled BY THE REGISTRAR — shown as "Rejected",
                     // matching the registrar dashboard's own Rejected count
foreach ($myRequests as $r) {
    if ($r['status'] === 'Pending')          $cntPending++;
    if ($r['status'] === 'Released')         $cntReleased++;
    if ($r['status'] === 'Processing')       $cntProcessing++;
    if ($r['status'] === 'Cancelled') {
        if (trim($r['cancelled_by'] ?? '') === 'registrar') $cntRejected++;
        else                                               $cntCancelled++;
    }
}

// ── Compile Notifications (Announcements + Request Status Updates) ──
$notifications = [];

// 1 · Admin Announcements
foreach ($announcements as $ann) {
    $createdTime = strtotime($ann['created_at']);
    $notifications[] = [
        'id'               => 'ann_' . ($ann['id'] ?? md5($ann['title'] . $ann['created_at'])),
        'type'             => 'announcement',
        'title'            => $ann['title'],
        'body'             => $ann['message'],
        'time'             => $createdTime,
        'date_str'         => date('M d, Y · h:i A', $createdTime),
        'badge'            => 'Announcement',
        'badge_cls'        => 'badge-announcement',
        'icon'             => 'megaphone',
        'rejection_reason' => null,
        'req_id'           => null,
        'e_cert'           => false,
    ];
}

// 2 · Student Request Status Updates
foreach ($myRequests as $r) {
    $reqNo   = 'REQ-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT);
    $docType = $r['document_type'];
    $status  = $r['status'];
    $byReg   = (trim($r['cancelled_by'] ?? '') === 'registrar');

    if ($status === 'Pending') {
        $reqTime = strtotime($r['date_requested'] ?? 'now');
        $notifications[] = [
            'id'               => 'req_' . $r['id'] . '_pending',
            'type'             => 'request',
            'title'            => "Request Submitted: {$docType}",
            'body'             => "Your request #{$reqNo} for \"{$docType}\" was submitted and is pending review by the Registrar.",
            'time'             => $reqTime,
            'date_str'         => date('M d, Y · h:i A', $reqTime),
            'badge'            => 'Pending Review',
            'badge_cls'        => 'badge-pending',
            'icon'             => 'hourglass',
            'rejection_reason' => null,
            'req_id'           => $r['id'],
            'e_cert'           => false,
        ];
    } elseif ($status === 'Processing') {
        $procTime = strtotime($r['updated_at'] ?? 'now');
        $notifications[] = [
            'id'               => 'req_' . $r['id'] . '_processing',
            'type'             => 'request',
            'title'            => "Request Processing: {$docType}",
            'body'             => "Your request #{$reqNo} for \"{$docType}\" has been accepted and is currently being processed by the Registrar.",
            'time'             => $procTime,
            'date_str'         => date('M d, Y · h:i A', $procTime),
            'badge'            => 'Processing',
            'badge_cls'        => 'badge-processing',
            'icon'             => 'loader',
            'rejection_reason' => null,
            'req_id'           => $r['id'],
            'e_cert'           => false,
        ];
    } elseif ($status === 'Released') {
        $relTime = !empty($r['date_released']) ? strtotime($r['date_released']) : strtotime($r['updated_at'] ?? 'now');
        $hasCert = !empty($r['e_certificate']);
        $notifications[] = [
            'id'               => 'req_' . $r['id'] . '_released',
            'type'             => 'request',
            'title'            => "Document Released: {$docType}",
            'body'             => "Your request #{$reqNo} for \"{$docType}\" has been approved and issued." . ($hasCert ? " You can download your official e-Certificate now." : ""),
            'time'             => $relTime,
            'date_str'         => date('M d, Y · h:i A', $relTime),
            'badge'            => 'Released',
            'badge_cls'        => 'badge-released',
            'icon'             => 'award',
            'rejection_reason' => null,
            'req_id'           => $r['id'],
            'e_cert'           => $hasCert,
        ];
    } elseif ($status === 'Cancelled') {
        $canTime = strtotime($r['updated_at'] ?? 'now');
        if ($byReg) {
            $reason = trim((string)($r['rejection_reason'] ?? ''));
            $notifications[] = [
                'id'               => 'req_' . $r['id'] . '_rejected',
                'type'             => 'request',
                'title'            => "Request Rejected: {$docType}",
                'body'             => "Your request #{$reqNo} for \"{$docType}\" was rejected by the Registrar.",
                'time'             => $canTime,
                'date_str'         => date('M d, Y · h:i A', $canTime),
                'badge'            => 'Rejected',
                'badge_cls'        => 'badge-rejected',
                'icon'             => 'x-circle',
                'rejection_reason' => $reason ?: null,
                'req_id'           => $r['id'],
                'e_cert'           => false,
            ];
        } else {
            $notifications[] = [
                'id'               => 'req_' . $r['id'] . '_cancelled',
                'type'             => 'request',
                'title'            => "Request Cancelled: {$docType}",
                'body'             => "You cancelled your request #{$reqNo} for \"{$docType}\".",
                'time'             => $canTime,
                'date_str'         => date('M d, Y · h:i A', $canTime),
                'badge'            => 'Cancelled',
                'badge_cls'        => 'badge-cancelled',
                'icon'             => 'ban',
                'rejection_reason' => null,
                'req_id'           => $r['id'],
                'e_cert'           => false,
            ];
        }
    }
}

// Sort notifications newest first
usort($notifications, function ($a, $b) {
    return $b['time'] <=> $a['time'];
});

$totalNotifs = count($notifications);

function badgeClass($status, $cancelledBy = '')
{
    if ($status === 'Cancelled') {
        if ($cancelledBy === 'registrar') return 'rejected';
        return 'cancelled';   // cancelled by the student
    }
    return match ($status) {
        'Ready for Pickup' => 'ready',
        'Processing'       => 'processing',
        'Pending'          => 'pending',
        'Released'         => 'released',
        default            => 'pending',
    };
}

$activeView     = $_GET['view'] ?? 'dashboard';
// Whitelist — an unknown ?view= value would otherwise hide every view
if (!in_array($activeView, ['dashboard', 'requests', 'request', 'notifications', 'account'], true)) {
    $activeView = 'dashboard';
}
$activeTab      = $_GET['tab']  ?? 'main';
$profilePhoto   = !empty($student['profile_photo']) ? $student['profile_photo'] : null;
// Photos are blocked from direct /uploads/ access — the ONLY way to view
// one is the authenticated endpoint (serves the logged-in student's own
// photo by default).
$profilePhotoUrl = $profilePhoto ? '../phpLogics/download_profile.php' : null;
$avatarInitials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../assets/img/Logo.png">
    <title>Student Portal — HEHMHS</title>
    <script>
        // One-time cleanup: dark mode has been removed, but some browsers
        // may still have 'hehms-theme: dark' saved from before. Clear it
        // so nobody gets stuck with no way to switch it off.
        localStorage.removeItem('hehms-theme');
        document.documentElement.removeAttribute('data-theme');
    </script>
    <link rel="stylesheet" href="portal.css?v=2">
    <?php echo theme_head(); // admin-managed brand color ?>
    <!-- Fonts are SELF-HOSTED (assets/fonts) — the page looks identical even offline.
         (Google-hosted fonts used to make the whole site look broken without internet.) -->
    <link rel="stylesheet" href="../assets/fonts/inter.css">
</head>

<body>

    <!-- ══ TOP BAR ══ -->
    <header class="topbar">
        <div class="topbar-inner">
            <a href="dashboard.php" class="brand">
                <img src="<?php echo site_logo_url(); ?>" alt="School logo">
                <div class="brand-text">
                    <strong>Hilario E. Hermosa Memorial High School</strong>
                    <span>Student Portal</span>
                </div>
            </a>

            <div class="topbar-actions">
                <div class="notif-panel-wrapper">
                    <button type="button" class="bell" onclick="toggleNotifPanel(event)"
                        title="Notifications">
                        <?php echo icon('bell', 19); ?>
                        <?php if ($totalNotifs > 0): ?>
                            <span class="avatar-badge" id="avatarNotifBadge"
                                title="<?php echo $totalNotifs; ?> notifications"><?php echo $totalNotifs > 99 ? '99+' : $totalNotifs; ?></span>
                        <?php endif; ?>
                    </button>

                    <!-- Notification dropdown panel -->
                    <div class="notif-panel" id="notifPanel">
                        <div class="notif-panel-head">
                            <h3>Notifications</h3>
                            <button type="button" class="np-mark-read" onclick="markAllNotifsAsRead()">
                                <?php echo icon('check', 12); ?> Mark all read
                            </button>
                        </div>
                        <div class="notif-panel-list">
                            <?php if (empty($notifications)): ?>
                                <div class="np-empty">
                                    <div class="np-empty-icon"><?php echo icon('bell', 26); ?></div>
                                    <p>No notifications yet</p>
                                </div>
                            <?php else: foreach (array_slice($notifications, 0, 6) as $n): ?>
                                <div class="np-item" data-time="<?php echo (int)$n['time']; ?>">
                                    <span class="np-icon <?php echo htmlspecialchars($n['badge_cls']); ?>"><?php echo icon($n['icon'], 15); ?></span>
                                    <div class="np-body">
                                        <div class="np-title">
                                            <?php echo htmlspecialchars($n['title']); ?>
                                            <span class="np-badge <?php echo htmlspecialchars($n['badge_cls']); ?>"><?php echo htmlspecialchars($n['badge']); ?></span>
                                        </div>
                                        <div class="np-text"><?php echo htmlspecialchars($n['body']); ?></div>
                                        <div class="np-time"><?php echo htmlspecialchars($n['date_str']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <a href="#" class="notif-panel-foot" onclick="showView('notifications'); closeNotifPanel();">
                            View all notifications <?php echo icon('chevron-right', 14); ?>
                        </a>
                    </div>
                </div>

                <div class="header-avatar-wrapper">
                    <div class="header-avatar" onclick="toggleProfileMenu(event)"
                        title="Account menu">
                        <?php if ($profilePhoto): ?>
                            <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="avatar">
                        <?php else: ?>
                            <?php echo $avatarInitials; ?>
                        <?php endif; ?>
                    </div>

                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="profile-dropdown-user">
                            <div class="pdu-name"><?php echo $studentName; ?></div>
                            <div class="pdu-id">Student ID: <?php echo htmlspecialchars($student['student_id'] ?? '—'); ?></div>
                        </div>
                        <div class="profile-dropdown-divider"></div>
                        <a href="#" onclick="showView('account'); closeProfileMenu();" class="profile-dropdown-item">
                            <span class="pdi-icon"><?php echo icon('user', 15); ?></span>
                            <span class="pdi-label">My Account</span>
                        </a>
                        <a href="#" onclick="showView('notifications'); closeProfileMenu();" class="profile-dropdown-item notif-dropdown-item">
                            <span class="pdi-icon"><?php echo icon('bell', 15); ?></span>
                            <span class="pdi-label">Notifications</span>
                            <?php if ($totalNotifs > 0): ?>
                                <span class="pdi-badge" id="dropdownNotifBadge"><?php echo $totalNotifs > 99 ? '99+' : $totalNotifs; ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="profile-dropdown-divider"></div>
                        <a href="../phpLogics/Logout.php" class="profile-dropdown-item pdi-logout">
                            <span class="pdi-icon"><?php echo icon('logout', 15); ?></span>
                            <span class="pdi-label">Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

    </header>

    <div class="shell">

        <!-- Main navigation — left sidebar -->
        <aside class="sidebar">
            <nav class="tabs">
            <a onclick="showView('dashboard')" id="nav-dashboard"
                class="ptab <?php echo $activeView === 'dashboard' ? 'active' : ''; ?>"><?php echo icon('home', 16); ?> Home</a>
            <a onclick="showView('requests')" id="nav-requests"
                class="ptab <?php echo $activeView === 'requests' ? 'active' : ''; ?>"><?php echo icon('clipboard', 16); ?> My Requests</a>
            <a onclick="showView('request')" id="nav-request"
                class="ptab <?php echo $activeView === 'request' ? 'active' : ''; ?>"><?php echo icon('plus', 16); ?> Request a Document</a>
            <a onclick="showView('account')" id="nav-account"
                class="ptab <?php echo $activeView === 'account' ? 'active' : ''; ?>"><?php echo icon('settings', 16); ?> My Account</a>

            <a href="../phpLogics/Logout.php" class="ptab ptab-logout"
                title="Log out of the Student Portal"><?php echo icon('logout', 16); ?> Logout</a>
            </nav>
        </aside>

        <main class="page">

        <!-- ══════ VIEW: HOME ══════ -->
        <div id="view-dashboard" style="display:<?php echo $activeView === 'dashboard' ? 'block' : 'none'; ?>;">

            <div class="home-hero">
                <div class="hero-text">
                    <span class="hero-date"><?php echo date('l, F j, Y'); ?></span>
                    <h1>Hello, <?php echo htmlspecialchars($student['first_name'] ?? 'Student'); ?>!</h1>
                    <p>Request school documents online and track them in one place.</p>
                </div>
                <button type="button" class="hero-cta" onclick="showView('request')"><?php echo icon('plus', 18); ?> Request a Document</button>
            </div>

            <!-- Status tiles (clickable → filtered list) -->
            <div class="stat-grid">
                <div class="stat-tile card-click st-total" data-goto="" data-view="main" title="Show all of my requests">
                    <div class="st-row">
                        <span class="st-icon"><?php echo icon('clipboard', 17); ?></span>
                        <h2><?php echo $cntTotal; ?></h2>
                    </div>
                    <h4>Total Requests</h4>
                </div>
                <div class="stat-tile card-click st-pending" data-goto="Pending" data-view="main" title="Show my pending requests">
                    <div class="st-row">
                        <span class="st-icon"><?php echo icon('hourglass', 17); ?></span>
                        <h2><?php echo $cntPending; ?></h2>
                    </div>
                    <h4>Pending</h4>
                </div>
                <div class="stat-tile card-click st-processing" data-goto="Processing" data-view="main" title="Show my processing requests">
                    <div class="st-row">
                        <span class="st-icon"><?php echo icon('loader', 17); ?></span>
                        <h2><?php echo $cntProcessing; ?></h2>
                    </div>
                    <h4>Processing</h4>
                </div>
                <div class="stat-tile card-click st-released" data-goto="Released" data-view="archived" title="Show my released requests">
                    <div class="st-row">
                        <span class="st-icon"><?php echo icon('check-circle', 17); ?></span>
                        <h2><?php echo $cntReleased; ?></h2>
                    </div>
                    <h4>Released</h4>
                </div>
                <div class="stat-tile card-click st-rejected" data-goto="Rejected" data-view="archived" title="Show rejected requests">
                    <div class="st-row">
                        <span class="st-icon"><?php echo icon('ban', 17); ?></span>
                        <h2><?php echo $cntRejected; ?></h2>
                    </div>
                    <h4>Rejected</h4>
                </div>
                <div class="stat-tile card-click st-cancelled" data-goto="Cancelled" data-view="archived" title="Show my cancelled requests">
                    <div class="st-row">
                        <span class="st-icon"><?php echo icon('x-circle', 17); ?></span>
                        <h2><?php echo $cntCancelled; ?></h2>
                    </div>
                    <h4>Cancelled</h4>
                </div>
            </div>

            <div class="home-grid">
                <div class="card">
                    <h3><?php echo icon('megaphone', 16); ?> Announcements</h3>
                    <?php if (empty($announcements)): ?>
                        <p class="card-sub">No announcements right now — check back later.</p>
                    <?php else: foreach (array_slice($announcements, 0, 5) as $a): ?>
                        <div class="announce-item">
                            <div><span class="a-title"><?php echo htmlspecialchars($a['title']); ?></span><span class="a-date"><?php echo date('M d, Y', strtotime($a['created_at'])); ?></span></div>
                            <div class="a-body"><?php echo nl2br(htmlspecialchars($a['message'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                        <button type="button" class="btn btn-ghost" style="margin-top:10px;" onclick="showView('notifications')">See all updates <?php echo icon('chevron-right', 14); ?></button>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3><?php echo icon('building', 16); ?> Registrar's Office</h3>
                    <div class="info-row"><span class="ic"><?php echo icon('clock', 15); ?></span><div><strong>Office hours</strong><?php echo htmlspecialchars(site_setting('office_hours', 'Monday to Friday, 8:00 AM – 4:00 PM')); ?></div></div>
                    <div class="info-row"><span class="ic"><?php echo icon('mail', 15); ?></span><div><strong>Email</strong><?php echo htmlspecialchars(site_setting('contact_email', 'registrar@hehms.edu.ph')); ?></div></div>
                    <div class="info-row"><span class="ic"><?php echo icon('phone', 15); ?></span><div><strong>Phone</strong><?php echo htmlspecialchars(site_setting('contact_phone', '(044) 123-4567')); ?></div></div>
                    <div class="info-row"><span class="ic"><?php echo icon('map-pin', 15); ?></span><div><strong>Location</strong><?php echo htmlspecialchars(site_setting('contact_location', 'Siclong, Laur, Nueva Ecija')); ?></div></div>
                </div>
            </div>
        </div>

        <!-- ══════ VIEW: MY REQUESTS ══════ -->
        <div id="view-requests" style="display:<?php echo $activeView === 'requests' ? 'block' : 'none'; ?>;">
            <div class="view-head">
                <h1>My <span>Requests</span></h1>
                <p>Track every document you've requested. Pending requests can still be edited or cancelled.</p>
            </div>

            <?php if ($successMsg): ?>
                <div class="alert alert-success"><?php echo icon('check-circle', 15); ?> <?php echo htmlspecialchars($successMsg); ?></div>
            <?php endif; ?>
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger"><?php echo icon('alert', 15); ?> <?php echo htmlspecialchars($errorMsg); ?></div>
            <?php endif; ?>

            <div class="switch-btn">
                <a class="<?php echo $activeTab === 'main' ? 'active' : ''; ?>" id="tab-main" onclick="switchTable('main')">Active</a>
                <a class="<?php echo $activeTab === 'archived' ? 'active' : ''; ?>" id="tab-archived" onclick="switchTable('archived')">History</a>
            </div>

            <!-- Active requests -->
            <div class="container" id="tbl-main" style="display:<?php echo $activeTab === 'archived' ? 'none' : ''; ?>;">
                <div class="table-header">
                    <h3>Active Requests</h3>
                    <div class="table-header-controls">
                        <div class="search-wrapper">
                            <span class="search-icon"><?php echo icon('search', 13); ?></span>
                            <input type="text" class="search" placeholder="Search…"
                                oninput="filterRows('main-tbody', this.value)">
                        </div>
                        <button type="button" class="btn-new-request" onclick="showView('request')"><?php echo icon('plus', 14); ?> New Request</button>
                    </div>
                </div>
                <div class="filter-chips" id="chips-main">
                    <button type="button" class="chip active" data-filter="">All</button>
                    <button type="button" class="chip" data-filter="Pending"><?php echo icon('hourglass', 13); ?> Pending</button>
                    <button type="button" class="chip" data-filter="Processing"><?php echo icon('loader', 13); ?> Processing</button>
                </div>
                <div class="tbl-scroll-wrap">
                    <table class="tbl-x" style="min-width: 860px;">
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Document</th>
                                <th>Date Requested</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="main-tbody">
                            <?php if (empty($mainRequests)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <div class="empty-icon"><?php echo icon('inbox', 30); ?></div>
                                            <p>No active requests yet.<br>Click <strong>Request a Document</strong> to get started.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($mainRequests as $r):
                                $cls     = badgeClass($r['status'], $r['cancelled_by'] ?? '');
                                $padId   = 'REQ-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT);
                                $dateReq = date('m/d/Y', strtotime($r['date_requested']));
                                $canAct  = ($r['status'] === 'Pending');
                                // Review lock: once the registrar has opened this request's
                                // details, the student can no longer EDIT it (cancel stays).
                                $isLocked = $canAct && !empty($r['registrar_viewed_at']);
                                $existId = !empty($r['id_photo']) ? $r['id_photo'] : '';
                            ?>
                                <tr data-status="<?php echo htmlspecialchars($r['status']); ?>">
                                    <td><strong><?php echo $padId; ?></strong></td>
                                    <td><?php echo htmlspecialchars($r['document_type']); ?></td>
                                    <td><?php echo $dateReq; ?></td>
                                    <td><span class="badge <?php echo $cls; ?>"><?php echo $r['status']; ?></span></td>
                                    <td>
                                        <div class="action-group">
                                            <?php if ($isLocked): ?>
                                                <span class="btn-na" title="Locked — the Registrar has already reviewed this request, so it can no longer be edited."><?php echo icon('lock', 12); ?> Edit</span>
                                                <form method="POST" style="display:inline;"
                                                    onsubmit="return confirm('Cancel this request?');">
                                                    <input type="hidden" name="cancel_request" value="1">
                                                    <input type="hidden" name="req_id" value="<?php echo $r['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <button type="submit" class="btn-cancel-req">Cancel</button>
                                                </form>
                                            <?php elseif ($canAct): ?>
                                                <button class="btn-edit"
                                                    data-req-id="<?php echo (int)$r['id']; ?>"
                                                    data-doc-type="<?php echo htmlspecialchars($r['document_type'], ENT_QUOTES); ?>"
                                                    data-purpose="<?php echo htmlspecialchars($r['purpose'], ENT_QUOTES); ?>"
                                                    data-sy="<?php echo htmlspecialchars($r['school_year_last_attended'] ?? '', ENT_QUOTES); ?>"
                                                    data-gl="<?php echo htmlspecialchars($r['grade_level'] ?? '', ENT_QUOTES); ?>"
                                                    data-id-photo="<?php echo htmlspecialchars($existId, ENT_QUOTES); ?>">
                                                    Edit
                                                </button>
                                                <form method="POST" style="display:inline;"
                                                    onsubmit="return confirm('Cancel this request?');">
                                                    <input type="hidden" name="cancel_request" value="1">
                                                    <input type="hidden" name="req_id" value="<?php echo $r['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <button type="submit" class="btn-cancel-req">Cancel</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="btn-na">Edit</span>
                                                <span class="btn-na">Cancel</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Request history -->
            <div class="container" id="tbl-archived" style="display:<?php echo $activeTab === 'archived' ? '' : 'none'; ?>;">
                <div class="table-header">
                    <h3>History</h3>
                    <div class="search-wrapper">
                        <span class="search-icon"><?php echo icon('search', 13); ?></span>
                        <input type="text" class="search" placeholder="Search…"
                            oninput="filterRows('archived-tbody', this.value)">
                    </div>
                </div>
                <div class="filter-chips" id="chips-archived">
                    <button type="button" class="chip active" data-filter="">All</button>
                    <button type="button" class="chip" data-filter="Released"><?php echo icon('check-circle', 13); ?> Released</button>
                    <button type="button" class="chip" data-filter="Rejected"><?php echo icon('ban', 13); ?> Rejected</button>
                    <button type="button" class="chip" data-filter="Cancelled"><?php echo icon('x-circle', 13); ?> Cancelled</button>
                </div>
                <div class="tbl-scroll-wrap">
                    <table class="tbl-x" style="min-width: 900px;">
                        <thead>
                            <tr>
                                <th>Req ID</th>
                                <th>Document</th>
                                <th>Date Requested</th>
                                <th>Date Released</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="archived-tbody">
                            <?php if (empty($archivedRequests)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <div class="empty-icon"><?php echo icon('folder', 30); ?></div>
                                            <p>No history yet. Released and closed requests appear here.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($archivedRequests as $r):
                                $cancelledBy = trim($r['cancelled_by'] ?? '');
                                $cls         = badgeClass($r['status'], $cancelledBy);
                                $padId       = 'REQ-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT);
                                $dateReq     = date('m/d/Y', strtotime($r['date_requested']));
                                $dateRel     = !empty($r['date_released']) ? date('m/d/Y', strtotime($r['date_released'])) : null;

                                $byRegistrar = ($r['status'] === 'Cancelled' && $cancelledBy === 'registrar');
                                $byStudent   = ($r['status'] === 'Cancelled' && ($cancelledBy === 'student' || $cancelledBy === ''));
                                $canRestore  = $byStudent;

                                $statusLabel = $r['status'];
                                if ($byRegistrar) $statusLabel = 'Rejected';
                                if ($byStudent)   $statusLabel = 'Cancelled';
                            ?>
                                <tr data-status="<?php echo htmlspecialchars($statusLabel); ?>"<?php echo $r['status'] === 'Cancelled' ? ' data-cancelled="1"' : ''; ?>>
                                    <td><strong><?php echo $padId; ?></strong></td>
                                    <td><?php echo htmlspecialchars($r['document_type']); ?></td>
                                    <td><?php echo $dateReq; ?></td>
                                    <td><?php echo $dateRel ? $dateRel : '<span class="date-na">—</span>'; ?></td>
                                    <td>
                                        <span class="badge <?php echo $cls; ?>"><?php echo $statusLabel; ?></span>
                                        <?php if ($byRegistrar): ?>
                                            <?php if (!empty($r['rejection_reason'])): ?>
                                                <div class="reject-reason" title="Registrar's reason"><?php echo icon('message', 13); ?> <?php echo htmlspecialchars($r['rejection_reason']); ?></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <?php if (!empty($r['e_certificate'])): ?>
                                                <a class="btn-dl-cert" href="../phpLogics/downloadCert.php?req_id=<?php echo (int)$r['id']; ?>"><?php echo icon('award', 14); ?> e-Certificate</a>
                                            <?php endif; ?>
                                            <?php if ($canRestore): ?>
                                                <form method="POST" action="../phpLogics/restoreReq.php" style="display:inline;"
                                                    onsubmit="return confirm('Restore this request to active?');">
                                                    <input type="hidden" name="restore_request" value="1">
                                                    <input type="hidden" name="req_id" value="<?php echo $r['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <button type="submit" class="btn-restore"><?php echo icon('restore', 13); ?> Restore</button>
                                                </form>
                                            <?php elseif ($byRegistrar): ?>
                                                <span class="btn-rejected"><?php echo icon('ban', 13); ?> Rejected</span>
                                            <?php else: ?>
                                                <span class="btn-closed"><?php echo icon('check', 13); ?> Closed</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div><!-- /view-requests -->

        <!-- ══════ VIEW: REQUEST A DOCUMENT ══════ -->
        <div id="view-request" style="display:<?php echo $activeView === 'request' ? 'block' : 'none'; ?>;">
            <div class="view-head">
                <h1>Request a <span>Document</span></h1>
                <p>Two quick steps: pick your document, then fill in the details.</p>
            </div>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger"><?php echo icon('alert', 15); ?> <?php echo htmlspecialchars($errorMsg); ?></div>
            <?php endif; ?>

            <!-- Step indicator -->
            <div class="step-indicator">
                <span class="step-dot active" id="stepdot-1"><span class="num">1</span> Choose document</span>
                <span class="step-line"></span>
                <span class="step-dot" id="stepdot-2"><span class="num">2</span> Fill in details</span>
            </div>

            <form method="POST" enctype="multipart/form-data" id="request-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="document_type" id="selected-document-input" value="">
                <input type="hidden" name="submit_request" value="1">

                <!-- STEP 1: choose the document -->
                <div id="step-document">
                    <div class="card">
                        <h2 class="card-title">What document do you need?</h2>
                        <ul class="note-list">
                            <li>Only the documents listed below can be requested online.</li>
                            <li>You may have up to <strong>3 pending requests</strong> at a time.</li>
                            <li>Documents you already requested are <strong>locked</strong> until that request is cancelled — they show their current status.</li>
                        </ul>

                        <div class="document-grid">
                            <?php foreach ($documentTypes as $dt):
                                $takenStatus = $activeDocTypes[$dt] ?? null; ?>
                                <?php if ($takenStatus): ?>
                                    <button type="button" class="doc-btn doc-btn-taken" disabled
                                        title="You already have a <?= htmlspecialchars($takenStatus) ?> request for this document.">
                                        <span class="doc-btn-label"><?php echo icon('file-text', 17); ?> <?= htmlspecialchars($dt) ?></span>
                                        <span class="doc-taken-badge"><span style="display:inline-flex;align-items:center;gap:2px;"><?php echo icon('check', 10); ?> <?= htmlspecialchars($takenStatus) ?></span></span>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="doc-btn"
                                        onclick="selectDocument('<?= htmlspecialchars($dt, ENT_QUOTES) ?>')">
                                        <span class="doc-btn-label"><?php echo icon('file-text', 17); ?> <?= htmlspecialchars($dt) ?></span>
                                    </button>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: details -->
                <div id="step-form" style="display:none;">
                    <div class="card">
                        <div class="selected-doc-banner" id="selected-doc-banner">
                            <span>Requesting: <strong id="selected-doc-label"></strong></span>
                            <button type="button" class="btn-restore" onclick="backToStep1()"><?php echo icon('arrow-left', 14); ?> Change document</button>
                        </div>

                        <div class="acct-form-grid">
                            <div class="acct-form-group">
                                <label>Student Name</label>
                                <input type="text" value="<?php echo $studentName; ?>" readonly class="input-readonly">
                            </div>
                            <div class="acct-form-group">
                                <label>Student ID</label>
                                <input type="text" value="<?php echo htmlspecialchars($student['student_id']); ?>" readonly class="input-readonly">
                            </div>
                            <div class="acct-form-group full" id="gl-field-wrap" style="display:none;">
                                <label>Grade Level <span class="req-star">*</span>
                                    <span class="upload-hint">required for this document</span>
                                </label>
                                <select name="grade_level" id="gl-input">
                                    <?php echo render_select_options($gradeOptions, $presetGrade, 'Select grade level…'); ?>
                                </select>
                                <span class="upload-hint">Saved to My Account automatically when you submit</span>
                            </div>
                            <div class="acct-form-group full" id="sy-field-wrap" style="display:none;">
                                <label>School Year Last Attended <span class="req-star">*</span></label>
                                <select name="school_year_last_attended" id="sy-input">
                                    <?php echo render_select_options($syOptions, $presetSy, 'Select school year…'); ?>
                                </select>
                                <span class="upload-hint">Saved to My Account automatically when you submit</span>
                            </div>
                            <div class="acct-form-group full">
                                <label>Purpose / Reason <span class="req-star">*</span></label>
                                <select name="purpose" id="purpose-input" onchange="toggleOtherPurpose()">
                                    <option value="">Select a purpose…</option>
                                    <?php foreach ($purposeOptions as $pOpt): ?>
                                        <option value="<?php echo htmlspecialchars($pOpt, ENT_QUOTES); ?>"><?php echo htmlspecialchars($pOpt); ?></option>
                                    <?php endforeach; ?>
                                    <option value="Other">Other — please specify</option>
                                </select>
                                <input type="text" name="purpose_other" id="purpose-other-input" maxlength="255"
                                    placeholder="Please specify your purpose" style="display:none; margin-top:8px;">
                            </div>

                            <div class="acct-form-group full">
                                <label>Valid ID
                                    <span class="upload-hint">JPG, PNG, GIF, WEBP or PDF · max 5 MB</span>
                                </label>
                                <div class="req-uploads-grid">
                                    <div class="req-upload-card" id="new-card-id"
                                        ondragover="cardDragOver(event,'new-card-id')"
                                        ondragleave="cardDragLeave('new-card-id')"
                                        ondrop="cardDrop(event,'new-card-id','new-id-input','new-id-name','new-id-preview')">
                                        <input type="file" name="id_photo" id="new-id-input"
                                            accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                                            onchange="cardFileSelected(this,'new-card-id','new-id-name','new-id-preview')">
                                        <button type="button" class="ruc-remove-btn"
                                            onclick="cardRemoveFile(event,'new-card-id','new-id-input','new-id-name','new-id-preview')"><?php echo icon('x', 11); ?></button>
                                        <span class="ruc-check"><?php echo icon('check', 12); ?></span>
                                        <span class="ruc-icon"><?php echo icon('id-card', 26); ?></span>
                                        <span class="ruc-title">Valid ID</span>
                                        <span class="ruc-required">Required</span>
                                        <p class="ruc-hint">School ID, government ID,<br>or any photo ID</p>
                                        <div class="ruc-preview" id="new-id-preview"></div>
                                        <div class="ruc-filename" id="new-id-name"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="button" class="save-btn" onclick="submitRequest()"><?php echo icon('check-circle', 16); ?> Submit Request</button>
                    </div>
                </div>
            </form>
        </div><!-- /view-request -->

        <!-- ══════ VIEW: MY ACCOUNT ══════ -->
        <div id="view-account" style="display:<?php echo $activeView === 'account' ? 'block' : 'none'; ?>;">
            <div class="view-head">
                <h1>My <span>Account</span></h1>
                <p>Keep your contact details up to date so the registrar can reach you.</p>
            </div>

            <?php if ($updateSuccess): ?>
                <div class="alert alert-success"><?php echo icon('check-circle', 15); ?> <?php echo htmlspecialchars($updateSuccess); ?></div>
            <?php endif; ?>
            <?php if ($updateError): ?>
                <div class="alert alert-danger"><?php echo icon('alert', 15); ?> <?php echo htmlspecialchars($updateError); ?></div>
            <?php endif; ?>

            <div class="card">
                <form method="POST" enctype="multipart/form-data" id="account-form">
                    <input type="hidden" name="update_account" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <!-- Profile photo -->
                    <div class="avatar-section">
                        <div class="avatar-ring">
                            <div class="account-avatar" id="avatar-display">
                                <?php if ($profilePhoto): ?>
                                    <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile photo"
                                        onerror="this.remove(); document.getElementById('avatar-fallback-initials').style.display='flex';">
                                    <span id="avatar-fallback-initials" style="display:none;"><?php echo $avatarInitials; ?></span>
                                <?php else: ?>
                                    <span id="avatar-fallback-initials"><?php echo $avatarInitials; ?></span>
                                <?php endif; ?>
                            </div>
                            <label for="profile-photo-input" class="avatar-edit-label" title="Change photo"><?php echo icon('pencil', 13); ?></label>
                        </div>
                        <input type="file" name="profile_photo" id="profile-photo-input"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            onchange="previewAvatar(this)">
                        <p class="avatar-hint">Click the pencil to change photo (JPG/PNG/GIF/WEBP · max 3 MB)</p>
                        <div class="avatar-new-preview" id="avatar-new-preview">
                            <img id="avatar-new-thumb" src="" alt="New photo preview">
                            <span id="avatar-new-name"></span>
                            <button type="button" class="remove-photo" onclick="clearAvatar()"><?php echo icon('x', 11); ?> Remove</button>
                        </div>
                    </div>

                    <!-- Identity — read-only, registrar-managed -->
                    <div class="acct-form-grid">
                        <div class="acct-form-group full">
                            <label>Name <?php echo icon('lock', 13); ?> <span class="account-role-badge-inline">Student</span></label>
                            <input type="text" id="name-display-input" value="<?php echo htmlspecialchars($studentName); ?>" readonly
                                class="readonly-field locked-field"
                                title="Updates automatically from First Name / Last Name below">
                            <span class="upload-hint">Updates automatically as you edit First Name / Last Name below</span>
                        </div>
                        <div class="acct-form-group">
                            <label>Student ID Number <?php echo icon('lock', 13); ?> <span class="locked-hint upload-hint">read-only</span></label>
                            <input type="text"
                                value="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>"
                                readonly class="readonly-field locked-field"
                                title="Your Student ID is system-assigned and cannot be changed">
                        </div>
                        <div class="acct-form-group">
                            <label>LRN (Learner Reference Number) <?php echo icon('lock', 13); ?> <span class="locked-hint upload-hint">read-only</span></label>
                            <input type="text"
                                value="<?php echo htmlspecialchars(!empty($student['lrn']) ? $student['lrn'] : '—'); ?>"
                                readonly class="readonly-field locked-field"
                                title="Your LRN is part of your official record — ask the Registrar to correct it">
                        </div>
                        <div class="acct-form-group">
                            <label>First Name <span class="req-star">*</span></label>
                            <input type="text" name="first_name" maxlength="100"
                                value="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="acct-form-group">
                            <label>Last Name <span class="req-star">*</span></label>
                            <input type="text" name="last_name" maxlength="100"
                                value="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="account-divider"></div>
                    <div class="form-section-label">Academic Information</div>
                    <div class="acct-form-grid">
                        <div class="acct-form-group">
                            <label>Grade Level</label>
                            <input type="text" name="grade_level" maxlength="20"
                                value="<?php echo htmlspecialchars(!empty($student['grade_level']) ? $student['grade_level'] : $displayGradeLevel); ?>"
                                placeholder="e.g. Grade 12">
                        </div>
                        <div class="acct-form-group">
                            <label>Last School Year Attended</label>
                            <input type="text" name="school_year_last_attended" maxlength="20"
                                value="<?php echo htmlspecialchars(!empty($student['school_year_last_attended']) ? $student['school_year_last_attended'] : $displaySchoolYear); ?>"
                                placeholder="e.g. 2024-2025">
                        </div>
                    </div>

                    <div class="account-divider"></div>
                    <div class="form-section-label">Contact Information</div>
                    <div class="acct-form-grid">
                        <div class="acct-form-group">
                            <label>Email <span class="req-star">*</span></label>
                            <input type="email" name="email"
                                value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" required>
                        </div>
                        <div class="acct-form-group">
                            <label>Phone Number</label>
                            <input type="text" name="contact" inputmode="numeric"
                                pattern="[0-9]{7,11}" title="7–11 digits, numbers only"
                                value="<?php echo htmlspecialchars($student['contact'] ?? ''); ?>"
                                placeholder="e.g. 09171234567" maxlength="11">
                        </div>
                    </div>

                    <div class="account-divider"></div>
                    <div class="form-section-label">Change Password <span class="hint-inline">(leave blank to keep your current password)</span></div>
                    <div class="acct-form-grid">
                        <div class="acct-form-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password" autocomplete="current-password">
                        </div>
                        <div class="acct-form-group">
                            <label>New Password <span class="hint-inline">(min. 8 characters)</span></label>
                            <input type="password" name="new_password" autocomplete="new-password">
                        </div>
                    </div>

                    <button type="submit" class="save-btn"><?php echo icon('save', 16); ?> Save Changes</button>
                </form>
            </div>
        </div><!-- /view-account -->

        <!-- ══════ VIEW: NOTIFICATIONS ══════ -->
        <div id="view-notifications" style="display:<?php echo $activeView === 'notifications' ? 'block' : 'none'; ?>;">
            <div class="view-head">
                <h1>Notifications</h1>
                <p>School announcements and updates about your requests.</p>
            </div>

            <div class="notif-toolbar">
                <div class="notif-filter-pills">
                    <button type="button" class="notif-pill active" onclick="filterNotifs('all', this)">
                        All <span class="notif-pill-count" id="count-all"><?php echo count($notifications); ?></span>
                    </button>
                    <button type="button" class="notif-pill" onclick="filterNotifs('announcement', this)">
                        <?php echo icon('megaphone', 14); ?> Announcements <span class="notif-pill-count" id="count-ann"><?php echo count(array_filter($notifications, fn($n) => $n['type'] === 'announcement')); ?></span>
                    </button>
                    <button type="button" class="notif-pill" onclick="filterNotifs('request', this)">
                        <?php echo icon('clipboard', 14); ?> Request Status <span class="notif-pill-count" id="count-req"><?php echo count(array_filter($notifications, fn($n) => $n['type'] === 'request')); ?></span>
                    </button>
                </div>
                <button type="button" class="btn-mark-all-read" onclick="markAllNotifsAsRead()"><?php echo icon('check', 13); ?> Mark all as read</button>
            </div>

            <div class="notif-list-container">
                <?php if (empty($notifications)): ?>
                    <div class="notif-empty-state">
                        <div class="notif-empty-icon"><?php echo icon('bell', 34); ?></div>
                        <h3>No notifications yet</h3>
                        <p>Announcements and request updates will show up here.</p>
                        <button type="button" class="btn-go-dash" onclick="showView('dashboard')">Back to Home</button>
                    </div>
                <?php else: ?>
                    <div class="notif-cards-grid" id="notifCardsGrid" style="display:contents;">
                        <?php foreach ($notifications as $n): ?>
                            <div class="notif-card notif-type-<?php echo htmlspecialchars($n['type']); ?>"
                                 data-type="<?php echo htmlspecialchars($n['type']); ?>"
                                 data-id="<?php echo htmlspecialchars($n['id']); ?>"
                                 data-time="<?php echo (int)$n['time']; ?>">

                                <div class="notif-card-icon-wrap <?php echo htmlspecialchars($n['badge_cls']); ?>">
                                    <span class="notif-card-icon"><?php echo icon($n['icon'], 18); ?></span>
                                </div>

                                <div class="notif-card-body">
                                    <div class="notif-card-header">
                                        <div class="notif-card-tags">
                                            <span class="notif-badge <?php echo htmlspecialchars($n['badge_cls']); ?>">
                                                <?php echo htmlspecialchars($n['badge']); ?>
                                            </span>
                                            <span class="notif-source-tag">
                                                <?php echo $n['type'] === 'announcement' ? 'School Announcement' : 'Request Update'; ?>
                                            </span>
                                        </div>
                                        <span class="notif-time"><?php echo htmlspecialchars($n['date_str']); ?></span>
                                    </div>

                                    <h3 class="notif-card-title"><?php echo htmlspecialchars($n['title']); ?></h3>

                                    <div class="notif-card-text">
                                        <?php echo nl2br(htmlspecialchars($n['body'])); ?>
                                    </div>

                                    <?php if (!empty($n['rejection_reason'])): ?>
                                        <div class="notif-rejection-callout">
                                            <strong>Registrar's note:</strong> <?php echo htmlspecialchars($n['rejection_reason']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="notif-card-actions">
                                        <?php if ($n['type'] === 'announcement'): ?>
                                            <button type="button" class="notif-action-btn primary" onclick="showView('dashboard')">
                                                View announcement <?php echo icon('chevron-right', 13); ?>
                                            </button>
                                        <?php elseif ($n['type'] === 'request'): ?>
                                            <?php if (!empty($n['e_cert'])): ?>
                                                <a href="../phpLogics/downloadCert.php?req_id=<?php echo (int)$n['req_id']; ?>" class="notif-action-btn success">
                                                    <?php echo icon('award', 14); ?> Download e-Certificate
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="notif-action-btn secondary" onclick="showView('requests')">
                                                Track request <?php echo icon('chevron-right', 13); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- /view-notifications -->

    </main>

    </div><!-- /.shell -->

    <footer class="footer">
        <div class="footer-inner">
            <img class="footer-logo" src="<?php echo site_logo_url(); ?>" alt="HEHMHS logo">
            <div class="footer-name">Hilario E. Hermosa Memorial High School</div>
            <div>© <?php echo date('Y'); ?> · Credential Request &amp; Tracking System</div>
        </div>
    </footer>

    <!-- ══ EDIT REQUEST MODAL ══ -->
    <div class="modal-overlay" id="editModal" onclick="closeEditModal(event)">
        <div class="modal-box modal-box--wide">
            <div class="modal-header">
                <h3>Edit Request</h3>
                <button type="button" class="modal-close" onclick="closeEditModal()"><?php echo icon('x', 13); ?></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="../phpLogics/editReq.php" enctype="multipart/form-data">
                    <input type="hidden" name="req_id" id="edit-req-id">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Document Type <span class="req-star">*</span></label>
                            <select name="document_type" id="edit-doc-type" required>
                                <?php foreach ($documentTypes as $dt): ?>
                                    <option value="<?php echo htmlspecialchars($dt); ?>"><?php echo htmlspecialchars($dt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group full" id="edit-gl-wrap" style="display:none;">
                            <label>Grade Level <span class="req-star">*</span></label>
                            <select name="grade_level" id="edit-gl-input">
                                <?php echo render_select_options($gradeOptions, '', 'Select grade level…'); ?>
                            </select>
                        </div>
                        <div class="form-group full" id="edit-sy-wrap" style="display:none;">
                            <label>School Year Last Attended <span class="req-star">*</span></label>
                            <select name="school_year_last_attended" id="edit-sy-input">
                                <?php echo render_select_options($syOptions, '', 'Select school year…'); ?>
                            </select>
                        </div>
                        <div class="form-group full">
                            <label>Purpose / Reason <span class="req-star">*</span></label>
                            <select name="purpose" id="edit-purpose" onchange="toggleOtherPurpose('edit-purpose-other-input')">
                                <option value="">Select a purpose…</option>
                                <?php foreach ($purposeOptions as $pOpt): ?>
                                    <option value="<?php echo htmlspecialchars($pOpt, ENT_QUOTES); ?>"><?php echo htmlspecialchars($pOpt); ?></option>
                                <?php endforeach; ?>
                                <option value="Other">Other — please specify</option>
                            </select>
                            <input type="text" name="purpose_other" id="edit-purpose-other-input" maxlength="255"
                                placeholder="Please specify your purpose" style="display:none; margin-top:8px;">
                        </div>
                        <div class="form-group full">
                            <label>Valid ID
                                <span class="upload-hint">upload a new file to replace · leave empty to keep current</span>
                            </label>
                            <div class="req-uploads-grid">
                                <div class="req-upload-card" id="edit-card-id"
                                    ondragover="cardDragOver(event,'edit-card-id')"
                                    ondragleave="cardDragLeave('edit-card-id')"
                                    ondrop="cardDrop(event,'edit-card-id','edit-id-input','edit-id-name','edit-id-preview')">
                                    <input type="file" name="id_photo" id="edit-id-input"
                                        accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                                        onchange="cardFileSelected(this,'edit-card-id','edit-id-name','edit-id-preview')">
                                    <button type="button" class="ruc-remove-btn"
                                        onclick="cardRemoveFile(event,'edit-card-id','edit-id-input','edit-id-name','edit-id-preview')"><?php echo icon('x', 11); ?></button>
                                    <span class="ruc-check"><?php echo icon('check', 12); ?></span>
                                    <span class="ruc-icon"><?php echo icon('id-card', 26); ?></span>
                                    <span class="ruc-title">Valid ID</span>
                                    <span class="ruc-optional">Optional</span>
                                    <p class="ruc-hint">Upload to replace current file</p>
                                    <div class="ruc-preview" id="edit-id-preview"></div>
                                    <div class="ruc-filename" id="edit-id-name"></div>
                                    <div class="ruc-existing-badge" id="edit-id-existing">
                                        <a id="edit-id-existing-link" href="#" target="_blank" onclick="event.stopPropagation()">
                                            <?php echo icon('paperclip', 13); ?> View current file
                                        </a>
                                        <div class="ruc-existing-replace-hint">Upload above to replace</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="edit_request" class="submit-btn"><?php echo icon('save', 16); ?> Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ══ PAGE-SPECIFIC STYLE FIXES (kept from previous design) ══ -->
    <style>
    .verify-badge { display: inline-block; font-size: .75rem; font-weight: 600; padding: 2px 8px; border-radius: 999px; margin-top: 4px; }
    .verify-badge-verified { background: #d1fae5; color: #065f46; }
    .verify-badge-pending  { background: #fef3c7; color: #92400e; }
    .verify-badge-none     { background: #f3f4f6; color: #6b7280; }

    .acct-form-group .acct-select {
        -webkit-appearance: none; appearance: none;
        width: 100%; box-sizing: border-box; font: inherit; color: inherit;
        background-color: #fff; border: 1px solid #d1d5db; border-radius: 8px;
        padding: 10px 36px 10px 12px; height: 44px; line-height: 1.2; cursor: pointer;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'><path d='M1 1l6 6 6-6' stroke='%23667085' stroke-width='2' fill='none' fill-rule='evenodd' stroke-linecap='round' stroke-linejoin='round'/></svg>");
        background-repeat: no-repeat; background-position: right 12px center;
    }

    .ruc-svg-id, .ruc-svg-check {
        display: inline-block; width: 28px; height: 28px;
        background-repeat: no-repeat; background-position: center; background-size: contain;
    }
    </style>

    <script>
    /* ── Read-only Name display ⇄ First/Last name fields ───────────
       The combined Name card is locked; it live-updates as the student
       edits First Name / Last Name below it. */
    (function () {
        var nameInp  = document.getElementById('name-display-input');
        var form     = document.getElementById('account-form');
        var firstInp = form ? form.querySelector('input[name="first_name"]') : null;
        var lastInp  = form ? form.querySelector('input[name="last_name"]') : null;
        if (!nameInp || !firstInp || !lastInp) return;

        function rebuild() {
            nameInp.value = (firstInp.value.trim() + ' ' + lastInp.value.trim()).trim();
        }
        firstInp.addEventListener('input', rebuild);
        lastInp.addEventListener('input', rebuild);
    })();

    function previewAvatar(input) {
        const file = input.files && input.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function (e) {
            const display = document.getElementById('avatar-display');
            display.innerHTML = '<img src="' + e.target.result + '" alt="Profile photo preview">';

            const thumb = document.getElementById('avatar-new-thumb');
            thumb.src = e.target.result;
            thumb.classList.add('visible');

            document.getElementById('avatar-new-name').textContent = file.name;
            document.getElementById('avatar-new-preview').classList.add('visible');
        };
        reader.readAsDataURL(file);
    }

    function clearAvatar() {
        document.getElementById('profile-photo-input').value = '';
        document.getElementById('avatar-new-preview').classList.remove('visible');
        document.getElementById('avatar-new-thumb').classList.remove('visible');
        document.getElementById('avatar-new-thumb').src = '';
        document.getElementById('avatar-new-name').textContent = '';
    }
    </script>

    <!-- Which documents ask for the Grade Level (from the DB, admin-managed
         per document) — must load BEFORE dashb.js, which reads it at parse time -->
    <script>window.DOC_GL_MAP = <?php echo json_encode($docGlMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script>window.ACTIVE_DOC_TYPES = <?php echo json_encode($activeDocTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="../assets/js/icons.js?v=2"></script>
    <script src="dashb.js?v=2"></script>
    <script src="../assets/js/offline-guard.js" defer></script>
    <script src="../assets/js/session-timeout.js" defer></script>
    <script>
        <?php if ($errorMsg): ?>showView('request');
        <?php endif; ?>
        <?php if ($updateError || $updateSuccess): ?>showView('account');
        <?php endif; ?>
        <?php if ($profilePhoto): ?>

            function clearAvatar() {
                document.getElementById('profile-photo-input').value = '';
                document.getElementById('avatar-new-preview').classList.remove('visible');
                document.getElementById('avatar-display').innerHTML =
                    '<img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" style="width:100%;height:100%;object-fit:cover;">';
            }
        <?php else: ?>

            function clearAvatar() {
                document.getElementById('profile-photo-input').value = '';
                document.getElementById('avatar-new-preview').classList.remove('visible');
                document.getElementById('avatar-display').innerHTML = '<span><?php echo $avatarInitials; ?></span>';
            }
        <?php endif; ?>
    </script>

</body>

</html>
