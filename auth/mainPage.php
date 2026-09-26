<!-- ── mainPage.php ───────────────────────────────────────────────────────────── -->
<?php


include(__DIR__ . '/../database/db.php');
include __DIR__ . '/../phpLogics/site_config.php';
require_once __DIR__ . '/../phpLogics/audit.php';

$errorMessage = "";
$lockSeconds  = 0;   // > 0 → account temporarily locked, show a live countdown

/* ── Brute-force lockout policy ─────────────────────────────────────
 * 3 consecutive wrong passwords → account temporarily locked.
 * Duration escalates with each lockout: 1 → 3 → 5 → 7 … minutes,
 * capped at 15. A successful sign-in clears the record entirely.
 * Only wrong passwords for an EXISTING account trigger a lock —
 * unknown e-mails keep the generic failure + audit entry (no account
 * to lock, and no way to reveal whether the account exists).
 * Lock state is evaluated inside SQL so PHP and MySQL can never
 * disagree about the current time. ───────────────────────────────── */
const LOCKOUT_MAX_ATTEMPTS = 3;
const LOCKOUT_CAP_MINUTES  = 15;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {

    // ── CSRF: the login form carries a per-session token ──
    // db.php already started the session, so csrf_token() is available here.
    if (!function_exists('csrf_token')) {
        function csrf_token(): string
        {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            return $_SESSION['csrf_token'];
        }
    }
    if (!isset($_POST['csrf_token']) || !hash_equals(csrf_token(), (string)$_POST['csrf_token'])) {
        $errorMessage = "Your session expired. Please try again.";
    } else {

    $email    = trim($_POST["username"]);
    // ⚠ Do NOT trim the password: profile updates store it untrimmed, so a
    // password containing leading/trailing spaces could never log in here.
    $password = (string)($_POST["password"] ?? '');

    // Authentication is by e-mail OR Student ID (see schema.sql note:
    // "Authentication is by e-mail OR Student ID"). Both are unique
    // columns, so at most one account can ever match.
    $stmt = $conn->prepare(
        "SELECT *,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked,
                IF(locked_until IS NOT NULL AND locked_until > NOW(),
                   TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0)    AS lock_seconds
         FROM users WHERE email = ? OR student_id = ?"
    );
    $stmt->bind_param("ss", $email, $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $actorInfo = [
            'id'   => (int)$row['id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'role' => $row['role'],
        ];

        /* 1 · Active lock? Reject even a correct password until expiry. */
        if ((int)$row['is_locked'] === 1) {
            $lockSeconds  = (int)$row['lock_seconds'];
            $errorMessage = "Too many failed attempts — account is temporarily locked. Try again later.";
            audit_log($conn, 'LOGIN_LOCKED', 'auth', (string)$row['id'],
                "Sign-in rejected for locked account '{$email}' ({$lockSeconds}s remaining)", $actorInfo);
        }

        /* 2 · Correct password? */
        elseif (password_verify($password, $row["password"])) {

            // 🚫 BLOCK archived users (only after password is proven)
            if (strtolower($row["status"]) === "archived") {
                $errorMessage = "Your account is archived.";
                audit_log($conn, 'LOGIN_BLOCKED', 'auth', (string)$row['id'],
                    "Blocked sign-in — account '{$email}' is archived", $actorInfo);
            } else {

                // ✅ Success clears the lockout record completely.
                $clear = $conn->prepare(
                    "UPDATE users
                        SET failed_login_count = 0, lockout_count = 0, locked_until = NULL
                      WHERE id = ?");
                $clear->bind_param("i", $row['id']);
                $clear->execute();
                $clear->close();

                $_SESSION["email"] = $row["email"];
                $_SESSION["role"] = $row["role"];
                $_SESSION["user_id"] = $row["id"];

                audit_log($conn, 'LOGIN', 'auth', (string)$row['id'],
                    "Signed in as {$row['role']}", $actorInfo);

                session_regenerate_id(true);

                if (strtolower($row["role"]) === "student") {
                    header("Location: ../student/dashboard.php");
                } elseif (strtolower($row["role"]) === "admin") {
                    header("Location: ../admin/adminDashboard.php");
                } else {
                    header("Location: ../registrar/registrarMainPage.php");
                }
                exit();
            }
        }

        /* 3 · Wrong password → count it; 3rd strike locks the account. */
        else {
            $failed = (int)$row['failed_login_count'] + 1;

            if ($failed >= LOCKOUT_MAX_ATTEMPTS) {
                // Escalating duration: 1 → 3 → 5 → 7 … capped at 15 min.
                $lockouts = (int)$row['lockout_count'] + 1;
                $minutes  = min(1 + 2 * ($lockouts - 1), LOCKOUT_CAP_MINUTES);

                $lock = $conn->prepare(
                    "UPDATE users
                        SET failed_login_count = 0,
                            lockout_count      = ?,
                            locked_until       = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                      WHERE id = ?");
                $lock->bind_param("iii", $lockouts, $minutes, $row['id']);
                $lock->execute();
                $lock->close();

                $lockSeconds  = $minutes * 60;
                $errorMessage = "Too many failed attempts — account locked for {$minutes} minute"
                              . ($minutes > 1 ? "s" : "") . ".";
                audit_log($conn, 'LOGIN_LOCKED', 'auth', (string)$row['id'],
                    "Account '{$email}' locked for {$minutes} min after " . LOCKOUT_MAX_ATTEMPTS
                    . " failed attempts (lockout #{$lockouts})", $actorInfo);
            } else {
                $count = $conn->prepare("UPDATE users SET failed_login_count = ? WHERE id = ?");
                $count->bind_param("ii", $failed, $row['id']);
                $count->execute();
                $count->close();

                $errorMessage = "Invalid username or password!";
                audit_log($conn, 'LOGIN_FAILED', 'auth', (string)$row['id'],
                    "Wrong password for '{$email}' ({$failed}/" . LOCKOUT_MAX_ATTEMPTS . ")", $actorInfo);
            }
        }
    } else {
        $errorMessage = "Invalid username or password!";
        audit_log($conn, 'LOGIN_FAILED', 'auth', null,
            "Unknown username '{$email}'", ['name' => $email, 'role' => 'unknown']);
    }

    $stmt->close();
    } // end CSRF-passed branch
}

// Ensure a CSRF token exists for the form below (no-op if already set)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$loginCsrfToken = $_SESSION['csrf_token'];

// Hero photo panel — replace with real campus/school photos in assets/img/.
// Add or remove array entries and the panel adjusts automatically.
$heroSlides = [
    ["img" => "../assets/img/hehms.jpg", "caption" => "Hilario E. Hermosa Memorial High School"],
    ["img" => "../assets/img/inservice.jpg", "caption" => "In-Service Training"],
    ["img" => "../assets/img/by.jpg", "caption" => "School Activities"],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credential Request System — HEHMS</title>
    <link rel="stylesheet" href="mainPageCSS.css">
    <?php echo theme_head(); // admin-managed brand color ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,400;1,9..144,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body>

    <div class="portal">

        <!-- ── LEFT — full-bleed auto-sliding photo panel ── -->
        <section class="photo-panel" id="heroSlider" aria-label="School gallery">

            <div class="photo-panel__slides">
                <?php foreach ($heroSlides as $i => $slide): ?>
                    <div class="slide<?php echo $i === 0 ? ' active' : ''; ?>"
                        style="background-image:url('<?php echo htmlspecialchars($slide['img']); ?>')">
                        <div class="slide-meta">
                            <p class="slide-caption"><?php echo htmlspecialchars($slide['caption']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="photo-panel__scrim"></div>
            </div>

            <div class="letterhead">
                <img src="<?php echo site_logo_url(); ?>" alt="" class="letterhead__seal">
                <span class="letterhead__name">Hilario E. Hermosa Memorial High School</span>
            </div>

            <div class="photo-panel__content">
                <h1>Every record, one request away.</h1>
                <p class="lead">
                    Request Good Moral, and other academic documents online,
                    then follow each one from submission to release — no campus visit required.
                </p>

                <div class="info-row">
                    <div class="info-row__item">
                        <strong>Request documents</strong>
                        <span>Certificate of Completion/Graduation, Good Moral, and more.</span>
                    </div>
                    <div class="info-row__item">
                        <strong>Track progress</strong>
                        <span>Follow a request from received to ready for release.</span>
                    </div>
                    <div class="info-row__item">
                        <strong>Secure records</strong>
                        <span>Pulled directly from the registrar's database.</span>
                    </div>
                </div>
            </div>

            <?php if (count($heroSlides) > 1): ?>
                <div class="slide-dots">
                    <?php foreach ($heroSlides as $i => $slide): ?>
                        <button type="button" class="dot<?php echo $i === 0 ? ' active' : ''; ?>"
                            data-index="<?php echo $i; ?>" aria-label="Show photo <?php echo $i + 1; ?>"></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ── RIGHT — sign-in ── -->
        <section class="login-panel">
            <div class="login-panel__inner">

                <div class="crest-mark">
                    <img src="<?php echo site_logo_url(); ?>" alt="Logo" class="crest-mark__img">
                </div>

                <h2 class="school-name">Hilario E. Hermosa Memorial High School</h2>
                <p class="school-address">Siclong, Laur, Nueva Ecija</p>

                <div class="rule"></div>


                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($loginCsrfToken); ?>">

                    <div class="field">
                        <label for="username">Email or Student ID</label>
                        <input type="text" name="username" id="username" required autocomplete="email">
                    </div>

                    <div class="field field--password">
                        <label for="password">Password</label>
                        <input type="password" name="password" id="password" required autocomplete="current-password">
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password">👁</button>
                    </div>

                    <button type="submit" name="login" class="btn-primary">Sign in</button>

                </form>

                <?php if ($errorMessage): ?>
                    <div class="notice"<?php echo $lockSeconds > 0 ? ' id="lockNotice" data-seconds="' . (int)$lockSeconds . '"' : ''; ?>>⚠ <?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <?php if (isset($_GET['loggedout'])): ?>
                    <div class="notice notice--success">You have been logged out.</div>
                <?php endif; ?>

                <?php if (isset($_GET['timeout'])): ?>
                    <div class="notice">Your session has expired. Please log in again.</div>
                <?php endif; ?>

                <p class="forgot">Forgot password? <a href="forgotPassword.php">Click here</a></p>

                <div class="panel-footer">
                    
                    <p>© 2026 Hilario E. Hermosa Memorial High School. All rights reserved.</p>
                </div>

            </div>
        </section>

    </div>

    <script>
        // ── Auto-sliding hero photo panel ──────────────────────────────
        (function () {
            const slider = document.getElementById('heroSlider');
            if (!slider) return;

            const slides = slider.querySelectorAll('.slide');
            const dots = slider.querySelectorAll('.dot');
            if (slides.length < 2) return; // nothing to slide

            let current = 0;
            let timer = null;
            const INTERVAL_MS = 5500;

            function goTo(index) {
                slides[current].classList.remove('active');
                dots[current] && dots[current].classList.remove('active');
                current = (index + slides.length) % slides.length;
                slides[current].classList.add('active');
                dots[current] && dots[current].classList.add('active');
            }

            function next() {
                goTo(current + 1);
            }

            function start() {
                timer = setInterval(next, INTERVAL_MS);
            }

            function stop() {
                clearInterval(timer);
            }

            dots.forEach((dot) => {
                dot.addEventListener('click', () => {
                    goTo(parseInt(dot.dataset.index, 10));
                    stop();
                    start();
                });
            });

            slider.addEventListener('mouseenter', stop);
            slider.addEventListener('mouseleave', start);

            start();
        })();

        // ── Password visibility toggle ──────────────────────────────
        const passwordInput = document.getElementById("password");
        const togglePassword = document.getElementById("togglePassword");

        togglePassword.addEventListener("click", function () {
            if (passwordInput.type === "password") {
                passwordInput.type = "text";
                togglePassword.textContent = "🙈";
            } else {
                passwordInput.type = "password";
                togglePassword.textContent = "👁";
            }
        });

        // ── Live countdown on the temporary lockout notice ──────────
        (function () {
            var box = document.getElementById('lockNotice');
            if (!box) return;

            var s = parseInt(box.dataset.seconds, 10);
            if (!(s > 0)) return;

            var base = box.textContent;

            function tick() {
                if (s <= 0) {
                    clearInterval(timer);
                    box.textContent = '⚠ The lock has expired — you may try signing in now.';
                    return;
                }
                var m = Math.floor(s / 60), r = s % 60;
                box.textContent = base + ' (' + (m > 0 ? m + 'm ' : '') + r + 's left)';
                s--;
            }

            tick();
            var timer = setInterval(tick, 1000);
        })();
    </script>

</body>

</html>