<?php
/* ── HEHMS — Registrar's Monthly Request Report (PDF) ──────────────
 * Registrar only. GET (read-only, no CSRF needed — same rule as the
 * CSV export). Produces a print-ready A4 summary of request activity:
 *
 *   exportReport.php              → current month
 *   exportReport.php?month=2026-09→ a specific month
 * ─────────────────────────────────────────────────────────────────── */
require __DIR__ . "/auth.php";
include(__DIR__ . "/../database/db.php");

require_role('registrar');
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../phpLogics/site_config.php';

// ── Period ──
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');                       // MySQL/Manila clock
}
$start = $month . '-01 00:00:00';
$end   = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
$label = date('F Y', strtotime($start));

// ── Helper ──
function scalar(mysqli $conn, string $sql, array $params): string
{
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();
    $v = $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return (string)$v;
}

// ── This month's activity ──
$submitted = (int)scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE date_requested >= ? AND date_requested < ?", [$start, $end]);
$released  = (int)scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE date_released >= ? AND date_released < ?", [$start, $end]);
$rejected  = (int)scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE cancelled_by = 'registrar' AND updated_at >= ? AND updated_at < ?", [$start, $end]);
$cancelled = (int)scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE cancelled_by = 'student' AND updated_at >= ? AND updated_at < ?", [$start, $end]);

// ── Current backlog (all-time snapshot) ──
$pending    = (int)scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE status = 'Pending'", []);
$processing = (int)scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE status = 'Processing'", []);

// ── Breakdown by document type (this month) ──
$byType = [];
$t = $conn->prepare(
    "SELECT document_type, COUNT(*) AS cnt
     FROM document_requests
     WHERE date_requested >= ? AND date_requested < ?
     GROUP BY document_type ORDER BY cnt DESC"
);
$t->bind_param("ss", $start, $end);
$t->execute();
$byType = $t->get_result()->fetch_all(MYSQLI_ASSOC);
$t->close();

// ── Releases this month, GROUPED BY DOCUMENT TYPE (count) ──────
// Compact summary instead of one row per release — the table can
// never outgrow the page no matter how many students request.
// No student names and no dates (names stay in the on-screen tables;
// the total released count is already in the Summary above).
$rel = $conn->prepare(
    "SELECT document_type, COUNT(*) AS cnt
     FROM document_requests
     WHERE date_released >= ? AND date_released < ?
     GROUP BY document_type
     ORDER BY cnt DESC, document_type"
);
$rel->bind_param("ss", $start, $end);
$rel->execute();
$releases = $rel->get_result()->fetch_all(MYSQLI_ASSOC);
$rel->close();

$reqNo = fn(int $id): string => 'REQ-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);

// ── Build the HTML ──
$rows = '';
$totalByType = 0;
foreach ($byType as $b) {
    $totalByType += (int)$b['cnt'];
    $rows .= "<tr><td>" . htmlspecialchars($b['document_type']) . "</td><td class='n'>{$b['cnt']}</td></tr>";
}
if ($rows === '') {
    $rows = "<tr><td colspan='2' class='none'>No requests were submitted this month.</td></tr>";
} else {
    $rows .= "<tr class='total'><td>Total</td><td class='n'>{$totalByType}</td></tr>";
}

$relRows = '';
$totalReleased = 0;
foreach ($releases as $r) {
    $totalReleased += (int)$r['cnt'];
    $relRows .= "<tr><td>" . htmlspecialchars($r['document_type']) . "</td><td class='n'>{$r['cnt']}</td></tr>";
}
if ($relRows === '') {
    $relRows = "<tr><td colspan='2' class='none'>No documents were released this month.</td></tr>";
} else {
    $relRows .= "<tr class='total'><td>Total</td><td class='n'>{$totalReleased}</td></tr>";
}

$schoolName = site_setting('school_name', 'Hilario E. Hermosa Memorial High School');

$html = "
<html>
<head>
<meta charset='UTF-8'>
<style>
    body   { font-family: 'DejaVu Serif', serif; color: #2b332b; font-size: 12px; }
    .head  { text-align: center; border-bottom: 3px double #4a6741; padding-bottom: 10px; margin-bottom: 16px; }
    .head h1 { font-size: 17px; margin: 0; color: #33402f; }
    .head p  { margin: 3px 0 0; font-size: 11px; color: #667; }
    h2     { font-size: 13px; color: #4a6741; margin: 18px 0 7px; }
    table  { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #cfd8cf; padding: 6px 9px; text-align: left; font-size: 11px; }
    th     { background: #eef4ee; }
    .n     { text-align: center; }
    .none  { text-align: center; color: #888; font-style: italic; }
    .total td { font-weight: bold; background: #f2f7f2; border-top: 1.5px solid #4a6741; color: #33402f; }
    .stats td { font-size: 12px; padding: 8px 10px; }
    .stats td:first-child { background: #f7faf7; font-weight: bold; width: 55%; }
    .foot  { margin-top: 22px; text-align: center; font-size: 10px; color: #99a; }
</style>
</head>
<body>
    <div class='head'>
        <h1>{$schoolName}</h1>
        <p>Registrar's Office — Monthly Request Report</p>
        <p><strong>Period: {$label}</strong> · Generated " . date('M d, Y g:i A') . "</p>
    </div>

    <h2>Summary — {$label}</h2>
    <table class='stats'>
        <tr><td>Requests submitted</td><td class='n'>{$submitted}</td></tr>
        <tr><td>Documents released (with e-certificate)</td><td class='n'>{$released}</td></tr>
        <tr><td>Requests rejected by the registrar</td><td class='n'>{$rejected}</td></tr>
        <tr><td>Cancelled by student</td><td class='n'>{$cancelled}</td></tr>
        <tr><td>Currently waiting (Pending)</td><td class='n'>{$pending}</td></tr>
        <tr><td>Currently in process (Processing)</td><td class='n'>{$processing}</td></tr>
    </table>

    <h2>Requests by Document Type — {$label}</h2>
    <table>
        <tr><th>Document Type</th><th style='width:80px'>Count</th></tr>
        {$rows}
    </table>

    <h2>Documents Released — {$label}</h2>
    <table>
        <tr><th>Document</th><th style='width:80px'>Count</th></tr>
        {$relRows}
    </table>

    <p class='foot'>HEHMS Credential Request &amp; Tracking System — confidential registrar report</p>
</body>
</html>";

// ── Render the PDF ──
try {
    $dompdf = new Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'defaultFont'     => 'DejaVu Serif',
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="hehms_report_' . $month . '.pdf"');
    header('Cache-Control: private, no-cache, must-revalidate');
    echo $dompdf->output();
} catch (Throwable $e) {
    error_log('Report PDF failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Report generation failed. Check the error log.');
}
