<?php
require("phpLogics/auth.php");
include("database/db.php");

if (strtolower($_SESSION['role']) !== 'registrar') {
    header("Location: dashboard.php");
    exit();
}

// ── Fetch registrar info for header & sidebar ─────────────────────
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Records — HEHMS</title>
    <link rel="stylesheet" href="css/studRec.css">
    <link rel="stylesheet" href="css/dark.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="javascripts/dark.js"></script>

    <!-- CSRF token for AJAX endpoints (addStud / editStud / archStud / unArch) -->
    <script>var CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;</script>



</head>

<body>

    <div class="header">
        <div class="header-left">
            <img src="img/Logo.png" alt="School Logo" class="logo-img">
            <div class="school-info">
                <h2>Hilario E. Hermosa Memorial High School</h2>
                <p>Siclong Laur, Nueva Ecija</p>
            </div>
        </div>

        </a><!-- end clickable name/avatar -->
        <a href="phpLogics/Logout.php" class="logout-btn">
            ↪ Logout
        </a>
    </div>
    </div>

    <div class="body-layout">

        <aside class="sidebar">
            <div class="sidebar-brand">
                <p>Logged as</p>
                <h3><?php echo $regName; ?></h3>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-group-label">Main</div>
                <a href="studentRecords.php" class="nav-main-item records active">
                    <div class="nmi-icon">🎓</div>
                    <div class="nmi-text">
                        <div class="nmi-title">List Of Records</div>
                        <div class="nmi-sub">View &amp; manage records</div>
                    </div>
                </a>
                <a href="registrarMainPage.php" class="nav-main-item requests">
                    <div class="nmi-icon">📋</div>
                    <div class="nmi-text">
                        <div class="nmi-title">Student Requests</div>
                        <div class="nmi-sub">Process document requests</div>
                    </div>
                </a>

                <div class="nav-divider"></div>
                <div class="nav-group-label">Tools</div>
                <a href="phpLogics/setting.php" class="nav-link"><span class="nl-icon">⚙️</span> Settings</a>
                <a href="phpLogics/Logout.php" class="nav-link"><span class="nl-icon">↪</span> Logout</a>

            </nav>
        </aside>

        <div class="main-container">

            <div class="title-section">
                <h1>Accounts <span>Records</span></h1>
                <p>View, manage, and update existing student/registrar information.</p>
            </div>

            <div class="cards">
                <div class="card">
                    <span class="card-icon">🎓</span>
                    <h4>Total Students</h4>
                    <h2 id="stat-total">0</h2>
                </div>
                <div class="card">
                    <span class="card-icon">👦</span>
                    <h4>Registrar</h4>
                    <h2 id="stat-registrar">0</h2>
                </div>
                <div class="card">
                    <span class="card-icon">📦</span>
                    <h4>Archived</h4>
                    <h2 id="stat-archived">0</h2>
                </div>
            </div>

            <div class="switch-btn">
                <a class="active" id="tab-enrolled">List</a>
                <a id="tab-alumni">Archive</a>
            </div>

            <div class="container">
              <div class="table-header-row">
    <h3>List of Accounts</h3>
    <div class="table-header-controls">
 
        <div class="search-wrapper">
            <span class="search-icon">🔍</span>
            <input type="text" class="search" id="student-search" placeholder="Search">
        </div>
 
        <!-- Hidden file input for CSV -->
        <input type="file" id="csv-file-input" accept=".csv" style="display:none">
 
        <!-- Import CSV button -->
        <a href="#" id="btn-import-csv" class="btn-add-student">
            Import
        </a>
 
        <!-- Existing create button -->
        <a href="#" id="btn-open-modal" class="btn-add-student">➕ Create an Account</a>
 
    </div>
</div>

                <table>
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
        </div>
    </div>
</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="records-tbody"></tbody>
                </table>
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



<!-- ══ CREATE ACCOUNT MODAL ══ -->
<div id="addStudentModal" class="add-modal-overlay">
    <div class="add-modal-box" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">
 
        <div class="add-modal-header">
            <h3 id="addModalTitle">➕ Create an Account</h3>
            <button id="btn-close-modal" class="modal-close" type="button">✕</button>
        </div>
 
        <div class="add-modal-body">
 
            <!-- PHOTO -->
            <p class="form-section-title">Profile Photo</p>
            <div class="form-grid">
                <div class="form-group full">
                    <label>
                        Profile Photo
                        <span class="hint-text">(JPG/PNG/GIF/WEBP · max 3 MB)</span>
                    </label>
                    <div class="photo-row">
                        <div id="modal-avatar-display" class="avatar-display"></div>
                        <div class="photo-controls">
                            <label for="f-photo" class="btn-upload">✏️ Choose Photo</label>
                            <input type="file" id="f-photo" class="hidden-input"
                                accept="image/jpeg,image/png,image/gif,image/webp">
                            <div id="modal-photo-name" class="photo-name"></div>
                            <button type="button" id="modal-photo-clear" class="btn-remove">✕ Remove</button>
                        </div>
                    </div>
                </div>
            </div>
 
            <!-- ROLE -->
            <p class="form-section-title">Account Role</p>
            <div class="form-grid">
                <div class="form-group full">
                    <label for="f-role">Role <span class="required">*</span></label>
                    <select id="f-role">
                        <option value="">— Select Role —</option>
                        <option value="Student">Student</option>
                        <option value="Registrar">Registrar</option>
                    </select>
                    <span class="field-error" id="err-role"></span>
                </div>
            </div>
 
            <!-- IDENTITY & ACADEMIC -->
            <p class="form-section-title">Identity &amp; Academic Information</p>
            <div class="form-grid">
 
                <div class="form-group">
                    <label for="f-first">First Name <span class="required">*</span></label>
                    <input type="text" id="f-first" placeholder="">
                    <span class="field-error" id="err-first"></span>
                </div>
 
                <div class="form-group">
                    <label for="f-last">Last Name <span class="required">*</span></label>
                    <input type="text" id="f-last" placeholder="">
                    <span class="field-error" id="err-last"></span>
                </div>
 
                <div class="form-group">
                    <label for="f-lrn">
                        LRN
                        <span class="hint-text">(Learner Reference Number)</span>
                    </label>
                    <input type="text" id="f-lrn" placeholder="12-digit LRN" maxlength="12">
                    <span class="field-error" id="err-lrn"></span>
                </div>
 
                <div class="form-group">
                    <label for="f-dob">Date of Birth</label>
                    <input type="date" id="f-dob">
                    <span class="field-error" id="err-dob"></span>
                </div>
 
                <div class="form-group" id="group-grade">
                    <label for="f-grade">Grade Level</label>
                    <select id="f-grade">
                        <option value="">— Select Grade —</option>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                        <option value="Grade 11">Grade 11</option>
                        <option value="Grade 12">Grade 12</option>
                    </select>
                    <span class="field-error" id="err-grade"></span>
                </div>
 
                <div class="form-group" id="group-strand">
                    <label for="f-strand">
                        Strand / Track
                        <span class="hint-text">(for Grade 11–12)</span>
                    </label>
                    <select id="f-strand">
                        <option value="">— Not Applicable —</option>
                        <option value="ABM">ABM – Accountancy, Business &amp; Management</option>
                        <option value="HUMSS">HUMSS – Humanities &amp; Social Sciences</option>
                        <option value="STEM">STEM – Science, Technology, Engineering &amp; Math</option>
                        <option value="GAS">GAS – General Academic Strand</option>
                        <option value="TVL">TVL – Technical-Vocational-Livelihood</option>
                        <option value="Sports">Sports Track</option>
                        <option value="Arts">Arts &amp; Design Track</option>
                    </select>
                    <span class="field-error" id="err-strand"></span>
                </div>
 
                <div class="form-group full">
                    <label for="f-syear">School Year Last Attended</label>
                    <input type="text" id="f-syear" placeholder="2024–2025" maxlength="20">
                    <span class="field-error" id="err-syear"></span>
                </div>
 
            </div>
 
            <!-- CONTACT DETAILS -->
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
 
            <!-- LOGIN CREDENTIALS -->
            <p class="form-section-title">Login Credentials</p>
            <div class="form-grid">
 
                <div class="form-group full">
                    <label for="f-password">
                        Password <span class="required" id="pw-required">*</span>
                    </label>
                    <div class="pw-wrapper">
                        <input type="password" id="f-password" placeholder="Enter password">
<small id="pw-hint" style="color:#888;font-size:0.78rem;margin-top:4px;display:block;">
    Enter password or it can be auto-filled from the last 5 digits of the LRN.
</small>
                        <button type="button" class="pw-toggle">👁</button>
                    </div>
                    <span class="field-error" id="err-password"></span>
                </div>
 
            </div>
 
        </div><!-- /add-modal-body -->
 
        <div class="add-modal-footer">
            <button id="btn-save-student" class="btn-save" type="button">
                <span class="btn-label">💾 Save</span>
                <span class="spinner"></span>
            </button>
        </div>
 
    </div>
</div>

       

    <!-- ══ VIEW DOCUMENTS MODAL ══ -->
<div id="viewDocsModal" class="add-modal-overlay">
    <div class="add-modal-box" style="max-width:600px" role="dialog" aria-modal="true" aria-labelledby="viewDocsTitle">
        <div class="add-modal-header">
            <h3 id="viewDocsTitle">📄 Document Requests</h3>
            <button id="btn-close-docs-modal" class="modal-close" type="button">✕</button>
        </div>
        <div class="add-modal-body" id="docs-modal-body">
            <p style="color:#888;text-align:center;padding:2rem">Loading…</p>
        </div>
        <div class="add-modal-footer">
            <button id="btn-close-docs-footer" class="btn-save" style="background:#6b7280" type="button">Close</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

    <?php include("phpLogics/setting.php"); ?>




</body>

<script src="javascripts/studRec.js"></script>



</html>