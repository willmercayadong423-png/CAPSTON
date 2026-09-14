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
            'logo_path'       => 'assets/img/Logo.png',
            'theme_color'     => '#6b8f3a',
            'office_hours'    => 'Monday to Friday, 8:00 AM – 4:00 PM',
            'contact_email'   => 'registrar@hehms.edu.ph',
            'contact_phone'   => '(044) 123-4567',
            'contact_location'=> 'Hilario E. Hermosa Memorial High School, Siclong, Laur, Nueva Ecija',
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

    /* Single-setting helper with fallback */
    function site_setting(string $key, string $default = ''): string
    {
        $s = site_settings();
        $v = trim((string)($s[$key] ?? ''));
        return $v !== '' ? $v : $default;
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

    /* ── Design theme catalog (admin-selectable full looks) ─────────── */
    function design_themes(): array
    {
        return [
            'heritage' => [
                'name' => 'Heritage Letterhead', 'emoji' => '🏛️',
                'desc' => 'Matches the login page — forest ink, parchment & antique gold.',
                'primary' => '#4c6b2f', 'accent' => '#a9812f', 'accent_light' => '#c9a44c',
                'paper'  => '#f4efe1',
                'radius' => '12px',
                'font_import' => 'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,400;1,9..144,500&family=Inter:wght@400;500;600;700&display=swap',
                'font_head' => "'Fraunces', serif",
                'font_body' => "'Inter', sans-serif",
                'extra'   => '--card-bg:#fffdf8;--card-border:#ddd4bb;--btn-grad:linear-gradient(135deg,#c9a44c,#a9812f);--btn-ink:#16220d;--ink:#16220d;',
            ],
            'classic' => [
                'name' => 'Classic Academy', 'emoji' => '🎓',
                'desc' => 'The original look — forest green & gold, serif headings.',
                'primary' => '#6b8f3a', 'accent' => '#c9a84c', 'accent_light' => '#f0d97a',
                'radius' => '16px', 'font_import' => '', 'font_head' => '', 'font_body' => '',
            ],
            'ocean' => [
                'name' => 'Ocean Blue', 'emoji' => '🌊',
                'desc' => 'Deep sea blues with teal accents — fresh and modern.',
                'primary' => '#1d6fa5', 'accent' => '#0f766e', 'accent_light' => '#7dd3c8',
                'radius' => '12px',
                'font_import' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@300;400;500;600&display=swap',
                'font_head' => "'Poppins', sans-serif",
                'font_body' => "'Inter', sans-serif",
            ],
            'violet' => [
                'name' => 'Royal Violet', 'emoji' => '👑',
                'desc' => 'Regal violet with warm amber highlights.',
                'primary' => '#7c3aed', 'accent' => '#d97706', 'accent_light' => '#fcd34d',
                'radius' => '14px',
                'font_import' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Source+Sans+3:wght@300;400;500;600&display=swap',
                'font_head' => "'Montserrat', sans-serif",
                'font_body' => "'Source Sans 3', sans-serif",
            ],
            'terracotta' => [
                'name' => 'Terracotta', 'emoji' => '🏺',
                'desc' => 'Warm earthy tones with golden olive accents.',
                'primary' => '#b0532b', 'accent' => '#8a6d1a', 'accent_light' => '#e0c76a',
                'radius' => '10px',
                'font_import' => 'https://fonts.googleapis.com/css2?family=Lora:wght@500;600;700&family=Nunito+Sans:wght@300;400;600;700&display=swap',
                'font_head' => "'Lora', serif",
                'font_body' => "'Nunito Sans', sans-serif",
            ],
            'slate' => [
                'name' => 'Corporate Slate', 'emoji' => '🗂️',
                'desc' => 'Sharp charcoal greys with a sky accent — businesslike.',
                'primary' => '#374151', 'accent' => '#0ea5e9', 'accent_light' => '#7dd3fc',
                'radius' => '8px',
                'font_import' => 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@300;400;500;600&display=swap',
                'font_head' => "'Space Grotesk', sans-serif",
                'font_body' => "'Inter', sans-serif",
            ],
        ];
    }

    function theme_head(): string
    {
        $themeKey = site_setting('design_theme', 'classic');
        $themes   = design_themes();
        $theme    = $themes[$themeKey] ?? $themes['classic'];

        // Classic keeps the admin's custom color picker; named themes use
        // their own full palette.
        $color = $theme['primary'];
        if ($themeKey === 'classic') {
            $color = site_settings()['theme_color'] ?? '#6b8f3a';
            if (!preg_match('/^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color)) {
                $color = '#6b8f3a';
            }
        }

        $dark   = theme_mix($color, '#000000', 0.30);
        $light  = theme_mix($color, '#ffffff', 0.22);
        $pale   = theme_mix($color, '#ffffff', 0.74);
        $accent = $theme['accent'];
        $accentLight = $theme['accent_light'];

        $out = '';
        if ($theme['font_import'] !== '') {
            $out .= "@import url('{$theme['font_import']}');";
        }
        $out .= ":root{--green-mid:{$color};--green-dark:{$dark};"
             . "--green-light:{$light};--green-pale:{$pale};"
             . "--gold:{$accent};--gold-light:{$accentLight};--radius:{$theme['radius']};"
             . ($theme['extra'] ?? '')
             . "}";

        // Font + corner-radius skin (named themes only — classic keeps built-ins)
        if ($theme['font_head'] !== '') {
            $fh = $theme['font_head'];
            $fb = $theme['font_body'];
            $out .= "h1,h2,h3,h4,.school-info h2{font-family:{$fh} !important;}"
                 . "body,button,input,select,textarea,table{font-family:{$fb} !important;}"
                 . ".account-card,.dashboard-card,.card,.container,.modal-box,.doc-card,.release-box{border-radius:var(--radius) !important;}"
                 . "button,.btn,.save-btn,.doc-act,.rf-group input,.rf-group textarea,.chip,.search{border-radius:calc(var(--radius) - 4px) !important;}";
        }

        return "<style>{$out}</style>";
    }
}
