<?php
require("phpLogics/auth.php");
include("database/db.php");

if (strtolower($_SESSION['role']) !== 'registrar') {
    header("Location: dashboard.php");
    exit();
}

// ── Handle status update ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    // ── CSRF check ──
    if (!verifyCsrfToken()) {
        header("Location: registrarMainPage.php?error=csrf_fail");
        exit();
    }

    $req_id    = (int)$_POST['req_id'];
    $newStatus = $_POST['new_status'];
    $allowed   = ['Pending', 'Processing', 'Ready for Pickup', 'Released', 'Unclaimed'];

    if (in_array($newStatus, $allowed)) {
        $dbStatus    = $newStatus;
        $cancelledBy = null;

        if ($newStatus === 'Unclaimed') {
            // Store as Cancelled with cancelled_by = 'unclaimed'
            $dbStatus    = 'Cancelled';
            $cancelledBy = 'unclaimed';
        }

        $upd = $conn->prepare(
            "UPDATE document_requests
             SET status        = ?,
                 cancelled_by  = CASE
                                    WHEN ? = 'Cancelled' THEN ?
                                    WHEN ? = 'Released'  THEN NULL
                                    ELSE cancelled_by
                                 END,
                 date_released = CASE WHEN ? = 'Released' THEN NOW() ELSE date_released END
             WHERE id = ?"
        );
        $upd->bind_param("sssssi", $dbStatus, $dbStatus, $cancelledBy, $dbStatus, $dbStatus, $req_id);
        $upd->execute();
        $upd->close();
    }
    header("Location: registrarMainPage.php");
    exit();
}

// ── Stats ──────────────────────────────────────────────────────────
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
$cntReady      = countWhere($conn, "status = 'Ready for Pickup'");
$cntToday      = countWhere($conn, "DATE(date_requested) = ?", "s", [$today]);

// ── Fetch registrar info for header ───────────────────────────────
$reg = $conn->prepare("SELECT * FROM students WHERE id = ?");
$reg->bind_param("i", $_SESSION['user_id']);
$reg->execute();
$registrar   = $reg->get_result()->fetch_assoc();
$reg->close();

$regName     = $registrar ? htmlspecialchars($registrar['first_name'] . ' ' . $registrar['last_name']) : 'Registrar';
$regPhoto    = !empty($registrar['profile_photo']) ? $registrar['profile_photo'] : null;
$regInitials = $registrar
    ? strtoupper(substr($registrar['first_name'], 0, 1) . substr($registrar['last_name'], 0, 1))
    : 'R';

// ── Fetch active rows ─────────────────────────────────────────────
$mainRows = $conn->query(
    "SELECT dr.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.profile_photo
     FROM document_requests dr
     JOIN students s ON dr.student_id = s.id
     WHERE dr.status NOT IN ('Released','Cancelled')
     ORDER BY dr.date_requested DESC"
)->fetch_all(MYSQLI_ASSOC);

// ── Fetch archived rows ───────────────────────────────────────────
$archivedRows = $conn->query(
    "SELECT dr.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.profile_photo
     FROM document_requests dr
     JOIN students s ON dr.student_id = s.id
     WHERE dr.status = 'Released'
        OR (dr.status = 'Cancelled' AND dr.cancelled_by = 'registrar')
        OR (dr.status = 'Cancelled' AND dr.cancelled_by = 'unclaimed')
     ORDER BY dr.date_requested DESC"
)->fetch_all(MYSQLI_ASSOC);

// ── Helpers ───────────────────────────────────────────────────────
function statusBadge($status, $cancelledBy = '')
{
    if ($status === 'Cancelled') {
        if ($cancelledBy === 'registrar') {
            return "<span class=\"status rejected\">Rejected</span>";
        } else {
            // 'unclaimed' or any other cancelled_by value
            return "<span class=\"status unclaimed\">Unclaimed</span>";
        }
    }
    $cls = match ($status) {
        'Pending'          => 'pending',
        'Processing'       => 'processing',
        'Ready for Pickup' => 'ready',
        'Released'         => 'released',
        default            => 'pending',
    };
    return "<span class=\"status {$cls}\">{$status}</span>";
}

function avatarHtml($r)
{
    if (!empty($r['profile_photo'])) {
        $src = htmlspecialchars($r['profile_photo']);
        return "<img src=\"{$src}\" alt=\"avatar\" class=\"avatar-img\">";
    }
    $parts    = explode(' ', trim($r['student_name']));
    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
    return "<span class=\"avatar-fallback\">{$initials}</span>";
}

function renderRow($r, $isArchived = false)
{
    $tok     = htmlspecialchars(csrf_token(), ENT_QUOTES);
    $id      = (int)$r['id'];
    $padId   = '#' . str_pad($id, 4, '0', STR_PAD_LEFT);
    $name    = htmlspecialchars($r['student_name']);
    $doc     = htmlspecialchars($r['document_type']);
    $purpose = htmlspecialchars($r['purpose'] ?? '—');
    $dateReq = date('m/d/Y', strtotime($r['date_requested']));
    $dateRel = !empty($r['date_released']) ? date('m/d/Y', strtotime($r['date_released'])) : '—';
    $badge   = statusBadge($r['status'], $r['cancelled_by'] ?? '');
    $cur     = $r['status'];
    $avatar  = avatarHtml($r);

    $hasIdPhoto    = !empty($r['id_photo']);
    $hasAuthLetter = !empty($r['auth_letter']);
    $idPhotoAttr    = $hasIdPhoto    ? htmlspecialchars($r['id_photo'],    ENT_QUOTES) : '';
    $authLetterAttr = $hasAuthLetter ? htmlspecialchars($r['auth_letter'], ENT_QUOTES) : '';

    $viewBtn = "<button type='button' class='btn-view-files'
                    data-id-photo=\"{$idPhotoAttr}\"
                    data-auth-letter=\"{$authLetterAttr}\"
                    data-purpose=\"{$purpose}\"
                    data-student=\"{$name}\"
                    data-doc=\"{$doc}\">
                    📎 View Files
                </button>";

    if ($isArchived) {
        $cancelledBy   = $r['cancelled_by'] ?? '';
        $isUnclaimed   = ($r['status'] === 'Cancelled' && $cancelledBy === 'unclaimed');

        $releaseBtn = '';
        if ($isUnclaimed) {
            $releaseBtn = "
                <form method='POST' style='display:inline;'>
                    <input type='hidden' name='csrf_token' value='{$tok}'>
                    <input type='hidden' name='update_status' value='1'>
                    <input type='hidden' name='req_id' value='{$id}'>
                    <input type='hidden' name='new_status' value='Released'>
                    <button type='submit' class='btn-release-archived'
                            onclick=\"return confirm('Mark this request as Released?')\">✔ Mark Released</button>
                </form>";
        }

        return "
        <tr>
            <td><strong>{$padId}</strong></td>
            <td><div class='student-cell'>{$avatar}<span>{$name}</span></div></td>
            <td>{$doc}</td>
            <td>{$dateReq}</td>
            <td>{$dateRel}</td>
            <td>{$badge}</td>
            <td><div class='action-group'>{$viewBtn}{$releaseBtn}</div></td>
        </tr>";
    }

    if ($cur === 'Pending') {
        $acceptBtn = "
            <form method='POST' style='display:inline;'>
                <input type='hidden' name='csrf_token' value='{$tok}'>
                <input type='hidden' name='update_status' value='1'>
                <input type='hidden' name='req_id' value='{$id}'>
                <input type='hidden' name='new_status' value='Processing'>
                <button type='submit' class='btn-accept'
                        onclick=\"return confirm('Accept this request?')\">✔ Accept</button>
            </form>";
        $rejectBtn = "
            <form method='POST' style='display:inline;'>
                <input type='hidden' name='csrf_token' value='{$tok}'>
                <input type='hidden' name='update_status' value='1'>
                <input type='hidden' name='req_id' value='{$id}'>
                <input type='hidden' name='new_status' value='Cancelled'>
                <button type='submit' class='btn-reject'
                        onclick=\"return confirm('Reject this request?')\">✖ Reject</button>
            </form>";
        $actions = "<div class='action-group'>{$viewBtn}{$acceptBtn}{$rejectBtn}</div>";
    } else {
        $opts = '';
        foreach (['Processing', 'Ready for Pickup', 'Released', 'Unclaimed'] as $opt) {
            $sel   = ($opt === $cur) ? 'selected' : '';
            $opts .= "<option value=\"{$opt}\" {$sel}>{$opt}</option>";
        }
        $statusForm = "
            <form method='POST' style='display:inline;'>
                <input type='hidden' name='csrf_token' value='{$tok}'>
                <input type='hidden' name='update_status' value='1'>
                <input type='hidden' name='req_id' value='{$id}'>
                <select name='new_status' onchange='this.form.submit()' class='status-select'>
                    {$opts}
                </select>
            </form>";
        $actions = "<div class='action-group'>{$viewBtn}{$statusForm}</div>";
    }

    return "
    <tr>
        <td><strong>{$padId}</strong></td>
        <td><div class='student-cell'>{$avatar}<span>{$name}</span></div></td>
        <td>{$doc}</td>
        <td>{$dateReq}</td>
        <td>{$badge}</td>
        <td>{$actions}</td>
    </tr>";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Dashboard — HEHMS</title>
    <link rel="stylesheet" href="css/registrarCSS.css">
    <link rel="stylesheet" href="css/dark.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="javascripts/dark.js"></script>

</head>

<body>

    <!-- ══ HEADER ══ -->
    <div class="header">
        <div class="header-left">
            <img src="img/Logo.png" alt="School Logo" class="logo-img">
            <div class="school-info">
                <h2>Hilario E. Hermosa Memorial High School</h2>
                <p>Siclong Laur, Nueva Ecija</p>
            </div>
        </div>
        <div class="header-right">

            <a href="phpLogics/Logout.php" class="logout-btn">↪ Logout</a>
        </div>
    </div>

    <div class="body-layout">

        <!-- ══ SIDEBAR ══ -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <p>Logged as</p>
                <h3><?php echo $regName; ?></h3>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-group-label">Main</div>
                <a href="studentRecords.php" class="nav-main-item records">
                    <div class="nmi-icon">🎓</div>
                    <div class="nmi-text">
                        <div class="nmi-title">List Of Records</div>
                        <div class="nmi-sub">View &amp; manage records</div>
                    </div>
                </a>
                <a href="registrarMainPage.php" class="nav-main-item requests active">
                    <div class="nmi-icon">📋</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Student Requests</div>
                        <div class="nmi-sub">Process document requests</div>
                    </div>
                </a>

                </a>
                <div class="nav-divider"></div>
                <div class="nav-group-label">Tools</div>
                <a href="phpLogics/setting.php" class="nav-link"><span class="nl-icon">⚙️</span> Settings</a>

                <a href="phpLogics/Logout.php" class="nav-link"><span class="nl-icon">↪</span> Logout</a>
            </nav>
        </aside>

        <!-- ══ MAIN ══ -->
        <div class="main-container">
            <div class="title-section">
                <h1>Registrar <span>Dashboard</span></h1>
                <p>Manage student document requests and records.</p>
            </div>

            <!-- Stat cards -->
            <div class="cards">
                <div class="card">
                    <span class="card-icon">⏳</span>
                    <h4>Pending Requests</h4>
                    <h2><?php echo $cntPending; ?></h2>
                </div>
                <div class="card">
                    <span class="card-icon">⚙️</span>
                    <h4>Processing</h4>
                    <h2><?php echo $cntProcessing; ?></h2>
                </div>
                <div class="card">
                    <span class="card-icon">📦</span>
                    <h4>Ready to Pickup</h4>
                    <h2><?php echo $cntReady; ?></h2>
                </div>
                <div class="card">
                    <span class="card-icon">📅</span>
                    <h4>Today's Requests</h4>
                    <h2><?php echo $cntToday; ?></h2>
                </div>
            </div>

            <!-- Tab switcher -->
            <div class="switch-btn">
                <a class="active" id="tab-main">Main</a>
                <a id="tab-archived">Archived</a>
            </div>

            <!-- Active requests -->
            <div class="container" id="view-main">
                <div class="table-header">
                    <h3>Active Requests</h3>
                    <div class="search-wrapper">
                        <span class="search-icon">🔍</span>
                        <input type="text" class="search" id="search-main" placeholder="Search requests...">
                    </div>
                </div>
                <table>
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
                            <tr>
                                <td colspan="6" class="empty-row">📭 No active requests.</td>
                            </tr>
                        <?php else: foreach ($mainRows as $r) echo renderRow($r, false);
                        endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Archived requests -->
            <div class="container" id="view-archived" style="display:none;">
                <div class="table-header">
                    <h3>Archived Requests</h3>
                    <div class="search-wrapper">
                        <span class="search-icon">🔍</span>
                        <input type="text" class="search" id="search-archived" placeholder="Search archived...">
                    </div>
                </div>
                <table>
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
                            <tr>
                                <td colspan="6" class="empty-row">📭 No archived requests yet.</td>
                            </tr>
                        <?php else: foreach ($archivedRows as $r) echo renderRow($r, true);
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div><!-- /main-container -->
    </div><!-- /body-layout -->

    <footer class="footer">
        <div class="footer-container">
            <div class="footer-center">
                <h3>&copy; 2026 Hilario E. Hermosa Memorial High School. All rights reserved.</h3>
                <p>Credential Request &amp; Tracking System</p>
            </div>
        </div>
    </footer>


    <?php include("phpLogics/setting.php"); ?>
    <script src="javascripts/registrarJS.js"></script>

    <!-- ══ File Viewer Modal ══ -->
    <div id="file-modal">
        <div class="modal-box">
            <div class="modal-header">
                <span class="modal-title">📋 Request Details</span>
                <button class="modal-close" id="closeFileModal">✕ Close</button>
            </div>

            <div class="modal-purpose-box">
                <p class="modal-section-label">📝 Purpose / Reason</p>
                <p id="modal-purpose">—</p>
            </div>

            <div id="section-id-photo" class="modal-section">
                <p class="modal-section-label">🪪 ID Photo</p>
                <div id="content-id-photo"></div>
            </div>

            <div id="section-auth-letter" class="modal-section">
                <p class="modal-section-label">✉️ Authorization Letter</p>
                <div id="content-auth-letter"></div>
            </div>
        </div>
    </div>
</body>

</html>