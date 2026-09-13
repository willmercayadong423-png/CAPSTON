
<!-- ── mainPage.php ───────────────────────────────────────────────────────────── -->
<?php


include("database/db.php");

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
                    header("Location: dashboard.php?");
                } else {
                    header("Location: registrarMainPage.php?");
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

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credential Request System — HEHMS</title>
    <link rel="stylesheet" href="css/mainPageCSS.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

   

    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>

<body>

    <!-- <div class="header">
    <div class="logo-wrap">
        <a href="mainPage.php">
    <img src="img/Logo.png" alt="School Logo" class="logo-img">
</a>
        <a href="mainPage.php" class="school-info">
            <h2>Hilario E. Hermosa Memorial High School</h2>
            <p>Siclong Laur, Nueva Ecija</p>
        </a>
    </div>

        <button id="theme-toggle" class="theme-toggle-btn" title="Toggle dark mode">🌙</button>
    </div> -->
    <main class="main-content">

        <div class="left-content">

            <div class="badge">Official Student Portal</div>
<div class="title-section">
    <h1>Credential Request <span>and Tracking</span> System</h1>

    <p>
        The official online system for Hilario E. Hermosa Memorial High School
        students and alumni to request and track academic documents — anytime, anywhere.
    </p>

    <p>
        <b><i>Office Schedule: Monday to Friday, 08:00 AM to 4:00 PM</i></b>
    </p>

    <p>
        <b>Reminder:</b><br>
        • Please ensure you have all necessary documents ready before submitting your request.<br>
        • Bring your valid ID when picking up your requested documents.
    </p>
</div>

            <div class="features">
                <div class="feature-box">
                    <div class="ficon-wrap"><img src="img/notebook.png" alt="request" class="ficon-img"></div>
                    <h3>Easy Requests</h3>
                    <p>Request Form 137, Diplomas, Good Moral and more without visiting campus.</p>
                </div>
                <div class="feature-box">
                    <div class="ficon-wrap"><img src="img/magnifier.png" alt="tracking" class="ficon-img"></div>
                    <h3>Real-time Tracking</h3>
                    <p>Monitor your request from Pending all the way to Ready for Pickup.</p>
                </div>
                <div class="feature-box">
                    <div class="ficon-wrap"><img src="img/encrypted.png" alt="secure" class="ficon-img"></div>
                    <h3>Secure Records</h3>
                    <p>Your academic records are retrieved securely from our centralized database.</p>
                </div>
            </div>

        </div>

        <div class="login-placeholder">
            <div class="login-card">

                <div class="logo-circle">
                    <img src="img/Logo.png" alt="Logo" class="logoLogin">
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
                    <div class="input-wrap">
                       
                        <input type="password" name="password" required>
                    </div>

                    <button type="submit" name="login" class="login-btn">LOGIN</button>

                </form>

                <?php if ($errorMessage): ?>
                    <div class="message">⚠️ <?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <?php if (isset($_GET['loggedout'])): ?>
                    <div class="message" style="border-left-color:#6b8f3a;background:#f0f9e8;color:#3d5a1e;">
                        ✔ You have been logged out.
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['timeout'])): ?>
                    <div class="message">⏱ Your session has expired. Please log in again.</div>
                <?php endif; ?>

                <p class="forgot-link">Forgot Password? <a href="forgotPassword.php">Click here</a></p>

            </div>
        </div>

    </main>
<!-- 
    <footer class="footer">
        <div class="footer-container">
            <div class="footer-center">
                <h3>&copy; 2026 Hilario E. Hermosa Memorial High School. All rights reserved.</h3>
                <p>Credential Request &amp; Tracking System</p>
            </div>
        </div>
    </footer> -->

</body>

</html>