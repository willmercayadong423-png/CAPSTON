<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMS Month-End Report E-mail  (phpLogics/monthly_report_email.php)
 * ───────────────────────────────────────────────────────────────────
 * Automatically e-mails the system-generated Monthly Request Report
 * (PDF) to every ACTIVE Registrar and Admin account once a month, so
 * staff always receive the summary even when nobody has time to log
 * in and export it manually.
 *
 * WHEN IT FIRES — two interchangeable triggers (same code path):
 *
 *   1. Dashboard trigger (default — no server setup needed):
 *      maybe_send_monthly_report() is called on every Registrar /
 *      Admin dashboard load. It sends the report for the PREVIOUS
 *      (now complete) month at most once, then records the month in
 *      site_settings ('monthly_report_last_sent') so it can never
 *      double-send. Same self-throttle pattern as the daily overdue
 *      digest in registrarMainPage.php.
 *
 *   2. Scheduled-task trigger (true month-end automation):
 *      Windows Task Scheduler / cron runs cron_monthly_report.php
 *      daily; it calls the same throttled function, so the PDF goes
 *      out in the early minutes of each new month even if nobody
 *      logs in. See the header of cron_monthly_report.php.
 *
 * The report is sent on the FIRST day(s) of the NEW month rather than
 * the last day of the old one — that way the month is complete and no
 * request made on the final day is missed.
 *
 * Public functions:
 *   maybe_send_monthly_report(mysqli $conn): void
 *       → throttled: sends previous month's report once, then stops
 *   send_monthly_report_emails(mysqli $conn, string $month): array
 *       → ['ok' => bool, 'sent' => int, 'recipients' => int,
 *          'error' => ?string]  (force-send one month; used by the
 *         throttled wrapper and by the CLI catch-up/test mode)
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/mailer.php';       // mailer_init(), mailer_template()
require_once __DIR__ . '/report_pdf.php';   // render_monthly_report_pdf()
require_once __DIR__ . '/site_config.php';  // site_setting()

/* ═══════════════════════════════════════════════════════════════════
 * Throttled wrapper — safe to call on EVERY staff dashboard load.
 * Never throws: a mail failure must not break the dashboard.
 * ═══════════════════════════════════════════════════════════════════ */
function maybe_send_monthly_report(mysqli $conn): void
{
    try {
        // Target: the month that just ENDED (report must be complete).
        $target = date('Y-m', strtotime('first day of previous month'));

        // Already sent this month's report? → nothing to do.
        if (site_setting('monthly_report_last_sent', '') === $target) {
            return;
        }

        $result = send_monthly_report_emails($conn, $target);

        // Mark as sent only when at least one copy went out — if SMTP
        // is down, the next dashboard load / scheduled run retries.
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
    } catch (Throwable $e) {
        error_log('Monthly report e-mail failed: ' . $e->getMessage());
    }
}

/* ═══════════════════════════════════════════════════════════════════
 * Force-send the report PDF for ONE month to all active staff.
 * Individual failures don't stop the other recipients.
 * ═══════════════════════════════════════════════════════════════════ */
function send_monthly_report_emails(mysqli $conn, string $month): array
{
    if (!report_valid_month($month)) {
        return ['ok' => false, 'sent' => 0, 'recipients' => 0, 'error' => "Bad month key: {$month}"];
    }
    // Guard: never auto-send a report for a month that isn't over yet.
    if ($month >= date('Y-m')) {
        return ['ok' => false, 'sent' => 0, 'recipients' => 0, 'error' => 'Month is not complete yet'];
    }

    // ── Recipients: every active Registrar + Admin with an e-mail ──
    $recipients = [];
    $r = $conn->prepare(
        "SELECT u.id, u.email, CONCAT(u.first_name, ' ', u.last_name) AS name
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE LOWER(r.role_name) IN ('registrar', 'admin')
           AND LOWER(u.status) != 'archived'
           AND u.email IS NOT NULL AND u.email <> ''"
    );
    $r->execute();
    $recipients = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();

    if (empty($recipients)) {
        return ['ok' => false, 'sent' => 0, 'recipients' => 0, 'error' => 'No registrar/admin recipients'];
    }

    // ── The report itself (same PDF as the on-demand export) ──
    $label = date('F Y', strtotime($month . '-01'));
    try {
        $report = build_monthly_report_html($conn, $month);
        $pdf    = render_monthly_report_pdf($conn, $month);
    } catch (Throwable $e) {
        error_log("Monthly report PDF build failed ({$month}): " . $e->getMessage());
        return ['ok' => false, 'sent' => 0, 'recipients' => count($recipients), 'error' => 'PDF build failed'];
    }
    $stats = $report['stats'];

    $subject   = "Monthly Request Report — {$label} — HEHMS";
    $attName   = "HEHMS_Monthly_Report_{$month}.pdf";

    $sent    = 0;
    $lastErr = null;
    foreach ($recipients as $rcpt) {
        $name  = $rcpt['name'] ?: 'Staff';   // CONCAT may give '' for odd records
        $email = $rcpt['email'];
        try {
            $mail = mailer_init($subject, $email, $name);

            $greeting = "<p style='color:#333;'>Hello, <strong>" . htmlspecialchars($name) . "</strong>!</p>";
            $body = "<p style='color:#555;'>Here is the system-generated "
                  . "<strong>Monthly Request Report for {$label}</strong>, attached to this "
                  . "e-mail as a PDF. No action needed to receive it — the report is "
                  . "produced automatically at the end of every month.</p>"
                  . "<table style='width:100%; border-collapse: collapse; margin: 16px 0;'>"
                  . "<tr><td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Requests submitted</td>"
                  . "<td style='padding: 8px 12px; background: #f5f7fa; color: #4a6741; font-weight: bold;'>{$stats['submitted']}</td></tr>"
                  . "<tr><td style='padding: 8px 12px; font-weight: bold; color: #333;'>Documents released</td>"
                  . "<td style='padding: 8px 12px; color: #555;'>{$stats['released']}</td></tr>"
                  . "<tr><td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Rejected by registrar</td>"
                  . "<td style='padding: 8px 12px; background: #f5f7fa; color: #555;'>{$stats['rejected']}</td></tr>"
                  . "<tr><td style='padding: 8px 12px; font-weight: bold; color: #333;'>Cancelled by student</td>"
                  . "<td style='padding: 8px 12px; color: #555;'>{$stats['cancelled']}</td></tr>"
                  . "</table>"
                  . "<p style='color:#555;'>The attachment includes the full breakdown by document type, the list of "
                  . "requesting students, and all documents released. You can also export any month on demand from "
                  . "your dashboard's <strong>Reports</strong> section.</p>";

            $mail->Body    = mailer_template('HEHMS Monthly Report', $greeting . $body);
            $mail->AltBody = "HEHMS Monthly Request Report for {$label}: "
                . "{$stats['submitted']} submitted, {$stats['released']} released, "
                . "{$stats['rejected']} rejected, {$stats['cancelled']} cancelled. "
                . "Full PDF report attached.";
            $mail->addStringAttachment($pdf, $attName, 'base64', 'application/pdf');
            $mail->send();
            $sent++;
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
            error_log("Monthly report mail failed ({$email}): " . $lastErr);
        }
    }

    // ── Audit trail: one entry per automated batch (not per person) ──
    if (function_exists('audit_log')) {
        $names = implode(', ', array_column($recipients, 'email'));
        audit_log(
            $conn,
            'REPORT_EMAILED',
            'report',
            $month,
            "Automatic month-end report for {$label} e-mailed to {$sent}/"
            . count($recipients) . " staff account(s): " . mb_substr($names, 0, 300),
            ['id' => null, 'name' => 'System (scheduled)', 'role' => 'Admin']
        );
    }

    return [
        'ok'         => $sent > 0,
        'sent'       => $sent,
        'recipients' => count($recipients),
        'error'      => $lastErr,
    ];
}
