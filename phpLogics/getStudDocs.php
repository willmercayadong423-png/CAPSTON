<?php
require("auth.php");
include("../database/db.php");

// ── Admin only ───────────────────────────────────────────────────────
if (strtolower($_SESSION['role']) !== 'admin') {
    echo json_encode(['error' => 'Unauthorized']); exit();
}

$student_id = trim($_GET['student_id'] ?? '');
if ($student_id === '') { echo json_encode([]); exit(); }

$sql = "SELECT document_type, COUNT(*) as cnt
        FROM document_requests
        WHERE user_id IN (SELECT id FROM users WHERE student_id = ?)
        GROUP BY document_type";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

$counts = [
    'certificate_of_enrollment'            => 0,
    'certificate_of_grades'                => 0,
    'certificate_of_good_moral'            => 0,
    'certificate_of_transfer'             => 0,
    'certificate_of_completion_graduation' => 0,
    // Types added by the admin (or retired legacy names) fall into 'other'.
    'other'                                => 0,
];

while ($row = $result->fetch_assoc()) {
    // Normalize: lowercase, spaces/dashes/slashes → underscore
    $type = strtolower(trim($row['document_type']));
    $type = str_replace([' ', '-', '/'], '_', $type);

    if (array_key_exists($type, $counts)) {
        $counts[$type] += (int)$row['cnt'];
    } else {
        $counts['other'] += (int)$row['cnt'];
    }
}

$stmt->close();
header('Content-Type: application/json');
echo json_encode($counts);