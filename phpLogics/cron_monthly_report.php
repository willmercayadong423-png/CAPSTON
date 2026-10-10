<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMHS Month-End Report — CLI runner  (phpLogics/cron_monthly_report.php)
 * ───────────────────────────────────────────────────────────────────
 * Command-line-only entry point for the automatic monthly report
 * e-mail (see monthly_report_email.php). Runs OUTSIDE the web session
 * — no login, no CSRF — so it must NEVER be reachable through a
 * browser (guarded below).
 *
 * HOW TO SCHEDULE (Windows / Laragon — recommended: daily at 00:30):
 *   Option A — Task Scheduler GUI:
 *     Program:   C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe
 *     Arguments: "C:\laragon\www\NEW UPCAP\phpLogics\cron_monthly_report.php"
 *     Trigger:   Daily, 00:30
 *   Option B — one-liner (Admin Command Prompt):
 *     schtasks /create /tn "HEHMHS Monthly Report" /sc daily /st 00:30 ^
 *       /tr "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe \"C:\laragon\www\NEW UPCAP\phpLogics\cron_monthly_report.php\""
 *
 * Linux deployment equivalent (crontab):
 *   30 0 * * * /usr/bin/php /var/www/hehmhs/phpLogics/cron_monthly_report.php
 *
 * The run is SELF-THROTTLED (site_settings 'monthly_report_last_sent'):
 * scheduled daily, it quietly does nothing all month and e-mails the
 * completed previous month's report on the first run of a new month.
 *
 * USAGE:
 *   php cron_monthly_report.php            → throttled (previous month)
 *   php cron_monthly_report.php 2026-09    → force-send a PAST month
 *   php cron_monthly_report.php --status   → show throttle state only
 * ═══════════════════════════════════════════════════════════════════ */

/* ── Web-access guard — MUST run before any include ──────────────── */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require __DIR__ . '/../database/db.php';            // $conn (+ session, harmless in CLI)
require __DIR__ . '/site_config.php';              // site_setting()
require __DIR__ . '/audit.php';                    // audit_log()
require __DIR__ . '/monthly_report_email.php';     // the sender

$target = date('Y-m', strtotime('first day of previous month'));
$lastSent = site_setting('monthly_report_last_sent', '(never)');

echo "HEHMHS month-end report runner " . date('Y-m-d H:i:s') . "\n";
echo "Previous month: {$target} · last report e-mailed: {$lastSent}\n";

$arg = $argv[1] ?? '';

if ($arg === '--status') {
    echo ($lastSent === $target)
        ? "Status: report for {$target} already sent. Nothing to do.\n"
        : "Status: report for {$target} NOT sent yet — next run (or staff login) will send it.\n";
    exit(0);
}

if ($arg !== '') {
    // Force-send a specific PAST month (catch-up / re-send after an
    // outage). send_monthly_report_emails() refuses incomplete months.
    if (!report_valid_month($arg)) {
        fwrite(STDERR, "Invalid month '{$arg}' — expected YYYY-MM (e.g. 2026-09).\n");
        exit(1);
    }
    echo "Force mode: sending report for {$arg}...\n";
    $result = send_monthly_report_emails($conn, $arg);
} else {
    if ($lastSent === $target) {
        echo "Already sent for {$target}. Nothing to do.\n";
        exit(0);
    }
    echo "Sending monthly report for {$target}...\n";
    $result = send_monthly_report_emails($conn, $target);

    // Mark the month so daily runs (and dashboard loads) don't re-send.
    if ($result['sent'] > 0) {
        $mark = $conn->prepare(
            "INSERT INTO site_settings (setting_key, setting_value)
             VALUES ('monthly_report_last_sent', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $mark->bind_param('s', $target);
        $mark->execute();
        $mark->close();
    }
}

if ($result['ok']) {
    echo "Done — e-mailed {$result['sent']}/{$result['recipients']} staff account(s).\n";
    exit(0);
}

fwrite(STDERR, "FAILED — sent {$result['sent']}/{$result['recipients']}"
    . ($result['error'] ? " · {$result['error']}" : '') . "\n");
exit(1);
