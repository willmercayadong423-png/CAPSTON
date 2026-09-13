<?php
require("auth.php");
include("../database/db.php");

if (strtolower($_SESSION['role']) !== 'registrar') {
    echo json_encode(['error' => 'Unauthorized']); exit();
}

$student_id = trim($_GET['student_id'] ?? '');
if ($student_id === '') { echo json_encode([]); exit(); }

$sql = "SELECT document_type, COUNT(*) as cnt
        FROM document_requests
        WHERE student_id IN (SELECT id FROM students WHERE student_id = ?)
        GROUP BY document_type";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

$counts = [
    'good_moral'    => 0,
    'diploma'       => 0,
    'form_137'      => 0,
    'form_138'      => 0,
    'transcript'    => 0,
    'certification' => 0,
    'yearbook'      => 0,
    'tor'           => 0,
    'other'         => 0,
];

while ($row = $result->fetch_assoc()) {
    // Normalize: lowercase, spaces/dashes → underscore
    $type = strtolower(trim($row['document_type']));
    $type = str_replace([' ', '-'], '_', $type);

    if (array_key_exists($type, $counts)) {
        $counts[$type] += (int)$row['cnt'];
    } else {
        $counts['other'] += (int)$row['cnt'];
    }
}

$stmt->close();
header('Content-Type: application/json');
echo json_encode($counts);