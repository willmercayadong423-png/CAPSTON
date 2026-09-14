<?php
require_once __DIR__ . '/../database/db.php';

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$timeout = 900; // 15 minutes

// ── Dynamic project base URL (no hardcoded folder names) ──────────
function base_url(string $path = ''): string
{
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $projDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $base    = ($docRoot !== '' && strpos($projDir, $docRoot) === 0)
        ? substr($projDir, strlen($docRoot))
        : '';
    $base = implode('/', array_map('rawurlencode', explode('/', $base)));
    return $base . '/' . ltrim($path, '/');
}

// ── CSRF helpers (shared by pages + endpoints) ────────────────────
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token = null): bool
{
    if ($token === null) {
        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';
    }
    return is_string($token)
        && $token !== ''
        && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// Not logged in
if (!isset($_SESSION["email"])) {
    header("Location: " . base_url('auth/mainPage.php'));
    exit();
}

// Session expired
if (isset($_SESSION["last_activity"]) &&
    (time() - $_SESSION["last_activity"]) > $timeout) {

    session_unset();
    session_destroy();

    header("Location: " . base_url('mainPage.php?timeout=1'));
    exit();
}

// Refresh timer
$_SESSION["last_activity"] = time();

// ── Role helpers ──────────────────────────────────────────────
function home_for_role(string $role): string
{
    return match (strtolower($role)) {
        'student'   => base_url('student/dashboard.php'),
        'admin'     => base_url('admin/adminDashboard.php'),
        default     => base_url('registrar/registrarMainPage.php'),
    };
}

function require_role(string $role): void
{
    if (strtolower($_SESSION['role'] ?? '') !== $role) {
        header('Location: ' . home_for_role($_SESSION['role'] ?? ''));
        exit;
    }
}
