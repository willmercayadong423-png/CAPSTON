<?php
/* ── HEHMHS — Student Import Template (CSV) ────────────────────────
 * Admin only. GET (read-only, no CSRF needed — same rule as the
 * PDF/CSV report exports). Streams the fill-out template for the
 * Accounts → Import (CSV) feature.
 *
 * The header row matches EXACTLY what admin.js expects (it normalizes
 * "First Name" → first_name), and the sample rows demonstrate every
 * rule the import applies:
 *   • first_name, last_name, email are REQUIRED — that's the minimum
 *   • role: Student | Registrar | Admin   (blank → Student)
 *   • contact: OPTIONAL — 7–11 digits when supplied
 *   • lrn: optional, up to 12 digits
 *   • password: optional — blank + LRN with 5+ digits →
 *     "<current year>-<last 5 digits of LRN>"; blank + no usable LRN →
 *     random password (emailed to the account owner either way)
 *   • emails must be unique; rows that fail validation are skipped
 *     and reported by the import results table
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

    // Sample 1 — minimum viable row: name + email + LRN (contact blank,
    // password blank → auto-generated from LRN)
    . "Juan,Dela Cruz,Student,juan.delacruz@example.com,,123456789012,\n"

    // Sample 2 — student with contact and an explicit password
    . "Maria,Santos,Student,maria.santos@example.com,09181234567,987654321012,2026-Maria1\n"

    // Sample 3 — staff account (LRN blank, random password gets emailed)
    . "Anna,Reyes,Registrar,anna.reyes@example.com,09201234567,,MyPass123\n";

// ── Stream it ──────────────────────────────────────────────────────
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="HEHMHS_Student_Import_Template.csv"');
header('Cache-Control: private, no-cache, must-revalidate');
header('Content-Length: ' . strlen($csv));
echo $csv;
exit;
