<?php
/* ── HEHMHS — Registrar's Monthly Request Report (PDF) ──────────────
 * Registrar + Admin. GET (read-only, no CSRF needed — same rule as
 * the CSV export). Produces a print-ready A4 summary of request
 * activity for the chosen month:
 *   1. Summary counters for the month
 *   2. Requests by document type (counts)
 *   3. Requesting students — one row per request (detail table)
 *   4. Documents released (grouped by type)
 *
 *   exportReport.php              → current month
 *   exportReport.php?month=2026-09→ a specific month
 *
 * The document itself is built by report_pdf.php so the automatic
 * month-end e-mail (monthly_report_email.php) sends the exact same
 * report staff can export here on demand. ─────────────────────────── */
require __DIR__ . "/auth.php";
include(__DIR__ . "/../database/db.php");

// Registrar AND Admin — the Admin dashboard adopted the registrar's
// request-management views, including the monthly report export.
$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'registrar' && $role !== 'admin') {
    header('Location: ' . home_for_role($_SESSION['role'] ?? ''));
    exit;
}
require_once __DIR__ . '/report_pdf.php';

// ── Period (default: current month) ──
$month = (string)($_GET['month'] ?? '');
if (!report_valid_month($month)) {
    $month = date('Y-m');                       // MySQL/Manila clock
}

// ── Build + stream the PDF ──
try {
    $pdf = render_monthly_report_pdf($conn, $month);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="hehmhs_report_' . $month . '.pdf"');
    header('Cache-Control: private, no-cache, must-revalidate');
    echo $pdf;
} catch (Throwable $e) {
    error_log('Report PDF failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Report generation failed. Check the error log.');
}
