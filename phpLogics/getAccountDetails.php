<?php
/* ── HEHMHS — Admin: full account details + request history ──────────
 * GET ?uid=N  → one account (users + student_profiles + role) together
 *               with ALL of its document requests, ready for the
 *               Accounts → "View Account" modal.
 *
 * Admin only (same rule as getStud.php / getStudDocs.php). Read-only,
 * so no CSRF token is needed (GET, no state change).
 * NOTE: the password hash is deliberately never selected.
 * ───────────────────────────────────────────────────────────────────── */
require __DIR__ . "/auth.php";
include __DIR__ . "/../database/db.php";
header('Content-Type: application/json');

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$uid = (int)($_GET['uid'] ?? 0);
if ($uid <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing account id']);
    exit;
}

/* ── The account itself (role + academic profile joined in) ─────── */
$acct = $conn->prepare("
    SELECT u.id, u.first_name, u.last_name, u.email, u.contact, u.status,
           u.created_at, u.profile_photo,
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
$acct->bind_param("i", $uid);
$acct->execute();
$account = $acct->get_result()->fetch_assoc();
$acct->close();

if (!$account) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Account not found']);
    exit;
}

/* ── Every document request this account ever filed ─────────────── */
$reqs = $conn->prepare("
    SELECT dr.id, dt.name AS document_type, dr.purpose, dr.status,
           dr.cancelled_by, dr.rejection_reason,
           dr.date_requested, dr.date_released,
           dr.grade_level, dr.school_year_last_attended,
           dr.id_photo, dr.e_certificate
    FROM document_requests dr
    LEFT JOIN document_types dt ON dt.id = dr.document_type_id
    WHERE dr.user_id = ?
    ORDER BY dr.date_requested DESC, dr.id DESC
");
$reqs->bind_param("i", $uid);
$reqs->execute();
$requests = $reqs->get_result()->fetch_all(MYSQLI_ASSOC);
$reqs->close();

/* ── Status summary chips for the modal header ──────────────────── */
$counts = [
    'total'      => count($requests),
    'pending'    => 0,
    'processing' => 0,
    'released'   => 0,
    'rejected'   => 0,   // cancelled by the registrar
    'cancelled'  => 0,   // cancelled by the student
];
foreach ($requests as $r) {
    if ($r['status'] === 'Pending')          $counts['pending']++;
    if ($r['status'] === 'Processing')       $counts['processing']++;
    if ($r['status'] === 'Released')         $counts['released']++;
    if ($r['status'] === 'Ready for Pickup') $counts['processing']++;  // legacy
    if ($r['status'] === 'Cancelled') {
        if (trim((string)$r['cancelled_by']) === 'registrar') $counts['rejected']++;
        else                                                  $counts['cancelled']++;
    }
}

echo json_encode([
    'success'  => true,
    'account'  => $account,
    'requests' => $requests,
    'counts'   => $counts,
]);
