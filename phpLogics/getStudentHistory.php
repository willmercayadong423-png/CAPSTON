<?php
require __DIR__ . "/auth.php";
include __DIR__ . "/../database/db.php";
header('Content-Type: application/json');

// ── Registrar AND Admin (the Admin dashboard mirrors the registrar's request UI)
$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'registrar' && $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$req_id = (int)($_GET['req_id'] ?? 0);
if (!$req_id) {
    echo json_encode(['error' => 'Missing request id']);
    exit;
}

/* ── First review locks student edits ────────────────────────────
 * Opening the details modal = "the office is reviewing exactly these
 * details" — from now on the student may no longer EDIT the request
 * (cancel stays allowed). COALESCE keeps the FIRST view timestamp.
 * Runs BEFORE the SELECT below so the returned reviewed_at already
 * reflects the current view. Only Pending requests are marked. */
$lock = $conn->prepare(
    "UPDATE document_requests
        SET registrar_viewed_at = COALESCE(registrar_viewed_at, NOW())
      WHERE id = ? AND status = 'Pending'"
);
$lock->bind_param('i', $req_id);
$lock->execute();
$lock->close();
// Resolve the student + document type behind this request (plus the
// grade level / school year the student entered on THIS request)
$find = $conn->prepare("
    SELECT dr.user_id, dr.document_type_id, dt.name AS document_type,
           dr.grade_level, dr.school_year_last_attended, dr.registrar_viewed_at
    FROM document_requests dr
    LEFT JOIN document_types dt ON dt.id = dr.document_type_id
    WHERE dr.id = ?
");
$find->bind_param("i", $req_id);
$find->execute();
$row        = $find->get_result()->fetch_assoc();
$student_id = $row['user_id'] ?? null;
$cur_doc    = $row['document_type'] ?? null;
$curTypeId  = (int)($row['document_type_id'] ?? 0);   // FK value for the same-type count
$reviewedAt = $row['registrar_viewed_at'] ?? null;   // capture NOW — $row is reused below
$reqAcademic = [
    'grade_level'               => $row['grade_level'] ?? null,
    'school_year_last_attended' => $row['school_year_last_attended'] ?? null,
];
$find->close();


if (!$student_id) {
    echo json_encode(['error' => 'Request not found']);
    exit;
}

// ── Student profile (for the registrar verification panel) ────────
$stu = $conn->prepare("
    SELECT sp.student_id, u.first_name, u.last_name, u.email, u.contact, sp.lrn
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = ?
");
$stu->bind_param("i", $student_id);
$stu->execute();
$studentInfo = $stu->get_result()->fetch_assoc();
$stu->close();

// Has this student requested the SAME document before? (any status,
// any date — even long-completed requests count)
$same = $conn->prepare(
    "SELECT COUNT(*) AS c, MAX(date_requested) AS last
     FROM document_requests
     WHERE user_id = ? AND document_type_id = ? AND id != ?"
);
$same->bind_param("iii", $student_id, $curTypeId, $req_id);
$same->execute();
$sameRow     = $same->get_result()->fetch_assoc();
$sameCount   = (int)($sameRow['c'] ?? 0);
$sameLast    = !empty($sameRow['last']) ? date('M d, Y', strtotime($sameRow['last'])) : null;
$same->close();

// Full request history for that student (most recent first)
$hist = $conn->prepare("
    SELECT dr.id, dt.name AS document_type, dr.status, dr.cancelled_by,
           dr.date_requested, dr.date_released
    FROM document_requests dr
    LEFT JOIN document_types dt ON dt.id = dr.document_type_id
    WHERE dr.user_id = ?
    ORDER BY dr.date_requested DESC
    LIMIT 20
");
$hist->bind_param("i", $student_id);
$hist->execute();
$res = $hist->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $row['is_current']     = ((int)$row['id'] === $req_id);
    $row['same_doc']       = (!$row['is_current'] && $row['document_type'] === $cur_doc);
    $row['date_requested'] = date('M d, Y', strtotime($row['date_requested']));
    $row['date_released']  = !empty($row['date_released'])
        ? date('M d, Y', strtotime($row['date_released']))
        : null;

    // Friendly status label (mirrors the badges used elsewhere)
    if ($row['status'] === 'Cancelled') {
        $row['status_label'] = match ($row['cancelled_by'] ?? '') {
            'registrar' => 'Rejected',
            default     => 'Cancelled',
        };
    } else {
        $row['status_label'] = $row['status'];
    }

    $items[] = $row;
}
$hist->close();

echo json_encode([
    'total'          => count($items),
    'current_doc'    => $cur_doc,
    'same_doc_count' => $sameCount,
    'same_doc_last'  => $sameLast,
    'student'        => $studentInfo,
    'request'        => $reqAcademic,
    'reviewed_at'    => !empty($reviewedAt)
        ? date('M d, Y g:i A', strtotime($reviewedAt))
        : null,
    'items'          => $items,
]);
