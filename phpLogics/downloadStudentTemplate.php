<?php
/* ── HEHMS — Student Import Template (CSV) ────────────────────────
 * Admin only. GET (read-only, no CSRF needed — same rule as the
 * PDF/CSV report exports). Streams the fill-out template for the
 * Accounts → Import (CSV) feature.
 *
 * The header row matches EXACTLY what admin.js expects (it normalizes
 * "First Name" → first_name), and the sample rows demonstrate every
 * rule the import applies:
 *   • first_name, last_name, role, email, contact are REQUIRED
 *   • role: Student | Registrar | Admin   (blank → Student)
 *   • lrn: optional, up to 12 digits
 *   • password: optional — if blank and LRN has 5+ digits it is
 *     auto-generated as "<current year>-<last 5 digits of LRN>"
 *   • emails must be unique; rows that fail validation are skipped
 *     and reported by the import preview
 *
 * Admins: DELETE the three sample rows before importing real data.
 * ─────────────────────────────────────────────────────────────────── */
require __DIR__ . "/auth.php";
require_role('admin');

// ── Build the CSV ──────────────────────────────────────────────────
// UTF-8 BOM so Excel renders ñ / é correctly when the file is opened
// and re-saved. (The import parser trims the BOM away safely.)
$csv = "\xEF\xBB\xBF"
    . "First Name,Last Name,Role,Email,Contact,LRN,Password\n"

    // Sample 1 — student, blank password → auto-generated from LRN
    . "Juan,Dela Cruz,Student,juan.delacruz@example.com,09171234567,123456789012,\n"

    // Sample 2 — student with an explicit password
    . "Maria,Santos,Student,maria.santos@example.com,09181234567,987654321012,2026-Maria1\n"

    // Sample 3 — staff account (LRN left blank)
    . "Anna,Reyes,Registrar,anna.reyes@example.com,09201234567,,MyPass123\n";

// ── Stream it ──────────────────────────────────────────────────────
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="HEHMS_Student_Import_Template.csv"');
header('Cache-Control: private, no-cache, must-revalidate');
header('Content-Length: ' . strlen($csv));
echo $csv;
exit;
