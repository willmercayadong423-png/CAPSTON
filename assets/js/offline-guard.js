/* ═══════════════════════════════════════════════════
   HEHMHS — Offline connection guard (assets/js/offline-guard.js)
   ═══════════════════════════════════════════════════
   WHY THIS EXISTS
   ─────────────
   The system itself runs on a LOCAL server (database included), so it
   keeps working even without internet. What breaks offline are the
   web fonts (previously loaded from Google) — pages then looked
   "broken/unstyled", which users mistook for a DATABASE problem.

   This guard makes the real situation unmistakable:
     • the moment the browser reports "no connection", a full-screen
       branded overlay explains: "You are offline — nothing is wrong
       with the system or your records."
     • when the connection returns, the page reloads itself
       automatically (so the fonts come back too).

   Include on every page:
     <script src="../assets/js/offline-guard.js" defer></script>
   (path adjusted for the page's depth)
   ═══════════════════════════════════════════════════ */
(function () {
    'use strict';

    var overlayId = 'hehms-offline-overlay';
    var shownAt = 0;          // timestamp when the overlay appeared
    var reloadTimer = null;   // retry-while-offline interval

    function buildOverlay() {
        var ov = document.createElement('div');
        ov.id = overlayId;
        ov.setAttribute('role', 'alertdialog');
        ov.setAttribute('aria-live', 'assertive');
        ov.innerHTML =
            '<div class="og-card">' +
                '<div class="og-icon">' +
                    '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                        '<path d="M1 1l22 22"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/>' +
                        '<path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/>' +
                        '<path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>' +
                        '<path d="M12 20h.01"/>' +
                    '</svg>' +
                '</div>' +
                '<h1 class="og-title">You&rsquo;re offline</h1>' +
                '<p class="og-text">It looks like your device lost its internet connection.</p>' +
                '<p class="og-note"><strong>Don&rsquo;t worry &mdash; nothing is wrong with the system or your records.</strong>' +
                ' The HEHMHS server and its database keep running locally. Some page styling ' +
                ' (fonts) needs the internet, which is why the page may also look plain right now.</p>' +
                '<p class="og-wait">Reconnect to the internet &mdash; this page will <strong>reload automatically</strong>.</p>' +
                '<div class="og-actions">' +
                    '<button type="button" class="og-btn" id="og-retry">Try again</button>' +
                '</div>' +
                '<p class="og-status" id="og-status">Waiting for connection&hellip;</p>' +
            '</div>';

        var style = document.createElement('style');
        style.textContent =
            '#' + overlayId + '{position:fixed;inset:0;z-index:2147483000;display:none;' +
                'align-items:center;justify-content:center;padding:24px;' +
                'background:rgba(21,32,18,.55);backdrop-filter:blur(3px);' +
                'font-family:Arial,Helvetica,sans-serif;}' +
            '#' + overlayId + '.og-on{display:flex;}' +
            '.og-card{background:#fffdf8;border:1px solid #ddd4bb;border-radius:16px;' +
                'max-width:460px;width:100%;padding:34px 30px 26px;text-align:center;' +
                'box-shadow:0 24px 60px rgba(0,0,0,.35);}' +
            '.og-icon{width:74px;height:74px;margin:0 auto 14px;border-radius:50%;' +
                'display:flex;align-items:center;justify-content:center;color:#fff;' +
                'background:linear-gradient(135deg,#c9a44c,#a9812f);}' +
            '.og-title{font-size:1.45rem;margin:0 0 10px;color:#16220d;font-weight:800;}' +
            '.og-text{margin:0 0 10px;color:#4b5563;font-size:.95rem;}' +
            '.og-note{margin:0 0 12px;color:#6b7280;font-size:.82rem;line-height:1.55;' +
                'background:#f4efe1;border:1px solid #e5dcc3;border-radius:10px;padding:10px 12px;}' +
            '.og-wait{margin:0 0 18px;color:#4a6741;font-size:.88rem;font-weight:600;}' +
            '.og-actions{display:flex;justify-content:center;}' +
            '.og-btn{background:linear-gradient(135deg,#6b8f3a,#4a6741);color:#fff;border:0;' +
                'border-radius:999px;padding:11px 30px;font-size:.9rem;font-weight:700;cursor:pointer;}' +
            '.og-btn:hover{filter:brightness(1.08);}' +
            '.og-status{margin:14px 0 0;color:#9ca3af;font-size:.75rem;}';

        document.head.appendChild(style);
        document.body.appendChild(ov);
        ov.querySelector('#og-retry').addEventListener('click', function () {
            /* "Try again" = probe the connection. If it's back, reload;
               otherwise tell the user we're still offline. */
            if (navigator.onLine) {
                window.location.reload();
            } else {
                var st = ov.querySelector('#og-status');
                if (st) {
                    st.textContent = 'Still offline — waiting for connection…';
                }
            }
        });
        return ov;
    }

    var overlay = null;

    function show() {
        if (!overlay) overlay = buildOverlay();
        if (!overlay.classList.contains('og-on')) {
            shownAt = Date.now();
            overlay.classList.add('og-on');
            document.body.style.overflow = 'hidden';
        }
        /* Probe every few seconds — some browsers only update
           navigator.onLine after an event we might have missed. */
        if (!reloadTimer) {
            reloadTimer = setInterval(function () {
                if (navigator.onLine) recover();
            }, 3000);
        }
    }

    function recover() {
        if (reloadTimer) { clearInterval(reloadTimer); reloadTimer = null; }
        /* Only auto-reload when we actually showed the overlay during
           THIS page view (a connection that was never lost must not
           trigger a pointless reload loop). */
        if (shownAt > 0) {
            window.location.reload();
        }
    }

    function hide() {
        if (overlay) overlay.classList.remove('og-on');
        document.body.style.overflow = '';
        recover();
    }

    window.addEventListener('offline', show);
    window.addEventListener('online', hide);

    /* Offline already at page load (e.g. the user opened the site with
       the Wi-Fi off) — show the notice immediately. */
    if (!navigator.onLine) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', show);
        } else {
            show();
        }
    }
})();
