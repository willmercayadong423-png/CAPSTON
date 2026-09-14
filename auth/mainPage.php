<!-- ── mainPage.php ───────────────────────────────────────────────────────────── -->
<?php


include(__DIR__ . '/../database/db.php');
include __DIR__ . '/../phpLogics/site_config.php';

$errorMessage = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR student_id = ?");
$stmt->bind_param("ss", $email, $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        // Verify the password FIRST so we never reveal account
        // existence/status to someone who doesn't know the credentials.
        if (password_verify($password, $row["password"])) {

            // 🚫 BLOCK archived users (only after password is proven)
            if (strtolower($row["status"]) === "archived") {
                $errorMessage = "Your account is archived.";
            } else {

                $_SESSION["email"] = $row["email"];
                $_SESSION["role"] = $row["role"];
                $_SESSION["user_id"] = $row["id"];

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
        } else {
            $errorMessage = "Invalid username or password!";
        }
    } else {
        $errorMessage = "Invalid username or password!";
    }

    $stmt->close();
}

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
                    Request Form 137, diplomas, and other academic documents online,
                    then follow each one from submission to release — no campus visit required.
                </p>

                <div class="info-row">
                    <div class="info-row__item">
                        <strong>Request documents</strong>
                        <span>Form 137, diplomas, Good Moral, and more.</span>
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

                <p class="signin-label">Sign in to continue</p>

                <form method="POST" novalidate>

                    <div class="field">
                        <label for="username">Username</label>
                        <input type="text" name="username" id="username" required autocomplete="username">
                    </div>

                    <div class="field field--password">
                        <label for="password">Password</label>
                        <input type="password" name="password" id="password" required autocomplete="current-password">
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password">👁</button>
                    </div>

                    <button type="submit" name="login" class="btn-primary">Sign in</button>

                </form>

                <?php if ($errorMessage): ?>
                    <div class="notice">⚠ <?php echo htmlspecialchars($errorMessage); ?></div>
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
    </script>

</body>

</html>