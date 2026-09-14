<?php
/* ── HEHMS — Export registrar request tables to CSV ─────────────────
 * Role: registrar only. Read-only endpoint (GET), so no CSRF token is
 * required — the role check below is the gate.
 * ─────────────────────────────────────────────────────────────────── */
require __DIR__ . "/auth.php";
include(__DIR__ . "/../database/db.php");

if (strtolower($_SESSION['role'] ?? '') !== 'registrar') {
    http_response_code(403);
    exit('Forbidden');
}

$type = $_GET['type'] ?? 'main';

// Mirror the exact WHERE clauses used by registrarMainPage.php
// ("archived" = the History tab: Released + ALL cancelled rows)
if ($type === 'archived') {
    $where = "dr.status IN ('Released','Cancelled')";
    $filename = 'hehms_requests_history_' . date('Y-m-d') . '.csv';
} else {
    $type = 'main';
    $where = "dr.status NOT IN ('Released','Cancelled')";
    $filename = 'hehms_requests_active_' . date('Y-m-d') . '.csv';
}

$rows = $conn->query(
    "SELECT dr.*, CONCAT(s.first_name,' ',s.last_name) AS student_name
     FROM document_requests dr
     JOIN users s ON dr.user_id = s.id
     WHERE {$where}
     ORDER BY dr.date_requested DESC"
)->fetch_all(MYSQLI_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens accented characters correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Request ID', 'Student', 'Document', 'Purpose',
    'Date Requested', 'Status', 'Cancelled By',
    'Date Released', 'Payment Status',
]);

foreach ($rows as $r) {
    // Friendly status label (mirrors the badges used in the UI)
    $status = $r['status'];
    if ($status === 'Cancelled') {
        $status = match ($r['cancelled_by'] ?? '') {
            'registrar' => 'Rejected',
            'unclaimed' => 'Unclaimed',
            default     => 'Cancelled',
        };
    }

    fputcsv($out, [
        'REQ-' . str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT),
        $r['student_name'],
        $r['document_type'],
        $r['purpose'],
        $r['date_requested'],
        $status,
        $r['cancelled_by'] ?: '',
        $r['date_released'] ?: '',
        $r['payment_status'] ?? '',
    ]);
}

fclose($out);
exit;
