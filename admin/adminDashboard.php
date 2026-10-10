<?php
require __DIR__ . "/../phpLogics/auth.php";
include(__DIR__ . "/../database/db.php");
include __DIR__ . "/../phpLogics/site_config.php";

require_role('admin');

// ── Shared request-management includes ─────────────────────────────
// The Admin dashboard adopts the Registrar's request-processing
// capabilities: status changes (accept/reject), detail viewing and
// e-certificate release all reuse the same shared logic.
require_once __DIR__ . '/../phpLogics/mailer.php';       // notifyStudentStatus()
require_once __DIR__ . '/../phpLogics/certificate.php';  // certificate_fetch()/generate
require_once __DIR__ . '/../phpLogics/audit.php';        // audit_log()
require_once __DIR__ . '/../phpLogics/icons.php';

/* ── Month-end report e-mail (self-throttled — no cron needed) ──────
 * Same as the Registrar dashboard: the first staff login of a new
 * month e-mails the completed previous month's report PDF to every
 * active Registrar + Admin (see monthly_report_email.php). */
require_once __DIR__ . '/../phpLogics/monthly_report_email.php';
maybe_send_monthly_report($conn);
require_once __DIR__ . '/../phpLogics/report_menu.php';   // Report PDF month chooser

$user_id = (int)$_SESSION['user_id'];
$csrf    = csrf_token();

// ── Admin info ─────────────────────────────────────────────────────
$s = $conn->prepare("SELECT * FROM users WHERE id = ?");
$s->bind_param("i", $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();

// Guard: if the admin account row is somehow missing, fall back to sane
// values instead of crashing on substr(htmlspecialchars(null)).
if (!$me) {
    $me = ['first_name' => 'Admin', 'last_name' => '', 'email' => '',
           'contact' => '', 'profile_photo' => null];
}

$myName        = htmlspecialchars(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
// Own photo via the authenticated endpoint (photos are not web-served)
$myPhoto       = !empty($me['profile_photo'])
    ? '../phpLogics/download_profile.php?user_id=' . (int)$_SESSION['user_id']
    : null;
$myInitials    = strtoupper(substr($me['first_name'] ?? 'A', 0, 1) . substr($me['last_name'] ?? '', 0, 1));

$activeView    = $_GET['view'] ?? 'overview';
// Legacy URLs may still point at the removed standalone announcements tab —
// announcements now live inside the Information section.
if ($activeView === 'announcements') $activeView = 'information';
// Whitelist — an unknown ?view= value would otherwise leave every section
// display:none and render a blank page (same guard as the student portal).
if (!in_array($activeView, ['overview', 'requests', 'accounts', 'settings', 'information', 'audit', 'account'], true)) {
    $activeView = 'overview';
}

// Friendly greeting by time of day
$hourNow  = (int)date('G');
$greeting = $hourNow < 12 ? 'Good morning' : ($hourNow < 17 ? 'Good afternoon' : 'Good evening');

// ── Stats ──────────────────────────────────────────────────────────
$statStudents  = $conn->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE LOWER(r.role_name)='student' AND LOWER(u.status)!='archived'")->fetch_row()[0];
$statStaff     = $conn->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE LOWER(r.role_name) IN ('registrar','admin') AND LOWER(u.status)!='archived'")->fetch_row()[0];
$statAnn       = $conn->query("SELECT COUNT(*) FROM announcements WHERE is_active=1")->fetch_row()[0];

/* ═════════════════════════════════════════════════════════════════
   REQUEST MANAGEMENT (adopted from the Registrar dashboard)
   Same handlers, same tables, same modals — the admin can accept,
   reject, view details and release certificates exactly like the
   registrar. Shared pieces live in phpLogics/ (mailer, certificate,
   audit, previewCertificate, getStudentHistory, downloadReqFile).
   ═════════════════════════════════════════════════════════════════ */

/* ── Handle release — system-generated e-certificate ─────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['release_request'])) {
    if (!verifyCsrfToken()) {
        header("Location: adminDashboard.php?view=requests&error=csrf_fail");
        exit();
    }

    $req_id = (int)($_POST['req_id'] ?? 0);
    $row    = certificate_fetch($conn, $req_id);

    if (!$row) {
        header("Location: adminDashboard.php?view=requests&release_error=" . urlencode('Request not found.'));
        exit();
    }

    // ── State guard (same rule as the registrar dashboard) ──────────
    if (in_array((string)$row['status'], ['Released', 'Cancelled'], true)) {
        $why = $row['status'] === 'Released'
            ? 'already released'
            : 'cancelled/rejected — re-queue it to Pending first';
        header("Location: adminDashboard.php?view=requests&release_error=" . urlencode("This request is {$why} and cannot be released."));
        exit();
    }

    // ── Admin edits (fall back to system defaults when empty) ──
    $edits = [
        'title'         => db_utf8(trim((string)($_POST['cert_title']    ?? ''))),
        'body'          => db_utf8(trim((string)($_POST['cert_body']     ?? ''))),
        'officer_name'  => db_utf8(trim((string)($_POST['officer_name']  ?? ''))),
        'officer_title' => db_utf8(trim((string)($_POST['officer_title'] ?? ''))),
        'cert_date'     => trim((string)($_POST['cert_date']     ?? '')),
        'remarks'       => db_utf8(trim((string)($_POST['remarks']       ?? ''))),
    ];

    // Signing-officer default: the admin currently logged in
    $q = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $q->bind_param("i", $_SESSION['user_id']);
    $q->execute();
    $meRow = $q->get_result()->fetch_assoc();
    $q->close();
    $officerDefault = $meRow ? trim($meRow['first_name'] . ' ' . $meRow['last_name']) : '';
    $certData = certificate_build_data($row, $edits, $officerDefault);

    try {
        $pdf = generate_certificate_pdf($certData);
    } catch (Throwable $e) {
        error_log("Certificate generation failed (REQ-{$req_id}): " . $e->getMessage());
        header("Location: adminDashboard.php?view=requests&release_error=" . urlencode('Certificate generation failed. Please try again.'));
        exit();
    }

    // ── Save the generated PDF ──
    $uploads_fs  = dirname(__DIR__) . '/uploads';
    $dir         = $uploads_fs . '/e_certificates/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'cert_' . $req_id . '_' . time() . '_' . rand(100, 999) . '.pdf';
    if (file_put_contents($dir . $filename, $pdf) === false) {
        header("Location: adminDashboard.php?view=requests&release_error=" . urlencode('Failed to save the certificate file. Please try again.'));
        exit();
    }

    // Replace: remove the previous certificate file if any
    if (!empty($row['e_certificate'])) {
        $old = $uploads_fs . '/' . ltrim($row['e_certificate'], '/');
        if (file_exists($old)) unlink($old);
    }

    $certWeb = 'uploads/e_certificates/' . $filename;

    $upd = $conn->prepare(
        "UPDATE document_requests
         SET status = 'Released', cancelled_by = NULL, rejection_reason = NULL,
             date_released = NOW(), e_certificate = ?
         WHERE id = ? AND status NOT IN ('Released','Cancelled')"
    );
    $upd->bind_param("si", $certWeb, $req_id);
    $upd->execute();
    $upd->close();

    // ── Email the certificate to the student ──
    $stu = null;
    $q = $conn->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
    $q->bind_param("i", $row['student_pk']);
    $q->execute();
    $stu = $q->get_result()->fetch_assoc();
    $q->close();

    $mailSent = false;
    if ($stu && !empty($stu['email'])) {
        $fullName = trim($stu['first_name'] . ' ' . $stu['last_name']);
        $reqNo    = $certData['req_no'];
        $result   = send_certificate_email($stu['email'], $fullName, $reqNo, $row['document_type'], $certWeb);
        $mailSent = $result['ok'];
        if (!$mailSent) {
            error_log("Release mail failed for {$reqNo}: " . $result['error']);
        }
    } else {
        error_log("Release mail skipped (no email on file) for REQ-{$req_id}.");
    }

    // ── Audit — wording reflects the REAL mail outcome ──
    audit_log($conn, 'CERTIFICATE_RELEASED', 'document_request', $certData['req_no'],
        "{$row['document_type']} — e-certificate generated"
        . (($stu && !empty($stu['email']))
            ? ($mailSent ? " and emailed to {$stu['email']}"
                         : " — email to {$stu['email']} FAILED (certificate is still downloadable from the dashboard)")
            : ' (no email on file — NOT emailed)'));

    header("Location: adminDashboard.php?view=requests&released=1");
    exit();
}

/* ── Handle status update (accept / reject / re-queue) ───────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    // ── CSRF check ──
    if (!verifyCsrfToken()) {
        header("Location: adminDashboard.php?view=requests&error=csrf_fail");
        exit();
    }

    $req_id    = (int)($_POST['req_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    // 'Released' is NOT accepted here (same rule as the registrar dashboard):
    // releasing must go through the e-certificate flow so no request is
    // ever marked Released without a certificate.
    $allowed   = ['Pending', 'Processing', 'Ready for Pickup', 'Cancelled'];

    if (in_array($newStatus, $allowed, true)) {
        $dbStatus    = $newStatus;
        $cancelledBy = null;

        // ── Audit: remember the current status for the change log ──
        $old = $conn->prepare("SELECT status FROM document_requests WHERE id = ?");
        $old->bind_param("i", $req_id);
        $old->execute();
        $oldStatus = (string)($old->get_result()->fetch_row()[0] ?? '?');
        $old->close();

        if ($newStatus === 'Cancelled') {
            // Rejection — a written reason is REQUIRED so the student knows why.
            $cancelledBy  = 'registrar';   // recorded as a staff rejection
            $rejectReason = db_utf8(trim((string)($_POST['reject_reason'] ?? '')));
            if ($rejectReason === '') {
                header("Location: adminDashboard.php?view=requests&error=reason_required");
                exit();
            }
        } else {
            $rejectReason = null;
        }

        $upd = $conn->prepare(
            "UPDATE document_requests
             SET status        = ?,
                 cancelled_by  = CASE
                                    WHEN ? = 'Cancelled' THEN ?
                                    WHEN ? IN ('Released','Pending') THEN NULL
                                    ELSE cancelled_by
                                 END,
                 rejection_reason = CASE
                                    WHEN ? = 'Cancelled' THEN ?
                                    ELSE NULL
                                 END,
                 date_released = CASE
                                    WHEN ? = 'Released' THEN NOW()
                                    WHEN ? = 'Pending'  THEN NULL
                                    ELSE date_released
                                 END
             WHERE id = ?"
        );
        $upd->bind_param("ssssssssi", $dbStatus, $dbStatus, $cancelledBy, $dbStatus, $dbStatus, $rejectReason, $dbStatus, $dbStatus, $req_id);
        $upd->execute();

        if ($upd->affected_rows > 0) {
            audit_log($conn, 'STATUS_CHANGED', 'document_request',
                'REQ-' . str_pad((string) $req_id, 4, '0', STR_PAD_LEFT),
                "{$oldStatus} → {$dbStatus}"
                . ($cancelledBy !== null ? " (by admin)" : '')
                . (!empty($rejectReason) ? " — Reason: {$rejectReason}" : ''));
        }
        $upd->close();

        // 📧 Notify the student when the request reaches a key status
        notifyStudentStatus($conn, $req_id, $newStatus, $rejectReason ?? null, $cancelledBy);
    }
    header("Location: adminDashboard.php?view=requests");
    exit();
}

// ── Request stats (the registrar's status cards) ──────────────────
$today = date('Y-m-d');

function countWhere($conn, $where, $types = '', $params = [])
{
    $n  = 0;
    $st = $conn->prepare("SELECT COUNT(*) FROM document_requests WHERE $where");
    if (!$st) return 0;
    if ($types) $st->bind_param($types, ...$params);
    $st->execute();
    $st->bind_result($n);
    $st->fetch();
    $st->close();
    return $n;
}

$cntPending    = countWhere($conn, "status = 'Pending'");
$cntProcessing = countWhere($conn, "status = 'Processing'");
$cntReleased   = countWhere($conn, "status = 'Released'");
$cntToday      = countWhere($conn, "DATE(date_requested) = ?", "s", [$today]);
$cntRejected   = countWhere($conn, "status = 'Cancelled' AND cancelled_by = 'registrar'");
$cntTotal      = countWhere($conn, "1=1");



// ── Recent requests (latest 6, any status) ────────────────────────
$recentReq = $conn->prepare(
    "SELECT dr.id, dt.name AS document_type, dr.status, dr.cancelled_by, dr.date_requested,
            CONCAT(s.first_name,' ',s.last_name) AS student_name
     FROM document_requests dr
     JOIN document_types dt ON dt.id = dr.document_type_id
     JOIN users s ON dr.user_id = s.id
     ORDER BY dr.date_requested DESC, dr.id DESC LIMIT 6"
);
$recentReq->execute();
$recentRequests = $recentReq->get_result()->fetch_all(MYSQLI_ASSOC);
$recentReq->close();

// ── Fetch active rows ─────────────────────────────────────────
$mainRows = $conn->query(
    "SELECT dr.*, dt.name AS document_type, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.profile_photo
     FROM document_requests dr
     JOIN document_types dt ON dt.id = dr.document_type_id
     JOIN users s ON dr.user_id = s.id
     WHERE dr.status NOT IN ('Released','Cancelled')
     ORDER BY (dr.status = 'Pending') DESC, dr.date_requested ASC"
)->fetch_all(MYSQLI_ASSOC);

// ── Overdue detection: Pending requests waiting too long ─────────
// Same escalation as the registrar dashboard — badges + banner keep
// the backlog visible. (The daily digest email stays registrar-side
// so the office isn't emailed twice.)
$overdueThreshold = 3;
$overdueRequests  = [];
$oldestWaitingDays = 0;
foreach ($mainRows as $r) {
    if ($r['status'] !== 'Pending') continue;
    $days = (int)floor((time() - strtotime($r['date_requested'])) / 86400);
    if ($days >= $overdueThreshold) {
        $overdueRequests[] = $r;
        $oldestWaitingDays = max($oldestWaitingDays, $days);
    }
}
// ── Fetch archived rows (Released + all cancelled) ────────────
$archivedRows = $conn->query(
    "SELECT dr.*, dt.name AS document_type, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.profile_photo
     FROM document_requests dr
     JOIN document_types dt ON dt.id = dr.document_type_id
     JOIN users s ON dr.user_id = s.id
     WHERE dr.status IN ('Released','Cancelled')
     ORDER BY dr.date_requested DESC"
)->fetch_all(MYSQLI_ASSOC);

// ── Repeat-request map (🔁 badge) ─────────────────────────────
$repeatMap = [];
$rep = $conn->query(
    "SELECT dr.id,
            (SELECT COUNT(*) FROM document_requests d2
             WHERE d2.user_id = dr.user_id
               AND d2.document_type_id = dr.document_type_id
               AND d2.date_requested < dr.date_requested) AS earlier_count
     FROM document_requests dr"
);
while ($row = $rep->fetch_assoc()) {
    $repeatMap[(int)$row['id']] = (int)$row['earlier_count'];
}

// ── Row rendering helpers (same look as the registrar's table) ────
function statusBadge($status, $cancelledBy = '')
{
    if ($status === 'Cancelled') {
        return match ($cancelledBy) {
            'registrar' => "<span class=\"status rejected\">Rejected</span>",
            default     => "<span class=\"badge cancelled\">Cancelled</span>",
        };
    }
    $cls = match ($status) {
        'Pending'          => 'pending',
        'Processing'       => 'processing',
        'Ready for Pickup' => 'ready',
        'Released'         => 'released',
        default            => 'pending',
    };
    return "<span class=\"badge {$cls}\">{$status}</span>";
}

function avatarHtml($r)
{
    if (!empty($r['profile_photo'])) {
        // Photos are blocked from direct /uploads/ access — serve through
        // the authenticated endpoint (admin may view any user's photo).
        $src = '../phpLogics/download_profile.php?user_id=' . (int)$r['user_id'];
        return "<img src=\"{$src}\" alt=\"avatar\" class=\"avatar-img\">";
    }
    $parts    = explode(' ', trim($r['student_name']));
    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
    return "<span class=\"avatar-fallback\">{$initials}</span>";
}

function renderRow($r, $isArchived = false)
{
    global $repeatMap;

    $id      = (int)$r['id'];
    $padId   = '#' . str_pad($id, 4, '0', STR_PAD_LEFT);
    $name    = htmlspecialchars($r['student_name']);
    $doc     = htmlspecialchars($r['document_type']);
    $purpose = htmlspecialchars($r['purpose'] ?? '—');
    $dateReq = date('m/d/Y', strtotime($r['date_requested']));
    $dateRel = !empty($r['date_released']) ? date('m/d/Y', strtotime($r['date_released'])) : '—';

    // ⏳ Aging badge (1–2 neutral · 3–6 amber · 7+ red) — active rows only
    $daysWait = (int)floor((time() - strtotime($r['date_requested'])) / 86400);
    $ageBadge = '';
    if (!$isArchived && $daysWait >= 1) {
        $ageCls    = $daysWait >= 7 ? 'age-critical' : ($daysWait >= 3 ? 'age-warn' : 'age-ok');
        $ageBadge  = ' <span class="age-badge ' . $ageCls . '" title="Waiting ' . $daysWait . ' day(s)">'
                   . icon('clock', 10) . ' ' . $daysWait . 'd</span>';
    }

    // 🔒 Review lock — office already viewed this Pending request
    $lockBadge = (!$isArchived && $r['status'] === 'Pending' && !empty($r['registrar_viewed_at']))
        ? ' <span class="lock-badge" title="Reviewed on ' . date('M d, g:i A', strtotime($r['registrar_viewed_at']))
        . ' — student edits are locked">' . icon('lock', 10) . ' Locked</span>'
        : '';

    $badge   = statusBadge($r['status'], $r['cancelled_by'] ?? '');
    $cur     = $r['status'];
    $avatar  = avatarHtml($r);

    $earlier = $repeatMap[$id] ?? 0;
    $repeatBadge = $earlier > 0
        ? ' <span class="repeat-badge" title="Requested this document ' . $earlier . ' time(s) before">' . icon('repeat', 11) . ' Repeat</span>'
        : '';

    $hasIdPhoto    = !empty($r['id_photo']);
    $idPhotoAttr   = $hasIdPhoto    ? htmlspecialchars($r['id_photo'],    ENT_QUOTES) : '';
    $eCertAttr     = !empty($r['e_certificate']) ? htmlspecialchars($r['e_certificate'], ENT_QUOTES) : '';

    $viewBtn = "<button type='button' class='btn-view-files'
                    data-req-id=\"{$id}\"
                    data-id-photo=\"{$idPhotoAttr}\"
                    data-e-cert=\"{$eCertAttr}\"
                    data-purpose=\"{$purpose}\"
                    data-student=\"{$name}\"
                    data-doc=\"{$doc}\"
                    data-date=\"" . htmlspecialchars(date('M d, Y', strtotime($r['date_requested'])), ENT_QUOTES) . "\"
                    data-status=\"" . htmlspecialchars($r['status'], ENT_QUOTES) . "\"
                    data-cancelled=\"" . htmlspecialchars($r['cancelled_by'] ?? '', ENT_QUOTES) . "\"
                    data-avatar=\"" . (!empty($r['profile_photo']) ? 1 : '') . "\"
                    data-avatar-user=\"" . (int)$r['user_id'] . "\">
                    " . icon('paperclip', 13) . " View Details
                </button>";

    if ($isArchived) {
        $cancelledBy = $r['cancelled_by'] ?? '';
        $archStatus = 'Released';
        if ($r['status'] === 'Cancelled') {
            $archStatus = match ($cancelledBy) {
                'registrar' => 'Rejected',
                default     => 'Cancelled',
            };
        }

        // 📧 Re-send the certificate email (original delivery may have
        // failed, e.g. Gmail daily sending limit — see audit trail)
        $resendBtn = '';
        if ($r['status'] === 'Released' && !empty($r['e_certificate'])) {
            $resendBtn = "<form method='POST' action='../phpLogics/resendCertMail.php' style='display:inline'
                onsubmit=\"return confirm('Re-send the certificate email to the student?')\" title='Retry the delivery email for this released certificate'>
                <input type='hidden' name='csrf_token' value='" . htmlspecialchars(csrf_token(), ENT_QUOTES) . "'>
                <input type='hidden' name='req_id' value='{$id}'>
                <button type='submit' class='btn-release'>" . icon('mail', 12) . " Resend Email</button>
            </form>";
        }

        return "
        <tr data-status=\"{$archStatus}\">
            <td><strong>{$padId}</strong></td>
            <td><div class='student-cell'>{$avatar}<span>{$name}</span></div></td>
            <td>{$doc}{$repeatBadge}</td>
            <td>{$dateReq}</td>
            <td>{$dateRel}</td>
            <td>{$badge}</td>
            <td><div class='action-group'>{$viewBtn}{$resendBtn}</div></td>
        </tr>";
    }

    if ($cur === 'Pending') {
        /* Accept / Reject live inside the View Details modal (same as the
           registrar) — the row itself only opens the details. */
        $actions = "<div class='action-group'>{$viewBtn}</div>";
    } else {
        /* Status changes happen inside the View Details modal only — no
           per-row dropdown (nothing can bypass the e-certificate flow). */
        $dropdownStatuses = ['Pending', 'Processing', 'Released', 'Cancelled'];

        $relBtn = "<button type='button' class='btn-release'
                        data-req-id=\"{$id}\"
                        data-student=\"{$name}\"
                        data-doc=\"{$doc}\"
                        title=\"Release with a system-generated e-certificate\">
                        " . icon('upload', 13) . " Release
                    </button>";
        $actions = "<div class='action-group'>{$viewBtn}{$relBtn}</div>";
    }

    return "
    <tr data-status=\"{$cur}\">
        <td><strong>{$padId}</strong></td>
        <td><div class='student-cell'>{$avatar}<span>{$name}</span></div></td>
        <td>{$doc}{$repeatBadge}</td>
        <td>{$dateReq}{$ageBadge}</td>
        <td>{$badge}{$lockBadge}</td>
        <td>{$actions}</td>
    </tr>";
}

// ── Announcements ─────────────────────────────────────────────────
$announcements = $conn->query(
    "SELECT a.id, a.title, a.message, a.is_active, a.created_at,
            CONCAT(s.first_name,' ',s.last_name) AS author
     FROM announcements a
     LEFT JOIN users s ON a.created_by = s.id
     ORDER BY a.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

$settings = site_settings();

/* ══ AUDIT TRAIL (Admin → Audit Logs) ═══════════════════════════
 * Server-rendered activity log with action/keyword/date filters
 * and pagination. Read-only: entries are only ever written by
 * audit_log() from the app's own flows. */
$auditPerPage = 25;
$auditPage    = max(1, (int)($_GET['apage'] ?? 1));
$fAction      = trim((string)($_GET['aaction'] ?? ''));
$fQuery       = trim((string)($_GET['aq'] ?? ''));
$fFrom        = trim((string)($_GET['afrom'] ?? ''));
$fTo          = trim((string)($_GET['ato'] ?? ''));

$auditWhere  = [];
$auditTypes  = '';
$auditParams = [];
if ($fAction !== '') {
    $auditWhere[]  = 'al.action = ?';
    $auditTypes   .= 's';
    $auditParams[] = $fAction;
}
if ($fQuery !== '') {
    $auditWhere[]  = '(al.actor_name LIKE ? OR al.details LIKE ? OR al.entity_id LIKE ? OR al.ip_address LIKE ?)';
    $auditTypes   .= 'ssss';
    $like          = '%' . $fQuery . '%';
    array_push($auditParams, $like, $like, $like, $like);
}
if ($fFrom !== '') {
    $auditWhere[]  = 'al.created_at >= ?';
    $auditTypes   .= 's';
    $auditParams[] = $fFrom . ' 00:00:00';
}
if ($fTo !== '') {
    $auditWhere[]  = 'al.created_at <= ?';
    $auditTypes   .= 's';
    $auditParams[] = $fTo . ' 23:59:59';
}
$auditWhereSql = $auditWhere ? ('WHERE ' . implode(' AND ', $auditWhere)) : '';

// Total matching rows (for pagination + heading count)
$cnt = $conn->prepare("SELECT COUNT(*) FROM audit_logs al {$auditWhereSql}");
if ($auditParams) {
    $cnt->bind_param($auditTypes, ...$auditParams);
}
$cnt->execute();
$auditTotal = (int)$cnt->get_result()->fetch_row()[0];
$cnt->close();

$auditPages  = max(1, (int)ceil($auditTotal / $auditPerPage));
$auditPage   = min($auditPage, $auditPages);
$auditOffset = ($auditPage - 1) * $auditPerPage;

$sel = $conn->prepare(
    "SELECT al.*,
            TRIM(CONCAT(tu.first_name, ' ', tu.last_name)) AS target_user_name,
            TRIM(CONCAT(au.first_name, ' ', au.last_name)) AS target_auth_name,
            an.title AS target_ann_title
     FROM audit_logs al
     LEFT JOIN student_profiles tsp ON al.entity = 'user' AND al.entity_id IS NOT NULL AND tsp.student_id = al.entity_id
    LEFT JOIN users tu ON tu.id = tsp.user_id
     LEFT JOIN users au ON al.entity = 'auth' AND al.entity_id REGEXP '^[0-9]+$' AND au.id = al.entity_id
     LEFT JOIN announcements an ON al.entity = 'announcement' AND al.entity_id REGEXP '^[0-9]+$' AND an.id = al.entity_id
     {$auditWhereSql}
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT {$auditPerPage} OFFSET {$auditOffset}"
);
if ($auditParams) {
    $sel->bind_param($auditTypes, ...$auditParams);
}
$sel->execute();
$auditRows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
$sel->close();

// Distinct actions for the filter dropdown (grows as new actions appear)
$auditActions = array_column(
    $conn->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetch_all(MYSQLI_ASSOC),
    'action'
);

// Badge color: routine events green, security failures red
if (!function_exists('audit_badge_class')) {
    function audit_badge_class(string $action): string
    {
        $routine = ['LOGIN', 'LOGOUT', 'USER_CREATED', 'USER_UPDATED', 'USER_UNARCHIVED',
                    'REQUEST_SUBMITTED', 'REQUEST_UPDATED', 'REQUEST_RESTORED', 'STATUS_CHANGED',
                    'CERTIFICATE_RELEASED', 'PROFILE_UPDATED', 'CREDENTIAL_REQUEST_SUBMITTED',
                    'DOCUMENT_TYPE_ADDED', 'DOCUMENT_TYPE_REMOVED',
                    'DOCUMENT_TYPE_RESTORED', 'DOCUMENT_TYPE_DELETED'];
        return in_array($action, $routine, true) ? 'ab-green' : 'ab-red';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — HEHMHS</title>
    <!-- Shared portal design system (same as student + registrar) + admin components -->
    <link rel="stylesheet" href="../student/portal.css?v=2">
    <link rel="stylesheet" href="../registrar/registrarCSS.css?v=9">
    <link rel="stylesheet" href="admin.css?v=3">
    <!-- Fonts are SELF-HOSTED (assets/fonts) — the page looks identical even offline.
         (Google-hosted fonts used to make the whole site look broken without internet.) -->
    <link rel="stylesheet" href="../assets/fonts/inter.css">
    <?php echo theme_head(); // admin-managed brand color ?>
</head>

<body>

    <!-- ══ TOP BAR (matches the student + registrar portals) ══ -->
    <header class="topbar">
        <div class="topbar-inner">
            <a href="adminDashboard.php" class="brand">
                <img src="<?php echo site_logo_url(); ?>" alt="School logo">
                <div class="brand-text">
                    <strong>Hilario E. Hermosa Memorial High School</strong>
                    <span>Admin Dashboard</span>
                </div>
            </a>

            <div class="topbar-actions">
                <div class="header-avatar-wrapper">
                    <div class="header-avatar" onclick="toggleProfileMenu(event)" title="Account menu">
                        <?php if ($myPhoto): ?>
                            <img src="<?php echo $myPhoto; ?>" alt="avatar">
                        <?php else: ?>
                            <?php echo $myInitials; ?>
                        <?php endif; ?>
                    </div>

                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="profile-dropdown-user">
                            <div class="pdu-name"><?php echo $myName; ?></div>
                            <div class="pdu-id">Administrator</div>
                        </div>
                        <div class="profile-dropdown-divider"></div>
                        <a href="adminDashboard.php?view=account" class="profile-dropdown-item">
                            <span class="pdi-icon"><?php echo icon('user', 15); ?></span>
                            <span class="pdi-label">Account Information</span>
                        </a>
                        <div class="profile-dropdown-divider"></div>
                        <a href="../phpLogics/Logout.php" class="profile-dropdown-item pdi-logout">
                            <span class="pdi-icon"><?php echo icon('logout', 15); ?></span>
                            <span class="pdi-label">Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="shell">

        <!-- Main navigation — left sidebar (same design as the other portals) -->
        <aside class="sidebar">
            <nav class="tabs">
                <a onclick="showView('overview')" id="nav-overview"
                    class="ptab <?php echo $activeView === 'overview' ? 'active' : ''; ?>"><?php echo icon('home', 16); ?> Dashboard</a>
                <a onclick="showView('requests')" id="nav-requests"
                    class="ptab <?php echo $activeView === 'requests' ? 'active' : ''; ?>"><?php echo icon('clipboard', 16); ?> Student Requests</a>
                <a onclick="showView('accounts')" id="nav-accounts"
                    class="ptab <?php echo $activeView === 'accounts' ? 'active' : ''; ?>"><?php echo icon('graduation', 16); ?> Accounts</a>
                <a onclick="showView('settings')" id="nav-settings"
                    class="ptab <?php echo $activeView === 'settings' ? 'active' : ''; ?>"><?php echo icon('palette', 16); ?> Site Settings</a>
                <a onclick="showView('information')" id="nav-information"
                    class="ptab <?php echo $activeView === 'information' ? 'active' : ''; ?>"><?php echo icon('megaphone', 16); ?> Information</a>
                <a onclick="showView('audit')" id="nav-audit"
                    class="ptab <?php echo $activeView === 'audit' ? 'active' : ''; ?>"><?php echo icon('file-text', 16); ?> Audit Logs</a>
                <a onclick="showView('account')" id="nav-account"
                    class="ptab <?php echo $activeView === 'account' ? 'active' : ''; ?>"><?php echo icon('user', 16); ?> My Account</a>

                <a href="../phpLogics/Logout.php" class="ptab ptab-logout"
                    title="Log out"><?php echo icon('logout', 16); ?> Logout</a>
            </nav>
        </aside>

        <!-- ══ MAIN ══ -->
        <main class="page">
<!-- ════ VIEW 1: OVERVIEW ════ -->
            <div id="view-overview" style="display:<?php echo $activeView === 'overview' ? 'block' : 'none'; ?>;">
                <div class="view-head">
                    <h1>Admin <span>Dashboard</span></h1>
                    <p>Manage requests, accounts, announcements, and site appearance.</p>
                </div>

<?php if (!empty($overdueRequests)): ?>
                <!-- Overdue backlog warning — jumps to the Pending filter -->
                <div class="alert alert-danger overdue-banner" role="button" tabindex="0"
                    onclick="jumpToRequests('Pending')"
                    onkeydown="if(event.key==='Enter') jumpToRequests('Pending')"
                    title="Show pending requests" style="cursor:pointer;">
                    <?php echo icon('hourglass', 15); ?>
                    <span><strong><?php echo count($overdueRequests); ?></strong> request(s) have been waiting more than
                    <?php echo $overdueThreshold; ?> days — the oldest for <strong><?php echo $oldestWaitingDays; ?> day(s)</strong>.
                    Click to review them <?php echo icon('chevron-right', 13); ?></span>
                </div>
<?php endif; ?>

                <!-- Welcome banner -->
                <div class="home-hero">
                    <div class="hero-text">
                        <span class="hero-date"><?php echo date('l, F j, Y'); ?> · Administrator</span>
                        <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($me['first_name'] ?? 'Admin'); ?>!</h1>
                        <p>Publish announcements, manage every account, and customize the site.</p>
                    </div>
                    <a class="hero-cta" href="#" onclick="showView('information'); return false;"
                        title="Create an announcement"><?php echo icon('megaphone', 16); ?> New Announcement</a>
                </div>

                <!-- Stat tiles: requests + system (clickable → filtered tables / sections) -->
                <div class="stat-grid">
                    <div class="stat-tile card-click st-total" data-goto="" data-view="requests" title="All requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('clipboard', 17); ?></span><h2><?php echo $cntTotal; ?></h2></div>
                        <h4>Total Requests</h4>
                    </div>
                    <div class="stat-tile card-click st-pending" data-goto="Pending" data-view="requests" title="Show pending requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('hourglass', 17); ?></span><h2><?php echo $cntPending; ?></h2></div>
                        <h4>Pending</h4>
                    </div>
                    <div class="stat-tile card-click st-processing" data-goto="Processing" data-view="requests" title="Show processing requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('loader', 17); ?></span><h2><?php echo $cntProcessing; ?></h2></div>
                        <h4>Processing</h4>
                    </div>
                    <div class="stat-tile card-click st-released" data-goto="Released" data-view="history" title="Show released requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('check-circle', 17); ?></span><h2><?php echo $cntReleased; ?></h2></div>
                        <h4>Released</h4>
                    </div>
                    <div class="stat-tile card-click st-rejected" data-goto="Rejected" data-view="history" title="Show rejected requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('ban', 17); ?></span><h2><?php echo $cntRejected; ?></h2></div>
                        <h4>Rejected</h4>
                    </div>
                    <div class="stat-tile st-total" title="Active student accounts" onclick="showView('accounts')" style="cursor:pointer;">
                        <div class="st-row"><span class="st-icon"><?php echo icon('graduation', 17); ?></span><h2><?php echo $statStudents; ?></h2></div>
                        <h4>Students</h4>
                    </div>
                    <div class="stat-tile st-total" title="Registrar + Admin accounts" onclick="showView('accounts')" style="cursor:pointer;">
                        <div class="st-row"><span class="st-icon"><?php echo icon('briefcase', 17); ?></span><h2><?php echo $statStaff; ?></h2></div>
                        <h4>Staff</h4>
                    </div>
                    <div class="stat-tile st-total" title="Active announcements" onclick="showView('information')" style="cursor:pointer;">
                        <div class="st-row"><span class="st-icon"><?php echo icon('megaphone', 17); ?></span><h2><?php echo $statAnn; ?></h2></div>
                        <h4>Announcements</h4>
                    </div>
                    <div class="stat-tile st-total" title="Requests submitted today">
                        <div class="st-row"><span class="st-icon"><?php echo icon('calendar', 17); ?></span><h2><?php echo $cntToday; ?></h2></div>
                        <h4>Today</h4>
                    </div>
                </div>

                <div class="home-grid">
                    <div class="card">
                        <h3><?php echo icon('folder', 16); ?> Recent Requests</h3>
                        <div class="dash-recent-list">
                                    <?php if (empty($recentRequests)): ?>
                                        <p class="dash-empty">No requests yet.</p>
                                    <?php else: foreach ($recentRequests as $r):
                                        $badge = statusBadge($r['status'], $r['cancelled_by'] ?? '');
                                    ?>
                                        <div class="dash-recent-item">
                                            <div class="dash-ri-main">
                                                <strong>REQ-<?php echo str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT); ?></strong>
                                                <span class="dash-ri-doc"><?php echo htmlspecialchars($r['document_type']); ?></span>
                                            </div>
                                            <div class="dash-ri-side">
                                                <span class="dash-ri-name"><?php echo htmlspecialchars($r['student_name']); ?></span>
                                                <div>
                                                    <?php echo $badge; ?>
                                                    <small class="dash-ri-date"><?php echo date('M d, g:i A', strtotime($r['date_requested'])); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; endif; ?>
                        </div>
                        <button type="button" class="btn btn-ghost" style="margin-top:12px;" onclick="showView('requests')">View all requests <?php echo icon('chevron-right', 14); ?></button>
                    </div>

                    <div class="card">
                        <h3><?php echo icon('megaphone', 16); ?> Latest Announcements</h3>
                        <?php if (empty($announcements)): ?>
                            <p class="card-sub">No announcements yet — create one under Information.</p>
                        <?php else: foreach (array_slice($announcements, 0, 4) as $a): ?>
                            <div class="announce-item">
                                <div><span class="a-title"><?php echo htmlspecialchars($a['title']); ?></span><span class="a-date"><?php echo date('M d, Y', strtotime($a['created_at'])); ?></span><?php if (!$a['is_active']): ?><span class="a-date">(hidden)</span><?php endif; ?></div>
                                <div class="a-body"><?php echo htmlspecialchars($a['message']); ?></div>
                            </div>
                        <?php endforeach; endif; ?>

                        <div class="account-divider"></div>
                        <h3><?php echo icon('info', 16); ?> Registrar's Office</h3>
                        <div class="info-row"><span class="ic"><?php echo icon('clock', 15); ?></span><div><strong>Office hours</strong><?php echo htmlspecialchars(site_setting('office_hours', 'Monday to Friday, 8:00 AM – 4:00 PM')); ?></div></div>
                        <div class="info-row"><span class="ic"><?php echo icon('mail', 15); ?></span><div><strong>Email</strong><?php echo htmlspecialchars(site_setting('contact_email', 'hehmhsadmin@gmail.com')); ?></div></div>
                        <div class="info-row"><span class="ic"><?php echo icon('phone', 15); ?></span><div><strong>Phone</strong><?php echo htmlspecialchars(site_setting('contact_phone', '(044) 123-4567')); ?></div></div>
                        <div class="info-row"><span class="ic"><?php echo icon('map-pin', 15); ?></span><div><strong>Location</strong><?php echo htmlspecialchars(site_setting('contact_location', 'Siclong, Laur, Nueva Ecija')); ?></div></div>
                    </div>
                </div><!-- /home-grid -->
            </div><!-- /view-overview -->
<!-- ════ VIEW 2B: STUDENT REQUESTS (adopted from Registrar) ════ -->
            <div id="view-requests" class="reqmgr" style="display:<?php echo $activeView === 'requests' ? 'block' : 'none'; ?>;">
                <div class="view-head">
                        <h1>Student <span>Requests</span></h1>
                        <p>Accept, verify, reject and release document requests — same capabilities as the Registrar's Office.</p>
                    </div>

                <?php if (isset($_GET['error']) && $_GET['error'] === 'csrf_fail'): ?>
                    <div class="alert alert-danger"><?php echo icon('alert', 14); ?> Your session expired — the last action was not performed. Please try again.</div>
                <?php endif; ?>
                <?php if (isset($_GET['error']) && $_GET['error'] === 'reason_required'): ?>
                    <div class="alert alert-danger"><?php echo icon('alert', 14); ?> A written reason is required when rejecting a request.</div>
                <?php endif; ?>
                <?php if (isset($_GET['released'])): ?>
                    <div class="alert alert-success"><?php echo icon('check-circle', 14); ?> Request released — the student has been notified by email.</div>
                <?php endif; ?>
                <?php if (isset($_GET['release_error'])): ?>
                    <div class="alert alert-danger"><?php echo icon('alert', 14); ?> <?php echo htmlspecialchars($_GET['release_error']); ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['resent'])): ?>
                    <div class="alert alert-success"><?php echo icon('check-circle', 14); ?> Certificate email re-sent to the student.</div>
                <?php endif; ?>
                <?php if (isset($_GET['resend_failed'])): ?>
                    <div class="alert alert-danger"><?php echo icon('alert', 14); ?> The re-send failed (mail server refused delivery — e.g. Gmail's daily sending limit). The certificate itself is unaffected and still downloadable from the student's dashboard. Try again later.</div>
                <?php endif; ?>

                <!-- Request status tiles (clickable → filtered tables) -->
                <div class="stat-grid">
                    <div class="stat-tile card-click st-total" data-goto="" data-tab="main" title="Show all active requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('clipboard', 17); ?></span><h2><?php echo $cntTotal; ?></h2></div>
                        <h4>Total</h4>
                    </div>
                    <div class="stat-tile card-click st-pending" data-goto="Pending" data-tab="main" title="Show pending requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('hourglass', 17); ?></span><h2><?php echo $cntPending; ?></h2></div>
                        <h4>Pending</h4>
                    </div>
                    <div class="stat-tile card-click st-processing" data-goto="Processing" data-tab="main" title="Show processing requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('loader', 17); ?></span><h2><?php echo $cntProcessing; ?></h2></div>
                        <h4>Processing</h4>
                    </div>
                    <div class="stat-tile card-click st-released" data-goto="Released" data-tab="archived" title="Show released requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('check-circle', 17); ?></span><h2><?php echo $cntReleased; ?></h2></div>
                        <h4>Released</h4>
                    </div>
                    <div class="stat-tile card-click st-rejected" data-goto="Rejected" data-tab="archived" title="Show rejected requests">
                        <div class="st-row"><span class="st-icon"><?php echo icon('ban', 17); ?></span><h2><?php echo $cntRejected; ?></h2></div>
                        <h4>Rejected</h4>
                    </div>
                    <div class="stat-tile st-total" title="Requests submitted today">
                        <div class="st-row"><span class="st-icon"><?php echo icon('calendar', 17); ?></span><h2><?php echo $cntToday; ?></h2></div>
                        <h4>Today</h4>
                    </div>
                </div>

                <!-- Tab switcher -->
                <div class="switch-btn">
                    <a class="active" id="tab-main">Main</a>
                    <a id="tab-archived">History</a>
                </div>

                <!-- Active requests -->
                <div class="container" id="view-main">
                    <div class="table-header">
                        <h3>Active Requests</h3>
                        <div class="table-header-controls">
                            <div class="search-wrapper">
                                <span class="search-icon"><?php echo icon('search', 13); ?></span>
                                <input type="text" class="search" id="search-main" placeholder="Search requests...">
                            </div>
                            <?php echo report_menu_html('btn-export', '../'); ?>
                        </div>
                    </div>

                    <div class="filter-chips" id="chips-main">
                        <button type="button" class="chip active" data-filter="">All</button>
                        <button type="button" class="chip" data-filter="Pending"><?php echo icon('hourglass', 12); ?> Pending</button>
                        <button type="button" class="chip" data-filter="Processing"><?php echo icon('loader', 12); ?> Processing</button>
                    </div>
                    <div class="tbl-scroll-wrap">
                    <table class="tbl-x" style="min-width: 1000px;">
                        <thead>
                            <tr>
                                <th>Req ID</th>
                                <th>Student Name</th>
                                <th>Document</th>
                                <th>Date Requested</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="main-tbody">
                            <?php if (empty($mainRows)): ?>
                                <tr><td colspan="6" class="empty-row"><?php echo icon('inbox', 18); ?> No active requests.</td></tr>
                            <?php else: foreach ($mainRows as $r) echo renderRow($r, false); endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <!-- Request history (released / cancelled) -->
                <div class="container" id="view-archived" style="display:none;">
                    <div class="table-header">
                        <h3>Request History</h3>
                        <div class="table-header-controls">
                            <div class="search-wrapper">
                                <span class="search-icon"><?php echo icon('search', 13); ?></span>
                                <input type="text" class="search" id="search-archived" placeholder="Search history...">
                            </div>
                        </div>
                    </div>

                    <div class="filter-chips" id="chips-archived">
                        <button type="button" class="chip active" data-filter="">All</button>
                        <button type="button" class="chip" data-filter="Released"><?php echo icon('check-circle', 12); ?> Released</button>
                        <button type="button" class="chip" data-filter="Rejected"><?php echo icon('ban', 12); ?> Rejected</button>
                        <button type="button" class="chip" data-filter="Cancelled"><?php echo icon('x-circle', 12); ?> Cancelled</button>
                    </div>
                    <div class="tbl-scroll-wrap">
                    <table class="tbl-x" style="min-width: 1080px;">
                        <thead>
                            <tr>
                                <th>Req ID</th>
                                <th>Student Name</th>
                                <th>Document</th>
                                <th>Date Requested</th>
                                <th>Date Released</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="archived-tbody">
                            <?php if (empty($archivedRows)): ?>
                                <tr><td colspan="7" class="empty-row"><?php echo icon('inbox', 18); ?> No request history yet.</td></tr>
                            <?php else: foreach ($archivedRows as $r) echo renderRow($r, true); endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div><!-- /view-requests -->

            <!-- ════ VIEW 3: ACCOUNTS (all in one page) ════ -->
            <div id="view-accounts" style="display:<?php echo $activeView === 'accounts' ? 'block' : 'none'; ?>;">
                <div class="view-head">
                        <h1>Account <span>Records</span></h1>
                        <p>View, manage, and update student/registrar/admin accounts.</p>
                    </div>

                <div class="switch-btn">
                    <a class="active" id="tab-enrolled" onclick="switchRecView('enrolled')">List</a>
                    <a id="tab-alumni" onclick="switchRecView('alumni')">Archive</a>
                </div>

                <div class="container">
                    <div class="table-header">
                        <h3>List of Accounts</h3>
                        <div class="table-header-controls">
                            <div class="search-wrapper">
                                <span class="search-icon"><?php echo icon('search', 13); ?></span>
                                <input type="text" class="search" id="student-search" placeholder="Search">
                            </div>

                            <input type="file" id="csv-file-input" accept=".csv" style="display:none">
                            <a href="../phpLogics/downloadStudentTemplate.php" class="btn-new-request"
                                title="Download the CSV template to fill out for bulk import — delete the sample rows first"><?php echo icon('file-text', 14); ?> Download Template</a>
                            <a href="#" id="btn-import-csv" class="btn-new-request"
                                title="Import accounts from a filled-out template (CSV)"><?php echo icon('download', 14); ?> Import</a>
                            <a href="#" id="btn-open-modal" class="btn-new-request"><?php echo icon('plus', 14); ?> Create an Account</a>
                        </div>
                    </div>

                    <div class="tbl-scroll-wrap">
                        <table class="tbl-x" style="min-width: 1050px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>
                                    Role
                                    <div class="role-filter-wrap">
                                        <button id="role-filter-btn" class="role-filter-btn" title="Filter by role">▾</button>
                                        <div class="role-filter-dropdown" id="role-filter-dropdown">
                                            <a href="#" data-role="">All Roles</a>
                                            <a href="#" data-role="Student">Students only</a>
                                            <a href="#" data-role="Registrar">Registrar only</a>
                                            <a href="#" data-role="Admin">Admins only</a>
                                        </div>
                                    </div>
                                </th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                            <tbody id="records-tbody">
                                <tr><td colspan="7">
                                    <div class="empty-state"><div class="empty-icon">⏳</div><p>Loading accounts…</p></div>
                                </td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination — 10 accounts per page (fills via admin.js) -->
                    <div id="accounts-pager" class="accounts-pager"></div>
                </div>
            </div>

            <!-- ════ VIEW 4: SITE SETTINGS (branding: logo + theme) ════ -->
            <div id="view-settings" style="display:<?php echo $activeView === 'settings' ? 'block' : 'none'; ?>;">
                <div class="view-head">
                        <h1>Site <span>Settings</span></h1>
                        <p>Manage the school branding — logo, design theme, and theme color.</p>
                    </div>

                <div class="account-card">
                    <h3><?php echo icon('building', 16); ?> School Logo</h3>
                    <p class="settings-hint">Shown in the header of every page. JPG/PNG/GIF/WEBP · max 2 MB · square image recommended.</p>
                    <div class="settings-row">
                        <div class="logo-preview-wrap">
                            <img id="logo-preview" src="<?php echo site_logo_url(); ?>?v=<?php echo time(); ?>" alt="Current logo">
                        </div>
                        <div class="settings-controls">
                            <label for="logo-input" class="btn-upload"><?php echo icon('pencil', 13); ?> Choose New Logo</label>
                            <input type="file" id="logo-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
                            <div id="logo-name" class="file-name"></div>
                        </div>
                    </div>
                </div>
                <br>

                <div class="account-card">
                    <h3><?php echo icon('image', 16); ?> Design Theme</h3>
                    <p class="settings-hint">A complete look: colors, fonts, and corner style — applied across every page instantly.</p>
                    <div class="theme-picker" id="theme-picker">
                        <?php
                        $themeIcons = [
                            'heritage' => 'building', 'classic' => 'graduation', 'ocean' => 'palette',
                            'royal' => 'award', 'terracotta' => 'palette', 'corporate' => 'briefcase',
                        ];
                        foreach (design_themes() as $key => $t): ?>
                            <button type="button" class="theme-option <?php echo (site_setting('design_theme', 'classic') === $key) ? 'selected' : ''; ?>"
                                data-theme="<?php echo htmlspecialchars($key); ?>"
                                style="--tp: <?php echo htmlspecialchars($t['primary']); ?>; --ta: <?php echo htmlspecialchars($t['accent']); ?>;">
                                <span class="to-preview">
                                    <span class="to-bar"></span>
                                    <span class="to-line"></span>
                                    <span class="to-line short"></span>
                                </span>
                                <span class="to-name"><?php echo icon($themeIcons[$key] ?? 'palette', 14); ?> <?php echo htmlspecialchars($t['name']); ?></span>
                                <span class="to-desc"><?php echo htmlspecialchars($t['desc']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <p class="settings-hint" style="margin-top:10px;"><?php echo icon('info', 13); ?> Classic Academy uses the custom Theme Color picker below; other themes use their own palette.</p>
                </div>
<br>
                <div class="account-card">
                    <h3><?php echo icon('palette', 16); ?> Theme Color</h3>
                    <p class="settings-hint">Applied to headers, buttons, banners, and highlights on all pages.</p>
                    <div class="settings-row">
                        <input type="color" id="theme-color" value="<?php echo htmlspecialchars($settings['theme_color']); ?>">
                        <div class="swatches">
                            <button type="button" class="swatch" data-color="#6b8f3a" style="background:#6b8f3a" title="Forest Green (default)"></button>
                            <button type="button" class="swatch" data-color="#2f6f68" style="background:#2f6f68" title="Teal"></button>
                            <button type="button" class="swatch" data-color="#3b5bdb" style="background:#3b5bdb" title="Royal Blue"></button>
                            <button type="button" class="swatch" data-color="#8e44ad" style="background:#8e44ad" title="Violet"></button>
                            <button type="button" class="swatch" data-color="#b0532b" style="background:#b0532b" title="Terracotta"></button>
                            <button type="button" class="swatch" data-color="#8a6d1a" style="background:#8a6d1a" title="Gold"></button>
                        </div>
                    </div>
                </div>
<br>
                <button type="button" class="save-btn" id="btn-save-settings"><?php echo icon('save', 15); ?> Save Settings</button>
            </div>

            <!-- ════ VIEW 5: INFORMATION (hours, contacts, announcements, documents) ════ -->
            <div id="view-information" style="display:<?php echo $activeView === 'information' ? 'block' : 'none'; ?>;">
                <div class="view-head">
                        <h1>Information <span>&amp; Content</span></h1>
                        <p>Office hours, contact details, announcements, and requestable documents.</p>
                    </div>

                <!-- ═══ Office hours & contact ═══ -->
                <div class="account-card">
                    <h3><?php echo icon('clock', 16); ?> Office Hours &amp; Contact Information</h3>
                    <p class="settings-hint">Shown on the login page and every dashboard (Office Hours &amp; Contact cards).</p>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Office Hours</label>
                            <input type="text" id="set-office-hours" maxlength="100"
                                value="<?php echo htmlspecialchars(site_setting('office_hours', 'Monday to Friday, 8:00 AM – 4:00 PM')); ?>"
                                placeholder="e.g. Monday to Friday, 8:00 AM – 4:00 PM">
                        </div>
                        <div class="form-group">
                            <label>Registrar Email</label>
                            <input type="email" id="set-contact-email" maxlength="150"
                                value="<?php echo htmlspecialchars(site_setting('contact_email', 'hehmhsadmin@gmail.com')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Contact Phone</label>
                            <input type="text" id="set-contact-phone" maxlength="50"
                                value="<?php echo htmlspecialchars(site_setting('contact_phone', '(044) 123-4567')); ?>">
                        </div>
                        <div class="form-group full">
                            <label>School / Office Location</label>
                            <input type="text" id="set-contact-location" maxlength="255"
                                value="<?php echo htmlspecialchars(site_setting('contact_location', 'Hilario E. Hermosa Memorial High School, Siclong, Laur, Nueva Ecija')); ?>">
                        </div>
                    </div>
                    <button type="button" class="save-btn" id="btn-save-info"><?php echo icon('save', 15); ?> Save Information</button>
                </div>

                <!-- ═══ Announcements (managed here) ═══ -->
                <div class="account-card" style="margin-top:28px;">
                    <h3><?php echo icon('plus', 15); ?> New Announcement</h3>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Title <span class="req-star">*</span></label>
                            <input type="text" id="ann-title" maxlength="150" placeholder="e.g. Midyear break schedule">
                        </div>
                        <div class="form-group full">
                            <label>Message <span class="req-star">*</span></label>
                            <textarea id="ann-message" rows="4" placeholder="Write the announcement details…"></textarea>
                        </div>
                    </div>
                    <button type="button" class="save-btn" id="btn-add-announcement"><?php echo icon('megaphone', 15); ?> Publish Announcement</button>
                </div>
<br>
                <div class="account-card">
                    <h3><?php echo icon('clipboard', 16); ?> All Announcements</h3>
                    <div id="ann-list">
                        <?php if (empty($announcements)): ?>
                            <div class="empty-state">
                                <div class="empty-icon"><?php echo icon('inbox', 28); ?></div>
                                <p>No announcements yet.</p>
                            </div>
                        <?php else: foreach ($announcements as $a): ?>
                            <div class="ann-item <?php echo $a['is_active'] ? '' : 'inactive'; ?>">
                                <div class="ann-head">
                                    <strong><?php echo htmlspecialchars($a['title']); ?></strong>
                                    <span class="badge-status <?php echo $a['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $a['is_active'] ? 'Active' : 'Hidden'; ?>
                                    </span>
                                </div>
                                <p class="ann-msg"><?php echo nl2br(htmlspecialchars($a['message'])); ?></p>
                                <div class="ann-meta">
                                    <small>by <?php echo htmlspecialchars($a['author'] ?? 'System'); ?>
                                        · <?php echo date('M d, Y g:i A', strtotime($a['created_at'])); ?></small>
                                    <div class="ann-actions">
                                        <button type="button" class="btn-edit" style="padding:6px 12px;font-size:.78rem;"
                                            onclick="toggleAnnouncement(<?php echo (int)$a['id']; ?>)">
                                            <?php echo $a['is_active'] ? icon('eye-off', 13) . ' Hide' : icon('eye', 13) . ' Show'; ?>
                                        </button>
                                        <button type="button" class="btn-cancel-req"
                                            onclick="deleteAnnouncement(<?php echo (int)$a['id']; ?>)"><?php echo icon('trash', 13); ?> Delete</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- ═══ Requestable documents (add / remove) ═══ -->
                <div class="account-card" style="margin-top:28px;">
                    <h3><?php echo icon('file-text', 16); ?> Requestable Documents</h3>
                    <p class="settings-hint">
                        Documents students can choose when submitting a request. <strong>Remove</strong> hides a
                        document from the request form — existing requests keep it in their history. Types that
                        were never requested can be deleted permanently.
                    </p>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="doc-type-name">New Document Name <span class="req-star">*</span></label>
                            <input type="text" id="doc-type-name" maxlength="150"
                                placeholder="e.g. Certificate of Honorable Dismissal">
                            <span class="field-error" id="err-doc-type"></span>
                        </div>
                        <div class="form-group">
                            <label>Grade Level</label>
                            <label class="doc-gl-check">
                                <input type="checkbox" id="doc-type-gl">
                                Ask for the Grade Level on the request form
                            </label>
                        </div>
                    </div>
                    <button type="button" class="save-btn" id="btn-add-doc-type"><?php echo icon('plus', 14); ?> Add Document</button>

                    <div id="doc-type-list">
                        <p class="empty-state">Loading…</p>
                    </div>
                </div>
            </div>

            <!-- ════ VIEW 6: ACCOUNT INFORMATION ════ -->
            <div id="view-account" style="display:<?php echo $activeView === 'account' ? 'block' : 'none'; ?>;">
                <div class="view-head">
                        <h1>Account <span>Information</span></h1>
                        <p>View and update your own administrator profile.</p>
                    </div>

                <div class="account-card">
                    <div class="avatar-section">
                        <div class="account-avatar" id="avatar-display">
                            <?php if ($myPhoto): ?>
                                <img src="<?php echo $myPhoto; ?>" alt="avatar">
                            <?php else: ?>
                                <span><?php echo $myInitials; ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="photo-input" class="btn-upload"><?php echo icon('pencil', 13); ?> Change Photo</label>
                            <input type="file" id="photo-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
                            <div id="photo-name" class="file-name"></div>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>First Name <span class="req-star">*</span></label>
                            <input type="text" id="acc-first" value="<?php echo htmlspecialchars($me['first_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Last Name <span class="req-star">*</span></label>
                            <input type="text" id="acc-last" value="<?php echo htmlspecialchars($me['last_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group full">
                            <label>Email <span class="req-star">*</span></label>
                            <input type="email" id="acc-email" value="<?php echo htmlspecialchars($me['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group full">
                            <label>Contact Number</label>
                            <input type="text" id="acc-contact" inputmode="numeric" maxlength="11"
                                value="<?php echo htmlspecialchars($me['contact'] ?? ''); ?>" placeholder="09XXXXXXXXX">
                        </div>
                        <div class="form-group full">
                            <label>Current Password <span class="hint-inline">(required when changing password)</span></label>
                            <input type="password" id="acc-current-password" autocomplete="current-password">
                        </div>
                        <div class="form-group full">
                            <label>New Password <span class="hint-inline">(leave blank to keep current)</span></label>
                            <input type="password" id="acc-password" placeholder="••••••••" autocomplete="new-password">
                        </div>
                    </div>

                    <button type="button" class="save-btn" id="btn-save-account"><?php echo icon('save', 15); ?> Save Changes</button>
                </div>
            </div>

            <!-- ════ VIEW 7: AUDIT LOGS ════ -->
            <div id="view-audit" style="display:<?php echo $activeView === 'audit' ? 'block' : 'none'; ?>;">
                <div class="view-head">
                        <h1>Audit <span>Logs</span></h1>
                        <p>Every login, request, release, and account change — recorded automatically.</p>
                    </div>

                <div class="container audit-wrap">
                    <div class="table-header">
                        <h3>Activity Trail · <?php echo number_format($auditTotal); ?> event<?php echo $auditTotal === 1 ? '' : 's'; ?></h3>
                    </div>

                    <form method="GET" action="adminDashboard.php" class="audit-filters">
                        <input type="hidden" name="view" value="audit">
                        <select name="aaction">
                            <option value="">All actions</option>
                            <?php foreach ($auditActions as $a): ?>
                                <option value="<?php echo htmlspecialchars($a); ?>"<?php echo $a === $fAction ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $a)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="aq" placeholder="Search name / details / IP…" value="<?php echo htmlspecialchars($fQuery); ?>">
                        <input type="date" name="afrom" value="<?php echo htmlspecialchars($fFrom); ?>" title="From date">
                        <input type="date" name="ato" value="<?php echo htmlspecialchars($fTo); ?>" title="To date">
                        <button type="submit" class="btn-new-request">Filter</button>
                        <a href="adminDashboard.php?view=audit" class="audit-clear">Clear</a>
                    </form>

                    <div class="audit-table-scroll">
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Actor</th>
                                    <th>Action</th>
                                    <th>Target</th>
                                    <th>Details</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$auditRows): ?>
                                    <tr><td colspan="6">
                                        <div class="empty-state"><div class="empty-icon"><?php echo icon('folder', 28); ?></div><p>No events match the current filters.</p></div>
                                    </td></tr>
                                <?php else: foreach ($auditRows as $r):
                                    $target    = '—';
                                    $targetSub = '';
                                    if ($r['entity'] === 'document_request' && $r['entity_id']) {
                                        $target = $r['entity_id'];               // REQ-0007
                                    } elseif ($r['entity'] === 'user' && !empty($r['target_user_name'])) {
                                        $target    = $r['target_user_name'];     // Willmer Cayadong
                                        $targetSub = $r['entity_id'];             // 2026-0001 (small, under the name)
                                    } elseif ($r['entity'] === 'auth' && !empty($r['target_auth_name'])) {
                                        $target = $r['target_auth_name'];         // affected account's name
                                    } elseif ($r['entity'] === 'announcement' && !empty($r['target_ann_title'])) {
                                        $target    = $r['target_ann_title'];       // the announcement's TITLE
                                        $targetSub = 'announcement #' . $r['entity_id'];
                                    } elseif ($r['entity'] === 'settings') {
                                        $target = 'Site Settings';                 // no row to resolve — label it
                                    } elseif ($r['entity'] === 'announcement') {
                                        $target = 'Announcement #' . $r['entity_id'];   // deleted — title is in Details
                                    } elseif ($r['entity_id'] !== null && $r['entity_id'] !== '') {
                                        $target = $r['entity_id'];
                                    }
                                ?>
                                    <tr>
                                        <td class="audit-when"><?php echo date('M d, Y g:i A', strtotime($r['created_at'])); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($r['actor_name'] ?: 'Unknown'); ?></strong>
                                            <?php if ($r['actor_role']): ?>
                                                <div class="audit-role"><?php echo htmlspecialchars(ucfirst(strtolower($r['actor_role']))); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="audit-badge <?php echo audit_badge_class($r['action']); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $r['action'])); ?></span></td>
                                        <td class="audit-target"><?php echo htmlspecialchars($target); ?><?php if ($targetSub !== ''): ?><div class="audit-role"><?php echo htmlspecialchars($targetSub); ?></div><?php endif; ?></td>
                                        <td class="audit-details"><?php echo htmlspecialchars($r['details'] ?? ''); ?></td>
                                        <td class="audit-ip"><?php echo htmlspecialchars($r['ip_address'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($auditPages > 1):
                        $qs = static function (int $p) use ($fAction, $fQuery, $fFrom, $fTo): string {
                            return 'adminDashboard.php?view=audit&apage=' . $p
                                . ($fAction !== '' ? '&aaction=' . urlencode($fAction) : '')
                                . ($fQuery !== '' ? '&aq=' . urlencode($fQuery) : '')
                                . ($fFrom !== '' ? '&afrom=' . urlencode($fFrom) : '')
                                . ($fTo !== '' ? '&ato=' . urlencode($fTo) : '');
                        }; ?>
                        <div class="audit-pager">
                            <?php if ($auditPage > 1): ?>
                                <a href="<?php echo $qs($auditPage - 1); ?>">&laquo; Newer</a>
                            <?php endif; ?>
                            <span>Page <?php echo $auditPage; ?> of <?php echo $auditPages; ?></span>
                            <?php if ($auditPage < $auditPages): ?>
                                <a href="<?php echo $qs($auditPage + 1); ?>">Older &raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>

    </div><!-- /.shell -->

    <footer class="footer">
        <div class="footer-inner">
            <img class="footer-logo" src="<?php echo site_logo_url(); ?>" alt="HEHMHS logo">
            <div class="footer-name">Hilario E. Hermosa Memorial High School</div>
            <div>© <?php echo date('Y'); ?> · Credential Request &amp; Tracking System</div>
        </div>
    </footer>

    <!-- ══ CREATE / EDIT ACCOUNT MODAL ══ -->
    <div id="addStudentModal" class="modal-overlay">
        <div class="modal-box modal-box--wide">
            <div class="modal-header">
                <h3 id="addModalTitle"><?php echo icon('plus', 16); ?> <span id="addModalTitleText">Create an Account</span></h3>
                <button class="modal-close" id="btn-close-modal" type="button"><?php echo icon('x', 13); ?></button>
            </div>
            <div class="modal-body">

                <p class="form-section-title">Profile Photo</p>
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Profile Photo <span class="hint-inline">(JPG/PNG/GIF/WEBP · max 3 MB)</span></label>
                        <div class="photo-row">
                            <div id="modal-avatar-display" class="avatar-display"></div>
                            <div class="photo-controls">
                                <label for="f-photo" class="btn-upload"><?php echo icon('pencil', 13); ?> Choose Photo</label>
                                <input type="file" id="f-photo" class="hidden-input"
                                    accept="image/jpeg,image/png,image/gif,image/webp">
                                <div id="modal-photo-name" class="file-name"></div>
                                <button type="button" id="modal-photo-clear" class="btn-remove" style="display:none"><?php echo icon('x', 11); ?> Remove</button>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="form-section-title">Account Role</p>
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="f-role">Role <span class="req-star">*</span></label>
                        <select id="f-role">
                            <option value="">— Select Role —</option>
                            <option value="Student">Student</option>
                            <option value="Registrar">Registrar</option>
                            <option value="Admin">Admin</option>
                        </select>
                        <span class="field-error" id="err-role"></span>
                    </div>
                </div>

                <p class="form-section-title">Identity &amp; Academic Information</p>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="f-first">First Name <span class="required">*</span></label>
                        <input type="text" id="f-first">
                        <span class="field-error" id="err-first"></span>
                    </div>
                    <div class="form-group">
                        <label for="f-last">Last Name <span class="required">*</span></label>
                        <input type="text" id="f-last">
                        <span class="field-error" id="err-last"></span>
                    </div>
                    <div class="form-group">
                        <label for="f-lrn">LRN <span class="hint-inline">(Learner Reference Number)</span></label>
                        <input type="text" id="f-lrn" placeholder="12-digit LRN" maxlength="12">
                        <span class="field-error" id="err-lrn"></span>
                    </div>
                </div>

                <p class="form-section-title">Contact Details</p>
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="f-email">Email Address</label>
                        <input type="email" id="f-email" placeholder="juan@email.com">
                        <span class="field-error" id="err-email"></span>
                    </div>
                    <div class="form-group full">
                        <label for="f-contact">Contact Number</label>
                        <input type="text" id="f-contact" placeholder="09XXXXXXXXX" maxlength="11">
                        <span class="field-error" id="err-contact"></span>
                    </div>
                </div>

                <p class="form-section-title">Login Credentials</p>
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="f-password">Password <span class="required" id="pw-required">*</span></label>
                        <div class="pw-wrapper">
                            <input type="password" id="f-password" placeholder="Enter password">
                            <small id="pw-hint" class="hint-inline" style="display:block;margin-top:4px;">
                                Enter password or it can be auto-filled from the last 5 digits of the LRN.
                            </small>
                            <button type="button" class="pw-toggle"><?php echo icon('eye', 14); ?></button>
                        </div>
                        <span class="field-error" id="err-password"></span>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button id="btn-save-student" class="save-btn" type="button">
                    <span class="btn-label"><?php echo icon('save', 14); ?> Save</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ══ ACCOUNT INFORMATION MODAL (click a row in Accounts) ══ -->
    <div id="accountInfoModal" class="modal-overlay">
        <div class="modal-box modal-box--wide">
            <div class="modal-header">
                <h3><?php echo icon('user', 16); ?> <span id="aiTitleText">Account Information</span></h3>
                <button id="btn-close-account-modal" class="modal-close" type="button"><?php echo icon('x', 13); ?></button>
            </div>
            <div class="modal-body" id="account-modal-body">
                <p class="empty-state">Loading…</p>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>var CSRF_TOKEN = <?php echo json_encode($csrf); ?>;</script>
    <script src="../assets/js/icons.js"></script>
    <script src="admin.js?v=8"></script>
    <script src="../assets/js/offline-guard.js" defer></script>
    <script src="../assets/js/session-timeout.js" defer></script>

    <!-- ══ Reject Reason Modal (adopted from Registrar) ══ -->
    <div id="reject-modal">
     <div class="reqmgr">
      <div class="reject-modal-box">
        <h3><?php echo icon('x-circle', 17); ?> Reject Request</h3>
        <p class="reject-modal-sub">The student will see this reason in their request history. Please be specific.</p>
        <form method="POST" action="adminDashboard.php" id="reject-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="new_status" value="Cancelled">
            <input type="hidden" name="req_id" id="reject-req-id" value="">
            <label for="reject-reason-input">Reason for rejection <span style="color:#b33;">*</span></label>
            <textarea id="reject-reason-input" name="reject_reason" rows="3" maxlength="500" required
                placeholder="e.g. Incomplete requirements — please attach a valid ID"></textarea>
            <div class="reject-modal-actions">
                <button type="button" class="btn-cancel-modal" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn-reject"><?php echo icon('x', 13); ?> Reject Request</button>
            </div>
        </form>
      </div>
     </div>
    </div>

    <!-- ══ Request Details Modal (adopted from Registrar) ══ -->
    <div id="file-modal">
     <div class="reqmgr">
      <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title-wrap">
                <span class="modal-title-icon"><?php echo icon('clipboard', 18); ?></span>
                <div class="modal-title-text">
                    <span class="modal-title">Request Details</span>
                    <span class="modal-subtitle" id="modal-req-id">—</span>
                </div>
            </div>
            <button class="modal-close" id="closeFileModal" type="button"><?php echo icon('x', 13); ?> Close</button>
        </div>
        <div class="modal-scroll">
            <div class="modal-summary">
                <div class="ms-cell ms-student">
                    <span class="ms-avatar" id="ms-avatar"></span>
                    <div class="ms-fields">
                        <span class="ms-label">Student</span>
                        <span class="ms-value" id="ms-student">—</span>
                    </div>
                </div>
                <div class="ms-cell">
                    <span class="ms-label">Document</span>
                    <span class="ms-value" id="ms-doc">—</span>
                </div>
                <div class="ms-cell">
                    <span class="ms-label">Date Requested</span>
                    <span class="ms-value" id="ms-date">—</span>
                </div>
                <div class="ms-cell">
                    <span class="ms-label">Status</span>
                    <span class="ms-value" id="ms-status-wrap">—</span>
                </div>
            </div>
            <div class="modal-section">
                <p class="modal-section-label"><?php echo icon('user', 14); ?> Student Information</p>
                <div class="student-info" id="student-info">
                    <p class="history-loading">Loading…</p>
                </div>
            </div>
            <div class="modal-purpose-box">
                <p class="modal-section-label"><?php echo icon('file-text', 14); ?> Purpose / Reason</p>
                <p id="modal-purpose">—</p>
            </div>
            <div class="modal-section">
                <p class="modal-section-label"><?php echo icon('paperclip', 14); ?> Submitted Requirements</p>
                <div class="docs-grid" id="docs-grid"></div>
            </div>
            <div class="modal-section">
                <p class="modal-section-label"><?php echo icon('history', 14); ?> Request History — This Student</p>
                <div id="content-history" class="history-list">
                    <p class="history-loading">Loading…</p>
                </div>
            </div>
        </div>
      <!-- Contextual actions (Accept / Reject / Release — set by openRequestModal) -->
            <div class="modal-actions-bar" id="modal-actions-bar" style="display:none;">
                <div class="mab-info" id="mab-info"></div>
                <div class="mab-buttons">
                    <form method="POST" action="adminDashboard.php" id="modal-accept-form" style="display:none;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="req_id" id="modal-accept-req-id" value="">
                        <input type="hidden" name="new_status" value="Processing">
                        <button type="submit" class="btn-accept"
                            onclick="return confirm('Accept this request?')"><?php echo icon('check', 13); ?> Accept</button>
                    </form>
                    <button type="button" class="btn-release" id="modal-release-btn" style="display:none;"
                        title="Release with a system-generated e-certificate"><?php echo icon('upload', 13); ?> Release</button>
                    <button type="button" class="btn-reject" id="modal-reject-btn" style="display:none;"
                        ><?php echo icon('x', 13); ?> Reject</button>
                </div>
            </div>
</div>
     </div>
    </div>

    <!-- ══ Release Modal — system-generated e-certificate (adopted) ══ -->
    <div id="release-modal">
     <div class="reqmgr">
      <div class="modal-box release-box">
        <div class="modal-header">
            <div class="modal-title-wrap">
                <span class="modal-title-icon"><?php echo icon('upload', 18); ?></span>
                <div class="modal-title-text">
                    <span class="modal-title">Release with e-Certificate</span>
                    <span class="modal-subtitle" id="rel-req-id">—</span>
                </div>
            </div>
            <button class="modal-close" id="closeReleaseModal" type="button"><?php echo icon('x', 13); ?> Close</button>
        </div>
        <form method="POST" class="release-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
            <input type="hidden" name="release_request" value="1">
            <input type="hidden" name="req_id" id="rel-req-id-input" value="">
            <div class="release-info">
                <div><span>Student</span><strong id="rel-student">—</strong></div>
                <div><span>Document</span><strong id="rel-doc">—</strong></div>
            </div>
            <p class="modal-section-label"><?php echo icon('pencil', 14); ?> Edit the system-generated certificate</p>
            <div class="release-fields">
                <div class="rf-group">
                    <label>Certificate Title</label>
                    <input type="text" id="rf-title" name="cert_title" maxlength="120">
                </div>
                <div class="rf-group rf-date">
                    <label>Issue Date</label>
                    <input type="date" id="rf-date" name="cert_date">
                </div>
                <div class="rf-group">
                    <label>Signing Officer</label>
                    <input type="text" id="rf-officer" name="officer_name" maxlength="100">
                </div>
                <div class="rf-group">
                    <label>Position / Title</label>
                    <input type="text" id="rf-officer-title" name="officer_title" maxlength="100">
                </div>
                <div class="rf-group rf-full">
                    <label>Certificate Body
                        <span class="rf-hint">Placeholders: {NAME} {SY} {DATE} {PURPOSE} {LRN} {GRADE} &middot; **bold**</span>
                    </label>
                    <textarea id="rf-body" name="cert_body" rows="4" maxlength="1500"></textarea>
                </div>
                <div class="rf-group rf-full">
                    <label>Additional Remarks <span class="rf-hint">(optional)</span></label>
                    <textarea id="rf-remarks" name="remarks" rows="2" maxlength="400"></textarea>
                </div>
            </div>
            <p class="modal-section-label"><?php echo icon('eye', 14); ?> Live Preview</p>
            <div class="release-preview-wrap">
                <iframe id="rel-preview" src="about:blank" title="Certificate preview"></iframe>
            </div>
            <div class="preview-actions">
                <button type="button" class="btn-refresh-preview" id="btn-refresh-preview"><?php echo icon('refresh', 13); ?> Refresh Preview</button>
                <button type="button" class="btn-refresh-preview btn-full-preview" id="btn-fullscreen-preview"
                    title="Open the certificate full-size in a new tab"><?php echo icon('maximize', 13); ?> Fullscreen</button>
            </div>
            <p class="release-note">
                On release, this certificate is <strong>generated as a PDF by the system</strong>,
                <strong>emailed to the student as an attachment</strong>, and saved to their record
                (downloadable in their My Requests <?php echo icon('chevron-right', 11); ?> History).
            </p>
            <button type="submit" class="btn-release-big"><?php echo icon('check-circle', 16); ?> Mark as Released &amp; Email Certificate</button>
        </form>
      </div>
     </div>
    </div>

    <!-- ══ Fullscreen Document Viewer (lightbox, adopted) ══ -->
    <div id="doc-lightbox">
     <div class="reqmgr">
      <div class="lb-bar">
        <span class="lb-caption" id="lightbox-caption"></span>
        <div class="lb-bar-actions">
            <a class="lb-btn" id="lightbox-open" href="#" target="_blank" rel="noopener"><?php echo icon('external', 13); ?> Open in New Tab</a>
            <button class="lb-btn" id="lightbox-close" type="button"><?php echo icon('x', 13); ?> Close</button>
        </div>
      </div>
      <div class="lb-stage">
        <img id="lightbox-img" src="" alt="Document preview">
        <iframe id="lightbox-frame" src="about:blank" title="PDF preview"></iframe>
      </div>
     </div>
    </div>
</body>

</html>
