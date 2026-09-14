<?php
/* ── HEHMS site configuration ────────────────────────────────────────
 * Loads admin-managed settings (logo, theme color) and provides helpers
 * usable from any page, at any folder depth:
 *   site_settings()   – cached key => value array
 *   site_url($path)   – absolute URL for a project-root-relative path
 *   site_logo_url()   – current logo URL (falls back to default logo)
 *   theme_head()      – <style> block overriding the CSS brand variables
 * Requires an active mysqli connection in $conn (db.php already loaded).
 * ──────────────────────────────────────────────────────────────────── */

if (!function_exists('site_settings')) {

    function site_settings(): array
    {
        static $settings = null;
        if ($settings !== null) return $settings;

        global $conn;
        $settings = [
            'logo_path'   => 'assets/img/Logo.png',
            'theme_color' => '#6b8f3a',
        ];
        if (isset($conn) && $conn instanceof mysqli) {
            $res = @$conn->query("SELECT setting_key, setting_value FROM site_settings");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            }
        }
        return $settings;
    }

    function site_url(string $path = ''): string
    {
        $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $projDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        $base    = ($docRoot !== '' && strpos($projDir, $docRoot) === 0)
            ? substr($projDir, strlen($docRoot))
            : '';
        $base = implode('/', array_map('rawurlencode', explode('/', $base)));
        return $base . '/' . ltrim($path, '/');
    }

    function site_logo_url(): string
    {
        $s     = site_settings();
        $logo  = trim($s['logo_path'] ?? '');
        return $logo !== '' ? site_url($logo) : site_url('assets/img/Logo.png');
    }

    /* Mix two hex colors; $amt = 0 returns $hex, 1 returns $target */
    function theme_mix(string $hex, string $target, float $amt): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 3 && strlen($hex) !== 6) return '#' . $hex;
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $t = ltrim($target, '#');
        $out = '';
        for ($i = 0; $i < 3; $i++) {
            $a = hexdec(substr($hex, $i * 2, 2));
            $b = hexdec(substr($t,   $i * 2, 2));
            $v = (int)round($a + ($b - $a) * $amt);
            $out .= str_pad(dechex(max(0, min(255, $v))), 2, '0', STR_PAD_LEFT);
        }
        return '#' . $out;
    }

    function theme_head(): string
    {
        $color = site_settings()['theme_color'] ?? '#6b8f3a';
        if (!preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color)) {
            $color = '#6b8f3a';
        }
        $dark  = theme_mix($color, '#000000', 0.30);
        $light = theme_mix($color, '#ffffff', 0.22);
        $pale  = theme_mix($color, '#ffffff', 0.74);

        return "<style>:root{--green-mid:{$color};--green-dark:{$dark};"
             . "--green-light:{$light};--green-pale:{$pale};}</style>";
    }
}
