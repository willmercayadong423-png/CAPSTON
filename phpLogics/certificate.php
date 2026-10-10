<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMHS Certificate Generator  (phpLogics/certificate.php)
 * ───────────────────────────────────────────────────────────────────
 * System-generates the e-certificate as a PDF (dompdf). The registrar
 * can edit the generated content before releasing — the student data
 * is auto-filled from the record.
 *
 * Placeholders usable in the certificate body (kept when the registrar
 * edits the default text):
 *   {NAME}     → student full name (rendered large, centered)
 *   {LRN}      → LRN
 *   {GRADE}    → grade/strand or last school year attended
 *   {PURPOSE}  → the request purpose
 *
 * Public functions:
 *   certificate_fetch($conn, $req_id)      → request + student row
 *   certificate_defaults($row, $officer)   → default editable fields
 *   certificate_build_data($row, $edits)   → merge edits over defaults
 *   render_certificate_html($d)            → branded HTML template
 *   generate_certificate_pdf($d)           → PDF binary (dompdf)
 *   certificate_logo_uri()                 → base64 logo for the PDF
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\{QRCode, QROptions};
use chillerlan\QRCode\Output\QRGdImagePNG;

/* ═══════════════════════════════════════════════════════════════════
 * QR VERIFICATION — every e-certificate carries a QR code that opens
 * the public verification page (verify.php) with an unguessable code:
 *
 *   certificate_verify_code($reqId)  → 16-hex HMAC of the request id
 *   certificate_verify_url($reqId)   → absolute URL for the QR target
 *   certificate_qr_uri($reqId)       → base64 PNG data-URI for the PDF
 *
 * The code is deterministic (HMAC-SHA256 vs VERIFICATION_SECRET), so the
 * QR on the live preview always matches the one on the released PDF,
 * and guessing/enumerating other requests' codes is infeasible.
 * ═══════════════════════════════════════════════════════════════════ */
function certificate_verify_code(int $reqId): string
{
    $secret = defined('VERIFICATION_SECRET') ? VERIFICATION_SECRET : 'hehmhs-verify';
    return substr(hash_hmac('sha256', 'certificate:' . $reqId, $secret), 0, 16);
}

function certificate_verify_url(int $reqId): string
{
    // Absolute URL — a phone camera must be able to open it directly.
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $projDir  = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $base     = ($docRoot !== '' && strpos($projDir, $docRoot) === 0)
        ? substr($projDir, strlen($docRoot))
        : '';
    $base     = implode('/', array_map('rawurlencode', explode('/', $base)));

    return $scheme . '://' . $host . $base . '/verify.php'
         . '?req=' . $reqId . '&code=' . certificate_verify_code($reqId);
}

function certificate_qr_uri(int $reqId): string
{
    static $cache = [];
    if (isset($cache[$reqId])) return $cache[$reqId];

    try {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,  // PNG via GD
            'eccLevel'        => 'H',                  // max damage tolerance
            'scale'           => 5,
            'imageBase64'     => true,                 // → data:image/png;base64,…
            'addQuietzone'    => true,
        ]);
        return $cache[$reqId] = (string)(new QRCode($options))->render(certificate_verify_url($reqId));
    } catch (Throwable $e) {
        // A QR failure must never block the certificate itself
        error_log('QR generation failed (REQ-' . $reqId . '): ' . $e->getMessage());
        return '';
    }
}

/* ── Fetch one request joined with its student record ────────────── */
function certificate_fetch(mysqli $conn, int $req_id): ?array
{
    $q = $conn->prepare(
        "SELECT dr.id AS req_id, dr.status, dt.name AS document_type, dr.purpose, dr.date_requested,
                dr.e_certificate, dr.grade_level AS req_grade_level,
                dr.school_year_last_attended AS req_school_year,
                s.id AS student_pk, sp.student_id AS school_id,
                s.first_name, s.last_name, sp.lrn
         FROM document_requests dr
         JOIN document_types dt ON dt.id = dr.document_type_id
         JOIN users s ON dr.user_id = s.id
         LEFT JOIN student_profiles sp ON sp.user_id = s.id
         WHERE dr.id = ?"
    );
    $q->bind_param("i", $req_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$row) {
        return null;
    }

    // Grade Level and School Year come from the REQUEST the student filed
    // (schema v2: the account-level fields were removed from the system —
    // grade_level/school_year_last_attended live on document_requests).
    $row['grade_level']               = trim((string)($row['req_grade_level'] ?? ''));
    $row['school_year_last_attended'] = trim((string)($row['req_school_year'] ?? ''));

    return $row;
}

/* ── Default editable fields (auto-filled from the record) ─────────
 * Bodies follow the school's OFFICIAL printed certificates
 * (Good_Moral.jpg / Graduation.jpg / Enrollment.jpg).
 * Placeholders: {NAME} renders as FIRSTNAME LASTNAME (bold, all caps),
 * {LRN} = Learner Reference Number, {SY} = school year,
 * {GRADE} = grade level (plain, for inline "as Grade 11"),
 * {DATE} = "22nd day of SEPTEMBER, 2026" (official format). */
function certificate_defaults(array $row, string $officerName = ''): array
{
    $docType = $row['document_type'];

    // ── Title style ──
    // Graduation prints "CERTIFICATE OF GRADUATION" (no salutation);
    // Good Moral and Enrollment are letter-style — they print the centered
    // title "CERTIFICATION" above "To Whom It May Concern:".
    $title = ($docType === 'Certificate of Completion/Graduation')
        ? 'CERTIFICATE OF GRADUATION'
        : 'CERTIFICATION';

    // Paragraph indent — 8 non-breaking spaces (survives the PDF
    // renderer's whitespace collapsing). Every paragraph of every
    // certificate body starts with it.
    $ind = str_repeat("\u{00A0}", 8);

    // ── Good Moral — official wording (DepEd letter style), three
    // paragraphs: certification → further certification → issued line.
    // Currently enrolled students get the "is a student in this school"
    // opening; graduates get the "graduated" opening.
    $goodMoral = trim($row['grade_level'] ?? '') !== ''
        ? "{$ind}THIS IS TO CERTIFY THAT {NAME} is a student in this school, **HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL**, Siclong, Laur, Nueva Ecija, this school year {SY}.\n\n"
          . "{$ind}This certifies further that he/she has never been subjected to any disciplinary action and that his/her behavior is a clear manifestation of **GOOD MORAL CHARACTER**.\n\n"
          . "{$ind}This certification is issued this {DATE} for reference purposes."
        : "{$ind}THIS IS TO CERTIFY THAT {NAME} graduated from **HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL**, Siclong, Laur, Nueva Ecija.\n\n"
          . "{$ind}This certifies further that he/she has never been subjected to any disciplinary action and that his/her behavior is a clear manifestation of **GOOD MORAL CHARACTER**.\n\n"
          . "{$ind}This certification is issued this {DATE} for reference purposes.";

    // Paragraph indent used by every body — see $ind above.

    $bodies = [
        'Certificate of Completion/Graduation' =>
            "{$ind}This is to certify that {NAME} has satisfactorily completed the requirements prescribed for SECONDARY SCHOOL CURRICULUM of the DEPARTMENT OF EDUCATION last School Year {SY}.\n\n"
          . "{$ind}This certifies further that the school **did not issue YEARBOOK** since this is not a requirement for graduation.\n\n"
          . "{$ind}Given this {DATE} for reference purposes.",
        'Certificate of Enrollment' =>
            "{$ind}THIS IS TO CERTIFY THAT {NAME} with Learner Reference Number (LRN) **{LRN}** is presently enrolled as **{GRADE}** at **HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL**, Siclong, Laur, Nueva Ecija this school year {SY}.\n\n"
          . "{$ind}This certification is issued this {DATE} for reference purposes.",
        'Certificate of Good Moral' => $goodMoral,
    ];

    $body = $bodies[$docType]
        ?? 'This is to certify that {NAME} was a bonafide student of HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL. '
         . 'This certification is issued this {DATE} for reference purposes.';

    return [
        'title'        => $title,
        'body'         => $body,
        // Default signatory — the school principal on the official
        // certificates (still editable per release)
        'officer_name' => 'NOILAN C. ATENDIDO',
        'officer_title'=> 'School Principal II',
        'cert_date'    => date('Y-m-d'),
        'remarks'      => '',
    ];
}

/* ── Grade value for the {GRADE} placeholder (plain "Grade 11") ── */
function certificate_grade_text(array $row): string
{
    $grade = trim($row['grade_level'] ?? '');
    if ($grade !== '') {
        return $grade;
    }
    return '________';
}

/* ── Merge registrar edits over the defaults ─────────────────────── */
function certificate_build_data(array $row, array $edits, string $officerName = ''): array
{
    $defaults = certificate_defaults($row, $officerName);

    $pick = function (string $key, int $maxLen) use ($defaults, $edits): string {
        $v = trim((string)($edits[$key] ?? ''));
        if ($v === '') return $defaults[$key];
        return mb_substr($v, 0, $maxLen);
    };

    $date = trim((string)($edits['cert_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        $date = date('Y-m-d');
    }

    return [
        'req_id'       => (int)$row['req_id'],
        'req_no'       => 'REQ-' . str_pad((string) $row['req_id'], 4, '0', STR_PAD_LEFT),
        'doc_type'     => $row['document_type'],
        'student_name' => trim($row['first_name'] . ' ' . $row['last_name']),
        // Official certificate style: FIRSTNAME LASTNAME (bold, all caps)
        'student_name_cert' => strtoupper(trim(trim($row['first_name'] ?? '') . ' ' . trim($row['last_name'] ?? ''))),
        'lrn'          => trim($row['lrn'] ?? '') !== '' ? $row['lrn'] : '________',
        'grade_text'   => certificate_grade_text($row),
        'purpose'      => $row['purpose'],
        'sy'           => trim($row['school_year_last_attended'] ?? '') !== ''
                            ? $row['school_year_last_attended']
                            : '________',
        'title'        => $pick('title', 120),
        'body'         => $pick('body', 1500),
        'remarks'      => $pick('remarks', 400),
        'officer_name' => $pick('officer_name', 100),
        'officer_title'=> $pick('officer_title', 100),
        'cert_date'    => $date,
        // Official date format: "22nd day of SEPTEMBER, 2026"
        'date_display' => date('jS', strtotime($date)) . ' day of ' . strtoupper(date('F', strtotime($date))) . ', ' . date('Y', strtotime($date)),
    ];
}

/* ── Base64 logo for embedding inside the PDF ────────────────────── */
function certificate_logo_uri(): string
{
    static $uri = null;
    if ($uri !== null) return $uri;

    $path = dirname(__DIR__) . '/assets/img/Logo.png';
    if (!is_file($path)) {
        // try site-configured logo, else none
        $path = dirname(__DIR__) . '/assets/img/logo.png';
        if (!is_file($path)) return $uri = '';
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
    return $uri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
}

/* ── The certificate HTML template (A4 landscape) ──────────────────
 * The design lives in phpLogics/certificate_template.html so it can be
 * edited directly (logo placement, fonts, text positions) without
 * touching PHP. This function loads it and fills in the {{TOKENS}}. */
function render_certificate_html(array $d): string
{
    static $tpl = null;
    if ($tpl === null) {
        $file = __DIR__ . '/certificate_template.html';
        if (!is_file($file)) {
            throw new RuntimeException('Certificate template missing: ' . $file);
        }
        $tpl = file_get_contents($file);
    }

    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    /* ── Title & salutation — letter-style vs titled, like the official
       certificates: Graduation = centered title (no salutation);
       Good Moral & Enrollment = "To Whom It May Concern:" (no title). */
    $titleHtml = trim((string)$d['title']) !== ''
        ? '<div class="cert-title">' . $esc($d['title']) . '</div>'
        : '';
    $isLetterStyle = in_array($d['doc_type'], ['Certificate of Good Moral', 'Certificate of Enrollment'], true);
    $salutHtml = $isLetterStyle
        ? '<div class="cert-to-whom">To Whom It May Concern:</div>'
        : '';

    // Replace placeholders INSIDE the body; escape first so registrar
    // edits can never inject HTML into the PDF.
    $body = nl2br($esc($d['body']));
    // **bold** syntax — lets the default bodies AND the registrar's edits
    // in the release modal bold any word(s): **SECONDARY SCHOOL CURRICULUM**
    // (applied AFTER escaping, so it can never inject HTML)
    $body = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $body);
    $body = str_replace(
        ['{NAME}', '{LRN}', '{GRADE}', '{PURPOSE}', '{SY}', '{DATE}'],
        [
            '<span class="cert-name">' . $esc($d['student_name_cert']) . '</span>',
            $esc($d['lrn']),
            $esc($d['grade_text']),
            $esc($d['purpose']),
            $esc($d['sy']),
            $esc($d['date_display']),
        ],
        $body
    );

    $remarks = trim($d['remarks']) !== ''
        ? '<div class="cert-remarks">' . nl2br($esc($d['remarks'])) . '</div>'
        : '';

    $logo = certificate_logo_uri() !== ''
        ? '<img class="cert-logo" src="' . certificate_logo_uri() . '" alt="">'
        : '';

    // Verification QR — sits above the Reference No. in the footer
    $qr = certificate_qr_uri((int)$d['req_id']) !== ''
        ? '<img class="cert-qr" src="' . certificate_qr_uri((int)$d['req_id']) . '" alt="Verification QR code">'
        : '';

    // Issuance line no longer needed here — the official wording carries
    // the date inside the body ("issued this 22nd day of SEPTEMBER, 2026 …").

    // Contact email — same admin-managed source as the site footer /
    // verify page (site_config loaded on demand for preview/standalone use)
    if (!function_exists('site_setting')) {
        require_once __DIR__ . '/site_config.php';
    }
    $contactEmail = site_setting('contact_email', 'hehmhsadmin@gmail.com');

    return str_replace(
        [
            '{{LOGO_IMG}}',
            '{{QR_IMG}}',
            '{{TITLE}}',
            '{{SALUTATION}}',
            '{{BODY}}',
            '{{REMARKS}}',
            '{{REQ_NO}}',
            '{{OFFICER_NAME}}',
            '{{OFFICER_TITLE}}',
            '{{CONTACT_EMAIL}}',
        ],
        [
            $logo,
            $qr,
            $titleHtml,
            $salutHtml,
            $body,
            $remarks,
            $esc($d['req_no']),
            $esc($d['officer_name']),
            $esc($d['officer_title']),
            $esc($contactEmail),
        ],
        $tpl
    );
}

/* ── Render the certificate to PDF binary ────────────────────────── */
function generate_certificate_pdf(array $d): string
{
    $dompdf = new Dompdf\Dompdf([
        'isRemoteEnabled' => false,   // only embedded (base64) assets
        'defaultFont'     => 'DejaVu Serif',
    ]);
    $dompdf->loadHtml(render_certificate_html($d), 'UTF-8');
    $dompdf->setPaper([0, 0, 612, 936], 'portrait');   // LONG BOND PAPER 8.5" x 13", portrait
    $dompdf->render();
    return $dompdf->output();
}
