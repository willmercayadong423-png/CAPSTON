<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMHS — session heartbeat  (phpLogics/heartbeat.php)
 * ───────────────────────────────────────────────────────────────────
 * Called every few minutes by assets/js/session-timeout.js WHILE the
 * user is present (any mouse/keyboard activity since the last ping).
 *
 *   alive session  → 200 {ok:true}   (auth.php refreshed last_activity)
 *   dead/expired   → 302 to the login page (auth.php) — the client
 *                    detects it via fetch(..., {redirect:'manual'})
 *                    → opaqueredirect → and bounces immediately.
 *
 * This closes the old gap where the client kept resetting its idle
 * timer on mouse-movements the SERVER never saw: an "idle but reading"
 * admin stayed on a zombie page whose session had long expired.
 * ═══════════════════════════════════════════════════════════════════ */
require __DIR__ . "/auth.php";   // refreshes last_activity (or redirects)

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
echo json_encode(['ok' => true, 'time' => time()]);
