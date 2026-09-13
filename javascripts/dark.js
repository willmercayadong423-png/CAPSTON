/* ═══════════════════════════════════════════
   HEHMS Dark Mode — dark.js
   ═══════════════════════════════════════════ */

(function () {

    /* ── Apply saved theme immediately on load ── */
    function applyTheme() {
        if (localStorage.getItem('hehms-theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
    }
    applyTheme();

    /* ── Toggle dark / light ── */
    window.toggleDarkMode = function () {
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (isDark) {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('hehms-theme', 'light');
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('hehms-theme', 'dark');
        }
        syncUI();
    };

    /* ── Open / close settings modal ── */
    window.openSettings = function () {
        var m = document.getElementById('settings-modal');
        if (m) { m.classList.add('active'); syncUI(); }
    };
    window.closeSettings = function (e) {
        var m = document.getElementById('settings-modal');
        if (!m) return;
        if (!e || e.target === m) m.classList.remove('active');
    };

    /* ── Sync all UI elements to current theme ── */
    function syncUI() {
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';

        /* Settings modal knob */
        var knob  = document.getElementById('dm-knob');
        var label = document.getElementById('dm-label');
        var track = document.getElementById('dm-track');
        if (knob)  knob.style.transform  = isDark ? 'translateX(22px)' : 'translateX(2px)';
        if (track) track.style.background = isDark ? '#6b8f3a' : '#d1d5db';
        if (label) label.textContent      = isDark ? 'On' : 'Off';

        /* Header toggle button icon */
        var btn = document.getElementById('theme-toggle');
        if (btn) btn.textContent = isDark ? '☀️' : '🌙';
    }

    /* ── DOM ready: wire everything up ── */
    document.addEventListener('DOMContentLoaded', function () {
        syncUI();

        /* Header toggle button */
        var btn = document.getElementById('theme-toggle');
        if (btn) {
            btn.addEventListener('click', function () {
                toggleDarkMode();
            });
        }

        /* Settings nav link */
        document.querySelectorAll('.nav-link').forEach(function (el) {
            if (el.textContent.trim().toLowerCase().includes('settings')) {
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    openSettings();
                });
            }
        });
    });

})();