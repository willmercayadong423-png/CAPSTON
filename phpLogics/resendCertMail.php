<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMHS — Resend a released certificate's email  (resendCertMail.php)
 * ───────────────────────────────────────────────────────────────────
 * Registrar + Admin. For a RELEASED request that has an e-certificate,
 * re-sends the delivery email (same attachment as the original release).
 *
 * Use case: the original send failed (e.g. Gmail daily sending limit,
 * SMTP outage) — the audit trail recorded "email FAILED". This endpoint
 * retries WITHOUT touching the record: no re-release, no new PDF, no
 * re-dating. The student can also always download it from their
 * dashboard regardless.
 * ═══════════════════════════════════════════════════════════════════ */
require __DIR__ . "/auth.php";
include __DIR__ . "/../database/db.php";
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/audit.php';

// ── Registrar AND Admin (both manage requests) ─────────────────────
$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'registrar' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}
$back = ($role === 'admin') ? '../admin/adminDashboard.php?view=requests' : '../registrar/registrarMainPage.php?view=main';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken()) {
    header("Location: $back&error=csrf_fail");
    exit;
}

$req_id = (int)($_POST['req_id'] ?? 0);

$q = $conn->prepare(
    "SELECT dr.id, dr.status, dr.e_certificate, dt.name AS document_type,
            u.id AS student_pk, u.email, u.first_name, u.last_name
     FROM document_requests dr
     LEFT JOIN document_types dt ON dt.id = dr.document_type_id
     JOIN users u ON u.id = dr.user_id
     WHERE dr.id = ?"
);
$q->bind_param("i", $req_id);
$q->execute();
$row = $q->get_result()->fetch_assoc();
$q->close();

if (!$row || $row['status'] !== 'Released' || empty($row['e_certificate']) || empty($row['email'])) {
    header("Location: $back&resend_failed=1");
    exit;
}

$reqNo    = 'REQ-' . str_pad((string)$req_id, 4, '0', STR_PAD_LEFT);
$fullName = trim($row['first_name'] . ' ' . $row['last_name']);
$result   = send_certificate_email($row['email'], $fullName, $reqNo, $row['document_type'], $row['e_certificate']);

// ── Audit: reflect the REAL outcome ──
audit_log($conn, 'CERTIFICATE_RESENT', 'document_request', $reqNo,
    "{$row['document_type']} — certificate email re-sent to {$row['email']}: "
    . ($result['ok'] ? 'DELIVERED' : 'FAILED — ' . mb_substr((string)$result['error'], 0, 200)));

if ($result['ok']) {
    header("Location: $back&resent=1");
} else {
    error_log("Resend failed for {$reqNo}: " . $result['error']);
    header("Location: $back&resend_failed=1");
}
exit;
