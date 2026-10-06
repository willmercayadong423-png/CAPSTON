<?php
/* ═══════════════════════════════════════════════════════════════════
   HEHMS — Report month chooser  (phpLogics/report_menu.php)
   ───────────────────────────────────────────────────────────────────
   Renders the "Report PDF" split button used on the Registrar AND
   Admin dashboards: click → small menu with exactly two options —

        · Current month   (month in progress — covers requests so far)
        · Last month      (the completed previous month)

   Each option opens exportReport.php?month=YYYY-MM in a new tab —
   the same PDF the automatic month-end e-mail sends (report_pdf.php
   builds both). Shared helper = one source of truth for all three
   button locations (registrar hero, registrar requests header, admin
   requests header).

   Usage:
       require_once __DIR__ . '/report_menu.php';
       echo report_menu_html('btn-export');   // table-header style
       echo report_menu_html('hero-cta');     // registrar home hero

   The button keeps whichever visual class you pass; the menu and the
   tiny toggle script are appended automatically (script prints once
   per page no matter how many menus are rendered).
   ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/icons.php';

if (!function_exists('report_menu_html')) {

    function report_menu_html(string $btnClass = 'btn-export', string $prefix = '../'): string
    {
        // ── The two months on offer ──
        $curKey    = date('Y-m');                                 // e.g. 2026-10
        $curLabel  = date('F Y');                                 // October 2026
        $lastKey   = date('Y-m', strtotime('first day of previous month'));
        $lastLabel = date('F Y', strtotime('first day of previous month'));

        $iconSize = ($btnClass === 'hero-cta') ? 16 : 14;

        $html = "<div class='report-menu'>"
            . "<button type='button' class='" . htmlspecialchars($btnClass) . " report-menu-toggle'"
            . " title='Choose a month, then open the report (PDF)' aria-haspopup='menu'>"
            . icon('file-text', $iconSize) . " Report PDF " . icon('chevron-down', 13)
            . "</button>"
            . "<div class='report-menu-list' role='menu'>"

            // Current month — still in progress, but staff may want a
            // mid-month snapshot (same behaviour as the old button).
            . "<a href='{$prefix}phpLogics/exportReport.php?month={$curKey}' target='_blank' role='menuitem'"
            . " title=\"Month in progress — covers requests so far\">"
            . "<span class='report-menu-month'>" . icon('calendar', 14) . " {$curLabel}</span>"
            . "<span class='report-menu-tag'>Current</span></a>"

            // Last month — complete, matches the automatic e-mailed report.
            . "<a href='{$prefix}phpLogics/exportReport.php?month={$lastKey}' target='_blank' role='menuitem'"
            . " title='Completed month — the same report the month-end e-mail sends'>"
            . "<span class='report-menu-month'>" . icon('calendar', 14) . " {$lastLabel}</span>"
            . "<span class='report-menu-tag'>Last Month</span></a>"

            . "</div></div>";

        return $html . report_menu_script();
    }

    /* ── Toggle script — printed once per page, event-delegated so it
       drives every .report-menu on the page identically. ─────────── */
    function report_menu_script(): string
    {
        static $printed = false;
        if ($printed) return '';
        $printed = true;

        return "<script>
(function () {
    if (window.__reportMenuInit) return; window.__reportMenuInit = true;
    function closeAll() {
        document.querySelectorAll('.report-menu-list.open').forEach(function (l) {
            l.classList.remove('open');
        });
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;
        var link = e.target.closest('.report-menu-list a');
        if (link) { closeAll(); return; }            // open the PDF tab, close the menu
        var toggle = e.target.closest('.report-menu-toggle');
        if (toggle) {
            var list = toggle.closest('.report-menu').querySelector('.report-menu-list');
            var wasOpen = list.classList.contains('open');
            closeAll();
            if (!wasOpen) list.classList.add('open');
            return;
        }
        closeAll();                                   // clicked anywhere else
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll();
    });
})();
</script>";
    }
}
