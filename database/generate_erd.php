<?php
/* ═══════════════════════════════════════════════════════════════════
 * HEHMS — ERD generator  (database/generate_erd.php)
 * ───────────────────────────────────────────────────────────────────
 * Reads the LIVE database and produces database/erd.html — a
 * print-ready Entity-Relationship Diagram for presentations.
 *
 * Run from the project root:
 *     php database/generate_erd.php
 * (or with XAMPP:  C:/xampp/php/php.exe database/generate_erd.php )
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error . "\n");
}
$conn->set_charset('utf8mb4');

/* ── Pull table metadata ─────────────────────────────────────────── */
$tables = [];
$res = $conn->query("SHOW TABLES");
while ($t = $res->fetch_array()) {
    $tables[] = $t[0];
}

$meta = [];   // table => [columns]
$fks  = [];   // [from_table, from_col, to_table, to_col]
$rels = $conn->query(
    "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = '" . $conn->real_escape_string(DB_NAME) . "'
       AND REFERENCED_TABLE_NAME IS NOT NULL"
);
while ($r = $rels->fetch_assoc()) {
    $fks[] = [$r['TABLE_NAME'], $r['COLUMN_NAME'], $r['REFERENCED_TABLE_NAME'], $r['REFERENCED_COLUMN_NAME']];
}

foreach ($tables as $table) {
    $cols = [];
    $c = $conn->query("SHOW FULL COLUMNS FROM `$table`");
    while ($col = $c->fetch_assoc()) {
        $key = $col['Key'];
        $isFK = false;
        foreach ($fks as $f) {
            if ($f[0] === $table && $f[1] === $col['Field']) { $isFK = true; break; }
        }
        $cols[] = [
            'name'    => $col['Field'],
            'type'    => $col['Type'],
            'null'    => $col['Null'] === 'YES',
            'key'     => $key,           // PRI / UNI / MUL / ''
            'fk'      => $isFK,
            'comment' => $col['Comment'] ?? '',
        ];
    }
    $row = $conn->query("SELECT TABLE_ROWS, TABLE_COMMENT FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA='" . $conn->real_escape_string(DB_NAME) . "' AND TABLE_NAME='$table'")->fetch_assoc();
    $meta[$table] = ['cols' => $cols, 'comment' => $row['TABLE_COMMENT'] ?? '', 'rows' => (int)($row['TABLE_ROWS'] ?? 0)];
}

/* ── Cardinality helper (every FK here is 1:N) ───────────────────── */
$cardinality = [
    'users → document_requests'     => 'One user (student) places <b>many</b> document requests (1 : N). Deleting an account cascades to its requests.',
    'users → announcements'         => 'One staff user authors <b>many</b> announcements (1 : N). Deleting the author sets the announcement’s author to NULL.',
];

/* ── Build the HTML ──────────────────────────────────────────────── */
$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$cards = '';
foreach ($meta as $table => $t) {
    $rows = '';
    foreach ($t['cols'] as $c) {
        $badge = '';
        if ($c['key'] === 'PRI')      $badge = '<span class="b pk">PK</span>';
        elseif ($c['key'] === 'UNI')  $badge = '<span class="b uk">UK</span>';
        elseif ($c['fk'])             $badge = '<span class="b fk">FK</span>';
        elseif ($c['key'] === 'MUL')  $badge = '<span class="b idx">IDX</span>';

        $comment = $c['comment'] !== ''
            ? '<span class="cmt" title="' . $esc($c['comment']) . '">' . $esc(mb_substr($c['comment'], 0, 34)) . (mb_strlen($c['comment']) > 34 ? '…' : '') . '</span>'
            : '';

        $rows .= '<tr>'
            . '<td class="cn">' . $badge . $esc($c['name']) . $comment . '</td>'
            . '<td class="ct">' . $esc($c['type']) . '</td>'
            . '<td class="cn2">' . ($c['null'] ? '<span class="nul">NULL</span>' : '<span class="nn">NOT NULL</span>') . '</td>'
            . '</tr>';
    }
    $cards .= '<div class="entity">'
        . '<div class="ehead"><span class="ename">' . $esc($table) . '</span>'
        . '<span class="ecount">' . number_format($t['rows']) . ' rows</span></div>'
        . ($t['comment'] !== '' ? '<div class="edesc">' . $esc($t['comment']) . '</div>' : '')
        . '<table>' . $rows . '</table></div>';
}

$fkRows = '';
foreach ($fks as $f) {
    $key = $f[2] . ' → ' . $f[0];
    $fkRows .= '<tr>'
        . '<td><code>' . $esc($f[2]) . '.' . $esc($f[3]) . '</code></td>'
        . '<td class="rel">1 ─────&lt; N</td>'
        . '<td><code>' . $esc($f[0]) . '.' . $esc($f[1]) . '</code></td>'
        . '<td class="note">' . $esc($cardinality[$key] ?? '') . '</td></tr>';
}

$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>HEHMS — Entity Relationship Diagram</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: "Segoe UI", Arial, sans-serif; background: #f4f6f0; color: #1a2409; padding: 36px; }
    h1 { font-size: 22px; color: #3d5a1e; }
    h1 span { color: #6b8f3a; }
    .sub { color: #7a8a65; font-size: 12.5px; margin: 6px 0 26px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(330px, 1fr)); gap: 20px; }
    .entity { background: #fff; border: 1px solid #d8e0c8; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(40,60,20,.08); }
    .ehead { background: linear-gradient(135deg,#3d5a1e,#6b8f3a); color: #fff; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; }
    .ename { font-weight: 700; font-family: Consolas, monospace; font-size: 14px; }
    .ecount { font-size: 10.5px; opacity: .85; background: rgba(255,255,255,.15); padding: 2px 8px; border-radius: 99px; }
    .edesc { background: #f0f7e6; color: #4a5c33; font-size: 11px; padding: 7px 14px; border-bottom: 1px solid #e4ecd6; font-style: italic; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 6px 12px; font-size: 12px; border-bottom: 1px solid #f0f3e8; vertical-align: top; }
    tr:last-child td { border-bottom: none; }
    .cn { font-family: Consolas, monospace; font-weight: 600; white-space: nowrap; }
    .ct { color: #7a8a65; font-family: Consolas, monospace; font-size: 11px; }
    .cn2 { white-space: nowrap; }
    .nul { color: #b0532b; font-size: 10px; font-weight: 700; }
    .nn { color: #6b8f3a; font-size: 10px; font-weight: 700; }
    .b { font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 99px; margin-right: 7px; letter-spacing: .5px; }
    .pk  { background: #c9a84c; color: #fff; }
    .uk  { background: #8e44ad; color: #fff; }
    .fk  { background: #2f6f68; color: #fff; }
    .idx { background: #d8e0c8; color: #4a5c33; }
    .cmt { display: block; color: #9aa585; font-size: 10px; font-weight: 400; margin: 1px 0 0 38px; font-family: "Segoe UI", sans-serif; }
    h2 { font-size: 15px; color: #3d5a1e; margin: 34px 0 12px; }
    .reltable { width: 100%; background: #fff; border: 1px solid #d8e0c8; border-radius: 10px; border-collapse: separate; border-spacing: 0; overflow: hidden; }
    .reltable th { background: #f0f7e6; color: #4a5c33; text-align: left; font-size: 11.5px; padding: 9px 14px; text-transform: uppercase; letter-spacing: .6px; }
    .reltable td { padding: 10px 14px; font-size: 12.5px; }
    .rel { color: #2f6f68; font-weight: 700; white-space: nowrap; text-align: center; }
    .note { color: #7a8a65; font-size: 11.5px; }
    code { background: #f0f7e6; padding: 2px 7px; border-radius: 5px; font-size: 11.5px; }
    .legend { margin: 18px 0 0; font-size: 11.5px; color: #4a5c33; }
    .footer { margin-top: 30px; text-align: center; color: #9aa585; font-size: 10.5px; }
    @media print { body { background: #fff; padding: 10mm; } .entity { box-shadow: none; } }
</style>
</head>
<body>
    <h1>HEHMS Credential Request &amp; Tracking System — <span>Entity Relationship Diagram</span></h1>
    <div class="sub">Hilario E. Hermosa Memorial High School · database <code>hehms</code> · generated ' . date('M j, Y') . ' from the live schema (database/generate_erd.php)</div>

    <div class="grid">' . $cards . '</div>

    <h2>Relationships &amp; Cardinality</h2>
    <table class="reltable">
        <tr><th>Parent (One)</th><th></th><th>Child (Many)</th><th>Notes</th></tr>
        ' . $fkRows . '
    </table>

    <div class="legend">
        <span class="b pk">PK</span> Primary key &nbsp;
        <span class="b uk">UK</span> Unique key &nbsp;
        <span class="b fk">FK</span> Foreign key &nbsp;
        <span class="b idx">IDX</span> Indexed &nbsp;·&nbsp;
        Engine: InnoDB · Charset: utf8mb4 · All FKs enforced
    </div>

    <div class="footer">System-generated ERD — regenerate any time with: php database/generate_erd.php</div>
</body>
</html>';

file_put_contents(__DIR__ . '/erd.html', $html);
echo "ERD written to database/erd.html (" . count($meta) . " tables, " . count($fks) . " relationships)\n";
