<?php
/* One-off build tool: extracts request-management styles from
 * registrar/registrarCSS.css into assets/css/requests.css, scoping
 * top-level rules under .reqmgr so they can coexist with dashb.css /
 * admin.css on the Admin dashboard. Rules already scoped to the unique
 * modal IDs are copied verbatim; @keyframes stay global.
 */
$src = file_get_contents('registrar/registrarCSS.css');
$lines = explode("\n", $src);   // ORIGINAL line numbers — ranges refer to these
$n = count($lines);

$ranges = [
    [292, 374],   // .cards / .card / .switch-btn
    [375, 431],   // table container / table-header / search
    [432, 503],   // .tbl-x table
    [504, 520],   // status badges
    [521, 589],   // action buttons
    [606, 715],   // filter chips / export / alerts / card-click
    [716, 947],   // release modal
    [948, 1331],  // request details modal
    [1332, 1550], // fullscreen lightbox
    [1592, 1657], // reject modal
    [1705, 1841], // dash-grid / dash-panel / recent list
];

$raw = '';
foreach ($ranges as [$a, $b]) {
    for ($i = $a - 1; $i <= $b - 1 && $i < $n; $i++) $raw .= $lines[$i] . "\n";
}

// Strip comments AFTER range extraction (keeps original line numbering)
$raw = preg_replace('/\/\*.*?\*\//s', '', $raw);

// @keyframes fadeUp lives outside the ranges — find and append verbatim
if (preg_match('/@keyframes\s+fadeUp\s*\{(?:[^{}]|\{[^{}]*\})*\}/s', $src, $km)) {
    $keyframes = $km[0];
} else {
    $keyframes = '';
}

function transform(string $css, string $prefix, int $depth = 0): string {
    $res = ''; $sel = ''; $len = strlen($css);
    for ($i = 0; $i < $len; $i++) {
        $ch = $css[$i];
        if ($ch === '{') {
            // read balanced body
            $d = 1; $j = $i + 1; $body = '';
            while ($j < $len && $d > 0) {
                $c = $css[$j];
                if ($c === '{') $d++;
                if ($c === '}') $d--;
                if ($d > 0) $body .= $c;
                $j++;
            }
            $s = trim($sel);
            if ($s !== '') {
                if ($s[0] === '@') {
                    if (preg_match('/^@(media|supports)/', $s)) {
                        $res .= $s . " {\n" . transform($body, $prefix, $depth + 1) . "}\n";
                    } else {
                        $res .= $s . " {" . $body . "}\n"; // keyframes/font-face verbatim
                    }
                } else {
                    $parts = array_map('trim', explode(',', $s));
                    $pref = [];
                    foreach ($parts as $p) {
                        if ($p === '') continue;
                        if (preg_match('/^(#file-modal|#release-modal|#reject-modal|#doc-lightbox)\b/', $p)
                            || strpos($p, '.reqmgr') === 0 || $p[0] === '@') {
                            $pref[] = $p;
                        } else {
                            $pref[] = $prefix . ' ' . $p;
                        }
                    }
                    $res .= implode(', ', $pref) . ' {' . $body . "}\n";
                }
            }
            $sel = '';
            $i = $j - 1;
            continue;
        }
        if ($ch === '}') { $sel = ''; continue; } // stray close (shouldn't happen)
        $sel .= $ch;
    }
    // trailing declarations noise (none expected)
    return $res;
}

$css = transform($raw, '.reqmgr');
// The registrar's dashboard-view id doesn't exist on admin — drop it
$css = str_replace('#view-dashboard ', '', $css);

$header = "/* ══════════════════════════════════════════════════════════════
   HEHMS — Request-management styles
   Extracted from registrar/registrarCSS.css by build_requests_css.php
   and scoped under .reqmgr so the Admin dashboard can reuse the
   registrar's request UI without colliding with dashb.css/admin.css.
   Modal internals keep their unique #file-modal / #release-modal /
   #reject-modal / #doc-lightbox ID scoping. Re-run the builder script
   after changing registrarCSS.css — don't hand-edit.
   ══════════════════════════════════════════════════════════════ */

.reqmgr { /* scope anchor */ }

";

file_put_contents('assets/css/requests.css', $header . $css . "\n" . $keyframes . "\n");
echo "OK: wrote assets/css/requests.css\n";
// quick sanity: no unprefixed top-level class selectors outside keyframes
if (preg_match_all('/^[.][a-zA-Z][^{,\n]*\s*\{/m', $css, $mm)) {
    echo "WARNING unprefixed selectors:\n" . implode("\n", $mm[0]) . "\n";
} else {
    echo "All top-level selectors scoped.\n";
}
