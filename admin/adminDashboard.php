<?php
require __DIR__ . "/../phpLogics/auth.php";
include(__DIR__ . "/../database/db.php");
include __DIR__ . "/../phpLogics/site_config.php";

require_role('admin');

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
$myPhoto       = !empty($me['profile_photo']) ? '../' . htmlspecialchars($me['profile_photo']) : null;
$myInitials    = strtoupper(substr($me['first_name'] ?? 'A', 0, 1) . substr($me['last_name'] ?? '', 0, 1));

$activeView    = $_GET['view'] ?? 'overview';
// Legacy URLs may still point at the removed standalone announcements tab —
// announcements now live inside the Information section.
if ($activeView === 'announcements') $activeView = 'information';

// ── Stats ──────────────────────────────────────────────────────────
$statStudents  = $conn->query("SELECT COUNT(*) FROM users WHERE LOWER(role)='student' AND LOWER(status)!='archived'")->fetch_row()[0];
$statStaff     = $conn->query("SELECT COUNT(*) FROM users WHERE LOWER(role) IN ('registrar','admin') AND LOWER(status)!='archived'")->fetch_row()[0];
$statPending   = $conn->query("SELECT COUNT(*) FROM document_requests WHERE status='Pending'")->fetch_row()[0];
$statAnn       = $conn->query("SELECT COUNT(*) FROM announcements WHERE is_active=1")->fetch_row()[0];

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
     LEFT JOIN users tu ON al.entity = 'user' AND al.entity_id IS NOT NULL AND tu.student_id = al.entity_id
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
    <title>Admin Dashboard — HEHMS</title>
    <link rel="stylesheet" href="../student/dashb.css">
    <link rel="stylesheet" href="admin.css">
    <link rel="stylesheet" href="../assets/css/scroll-table.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <?php echo theme_head(); // admin-managed brand color ?>
</head>

<body>

    <!-- ══ HEADER ══ -->
    <div class="header">
        <a href="adminDashboard.php" class="header-left">
            <img src="<?php echo site_logo_url(); ?>" alt="School Logo" class="logo-img">
            <div class="school-info">
                <h2>Hilario E. Hermosa Memorial High School</h2>
                <p>Siclong Laur, Nueva Ecija</p>
            </div>
        </a>

        <div class="header-right">
            <div class="header-profile-name">
                <span><?php echo $myName; ?></span>
            </div>

            <div class="header-avatar-wrapper">
                <div class="header-avatar" onclick="toggleProfileMenu(event)" style="cursor:pointer;">
                    <?php if ($myPhoto): ?>
                        <img src="<?php echo $myPhoto; ?>" alt="avatar">
                    <?php else: ?>
                        <?php echo $myInitials; ?>
                    <?php endif; ?>
                </div>

                <div class="profile-dropdown" id="profileDropdown">
                    <a href="#" onclick="showView('account'); closeProfileMenu();">
                        👤 Account Information
                    </a>
                    <a href="../phpLogics/Logout.php">
                        🚪 Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="body-layout">

        <!-- ══ SIDEBAR ══ -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <div class="welcome-card">
                    <div class="welcome-avatar">
                        <?php if ($myPhoto): ?>
                            <img src="<?php echo $myPhoto; ?>" alt="Profile Avatar">
                        <?php else: ?>
                            <?php echo $myInitials; ?>
                        <?php endif; ?>
                    </div>
                    <div class="welcome-info">
                        <span class="welcome-text">Logged in as</span>
                        <h3><?php echo $myName; ?></h3>
                        <span class="account-role-badge-inline">Administrator</span>
                    </div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-group-label">Main Menus</div>

                <a onclick="showView('overview')" id="nav-overview"
                    class="nav-main-item dashboard <?php echo $activeView === 'overview' ? 'active' : ''; ?>">
                    <div class="nmi-icon">🏠</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Dashboard</div>
                    </div>
                </a>

                <a onclick="showView('accounts')" id="nav-accounts"
                    class="nav-main-item records <?php echo $activeView === 'accounts' ? 'active' : ''; ?>">
                    <div class="nmi-icon">🎓</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Accounts</div>
                        <div class="nmi-sub">Manage all accounts</div>
                    </div>
                </a>

                <a onclick="showView('settings')" id="nav-settings"
                    class="nav-main-item <?php echo $activeView === 'settings' ? 'active' : ''; ?>">
                    <div class="nmi-icon">🎨</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Site Settings</div>
                        <div class="nmi-sub">Logo &amp; theme color</div>
                    </div>
                </a>

                <a onclick="showView('information')" id="nav-information"
                    class="nav-main-item <?php echo $activeView === 'information' ? 'active' : ''; ?>">
                    <div class="nmi-icon">📢</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Information</div>
                        <div class="nmi-sub">Hours, contacts, announcements &amp; documents</div>
                    </div>
                </a>

                <a onclick="showView('audit')" id="nav-audit"
                    class="nav-main-item <?php echo $activeView === 'audit' ? 'active' : ''; ?>">
                    <div class="nmi-icon">🧾</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Audit Logs</div>
                        <div class="nmi-sub">Who did what, when</div>
                    </div>
                </a>

                <a onclick="showView('account')" id="nav-account"
                    class="nav-main-item <?php echo $activeView === 'account' ? 'active' : ''; ?>">
                    <div class="nmi-icon">👤</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Account Information</div>
                    </div>
                </a>

                <div class="nav-divider"></div>
                <div class="nav-group-label">Others</div>
                <a href="../phpLogics/Logout.php" class="nav-main-item logout">
                    <div class="nmi-icon">↩️</div>
                    <div class="nmi-text">
                        <div class="nmi-title">LOGOUT</div>
                    </div>
                </a>
            </nav>
        </aside>

        <!-- ══ MAIN ══ -->
        <div class="main-container">

            <!-- ════ VIEW 1: OVERVIEW ════ -->
            <div id="view-overview" style="display:<?php echo $activeView === 'overview' ? 'block' : 'none'; ?>;">
                <div class="page-banner">
                    <div class="page-banner-icon">🛡️</div>
                    <div class="page-banner-content">
                        <h1>Admin <span>Dashboard</span></h1>
                        <p>Manage announcements, accounts, and site appearance.</p>
                    </div>
                </div>

                <div class="dashboard-card">
                    <h2 class="card-title">You can publish announcements, manage every account, and customize the site's logo and theme color.</h2>
                    <br>
                    <div class="cards">
                        <div class="card"><span class="card-icon">🎓</span><h4>Active Students</h4><h2><?php echo $statStudents; ?></h2></div>
                        <div class="card"><span class="card-icon">🧑‍💼</span><h4>Staff Accounts</h4><h2><?php echo $statStaff; ?></h2></div>
                        <div class="card"><span class="card-icon">⏳</span><h4>Pending Requests</h4><h2><?php echo $statPending; ?></h2></div>
                        <div class="card"><span class="card-icon">📢</span><h4>Active Announcements</h4><h2><?php echo $statAnn; ?></h2></div>
                    </div>

                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <h3>📢 Latest Announcements</h3>
                            <ul class="dashboard-list">
                                <?php if (empty($announcements)): ?>
                                    <li>No announcements yet.</li>
                                    <li>Create one under <strong>Information</strong>.</li>
                                <?php else: foreach (array_slice($announcements, 0, 3) as $a): ?>
                                    <li>
                                        <strong><?php echo htmlspecialchars($a['title']); ?></strong>
                                        <small> — <?php echo date('M d, Y', strtotime($a['created_at'])); ?></small>
                                        <?php if (!$a['is_active']): ?><small>(hidden)</small><?php endif; ?><br>
                                        <?php echo htmlspecialchars($a['message']); ?>
                                    </li>
                                <?php endforeach; endif; ?>
                            </ul>
                        </div>

                        <div class="dashboard-card">
                            <h3>🕒 Office Hours</h3>
                            <p><?php echo htmlspecialchars(site_setting('office_hours', 'Monday to Friday, 8:00 AM – 4:00 PM')); ?></p>
                            <p style="margin-top:10px;">Closed during weekends and holidays.</p>
                        </div>

                        <div class="dashboard-card full-width">
                            <h3>☎ Contact Information</h3>
                            <p><strong>Registrar's Office</strong></p>
                            <p>Email: <?php echo htmlspecialchars(site_setting('contact_email', 'registrar@hehms.edu.ph')); ?></p>
                            <p>Phone: <?php echo htmlspecialchars(site_setting('contact_phone', '(044) 123-4567')); ?></p>
                            <p>Location: <?php echo htmlspecialchars(site_setting('contact_location', 'Hilario E. Hermosa Memorial High School, Siclong, Laur, Nueva Ecija')); ?></p>
                        </div>
                    </div>

                    <button class="save-btn" type="button" onclick="showView('information')">📢 New Announcement</button>
                </div>
            </div>

            <!-- ════ VIEW 3: ACCOUNTS (all in one page) ════ -->
            <div id="view-accounts" style="display:<?php echo $activeView === 'accounts' ? 'block' : 'none'; ?>;">
                <div class="page-banner">
                    <div class="page-banner-icon">🎓</div>
                    <div class="page-banner-content">
                        <h1>Account <span>Records</span></h1>
                        <p>View, manage, and update student/registrar/admin accounts.</p>
                    </div>
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
                                <span class="search-icon">🔍</span>
                                <input type="text" class="search" id="student-search" placeholder="Search">
                            </div>

                            <input type="file" id="csv-file-input" accept=".csv" style="display:none">
                            <a href="#" id="btn-import-csv" class="btn-new-request">📥 Import</a>
                            <a href="#" id="btn-open-modal" class="btn-new-request">➕ Create an Account</a>
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
                </div>
            </div>

            <!-- ════ VIEW 4: SITE SETTINGS (branding: logo + theme) ════ -->
            <div id="view-settings" style="display:<?php echo $activeView === 'settings' ? 'block' : 'none'; ?>;">
                <div class="page-banner">
                    <div class="page-banner-icon">🎨</div>
                    <div class="page-banner-content">
                        <h1>Site <span>Settings</span></h1>
                        <p>Manage the school branding — logo, design theme, and theme color.</p>
                    </div>
                </div>

                <div class="account-card">
                    <h3>🏫 School Logo</h3>
                    <p class="settings-hint">Shown in the header of every page. JPG/PNG/GIF/WEBP · max 2 MB · square image recommended.</p>
                    <div class="settings-row">
                        <div class="logo-preview-wrap">
                            <img id="logo-preview" src="<?php echo site_logo_url(); ?>?v=<?php echo time(); ?>" alt="Current logo">
                        </div>
                        <div class="settings-controls">
                            <label for="logo-input" class="btn-upload">✏️ Choose New Logo</label>
                            <input type="file" id="logo-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
                            <div id="logo-name" class="file-name"></div>
                        </div>
                    </div>
                </div>
                <br>

                <div class="account-card">
                    <h3>🖼️ Design Theme</h3>
                    <p class="settings-hint">A complete look: colors, fonts, and corner style — applied across every page instantly.</p>
                    <div class="theme-picker" id="theme-picker">
                        <?php foreach (design_themes() as $key => $t): ?>
                            <button type="button" class="theme-option <?php echo (site_setting('design_theme', 'classic') === $key) ? 'selected' : ''; ?>"
                                data-theme="<?php echo htmlspecialchars($key); ?>"
                                style="--tp: <?php echo htmlspecialchars($t['primary']); ?>; --ta: <?php echo htmlspecialchars($t['accent']); ?>;">
                                <span class="to-preview">
                                    <span class="to-bar"></span>
                                    <span class="to-line"></span>
                                    <span class="to-line short"></span>
                                </span>
                                <span class="to-name"><?php echo htmlspecialchars($t['emoji'] . ' ' . $t['name']); ?></span>
                                <span class="to-desc"><?php echo htmlspecialchars($t['desc']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <p class="settings-hint" style="margin-top:10px;">ℹ️ Classic Academy uses the custom Theme Color picker below; other themes use their own palette.</p>
                </div>
<br>
                <div class="account-card">
                    <h3>🎨 Theme Color</h3>
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
                <button type="button" class="save-btn" id="btn-save-settings">💾 Save Settings</button>
            </div>

            <!-- ════ VIEW 5: INFORMATION (hours, contacts, announcements, documents) ════ -->
            <div id="view-information" style="display:<?php echo $activeView === 'information' ? 'block' : 'none'; ?>;">
                <div class="page-banner">
                    <div class="page-banner-icon">📢</div>
                    <div class="page-banner-content">
                        <h1>Information <span>&amp; Content</span></h1>
                        <p>Office hours, contact details, announcements, and requestable documents.</p>
                    </div>
                </div>

                <!-- ═══ Office hours & contact ═══ -->
                <div class="account-card">
                    <h3>🕒 Office Hours &amp; Contact Information</h3>
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
                                value="<?php echo htmlspecialchars(site_setting('contact_email', 'registrar@hehms.edu.ph')); ?>">
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
                    <button type="button" class="save-btn" id="btn-save-info">💾 Save Information</button>
                </div>

                <!-- ═══ Announcements (managed here) ═══ -->
                <div class="account-card" style="margin-top:28px;">
                    <h3>➕ New Announcement</h3>
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
                    <button type="button" class="save-btn" id="btn-add-announcement">💾 Publish Announcement</button>
                </div>
<br>
                <div class="account-card">
                    <h3>📋 All Announcements</h3>
                    <div id="ann-list">
                        <?php if (empty($announcements)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">📭</div>
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
                                            <?php echo $a['is_active'] ? '🙈 Hide' : '👁 Show'; ?>
                                        </button>
                                        <button type="button" class="btn-cancel-req"
                                            onclick="deleteAnnouncement(<?php echo (int)$a['id']; ?>)">🗑 Delete</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- ═══ Requestable documents (add / remove) ═══ -->
                <div class="account-card" style="margin-top:28px;">
                    <h3>📄 Requestable Documents</h3>
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
                    <button type="button" class="save-btn" id="btn-add-doc-type">➕ Add Document</button>

                    <div id="doc-type-list">
                        <p class="empty-state">Loading…</p>
                    </div>
                </div>
            </div>

            <!-- ════ VIEW 6: ACCOUNT INFORMATION ════ -->
            <div id="view-account" style="display:<?php echo $activeView === 'account' ? 'block' : 'none'; ?>;">
                <div class="page-banner">
                    <div class="page-banner-icon">👤</div>
                    <div class="page-banner-content">
                        <h1>Account <span>Information</span></h1>
                        <p>View and update your own administrator profile.</p>
                    </div>
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
                            <label for="photo-input" class="btn-upload">✏️ Change Photo</label>
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

                    <button type="button" class="save-btn" id="btn-save-account">💾 Save Changes</button>
                </div>
            </div>

            <!-- ════ VIEW 7: AUDIT LOGS ════ -->
            <div id="view-audit" style="display:<?php echo $activeView === 'audit' ? 'block' : 'none'; ?>;">
                <div class="page-banner">
                    <div class="page-banner-icon">🧾</div>
                    <div class="page-banner-content">
                        <h1>Audit <span>Logs</span></h1>
                        <p>Every login, request, release, and account change — recorded automatically.</p>
                    </div>
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
                                        <div class="empty-state"><div class="empty-icon">🗂️</div><p>No events match the current filters.</p></div>
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

        </div><!-- /main-container -->
    </div><!-- /body-layout -->

    <!-- ══ CREATE / EDIT ACCOUNT MODAL ══ -->
    <div id="addStudentModal" class="modal-overlay">
        <div class="modal-box modal-box--wide">
            <div class="modal-header">
                <h3 id="addModalTitle">➕ Create an Account</h3>
                <button class="modal-close" id="btn-close-modal" type="button">✕</button>
            </div>
            <div class="modal-body">

                <p class="form-section-title">Profile Photo</p>
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Profile Photo <span class="hint-inline">(JPG/PNG/GIF/WEBP · max 3 MB)</span></label>
                        <div class="photo-row">
                            <div id="modal-avatar-display" class="avatar-display"></div>
                            <div class="photo-controls">
                                <label for="f-photo" class="btn-upload">✏️ Choose Photo</label>
                                <input type="file" id="f-photo" class="hidden-input"
                                    accept="image/jpeg,image/png,image/gif,image/webp">
                                <div id="modal-photo-name" class="file-name"></div>
                                <button type="button" id="modal-photo-clear" class="btn-remove" style="display:none">✕ Remove</button>
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
                            <button type="button" class="pw-toggle">👁</button>
                        </div>
                        <span class="field-error" id="err-password"></span>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button id="btn-save-student" class="save-btn" type="button">
                    <span class="btn-label">💾 Save</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ══ VIEW DOCUMENTS MODAL ══ -->
    <div id="viewDocsModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="viewDocsTitle">📄 Document Requests</h3>
                <button id="btn-close-docs-modal" class="modal-close" type="button">✕</button>
            </div>
            <div class="modal-body" id="docs-modal-body">
                <p class="empty-state">Loading…</p>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>var CSRF_TOKEN = <?php echo json_encode($csrf); ?>;</script>
    <script src="admin.js"></script>
    <script src="../assets/js/session-timeout.js" defer></script>
</body>

</html>
