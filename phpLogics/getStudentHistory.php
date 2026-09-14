<?php
require __DIR__ . "/auth.php";
include __DIR__ . "/../database/db.php";
header('Content-Type: application/json');

// ── Registrar only ────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'registrar') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$req_id = (int)($_GET['req_id'] ?? 0);
if (!$req_id) {
    echo json_encode(['error' => 'Missing request id']);
    exit;
}

// Resolve the student + document type behind this request
$find = $conn->prepare("SELECT user_id, document_type FROM document_requests WHERE id = ?");
$find->bind_param("i", $req_id);
$find->execute();
$row        = $find->get_result()->fetch_assoc();
$student_id = $row['user_id'] ?? null;
$cur_doc    = $row['document_type'] ?? null;
$find->close();

if (!$student_id) {
    echo json_encode(['error' => 'Request not found']);
    exit;
}

// ── Student profile (for the registrar verification panel) ────────
$stu = $conn->prepare(
    "SELECT student_id, first_name, last_name, email, contact, lrn,
            grade_level, strand, school_year_last_attended
     FROM users WHERE id = ?"
);
$stu->bind_param("i", $student_id);
$stu->execute();
$studentInfo = $stu->get_result()->fetch_assoc();
$stu->close();

// Has this student requested the SAME document before? (any status,
// any date — even long-completed requests count)
$same = $conn->prepare(
    "SELECT COUNT(*) AS c, MAX(date_requested) AS last
     FROM document_requests
     WHERE user_id = ? AND document_type = ? AND id != ?"
);
$same->bind_param("isi", $student_id, $cur_doc, $req_id);
$same->execute();
$sameRow     = $same->get_result()->fetch_assoc();
$sameCount   = (int)($sameRow['c'] ?? 0);
$sameLast    = !empty($sameRow['last']) ? date('M d, Y', strtotime($sameRow['last'])) : null;
$same->close();

// Full request history for that student (most recent first)
$hist = $conn->prepare(
    "SELECT id, document_type, status, cancelled_by, date_requested, date_released
     FROM document_requests
     WHERE user_id = ?
     ORDER BY date_requested DESC
     LIMIT 20"
);
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
            'unclaimed' => 'Unclaimed',
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
    'items'          => $items,
]);
