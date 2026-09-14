<?php
require("auth.php");
require_once __DIR__ . '/mailer.php';

// Students only
if (strtolower($_SESSION['role']) !== 'student') {
    header("Location: ../registrar/registrarMainPage.php");
    exit();
}

$student_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['restore_request'])
    && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')
) {
    $req_id = (int)$_POST['req_id'];

    // ── Guard: restoring must not exceed the 3-pending-requests limit ──
    $chk = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM document_requests
         WHERE user_id = ? AND status = 'Pending'"
    );
    $chk->bind_param("i", $student_id);
    $chk->execute();
    $pending = (int)$chk->get_result()->fetch_assoc()['cnt'];
    $chk->close();

    if ($pending >= 3) {
        header("Location: ../student/dashboard.php?error=not_found");
        exit();
    }

    // ── Details for the registrar notification (fetched before the update) ──
    $info = null;
    $q = $conn->prepare(
        "SELECT dr.document_type, dr.purpose,
                CONCAT(s.first_name,' ',s.last_name) AS student_name
         FROM document_requests dr
         JOIN users s ON dr.user_id = s.id
         WHERE dr.id = ? AND dr.user_id = ?"
    );
    $q->bind_param("ii", $req_id, $student_id);
    $q->execute();
    $info = $q->get_result()->fetch_assoc();
    $q->close();

    // ── Restore: only student-cancelled rows come back to the Pending queue ──
    // cancelled_by / date_released are cleared so the row is clean again.
    $restore = $conn->prepare(
        "UPDATE document_requests
         SET status = 'Pending', cancelled_by = NULL, date_released = NULL
         WHERE id = ? AND user_id = ? AND status = 'Cancelled'
           AND (cancelled_by = 'student' OR cancelled_by IS NULL OR cancelled_by = '')"
    );
    $restore->bind_param("ii", $req_id, $student_id);
    $restore->execute();
    $restored = $restore->affected_rows > 0;
    $restore->close();

    // ── Tell the registrar the request is back in their Pending queue ──
    if ($restored && $info) {
        $reqNo = 'REQ-' . str_pad((string) $req_id, 4, '0', STR_PAD_LEFT);
        try {
            $regs = $conn->query(
                "SELECT email, first_name, last_name FROM users
                 WHERE LOWER(role) = 'registrar' AND LOWER(status) != 'archived'
                   AND email IS NOT NULL AND email <> ''"
            );
            while ($regs && ($rg = $regs->fetch_assoc())) {
                $res = send_registrar_request_email(
                    $rg['email'],
                    trim($rg['first_name'] . ' ' . $rg['last_name']),
                    $reqNo,
                    $info['student_name'],
                    $info['document_type'],
                    $info['purpose'] ?? '',
                    'restored'
                );
                if (!$res['ok']) {
                    error_log("Registrar restore-notice failed ({$reqNo}): " . $res['error']);
                }
            }
        } catch (Throwable $e) {
            error_log("Registrar restore-notice error ({$reqNo}): " . $e->getMessage());
        }
    }

    header("Location: ../student/dashboard.php?view=requests&tab=main&restore_success=1");
    exit();
}

header("Location: ../student/dashboard.php?view=requests&tab=archived");
exit();
