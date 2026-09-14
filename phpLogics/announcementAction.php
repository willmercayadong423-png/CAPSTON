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

$action = $_POST['action'] ?? '';
$user   = (int)$_SESSION['user_id'];

try {
    switch ($action) {

        case 'add':
            $title   = trim($_POST['title'] ?? '');
            $message = trim($_POST['message'] ?? '');

            if ($title === '' || $message === '') {
                echo json_encode(['success' => false, 'message' => 'Title and message are required.']);
                exit;
            }
            if (mb_strlen($title) > 150) {
                echo json_encode(['success' => false, 'message' => 'Title must be 150 characters or fewer.']);
                exit;
            }

            $ins = $conn->prepare(
                "INSERT INTO announcements (title, message, is_active, created_by) VALUES (?, ?, 1, ?)"
            );
            $ins->bind_param("ssi", $title, $message, $user);
            $ins->execute();
            $ins->close();

            echo json_encode(['success' => true, 'id' => $conn->insert_id]);
            exit;

        case 'toggle':
            $id = (int)($_POST['id'] ?? 0);
            $upd = $conn->prepare(
                "UPDATE announcements SET is_active = 1 - is_active WHERE id = ?"
            );
            $upd->bind_param("i", $id);
            $upd->execute();
            $upd->close();
            echo json_encode(['success' => true]);
            exit;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $del = $conn->prepare("DELETE FROM announcements WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            echo json_encode(['success' => true]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
            exit;
    }

} catch (Throwable $e) {
    error_log('announcementAction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
