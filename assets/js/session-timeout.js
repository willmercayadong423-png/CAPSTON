/* ═══════════════════════════════════════════════════
   HEHMS — Session idle auto-logout  (assets/js/session-timeout.js)
   ═══════════════════════════════════════════════════
   Mirrors the server-side 15-minute timeout in phpLogics/auth.php.
   PHP can only expire a session on the NEXT request, so an idle open
   tab never visibly logs out. This timer redirects the page to the
   login screen after 15 minutes of no activity — same window as the
   server, so both stay in sync.

   Include on every logged-in page (student/registrar/admin) with:
     <script src="../assets/js/session-timeout.js" defer></script>
   ═══════════════════════════════════════════════════ */
(function () {
    'use strict';

    var LIMIT_MS  = 5 * 60 * 1000; // 5 minutes
    var LOGIN_URL = '../auth/mainPage.php?timeout=1'; // all authed pages sit one level deep
    var timer;

    function logout() {
        window.location.href = LOGIN_URL;
    }

    function resetTimer() {
        clearTimeout(timer);
        timer = setTimeout(logout, LIMIT_MS);
    }

    // Any interaction keeps the session alive
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'].forEach(function (evt) {
        document.addEventListener(evt, resetTimer, { passive: true });
    });

    resetTimer();
})();
