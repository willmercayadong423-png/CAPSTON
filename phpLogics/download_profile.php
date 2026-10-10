<?php
/* ── HEHMHS — Serve a profile photo through authentication ───────────
 * uploads/profile_photos/ is blocked from direct web access (personal
 * data). This endpoint is the only way to view a profile photo:
 *
 *   GET download_profile.php            → the logged-in user's OWN photo
 *   GET download_profile.php?user_id=N  → that user's photo
 *                                          (Registrar / Admin only —
 *                                          staff need it for the request
 *                                          tables and account records)
 *
 * Students may only ever fetch their own photo.
 * ───────────────────────────────────────────────────────────────────── */
require(__DIR__ . "/auth.php");
require_once __DIR__ . '/../database/db.php';

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'student' && $role !== 'registrar' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$viewerId = (int)$_SESSION['user_id'];
$targetId = (int)($_GET['user_id'] ?? 0);
if ($targetId === 0) {
    $targetId = $viewerId;                       // default: own photo
}

// Students are limited to their OWN photo
if ($role === 'student' && $targetId !== $viewerId) {
    http_response_code(403);
    exit('Forbidden');
}

$stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ?");
$stmt->bind_param("i", $targetId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['profile_photo'])) {
    http_response_code(404);
    exit('Not found');
}

// DB paths are web-root-relative ("uploads/...") — resolve to filesystem
$path = dirname(__DIR__) . '/' . ltrim($row['profile_photo'], '/');

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found');
}

$mime = mime_content_type($path);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
