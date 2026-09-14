<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMS Certificate Generator  (phpLogics/certificate.php)
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

/* ── Fetch one request joined with its student record ────────────── */
function certificate_fetch(mysqli $conn, int $req_id): ?array
{
    $q = $conn->prepare(
        "SELECT dr.id AS req_id, dr.document_type, dr.purpose, dr.date_requested,
                dr.e_certificate, s.id AS student_pk, s.student_id AS school_id,
                s.first_name, s.last_name, s.lrn, s.grade_level, s.strand,
                s.school_year_last_attended
         FROM document_requests dr
         JOIN users s ON dr.user_id = s.id
         WHERE dr.id = ?"
    );
    $q->bind_param("i", $req_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return $row ?: null;
}

/* ── Default editable fields (auto-filled from the record) ───────── */
function certificate_defaults(array $row, string $officerName = ''): array
{
    $docType = $row['document_type'];

    $title = strtoupper($docType);
    if ($docType === 'SF10/Form 137') {
        $title = 'CERTIFICATION — FORM 137 (SF10)';
    }

    // Per-document default bodies — official DepEd wording.
    // Placeholders: {NAME} renders as SURNAME, FIRSTNAME (all caps),
    // {SY} = school year last attended, {PURPOSE} = request purpose.
    // Certificate of Good Moral — official wording. Graduates get the
    // "graduated during SY" opening; enrolled students get "bonafide student".
    $goodMoral = trim($row['grade_level'] ?? '') !== ''
        ? 'This is to certify that {NAME} is a bonafide student of HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL. '
          . 'This further certifies that he/she has not committed any misbehavior and/or violated any school rules and regulation during his/her stay in school.'
        : 'This is to certify that {NAME} graduated from HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL during the school year {SY}. '
          . 'This further certifies that he/she has not committed any misbehavior and/or violated any school rules and regulation during his/her stay in school.';

    $bodies = [
        'Certificate of Completion/Graduation' =>
            'This is to certify that {NAME} graduated from HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL during the school year {SY}.',
        'Diploma' =>
            'This is to certify that {NAME} graduated from HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL during the school year {SY}.',
        'Certificate of Enrollment' =>
            'This is to certify that {NAME} is officially enrolled at HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL.',
        'Certificate of Registration' =>
            'This is to certify that {NAME} is officially enrolled at HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL.',
        'Certificate of Grades' =>
            'This is to certify that {NAME} was a bonafide student of HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL and that a copy of his/her report card is available in the records of this school.',
        'Certificate of Good Moral' => $goodMoral,
        'Certificate of Transfer' =>
            'This is to certify that {NAME} was a bonafide student of HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL and is eligible for transfer to another school.',
        'SF10/Form 137' =>
            'This is to certify that the Form 137 (SF10) permanent record of {NAME} is on file at HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL.',
        'YearBook' =>
            'This is to certify that {NAME} was a bonafide student of HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL.',
    ];

    $body = $bodies[$docType]
        ?? 'This is to certify that {NAME} was a bonafide student of HILARIO E. HERMOSA MEMORIAL HIGH SCHOOL.';

    // Standard closing clause on every certification (includes issue date)
    $body .= ' This certification is issued this {DATE} for any legal purposes it may serve.';

    return [
        'title'        => $title,
        'body'         => $body,
        // Default signatory for every certificate (still editable per release)
        'officer_name' => 'EDUARD B. GOBOLI',
        'officer_title'=> 'Teacher-In-Charge',
        'cert_date'    => date('Y-m-d'),
        'remarks'      => '',
    ];
}

/* ── Grade/context line used for the {GRADE} placeholder ─────────── */
function certificate_grade_text(array $row): string
{
    $grade = trim($row['grade_level'] ?? '');
    $strand = trim($row['strand'] ?? '');
    $sy = trim($row['school_year_last_attended'] ?? '');

    if ($grade !== '') {
        return ', currently enrolled in ' . $grade . ($strand !== '' ? ' — ' . $strand . ' Strand' : '');
    }
    if ($sy !== '') {
        return ', who last attended this school in School Year ' . $sy;
    }
    return '';
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
        'req_no'       => 'REQ-' . str_pad((string) $row['req_id'], 4, '0', STR_PAD_LEFT),
        'doc_type'     => $row['document_type'],
        'student_name' => trim($row['first_name'] . ' ' . $row['last_name']),
        // DepEd style for the certificate body: SURNAME, FIRSTNAME (all caps)
        'student_name_cert' => strtoupper(trim(trim($row['last_name'] ?? '') . ', ' . trim($row['first_name'] ?? ''), " ,")),
        'lrn'          => trim($row['lrn'] ?? '') !== '' ? $row['lrn'] : 'N/A',
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
        'date_display' => date('jS \o\f F Y', strtotime($date)),   // e.g. "24th of June 2022"
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

    // Replace placeholders INSIDE the body; escape first so registrar
    // edits can never inject HTML into the PDF.
    $body = nl2br($esc($d['body']));
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

    // Issuance line — official format: "Given this 24th of June 2022, at …"
    $ts = strtotime($d['cert_date']);
    $day = date('jS', $ts);
    $monthYear = date('F Y', $ts);
    $issuedLine = 'Given this ' . $day . ' of ' . $monthYear
                . ', at Hilario E. Hermosa Memorial High School, Siclong, Laur, Nueva Ecija.';

    return str_replace(
        [
            '{{LOGO_IMG}}',
            '{{TITLE}}',
            '{{BODY}}',
            '{{REMARKS}}',
            '{{ISSUED_LINE}}',
            '{{REQ_NO}}',
            '{{OFFICER_NAME}}',
            '{{OFFICER_TITLE}}',
        ],
        [
            $logo,
            $esc($d['title']),
            $body,
            $remarks,
            $esc($issuedLine),
            $esc($d['req_no']),
            $esc($d['officer_name']),
            $esc($d['officer_title']),
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
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    return $dompdf->output();
}
