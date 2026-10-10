/* ═══════════════════════════════════════════════════
   HEHMHS — Session idle auto-logout + heartbeat
   (assets/js/session-timeout.js)
   ═══════════════════════════════════════════════════
   HOW IT WORKS (client + server stay in sync)

   • Any interaction (mouse / keyboard / scroll / touch) marks the user
     PRESENT and resets the idle countdown.
   • While present, a heartbeat is sent every HEARTBEAT_MS to
     phpLogics/heartbeat.php, which refreshes the SERVER-side
     session timer (auth.php $timeout = 900s) — so an actively
     reading user is never logged out by surprise.
   • While idle, NO heartbeat is sent → the server session expires on
     its own → any stale tab that later makes a request is bounced.
   • After WARN_AT ms of true inactivity a warning bar appears with a
     live countdown and a "Stay signed in" button (one click = heartbeat
     + reset). At LIMIT_MS the page redirects to the login screen with
     ?timeout=1.
   • If a heartbeat reveals the session is already dead (server
     restarted / expired elsewhere / logged out in another tab), the
     page redirects immediately — no zombie "looks logged in" state.

   Include on every logged-in page (all pages sit one level deep):
     <script src="../assets/js/session-timeout.js" defer></script>
   ═══════════════════════════════════════════════════ */
(function () {
    'use strict';

    var LIMIT_MS     = 15 * 60 * 1000;   // MUST match auth.php ($timeout = 900)
    var WARN_AT_MS   = LIMIT_MS - 2 * 60 * 1000;   // warn 2 minutes before
    var HEARTBEAT_MS = 4 * 60 * 1000;    // keep-alive ping while present

    var LOGIN_URL     = '../auth/mainPage.php?timeout=1';
    var HEARTBEAT_URL = '../phpLogics/heartbeat.php';

    var idleTimer   = null;   // → shows warning at WARN_AT_MS, logs out at LIMIT_MS
    var tickTimer   = null;   // updates the warning countdown every second
    var beatTimer   = null;   // sends a heartbeat every HEARTBEAT_MS
    var lastActivity = Date.now();
    var activeSinceBeat = false;   // was there ANY interaction since the last ping?
    var loggingOut = false;

    /* ── UI: warning bar (injected once, hidden until needed) ── */
    var bar = null, countdownEl = null;

    function buildBar() {
        if (bar) return;
        bar = document.createElement('div');
        bar.id = 'hehmhs-idle-bar';
        bar.setAttribute('role', 'alertdialog');
        bar.setAttribute('aria-live', 'assertive');
        bar.innerHTML =
            '<span class="ib-icon">' +
                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
                'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
            '</span>' +
            '<span class="ib-text">You\'ll be signed out automatically in ' +
                '<strong id="hehmhs-idle-countdown">2:00</strong> due to inactivity.</span>' +
            '<button type="button" class="ib-stay" id="hehmhs-idle-stay">Stay signed in</button>';

        var style = document.createElement('style');
        style.textContent =
            '#hehmhs-idle-bar{position:fixed;left:50%;transform:translate(-50%,-160%);' +
                'bottom:26px;z-index:2147483000;display:flex;align-items:center;gap:12px;' +
                'background:#fffdf8;border:1px solid #ddd4bb;border-left:4px solid #c9a84c;' +
                'border-radius:12px;padding:12px 16px;box-shadow:0 14px 40px rgba(21,32,18,.28);' +
                'font-family:Arial,Helvetica,sans-serif;font-size:.86rem;color:#374151;' +
                'transition:transform .25s ease, opacity .25s ease;opacity:0;max-width:92vw;}' +
            '#hehmhs-idle-bar.ib-on{transform:translate(-50%,0);opacity:1;}' +
            '.ib-icon{color:#a9812f;display:inline-flex;}' +
            '.ib-text strong{color:#7c5b16;font-variant-numeric:tabular-nums;}' +
            '.ib-stay{background:linear-gradient(135deg,#6b8f3a,#4a6741);color:#fff;border:0;' +
                'border-radius:999px;padding:8px 18px;font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap;}' +
            '.ib-stay:hover{filter:brightness(1.08);}';

        document.head.appendChild(style);
        document.body.appendChild(bar);
        countdownEl = document.getElementById('hehmhs-idle-countdown');
        document.getElementById('hehmhs-idle-stay').addEventListener('click', function () {
            noteActivity();          // reset everything…
            hideBar();
            heartbeat();             // …and confirm with the server right away
        });
    }

    function showBar() { buildBar(); bar.classList.add('ib-on'); startTicking(); }
    function hideBar()  { if (bar) bar.classList.remove('ib-on'); stopTicking(); }

    function startTicking() {
        stopTicking();
        tickTimer = setInterval(function () {
            if (!countdownEl) return;
            var left = LIMIT_MS - (Date.now() - lastActivity);
            if (left <= 0) return;
            var m = Math.floor(left / 60000);
            var s = Math.floor((left % 60000) / 1000);
            countdownEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
        }, 1000);
    }
    function stopTicking() { if (tickTimer) { clearInterval(tickTimer); tickTimer = null; } }

    /* ── logout / redirect ── */
    function logoutNow() {
        if (loggingOut) return;
        loggingOut = true;
        window.location.href = LOGIN_URL;
    }

    /* ── heartbeat: keep the SERVER timer in sync with real presence ── */
    function heartbeat() {
        fetch(HEARTBEAT_URL, {
            method: 'GET',
            credentials: 'same-origin',
            redirect: 'manual',      // do NOT follow the login redirect silently
            cache: 'no-store'
        }).then(function (res) {
            // opaqueredirect = auth.php bounced us → the session is gone
            if (!res || res.type === 'opaqueredirect' || res.status === 401 || res.status === 403) {
                logoutNow();
                return;
            }
            return res.json().then(function (d) {
                if (!d || d.ok !== true) logoutNow();   // unexpected body → treat as dead
            }).catch(function () { /* transient network error: ignore, retry next cycle */ });
        }).catch(function () { /* offline moment — the offline guard handles that */ });
    }

    /* ── idle countdown ── */
    function scheduleIdleCheck() {
        if (idleTimer) clearTimeout(idleTimer);
        idleTimer = setTimeout(function () {
            var idleFor = Date.now() - lastActivity;
            if (idleFor >= LIMIT_MS) { logoutNow(); return; }
            if (idleFor >= WARN_AT_MS) {
                showBar();
                // re-check every second until logout
                idleTimer = setTimeout(scheduleIdleCheck, 1000);
            }
        }, WARN_AT_MS);
    }

    function noteActivity() {
        lastActivity = Date.now();
        activeSinceBeat = true;
        hideBar();
        scheduleIdleCheck();
    }

    /* Any interaction marks presence. Mouse-move counts too — but ONLY
       the heartbeat tells the server, so presence is honest on both sides. */
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'].forEach(function (evt) {
        document.addEventListener(evt, noteActivity, { passive: true });
    });

    /* Periodic keep-alive while the user is around; silent while idle. */
    beatTimer = setInterval(function () {
        if (activeSinceBeat) {
            activeSinceBeat = false;
            heartbeat();
        }
    }, HEARTBEAT_MS);

    /* Boot */
    scheduleIdleCheck();
})();
