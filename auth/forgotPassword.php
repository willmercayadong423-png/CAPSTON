<?php
require __DIR__ . '/../database/db.php';   // db.php already loads config.php (require_once)
require_once __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../phpLogics/site_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function checkRateLimit($conn, $email) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS attempts
        FROM credential_resend_log
        WHERE email = ? AND requested_at > NOW() - INTERVAL 1 HOUR
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['attempts'] >= 3;
}

function logResendAttempt($conn, $email) {
    $stmt = $conn->prepare("
        INSERT INTO credential_resend_log (email, requested_at) VALUES (?, NOW())
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
}

// ── Generate a readable temporary password ─────────────────────────
function generateTempPassword(int $length = 10): string
{
    // Unambiguous character set (no 0/O, 1/l/I)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $out   = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function sendCredentialsEmail($email, $full_name, $student_id, $temp_password) {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($email, $full_name);
    $mail->isHTML(true);
    $mail->Subject = 'Your HEHMS Temporary Password';
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 520px; margin: auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
            <div style='background: #4a6741; padding: 24px; text-align: center;'>
                <h2 style='color: #fff; margin: 0;'>HEHMS Credentials</h2>
            </div>
            <div style='padding: 28px;'>
                <p style='color: #333;'>Hello, <strong>{$full_name}</strong>!</p>
                <p style='color: #555;'>As requested, here is your temporary password:</p>
                <table style='width:100%; border-collapse: collapse; margin: 16px 0;'>
                    <tr>
                        <td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Student ID</td>
                        <td style='padding: 8px 12px; background: #f5f7fa; color: #4a6741; font-weight: bold;'>{$student_id}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 12px; font-weight: bold; color: #333;'>Email</td>
                        <td style='padding: 8px 12px; color: #555;'>{$email}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Temporary Password</td>
                        <td style='padding: 8px 12px; background: #f5f7fa; color: #4a6741; font-weight: bold; letter-spacing: 1px;'>{$temp_password}</td>
                    </tr>
                </table>
                <p style='color: #555;'>Use this temporary password to sign in. For your security, a new temporary password is generated each time — your previous password no longer works.</p>
                <p style='color: #888; font-size: 13px;'>If you did not request this, please contact your registrar immediately.</p>
            </div>
            <div style='background: #f5f7fa; padding: 14px; text-align: center;'>
                <p style='color: #aaa; font-size: 12px; margin: 0;'>HEHMS Student Records System &mdash; Confidential</p>
            </div>
        </div>
    ";

    $mail->send();
}

$step    = 'form';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = trim($_POST['student_id']    ?? '');
    $dob        = trim($_POST['date_of_birth'] ?? '');
    $first_name = trim($_POST['first_name']    ?? '');
    $last_name  = trim($_POST['last_name']     ?? '');

    if (!$student_id || !$dob || !$first_name || !$last_name) {
        $step    = 'error';
        $message = 'Please fill in all fields.';
    } else {
        // Match against student_id, date_of_birth, first_name, AND last_name
        $stmt = $conn->prepare("
            SELECT id, first_name, last_name, email, student_id
            FROM users
            WHERE student_id    = ?
              AND date_of_birth = ?
              AND LOWER(first_name) = LOWER(?)
              AND LOWER(last_name)  = LOWER(?)
              AND status = 'active'
        ");
        $stmt->bind_param("ssss", $student_id, $dob, $first_name, $last_name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $step    = 'error';
            $message = 'The details you entered do not match our records. Please check and try again.';
        } else {
            $student   = $result->fetch_assoc();
            $email     = $student['email'];
            $full_name = $student['first_name'] . ' ' . $student['last_name'];
            $sid       = $student['student_id'];

            if (checkRateLimit($conn, $email)) {
                $step    = 'error';
                $message = 'Too many resend requests. Please wait 1 hour before trying again, or contact your registrar.';
            } else {
                logResendAttempt($conn, $email);

                try {
                    // ── Secure flow: issue a NEW temporary password ──
                    // The original password is hashed in the DB and can never
                    // be "resent". A new temp password replaces it instead.
                    $temp_password = generateTempPassword(10);
                    $new_hash      = password_hash($temp_password, PASSWORD_DEFAULT);

                    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->bind_param("si", $new_hash, $student['id']);
                    $upd->execute();
                    $upd->close();

                    sendCredentialsEmail($email, $full_name, $sid, $temp_password);
                    $step    = 'success';
                    $message = 'A temporary password has been sent to your registered email address.';
                } catch (Exception $e) {
                    // Log details server-side; never expose them to the user
                    error_log('Forgot-password mail error: ' . $e->getMessage());
                    $step    = 'error';
                    $message = 'We could not send the email right now. Please try again later or contact your registrar.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Credentials — HEHMS</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="mainPageCSS.css">
    <link rel="stylesheet" href="forgotPass.css">
    <?php echo theme_head(); // admin-managed brand color ?>

    <!-- Apply dark mode before paint to avoid flash -->
    <script src="forgotPass.js"></script>
</head>
<body>

    <div class="page-center">
        <div class="card">

            <div class="card-header">
                <h2>Forgot Credentials</h2>
                <p>Verify your identity to receive a temporary password</p>
            </div>

            <div class="card-body">

                <?php if ($step === 'success'): ?>

                    <div class="message success">
                        <strong>✅ Credentials Sent!</strong>
                        <?= htmlspecialchars($message) ?><br><br>
                        Check your registered email inbox (and spam folder).
                    </div>
                    <p class="forgot-link" style="margin-top:20px;">
                        <a href="mainPage.php">← Back to Login</a>
                    </p>

                <?php elseif ($step === 'error'): ?>

                    <div class="message">
                        <strong>⚠️ Verification Failed</strong>
                        <?= htmlspecialchars($message) ?>
                    </div>
                    <p class="forgot-link" style="margin-top:20px;">
                        <a href="forgotPassword.php">← Try Again</a>
                    </p>

                <?php else: ?>

                    <div class="info-box">
                        Enter your <strong>First Name</strong>, <strong>Last Name</strong>,
                        <strong>Student ID</strong>, and <strong>Date of Birth</strong> to verify
                        your identity. A temporary password will be sent to your registered email.
                    </div>

                    <form method="POST" action="">

                        <!-- Name row: First + Last side by side -->
                        <div class="name-row">
                            <div>
                                <label class="login-label" for="first_name">First Name</label>
                                <div class="input-wrap">
                                    <span class="input-icon"></span>
                                    <input
                                        type="text"
                                        id="first_name"
                                        name="first_name"
                                        required
                                        autocomplete="off"
                                    >
                                </div>
                            </div>
                            <div>
                                <label class="login-label" for="last_name">Last Name</label>
                                <div class="input-wrap">
                                    <span class="input-icon"></span>
                                    <input
                                        type="text"
                                        id="last_name"
                                        name="last_name"
                                        placeholder=""
                                        required
                                        autocomplete="off"
                                    >
                                </div>
                            </div>
                        </div>

                        <label class="login-label" for="student_id">Student ID</label>
                        <div class="input-wrap">
                            <span class="input-icon"></span>
                            <input
                                type="text"
                                id="student_id"
                                name="student_id"
                                placeholder=""
                                required
                                autocomplete="off"
                            >
                        </div>
                        <p class="hint">The Student ID given to you by the registrar.</p>

                        <label class="login-label" for="date_of_birth">Date of Birth</label>
                        <div class="input-wrap">

                            <input
                                type="date"
                                id="date_of_birth"
                                name="date_of_birth"
                                required
                            >
                        </div>

                        <button type="submit" class="login-btn">Verify &amp; Send Temporary Password</button>

                    </form>

                    <p class="forgot-link">
                        Remembered it? <a href="mainPage.php">Back to Login</a>
                    </p>

                <?php endif; ?>

            </div>
        </div>
    </div>

</body>
</html>
