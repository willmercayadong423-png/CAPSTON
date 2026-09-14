# HEHMS Database — Presentation Package

Everything the panel needs for the database side of your system.

## Files

| File | Purpose |
|---|---|
| **`schema.sql`** | Canonical database script. Import this on a fresh machine and the whole DB (tables + default accounts + branding) is created. Present this file as your DDL. |
| **`erd.html`** | **Entity-Relationship Diagram** — open in any browser, print-ready (Ctrl+P). Generated from the live database. |
| **`ER-diagram.mmd`** | Mermaid source of the same ERD. Paste into <https://mermaid.live> for a fancier rendered diagram you can screenshot for slides. |
| **`generate_erd.php`** | Regenerates `erd.html` from the live DB. Run: `C:/xampp/php/php.exe database/generate_erd.php` |
| **`config.php`** | DB credentials (gitignored — never present this file's contents). |
| **`db.php`** | Shared mysqli connection + hardened session cookies. |

## Seeded accounts (fresh import)

| Login | Password | Role |
|---|---|---|
| `registrar@hehms.edu.ph` | `Registrar@123` | Registrar |
| `admin@hehms.edu.ph` | `Admin@123` | Admin |
| `juan@hehms.edu.ph` (Student ID `2026-9001`) | `Student@123` | Student (demo) |

> ⚠️ Change these on any real deployment.

## Schema highlights (talking points for the panel)

- **5 tables, fully normalized** — `users` (role-based accounts), `document_requests`, `announcements`, `site_settings`, `credential_resend_log`.
- **InnoDB + enforced foreign keys** — referential integrity (deleting a student cascades to their requests; deleting an announcement author sets NULL).
- **utf8mb4 everywhere** — correct storage of Filipino names (ñ, é).
- **bcrypt-only passwords** — `password_hash()` / `password_verify()`, no plaintext anywhere.
- **Performance indexes** on `status`, `date_requested`, `role`, `email` — the exact columns the dashboards filter and sort by.
- **Lifecycle documented in ENUM** — `Pending → Processing → Released / Cancelled`, with rejection metadata in `cancelled_by`.
- **Audit timestamps** — `created_at` + `updated_at` (auto-maintained by MySQL).

## Request lifecycle (for your flow diagram)

```
 Student submits ──▶ PENDING
 Registrar accepts ─▶ PROCESSING        (student emailed: "being processed")
 Registrar releases─▶ RELEASED          (system generates e-certificate PDF,
                                          emails it as attachment, student can
                                          re-download from History)
 Student cancels ───▶ CANCELLED         (registrar sees it in History)
 Registrar rejects ─▶ CANCELLED (by registrar → shown as "Rejected")
```

## Re-import warning

`schema.sql` **drops and recreates** the `hehms` tables — only import it when
setting up a **fresh** database (phpMyAdmin → Import), never over live data.
