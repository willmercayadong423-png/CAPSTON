<?php
/* ── HEHMS — Admin: manage requestable document types ────────────────
 *   GET  ?action=list             → all types + how many requests use them
 *   POST action=add               → add a new requestable document
 *   POST action=toggle            → remove (deactivate) / restore (activate)
 *   POST action=delete            → permanently delete (only if never used;
 *                                   otherwise the row is deactivated instead
 *                                   so existing request history stays intact)
 * Admin only. POST actions are CSRF-protected; the GET list is read-only.
 * ───────────────────────────────────────────────────────────────────── */
require __DIR__ . "/auth.php";
include __DIR__ . "/../database/db.php";
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/document_types.php';
header('Content-Type: application/json');

// ── Admin only ────────────────────────────────────────────────────
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

/* ── GET · list (read-only) ───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    ensure_document_types_table($conn);
    $res = $conn->query(
        "SELECT dt.id, dt.name, dt.requires_grade_level, dt.is_active,
                (SELECT COUNT(*) FROM document_requests dr
                 WHERE dr.document_type = dt.name) AS usage_count
         FROM document_types dt
         ORDER BY dt.is_active DESC, dt.sort_order, dt.name"
    );
    $items = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

/* ── POST actions · CSRF check ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session. Reload the page and try again.']);
    exit;
}

switch ($action) {

    case 'add':
        // Same cleaning rule as addStud.php — names are escaped at OUTPUT.
        $name = trim(strip_tags((string)($_POST['name'] ?? '')));
        $gl   = isset($_POST['requires_grade_level']) ? 1 : 0;

        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Document name is required.']);
            exit;
        }
        if (mb_strlen($name) > 150) {
            echo json_encode(['success' => false, 'message' => 'Document name must be 150 characters or fewer.']);
            exit;
        }

        // Duplicate check (utf8mb4_general_ci collation is case-insensitive,
        // so this also blocks "certificate of grades" vs "Certificate of Grades")
        $dup = $conn->prepare("SELECT id FROM document_types WHERE name = ?");
        $dup->bind_param("s", $name);
        $dup->execute();
        $dupId = $dup->get_result()->fetch_row()[0] ?? null;
        $dup->close();

        if ($dupId !== null) {
            echo json_encode(['success' => false, 'message' => 'That document already exists in the list.']);
            exit;
        }

        // Append at the end of the display order
        $next = $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM document_types");
        $sort = (int)($next ? $next->fetch_row()[0] : 1);

        $ins = $conn->prepare(
            "INSERT INTO document_types (name, requires_grade_level, is_active, sort_order)
             VALUES (?, ?, 1, ?)"
        );
        $ins->bind_param("sii", $name, $gl, $sort);
        $ins->execute();
        $newId = (int)$conn->insert_id;
        $ins->close();

        audit_log($conn, 'DOCUMENT_TYPE_ADDED', 'document_type', (string)$newId,
            "Requestable document added: {$name}" . ($gl ? ' (asks for Grade Level)' : ''));

        echo json_encode(['success' => true]);
        exit;

    case 'toggle':
        $id = (int)($_POST['id'] ?? 0);

        $q = $conn->prepare("SELECT name, is_active FROM document_types WHERE id = ?");
        $q->bind_param("i", $id);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Document not found.']);
            exit;
        }

        $newState = ((int)$row['is_active'] === 1) ? 0 : 1;
        $upd = $conn->prepare("UPDATE document_types SET is_active = ? WHERE id = ?");
        $upd->bind_param("ii", $newState, $id);
        $upd->execute();
        $upd->close();

        audit_log($conn,
            $newState === 1 ? 'DOCUMENT_TYPE_RESTORED' : 'DOCUMENT_TYPE_REMOVED',
            'document_type', (string)$id,
            ($newState === 1 ? 'Document restored: ' : 'Document removed from the request form: ')
            . $row['name']);

        echo json_encode(['success' => true, 'active' => $newState]);
        exit;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);

        $q = $conn->prepare("SELECT name FROM document_types WHERE id = ?");
        $q->bind_param("i", $id);
        $q->execute();
        $name = (string)($q->get_result()->fetch_row()[0] ?? '');
        $q->close();

        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Document not found.']);
            exit;
        }

        // Never hard-delete a type that has request history — deactivate it
        // instead so old rows keep showing the right document name.
        $c = $conn->prepare("SELECT COUNT(*) FROM document_requests WHERE document_type = ?");
        $c->bind_param("s", $name);
        $c->execute();
        $used = (int)$c->get_result()->fetch_row()[0];
        $c->close();

        if ($used > 0) {
            // In use → soft-remove only (history must keep resolving the name)
            $upd = $conn->prepare("UPDATE document_types SET is_active = 0 WHERE id = ?");
            $upd->bind_param("i", $id);
            $upd->execute();
            $upd->close();

            audit_log($conn, 'DOCUMENT_TYPE_REMOVED', 'document_type', (string)$id,
                "Document removed from the request form: {$name} (kept in history — {$used} request(s) use it)");

            echo json_encode([
                'success'   => true,
                'deactivated' => true,
                'message'   => "\"{$name}\" has {$used} existing request(s), so it was removed from the request form but kept in history.",
            ]);
            exit;
        }

        $del = $conn->prepare("DELETE FROM document_types WHERE id = ?");
        $del->bind_param("i", $id);
        $del->execute();
        $del->close();

        audit_log($conn, 'DOCUMENT_TYPE_DELETED', 'document_type', (string)$id,
            "Document permanently deleted: {$name} (never requested)");

        echo json_encode(['success' => true]);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}
