<?php
/* ── HEHMS — Certificate preview / defaults (registrar only) ─────────
 * GET  ?req_id=N            → streams the generated PDF inline
 *      &title=&body=&officer_name=&officer_title=&cert_date=&remarks=
 *                           → same, but with the registrar's edits applied
 * GET  ?req_id=N&format=json→ returns the editable defaults for prefill
 * ───────────────────────────────────────────────────────────────────── */
require __DIR__ . "/auth.php";
include(__DIR__ . "/../database/db.php");
require_once __DIR__ . "/certificate.php";

// auth.php sends X-Frame-Options: DENY, which would make Chrome refuse to
// render this PDF inside the registrar's live-preview iframe. SAMEORIGIN
// still blocks all external sites from framing it — only our own pages can.
header('X-Frame-Options: SAMEORIGIN');

if (strtolower($_SESSION['role'] ?? '') !== 'registrar') {
    http_response_code(403);
    exit('Forbidden');
}

$req_id = (int)($_GET['req_id'] ?? 0);
$row = certificate_fetch($conn, $req_id);

if (!$row) {
    http_response_code(404);
    exit('Request not found');
}

// The registrar's own name is the default signing officer
$me = $conn->prepare("SELECT first_name, last_name FROM students WHERE id = ?");
$me->bind_param("i", $_SESSION['user_id']);
$me->execute();
$reg = $me->get_result()->fetch_assoc();
$me->close();
$officerDefault = $reg ? trim($reg['first_name'] . ' ' . $reg['last_name']) : '';

$edits = [
    'title'         => trim((string)($_GET['title'] ?? '')),
    'body'          => trim((string)($_GET['body'] ?? '')),
    'officer_name'  => trim((string)($_GET['officer_name'] ?? '')),
    'officer_title' => trim((string)($_GET['officer_title'] ?? '')),
    'cert_date'     => trim((string)($_GET['cert_date'] ?? '')),
    'remarks'       => trim((string)($_GET['remarks'] ?? '')),
];

/* ── JSON mode: prefill data for the release modal ── */
if (($_GET['format'] ?? '') === 'json') {
    $defaults = certificate_defaults($row, $officerDefault);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'            => true,
        'req_id'        => $req_id,
        'req_no'        => 'REQ-' . str_pad((string) $req_id, 4, '0', STR_PAD_LEFT),
        'student_name'  => trim($row['first_name'] . ' ' . $row['last_name']),
        'doc_type'      => $row['document_type'],
        'defaults'      => $defaults,
    ]);
    exit;
}

/* ── PDF mode: stream the generated certificate inline ── */
$d = certificate_build_data($row, $edits, $officerDefault);

try {
    $pdf = generate_certificate_pdf($d);
} catch (Throwable $e) {
    error_log("Certificate preview failed (REQ-{$req_id}): " . $e->getMessage());
    http_response_code(500);
    exit('Certificate generation failed. Check the error log.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="preview.pdf"');
header('Cache-Control: private, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
