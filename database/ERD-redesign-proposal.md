# HEHMHS — ERD Redesign Proposal v5 (**APPLIED** 2026-10-06)

> **Status:** ✅ **Applied and verified end-to-end.** Migration file:
> `database/migrate_erd_v5.sql`. Pre-migration backup:
> `dumps/backup_pre_erd_migration_20261006_173106.sql`.
> The live ERD was regenerated: `database/erd.html`.
> All application code referencing the old columns was updated and tested
> (logins, request submit/edit/cancel/restore/release, admin account
> management, reports, audit trail).

---

## 1. New / changed tables

### `roles` (NEW — lookup table)
| Column | Type | Notes |
|---|---|---|
| `role_id` | INT PK AUTO_INCREMENT | **1 = Student, 2 = Registrar, 3 = Admin** |
| `role_name` | VARCHAR(50) UNIQUE NOT NULL | 'Student' / 'Registrar' / 'Admin' |

### `student_profiles` (NEW — 1:1 with users, students only)
| Column | Type | Notes |
|---|---|---|
| `user_id` | INT **PK & FK** → users.id | ON DELETE CASCADE |
| `student_id` | VARCHAR(20) UNIQUE | school number 2026-0001 — moved from users |
| `lrn` | VARCHAR(20) | moved from users |
| `grade_level` | VARCHAR(20) | moved from users |
| `school_year_last_attended` | VARCHAR(20) | moved from users |

### `users` (slimmed)
| Kept | Removed |
|---|---|
| `id`, `role_id` **(new FK → roles)**, `first_name`, `last_name`, `email` (UK), `contact`, `password`, `status`, `profile_photo`, `failed_login_count`, `lockout_count`, `locked_until`, `created_at`, `updated_at` | `role` (varchar → role_id), `student_id`, `lrn`, `grade_level`, `school_year_last_attended` (all 4 → student_profiles) |

### `document_requests` (one column swapped)
| Column | Type | Notes |
|---|---|---|
| `document_type_id` | INT NOT NULL FK → document_types.id | **replaces** the old free-text `document_type` varchar, which is **dropped** — no duplicate |

Everything else in `document_requests` unchanged (`purpose`, `status`,
`grade_level` / `school_year_last_attended` snapshots, dates, files).

### Everything else unchanged
`document_types`, `announcements`, `audit_logs`,
`credential_resend_log`, `site_settings`.

---

## 2. Relationships: 3 → 7

| # | Relationship | Cardinality | Status |
|---|---|---|---|
| 1 | ROLES → USERS | 1:N | NEW |
| 2 | USERS → STUDENT_PROFILES | 1:1 | NEW |
| 3 | USERS → DOCUMENT_REQUESTS | 1:N | existing |
| 4 | DOCUMENT_TYPES → DOCUMENT_REQUESTS | 1:N | **NEW (fixes orphan table)** |
| 5 | USERS → ANNOUNCEMENTS | 1:N | existing |
| 6 | USERS → AUDIT_LOGS | 1:N | existing |
| 7 | USERS → CREDENTIAL_RESEND_LOG | 1:N (soft, by email) | NEW on diagram |

```
 ROLES ──1:N──▶ USERS ──1:1──▶ STUDENT_PROFILES
                 │
                 ├──1:N──▶ DOCUMENT_REQUESTS ◀──1:N── DOCUMENT_TYPES
                 ├──1:N──▶ ANNOUNCEMENTS
                 ├──1:N──▶ AUDIT_LOGS
                 └──1:N──▶ CREDENTIAL_RESEND_LOG (soft, by email)
```

Only `SITE_SETTINGS` remains standalone (system config — intentional).

---

## 3. Data check (already verified — all clean ✓)

- Every existing `document_requests.document_type` value matches an
  **active** row in `document_types` (Certificate of Enrollment → id 1,
  Certificate of Good Moral → id 3). Backfill produces **no NULLs**.
- Current users: 4 Students, 1 Registrar, 1 Admin — all role names match
  the seed values exactly.

---

## 4. Migration plan (what will actually run once approved)

```sql
-- A. Roles lookup -------------------------------------------------
CREATE TABLE roles (
    role_id   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (role_id, role_name) VALUES
    (1, 'Student'), (2, 'Registrar'), (3, 'Admin');

ALTER TABLE users ADD COLUMN role_id INT NULL AFTER id;
UPDATE users u JOIN roles r ON r.role_name = u.role
        SET u.role_id = r.role_id;                    -- 6/6 rows match
ALTER TABLE users
    MODIFY role_id INT NOT NULL,
    ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id)
        REFERENCES roles (role_id) ON UPDATE CASCADE;
ALTER TABLE users DROP COLUMN role;                   -- old varchar

-- B. Student profiles (all 5 columns out of users) -----------------
CREATE TABLE student_profiles (
    user_id     INT NOT NULL PRIMARY KEY,
    student_id  VARCHAR(20) DEFAULT NULL,
    lrn         VARCHAR(20) DEFAULT NULL,
    grade_level VARCHAR(20) DEFAULT NULL,
    school_year_last_attended VARCHAR(20) DEFAULT NULL,
    UNIQUE KEY uq_profile_student_no (student_id),
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO student_profiles
    (user_id, student_id, lrn, grade_level, school_year_last_attended)
SELECT id, student_id, lrn, grade_level, school_year_last_attended
FROM users WHERE role_id = 1;

ALTER TABLE users
    DROP COLUMN student_id, DROP COLUMN lrn,
    DROP COLUMN grade_level, DROP COLUMN school_year_last_attended;

-- C. Real link: requests → document_types --------------------------
ALTER TABLE document_requests
    ADD COLUMN document_type_id INT NULL AFTER user_id;

UPDATE document_requests dr
   JOIN document_types dt ON dt.name = dr.document_type
   SET dr.document_type_id = dt.id;                   -- 4/4 rows match

ALTER TABLE document_requests
    MODIFY document_type_id INT NOT NULL,
    ADD CONSTRAINT fk_request_doc_type FOREIGN KEY (document_type_id)
        REFERENCES document_types (id) ON UPDATE CASCADE;
-- FK has no ON DELETE: a type in use can never be deleted (RESTRICT),
-- so admin hides types with is_active = 0 instead.
ALTER TABLE document_requests DROP COLUMN document_type; -- duplicate removed
```

**Execution order:** backup first (`dumps/`), then A → B → C, wrapped so
that any error stops the run. After the schema change, the PHP files that
reference `users.role` / `users.lrn` / `users.grade_level` /
`users.student_id` must be updated in the same pass (login, addStud,
editStud, getStud, dashboards, exports, certificates).

---

## 5. Panel talking points

1. **Roles lookup table** — DB-enforced role names (no typos), new roles
   need no schema change; standard referential-integrity pattern.
2. **student_profiles (1:1)** — staff accounts no longer carry NULL
   academic columns; clean separation of identity vs. academic data.
3. **document_type_id FK** — requests are now validated against the
   admin-managed catalog; deleting a type that has requests is blocked
   by the database (types are deactivated via `is_active` instead).
4. **credential_resend_log soft reference** — disposable rate-limit log
   keyed by email; intentionally not FK-constrained.
5. **Snapshot columns in document_requests** — grade level / school
   year / document name are recorded *as of request time*.

## 6. Files

| File | Purpose |
|---|---|
| `ERD-redesign-proposal.md` | this document |
| `ER-diagram-proposed.mmd` | Mermaid source (v5) |
| `erd-proposed.html` | printable diagram — open in browser |

Live DB and existing ERD files remain untouched.
