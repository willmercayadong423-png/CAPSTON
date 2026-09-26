<?php
/* ── Legacy URL — Account Information now lives INSIDE the Registrar
 * Dashboard as an in-page view (the separate page made the whole
 * dashboard disappear on navigation). Old links/bookmarks are sent
 * to the right place. ──────────────────────────────────────────── */
require __DIR__ . "/../phpLogics/auth.php";

header("Location: registrarMainPage.php?view=account");
exit;
