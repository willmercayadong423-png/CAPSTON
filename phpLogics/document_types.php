<?php
/* ── Single source of truth: requestable document types ─────────────
 * The list shown on the student request form (and validated server-side
 * in editReq.php) now lives in the `document_types` table so the Admin
 * can add/remove documents from the dashboard (Information → Requestable
 * Documents) without a code change.
 *
 * Public helpers:
 *   ensure_document_types_table($conn)  → creates + seeds the table once
 *   get_document_types($conn, $active)  → list of names (active by default)
 *   get_document_type_map($conn, $active)→ name => requires_grade_level (0/1)
 *
 * The `requires_grade_level` flag replaces the old hardcoded $needsGl
 * array — new documents can ask for the Grade Level too.
 *
 * MIGRATION: the table is created and seeded automatically (idempotent)
 * on first use, so existing databases are upgraded without manual SQL. */

function ensure_document_types_table(mysqli $conn): void
{
    static $ready = false;
    if ($ready) return;

    @$conn->query("
        CREATE TABLE IF NOT EXISTS `document_types` (
            `id`          INT(11)     NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(150) NOT NULL,
            `requires_grade_level` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = the request form asks for the Grade Level',
            `is_active`   TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '0 = hidden from the request form (kept for history)',
            `sort_order`  INT(11)     NOT NULL DEFAULT 0,
            `created_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_document_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
          COMMENT='Requestable document types (admin-managed).'
    ");

    // Seed: the five online-requestable documents. (SF10/Form 137, Diploma
    // and YearBook were dropped entirely — in-person only, not listed.)
    @$conn->query("
        INSERT IGNORE INTO `document_types` (`name`, `requires_grade_level`, `is_active`, `sort_order`)
        VALUES
          ('Certificate of Enrollment',             1, 1, 1),
          ('Certificate of Grades',                 1, 1, 2),
          ('Certificate of Good Moral',             0, 1, 3),
          ('Certificate of Transfer',               1, 1, 4),
          ('Certificate of Completion/Graduation',  1, 1, 5)
    ");

    $ready = true;
}

/* ── Names of requestable document types, in display order ──────────
 * $activeOnly = true  → only documents students can currently request
 * $activeOnly = false → every known type (incl. removed/legacy ones;
 *                       editReq.php uses this so an old pending request
 *                       can still be edited after its type was removed). */
function get_document_types(mysqli $conn, bool $activeOnly = true): array
{
    ensure_document_types_table($conn);
    $res = $conn->query(
        "SELECT name FROM document_types"
        . ($activeOnly ? " WHERE is_active = 1" : "")
        . " ORDER BY sort_order, name"
    );
    if (!$res) return [];
    $out = [];
    while ($r = $res->fetch_row()) {
        $out[] = (string)$r[0];
    }
    return $out;
}

/* ── name => requires_grade_level map (0/1), same filtering rules ──── */
function get_document_type_map(mysqli $conn, bool $activeOnly = true): array
{
    ensure_document_types_table($conn);
    $res = $conn->query(
        "SELECT name, requires_grade_level FROM document_types"
        . ($activeOnly ? " WHERE is_active = 1" : "")
        . " ORDER BY sort_order, name"
    );
    if (!$res) return [];
    $map = [];
    while ($r = $res->fetch_assoc()) {
        $map[(string)$r['name']] = (int)$r['requires_grade_level'];
    }
    return $map;
}
