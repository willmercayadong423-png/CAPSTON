<?php
require __DIR__ . "/auth.php";
include __DIR__ . "/../database/db.php";
header('Content-Type: application/json');

// ── Admin only ────────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
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

$uploads_fs  = dirname(__DIR__) . '/uploads';
$uploads_web = 'uploads';

// ── Design theme (full look: palette + fonts + radius) ─────────────
$designTheme = trim((string)($_POST['design_theme'] ?? ''));
if ($designTheme !== '') {
    require_once __DIR__ . '/site_config.php';
    $validThemes = array_keys(design_themes());
    if (!in_array($designTheme, $validThemes, true)) {
        echo json_encode(['success' => false, 'message' => 'Unknown design theme.']);
        exit;
    }
    $stmt = $conn->prepare(
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('design_theme', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param("s", $designTheme);
    $stmt->execute();
    $stmt->close();
}

// ── Theme color (applies when Design Theme = Classic Academy) ─────────
$color = trim($_POST['theme_color'] ?? '');
if ($color !== '') {
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        echo json_encode(['success' => false, 'message' => 'Invalid color format.']);
        exit;
    }
    $stmt = $conn->prepare(
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('theme_color', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param("s", $color);
    $stmt->execute();
    $stmt->close();
}

// ── Office hours & contact information (admin-editable) ───────────
$textSettings = [
    'office_hours'    => ['max' => 100, 'label' => 'Office hours'],
    'contact_email'   => ['max' => 150, 'label' => 'Registrar email'],
    'contact_phone'   => ['max' => 50,  'label' => 'Contact phone'],
    'contact_location'=> ['max' => 255, 'label' => 'Location'],
];
foreach ($textSettings as $key => $rule) {
    $val = trim((string)($_POST[$key] ?? ''));
    if ($val === '') continue;                       // empty = keep current
    if (mb_strlen($val) > $rule['max']) {
        echo json_encode(['success' => false, 'message' => $rule['label'] . ' is too long (max ' . $rule['max'] . ' characters).']);
        exit;
    }
    if ($key === 'contact_email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid registrar email.']);
        exit;
    }
    $val = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');   // stored escaped, pages print raw
    $stmt = $conn->prepare(
        "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param("ss", $key, $val);
    $stmt->execute();
    $stmt->close();
}

// ── Logo upload (optional) ────────────────────────────────────────
if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['logo'];

    $allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_ext  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime         = mime_content_type($file['tmp_name']);

    if (!in_array($ext, $allowed_ext, true)) {
        echo json_encode(['success' => false, 'message' => 'Logo must be JPG, PNG, GIF, or WEBP.']);
        exit;
    }
    if (!in_array($mime, $allowed_mime, true)) {
        echo json_encode(['success' => false, 'message' => 'Logo file content is not a valid image.']);
        exit;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Logo must be under 2 MB.']);
        exit;
    }

    $fs_dir  = $uploads_fs . '/site/';
    if (!is_dir($fs_dir)) mkdir($fs_dir, 0755, true);

    $filename = 'logo_' . time() . '_' . rand(100, 999) . '.' . $ext;

    if (move_uploaded_file($file['tmp_name'], $fs_dir . $filename)) {
        // Remove the previous custom logo (keep the original bundled default)
        $old = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key = 'logo_path'");
        if ($old && ($prev = $old->fetch_row()[0] ?? null)) {
            if (strpos($prev, 'uploads/site/') === 0) {
                $prevFs = dirname(__DIR__) . '/' . $prev;
                if (file_exists($prevFs)) unlink($prevFs);
            }
        }

        $webPath = $uploads_web . '/site/' . $filename;
        $stmt = $conn->prepare(
            "INSERT INTO site_settings (setting_key, setting_value) VALUES ('logo_path', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->bind_param("s", $webPath);
        $stmt->execute();
        $stmt->close();

        // Bust browser cache of the old logo
        echo json_encode(['success' => true, 'logo_url' => site_url($webPath) . '?v=' . time()]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Failed to save the logo. Please try again.']);
    exit;
}

// Color-only save (or nothing uploaded at all)
echo json_encode(['success' => true, 'theme_color' => $color ?: null]);
