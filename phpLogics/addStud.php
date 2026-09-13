<?php
require("auth.php");
header('Content-Type: application/json');

// ── Registrar only ────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'registrar') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session. Reload the page and try again.']);
    exit;
}

require_once __DIR__ . '/../database/config.php';

function clean($val)
{
    return htmlspecialchars(strip_tags(trim($val)));
}

$first    = clean($_POST['first_name'] ?? '');
$last     = clean($_POST['last_name']  ?? '');
$role     = clean($_POST['role']       ?? '');
$email    = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$contact  = clean($_POST['contact']    ?? '');
$password = $_POST['password'] ?? '';

$lrn    = clean($_POST['lrn']                       ?? '');
$dob    = clean($_POST['date_of_birth']             ?? '');
$grade  = clean($_POST['grade_level']               ?? '');
$strand = clean($_POST['strand']                    ?? '');
$syear  = clean($_POST['school_year_last_attended'] ?? '');

$year = date('Y'); // must be defined BEFORE it is used below

// Auto-generate password from year + last 5 digits of LRN if not provided
if ($password === '' && strlen($lrn) >= 5) {
    $password = $year . '-' . substr($lrn, -5);
}

$allowedRoles = ['Student', 'Registrar'];

if (!$first || !$last || !$email || !$contact || !$password) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}
if (!in_array($role, $allowedRoles, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid role']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 🔍 Check duplicate email
    $stmt = $pdo->prepare("SELECT id FROM students WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        exit;
    }

    // 🎓 Generate student ID
    $lastStudent = $pdo->prepare("SELECT student_id FROM students WHERE student_id LIKE ? ORDER BY id DESC LIMIT 1");
    $lastStudent->execute([$year . '-%']);
    $lastId     = $lastStudent->fetchColumn();
    $seq        = $lastId ? intval(substr($lastId, 5)) + 1 : 1;
    $student_id = $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

    $full_name = "$first $last";

    // 🔐 Hash password for DB
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // 📷 Handle profile photo upload
    $new_photo = null;
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime    = mime_content_type($_FILES['profile_photo']['tmp_name']);
        $size    = $_FILES['profile_photo']['size'];

        if (in_array($mime, $allowed) && $size <= 3 * 1024 * 1024) {
            $dir = __DIR__ . '/../uploads/profile_photos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $ext      = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $filename = 'student_' . $student_id . '_' . time() . '.' . $ext;

            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dir . $filename)) {
                $new_photo = 'uploads/profile_photos/' . $filename;
            }
        }
    }

    // 💾 Insert new account
    // NOTE: the plain-text password is intentionally NOT stored anymore.
    // It is emailed once below; "Forgot Credentials" issues a temp password.
    $insert = $pdo->prepare("
        INSERT INTO students
            (student_id, profile_photo, first_name, last_name, role, email, contact,
             lrn, date_of_birth, grade_level, strand, school_year_last_attended,
             status, password)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $insert->execute([
        $student_id,
        $new_photo,
        $first,
        $last,
        $role,
        $email,
        $contact,
        $lrn,
        $dob ?: null,
        $grade,
        $strand,
        $syear,
        'active',
        $hash,
    ]);

    // 📧 Send welcome email with credentials
    require __DIR__ . '/../vendor/autoload.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
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
        $mail->Subject = 'Your HEHMS Account Credentials';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 520px; margin: auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
                <div style='background: #4a6741; padding: 24px; text-align: center;'>
                    <h2 style='color: #fff; margin: 0;'>Welcome to HEHMS</h2>
                </div>
                <div style='padding: 28px;'>
                    <p style='color: #333;'>Hello, <strong>{$full_name}</strong>!</p>
                    <p style='color: #555;'>Your student account has been created. Here are your login credentials:</p>
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
                            <td style='padding: 8px 12px; background: #f5f7fa; font-weight: bold; color: #333;'>Password</td>
                            <td style='padding: 8px 12px; background: #f5f7fa; color: #4a6741; font-weight: bold; letter-spacing: 1px;'>{$password}</td>
                        </tr>
                    </table>
                    <p style='color: #888; font-size: 13px;'>Please keep your credentials confidential. If you forget them, use the Forgot Credentials option on the login page.</p>
                </div>
                <div style='background: #f5f7fa; padding: 14px; text-align: center;'>
                    <p style='color: #aaa; font-size: 12px; margin: 0;'>HEHMS Student Records System &mdash; Confidential</p>
                </div>
            </div>
        ";

        $mail->send();

    } catch (Exception $e) {
        // Email failed — but account was already created.
        // Log the details server-side, return only a generic warning.
        error_log("Welcome mail failed for {$student_id}: " . $mail->ErrorInfo);
        echo json_encode([
            'success'   => true,
            'warning'   => 'Account created but welcome email could not be sent. Please note the credentials and inform the student.',
            'student_id'                => $student_id,
            'profile_photo'             => $new_photo,
            'full_name'                 => $full_name,
            'role'                      => $role,
            'email'                     => $email,
            'contact'                   => $contact,
            'lrn'                       => $lrn,
            'date_of_birth'             => $dob,
            'grade_level'               => $grade,
            'strand'                    => $strand,
            'school_year_last_attended' => $syear,
        ]);
        exit;
    }

    echo json_encode([
        'success'                    => true,
        'student_id'                 => $student_id,
        'profile_photo'              => $new_photo,
        'full_name'                  => $full_name,
        'role'                       => $role,
        'email'                      => $email,
        'contact'                    => $contact,
        'lrn'                        => $lrn,
        'date_of_birth'              => $dob,
        'grade_level'                => $grade,
        'strand'                     => $strand,
        'school_year_last_attended'  => $syear,
    ]);

} catch (PDOException $e) {
    // Never expose DB error details to the client
    error_log('addStud DB error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred. Please try again.'
    ]);
}
