<!-- ── mainPage.php ───────────────────────────────────────────────────────────── -->
<?php


include(__DIR__ . '/../database/db.php');
include __DIR__ . '/../phpLogics/site_config.php';

$errorMessage = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    $stmt = $conn->prepare("SELECT * FROM students WHERE email = ? OR student_id = ?");
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

                // Clear any stored plain-text password — it is no longer
                // needed once the student has logged in successfully.
                if (!empty($row["plain_password"])) {
                    $clr = $conn->prepare("UPDATE students SET plain_password = NULL WHERE id = ?");
                    $clr->bind_param("i", $row["id"]);
                    $clr->execute();
                    $clr->close();
                }

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

// Hero slider images — replace with real campus/school photos in assets/img/.
// Add or remove array entries and the slider adjusts automatically.
$heroSlides = [
    ["img" => "../assets/img/hehms.jpg", "caption" => "Hilario E. Hermosa Memorial High School"],
    ["img" => "../assets/img/inservice.jpg", "caption" => "Committed to Academic Excellence"],
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

    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>

<body>

    <div class="header">
        <div class="logo-wrap">
            <a href="mainPage.php">
                <img src="<?php echo site_logo_url(); ?>" alt="School Logo" class="logo-img">
            </a>
            <a href="mainPage.php" class="school-info">
                <h2>Hilario E. Hermosa Memorial High School</h2>
                <p>Siclong, Laur, Nueva Ecija</p>
            </a>
        </div>

        <div class="header-right">
            <span class="office-hours">Office Hours: Mon–Fri, 8:00 AM–4:00 PM</span>
        </div>
    </div>

    <main class="main-content">

        <div class="left-content">

            <!-- ── AUTO-SLIDING HERO — appears before all text content ── -->
            <div class="hero-slider" id="heroSlider">
                <div class="hero-slides">
                    <?php foreach ($heroSlides as $i => $slide): ?>
                        <div class="slide<?php echo $i === 0 ? ' active' : ''; ?>"
                            style="background-image:url('<?php echo htmlspecialchars($slide['img']); ?>')">
                            <div class="slide-caption"><?php echo htmlspecialchars($slide['caption']); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="hero-gradient"></div>
                </div>

                <?php if (count($heroSlides) > 1): ?>
                    <div class="slide-dots">
                        <?php foreach ($heroSlides as $i => $slide): ?>
                            <span class="dot<?php echo $i === 0 ? ' active' : ''; ?>" data-index="<?php echo $i; ?>"></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="badge">Official Student Portal</div>
            <div class="title-section">
                <h1>Credential Request and Tracking System</h1>

                <p>
                    The official online system for Hilario E. Hermosa Memorial High School
                    students and alumni to request and track academic documents.
                </p>

                <p class="note">
                    <b>Reminder:</b> Please ensure all required documents are ready before
                    submitting a request, and bring a valid ID when claiming requested documents.
                </p>
            </div>

            <div class="features">
                <div class="feature-box">
                    <div class="ficon-wrap"><img src="../assets/img/notebook.png" alt="request" class="ficon-img"></div>
                    <h3>Document Requests</h3>
                    <p>Request Form 137, Diplomas, Good Moral and other records online.</p>
                </div>
                <div class="feature-box">
                    <div class="ficon-wrap"><img src="../assets/img/magnifier.png" alt="tracking" class="ficon-img"></div>
                    <h3>Status Tracking</h3>
                    <p>Monitor each request from submission through to release.</p>
                </div>
                <div class="feature-box">
                    <div class="ficon-wrap"><img src="../assets/img/encrypted.png" alt="secure" class="ficon-img"></div>
                    <h3>Secure Records</h3>
                    <p>Records are retrieved securely from the school's central database.</p>
                </div>
            </div>

        </div>

        <div class="login-placeholder">
            <div class="login-card">

                <div class="logo-circle">
                    <img src="<?php echo site_logo_url(); ?>" alt="Logo" class="logoLogin">
                </div>

                <div class="loginText">
                    Hilario E. Hermosa Memorial<br>High School (HEHMS)
                </div>

                <div class="divider"><span>Sign in to your account</span></div>

                <form method="POST">

                    <label class="login-label">Username</label>
                    <div class="input-wrap">
                        <input type="text" name="username" required>
                    </div>

                    <label class="login-label">Password</label>
<div class="input-wrap password-wrap">
    <input type="password" name="password" id="password" required>

    <button type="button" class="password-toggle" id="togglePassword">
        👁
    </button>
</div>

                    <button type="submit" name="login" class="login-btn">Sign In</button>

                </form>

                <?php if ($errorMessage): ?>
                    <div class="message">⚠ <?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <?php if (isset($_GET['loggedout'])): ?>
                    <div class="message message-success">
                        You have been logged out.
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['timeout'])): ?>
                    <div class="message">Your session has expired. Please log in again.</div>
                <?php endif; ?>

                <p class="forgot-link">Forgot Password? <a href="forgotPassword.php">Click here</a></p>

            </div>
        </div>

    </main>

    <footer class="footer">
        <div class="footer-container">
            <div class="footer-center">
                <p class="footer-title">Hilario E. Hermosa Memorial High School</p>
                <p>&copy; 2026 All rights reserved. Credential Request &amp; Tracking System.</p>
            </div>
        </div>
    </footer>

    <script>
        // ── Auto-sliding hero carousel ──────────────────────────────
        (function () {
            const slider = document.getElementById('heroSlider');
            if (!slider) return;

            const slides = slider.querySelectorAll('.slide');
            const dots = slider.querySelectorAll('.dot');
            if (slides.length < 2) return; // nothing to slide

            let current = 0;
            let timer = null;
            const INTERVAL_MS = 5000;

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