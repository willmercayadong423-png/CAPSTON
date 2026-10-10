<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMHS — public certificate verification  (verify.php)
 * ───────────────────────────────────────────────────────────────────
 * Every e-certificate carries a QR code (bottom-right, above the
 * Reference No.) pointing here:
 *
 *   verify.php?req=<id>&code=<16-hex HMAC>
 *
 * Anyone can scan it with a phone — NO LOGIN — and instantly see whether
 * the certificate is legitimate, together with the ORIGINAL owner's
 * details and the actual released PDF (side-by-side on desktop).
 *
 * Security:
 *   • the ?code= is an unguessable HMAC-SHA256 of the request id vs
 *     VERIFICATION_SECRET (config.php) → request ids cannot be enumerated
 *   • comparison uses hash_equals (constant time)
 *   • only RELEASED certificates verify; anything else fails with one
 *     generic message (no reason leaked)
 *   • the PDF itself streams through verifyCertFile.php (same gate)
 * ═══════════════════════════════════════════════════════════════════ */
require __DIR__ . '/database/db.php';
require_once __DIR__ . '/phpLogics/certificate.php';   // certificate_verify_code()
require_once __DIR__ . '/phpLogics/site_config.php';   // registrar contact info

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$reqId = (int)($_GET['req'] ?? 0);
$code  = preg_replace('/[^0-9a-f]/', '', (string)($_GET['code'] ?? ''));

$cert   = null;    // valid record details (null = NOT verifiable)
$hadParams = ($reqId > 0 && $code !== '');

if ($hadParams) {
    if (strlen($code) === 16 && hash_equals(certificate_verify_code($reqId), $code)) {
        $q = $conn->prepare(
            "SELECT dr.id, dr.status, dr.date_requested, dr.date_released,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    dt.name AS document_type
             FROM document_requests dr
             JOIN users u ON u.id = dr.user_id
             LEFT JOIN document_types dt ON dt.id = dr.document_type_id
             WHERE dr.id = ? AND dr.status = 'Released' AND dr.e_certificate IS NOT NULL"
        );
        $q->bind_param('i', $reqId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();

        if ($row) {
            $cert = [
                'req_no'     => 'REQ-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT),
                'student'    => $row['student_name'],
                'doc_type'   => $row['document_type'] ?? '—',
                'requested'  => date('F j, Y', strtotime($row['date_requested'])),
                'released'   => $row['date_released'] ? date('F j, Y', strtotime($row['date_released'])) : '—',
            ];
        }
    }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$registrarEmail = e(site_setting('contact_email', 'hehmhsadmin@gmail.com'));
$registrarPhone = e(site_setting('contact_phone', '(044) 123-4567'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/png" href="assets/img/Logo.png">
    <title>Certificate Verification — HEHMHS</title>
    <style>
        :root {
            --green:#4a6741; --green-mid:#6b8f3a; --green-pale:#eef4e6;
            --gold:#c9a84c; --gold-d:#a9812f; --paper:#fffdf8;
            --line:#e5dcc3; --ink:#1f2937; --ink-soft:#6b7280; --red:#b91c1c;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { min-height: 100%; }
        body {
            background: linear-gradient(160deg, #f4efe1 0%, #eef4e6 55%, #e7efdc 100%);
            font-family: Arial, Helvetica, sans-serif; color: var(--ink);
            display: flex; flex-direction: column; min-height: 100vh;
        }
        .wrap { width: 100%; max-width: 1200px; margin: 0 auto; padding: 20px 20px 0; flex: 1; display: flex; flex-direction: column; }

        /* ── Header bar ─────────────────────────────────────────── */
        .top {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 20px; margin-bottom: 20px;
            background: var(--paper); border: 1px solid var(--line); border-radius: 16px;
            box-shadow: 0 10px 28px rgba(21,32,18,.10);
        }
        .top img { width: 46px; height: 46px; object-fit: contain; border-radius: 10px; background: #fff; padding: 3px; border: 1px solid var(--line); }
        .top .t-name { flex: 1; min-width: 0; }
        .top .t-name strong { display: block; font-size: .98rem; color: #16220d; letter-spacing: .01em; }
        .top .t-name span { display: block; font-size: .76rem; color: var(--ink-soft); margin-top: 2px; }
        .top .t-pill {
            font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
            color: var(--green); background: var(--green-pale); border: 1px solid #d7e3c5;
            padding: 6px 13px; border-radius: 999px; white-space: nowrap;
        }

        /* ── Content: two columns when valid, centered card otherwise ── */
        .content { flex: 1; display: grid; gap: 22px; align-items: stretch; padding-bottom: 22px; }
        .content.two-col { grid-template-columns: minmax(0, 1fr) 400px; }
        .content.single   { grid-template-columns: minmax(0, 560px); justify-content: center; }

        /* ── LEFT — certificate preview ─────────────────────────── */
        .preview {
            display: flex; flex-direction: column; min-width: 0;
            background: var(--paper); border: 1px solid var(--line); border-radius: 16px;
            box-shadow: 0 18px 44px rgba(21,32,18,.13); overflow: hidden;
        }
        .p-head {
            display: flex; align-items: center; gap: 9px;
            padding: 13px 18px; background: #faf6ec; border-bottom: 1px solid var(--line);
            font-size: .78rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .07em; color: #6d6553;
        }
        .p-head svg { color: var(--gold-d); flex: 0 0 auto; }
        .p-head .p-req { margin-left: auto; color: var(--green); font-size: .78rem; }
        .p-frame { position: relative; flex: 1; min-height: 540px; background: #f3efe3; }
        .p-frame iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; display: block; }
        .p-spin {
            position: absolute; inset: 0; display: flex; flex-direction: column; gap: 12px;
            align-items: center; justify-content: center; color: var(--ink-soft); font-size: .82rem;
            background: #f3efe3;
        }
        .p-spin i {
            width: 34px; height: 34px; border-radius: 50%;
            border: 3px solid #ddd4bb; border-top-color: var(--gold-d);
            animation: spin .8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .p-actions {
            display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;
            padding: 14px 16px; background: #faf6ec; border-top: 1px solid var(--line);
        }
        .btn {
            display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
            border-radius: 999px; padding: 11px 26px; font-size: .87rem; font-weight: 700;
            cursor: pointer; border: 0; transition: filter .15s ease, background .15s ease;
        }
        .btn svg { flex: 0 0 auto; }
        .btn-primary { background: linear-gradient(135deg, var(--green-mid), var(--green)); color: #fff; }
        .btn-primary:hover { filter: brightness(1.08); }
        .btn-ghost { background: transparent; color: var(--green); border: 1.5px solid #cfd8c5; }
        .btn-ghost:hover { background: #f2f6ec; }

        /* ── RIGHT — verdict card ───────────────────────────────── */
        .verdict {
            display: flex; flex-direction: column; justify-content: center;
            background: var(--paper); border: 1px solid var(--line); border-radius: 16px;
            box-shadow: 0 18px 44px rgba(21,32,18,.13);
            padding: 30px 28px 24px; text-align: center; position: relative; overflow: hidden;
        }
        .verdict::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 5px; }
        .verdict.ok::before  { background: linear-gradient(90deg, var(--green-mid), var(--green)); }
        .verdict.bad::before { background: linear-gradient(90deg, #ef4444, var(--red)); }
        .verdict.q::before   { background: linear-gradient(90deg, var(--gold), var(--gold-d)); }

        .v-icon { width: 74px; height: 74px; margin: 8px auto 14px; border-radius: 50%;
                  display: flex; align-items: center; justify-content: center; }
        .v-icon.ok  { background: linear-gradient(135deg, var(--green-mid), var(--green)); color: #fff; }
        .v-icon.bad { background: linear-gradient(135deg, #ef4444, var(--red)); color: #fff; }
        .v-icon.q   { background: linear-gradient(135deg, var(--gold), var(--gold-d)); color: #fff; }

        .v-title { font-size: 1.32rem; margin-bottom: 8px; color: #16220d; letter-spacing: -.01em; }
        .v-title.ok  { color: #14532d; }
        .v-title.bad { color: #7f1d1d; }
        .v-lead { color: #4b5563; font-size: .89rem; line-height: 1.6; }

        .v-list { margin: 20px 0 4px; text-align: left; }
        .v-row { display: flex; gap: 14px; padding: 10px 2px; border-bottom: 1px solid #f0ead8; align-items: baseline; }
        .v-row:last-child { border-bottom: 0; }
        .v-row dt { flex: 0 0 42%; font-size: .7rem; font-weight: 700; text-transform: uppercase;
                    letter-spacing: .05em; color: var(--ink-soft); }
        .v-row dd { flex: 1; font-size: .9rem; font-weight: 700; color: var(--ink); overflow-wrap: anywhere; }
        .v-row dd.hl { color: var(--green); font-size: .95rem; }

        .v-note {
            margin-top: 16px; font-size: .77rem; color: #6b7280; line-height: 1.6;
            background: #f4efe1; border: 1px solid var(--line); border-radius: 12px;
            padding: 11px 13px; text-align: left;
        }
        .v-note strong { color: #5f4410; }
        .v-note.bad { background: #fdf1f1; border-color: #ecc8c8; }
        .v-note.bad strong { color: #7f1d1d; }

        /* ── Footer ─────────────────────────────────────────────── */
        .foot {
            text-align: center; font-size: .74rem; color: #8a8271;
            padding: 14px 10px 18px;
        }
        .foot strong { color: #6d6553; }

        /* ── Responsive ─────────────────────────────────────────── */
        @media (max-width: 980px) {
            .content.two-col { grid-template-columns: minmax(0, 1fr); }
            .verdict { order: -1; }          /* verdict first on phones */
            .p-frame { min-height: 460px; }
        }
        @media (max-width: 560px) {
            .wrap { padding: 12px 12px 0; }
            .top { flex-wrap: wrap; padding: 12px 14px; }
            .top .t-pill { display: none; }
            .p-frame { min-height: 380px; }
            .verdict { padding: 24px 18px 20px; }
            .v-row dt { flex-basis: 46%; }
        }
    </style>
</head>
<body>
    <div class="wrap">

        <!-- ══ Header ══ -->
        <header class="top">
            <img src="assets/img/Logo.png" alt="HEHMHS logo">
            <div class="t-name">
                <strong>Hilario E. Hermosa Memorial High School</strong>
                <span>Registrar's Office · Certificate Verification Portal</span>
            </div>
            <span class="t-pill">Official</span>
        </header>

        <!-- ══ Content ══ -->
        <?php if ($cert !== null): ?>
            <?php $fileBase = 'phpLogics/verifyCertFile.php?req=' . $reqId . '&code=' . $code; ?>
            <main class="content two-col" role="main">

                <!-- LEFT — the actual certificate -->
                <section class="preview" aria-label="Original certificate preview">
                    <div class="p-head">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        Original e-Certificate
                        <span class="p-req"><?php echo e($cert['req_no']); ?></span>
                    </div>
                    <div class="p-frame">
                        <div class="p-spin" id="pdfSpin"><i></i>Loading certificate…</div>
                        <iframe src="<?php echo e($fileBase); ?>" title="Certificate PDF preview" loading="lazy"
                            onload="document.getElementById('pdfSpin').style.display='none'">
                            Your browser cannot display PDFs — use the buttons below.
                        </iframe>
                    </div>
                    <div class="p-actions">
                        <a class="btn btn-primary" href="<?php echo e($fileBase . '&download=1'); ?>" download>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Download PDF
                        </a>
                        <a class="btn btn-ghost" href="<?php echo e($fileBase); ?>" target="_blank" rel="noopener">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                            View Full Size
                        </a>
                    </div>
                </section>

                <!-- RIGHT — the verdict -->
                <aside class="verdict ok">
                    <div class="v-icon ok">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <h1 class="v-title ok">Certificate is legitimate</h1>
                    <p class="v-lead">This matches a record officially <strong>released</strong> by the Registrar's Office of Hilario E. Hermosa Memorial High School.</p>

                    <dl class="v-list">
                        <div class="v-row"><dt>Reference No.</dt><dd class="hl"><?php echo e($cert['req_no']); ?></dd></div>
                        <div class="v-row"><dt>Registered Owner</dt><dd><?php echo e($cert['student']); ?></dd></div>
                        <div class="v-row"><dt>Document</dt><dd><?php echo e($cert['doc_type']); ?></dd></div>
                        <div class="v-row"><dt>Date Requested</dt><dd><?php echo e($cert['requested']); ?></dd></div>
                        <div class="v-row"><dt>Date Released</dt><dd><?php echo e($cert['released']); ?></dd></div>
                    </dl>

                    <div class="v-note">
                        <strong>How to judge the printed copy:</strong> the owner and document above are the
                        original details as released by the school — compare them (and the preview on the left)
                        with the certificate you are holding. If anything differs, the copy is not legitimate.
                        <?php if ($registrarEmail): ?>Report concerns to <strong><?php echo $registrarEmail; ?></strong><?php if ($registrarPhone): ?> · <?php echo $registrarPhone; ?><?php endif; ?>.<?php endif; ?>
                    </div>
                </aside>

            </main>

        <?php elseif ($hadParams): ?>
            <!-- ═══ INVALID ═══ -->
            <main class="content single" role="main">
                <aside class="verdict bad">
                    <div class="v-icon bad">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </div>
                    <h1 class="v-title bad">Not verified</h1>
                    <p class="v-lead">This QR code does not match any certificate released by Hilario E. Hermosa Memorial High School.</p>
                    <div class="v-note bad">
                        <strong>Do not accept this certificate.</strong> It may be a fake or altered copy — or the
                        request was cancelled before release. Contact the Registrar's Office to confirm:
                        <?php if ($registrarEmail): ?><strong><?php echo $registrarEmail; ?></strong><?php endif; ?>
                        <?php if ($registrarPhone): ?> · <?php echo $registrarPhone; ?><?php endif; ?>.
                    </div>
                </aside>
            </main>

        <?php else: ?>
            <!-- ═══ NO PARAMETERS — instructions ═══ -->
            <main class="content single" role="main">
                <aside class="verdict q">
                    <div class="v-icon q">
                        <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 7h.01"/><path d="M17 7h.01"/><path d="M7 17h.01"/><path d="M17 17h.01"/><rect x="7" y="7" width="10" height="10"/></svg>
                    </div>
                    <h1 class="v-title">Verify a certificate</h1>
                    <p class="v-lead">Scan the <strong>QR code printed at the bottom-right of an HEHMHS e-certificate</strong> with your phone camera — this page will open and confirm whether the certificate is legitimate, show the original owner's details, and let you compare the actual PDF.</p>
                    <div class="v-note">
                        You can also open the address manually:
                        <strong>verify.php?req=NUMBER&amp;code=CODE</strong> exactly as printed beside the QR code.
                    </div>
                </aside>
            </main>
        <?php endif; ?>

        <!-- ══ Footer ══ -->
        <footer class="foot">
            <strong>Hilario E. Hermosa Memorial High School</strong> · Registrar's Office<br>
            Credential Request &amp; Tracking System
        </footer>

    </div>
</body>
</html>
