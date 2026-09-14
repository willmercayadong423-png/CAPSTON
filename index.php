<?php
/* ── Root entry point ─────────────────────────────────────────────
 * Sends visitors to the right place: logged-in users land on their
 * role's dashboard, everyone else on the login page. ────────────── */
require __DIR__ . '/database/db.php';

if (!isset($_SESSION['email'])) {
    header('Location: auth/mainPage.php');
    exit;
}

// Logged in — go to this role's home
$role = strtolower($_SESSION['role'] ?? '');
$home = match ($role) {
    'student'   => 'student/dashboard.php',
    'admin'     => 'admin/adminDashboard.php',
    default     => 'registrar/registrarMainPage.php',
};
header('Location: ' . $home);
exit;
