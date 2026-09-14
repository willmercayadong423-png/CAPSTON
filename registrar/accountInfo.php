<?php
require __DIR__ . "/../phpLogics/auth.php";
include(__DIR__ . "/../database/db.php");
include __DIR__ . "/../phpLogics/site_config.php";

require_role('registrar');

$user_id = (int)$_SESSION['user_id'];
$csrf    = csrf_token();

// ── Registrar info ────────────────────────────────────────────────
$s = $conn->prepare("SELECT * FROM students WHERE id = ?");
$s->bind_param("i", $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();

$myName     = htmlspecialchars(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
$myPhoto    = !empty($me['profile_photo']) ? '../' . htmlspecialchars($me['profile_photo']) : null;
$myInitials = strtoupper(substr($me['first_name'] ?? 'R', 0, 1) . substr($me['last_name'] ?? '', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Information — HEHMS</title>
    <link rel="stylesheet" href="registrarCSS.css">
    <link rel="stylesheet" href="accountInfo.css">
    <link rel="stylesheet" href="../assets/css/dark.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <?php echo theme_head(); // admin-managed brand color ?>
    <script src="../assets/js/dark.js"></script>
</head>

<body>

    <!-- ══ HEADER ══ -->
    <div class="header">
        <div class="header-left">
            <img src="<?php echo site_logo_url(); ?>" alt="School Logo" class="logo-img">
            <div class="school-info">
                <h2>Hilario E. Hermosa Memorial High School</h2>
                <p>Siclong Laur, Nueva Ecija</p>
            </div>
        </div>
        <div class="header-right">
            <a href="../phpLogics/Logout.php" class="logout-btn">↪ Logout</a>
        </div>
    </div>

    <div class="body-layout">

        <!-- ══ SIDEBAR ══ -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <p>Logged as</p>
                <h3><?php echo $myName; ?></h3>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-group-label">Main</div>
                <a href="registrarMainPage.php" class="nav-main-item requests">
                    <div class="nmi-icon">📋</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Student Requests</div>
                        <div class="nmi-sub">Accept &amp; verify requests</div>
                    </div>
                </a>
                <a href="accountInfo.php" class="nav-main-item records active">
                    <div class="nmi-icon">👤</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Account Information</div>
                        <div class="nmi-sub">Update your profile</div>
                    </div>
                </a>

                <div class="nav-divider"></div>
                <div class="nav-group-label">Tools</div>
                <a href="../phpLogics/setting.php" class="nav-link"><span class="nl-icon">⚙️</span> Settings</a>

                <a href="../phpLogics/Logout.php" class="nav-link"><span class="nl-icon">↪</span> Logout</a>
            </nav>
        </aside>

        <!-- ══ MAIN ══ -->
        <div class="main-container">
            <div class="title-section">
                <h1>Account <span>Information</span></h1>
                <p>View and update your own registrar profile.</p>
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
                        <label>New Password <span class="hint-inline">(leave blank to keep current)</span></label>
                        <input type="password" id="acc-password" placeholder="••••••••" autocomplete="new-password">
                    </div>
                </div>

                <button type="button" class="save-btn" id="btn-save-account">💾 Save Changes</button>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="footer-container">
            <div class="footer-center">
                <h3>&copy; 2026 Hilario E. Hermosa Memorial High School. All rights reserved.</h3>
                <p>Credential Request &amp; Tracking System</p>
            </div>
        </div>
    </footer>

    <div class="toast" id="toast"></div>

    <script>var CSRF_TOKEN = <?php echo json_encode($csrf); ?>;</script>
    <script src="accountInfo.js"></script>
</body>

</html>
