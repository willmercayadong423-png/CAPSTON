<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMS Mail API  (phpLogics/mailer.php)
 * ───────────────────────────────────────────────────────────────────
 * Single reusable entry point for every email the system sends.
 * SMTP credentials come from database/config.php (SMTP_* constants),
 * so no passwords live in this file.
 *
 * Public functions (the "API"):
 *   send_status_email($toEmail, $toName, $reqNo, $docType, $status, $reason = null)
 *       → notifies a student that their request status changed
 *         (Pending = received/confirmation · Processing / Released /
 *         legacy "Ready for Pickup" · Rejected = registrar rejection,
 *         $reason carries the registrar's written reason)
 *   send_certificate_email($toEmail, $toName, $reqNo, $docType, $certWebPath)
 *       → delivers the released e-certificate as an email attachment
 *
 * Every function returns:  ['ok' => bool, 'error' => ?string]
 * Callers should log the error but never expose it to users.
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

/* ── Low-level: build a pre-configured SMTP mailer ───────────────── */
function mailer_init(string $subject, string $toEmail, string $toName): PHPMailer\PHPMailer\PHPMailer
{
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'base64';
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    return $mail;
}

/* ── Shared branded HTML wrapper (matches all HEHMS emails) ──────── */
function mailer_template(string $heading, string $bodyHtml): string
{
    return "
        <div style='font-family: Arial, sans-serif; max-width: 520px; margin: auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
            <div style='background: #4a6741; padding: 24px; text-align: center;'>
                <h2 style='color: #fff; margin: 0;'>{$heading}</h2>
            </div>
            <div style='padding: 28px;'>{$bodyHtml}</div>
            <div style='background: #f5f7fa; padding: 14px; text-align: center;'>
                <p style='color: #aaa; font-size: 12px; margin: 0;'>HEHMS Student Records System &mdash; Confidential</p>
            </div>
        </div>";
}

/* ── Request info table used inside every notification ───────────── */
function mailer_info_table(string $reqNo, string $docType, string $status): string
{
    $docType = htmlspecialchars($docType);
    $status  = htmlspecialchars($status);
    return "
        <table style='width:100%; border-collapse: collapse; margin: 16px 0;'>
            <tr>
                <td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Request No.</td>
                <td style='padding: 8px 12px; background: #f5f7fa; color: #4a6741; font-weight: bold;'>{$reqNo}</td>
            </tr>
            <tr>
                <td style='padding: 8px 12px; font-weight: bold; color: #333;'>Document</td>
                <td style='padding: 8px 12px; color: #555;'>{$docType}</td>
            </tr>
            <tr>
                <td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Status</td>
                <td style='padding: 8px 12px; background: #f5f7fa; color: #4a6741; font-weight: bold;'>{$status}</td>
            </tr>
        </table>";
}

/* ═══════════════════════════════════════════════════════════════════
 * API 1 — Status-change notification
 * ═══════════════════════════════════════════════════════════════════ */
function send_status_email(string $toEmail, string $toName, string $reqNo, string $docType, string $status, ?string $reason = null): array
{
    switch ($status) {
        case 'Pending':   // submission confirmation — "we received your request"
            $subject = 'Request received — HEHMS';
            $body    = "<p style='color:#555;'>Your request has been "
                     . "<strong style='color:#166534;'>received</strong> and is now in the Registrar's "
                     . "<strong>Pending</strong> queue.</p>"
                     . "<p style='color:#555;'>You will receive another email as soon as your request is "
                     . "accepted for processing. You can also track its status anytime in your HEHMS "
                     . "account under <strong>My Requests</strong>.</p>";
            break;

        case 'Rejected':  // registrar rejected the request — reason included
            $subject = 'Your request was rejected — HEHMS';
            $reasonHtml = $reason
                ? "<div style='background:#fdf3f3;border:1px solid #e3c8c8;border-radius:8px;padding:12px 14px;margin:14px 0;'>"
                 . "<p style='margin:0;font-size:12px;color:#9c2b2b;font-weight:700;'>&#9888; REASON FOR REJECTION</p>"
                 . "<p style='margin:6px 0 0;color:#5a3a3a;'>" . htmlspecialchars($reason) . "</p></div>"
                : '';
            $body    = "<p style='color:#555;'>Unfortunately, your request has been "
                     . "<strong style='color:#b33;'>rejected</strong> by the Registrar's Office.</p>"
                     . $reasonHtml
                     . "<p style='color:#555;'>If you can complete the missing requirements, you may submit "
                     . "a new request through your HEHMS account.</p>";
            break;

        case 'Processing':
            $subject = 'Your request has been accepted — HEHMS';
            $body    = "<p style='color:#555;'>Good news! Your request has been "
                     . "<strong style='color:#166534;'>accepted</strong> by the Registrar's Office "
                     . "and is now <strong>being processed</strong>.</p>"
                     . "<p style='color:#555;'>We will send you another email as soon as your "
                     . "document has been released.</p>";
            break;

        case 'Released':
            $subject = 'Your document has been released — HEHMS';
            $body    = "<p style='color:#555;'>Your requested document has been prepared and "
                     . "<strong style='color:#166534;'>released</strong> — it is now ready for claiming.</p>"
                     . "<p style='color:#555;'>Please claim it at the Registrar's Office during office hours "
                     . "(Mon&ndash;Fri, 8:00 AM &ndash; 4:00 PM) and bring a <strong>valid ID</strong>. "
                     . "If a representative will claim it on your behalf, prepare your "
                     . "<strong>authorization letter</strong> and both of your valid IDs.</p>";
            break;

        case 'Ready for Pickup': // legacy status — kept for old rows only
            $subject = 'Your document is ready for pickup — HEHMS';
            $body    = "<p style='color:#555;'>Good news! Your requested document is now "
                     . "<strong style='color:#166534;'>Ready for Pickup</strong>.</p>"
                     . "<p style='color:#555;'>Please claim it at the Registrar's Office during office hours "
                     . "(Mon&ndash;Fri, 8:00 AM &ndash; 4:00 PM) and bring a <strong>valid ID</strong>.</p>";
            break;

        default:
            $subject = 'Request update — HEHMS';
            $body    = "<p style='color:#555;'>The status of your request has been updated to "
                     . "<strong>" . htmlspecialchars($status) . "</strong>.</p>";
    }

    $greeting = "<p style='color:#333;'>Hello, <strong>" . htmlspecialchars($toName) . "</strong>!</p>";

    try {
        $mail = mailer_init($subject, $toEmail, $toName);
        $mail->Body    = mailer_template('HEHMS Request Update', $greeting . mailer_info_table($reqNo, $docType, $status) . $body);
        $mail->AltBody = "HEHMS Request Update — {$reqNo} ({$docType}) is now: {$status}.";
        $mail->send();
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ═══════════════════════════════════════════════════════════════════
 * API 3 — Notify a registrar account about a student action
 * (new request submitted, or a cancelled request restored)
 * ═══════════════════════════════════════════════════════════════════ */
function send_registrar_request_email(
    string $regEmail,
    string $regName,
    string $reqNo,
    string $studentName,
    string $docType,
    string $purpose,
    string $action = 'submitted'
): array {
    $restored  = ($action === 'restored');
    $subject   = $restored
        ? "Request restored to Pending ({$reqNo}) — HEHMS"
        : "New document request ({$reqNo}) — HEHMS";

    $studentName = htmlspecialchars($studentName);
    $docTypeHtml = htmlspecialchars($docType);
    $purposeHtml = htmlspecialchars($purpose);

    $greeting = "<p style='color:#333;'>Hello, <strong>" . htmlspecialchars($regName) . "</strong>!</p>";
    $body = "<p style='color:#555;'><strong>{$studentName}</strong> has "
          . ($restored ? "<strong style='color:#166534;'>restored</strong> a previously cancelled request" : "submitted a <strong>new document request</strong>")
          . ". It is now in your <strong>Pending</strong> queue.</p>"
          . "<table style='width:100%; border-collapse: collapse; margin: 16px 0;'>"
          . "<tr><td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Request No.</td>"
          . "<td style='padding: 8px 12px; background: #f5f7fa; color: #4a6741; font-weight: bold;'>{$reqNo}</td></tr>"
          . "<tr><td style='padding: 8px 12px; font-weight: bold; color: #333;'>Student</td>"
          . "<td style='padding: 8px 12px; color: #555;'>{$studentName}</td></tr>"
          . "<tr><td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Document</td>"
          . "<td style='padding: 8px 12px; background: #f5f7fa; color: #555;'>{$docTypeHtml}</td></tr>"
          . "<tr><td style='padding: 8px 12px; font-weight: bold; color: #333;'>Purpose</td>"
          . "<td style='padding: 8px 12px; color: #555;'>{$purposeHtml}</td></tr>"
          . "</table>"
          . "<p style='color:#555;'>Log in to the Registrar Dashboard to process it.</p>";

    try {
        $mail = mailer_init($subject, $regEmail, $regName);
        $mail->Body    = mailer_template('HEHMS Registrar Notice', $greeting . $body);
        $mail->AltBody = "{$studentName} {$action} request {$reqNo} ({$docType}). Log in to the Registrar Dashboard.";
        $mail->send();
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ═══════════════════════════════════════════════════════════════════
 * API 2 — E-certificate delivery (released document as attachment)
 * $certWebPath is the web-root-relative path stored in the DB
 * (e.g. "uploads/e_certificates/cert_5_...pdf").
 * ═══════════════════════════════════════════════════════════════════ */
function send_certificate_email(string $toEmail, string $toName, string $reqNo, string $docType, string $certWebPath): array
{
    $fsPath = dirname(__DIR__) . '/' . ltrim($certWebPath, '/');
    if (!is_file($fsPath)) {
        return ['ok' => false, 'error' => "Certificate file missing: {$certWebPath}"];
    }

    // Friendly attachment name: HEHMS_Certificate_of_Grades_REQ-0005.pdf
    $safeDoc  = preg_replace('/[^A-Za-z0-9]+/', '_', $docType);
    $safeDoc  = trim($safeDoc, '_');
    $ext      = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
    $attName  = "HEHMS_{$safeDoc}_{$reqNo}.{$ext}";

    $subject = "Your e-Certificate ({$reqNo}) — HEHMS";

    $greeting = "<p style='color:#333;'>Hello, <strong>" . htmlspecialchars($toName) . "</strong>!</p>";
    $body     = "<p style='color:#555;'>Your requested document has been released. Please find your "
              . "<strong style='color:#166534;'>e-certificate attached</strong> to this email — a copy is also "
              . "saved in your HEHMS account under <strong>My Requests &rarr; History</strong>.</p>"
              . "<p style='color:#555;'>If you also need the printed/original copy, please claim it at the "
              . "Registrar's Office during office hours (Mon&ndash;Fri, 8:00 AM &ndash; 4:00 PM) and bring a "
              . "<strong>valid ID</strong>.</p>";

    try {
        $mail = mailer_init($subject, $toEmail, $toName);
        $mail->Body    = mailer_template('HEHMS E-Certificate Delivery', $greeting . mailer_info_table($reqNo, $docType, 'Released') . $body);
        $mail->AltBody = "Your document ({$docType}, {$reqNo}) has been released. The e-certificate is attached to this email.";
        $mail->addAttachment($fsPath, $attName);
        $mail->send();
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ═══════════════════════════════════════════════════════════════
   notifyStudentStatus — email the student when a request status
   changes. Shared by the Registrar AND Admin dashboards (both drive
   request statuses); fires on Processing (accepted), Released and
   legacy "Ready for Pickup", plus registrar/admin rejections.
   Failures are logged server-side and never block the update.
   ═══════════════════════════════════════════════════════════════ */
function notifyStudentStatus(mysqli $conn, int $req_id, string $newStatus, ?string $reason = null, ?string $cancelledBy = null): void
{
    $notifyable = ['Processing', 'Ready for Pickup', 'Released'];
    $isRejected = ($newStatus === 'Cancelled' && $cancelledBy === 'registrar');
    if (!in_array($newStatus, $notifyable, true) && !$isRejected) {
        return;
    }

    $q = $conn->prepare(
        "SELECT s.email, s.first_name, s.last_name, dr.document_type
         FROM document_requests dr
         JOIN users s ON dr.user_id = s.id
         WHERE dr.id = ?"
    );
    $q->bind_param("i", $req_id);
    $q->execute();
    $info = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$info || empty($info['email'])) {
        return;
    }

    $fullName = trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? ''));
    $reqNo    = 'REQ-' . str_pad((string) $req_id, 4, '0', STR_PAD_LEFT);
    $mailStatus = $isRejected ? 'Rejected' : $newStatus;

    $result = send_status_email(
        $info['email'],
        $fullName,
        $reqNo,
        $info['document_type'] ?? 'your document',
        $mailStatus,
        $reason
    );

    if (!$result['ok']) {
        error_log("Status mail failed for {$reqNo} ({$newStatus}): " . $result['error']);
    }
}
