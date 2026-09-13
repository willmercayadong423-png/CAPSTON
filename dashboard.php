
<!--dashboard.php -->
<?php






require("phpLogics/auth.php");
include("database/db.php");


header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");

if (strtolower($_SESSION['role']) !== 'student') {
    header("Location: registrarMainPage.php");
    exit();
}

$student_id = $_SESSION['user_id'];

// ── CSRF token (one per session) ───────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool
{
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// ── Shared list of requestable document types (keep in sync everywhere) ──
// ── Documents requestable fully online ──
$documentTypes = [
    'Certificate of Registration',
    'Certificate of Enrollment',
    'Certificate of Grades',
    'Certificate of Good Moral',
    'Certificate of Transfer',
    'Certificate of Completion/Graduation',
     'SF10/Form 137',
    'Diploma',
    'YearBook',
];

$inPersonOnlyTypes = [
    'SF10/Form 137',
    'Diploma',
    'YearBook',
];


// ── Fetch student info ─────────────────────────────────────────────
$s = $conn->prepare("SELECT * FROM students WHERE id = ?");
$s->bind_param("i", $student_id);
$s->execute();
$student = $s->get_result()->fetch_assoc();
$s->close();

$studentName = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);

// ── Handle: Update Account Info ────────────────────────────────────
$updateSuccess = "";
$updateError   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $new_email   = trim($_POST['email']);
    $new_contact = trim($_POST['contact']);

    if (!verifyCsrf()) {
        $updateError = "Your session expired. Please try again.";
    } elseif (empty($new_email)) {
        $updateError = "Email address is required.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $updateError = "Please enter a valid email address.";
    } elseif ($new_contact !== '' && !preg_match('/^[0-9]{7,11}$/', $new_contact)) {
        $updateError = "Contact number must be 7–11 digits, numbers only.";
    } else {
        $chk = $conn->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
        $chk->bind_param("si", $new_email, $student_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $updateError = "That email is already used by another account.";
        } else {
            $new_photo = $student['profile_photo'] ?? null;

           
if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $img_allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $img_allowed_ext  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $img_type = mime_content_type($_FILES['profile_photo']['tmp_name']);
                $img_ext  = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                $img_size = $_FILES['profile_photo']['size'];

                if (!in_array($img_ext, $img_allowed_ext, true)) {
                    $updateError = "Profile photo must be a JPG, PNG, GIF, or WEBP file.";
                } elseif (!in_array($img_type, $img_allowed_mime, true)) {
                    $updateError = "Profile photo must be JPG, PNG, GIF, or WEBP.";
                } elseif ($img_size > 3 * 1024 * 1024) {
                    $updateError = "Profile photo must be under 3 MB.";
                } else {
                    $photo_dir = 'uploads/profile_photos/';
                    if (!is_dir($photo_dir)) mkdir($photo_dir, 0755, true);
                    $photo_fn   = 'student_' . $student_id . '_' . time() . '_' . rand(100, 999) . '.' . $img_ext;
                    $photo_dest = $photo_dir . $photo_fn;
                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $photo_dest)) {
                        if (!empty($student['profile_photo']) && file_exists($student['profile_photo'])) {
                            unlink($student['profile_photo']);
                        }
                        $new_photo = $photo_dest;
                    } else {
                        $updateError = "Failed to save profile photo. Please try again.";
                    }
                }
            }






// ── NEW: ID photo front/back (optional replacement) ────────────────
$allowed_img_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$allowed_img_ext   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$id_dir            = 'uploads/id_photos/';
if (!is_dir($id_dir)) mkdir($id_dir, 0755, true);

$new_id_front = $student['id_front'] ?? null;
$new_id_back  = $student['id_back']  ?? null;

foreach (['id_front' => &$new_id_front, 'id_back' => &$new_id_back] as $field => &$target) {
    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$field];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = mime_content_type($file['tmp_name']);

        if (!in_array($ext, $allowed_img_ext, true)) {
            $updateError = ucfirst(str_replace('_', ' ', $field)) . ": file extension not allowed.";
        } elseif (!in_array($mime, $allowed_img_types)) {
            $updateError = ucfirst(str_replace('_', ' ', $field)) . ": must be JPG, PNG, GIF, or WEBP.";
        } elseif ($file['size'] > 3 * 1024 * 1024) {
            $updateError = ucfirst(str_replace('_', ' ', $field)) . ": file exceeds 3 MB limit.";
        } else {
            $filename = $field . '_' . $student_id . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
            $dest     = $id_dir . $filename;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                if (!empty($target) && file_exists($target)) unlink($target);
                $target = $dest;
            } else {
                $updateError = ucfirst(str_replace('_', ' ', $field)) . ": failed to save. Please try again.";
            }
        }
    }
}
unset($target);






            if (empty($updateError)) {
    $new_lrn        = trim($_POST['lrn'] ?? '');
    $new_first_name = trim($_POST['first_name'] ?? '');
    $new_last_name  = trim($_POST['last_name'] ?? '');
    $new_grade      = trim($_POST['grade_level'] ?? '');
    $new_strand     = trim($_POST['strand'] ?? '');
    $new_sy         = trim($_POST['school_year_last_attended'] ?? '');
    $new_dob        = trim($_POST['date_of_birth'] ?? '');

    $upd = $conn->prepare("UPDATE students
        SET email=?, contact=?, profile_photo=?, id_front=?, id_back=?,
            lrn=?, first_name=?, last_name=?, grade_level=?, strand=?,
            school_year_last_attended=?, date_of_birth=?
        WHERE id=?");
   $upd->bind_param(
    "ssssssssssssi",
    $new_email, $new_contact, $new_photo, $new_id_front, $new_id_back,
    $new_lrn, $new_first_name, $new_last_name, $new_grade, $new_strand,
    $new_sy, $new_dob, $student_id
);
                if ($upd->execute()) {
                    $updateSuccess = "Account information updated successfully.";
                    $s2 = $conn->prepare("SELECT * FROM students WHERE id = ?");
                    $s2->bind_param("i", $student_id);
                    $s2->execute();
                    $student     = $s2->get_result()->fetch_assoc();
                    $s2->close();
                    $studentName = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
                    $_SESSION['email'] = $student['email'];
                } else {
                    $updateError = "Something went wrong. Please try again.";
                }
                $upd->close();
            }
        }
        $chk->close();
    }
}

// ── Handle: Cancel request ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request']) && verifyCsrf()) {
    $req_id = (int)$_POST['req_id'];
    $cancel = $conn->prepare(
        "UPDATE document_requests
         SET status = 'Cancelled', cancelled_by = 'student'
         WHERE id = ? AND student_id = ? AND status = 'Pending'"
    );
    $cancel->bind_param("ii", $req_id, $student_id);
    $cancel->execute();
    $cancel->close();
    header("Location: dashboard.php?view=requests&tab=archived");
    exit();
}

// ── Handle: Submit new request ─────────────────────────────────────
$successMsg = isset($_GET['success'])         ? "Your request has been submitted successfully!" : "";
$successMsg = isset($_GET['edit_success'])    ? "Request updated successfully."                 : $successMsg;
$successMsg = isset($_GET['restore_success']) ? "Request restored to active."                   : $successMsg;
$errorMsg   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $doc_type = trim($_POST['document_type']);
    $purpose  = trim($_POST['purpose']);

    if (!verifyCsrf()) {
        $errorMsg = "Your session expired. Please try again.";
    } elseif (empty($doc_type) || empty($purpose)) {
        $errorMsg = "Please fill in all required fields.";
    } elseif (!in_array($doc_type, $documentTypes, true)) {
        $errorMsg = "Please select a valid document type.";
    } else {

        // ── Limit: max 5 pending requests at once ──
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM document_requests WHERE student_id = ? AND status = 'Pending'"
        );
        $countStmt->bind_param("i", $student_id);
        $countStmt->execute();
        $pendingCount = $countStmt->get_result()->fetch_assoc()['cnt'];
        $countStmt->close();

        // ── Duplicate check: same document type already pending ──
        $dupStmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM document_requests
             WHERE student_id = ? AND document_type = ? AND status = 'Pending'"
        );
        $dupStmt->bind_param("is", $student_id, $doc_type);
        $dupStmt->execute();
        $dupCount = $dupStmt->get_result()->fetch_assoc()['cnt'];
        $dupStmt->close();

        if ($pendingCount >= 5) {
            $errorMsg = "You already have 5 pending requests. Please wait for one to be processed before submitting a new one.";
        } elseif ($dupCount > 0) {
            $errorMsg = "You already have a pending request for \"$doc_type\". Please wait for it to be processed.";
        } elseif (!isset($_FILES['id_photo']) || $_FILES['id_photo']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = "Please upload your Valid ID.";
        } else {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $max_size      = 5 * 1024 * 1024;
        $upload_dir    = 'uploads/request_requirements/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $upload_errors = [];
        $saved_files   = ['id_photo' => null, 'auth_letter' => null];

      $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

$id_file = $_FILES['id_photo'];
$id_type = mime_content_type($id_file['tmp_name']);
$id_ext  = strtolower(pathinfo($id_file['name'], PATHINFO_EXTENSION));

if (!in_array($id_ext, $allowed_ext, true)) {
    $upload_errors[] = "Valid ID: file extension not allowed.";
} elseif (!in_array($id_type, $allowed_types)) {
    $upload_errors[] = "Valid ID: invalid file type. Use JPG, PNG, GIF, WEBP, or PDF.";
} elseif ($id_file['size'] > $max_size) {
    $upload_errors[] = "Valid ID: file exceeds 5 MB limit.";
} else {
    $filename = 'id_photo_' . $student_id . '_' . time() . '_' . rand(100, 999) . '.' . $id_ext;
    if (move_uploaded_file($id_file['tmp_name'], $upload_dir . $filename)) {
        $saved_files['id_photo'] = $upload_dir . $filename;
    } else {
        $upload_errors[] = "Valid ID: failed to save. Please try again.";
    }
}

  if (isset($_FILES['auth_letter']) && $_FILES['auth_letter']['error'] === UPLOAD_ERR_OK) {
    $al_file = $_FILES['auth_letter'];
    $al_type = mime_content_type($al_file['tmp_name']);
    $al_ext  = strtolower(pathinfo($al_file['name'], PATHINFO_EXTENSION));

    if (!in_array($al_ext, $allowed_ext, true)) {
        $upload_errors[] = "Authorization Letter: file extension not allowed.";
    } elseif (!in_array($al_type, $allowed_types)) {
        $upload_errors[] = "Authorization Letter: invalid file type.";
    } elseif ($al_file['size'] > $max_size) {
        $upload_errors[] = "Authorization Letter: file exceeds 5 MB limit.";
    } else {
        $filename = 'auth_letter_' . $student_id . '_' . time() . '_' . rand(100, 999) . '.' . $al_ext;
        if (move_uploaded_file($al_file['tmp_name'], $upload_dir . $filename)) {
            $saved_files['auth_letter'] = $upload_dir . $filename;
        } else {
            $upload_errors[] = "Authorization Letter: failed to save. Please try again.";
        }
    }
}

        // ── Payment method ──
        $payment_method = isset($_POST['payment']) && in_array($_POST['payment'], ['Cash', 'Cashless'], true)
            ? $_POST['payment']
            : null;

        if (!$payment_method) {
            $upload_errors[] = "Please select a payment method.";
        }

        // ── Receipt (optional) ──
        $saved_files['receipt'] = null;

        if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $rc_file = $_FILES['receipt'];
            $rc_type = mime_content_type($rc_file['tmp_name']);
            $rc_ext  = strtolower(pathinfo($rc_file['name'], PATHINFO_EXTENSION));

            if (!in_array($rc_ext, $allowed_ext, true)) {
                $upload_errors[] = "Receipt: file extension not allowed.";
            } elseif (!in_array($rc_type, $allowed_types)) {
                $upload_errors[] = "Receipt: invalid file type.";
            } elseif ($rc_file['size'] > $max_size) {
                $upload_errors[] = "Receipt: file exceeds 5 MB limit.";
            } else {
                $filename = 'receipt_' . $student_id . '_' . time() . '_' . rand(100, 999) . '.' . $rc_ext;
                if (move_uploaded_file($rc_file['tmp_name'], $upload_dir . $filename)) {
                    $saved_files['receipt'] = $upload_dir . $filename;
                } else {
                    $upload_errors[] = "Receipt: failed to save. Please try again.";
                }
            }
        }

        if (!empty($upload_errors)) {
            $errorMsg = implode(' ', $upload_errors);
        } else {
            $payment_status = ($payment_method === 'Cashless' && $saved_files['receipt'])
                ? 'Pending Verification'
                : 'Unpaid';

            $ins = $conn->prepare(
                "INSERT INTO document_requests
                    (student_id, document_type, purpose, id_photo, auth_letter, payment_method, receipt, payment_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->bind_param(
                "isssssss",
                $student_id,
                $doc_type,
                $purpose,
                $saved_files['id_photo'],
                $saved_files['auth_letter'],
                $payment_method,
                $saved_files['receipt'],
                $payment_status
            );
               if ($ins->execute()) {
                header("Location: dashboard.php?success=1&view=requests");
                exit();
            } else {
                $errorMsg = "Something went wrong saving your request. Please try again.";
            }
            $ins->close();
        }
        }
    }
}

// ── Fetch requests ─────────────────────────────────────────────────
$req = $conn->prepare("SELECT * FROM document_requests WHERE student_id = ? ORDER BY date_requested DESC");
$req->bind_param("i", $student_id);
$req->execute();
$myRequests = $req->get_result()->fetch_all(MYSQLI_ASSOC);
$req->close();

$mainRequests     = [];
$archivedRequests = [];
foreach ($myRequests as $r) {
    if (in_array($r['status'], ['Released', 'Cancelled'])) {
        $archivedRequests[] = $r;
    } else {
        $mainRequests[] = $r;
    }
}

$cntTotal   = count($myRequests);
$cntPending = 0;
$cntReady   = 0;
$cntProcessing = 0;
$cntCancelled = 0;
foreach ($myRequests as $r) {
    if ($r['status'] === 'Pending')          $cntPending++;
    if ($r['status'] === 'Ready for Pickup') $cntReady++;
    if ($r['status'] === 'Processing')       $cntProcessing++;
    if ($r['status'] === 'Cancelled')        $cntCancelled++;
}

function badgeClass($status, $cancelledBy = '')
{
    if ($status === 'Cancelled') {
        if ($cancelledBy === 'registrar') return 'rejected';
        if ($cancelledBy === 'unclaimed') return 'unclaimed';
        return 'unclaimed'; // student-cancelled also shows as unclaimed
    }
    return match ($status) {
        'Ready for Pickup' => 'ready',
        'Processing'       => 'processing',
        'Pending'          => 'pending',
        'Released'         => 'released',
        default            => 'pending',
    };
}

$activeView     = $_GET['view'] ?? 'dashboard';
$activeTab      = $_GET['tab']  ?? 'main';
$profilePhoto   = !empty($student['profile_photo']) ? $student['profile_photo'] : null;
$avatarInitials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard — HEHMS</title>
    <script>
        // One-time cleanup: dark mode has been removed, but some browsers
        // may still have 'hehms-theme: dark' saved from before. Clear it
        // so nobody gets stuck with no way to switch it off.
        localStorage.removeItem('hehms-theme');
        document.documentElement.removeAttribute('data-theme');
    </script>
    <link rel="stylesheet" href="css/dashb.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    
</head>

<body>

    <!-- ══ HEADER ══ -->
    <div class="header">
        <a href="dashboard.php" class="header-left">
    <img src="img/Logo.png" alt="School Logo" class="logo-img">

    <div class="school-info">
        <h2>Hilario E. Hermosa Memorial High School</h2>
        <p>Siclong Laur, Nueva Ecija</p>
    </div>
</a>

        
      <div class="header-right">

    <!-- Student Name -->
   <div class="header-profile-name">
    <span><?php echo $studentName; ?></span>
</div>

    <!-- Avatar -->
    <div class="header-avatar-wrapper">

      <div class="header-avatar"
     onclick="toggleProfileMenu(event)"
     style="cursor:pointer;">
    <?php if ($profilePhoto): ?>
        <img src="<?php echo htmlspecialchars($profilePhoto); ?>" alt="avatar">
    <?php else: ?>
        <?php echo $avatarInitials; ?>
    <?php endif; ?>
</div>

        <div class="profile-dropdown" id="profileDropdown">
            <a href="#" onclick="showView('account'); closeProfileMenu();">
                👤 Account Information
            </a>

            <a href="phpLogics/Logout.php">
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
            <?php if ($profilePhoto): ?>
                <img src="<?php echo htmlspecialchars($profilePhoto); ?>" alt="Profile Avatar">
            <?php else: ?>
                <?php echo htmlspecialchars($avatarInitials); ?>
            <?php endif; ?>
        </div>

        <div class="welcome-info">
            <span class="welcome-text">Welcome back</span>
            <h3><?php echo htmlspecialchars($studentName); ?></h3>
         
        </div>

    </div>
</div>
            <nav class="sidebar-nav">
                <div class="nav-group-label">Main Menus</div>

  <a onclick="showView('dashboard')" id="nav-dashboard"
                    class="nav-main-item dashboard <?php echo $activeView === 'dashboard' ? 'active' : ''; ?>">
                    <div class="nmi-icon">🏠</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Dashboard</div>
                        
                    </div>
                </a>
           


  <a onclick="showView('requests')" id="nav-requests"
                    class="nav-main-item requests <?php echo $activeView === 'requests' ? 'active' : ''; ?>">
                    <div class="nmi-icon">📋</div>
                    <div class="nmi-text">
                        <div class="nmi-title">My Requests</div>
                        
                    </div>
                </a>



                          <a onclick="showView('request')" id="nav-request"
   class="nav-main-item <?php echo $activeView === 'request' ? 'active' : ''; ?>">
    <div class="nmi-icon">📝</div>
    <div class="nmi-text">
        <div class="nmi-title">Request</div>
       
    </div>
</a>
                <a onclick="showView('account')" id="nav-account"
                    class="nav-main-item records <?php echo $activeView === 'account' ? 'active' : ''; ?>">
                    <div class="nmi-icon">👤</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Account Information</div>
                       
                    </div>
                </a>
      
                <div class="nav-divider"></div>
                <div class="nav-group-label">Others</div>
            <a href="phpLogics/Logout.php" class="nav-main-item logout">
    <div class="nmi-icon">↩️</div>
    <div class="nmi-text">
        <div class="nmi-title">LOGOUT</div>
       
    </div>
</a>
                   

            </nav>
        </aside>
        



        

        <!-- ══ MAIN ══ -->
        <div class="main-container">



<!-- ════ VIEW 1: Dashboard ════ -->
<div id="view-dashboard" style="display:<?php echo $activeView === 'dashboard' ? 'block' : 'none'; ?>;">

    <!-- Page Banner -->
    <div class="page-banner">
        <div class="page-banner-icon">🏠</div>

        <div class="page-banner-content">
            <h1>Student <span>Dashboard</span></h1>
            <p>Welcome to the HEHMS Online Credential Request System.</p>
        </div>
    </div>

   <!-- Welcome Card -->
<div class="dashboard-card">
    <h2 class="card-title">You can request school documents, monitor your request status, and stay updated with school announcements.</h2>
    <Br>
    <div class="cards">
        <div class="card"><span class="card-icon">📋</span><h4>Total Requests</h4><h2><?php echo $cntTotal; ?></h2></div>
        <div class="card"><span class="card-icon">⏳</span><h4>Pending</h4><h2><?php echo $cntPending; ?></h2></div>
        <div class="card"><span class="card-icon">⚙️</span><h4>Processing</h4><h2><?php echo $cntProcessing; ?></h2></div>
        <div class="card"><span class="card-icon">📦</span><h4>Ready to Pickup</h4><h2><?php echo $cntReady; ?></h2></div>
        <div class="card"><span class="card-icon">❌</span><h4>Cancelled</h4><h2><?php echo $cntCancelled; ?></h2></div>
    </div>

    <div class="dashboard-grid">
        <div class="dashboard-card">
            <h3>📢 Announcements</h3>
            <ul class="dashboard-list">
                <li>No new announcements.</li>
                <li>Check back regularly for updates.</li>
            </ul>
        </div>

        <div class="dashboard-card">
            <h3>🕒 Office Hours</h3>
            <p>Monday – Friday </p>
            <p>8:00 AM – 4:00 PM</p>
            <p style="margin-top:10px;">Closed during weekends and holidays.</p>
        </div>

        <div class="dashboard-card full-width">
            <h3>☎ Contact Information</h3>
            <p><strong>Registrar's Office</strong></p>
            <p>Email: registrar@hehms.edu.ph</p>
            <p>Phone: (044) 123-4567</p>
            <p>Location: Hilario E. Hermosa Memorial High School, Laur, Nueva Ecija</p>
        </div>
    </div>

    <button class="save-btn" type="button" onclick="showView('request')">📝 Request New Document</button>
</div>
</div>












            <!-- ════ VIEW 2: REQUESTS ════ -->
            <div id="view-requests" style="display:<?php echo $activeView === 'requests' ? 'block' : 'none'; ?>;">
                <div class="page-banner">
    <div class="page-banner-icon">
        📋
    </div>

    <div class="page-banner-content">
        <h1>My <span>Requests</span></h1>
        <p>Track the status of your requested documents.</p>
    </div>
</div>
                <?php if ($successMsg): ?>
                    <div class="alert alert-success">✔ <?php echo htmlspecialchars($successMsg); ?></div>
                <?php endif; ?>

              

                <div class="switch-btn">
                    <a class="<?php echo $activeTab === 'main' ? 'active' : ''; ?>" id="tab-main" onclick="switchTable('main')">Main</a>
                    <a class="<?php echo $activeTab === 'archived' ? 'active' : ''; ?>" id="tab-archived" onclick="switchTable('archived')">History</a>
                </div>

                <!-- Active requests -->
                <div class="container" id="tbl-main" style="display:<?php echo $activeTab === 'archived' ? 'none' : ''; ?>;">
                    <div class="table-header">
                        <h3>Active Requests</h3>
                        <div class="table-header-controls">
                            <div class="search-wrapper">
                                <span class="search-icon">🔍</span>
                                <input type="text" class="search" placeholder="Search requests..."
                                    oninput="filterRows('main-tbody', this.value)">
                            </div>
                            <button class="btn-new-request" onclick="showView('request')">✚ New Request</button>
                        </div>
                    </div>
                    <table class="tbl-header-table">
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Name</th>
                                <th>Document</th>
                                <th>Date Requested</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                    </table>
                    <div class="table-scroll-body">
                        <table>
                            <tbody id="main-tbody">
                                <?php if (empty($mainRequests)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <div class="empty-icon">📭</div>
                                                <p>No active requests.<br>Click <strong>Request Document</strong> to get started.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: foreach ($mainRequests as $r):
                                        $cls     = badgeClass($r['status'], $r['cancelled_by'] ?? '');
                                        $padId   = 'REQ-' . str_pad($r['id'], 3, '0', STR_PAD_LEFT);
                                        $dateReq = date('m/d/Y', strtotime($r['date_requested']));
                                        $canAct  = ($r['status'] === 'Pending');
                                        $existId = !empty($r['id_photo'])    ? $r['id_photo']    : '';
                                        $existAl = !empty($r['auth_letter']) ? $r['auth_letter'] : '';
                                    ?>
                                        <tr>
                                            <td><strong><?php echo $padId; ?></strong></td>
                                            <td><?php echo $studentName; ?></td>
                                            <td><?php echo htmlspecialchars($r['document_type']); ?></td>
                                            <td><?php echo $dateReq; ?></td>
                                            <td><span class="badge <?php echo $cls; ?>"><?php echo $r['status']; ?></span></td>
                                            <td>
                                                <div class="action-group">
                                                    <?php if ($canAct): ?>
                                                        <button class="btn-edit"
    data-req-id="<?php echo (int)$r['id']; ?>"
    data-doc-type="<?php echo htmlspecialchars($r['document_type'], ENT_QUOTES); ?>"
    data-purpose="<?php echo htmlspecialchars($r['purpose'], ENT_QUOTES); ?>"
    data-id-photo="<?php echo htmlspecialchars($existId, ENT_QUOTES); ?>"
    data-auth-letter="<?php echo htmlspecialchars($existAl, ENT_QUOTES); ?>">
    Edit
</button>
                                                        <form method="POST" style="display:inline;"
                                                            onsubmit="return confirm('Cancel this request?');">
                                                            <input type="hidden" name="cancel_request" value="1">
                                                            <input type="hidden" name="req_id" value="<?php echo $r['id']; ?>">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                            <button type="submit" class="btn-cancel-req">Cancel</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="btn-na">Edit</span>
                                                        <span class="btn-na">Cancel</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Requests History -->
                <div class="container" id="tbl-archived" style="display:<?php echo $activeTab === 'archived' ? '' : 'none'; ?>;">
                    <div class="table-header">
                        <h3>Requests History</h3>
                        <div class="search-wrapper">
                            <span class="search-icon">🔍</span>
                            <input type="text" class="search" placeholder="Search archived..."
                                oninput="filterRows('archived-tbody', this.value)">
                        </div>
                    </div>
                    <table class="tbl-header-table">
                        <thead>
                            <tr>
                                <th>Req ID</th>
                                <th>Document</th>
                                <th>Date Requested</th>
                                <th>Date Released</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                    </table>
                    <div class="table-scroll-body">
                        <table>
                            <tbody id="archived-tbody">
                                <?php if (empty($archivedRequests)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <div class="empty-icon">📁</div>
                                                <p>No archived requests yet.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: foreach ($archivedRequests as $r):
                                        $cancelledBy = trim($r['cancelled_by'] ?? '');
                                        $cls         = badgeClass($r['status'], $cancelledBy);
                                        $padId       = 'REQ-' . str_pad($r['id'], 3, '0', STR_PAD_LEFT);
                                        $dateReq     = date('m/d/Y', strtotime($r['date_requested']));
                                        $dateRel     = !empty($r['date_released']) ? date('m/d/Y', strtotime($r['date_released'])) : null;

                                        // Determine the exact cancellation type
                                        $byRegistrar = ($r['status'] === 'Cancelled' && $cancelledBy === 'registrar');
                                        $byUnclaimed = ($r['status'] === 'Cancelled' && $cancelledBy === 'unclaimed');
                                        $byStudent   = ($r['status'] === 'Cancelled' && ($cancelledBy === 'student' || $cancelledBy === ''));

                                        // Only student-cancelled requests can be restored
                                        $canRestore  = $byStudent;

                                        // Label to display in the Status column
                                        $statusLabel = $r['status'];
                                        if ($byRegistrar) $statusLabel = 'Rejected';
                                        if ($byUnclaimed) $statusLabel = 'Unclaimed';
                                        if ($byStudent)   $statusLabel = 'Cancelled';
                                    ?>
                                        <tr>
                                            <td><strong><?php echo $padId; ?></strong></td>
                                            <td><?php echo htmlspecialchars($r['document_type']); ?></td>
                                            <td><?php echo $dateReq; ?></td>
                                            <td><?php echo $dateRel ? $dateRel : '<span class="date-na">—</span>'; ?></td>
                                            <td>
                                                <span class="badge <?php echo $cls; ?>"><?php echo $statusLabel; ?></span>
                                                <?php if ($byRegistrar): ?>
                                                    <span class="badge-rejected">Rejected by Registrar</span>
                                                <?php elseif ($byUnclaimed): ?>
                                                    <span class="badge-unclaimed">Not picked up</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($canRestore): ?>
                                                    <form method="POST" action="phpLogics/restoreReq.php" style="display:inline;"
                                                        onsubmit="return confirm('Restore this request to active?');">
                                                        <input type="hidden" name="restore_request" value="1">
                                                        <input type="hidden" name="req_id" value="<?php echo $r['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <button type="submit" class="btn-restore">↩ Restore</button>
                                                    </form>
                                                <?php elseif ($byRegistrar): ?>
                                                    <span class="btn-rejected">🚫 Rejected</span>
                                                <?php elseif ($byUnclaimed): ?>
                                                    <span class="btn-unclaimed">📦 Unclaimed</span>
                                                <?php else: ?>
                                                    <span class="btn-closed">✔ Closed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div><!-- /view-requests -->




<!-- ════ VIEW 3: Request Document ════ -->


    <!-- Page Banner -->
   

 <div id="view-request" style="display:<?php echo $activeView === 'request' ? 'block' : 'none'; ?>;">
    <div class="page-banner">
        <div class="page-banner-icon">📝</div>
        <div class="page-banner-content">
            <h1>Request <span>Document</span></h1>
            <p>Submit a request for school credentials.</p>
        </div>
    </div>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger">⚠️ <?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" id="request-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="document_type" id="selected-document-input" value="">
        <input type="hidden" name="submit_request" value="1">

        <!-- ===== STEP 1: DOCUMENT CHOICE ===== -->
        <div id="step-document">
            <div class="document-card">
                <h2 class="card-title">What document do you need?</h2>
              
     <div class="card-note">
    <p>
        <strong>📌 Note:</strong><br>
        <ul class="note-list">
            <li>Only the documents listed below can be requested online.</li>
            <li>You may have a maximum of 5 pending requests at a time.</li>
            <li>You cannot submit a new request for a document type while a previous request for it is still pending.</li>
        </ul>
    </p>

    <hr>

    <p>
        <strong>📢 Reminder:</strong><br>
        If your parent, guardian, or an authorized representative will claim your requested document, please prepare the following:
    </p>
    <ul class="note-list">
        <li>Authorization Letter signed by the student.</li>
        <li>Valid ID of the student (photocopy or scanned copy, if required).</li>
        <li>Valid ID of the authorized representative.</li>
    </ul>
</div>

          <div class="document-grid">
    <?php foreach ($documentTypes as $dt): ?>
        <button type="button" class="doc-btn"
            onclick="selectDocument('<?php echo htmlspecialchars($dt, ENT_QUOTES); ?>')">
            📄 <?php echo htmlspecialchars($dt); ?>
        </button>
    <?php endforeach; ?>
</div>
<br>
<div class="in-person-note">
    <p><strong>🌐 Request Online, Collect In-Person:</strong> 
</p>
    <ul class="note-list">
        <?php foreach ($inPersonOnlyTypes as $ip): ?>
            <li><?php echo htmlspecialchars($ip); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
            

            </div>
        </div>



        <!-- ===== STEP 2: REQUEST FORM ===== -->
        <div id="step-form" style="display:none;">
            <div class="account-card">

                <div class="selected-doc-banner" id="selected-doc-banner">
                    <span>Requesting: <strong id="selected-doc-label"></strong></span>
                    <button type="button" class="btn-restore" onclick="backToStep1()">Change document</button>
                </div>

                <div class="acct-form-grid" style="margin-top:18px;">
                    <div class="acct-form-group">
                        <label>Student Name</label>
                        <input type="text" value="<?php echo $studentName; ?>" readonly class="input-readonly">
                    </div>
                    <div class="acct-form-group">
                        <label>Student ID</label>
                        <input type="text" value="<?php echo htmlspecialchars($student['student_id']); ?>" readonly class="input-readonly">
                    </div>
                    <div class="acct-form-group full">
                        <label>Purpose / Reason <span class="req-star">*</span></label>
                        <input type="text" name="purpose" id="purpose-input" placeholder="e.g. College application">
                    </div>

                    <div class="acct-form-group full">
                        <label>Supporting Documents
                            <span class="upload-hint">JPG, PNG, GIF, WEBP or PDF · max 5 MB</span>
                        </label>
                        <div class="req-uploads-grid">
                            <div class="req-upload-card" id="new-card-id"
                                ondragover="cardDragOver(event,'new-card-id')"
                                ondragleave="cardDragLeave('new-card-id')"
                                ondrop="cardDrop(event,'new-card-id','new-id-input','new-id-name','new-id-preview')">
                                <input type="file" name="id_photo" id="new-id-input"
                                    accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                                    onchange="cardFileSelected(this,'new-card-id','new-id-name','new-id-preview')">
                                <button type="button" class="ruc-remove-btn"
                                    onclick="cardRemoveFile(event,'new-card-id','new-id-input','new-id-name','new-id-preview')">✕</button>
                                <span class="ruc-check">✅</span>
                                <span class="ruc-icon">🪪</span>
                                <span class="ruc-title">Valid ID</span>
                                <span class="ruc-required">Required</span>
                                <p class="ruc-hint">School ID, Government ID,<br>or any valid photo ID</p>
                                <div class="ruc-preview" id="new-id-preview"></div>
                                <div class="ruc-filename" id="new-id-name"></div>
                            </div>

                            <div class="req-upload-card" id="new-card-al"
                                ondragover="cardDragOver(event,'new-card-al')"
                                ondragleave="cardDragLeave('new-card-al')"
                                ondrop="cardDrop(event,'new-card-al','new-al-input','new-al-name','new-al-preview')">
                                <input type="file" name="auth_letter" id="new-al-input"
                                    accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                                    onchange="cardFileSelected(this,'new-card-al','new-al-name','new-al-preview')">
                                <button type="button" class="ruc-remove-btn"
                                    onclick="cardRemoveFile(event,'new-card-al','new-al-input','new-al-name','new-al-preview')">✕</button>
                                <span class="ruc-check">✅</span>
                                <span class="ruc-icon">📄</span>
                                <span class="ruc-title">Authorization Letter</span>
                                <span class="ruc-optional">Optional</span>
                                <p class="ruc-hint">If the parent or guardian will claim the document on behalf of the student.</p>
                                <div class="ruc-preview" id="new-al-preview"></div>
                                <div class="ruc-filename" id="new-al-name"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" class="save-btn" onclick="proceedToPayment()">Continue to payment →</button>
            </div>
        </div>

        <!-- ===== STEP 3: PAYMENT ===== -->
        <div id="step-payment" style="display:none;">
            <div class="payment-card">
                <h2>💳 Choose Payment Method</h2>

                <label class="payment-option">
                    <input type="radio" name="payment" value="Cash" required>
                    <div><strong>Cash</strong><p>Pay at the Registrar's Office during pickup.</p></div>
                </label>

                <label class="payment-option">
                    <input type="radio" name="payment" value="Cashless">
                    <div><strong>Cashless</strong><p>Pay Online through GCash/Maya Wallet.</p></div>
                </label>

                <div style="display:flex; gap:10px; margin-top:18px;">
                    <button type="button" class="btn-edit" onclick="backToStep2()">← Back</button>
                    <button type="button" class="save-btn" style="flex:1;" onclick="proceedPayment()">
    ✅ Proceed
</button>
                </div>
            </div>
        </div>


<!-- ===== STEP 4: CASHLESS PAYMENT ===== -->
<div id="step-cashless" style="display:none;">
    <div class="payment-card">
        <h2>📲 Cashless Payment</h2>
        <p>Please scan one of the QR codes below to complete your payment.</p>
<br>
        <div class="price-list">
            <p class="price-list-title">Prices of requested documents</p>
            <ul>
                <li><span>COE, COG, COR</span><span>₱20.00</span></li>
                <li><span>Completion / Graduation</span><span>₱100.00</span></li>
                <li><span>Transfer, Good Moral</span><span>₱30.00</span></li>
            </ul>
        </div>

        <div class="qr-payment-grid">

            <div class="qr-payment-card">
                <div class="qr-img-wrap">
                    <img src="img/gc.jpg" alt="GCash QR">
                </div>
                <p class="qr-label">GCash</p>
            </div>

            <div class="qr-payment-card">
                <div class="qr-img-wrap">
                    <img src="img/maya.jfif" alt="Maya QR">
                </div>
                <p class="qr-label">Maya</p>
            </div>

        </div>
<br>
        <p class="qr-alt-number">
            Alternative number for GCash/Maya: <strong>09123456789</strong>
        </p>
        <p class="qr-alt-number">
            Alternative number for GCash/Maya: <strong>09123456789</strong>
        </p>
        <p class="qr-alt-number">
            Alternative number for GCash/Maya: <strong>09123456789</strong>
        </p>




        <div class="qr-notes">
            <p class="qr-note">After payment, click <strong>"Attach Receipt"</strong> below and upload your receipt to confirm your transaction.</p>
            <p class="qr-note">The receipt will be verified by the registrar.</p>
        </div>

      <!-- ===== RECEIPT UPLOAD ===== -->
        <div class="acct-form-group full" style="margin-top:20px;">
            <label>Payment Receipt </label>
            <div class="req-upload-card" id="receipt-card"
                ondragover="cardDragOver(event,'receipt-card')"
                ondragleave="cardDragLeave('receipt-card')"
                ondrop="cardDrop(event,'receipt-card','receipt-input','receipt-name','receipt-preview')">
                <input type="file" name="receipt" id="receipt-input"
                    accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                    onchange="cardFileSelected(this,'receipt-card','receipt-name','receipt-preview')">
                <button type="button" class="ruc-remove-btn"
                    onclick="cardRemoveFile(event,'receipt-card','receipt-input','receipt-name','receipt-preview')">✕</button>
                <span class="ruc-check">✅</span>
                <span class="ruc-icon">🧾</span>
                <span class="ruc-title">Attach Receipt</span>
                <p class="ruc-hint">Screenshot or photo of your payment confirmation</p>
                <div class="ruc-preview" id="receipt-preview"></div>
                <div class="ruc-filename" id="receipt-name"></div>
            </div>
        </div>

        <div style="display:flex; gap:10px; margin-top:22px;">
            <button type="button" class="btn-edit" onclick="backToPayment()">← Back</button>
            <button type="button" class="save-btn" style="flex:1;" onclick="submitCashlessPayment()">✅ I Have Paid</button>
        </div>
    </div>
</div>  
      
    </form>
</div>







       <!-- ════ VIEW 3: ACCOUNT ════ -->
<div id="view-account" style="display:<?php echo $activeView === 'account' ? 'block' : 'none'; ?>;">

    <div class="page-banner">
        <div class="page-banner-icon">📝</div>
        <div class="page-banner-content">
            <h1>Account <span>Information</span></h1>
            <p>View and update your contact details.</p>
        </div>
    </div>

    <?php if ($updateSuccess): ?>
        <div class="alert alert-success">✔ <?php echo htmlspecialchars($updateSuccess); ?></div>
    <?php endif; ?>
    <?php if ($updateError): ?>
        <div class="alert alert-danger">⚠️ <?php echo htmlspecialchars($updateError); ?></div>
    <?php endif; ?>

    <div class="account-card">
        <form method="POST" enctype="multipart/form-data" id="account-form">
            <input type="hidden" name="update_account" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <!-- ═══════════════════ 👤 PROFILE ═══════════════════ -->
            <div class="form-section-label">👤 Profile</div>

            <div class="avatar-section">
                <div class="avatar-ring">
                    <div class="account-avatar" id="avatar-display">
                        <?php if ($profilePhoto): ?>
                            <img src="<?php echo htmlspecialchars($profilePhoto); ?>" alt="Profile photo"
                                onerror="this.remove(); document.getElementById('avatar-fallback-initials').style.display='flex';">
                            <span id="avatar-fallback-initials" style="display:none;"><?php echo $avatarInitials; ?></span>
                        <?php else: ?>
                            <span id="avatar-fallback-initials"><?php echo $avatarInitials; ?></span>
                        <?php endif; ?>
                    </div>
                    <label for="profile-photo-input" class="avatar-edit-label" title="Change photo">✏️</label>
                </div>
                <input type="file" name="profile_photo" id="profile-photo-input"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    onchange="previewAvatar(this)">
                <p class="avatar-hint">Click ✏️ to change photo (JPG/PNG/GIF/WEBP · max 3 MB)</p>
                <div class="avatar-new-preview" id="avatar-new-preview">
                    <img id="avatar-new-thumb" src="" alt="New photo preview">
                    <span id="avatar-new-name"></span>
                    <button type="button" class="remove-photo" onclick="clearAvatar()">✕ Remove</button>
                </div>
            </div>

           <div class="acct-form-grid">
    <div class="acct-form-group full">
        <label>Name <span class="account-role-badge-inline">Student</span></label>
        <input type="text" name="student_name"
            value="<?php echo htmlspecialchars($studentName); ?>">
    </div>
</div>
             <div class="acct-form-group">
    <label>Student ID Number 🔒</label>
    <input
        type="text"
        value="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>"
        readonly
        class="readonly-field">
</div>                 
            <div class="account-divider"></div>

            <!-- ═══════════════════ ACADEMIC INFORMATION ═══════════════════ -->
            <div class="form-section-label">Academic Information</div>
            <div class="acct-form-grid">

                <div class="acct-form-group">
                    <label>LRN (Learner Reference Number)</label>
                    <input type="text" name="lrn" inputmode="numeric" maxlength="12"
                        value="<?php echo htmlspecialchars($student['lrn'] ?? ''); ?>"
                        placeholder="12-digit LRN">
                </div>

                

                <div class="acct-form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name"
                        value="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>" required>
                </div>

                <div class="acct-form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name"
                        value="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>" required>
                </div>

           <div class="acct-form-group">
    <label>Grade Level</label>
    <input type="text" name="grade_level"
        value="<?php echo htmlspecialchars($student['grade_level'] ?? ''); ?>"
        placeholder="e.g. Grade 10">
</div>
                <div class="acct-form-group">
                    <label>Strand / Track</label>
                    <input type="text" name="strand"
                        value="<?php echo htmlspecialchars($student['strand'] ?? ''); ?>"
                        placeholder="e.g. STEM, ABM, HUMSS">
                </div>

                <div class="acct-form-group">
                    <label>School Year Last Attended</label>
                    <input type="text" name="school_year_last_attended"
                        value="<?php echo htmlspecialchars($student['school_year_last_attended'] ?? ''); ?>"
                        placeholder="e.g. 2024-2025">
                </div>

                <div class="acct-form-group">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth"
                        value="<?php echo !empty($student['date_of_birth']) ? htmlspecialchars(date('Y-m-d', strtotime($student['date_of_birth']))) : ''; ?>">
                </div>

            </div>

            

            <!-- ═══════════════════ CONTACT INFORMATION ═══════════════════ -->
            <div class="form-section-label">Contact Information</div>
            <div class="acct-form-grid">
                <div class="acct-form-group full">
                    <label>Email <span class="req-star">*</span></label>
                    <input type="email" name="email"
                        value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" required>
                </div>
                <div class="acct-form-group full">
                    <label>Phone Number</label>
                    <input type="text" name="contact" inputmode="numeric"
                        pattern="[0-9]{7,11}" title="7–11 digits, numbers only"
                        value="<?php echo htmlspecialchars($student['contact'] ?? ''); ?>"
                        placeholder="e.g. 09171234567" maxlength="11">
                </div>
            </div>

            <button type="submit" class="save-btn">💾 Save Changes</button>
        </form>
    </div>
</div><!-- /view-account -->

<style>
.verify-badge {
    display: inline-block;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
    margin-top: 4px;
}
.verify-badge-verified {
    background: #d1fae5;
    color: #065f46;
}
.verify-badge-pending {
    background: #fef3c7;
    color: #92400e;
}
.verify-badge-none {
    background: #f3f4f6;
    color: #6b7280;
}

/* ── Make the Grade Level dropdown look like the text inputs beside it ── */
.acct-form-group .acct-select {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    width: 100%;
    box-sizing: border-box;
    font: inherit;
    color: inherit;
    background-color: #fff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 10px 36px 10px 12px;
    height: 44px;
    line-height: 1.2;
    cursor: pointer;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'><path d='M1 1l6 6 6-6' stroke='%23667085' stroke-width='2' fill='none' fill-rule='evenodd' stroke-linecap='round' stroke-linejoin='round'/></svg>");
    background-repeat: no-repeat;
    background-position: right 12px center;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.acct-form-group .acct-select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}
.acct-form-group .acct-select:hover {
    border-color: #9ca3af;
}

/* ── Upload cards: hide the raw native file input (was showing "No file chosen") ── */
.req-upload-card {
    position: relative;
    cursor: pointer;
}
.req-upload-card .ruc-file-input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    overflow: hidden;
    pointer-events: none;
}
.req-upload-card .ruc-remove-btn {
    position: relative;
    z-index: 2;
}

/* Two emoji (🪪 and ✅) aren't in every system font and were rendering as
   empty boxes — swapping them for inline SVG so they always show up. */
.ruc-svg-id, .ruc-svg-check {
    display: inline-block;
    width: 28px;
    height: 28px;
    background-repeat: no-repeat;
    background-position: center;
    background-size: contain;
}
.ruc-svg-id {
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='1.6'><rect x='2.5' y='5' width='19' height='14' rx='2.5'/><circle cx='8' cy='12' r='2.2'/><line x1='13' y1='9.5' x2='18.5' y2='9.5'/><line x1='13' y1='12.5' x2='18.5' y2='12.5'/><line x1='5.5' y1='16' x2='10.5' y2='16'/></svg>");
}
.ruc-svg-check {
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10' fill='%23dcfce7' stroke='none'/><path d='M7 12.5l3 3 7-7'/></svg>");
}

/* ── Avatar: make sure the photo actually renders inside the circle ── */
.account-avatar {
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eef0f4;
}
.account-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
#avatar-fallback-initials {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    font-weight: 600;
}
.avatar-new-preview {
    display: none;
    align-items: center;
    gap: 8px;
}
.avatar-new-preview.visible {
    display: flex;
}
#avatar-new-thumb {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    display: none;
}
#avatar-new-thumb.visible {
    display: block;
}
</style>

<script>
function previewAvatar(input) {
    const file = input.files && input.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function (e) {
        // Swap the main circular avatar to show the newly picked photo immediately
        const display = document.getElementById('avatar-display');
        display.innerHTML = '<img src="' + e.target.result + '" alt="Profile photo preview">';

        // Show the small "new file" chip under the avatar
        const thumb = document.getElementById('avatar-new-thumb');
        thumb.src = e.target.result;
        thumb.classList.add('visible');

        document.getElementById('avatar-new-name').textContent = file.name;
        document.getElementById('avatar-new-preview').classList.add('visible');
    };
    reader.readAsDataURL(file);
}

function clearAvatar() {
    const input = document.getElementById('profile-photo-input');
    input.value = '';
    document.getElementById('avatar-new-preview').classList.remove('visible');
    document.getElementById('avatar-new-thumb').classList.remove('visible');
    document.getElementById('avatar-new-thumb').src = '';
    document.getElementById('avatar-new-name').textContent = '';
    // Note: this does not restore the original saved photo view if it was replaced above;
    // reloading the page will show the saved photo again since nothing is submitted yet.
}
</script>



        </div><!-- /main-container -->
    </div><!-- /body-layout -->

    <!-- ══ EDIT REQUEST MODAL ══ -->
    <div class="modal-overlay" id="editModal" onclick="closeEditModal(event)">
        <div class="modal-box modal-box--wide">
            <div class="modal-header">
                <h3>Edit Request</h3>
                <button class="modal-close" onclick="closeEditModal()">✕</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="phpLogics/editReq.php" enctype="multipart/form-data">
                    <input type="hidden" name="req_id" id="edit-req-id">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Document Type <span class="req-star">*</span></label>
                            <select name="document_type" id="edit-doc-type" required>
                                <?php foreach ($documentTypes as $dt): ?>
                                    <option value="<?php echo htmlspecialchars($dt); ?>"><?php echo htmlspecialchars($dt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group full">
                            <label>Purpose / Reason <span class="req-star">*</span></label>
                            <input type="text" name="purpose" id="edit-purpose" required>
                        </div>
                        <div class="form-group full">
                            <label class="upload-label">
                                Supporting Documents
                                <span class="upload-hint">Upload a new file to replace · leave empty to keep current</span>
                            </label>
                            <div class="req-uploads-grid">
                                <div class="req-upload-card" id="edit-card-id"
                                    ondragover="cardDragOver(event,'edit-card-id')"
                                    ondragleave="cardDragLeave('edit-card-id')"
                                    ondrop="cardDrop(event,'edit-card-id','edit-id-input','edit-id-name','edit-id-preview')">
                                    <input type="file" name="id_photo" id="edit-id-input"
                                        accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                                        onchange="cardFileSelected(this,'edit-card-id','edit-id-name','edit-id-preview')">
                                    <button type="button" class="ruc-remove-btn"
                                        onclick="cardRemoveFile(event,'edit-card-id','edit-id-input','edit-id-name','edit-id-preview')">✕</button>
                                    <span class="ruc-check">✅</span>
                                    <span class="ruc-icon">🪪</span>
                                    <span class="ruc-title">Valid ID</span>
                                    <span class="ruc-optional">Optional</span>
                                    <p class="ruc-hint">Upload to replace current file</p>
                                    <div class="ruc-preview" id="edit-id-preview"></div>
                                    <div class="ruc-filename" id="edit-id-name"></div>
                                    <div class="ruc-existing-badge" id="edit-id-existing">
                                        <a id="edit-id-existing-link" href="#" target="_blank" onclick="event.stopPropagation()">
                                            📎 View current file
                                        </a>
                                        <div class="ruc-existing-replace-hint">Upload above to replace</div>
                                    </div>
                                </div>
                                <div class="req-upload-card" id="edit-card-al"
                                    ondragover="cardDragOver(event,'edit-card-al')"
                                    ondragleave="cardDragLeave('edit-card-al')"
                                    ondrop="cardDrop(event,'edit-card-al','edit-al-input','edit-al-name','edit-al-preview')">
                                    <input type="file" name="auth_letter" id="edit-al-input"
                                        accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                                        onchange="cardFileSelected(this,'edit-card-al','edit-al-name','edit-al-preview')">
                                    <button type="button" class="ruc-remove-btn"
                                        onclick="cardRemoveFile(event,'edit-card-al','edit-al-input','edit-al-name','edit-al-preview')">✕</button>
                                    <span class="ruc-check">✅</span>
                                    <span class="ruc-icon">📄</span>
                                    <span class="ruc-title">Authorization Letter</span>
                                    <span class="ruc-optional">Optional</span>
                                    <p class="ruc-hint">Upload to replace current file</p>
                                    <div class="ruc-preview" id="edit-al-preview"></div>
                                    <div class="ruc-filename" id="edit-al-name"></div>
                                    <div class="ruc-existing-badge" id="edit-al-existing">
                                        <a id="edit-al-existing-link" href="#" target="_blank" onclick="event.stopPropagation()">
                                            📎 View current file
                                        </a>
                                        <div class="ruc-existing-replace-hint">Upload above to replace</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="edit_request" class="submit-btn">💾 Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    

    <script src="javascripts/dashb.js"></script>
    <script>
        <?php if ($errorMsg): ?>showView('request');
        <?php endif; ?>
        <?php if ($updateError || $updateSuccess): ?>showView('account');
        <?php endif; ?>
        <?php if ($profilePhoto): ?>

            function clearAvatar() {
                document.getElementById('profile-photo-input').value = '';
                document.getElementById('avatar-new-preview').classList.remove('visible');
                document.getElementById('avatar-display').innerHTML =
                    '<img src="<?php echo htmlspecialchars($profilePhoto); ?>" style="width:100%;height:100%;object-fit:cover;">';
            }
        <?php else: ?>

            function clearAvatar() {
                document.getElementById('profile-photo-input').value = '';
                document.getElementById('avatar-new-preview').classList.remove('visible');
                document.getElementById('avatar-display').innerHTML = '<span><?php echo $avatarInitials; ?></span>';
            }
        <?php endif; ?>
    </script>

</body>

</html>