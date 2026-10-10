<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMHS — Admin: re-send an account's login credentials
 * (phpLogics/resendCredentials.php)
 * ───────────────────────────────────────────────────────────────────
 * For students (or staff) who never received their welcome email
 * (e.g. Gmail daily sending limit, typo'd address, SMTP outage).
 *
 * The original password is stored hashed and can never be "resent" —
 * so this issues a NEW temporary password instead (same policy as the
 * self-service Forgot Credentials flow):
 *   • email is sent FIRST; the password hash is updated only after a
 *     SUCCESSFUL delivery (a failed send never locks the account with
 *     a password nobody knows)
 *   • rate-limited to 3 requests per email per hour (same log table
 *     as forgot-password — protects the daily sending quota)
 *   • admin-only + CSRF; every attempt is audited
 * ═══════════════════════════════════════════════════════════════════ */
require __DIR__ . "/auth.php";
include __DIR__ . "/../database/db.php";
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/audit.php';
header('Content-Type: application/json');

// ── Admin only ─────────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session. Reload the page and try again.']);
    exit;
}

$uid = (int)($_POST['uid'] ?? 0);
if ($uid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing account id']);
    exit;
}

// ── Look up the account (role + school number for the email body) ──
$q = $conn->prepare(
    "SELECT u.id, u.first_name, u.last_name, u.email, u.status,
            r.role_name AS role, sp.student_id
     FROM users u
     LEFT JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN student_profiles sp ON sp.user_id = u.id
     WHERE u.id = ?"
);
$q->bind_param('i', $uid);
$q->execute();
$acct = $q->get_result()->fetch_assoc();
$q->close();

if (!$acct) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Account not found']);
    exit;
}
if (empty($acct['email'])) {
    echo json_encode(['success' => false, 'message' => 'This account has no email address on file — add one first (Edit Record).']);
    exit;
}
if (strtolower($acct['status']) === 'archived') {
    echo json_encode(['success' => false, 'message' => 'This account is archived — restore it first.']);
    exit;
}
if ($uid === (int)$_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'This is your own account — change your password from My Account instead.']);
    exit;
}

// ── Rate limit: 3 per email per hour (shared with forgot-password) ──
$rl = $conn->prepare(
    "SELECT COUNT(*) AS attempts FROM credential_resend_log
     WHERE email = ? AND requested_at > NOW() - INTERVAL 1 HOUR"
);
$rl->bind_param('s', $acct['email']);
$rl->execute();
$attempts = (int)$rl->get_result()->fetch_assoc()['attempts'];
$rl->close();

if ($attempts >= 3) {
    audit_log($conn, 'CREDENTIAL_RESENT', 'user', (string)$uid,
        "Re-send refused for {$acct['email']} — rate limit (3/hour) exceeded");
    echo json_encode(['success' => false, 'message' => 'Too many re-sends for this email within the hour (limit 3). Try again later.']);
    exit;
}
$log = $conn->prepare("INSERT INTO credential_resend_log (email, requested_at) VALUES (?, NOW())");
$log->bind_param('s', $acct['email']);
$log->execute();
$log->close();

// ── Readable temporary password (same set as forgot-password) ──────
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
$tempPassword = '';
for ($i = 0; $i < 10; $i++) {
    $tempPassword .= $chars[random_int(0, strlen($chars) - 1)];
}

$fullName   = trim($acct['first_name'] . ' ' . $acct['last_name']);
$studentId  = $acct['student_id'] ?? '';
$escName    = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
$escEmail   = htmlspecialchars($acct['email'], ENT_QUOTES, 'UTF-8');
$escId      = htmlspecialchars($studentId, ENT_QUOTES, 'UTF-8');
$escPw      = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');

$idRow = $studentId !== ''
    ? "<tr>
         <td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Student ID</td>
         <td style='padding: 8px 12px; background: #f5f7fa; color: #4a6741; font-weight: bold;'>{$escId}</td>
       </tr>"
    : '';

try {
    $mail = mailer_init("Your HEHMHS account credentials (re-issued) — {$fullName}", $acct['email'], $fullName);
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 520px; margin: auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
            <div style='background: #4a6741; padding: 24px; text-align: center;'>
                <h2 style='color: #fff; margin: 0;'>HEHMHS Credentials (Re-issued)</h2>
            </div>
            <div style='padding: 28px;'>
                <p style='color: #333;'>Hello, <strong>{$escName}</strong>!</p>
                <p style='color: #555;'>At the administrator's request, here are your new login credentials:</p>
                <table style='width:100%; border-collapse: collapse; margin: 16px 0;'>
                    {$idRow}
                    <tr>
                        <td style='padding: 8px 12px; font-weight: bold; color: #333;'>Email</td>
                        <td style='padding: 8px 12px; color: #555;'>{$escEmail}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Temporary Password</td>
                        <td style='padding: 8px 12px; background: #f5f7fa; color: #4a6741; font-weight: bold; letter-spacing: 1px;'>{$escPw}</td>
                    </tr>
                </table>
                <p style='color: #555;'>Sign in with this temporary password, then change it from <strong>My Account</strong>. Your previous password no longer works.</p>
                <p style='color: #888; font-size: 13px;'>If you did not expect this email, please contact the Registrar's Office immediately.</p>
            </div>
            <div style='background: #f5f7fa; padding: 14px; text-align: center;'>
                <p style='color: #aaa; font-size: 12px; margin: 0;'>HEHMHS Student Records System &mdash; Confidential</p>
            </div>
        </div>";
    $mail->AltBody = "HEHMHS credentials (re-issued) for {$fullName}: Student ID {$studentId}, Email {$acct['email']}, Temporary Password {$tempPassword}";
    $mail->send();

    // Email delivered — NOW persist the new password hash
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $upd->bind_param("si", $hash, $uid);
    $upd->execute();
    $upd->close();

    // Clear any login lockout so the fresh password works immediately
    $clr = $conn->prepare("UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?");
    $clr->bind_param("i", $uid);
    $clr->execute();
    $clr->close();

    audit_log($conn, 'CREDENTIAL_RESENT', 'user', (string)$uid,
        "New temporary password issued and emailed to {$acct['email']} (admin re-send)");

    echo json_encode(['success' => true, 'message' => 'New credentials emailed to ' . $acct['email'] . '.']);
} catch (Throwable $e) {
    error_log('resendCredentials mail failed (uid ' . $uid . '): ' . $e->getMessage());
    audit_log($conn, 'CREDENTIAL_RESENT', 'user', (string)$uid,
        'Re-send to ' . $acct['email'] . ' FAILED — ' . mb_substr($e->getMessage(), 0, 200));
    echo json_encode(['success' => false,
        'message' => 'The email could not be sent (mail server refused delivery — e.g. Gmail daily limit). The account was NOT changed; try again later.']);
}
