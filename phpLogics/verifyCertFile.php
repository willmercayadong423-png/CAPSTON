<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMHS — public certificate file viewer  (verifyCertFile.php)
 * ───────────────────────────────────────────────────────────────────
 * Streams the released e-certificate PDF for the VERIFICATION page.
 *
 *   GET ?req=<id>&code=<16-hex>              → inline preview (iframe)
 *   GET ?req=<id>&code=<16-hex>&download=1   → force file download
 *
 * Security — identical gate to verify.php: the request must be RELEASED
 * with an e-certificate on file, and the ?code= must match the
 * unguessable HMAC of the request id. No login — the QR code printed
 * on the certificate IS the credential (anyone holding the physical
 * copy can view the original; nobody else can guess the URL).
 * ═══════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../database/db.php';
require_once __DIR__ . '/certificate.php';   // certificate_verify_code()

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$reqId = (int)($_GET['req'] ?? 0);
$code  = preg_replace('/[^0-9a-f]/', '', (string)($_GET['code'] ?? ''));
$download = isset($_GET['download']);

function reject(int $status = 404): void
{
    http_response_code($status);
    exit('Not found');
}

if ($reqId <= 0 || strlen($code) !== 16 || !hash_equals(certificate_verify_code($reqId), $code)) {
    reject();
}

$q = $conn->prepare(
    "SELECT dr.e_certificate, dt.name AS document_type
     FROM document_requests dr
     LEFT JOIN document_types dt ON dt.id = dr.document_type_id
     WHERE dr.id = ? AND dr.status = 'Released' AND dr.e_certificate IS NOT NULL"
);
$q->bind_param('i', $reqId);
$q->execute();
$row = $q->get_result()->fetch_assoc();
$q->close();

if (!$row) {
    reject();
}

// DB paths are web-root-relative ("uploads/...") — resolve to filesystem
$path = dirname(__DIR__) . '/' . ltrim($row['e_certificate'], '/');
if (!is_file($path)) {
    reject(404);
}

$reqNo  = 'REQ-' . str_pad((string)$reqId, 4, '0', STR_PAD_LEFT);
$safeDoc = preg_replace('/[^A-Za-z0-9]+/', '_', (string)($row['document_type'] ?? 'Certificate'));
$safeDoc = trim($safeDoc, '_');
$fname  = "HEHMHS_{$safeDoc}_{$reqNo}.pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-cache, must-revalidate');
readfile($path);
exit;
