<?php
require("auth.php");


// Students only
if (strtolower($_SESSION['role']) !== 'student') {
    header("Location: ../registrarMainPage.php");
    exit();
}

$student_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' 
    && isset($_POST['restore_request'])
    && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')
) {
    $req_id = (int)$_POST['req_id'];
    $restore = $conn->prepare(
        "UPDATE document_requests SET status = 'Pending'
         WHERE id = ? AND student_id = ? AND status = 'Cancelled'"
    );
    $restore->bind_param("ii", $req_id, $student_id);
    $restore->execute();
    $restore->close();
    header("Location: ../dashboard.php?view=requests&tab=main&restore_success=1");
    exit();
}

header("Location: ../dashboard.php?view=requests&tab=archived");
exit();