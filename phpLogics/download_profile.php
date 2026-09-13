<?php
require(__DIR__ . "/auth.php");
require_once __DIR__ . '/../database/db.php';

if (strtolower($_SESSION['role']) !== 'student') {
    http_response_code(403);
    exit('Forbidden');
}

$student_id = $_SESSION['user_id'];
$field      = $_GET['field'] ?? '';
$allowed    = ['id_front', 'id_back', 'profile_photo'];

if (!in_array($field, $allowed, true)) {
    http_response_code(400);
    exit('Invalid field');
}

$stmt = $conn->prepare("SELECT $field AS filepath FROM students WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();


$path = __DIR__ . '/../' . $row['filepath'];

if (!$row || empty($row['filepath']) || !file_exists($path)) {
    http_response_code(404);
    exit('Not found');
}

$mime = mime_content_type($path);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;