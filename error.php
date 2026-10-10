<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMHS — branded error page  (error.php)
 * ───────────────────────────────────────────────────────────────────
 * Shown for ANY request inside the project that doesn't resolve to a
 * real file (see .htaccess) — 404 by default, but also 403 / 500 via
 * Apache's ErrorDocument.
 *
 * ⚠ THIS PAGE IS SERVED AT WHATEVER URL THE USER TYPED — NOT at its
 *   own location. Every link below MUST therefore be an ABSOLUTE path
 *   (relative links like "auth/mainPage.php" resolve against the
 *   visitor's current URL: on /auth/missing they became
 *   /auth/auth/mainPage.php — another 404 — and stacked "auth/"
 *   deeper with every click).
 *
 * The page is deliberately SELF-CONTAINED so it works under any
 * condition (offline, DB down, missing assets):
 *   • inline CSS + inline SVG icon
 *   • absolute links computed from DOCUMENT_ROOT vs __DIR__
 *   • a plain session_start() (no DB) to offer signed-in users a way
 *     back to their own dashboard instead of the sign-in page
 * ═══════════════════════════════════════════════════════════════════ */

/* Which error are we showing? Apache sets REDIRECT_STATUS when this
 * page is used as an ErrorDocument; the .htaccess catch-all passes
 * nothing (it simply rewrites here) → default 404. */
$code = (int)($_SERVER['REDIRECT_STATUS'] ?? 0);
if (!in_array($code, [403, 404, 500], true)) {
    $code = 404;
}
http_response_code($code);

$titles = [
    403 => 'Access denied',
    404 => 'Page not found',
    500 => 'Something went wrong',
];
$messages = [
    403 => "You don't have permission to open this address. If you think you should, please sign in first or contact the administrator.",
    404 => "The page you're looking for doesn't exist (or was moved). Check the address, or use the buttons below to get back on track.",
    500 => "The server hit an unexpected error while preparing this page. This is usually temporary — please try again in a moment.",
];
$title   = $titles[$code];
$message = $messages[$code];

/* ── Absolute project base (same math as phpLogics/auth.php base_url) ──
 * Works no matter which folder depth this page is served at, keeps the
 * project folder-agnostic, and URL-encodes each segment (spaces etc.). */
$docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$projDir = rtrim(str_replace('\\', '/', __DIR__), '/');
$base    = ($docRoot !== '' && strpos($projDir, $docRoot) === 0)
    ? substr($projDir, strlen($docRoot))
    : '';
$base = implode('/', array_map('rawurlencode', explode('/', $base)));

$loginUrl  = $base . '/auth/mainPage.php';   // ABSOLUTE — see warning above
$favicon   = $base . '/assets/img/Logo.png';

/* ── Signed in? Offer the way back to THEIR dashboard ───────────────
 * Plain session read only — no DB, so this still works if MySQL is
 * down (the 500 case needs exactly that). Cookie params mirror
 * database/db.php so guests don't receive a mismatched session cookie. */
$dashUrl  = null;
$dashName = 'Back to my Dashboard';
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    @session_start();
}
if (!empty($_SESSION['email'])) {
    $dashUrl = match (strtolower((string)($_SESSION['role'] ?? ''))) {
        'student'   => $base . '/student/dashboard.php',
        'admin'     => $base . '/admin/adminDashboard.php',
        'registrar' => $base . '/registrar/registrarMainPage.php',
        default     => $loginUrl,
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($favicon, ENT_QUOTES); ?>">
    <title><?php echo htmlspecialchars($title); ?> — HEHMHS</title>
    <style>
        :root { --green:#4a6741; --green-mid:#6b8f3a; --gold:#c9a84c; --gold-d:#a9812f; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: linear-gradient(160deg, #f4efe1 0%, #eef4e6 55%, #e7efdc 100%);
            font-family: Arial, Helvetica, sans-serif; color: #1f2937;
        }
        .card {
            background: #fffdf8; border: 1px solid #ddd4bb; border-radius: 18px;
            max-width: 480px; width: 100%; padding: 40px 34px 30px; text-align: center;
            box-shadow: 0 24px 60px rgba(21, 32, 18, .16);
        }
        .code {
            font-size: 4.4rem; font-weight: 800; line-height: 1; letter-spacing: -.03em;
            background: linear-gradient(135deg, var(--green), var(--gold-d));
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        .badge {
            display: inline-flex; align-items: center; gap: 6px; margin: 12px 0 6px;
            font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .09em;
            color: var(--green); background: #eef4e6; border: 1px solid #d7e3c5;
            padding: 5px 14px; border-radius: 999px;
        }
        h1 { font-size: 1.5rem; margin: 8px 0 10px; color: #16220d; }
        p.msg { color: #4b5563; font-size: .93rem; line-height: 1.6; margin-bottom: 8px; }
        .offline-note {
            display: none; margin: 14px 0 4px; padding: 11px 13px; border-radius: 10px;
            background: #fdf3e7; border: 1px solid #ecd9b8; color: #7c5b16;
            font-size: .82rem; line-height: 1.55; text-align: left;
        }
        .offline-note strong { color: #5f4410; }
        body.is-offline .offline-note { display: block; }
        .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin: 22px 0 6px; }
        .btn {
            display: inline-flex; align-items: center; gap: 7px; text-decoration: none;
            border-radius: 999px; padding: 11px 26px; font-size: .88rem; font-weight: 700;
            cursor: pointer; border: 0;
        }
        .btn-primary { background: linear-gradient(135deg, var(--green-mid), var(--green)); color: #fff; }
        .btn-primary:hover { filter: brightness(1.08); }
        .btn-ghost { background: transparent; color: var(--green); border: 1.5px solid #cfd8c5; }
        .btn-ghost:hover { background: #f2f6ec; }
        .school {
            margin-top: 20px; padding-top: 16px; border-top: 1px dashed #e2d9c2;
            font-size: .76rem; color: #8a8271;
        }
        .school strong { color: #6d6553; }
        @media (max-width: 420px) { .code { font-size: 3.4rem; } .card { padding: 30px 22px 24px; } }
    </style>
</head>
<body>
    <main class="card" role="main">
        <div class="code"><?php echo (int)$code; ?></div>
        <div class="badge">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
            HEHMHS
        </div>
        <h1 id="err-title"><?php echo htmlspecialchars($title); ?></h1>
        <p class="msg" id="err-msg"><?php echo htmlspecialchars($message); ?></p>

        <!-- Shown only when the browser reports NO connection. NOTE: the
             system itself (server + database + styles) runs locally and
             keeps working — only an ONLINE deployment would be affected. -->
        <div class="offline-note">
            <strong>You also appear to be offline right now.</strong>
            The HEHMHS server, its database and this site's styles all run locally —
            they keep working without internet. If you are using an online deployment
            of the system, reconnect and try again before assuming something is broken.
        </div>

        <div class="actions">
            <?php if ($dashUrl): ?>
                <!-- Signed-in user: primary way back is THEIR dashboard -->
                <a class="btn btn-primary" href="<?php echo htmlspecialchars($dashUrl, ENT_QUOTES); ?>"><?php echo htmlspecialchars($dashName); ?></a>
                <a class="btn btn-ghost" href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES); ?>">Go to Sign-in</a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES); ?>">Go to Sign-in</a>
            <?php endif; ?>
            <!-- history.back() only when there IS history (a 404 opened in a
                 fresh tab has none — then fall through to the sign-in page
                 instead of a dead button) -->
            <button type="button" class="btn btn-ghost"
                onclick="if (history.length > 1) { history.back(); } else { location.href = '<?php echo htmlspecialchars($loginUrl, ENT_QUOTES); ?>'; }">
                Go back
            </button>
        </div>

        <div class="school">
            <strong>Hilario E. Hermosa Memorial High School</strong><br>
            Credential Request &amp; Tracking System
        </div>
    </main>

    <script>
        /* Offline-aware copy: swap the headline/message when the real
           issue is a dropped connection (self-contained version of
           assets/js/offline-guard.js — this page must work standalone). */
        (function () {
            function apply() {
                document.body.classList.toggle('is-offline', !navigator.onLine);
                if (!navigator.onLine) {
                    document.getElementById('err-title').textContent = 'You\u2019re offline';
                    document.getElementById('err-msg').textContent =
                        'Your device lost its internet connection. Nothing is wrong with the system ' +
                        'or your records \u2014 the HEHMHS server and database keep running locally. ' +
                        'Reconnect to the internet, then reload this page.';
                }
            }
            window.addEventListener('offline', apply);
            window.addEventListener('online', apply);
            apply();
        })();
    </script>
</body>
</html>
