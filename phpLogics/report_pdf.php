<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMHS Monthly Report builder  (phpLogics/report_pdf.php)
 * ───────────────────────────────────────────────────────────────────
 * Single source of truth for the Monthly Request Report PDF. Two very
 * different callers share the exact same document:
 *
 *   1. exportReport.php        — on-demand browser view (registrar/admin
 *                                 clicks "Export Report")
 *   2. monthly_report_email.php — automatic month-end e-mail to every
 *                                 active Registrar + Admin account
 *
 * Public functions:
 *   build_monthly_report_html(mysqli $conn, string $month): array
 *       → ['month' => 'YYYY-MM', 'label' => 'September 2026',
 *          'html' => '<html>…', 'stats' => ['submitted' => n, …]]
 *   render_monthly_report_pdf(mysqli $conn, string $month): string
 *       → raw PDF bytes (dompdf)
 *
 * $month format: 'YYYY-MM'. The report is identical no matter who asks
 * for it — deterministic input, deterministic output.
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/site_config.php';

/* ── Validate / normalize a 'YYYY-MM' period key ─────────────────── */
function report_valid_month(string $month): bool
{
    return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
}

/* ── Tiny scalar helper (COUNT(*) etc.) ──────────────────────────── */
function report_scalar(mysqli $conn, string $sql, array $params): string
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

/* ═══════════════════════════════════════════════════════════════════
 * Build the full report HTML + headline stats for one month.
 * ═══════════════════════════════════════════════════════════════════ */
function build_monthly_report_html(mysqli $conn, string $month): array
{
    if (!report_valid_month($month)) {
        throw new InvalidArgumentException("Bad month key: {$month}");
    }

    $start = $month . '-01 00:00:00';
    $end   = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
    $label = date('F Y', strtotime($start));

    // ── This month's activity ──
    $submitted = (int)report_scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE date_requested >= ? AND date_requested < ?", [$start, $end]);
    $released  = (int)report_scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE date_released >= ? AND date_released < ?", [$start, $end]);
    $rejected  = (int)report_scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE cancelled_by = 'registrar' AND updated_at >= ? AND updated_at < ?", [$start, $end]);
    $cancelled = (int)report_scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE cancelled_by = 'student' AND updated_at >= ? AND updated_at < ?", [$start, $end]);

    // ── Current backlog (all-time snapshot) ──
    $pending    = (int)report_scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE status = 'Pending'", []);
    $processing = (int)report_scalar($conn, "SELECT COUNT(*) FROM document_requests WHERE status = 'Processing'", []);

    // ── Breakdown by document type (this month) ──
    $t = $conn->prepare(
        "SELECT dt.name AS document_type, COUNT(*) AS cnt
         FROM document_requests dr
         JOIN document_types dt ON dt.id = dr.document_type_id
         WHERE dr.date_requested >= ? AND dr.date_requested < ?
         GROUP BY dt.name ORDER BY cnt DESC"
    );
    $t->bind_param("ss", $start, $end);
    $t->execute();
    $byType = $t->get_result()->fetch_all(MYSQLI_ASSOC);
    $t->close();

    // ── Releases this month, GROUPED BY DOCUMENT TYPE (count) ──────
    $rel = $conn->prepare(
        "SELECT dt.name AS document_type, COUNT(*) AS cnt
         FROM document_requests dr
         JOIN document_types dt ON dt.id = dr.document_type_id
         WHERE dr.date_released >= ? AND dr.date_released < ?
         GROUP BY dt.name
         ORDER BY cnt DESC, dt.name"
    );
    $rel->bind_param("ss", $start, $end);
    $rel->execute();
    $releases = $rel->get_result()->fetch_all(MYSQLI_ASSOC);
    $rel->close();

    // ── Students who submitted a request this month (detail table) ──
    $stu = $conn->prepare(
        "SELECT r.id, sp.student_id, u.first_name, u.last_name,
                dt.name AS document_type, r.date_requested, r.status
         FROM document_requests r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN student_profiles sp ON sp.user_id = u.id
         JOIN document_types dt ON dt.id = r.document_type_id
         WHERE r.date_requested >= ? AND r.date_requested < ?
         ORDER BY r.date_requested ASC, r.id ASC"
    );
    $stu->bind_param("ss", $start, $end);
    $stu->execute();
    $studentRows = $stu->get_result()->fetch_all(MYSQLI_ASSOC);
    $stu->close();

    // ── Document-type table rows ──
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

    // ── Student detail rows ──
    $stuRows = '';
    $distinctStudents = [];
    foreach ($studentRows as $i => $s) {
        $distinctStudents[$s['student_id'] ?? $s['id']] = true;
        $name   = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
        $when   = date('M d, Y', strtotime($s['date_requested']));
        $stuRows .= "<tr>"
            . "<td class='n'>" . ($i + 1) . "</td>"
            . "<td>" . htmlspecialchars($s['student_id'] ?? '—') . "</td>"
            . "<td>" . htmlspecialchars($name !== '' ? $name : '—') . "</td>"
            . "<td>" . htmlspecialchars($s['document_type']) . "</td>"
            . "<td>" . $when . "</td>"
            . "<td>" . htmlspecialchars($s['status']) . "</td>"
            . "</tr>";
    }
    if ($stuRows === '') {
        $stuRows = "<tr><td colspan='6' class='none'>No students submitted a request this month.</td></tr>";
    } else {
        $stuRows .= "<tr class='total'><td colspan='4'>Total — " . count($distinctStudents) . " student(s), "
            . count($studentRows) . " request(s)</td><td></td><td></td></tr>";
    }

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
    th.stu { background: #e2ecdf; }
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

    <h2>Requesting Students — {$label}</h2>
    <table>
        <tr>
            <th class='stu n' style='width:34px'>No.</th>
            <th class='stu' style='width:88px'>Student ID</th>
            <th class='stu'>Name</th>
            <th class='stu'>Document Requested</th>
            <th class='stu' style='width:78px'>Date</th>
            <th class='stu' style='width:88px'>Status</th>
        </tr>
        {$stuRows}
    </table>

    <h2>Documents Released — {$label}</h2>
    <table>
        <tr><th>Document</th><th style='width:80px'>Count</th></tr>
        {$relRows}
    </table>

    <p class='foot'>HEHMHS Credential Request &amp; Tracking System — confidential registrar report</p>
</body>
</html>";

    return [
        'month'  => $month,
        'label'  => $label,
        'html'   => $html,
        'stats'  => [
            'submitted' => $submitted,
            'released'  => $released,
            'rejected'  => $rejected,
            'cancelled' => $cancelled,
        ],
    ];
}

/* ═══════════════════════════════════════════════════════════════════
 * Render the report HTML to raw PDF bytes (dompdf, A4 portrait).
 * ═══════════════════════════════════════════════════════════════════ */
function render_monthly_report_pdf(mysqli $conn, string $month): string
{
    $report = build_monthly_report_html($conn, $month);

    $dompdf = new Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'defaultFont'     => 'DejaVu Serif',
    ]);
    $dompdf->loadHtml($report['html'], 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}
