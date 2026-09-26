<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMS Audit Trail helper  (phpLogics/audit.php)
 * ───────────────────────────────────────────────────────────────────
 * One function — audit_log() — called at every security-relevant
 * point (logins, request lifecycle, certificate releases, account
 * changes). Rows go to the `audit_logs` table and are shown in the
 * Admin Dashboard → Audit Logs viewer.
 *
 * Design rules:
 *   • NEVER throws or blocks the main flow — a logging failure is
 *     written to error_log and silently swallowed.
 *   • Accepts BOTH connection styles used by the project: the mysqli
 *     $conn from database/db.php and the ad-hoc PDO in addStud/editStud.
 *   • The actor defaults to the logged-in session; pass an $actor
 *     array to override (needed for login events, which fire before
 *     the session is populated).
 *
 * Standard action names (keep them stable — the admin viewer groups
 * and colors entries by these):
 *   LOGIN, LOGIN_FAILED, LOGIN_BLOCKED, LOGIN_LOCKED, LOGOUT,
 *   PASSWORD_RESET_REQUESTED, PASSWORD_RESET_FAILED,
 *   REQUEST_SUBMITTED, REQUEST_CANCELLED, REQUEST_RESTORED,
 *   STATUS_CHANGED, CERTIFICATE_RELEASED,
 *   USER_CREATED, USER_UPDATED, USER_ARCHIVED, USER_UNARCHIVED,
 *   PROFILE_UPDATED
 * ═══════════════════════════════════════════════════════════════════ */

function audit_log(
    mysqli|PDO $conn,
    string $action,
    string $entity = '',
    ?string $entityId = null,
    string $details = '',
    ?array $actor = null
): void {
    try {
        // ── Who did it? (explicit override → session → anonymous) ──
        $userId = isset($actor['id'])   ? (int)$actor['id']     : ($_SESSION['user_id'] ?? null);
        $name   = isset($actor['name']) ? (string)$actor['name'] : '';
        $role   = isset($actor['role']) ? (string)$actor['role'] : (string)($_SESSION['role'] ?? '');

        // Look the name up only when the caller didn't provide one.
        if ($name === '' && $userId !== null) {
            if ($conn instanceof mysqli) {
                $q = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?");
                $q->bind_param("i", $userId);
                $q->execute();
                $name = (string)($q->get_result()->fetch_row()[0] ?? '');
                $q->close();
            } else {
                $q = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?");
                $q->execute([$userId]);
                $name = (string)($q->fetchColumn() ?: '');
            }
        }

        // Guard the column limits so no event can ever fail validation.
        $name    = mb_substr(trim($name), 0, 150);
        $role    = mb_substr(trim($role), 0, 50);
        $entity  = mb_substr(trim($entity), 0, 50);
        $entId   = $entityId === null ? null : mb_substr(trim((string)$entityId), 0, 50);
        $details = mb_substr(trim($details), 0, 500);
        $ip      = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        $sql = "INSERT INTO audit_logs
                    (user_id, actor_name, actor_role, action, entity, entity_id, details, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        if ($conn instanceof mysqli) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssssss", $userId, $name, $role, $action, $entity, $entId, $details, $ip);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->execute([$userId, $name, $role, $action, $entity, $entId, $details, $ip]);
        }
    } catch (Throwable $e) {
        // The audit trail must never take the feature down with it.
        error_log("audit_log failed ({$action}): " . $e->getMessage());
    }
}
